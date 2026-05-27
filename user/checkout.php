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

// ── Fetch selected cart items (safe IN-clause builder) ────────────────────────
function fetch_selected_items(mysqli $conn, int $user_id, array $ids): array {
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = 'i' . str_repeat('i', count($ids));
    $params       = array_merge([$user_id], $ids);

    $sql = "
        SELECT c.product_id, c.quantity,
               p.name, p.price, p.image, p.stock, p.seller_id
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
          AND c.product_id IN ($placeholders)
          AND p.status = 'approved'
        ORDER BY c.created_at DESC
    ";
    // Note: we also fetch p.seller_id now — needed for order_items.seller_id

    $stmt = $conn->prepare($sql);
    $bind_params   = [];
    $bind_params[] = &$types;
    foreach ($params as $k => $v) {
        $params[$k]    = $v;
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

// ── NEW: Load user's saved addresses ─────────────────────────────────────────
// Uses the new `addresses` table from the upgrade migration.
$saved_addresses = [];
$stmt = $conn->prepare("
    SELECT * FROM addresses
    WHERE user_id = ?
    ORDER BY is_default DESC, id DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$addr_result = $stmt->get_result();
while ($a = $addr_result->fetch_assoc()) {
    $saved_addresses[] = $a;
}
$stmt->close();

// ── Coupon ────────────────────────────────────────────────────────────────────
$discount       = 0;
$coupon_row     = null;
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
              AND (max_uses IS NULL OR used_count < max_uses)
        ");
        $stmt->bind_param("s", $code_input);
        $stmt->execute();
        $found_coupon = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($found_coupon && $subtotal >= floatval($found_coupon['min_order_amount'])) {
            // NEW: check per-user usage limit using coupon_usage table
            $max_per_user = intval($found_coupon['max_uses_per_user'] ?? 1);
            $stmt = $conn->prepare("
                SELECT COUNT(*) as cnt FROM coupon_usage
                WHERE coupon_id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $found_coupon['id'], $user_id);
            $stmt->execute();
            $usage_count = intval($stmt->get_result()->fetch_assoc()['cnt']);
            $stmt->close();

            if ($usage_count >= $max_per_user) {
                $coupon_error = "You have already used this coupon the maximum number of times.";
            } else {
                $_SESSION['applied_coupon'] = $code_input;
                $coupon_success = "Coupon applied!";
            }
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
          AND (max_uses IS NULL OR used_count < max_uses)
    ");
    $stmt->bind_param("s", $_SESSION['applied_coupon']);
    $stmt->execute();
    $coupon_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($coupon_row && $subtotal >= floatval($coupon_row['min_order_amount'])) {
        $coupon_code = $_SESSION['applied_coupon'];
        $discount    = $coupon_row['discount_type'] === 'percentage'
            ? $subtotal * ($coupon_row['discount_value'] / 100)
            : floatval($coupon_row['discount_value']);
    } else {
        $_SESSION['applied_coupon'] = null;
        $coupon_row = null;
    }
}

// ── Shipping options ──────────────────────────────────────────────────────────
$shipping_options = [
    'standard' => ['label' => 'Standard Delivery',  'days' => '5–7 business days',         'cost' => 60],
    'express'  => ['label' => 'Express Delivery',   'days' => '2–3 business days',          'cost' => 120],
    'same_day' => ['label' => 'Same-Day Delivery',  'days' => 'Today (order before 12 PM)', 'cost' => 200],
];
$shipping_method = $_POST['shipping_method'] ?? $_SESSION['shipping_method'] ?? 'standard';
if (!array_key_exists($shipping_method, $shipping_options)) {
    $shipping_method = 'standard';
}
$_SESSION['shipping_method'] = $shipping_method;
$shipping_cost = $shipping_options[$shipping_method]['cost'];

// ── Payment methods ───────────────────────────────────────────────────────────
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
$payment_label = $payment_methods[$payment_key]['label'];

$final_total = max(0, $subtotal - $discount) + $shipping_cost;

// ── Fetch user profile for pre-fill ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, phone, address, city FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── PLACE ORDER ───────────────────────────────────────────────────────────────
if (isset($_POST['place_order'])) {

    $ship_name    = trim(strip_tags($_POST['ship_name']    ?? ''));
    $ship_phone   = trim(strip_tags($_POST['ship_phone']   ?? ''));
    $ship_email   = trim($_POST['ship_email']              ?? '');
    $ship_address = trim(strip_tags($_POST['ship_address'] ?? ''));
    $ship_city    = trim(strip_tags($_POST['ship_city']    ?? ''));
    $ship_zip     = trim(strip_tags($_POST['ship_zip']     ?? ''));
    $ship_country = trim(strip_tags($_POST['ship_country'] ?? 'Bangladesh'));
    $ship_notes   = trim(strip_tags($_POST['ship_notes']   ?? ''));
    $payment_key  = $_POST['payment_method'] ?? 'cod';
    if (!array_key_exists($payment_key, $payment_methods)) $payment_key = 'cod';
    $payment_label = $payment_methods[$payment_key]['label'];

    // NEW: user can select a saved address by ID
    $save_address = isset($_POST['save_address']) && $_POST['save_address'] == '1';
    $selected_address_id = isset($_POST['selected_address_id']) && intval($_POST['selected_address_id']) > 0
        ? intval($_POST['selected_address_id'])
        : null;

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

            // ── Stock + status re-validation (with row lock) ──────────────
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

            // ── INSERT orders ─────────────────────────────────────────────
            // Still keeps the JSON snapshot in `notes` for backward compatibility.
            // Also writes to the new `order_addresses` table (structured).
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

            $coupon_used     = !empty($coupon_code) ? $coupon_code : null;
            $final_total_f   = (float)$final_total;
            $shipping_cost_f = (float)$shipping_cost;
            $discount_f      = (float)$discount;

            $stmt = $conn->prepare("
                INSERT INTO orders
                    (user_id, total_amount, shipping_cost, discount_amount,
                     coupon_code, status, notes)
                VALUES (?, ?, ?, ?, ?, 'Pending', ?)
            ");
            $stmt->bind_param("idddss",
                $user_id, $final_total_f, $shipping_cost_f,
                $discount_f, $coupon_used, $shipping_snapshot
            );
            $stmt->execute();
            $order_id = $conn->insert_id;
            $stmt->close();

           

            $stmt = $conn->prepare("
                INSERT INTO order_addresses
                    (order_id, address_id, recipient_name, phone, email,
                     address_line, city, zip, country, delivery_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param("iissssssss",
                $order_id, $selected_address_id,
                $ship_name, $ship_phone, $ship_email,
                $ship_address, $ship_city, $ship_zip,
                $ship_country, $ship_notes
            );
            $stmt->execute();
            $stmt->close();

            // ── NEW: Optionally save address to address book ──────────────
            $new_address_id = $selected_address_id;
            if ($save_address && $selected_address_id === null) {
                $stmt = $conn->prepare("
                    INSERT INTO addresses
                        (user_id, label, recipient_name, phone,
                         address_line, city, zip, country, is_default)
                    VALUES (?, 'Home', ?, ?, ?, ?, ?, 'Bangladesh', 0)
                ");
                $stmt->bind_param("isssss",
                    $user_id, $ship_name, $ship_phone,
                    $ship_address, $ship_city, $ship_zip
                );
                $stmt->execute();
                $new_address_id = $conn->insert_id;
                $stmt->close();

                // Update order_addresses to link the newly saved address
                $stmt = $conn->prepare("
                    UPDATE order_addresses SET address_id = ? WHERE order_id = ?
                ");
                $stmt->bind_param("ii", $new_address_id, $order_id);
                $stmt->execute();
                $stmt->close();
            }

            // ── INSERT order_items (now includes seller_id) ───────────────
            foreach ($items as $item) {
    $seller_id = $item['seller_id'] ? intval($item['seller_id']) : null;

    if ($seller_id !== null) {
        $stmt = $conn->prepare("
            INSERT INTO order_items
                (order_id, product_id, quantity, price, seller_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiidi",
            $order_id, $item['product_id'],
            $item['quantity'], $item['price'], $seller_id
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO order_items
                (order_id, product_id, quantity, price, seller_id)
            VALUES (?, ?, ?, ?, NULL)
        ");
        $stmt->bind_param("iiid",
            $order_id, $item['product_id'],
            $item['quantity'], $item['price']
        );
    }
    $stmt->execute();
    $stmt->close();

                // Deduct stock (with guard: only if stock >= qty)
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

            // ── INSERT payments (full label stored, amount recorded) ──────
            $stmt = $conn->prepare("
                INSERT INTO payments
                    (order_id, payment_method, payment_status, amount, currency)
                VALUES (?, ?, 'Pending', ?, 'BDT')
            ");
            $stmt->bind_param("isd", $order_id, $payment_label, $final_total_f);
            $stmt->execute();
            $stmt->close();

            // ── NEW: Insert initial shipment record ───────────────────────
            // Creates a 'pending' shipment row so admin/seller can assign courier later.
            $stmt = $conn->prepare("
                INSERT INTO shipments
                    (order_id, shipping_status, destination_address)
                VALUES (?, 'pending', ?)
            ");
            $dest = "$ship_address, $ship_city, $ship_zip, $ship_country";
            $stmt->bind_param("is", $order_id, $dest);
            $stmt->execute();
            $stmt->close();

            // ── NEW: Coupon usage tracking ────────────────────────────────
            // Records this user's use of the coupon in coupon_usage table.
            // Also increments the global used_count on the coupons table.
            if (!empty($coupon_code) && $coupon_row) {
                $stmt = $conn->prepare("
                    INSERT INTO coupon_usage
                        (coupon_id, user_id, order_id, discount_given)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param("iiid",
                    $coupon_row['id'], $user_id, $order_id, $discount_f
                );
                $stmt->execute();
                $stmt->close();

                // Increment global used_count
                $stmt = $conn->prepare("
                    UPDATE coupons SET used_count = used_count + 1 WHERE id = ?
                ");
                $stmt->bind_param("i", $coupon_row['id']);
                $stmt->execute();
                $stmt->close();
            }

            // ── NEW: Call stored procedure to split order by seller ───────
            // Creates vendor_orders rows, links order_items, creates
            // seller_transactions — all handled inside the procedure.
            // Products without a seller_id (platform products) are skipped.
            $conn->query("CALL sp_create_vendor_orders($order_id)");
            
            

            // ── NEW: Send in-app notification to buyer ────────────────────
            $stmt = $conn->prepare("
                INSERT INTO notifications
                    (user_id, type, title, message, link)
                VALUES (?, 'order_placed', 'Order Placed Successfully',
                        ?, ?)
            ");
            $notif_msg  = "Your order #$order_id has been placed. Total: ৳" . number_format($final_total_f);
            $notif_link = "/user/order_details.php?id=$order_id";
            $stmt->bind_param("iss", $user_id, $notif_msg, $notif_link);
            $stmt->execute();
            $stmt->close();

            // ── Remove checked-out items from cart ────────────────────────
            foreach ($selected_ids as $pid) {
                $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("ii", $user_id, $pid);
                $stmt->execute();
                $stmt->close();
            }

            // ── Clear session ─────────────────────────────────────────────
            $_SESSION['applied_coupon']    = null;
            $_SESSION['checkout_products'] = [];
            $_SESSION['shipping_method']   = null;

            $conn->commit();
            header("Location: orders.php?success=1&order_id=" . $order_id);
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $order_errors[] = $e->getMessage();
        }
    } else {
        $order_errors = $errors;
    }
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

/* Saved address cards */
.saved-addr-list { display:flex; flex-direction:column; gap:.6rem; margin-bottom:1rem; }
.saved-addr-option { display:flex; align-items:flex-start; gap:.75rem; padding:.85rem 1rem; border:.5px solid var(--border); border-radius:8px; cursor:pointer; transition:border-color .15s,background .15s; }
.saved-addr-option:has(input:checked) { border-color:var(--accent); background:#f0f9ff; }
.saved-addr-option input[type=radio] { accent-color:var(--accent); margin-top:3px; flex-shrink:0; }
.saved-addr-label { font-size:.82rem; color:var(--ink); }
.saved-addr-label strong { display:block; font-size:.88rem; margin-bottom:2px; }
.addr-divider { text-align:center; font-size:.78rem; color:var(--muted); margin:.5rem 0; position:relative; }
.addr-divider::before { content:''; position:absolute; top:50%; left:0; right:0; height:1px; background:var(--border); z-index:0; }
.addr-divider span { background:#fff; padding:0 .75rem; position:relative; z-index:1; }

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
.save-addr-check { display:flex; align-items:center; gap:.5rem; font-size:.83rem; color:var(--ink); margin-top:.25rem; cursor:pointer; }
.save-addr-check input { accent-color:var(--accent); width:15px; height:15px; }

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

        <!-- ── LEFT COLUMN ── -->
        <div>

            <!-- 1. Delivery Information -->
            <div class="co-card">
                <div class="co-card-title"><span>📦</span> Delivery Information</div>

                <!-- NEW: Saved address selector (only shown if user has saved addresses) -->
                <?php if (!empty($saved_addresses)): ?>
                <div class="saved-addr-list" id="savedAddrList">
                    <?php foreach ($saved_addresses as $sa): ?>
                    <label class="saved-addr-option">
                        <input type="radio" name="selected_address_id"
                               value="<?= intval($sa['id']) ?>"
                               onchange="fillAddress(this)"
                               data-name="<?= htmlspecialchars($sa['recipient_name'], ENT_QUOTES) ?>"
                               data-phone="<?= htmlspecialchars($sa['phone'], ENT_QUOTES) ?>"
                               data-address="<?= htmlspecialchars($sa['address_line'], ENT_QUOTES) ?>"
                               data-city="<?= htmlspecialchars($sa['city'], ENT_QUOTES) ?>"
                               data-zip="<?= htmlspecialchars($sa['zip'] ?? '', ENT_QUOTES) ?>">
                        <div class="saved-addr-label">
                            <strong><?= htmlspecialchars($sa['recipient_name']) ?> — <?= htmlspecialchars($sa['label']) ?></strong>
                            <?= htmlspecialchars($sa['address_line']) ?>,
                            <?= htmlspecialchars($sa['city']) ?>
                            <?php if (!empty($sa['zip'])): ?> – <?= htmlspecialchars($sa['zip']) ?><?php endif; ?>
                            <br><small style="color:var(--muted)"><?= htmlspecialchars($sa['phone']) ?></small>
                        </div>
                    </label>
                    <?php endforeach; ?>
                    <!-- Option to enter a new address instead -->
                    <label class="saved-addr-option">
                        <input type="radio" name="selected_address_id"
                               value="0" onchange="clearAddress()" checked>
                        <div class="saved-addr-label">
                            <strong>+ Enter a new address</strong>
                        </div>
                    </label>
                </div>
                <div class="addr-divider"><span>or fill in below</span></div>
                <?php else: ?>
                <input type="hidden" name="selected_address_id" value="0">
                <?php endif; ?>

                <div class="field-grid" id="addressFields">
                    <div class="field-grid cols-2">
                        <div class="field">
                            <label>Full Name <span class="req">*</span></label>
                            <input type="text" name="ship_name" id="ship_name" required autocomplete="name"
                                   value="<?= htmlspecialchars($_POST['ship_name'] ?? $profile['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field">
                            <label>Phone Number <span class="req">*</span></label>
                            <input type="tel" name="ship_phone" id="ship_phone" required autocomplete="tel"
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
                        <textarea name="ship_address" id="ship_address" required rows="2"
                                  placeholder="House / Road / Block / Area"><?= htmlspecialchars($_POST['ship_address'] ?? $profile['address'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="field-grid cols-3">
                        <div class="field">
                            <label>City <span class="req">*</span></label>
                            <input type="text" name="ship_city" id="ship_city" required
                                   value="<?= htmlspecialchars($_POST['ship_city'] ?? $profile['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field">
                            <label>ZIP / Postal Code</label>
                            <input type="text" name="ship_zip" id="ship_zip" placeholder="e.g. 1207"
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

                    <!-- NEW: Save address to address book checkbox -->
                    <label class="save-addr-check">
                        <input type="checkbox" name="save_address" value="1">
                        Save this address for future orders
                    </label>
                </div>
            </div>

            <!-- 2. Shipping Method -->
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

            <!-- 3. Payment Method -->
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

        </div><!-- /left col -->

        <!-- ── RIGHT COLUMN: Order Summary ── -->
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

<!-- NEW: JS to auto-fill address fields when a saved address is selected -->
<script>
function fillAddress(radio) {
    document.getElementById('ship_name').value    = radio.dataset.name;
    document.getElementById('ship_phone').value   = radio.dataset.phone;
    document.getElementById('ship_address').value = radio.dataset.address;
    document.getElementById('ship_city').value    = radio.dataset.city;
    document.getElementById('ship_zip').value     = radio.dataset.zip;
}
function clearAddress() {
    ['ship_name','ship_phone','ship_address','ship_city','ship_zip']
        .forEach(id => { document.getElementById(id).value = ''; });
}
</script>

<?php include("../includes/footer.php"); ?>