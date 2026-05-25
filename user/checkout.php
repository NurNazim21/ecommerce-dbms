<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = intval($_SESSION['user_id']);

// ── Coupon session ────────────────────────────────────────────────────────────
if (!isset($_SESSION['applied_coupon'])) {
    $_SESSION['applied_coupon'] = null;
}

// ── Which products were selected in cart ──────────────────────────────────────
if (isset($_POST['selected_products']) && is_array($_POST['selected_products'])) {
    $_SESSION['checkout_products'] = array_map('intval', $_POST['selected_products']);
}

$selected_ids = $_SESSION['checkout_products'] ?? [];

if (empty($selected_ids)) {
    header("Location: cart.php");
    exit();
}

// ── Fetch only the selected cart items ───────────────────────────────────────
// FIX: replaced the fragile call_user_func_array / reference trick with a
//      clean helper that builds the IN (?,?,…) list safely.
function fetch_selected_items(mysqli $conn, int $user_id, array $ids): array {
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = 'i' . str_repeat('i', count($ids));   // first i = user_id
    $params       = array_merge([$user_id], $ids);

    $sql  = "
        SELECT c.product_id, c.quantity,
               p.name, p.price, p.image, p.stock
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
          AND c.product_id IN ($placeholders)
          AND p.status = 'approved'
        ORDER BY c.created_at DESC
    ";

    $stmt = $conn->prepare($sql);

    // PHP 8.1+ compatible: spread into bind_param via a reference array
    $bind_params = [];
    $bind_params[] = &$types;
    foreach ($params as $k => $v) {
        $params[$k] = $v;                 // ensure actual values, not refs to loop var
        $bind_params[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_params);

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$raw_items = fetch_selected_items($conn, $user_id, $selected_ids);
$items     = [];
$subtotal  = 0;
foreach ($raw_items as $row) {
    $row['line_total'] = $row['price'] * $row['quantity'];
    $subtotal         += $row['line_total'];
    $items[]           = $row;
}

if (empty($items)) {
    header("Location: cart.php");
    exit();
}

// ── Coupon ────────────────────────────────────────────────────────────────────
$discount       = 0;
$coupon_code    = '';
$coupon_error   = '';
$coupon_success = '';

if (isset($_POST['remove_coupon'])) {
    $_SESSION['applied_coupon'] = null;
    header("Location: checkout.php");
    exit();
}

if (isset($_POST['apply_coupon']) && !empty($_POST['coupon_code'])) {
    $code_input = strtoupper(trim($_POST['coupon_code']));
    if (!preg_match('/^[A-Z0-9_\-]{3,20}$/', $code_input)) {
        $coupon_error = "Invalid coupon code format.";
    } else {
        $stmt = $conn->prepare("
            SELECT * FROM coupons
            WHERE code = ? AND is_active = 1
              AND (expiry_date IS NULL OR expiry_date >= CURDATE())
        ");
        $stmt->bind_param("s", $code_input);
        $stmt->execute();
        $coupon = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($coupon && $subtotal >= floatval($coupon['min_order_amount'])) {
            $_SESSION['applied_coupon'] = $code_input;
            $coupon_success = "Coupon applied!";
        } else {
            $coupon_error = "Invalid coupon or minimum order amount not met.";
        }
    }
}

// Re-validate coupon on every load
if (!empty($_SESSION['applied_coupon'])) {
    $stmt = $conn->prepare("
        SELECT * FROM coupons
        WHERE code = ? AND is_active = 1
          AND (expiry_date IS NULL OR expiry_date >= CURDATE())
    ");
    $stmt->bind_param("s", $_SESSION['applied_coupon']);
    $stmt->execute();
    $coupon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($coupon && $subtotal >= floatval($coupon['min_order_amount'])) {
        $coupon_code = $_SESSION['applied_coupon'];
        $discount    = $coupon['discount_type'] === 'percentage'
            ? $subtotal * ($coupon['discount_value'] / 100)
            : floatval($coupon['discount_value']);
    } else {
        $_SESSION['applied_coupon'] = null;
    }
}

// ── Shipping options ──────────────────────────────────────────────────────────
$shipping_options = [
    'standard' => ['label' => 'Standard Delivery',  'days' => '5–7 business days',          'cost' => 60],
    'express'  => ['label' => 'Express Delivery',   'days' => '2–3 business days',           'cost' => 120],
    'same_day' => ['label' => 'Same-Day Delivery',  'days' => 'Today (order before 12 PM)',  'cost' => 200],
];
$shipping_method = $_POST['shipping_method'] ?? $_SESSION['shipping_method'] ?? 'standard';
if (!array_key_exists($shipping_method, $shipping_options)) {
    $shipping_method = 'standard';
}
$_SESSION['shipping_method'] = $shipping_method;
$shipping_cost = $shipping_options[$shipping_method]['cost'];

// ── Payment methods ───────────────────────────────────────────────────────────
// FIX: store the human-readable label in the DB, not the short key.
//      order_details.php can then display it directly without a lookup map.
$payment_methods = [
    'cod'   => ['label' => 'Cash on Delivery',   'icon' => '💵'],
    'bkash' => ['label' => 'bKash',               'icon' => '📱'],
    'nagad' => ['label' => 'Nagad',               'icon' => '📲'],
    'card'  => ['label' => 'Credit / Debit Card', 'icon' => '💳'],
    'bank'  => ['label' => 'Bank Transfer',       'icon' => '🏦'],
];
$payment_key = $_POST['payment_method'] ?? 'cod';
if (!array_key_exists($payment_key, $payment_methods)) {
    $payment_key = 'cod';
}
$payment_label = $payment_methods[$payment_key]['label'];  // ← stored in DB

$final_total = max(0, $subtotal - $discount) + $shipping_cost;

// ── Fetch user profile for pre-fill ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, phone, address, city FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── PLACE ORDER ───────────────────────────────────────────────────────────────
if (isset($_POST['place_order'])) {

    $ship_name      = trim(strip_tags($_POST['ship_name']    ?? ''));
    $ship_phone     = trim(strip_tags($_POST['ship_phone']   ?? ''));
    $ship_email     = trim($_POST['ship_email']              ?? '');
    $ship_address   = trim(strip_tags($_POST['ship_address'] ?? ''));
    $ship_city      = trim(strip_tags($_POST['ship_city']    ?? ''));
    $ship_zip       = trim(strip_tags($_POST['ship_zip']     ?? ''));
    $ship_country   = trim(strip_tags($_POST['ship_country'] ?? 'Bangladesh'));
    $ship_notes     = trim(strip_tags($_POST['ship_notes']   ?? ''));
    $payment_key    = $_POST['payment_method'] ?? 'cod';
    if (!array_key_exists($payment_key, $payment_methods)) $payment_key = 'cod';
    $payment_label  = $payment_methods[$payment_key]['label'];

    $errors = [];
    if (empty($ship_name))    $errors[] = "Full name is required.";
    if (empty($ship_phone))   $errors[] = "Phone number is required.";
    if (empty($ship_address)) $errors[] = "Delivery address is required.";
    if (empty($ship_city))    $errors[] = "City is required.";
    if (!empty($ship_email) && !filter_var($ship_email, FILTER_VALIDATE_EMAIL))
                               $errors[] = "Enter a valid email address.";
    if (!array_key_exists($shipping_method, $shipping_options))
                               $errors[] = "Invalid shipping method.";

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            // Re-validate every item's stock + status
            foreach ($items as $item) {
                $pid  = $item['product_id'];
                $stmt = $conn->prepare("SELECT stock, status FROM products WHERE id = ? FOR UPDATE");
                $stmt->bind_param("i", $pid);
                $stmt->execute();
                $latest = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$latest || $latest['status'] !== 'approved') {
                    throw new Exception("A product is no longer available. Please review your cart.");
                }
                if ($latest['stock'] < $item['quantity']) {
                    throw new Exception("'{$item['name']}' only has {$latest['stock']} left in stock.");
                }
            }

            // Shipping snapshot stored in notes
            $shipping_snapshot = json_encode([
                'name'    => $ship_name,
                'phone'   => $ship_phone,
                'email'   => $ship_email,
                'address' => $ship_address,
                'city'    => $ship_city,
                'zip'     => $ship_zip,
                'country' => $ship_country,
                'method'  => $shipping_method,
                'notes'   => $ship_notes,
            ]);

            $coupon_used = !empty($coupon_code) ? $coupon_code : null;

            // FIX: now also stores shipping_cost and discount_amount in the DB
            //      so order_details.php can show a proper price breakdown.
            //      Requires migration_add_order_columns.sql to have been run.
            $stmt = $conn->prepare("
                INSERT INTO orders
                    (user_id, total_amount, shipping_cost, discount_amount,
                     coupon_code, status, notes)
                VALUES (?, ?, ?, ?, ?, 'Pending', ?)
            ");
            $stmt->bind_param("iddss s",
                $user_id, $final_total, $shipping_cost,
                $discount, $coupon_used, $shipping_snapshot
            );
            // bind_param type string without the space:
            $stmt->close();

            // Re-prepare with the correct type string (no space)
            $stmt = $conn->prepare("
                INSERT INTO orders
                    (user_id, total_amount, shipping_cost, discount_amount,
                     coupon_code, status, notes)
                VALUES (?, ?, ?, ?, ?, 'Pending', ?)
            ");
            $stmt->bind_param("idddss",
                $user_id, $final_total, (float)$shipping_cost,
                (float)$discount, $coupon_used, $shipping_snapshot
            );
            $stmt->execute();
            $order_id = $conn->insert_id;
            $stmt->close();

            // Insert order items + deduct stock
            foreach ($items as $item) {
                $stmt = $conn->prepare("
                    INSERT INTO order_items (order_id, product_id, quantity, price)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param("iiid",
                    $order_id, $item['product_id'],
                    $item['quantity'], $item['price']
                );
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("
                    UPDATE products SET stock = stock - ?
                    WHERE id = ? AND stock >= ?
                ");
                $stmt->bind_param("iii",
                    $item['quantity'], $item['product_id'], $item['quantity']
                );
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    throw new Exception(
                        "Stock update failed for '{$item['name']}'. It may have just sold out."
                    );
                }
                $stmt->close();
            }

            // FIX: store the human-readable payment label, not the short key
            $stmt = $conn->prepare("
                INSERT INTO payments (order_id, payment_method, payment_status)
                VALUES (?, ?, 'Pending')
            ");
            $stmt->bind_param("is", $order_id, $payment_label);
            $stmt->execute();
            $stmt->close();

            // Remove checked-out items from cart
            foreach ($selected_ids as $pid) {
                $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("ii", $user_id, $pid);
                $stmt->execute();
                $stmt->close();
            }

            // Clear session
            $_SESSION['applied_coupon']    = null;
            $_SESSION['checkout_products'] = [];
            $_SESSION['shipping_method']   = null;

            $conn->commit();
            header("Location: orders.php?success=1&order_id=" . $order_id);
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = $e->getMessage();
        }
    }

    $order_errors = $errors;
}
?>
<?php include("../includes/header.php"); ?>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">

<style>
:root {
    --ink:    #0f0f0f;
    --muted:  #6b7280;
    --border: #e5e7eb;
    --surface:#f9fafb;
    --accent: #131921;
    --amber:  #f59e0b;
    --red:    #ef4444;
    --green:  #16a34a;
    --radius: 12px;
}
*, *::before, *::after { box-sizing: border-box; }
.co-page { font-family:'DM Sans',sans-serif; background:#f3f4f6; min-height:100vh; padding:2.5rem 0 5rem; }
.co-grid { display:grid; grid-template-columns:1fr 380px; gap:1.75rem; align-items:start; }
@media(max-width:960px){ .co-grid { grid-template-columns:1fr; } }

/* Steps */
.steps { display:flex; align-items:center; margin-bottom:2rem; }
.step { display:flex; align-items:center; gap:.5rem; font-size:.82rem; font-weight:600; color:var(--muted); }
.step.active { color:var(--ink); }
.step.done   { color:var(--green); }
.step-num { width:26px; height:26px; border-radius:50%; border:2px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; background:#fff; }
.step.active .step-num { border-color:var(--accent); background:var(--accent); color:#fff; }
.step.done .step-num   { border-color:var(--green); background:var(--green); color:#fff; }
.step-line { flex:1; height:1px; background:var(--border); margin:0 .5rem; min-width:24px; }

/* Cards */
.co-card { background:#fff; border-radius:var(--radius); padding:1.5rem; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:1.25rem; }
.co-card-title { font-family:'Playfair Display',serif; font-size:1.1rem; color:var(--ink); margin-bottom:1.25rem; display:flex; align-items:center; gap:.5rem; }

/* Fields */
.field-grid { display:grid; gap:.85rem; }
.field-grid.cols-2 { grid-template-columns:1fr 1fr; }
.field-grid.cols-3 { grid-template-columns:1fr 1fr 1fr; }
@media(max-width:600px){ .field-grid.cols-2,.field-grid.cols-3 { grid-template-columns:1fr; } }
.field { display:flex; flex-direction:column; gap:.3rem; }
.field label { font-size:.8rem; font-weight:600; color:var(--ink); letter-spacing:.02em; }
.field label .req { color:var(--red); margin-left:2px; }
.field input, .field select, .field textarea {
    padding:.65rem .85rem; border:.5px solid var(--border); border-radius:8px;
    font-family:'DM Sans',sans-serif; font-size:.9rem; color:var(--ink);
    background:#fff; transition:border-color .15s,box-shadow .15s; outline:none; width:100%;
}
.field input:focus, .field select:focus, .field textarea:focus {
    border-color:var(--accent); box-shadow:0 0 0 3px rgba(19,25,33,.08);
}
.field textarea { resize:vertical; min-height:72px; }
.field-hint { font-size:.75rem; color:var(--muted); }

/* Shipping pills */
.ship-options { display:flex; flex-direction:column; gap:.6rem; }
.ship-option { display:flex; align-items:center; gap:.85rem; padding:.9rem 1rem; border:.5px solid var(--border); border-radius:8px; cursor:pointer; transition:border-color .15s,background .15s; }
.ship-option:has(input:checked) { border-color:var(--accent); background:#f0f9ff; }
.ship-option input[type=radio] { accent-color:var(--accent); width:16px; height:16px; flex-shrink:0; }
.ship-option-info { flex:1; }
.ship-option-label { font-size:.9rem; font-weight:600; color:var(--ink); }
.ship-option-days  { font-size:.78rem; color:var(--muted); margin-top:1px; }
.ship-option-price { font-size:.9rem; font-weight:700; color:var(--ink); white-space:nowrap; }

/* Payment pills */
.pay-options { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.6rem; }
.pay-option { position:relative; }
.pay-option input[type=radio] { position:absolute; opacity:0; pointer-events:none; }
.pay-option label { display:flex; flex-direction:column; align-items:center; gap:.35rem; padding:.85rem .5rem; border:.5px solid var(--border); border-radius:8px; cursor:pointer; font-size:.82rem; font-weight:600; color:var(--muted); text-align:center; transition:all .15s; }
.pay-option label .pay-icon { font-size:1.4rem; }
.pay-option input:checked + label { border-color:var(--accent); background:var(--accent); color:#fff; }

/* Coupon */
.coupon-row { display:flex; gap:.5rem; }
.coupon-row input { flex:1; padding:.65rem .85rem; border:.5px solid var(--border); border-radius:8px; font-size:.9rem; outline:none; font-family:'DM Sans',sans-serif; }
.coupon-row input:focus { border-color:var(--accent); }
.coupon-btn { padding:.65rem 1.1rem; background:var(--accent); color:#fff; border:none; border-radius:8px; font-family:'DM Sans',sans-serif; font-size:.85rem; font-weight:600; cursor:pointer; white-space:nowrap; }
.coupon-btn:hover { background:#232f3e; }
.coupon-applied { display:flex; align-items:center; justify-content:space-between; background:#d1fae5; border-radius:8px; padding:.55rem .85rem; font-size:.85rem; font-weight:600; color:#065f46; }
.coupon-remove { background:none; border:none; color:#065f46; cursor:pointer; font-size:1rem; padding:0; }
.flash-err { background:#fee2e2; color:#991b1b; border-radius:8px; padding:.55rem .85rem; font-size:.83rem; font-weight:500; margin-top:.5rem; }
.flash-ok  { background:#d1fae5; color:#065f46; border-radius:8px; padding:.55rem .85rem; font-size:.83rem; font-weight:500; margin-top:.5rem; }

/* Error list */
.error-box { background:#fee2e2; border-radius:8px; padding:1rem 1.1rem; margin-bottom:1.25rem; }
.error-box ul { margin:0; padding-left:1.2rem; }
.error-box li { font-size:.85rem; color:#991b1b; font-weight:500; margin-bottom:.25rem; }

/* Summary */
.summary-panel { background:#fff; border-radius:var(--radius); padding:1.5rem; box-shadow:0 1px 3px rgba(0,0,0,.08); position:sticky; top:80px; }
.summary-title { font-family:'Playfair Display',serif; font-size:1.1rem; color:var(--ink); margin-bottom:1.1rem; }
.summary-items { display:flex; flex-direction:column; gap:.75rem; margin-bottom:1rem; }
.summary-item { display:flex; align-items:center; gap:.75rem; }
.summary-item img { width:48px; height:48px; object-fit:cover; border-radius:6px; border:.5px solid var(--border); flex-shrink:0; }
.summary-item-info { flex:1; min-width:0; }
.summary-item-name { font-size:.82rem; font-weight:600; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.summary-item-qty  { font-size:.76rem; color:var(--muted); }
.summary-item-price { font-size:.85rem; font-weight:700; color:var(--ink); white-space:nowrap; }
.divider { border:none; border-top:.5px solid var(--border); margin:.75rem 0; }
.sum-row { display:flex; justify-content:space-between; font-size:.87rem; color:var(--muted); margin-bottom:.4rem; }
.sum-row.discount { color:var(--green); }
.sum-row.total { font-size:1rem; font-weight:700; color:var(--ink); margin-top:.5rem; padding-top:.5rem; border-top:.5px solid var(--border); }
.place-btn { display:block; width:100%; margin-top:1.25rem; padding:.95rem; background:var(--amber); color:#fff; border:none; border-radius:8px; font-family:'DM Sans',sans-serif; font-size:1rem; font-weight:700; cursor:pointer; text-align:center; letter-spacing:.01em; transition:background .15s,transform .1s; }
.place-btn:hover { background:#d97706; transform:translateY(-1px); }
.secure-note { text-align:center; font-size:.75rem; color:var(--muted); margin-top:.65rem; }
</style>

<div class="co-page">
<div class="container">

    <div class="steps">
        <div class="step done"><div class="step-num">✓</div> Cart</div>
        <div class="step-line"></div>
        <div class="step active"><div class="step-num">2</div> Shipping & Payment</div>
        <div class="step-line"></div>
        <div class="step"><div class="step-num">3</div> Confirmation</div>
    </div>

    <?php if (!empty($order_errors)): ?>
    <div class="error-box">
        <ul>
            <?php foreach ($order_errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="checkout.php" id="checkoutForm">
    <div class="co-grid">

        <!-- LEFT COLUMN -->
        <div>
            <!-- 1. Delivery information -->
            <div class="co-card">
                <div class="co-card-title"><span>📦</span> Delivery Information</div>
                <div class="field-grid">
                    <div class="field-grid cols-2">
                        <div class="field">
                            <label>Full Name <span class="req">*</span></label>
                            <input type="text" name="ship_name" required autocomplete="name"
                                   value="<?= htmlspecialchars($_POST['ship_name'] ?? $profile['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field">
                            <label>Phone Number <span class="req">*</span></label>
                            <input type="tel" name="ship_phone" required autocomplete="tel"
                                   placeholder="e.g. 01XXXXXXXXX"
                                   value="<?= htmlspecialchars($_POST['ship_phone'] ?? $profile['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label>Email Address</label>
                        <input type="email" name="ship_email" autocomplete="email"
                               placeholder="For order updates (optional)"
                               value="<?= htmlspecialchars($_POST['ship_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="field">
                        <label>Street Address <span class="req">*</span></label>
                        <textarea name="ship_address" required rows="2"
                                  placeholder="House / Road / Block / Area"><?= htmlspecialchars($_POST['ship_address'] ?? $profile['address'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="field-grid cols-3">
                        <div class="field">
                            <label>City <span class="req">*</span></label>
                            <input type="text" name="ship_city" required
                                   value="<?= htmlspecialchars($_POST['ship_city'] ?? $profile['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field">
                            <label>ZIP / Postal Code</label>
                            <input type="text" name="ship_zip" placeholder="e.g. 1207"
                                   value="<?= htmlspecialchars($_POST['ship_zip'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field">
                            <label>Country</label>
                            <select name="ship_country">
                                <?php foreach (['Bangladesh','India','Pakistan','Nepal','Sri Lanka','Other'] as $c): ?>
                                <option value="<?= $c ?>"
                                    <?= (($_POST['ship_country'] ?? 'Bangladesh') === $c) ? 'selected' : '' ?>>
                                    <?= $c ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label>Delivery Notes</label>
                        <textarea name="ship_notes" rows="2"
                                  placeholder="Gate code, landmark, preferred delivery time…"><?= htmlspecialchars($_POST['ship_notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        <span class="field-hint">Optional — helps our courier find you faster.</span>
                    </div>
                </div>
            </div>

            <!-- 2. Shipping method -->
            <div class="co-card">
                <div class="co-card-title"><span>🚚</span> Shipping Method</div>
                <div class="ship-options">
                    <?php foreach ($shipping_options as $key => $opt): ?>
                    <label class="ship-option">
                        <input type="radio" name="shipping_method" value="<?= $key ?>"
                               <?= $shipping_method === $key ? 'checked' : '' ?>
                               onchange="this.form.submit()">
                        <div class="ship-option-info">
                            <div class="ship-option-label"><?= htmlspecialchars($opt['label']) ?></div>
                            <div class="ship-option-days"><?= htmlspecialchars($opt['days']) ?></div>
                        </div>
                        <div class="ship-option-price">৳ <?= number_format($opt['cost']) ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 3. Payment method -->
            <div class="co-card">
                <div class="co-card-title"><span>💳</span> Payment Method</div>
                <div class="pay-options">
                    <?php foreach ($payment_methods as $key => $pm): ?>
                    <div class="pay-option">
                        <input type="radio" name="payment_method"
                               id="pm-<?= $key ?>" value="<?= $key ?>"
                               <?= $payment_key === $key ? 'checked' : '' ?>>
                        <label for="pm-<?= $key ?>">
                            <span class="pay-icon"><?= $pm['icon'] ?></span>
                            <?= htmlspecialchars($pm['label']) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 4. Coupon -->
            <div class="co-card">
                <div class="co-card-title"><span>🏷</span> Coupon Code</div>
                <?php if (empty($coupon_code)): ?>
                <div class="coupon-row">
                    <input type="text" name="coupon_code" placeholder="Enter code"
                           style="text-transform:uppercase"
                           value="<?= htmlspecialchars($_POST['coupon_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" name="apply_coupon" class="coupon-btn">Apply</button>
                </div>
                <?php if (!empty($coupon_error)): ?>
                    <div class="flash-err"><?= htmlspecialchars($coupon_error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php else: ?>
                <div class="coupon-applied">
                    <span>🎉 <strong><?= htmlspecialchars($coupon_code, ENT_QUOTES, 'UTF-8') ?></strong> applied</span>
                    <button type="submit" name="remove_coupon" class="coupon-remove" title="Remove">✕</button>
                </div>
                <?php if (!empty($coupon_success)): ?>
                    <div class="flash-ok"><?= htmlspecialchars($coupon_success, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: order summary -->
        <div>
            <div class="summary-panel">
                <div class="summary-title">Order Summary</div>
                <div class="summary-items">
                    <?php foreach ($items as $item): ?>
                    <div class="summary-item">
                        <img src="../<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="summary-item-info">
                            <div class="summary-item-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="summary-item-qty">Qty: <?= intval($item['quantity']) ?></div>
                        </div>
                        <div class="summary-item-price">৳ <?= number_format($item['line_total']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <hr class="divider">

                <div class="sum-row">
                    <span>Subtotal (<?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?>)</span>
                    <span>৳ <?= number_format($subtotal) ?></span>
                </div>
                <?php if ($discount > 0): ?>
                <div class="sum-row discount">
                    <span>Coupon discount</span>
                    <span>−৳ <?= number_format($discount) ?></span>
                </div>
                <?php endif; ?>
                <div class="sum-row">
                    <span>Shipping (<?= htmlspecialchars($shipping_options[$shipping_method]['label']) ?>)</span>
                    <span>৳ <?= number_format($shipping_cost) ?></span>
                </div>
                <div class="sum-row total">
                    <span>Total</span>
                    <span>৳ <?= number_format($final_total) ?></span>
                </div>

                <button type="submit" name="place_order" class="place-btn">
                    Place Order — ৳ <?= number_format($final_total) ?>
                </button>
                <div class="secure-note">🔒 Your information is secure and encrypted</div>
            </div>
        </div>

    </div>
    </form>
</div>
</div>

<?php include("../includes/footer.php"); ?>