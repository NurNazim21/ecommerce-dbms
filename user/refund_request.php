<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id  = intval($_SESSION['user_id']);
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if (!$order_id) { header("Location: orders.php"); exit(); }

// ── Verify order belongs to user and is Delivered ────────────────────────────
$stmt = $conn->prepare("
    SELECT o.id, o.total_amount, o.status, o.created_at,
           p.payment_method, p.payment_status
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.id
    WHERE o.id = ? AND o.user_id = ?
");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) { header("Location: orders.php"); exit(); }

// Only allow refund within 7 days of order creation (not delivery date since we may not track it)
$days_since = (time() - strtotime($order['created_at'])) / 86400;
if ($order['status'] !== 'Delivered' || $days_since > 7) {
    header("Location: order_details.php?id=$order_id");
    exit();
}

// ── Check if refund already requested ────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, status FROM refunds WHERE order_id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$existing_refund = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Fetch order items ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT oi.id, oi.quantity, oi.price, oi.item_subtotal, oi.seller_id,
           p.name AS product_name, p.image
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Refund reasons ────────────────────────────────────────────────────────────
$reasons = [
    'wrong_item'     => 'Received wrong item',
    'damaged'        => 'Item arrived damaged or defective',
    'not_as_desc'    => 'Item not as described',
    'missing_parts'  => 'Missing parts or accessories',
    'changed_mind'   => 'Changed my mind',
    'duplicate'      => 'Accidentally ordered duplicate',
    'other'          => 'Other',
];

$refund_methods = [
    'bkash'       => 'bKash',
    'nagad'       => 'Nagad',
    'bank'        => 'Bank Transfer',
    'store_credit'=> 'Store Credit',
];

$errors       = [];
$submit_done  = false;

// ── HANDLE SUBMISSION ─────────────────────────────────────────────────────────
if (isset($_POST['submit_refund']) && !$existing_refund) {

    $reason         = $_POST['reason'] ?? '';
    $details        = trim(strip_tags($_POST['details'] ?? ''));
    $refund_method  = $_POST['refund_method'] ?? '';
    $refund_item_id = isset($_POST['order_item_id']) && intval($_POST['order_item_id']) > 0
                        ? intval($_POST['order_item_id'])
                        : null;

    // Determine refund amount
    if ($refund_item_id) {
        // Single item refund
        $item_row = array_filter($items_result, fn($i) => $i['id'] === $refund_item_id);
        $item_row = array_values($item_row);
        $amount   = $item_row ? floatval($item_row[0]['item_subtotal']) : 0;
        $seller_id = $item_row ? intval($item_row[0]['seller_id']) : null;
    } else {
        // Full order refund
        $amount    = floatval($order['total_amount']);
        $seller_id = null;
    }

    // Validate
    if (!array_key_exists($reason, $reasons))        $errors[] = "Please select a valid reason.";
    if (!array_key_exists($refund_method, $refund_methods)) $errors[] = "Please select a refund method.";
    if (empty($details))                              $errors[] = "Please describe the issue.";
    if ($amount <= 0)                                 $errors[] = "Invalid refund amount.";

    // Handle evidence image upload
    $evidence_image = null;
    if (!empty($_FILES['evidence']['name'])) {
        $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
        $file_type     = mime_content_type($_FILES['evidence']['tmp_name']);
        $file_size     = $_FILES['evidence']['size'];

        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Evidence image must be JPG, PNG, WebP, or GIF.";
        } elseif ($file_size > 5 * 1024 * 1024) {
            $errors[] = "Evidence image must be under 5MB.";
        } else {
            $ext            = pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION);
            $filename       = 'refund_' . $order_id . '_' . time() . '.' . $ext;
            $upload_path    = "../assets/images/refunds/" . $filename;
            if (!is_dir("../assets/images/refunds/")) {
                mkdir("../assets/images/refunds/", 0755, true);
            }
            if (move_uploaded_file($_FILES['evidence']['tmp_name'], $upload_path)) {
                $evidence_image = 'assets/images/refunds/' . $filename;
            } else {
                $errors[] = "Failed to upload evidence image. Please try again.";
            }
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO refunds
                (order_id, order_item_id, user_id, seller_id,
                 amount, reason, details, evidence_image,
                 refund_method, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'requested')
        ");
        $stmt->bind_param("iiiidsss s",
            $order_id, $refund_item_id, $user_id, $seller_id,
            $amount, $reason, $details, $evidence_image, $refund_method
        );
        // fix bind string — no space
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO refunds
                (order_id, order_item_id, user_id, seller_id,
                 amount, reason, details, evidence_image,
                 refund_method, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'requested')
        ");
        $stmt->bind_param("iiiidssss",
            $order_id, $refund_item_id, $user_id, $seller_id,
            $amount, $reason, $details, $evidence_image, $refund_method
        );
        $stmt->execute();
        $refund_id = $conn->insert_id;
        $stmt->close();

        // Notify admin via notifications (admin user_id = 1 or use role check)
        $notif_msg  = "Refund requested for Order #$order_id — ৳" . number_format($amount);
        $notif_link = "/admin/manage_orders.php?refund_id=$refund_id";
        // Find an admin to notify
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        if ($admin) {
            $admin_id = intval($admin['id']);
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, 'refund_request', 'New Refund Request', ?, ?)
            ");
            $stmt->bind_param("iss", $admin_id, $notif_msg, $notif_link);
            $stmt->execute();
            $stmt->close();
        }

        // Notify buyer confirmation
        $buyer_msg  = "Your refund request for Order #$order_id has been submitted. We'll review it shortly.";
        $buyer_link = "/user/order_details.php?id=$order_id";
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, 'refund_submitted', 'Refund Request Submitted', ?, ?)
        ");
        $stmt->bind_param("iss", $user_id, $buyer_msg, $buyer_link);
        $stmt->execute();
        $stmt->close();

        $submit_done    = true;
        $existing_refund = ['id' => $refund_id, 'status' => 'requested'];
    }
}
?>
<?php include("../includes/header.php"); ?>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">

<style>
:root{--ink:#0f0f0f;--muted:#6b7280;--border:#e5e7eb;--surface:#f9fafb;--accent:#131921;--amber:#f59e0b;--red:#ef4444;--green:#16a34a;--radius:12px;}
*,*::before,*::after{box-sizing:border-box;}
.rf-page{font-family:'DM Sans',sans-serif;background:#f3f4f6;min-height:100vh;padding:2.5rem 0 5rem;}
.rf-grid{display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start;}
@media(max-width:860px){.rf-grid{grid-template-columns:1fr;}}
.rf-card{background:#fff;border-radius:var(--radius);padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.25rem;}
.rf-card-title{font-family:'Playfair Display',serif;font-size:1.05rem;color:var(--ink);margin-bottom:1.1rem;display:flex;align-items:center;gap:.5rem;}
.back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--muted);text-decoration:none;margin-bottom:1.25rem;font-weight:500;}
.back-link:hover{color:var(--ink);}
.page-heading{font-family:'Playfair Display',serif;font-size:1.65rem;color:var(--ink);margin-bottom:.35rem;}
.page-sub{font-size:.85rem;color:var(--muted);margin-bottom:1.5rem;}
.field{display:flex;flex-direction:column;gap:.3rem;margin-bottom:1rem;}
.field label{font-size:.8rem;font-weight:700;color:var(--ink);letter-spacing:.02em;}
.field label .req{color:var(--red);margin-left:2px;}
.field select,.field textarea,.field input[type=text]{padding:.65rem .85rem;border:.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--ink);background:#fff;transition:border-color .15s;outline:none;width:100%;}
.field select:focus,.field textarea:focus,.field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(19,25,33,.08);}
.field textarea{resize:vertical;min-height:96px;}
.field-hint{font-size:.75rem;color:var(--muted);margin-top:2px;}
.item-radio{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border:.5px solid var(--border);border-radius:8px;cursor:pointer;transition:border-color .15s,background .15s;margin-bottom:.5rem;}
.item-radio:has(input:checked){border-color:var(--accent);background:#f0f9ff;}
.item-radio input{accent-color:var(--accent);flex-shrink:0;}
.item-radio img{width:44px;height:44px;object-fit:cover;border-radius:6px;border:.5px solid var(--border);flex-shrink:0;}
.item-radio-info{flex:1;}
.item-radio-name{font-size:.85rem;font-weight:600;color:var(--ink);}
.item-radio-price{font-size:.76rem;color:var(--muted);}
.submit-btn{display:block;width:100%;padding:.9rem;background:#dc2626;color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;transition:background .15s;margin-top:.5rem;}
.submit-btn:hover{background:#b91c1c;}
.error-box{background:#fee2e2;border-radius:8px;padding:.9rem 1.1rem;margin-bottom:1.25rem;}
.error-box ul{margin:0;padding-left:1.2rem;}
.error-box li{font-size:.85rem;color:#991b1b;font-weight:500;margin-bottom:.2rem;}
.success-box{background:#d1fae5;border-radius:10px;padding:1.5rem;text-align:center;margin-bottom:1.25rem;}
.success-box .si{font-size:2.5rem;margin-bottom:.5rem;}
.success-box h4{font-family:'Playfair Display',serif;color:#065f46;margin-bottom:.35rem;}
.success-box p{font-size:.85rem;color:#047857;margin:0;}
.existing-box{background:#fef9c3;border-radius:10px;padding:1.25rem;border:.5px solid #fde68a;}
.existing-box h5{font-size:.95rem;font-weight:700;color:#854d0e;margin-bottom:.35rem;}
.existing-box p{font-size:.83rem;color:#92400e;margin:0;}
.sum-row{display:flex;justify-content:space-between;font-size:.86rem;color:var(--muted);margin-bottom:.35rem;}
.sum-row.total{font-weight:700;color:var(--ink);padding-top:.4rem;border-top:.5px solid var(--border);margin-top:.4rem;}
.policy-item{display:flex;align-items:flex-start;gap:.6rem;font-size:.8rem;color:var(--muted);margin-bottom:.5rem;}
.policy-item .pi{font-size:1rem;flex-shrink:0;margin-top:1px;}
</style>

<div class="rf-page"><div class="container">

<a href="order_details.php?id=<?= $order_id ?>" class="back-link">← Back to Order #<?= $order_id ?></a>
<h1 class="page-heading">Request a Refund</h1>
<div class="page-sub">Order #<?= $order_id ?> — placed <?= date('d M Y', strtotime($order['created_at'])) ?></div>

<?php if ($submit_done): ?>
<div class="success-box">
    <div class="si">✅</div>
    <h4>Refund Request Submitted</h4>
    <p>We've received your request and will review it within 2–3 business days. You'll be notified of the decision.</p>
</div>
<?php elseif ($existing_refund): ?>
<div class="existing-box">
    <h5>⏳ Refund Already Requested</h5>
    <p>You already have a refund request for this order with status: <strong><?= ucfirst(str_replace('_',' ',$existing_refund['status'])) ?></strong>. Our team is reviewing it.</p>
</div>
<?php else: ?>

<?php if (!empty($errors)): ?>
<div class="error-box"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<div class="rf-grid">

  <!-- LEFT -->
  <div>

    <!-- Step 1: What to refund -->
    <div class="rf-card">
      <div class="rf-card-title">📦 What would you like to return?</div>

      <!-- Full order option -->
      <label class="item-radio">
        <input type="radio" name="order_item_id" value="0" checked>
        <div class="item-radio-info">
          <div class="item-radio-name">Entire Order</div>
          <div class="item-radio-price">All <?= count($items_result) ?> item<?= count($items_result) !== 1 ? 's' : '' ?> — ৳ <?= number_format($order['total_amount']) ?></div>
        </div>
      </label>

      <!-- Individual items -->
      <?php foreach ($items_result as $item): ?>
      <label class="item-radio">
        <input type="radio" name="order_item_id" value="<?= intval($item['id']) ?>">
        <img src="../<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
        <div class="item-radio-info">
          <div class="item-radio-name"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="item-radio-price">Qty <?= intval($item['quantity']) ?> × ৳ <?= number_format($item['price']) ?> = ৳ <?= number_format($item['item_subtotal']) ?></div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>

    <!-- Step 2: Reason -->
    <div class="rf-card">
      <div class="rf-card-title">❓ Reason for return</div>
      <div class="field">
        <label>Reason <span class="req">*</span></label>
        <select name="reason" required>
          <option value="">— Select a reason —</option>
          <?php foreach ($reasons as $key => $label): ?>
          <option value="<?= $key ?>" <?= ($_POST['reason'] ?? '') === $key ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Describe the issue <span class="req">*</span></label>
        <textarea name="details" placeholder="Please describe the problem in detail…" required><?= htmlspecialchars($_POST['details'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </div>

    <!-- Step 3: Evidence -->
    <div class="rf-card">
      <div class="rf-card-title">📷 Evidence (optional)</div>
      <div class="field">
        <label>Upload a photo</label>
        <input type="file" name="evidence" accept="image/*" style="padding:.5rem .85rem;border:.5px solid var(--border);border-radius:8px;font-size:.88rem;">
        <span class="field-hint">JPG, PNG, WebP — max 5MB. Helps speed up your refund review.</span>
      </div>
    </div>

    <!-- Step 4: Refund method -->
    <div class="rf-card">
      <div class="rf-card-title">💳 Preferred refund method</div>
      <div class="field">
        <label>How should we refund you? <span class="req">*</span></label>
        <select name="refund_method" required>
          <option value="">— Select —</option>
          <?php foreach ($refund_methods as $key => $label): ?>
          <option value="<?= $key ?>" <?= ($_POST['refund_method'] ?? '') === $key ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <span class="field-hint">Refunds are typically processed within 3–5 business days after approval.</span>
      </div>
    </div>

  </div><!-- /left -->

  <!-- RIGHT -->
  <div>

    <!-- Order summary -->
    <div class="rf-card">
      <div class="rf-card-title">📋 Order Summary</div>
      <div class="sum-row"><span>Order #<?= $order_id ?></span><span><?= date('d M Y', strtotime($order['created_at'])) ?></span></div>
      <div class="sum-row"><span>Payment</span><span><?= htmlspecialchars($order['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span></div>
      <div class="sum-row"><span>Payment Status</span><span><?= htmlspecialchars($order['payment_status'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span></div>
      <div class="sum-row total"><span>Order Total</span><span>৳ <?= number_format($order['total_amount']) ?></span></div>
    </div>

    <!-- Return policy -->
    <div class="rf-card">
      <div class="rf-card-title">📜 Return Policy</div>
      <div class="policy-item"><span class="pi">✅</span> Returns accepted within 7 days of delivery.</div>
      <div class="policy-item"><span class="pi">📸</span> Photos of damaged/wrong items speed up approval.</div>
      <div class="policy-item"><span class="pi">⏱</span> Refunds processed in 3–5 business days after approval.</div>
      <div class="policy-item"><span class="pi">🔒</span> Items must be unused and in original packaging.</div>
      <div class="policy-item"><span class="pi">❌</span> Digital goods and perishables are non-refundable.</div>
    </div>

    <button type="submit" name="submit_refund" class="submit-btn">
      ↩ Submit Refund Request
    </button>
    <a href="order_details.php?id=<?= $order_id ?>" style="display:block;text-align:center;font-size:.82rem;color:var(--muted);margin-top:.75rem;text-decoration:none;">
      Cancel — keep my order
    </a>

  </div><!-- /right -->

</div>
</form>
<?php endif; ?>

</div></div>
<?php include("../includes/footer.php"); ?>