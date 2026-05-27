<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = intval($_SESSION['user_id']);
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$order_id) { header("Location: manage_orders.php"); exit(); }

$valid_statuses = [
    'Pending','Confirmed','Processing','Ready_to_Ship',
    'Shipped','Delivered','Cancelled','Refunded','Disputed'
];

// ── UPDATE STATUS ─────────────────────────────────────────────────────────────
if (isset($_POST['update_status'])) {
    $new_status = $_POST['status'] ?? '';
    $note       = trim(strip_tags($_POST['status_note'] ?? ''));

    if (!in_array($new_status, $valid_statuses, true)) {
        $status_error = "Invalid status.";
    } else {
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("CALL sp_log_order_status(?, ?, ?, ?)");
            $stmt->bind_param("isis", $order_id, $new_status, $admin_id, $note);
            $stmt->execute();
            $stmt->close();

            if ($new_status === 'Confirmed') {
                $stmt = $conn->prepare("UPDATE orders SET confirmed_at = NOW() WHERE id = ? AND confirmed_at IS NULL");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $stmt->close();
                // Create vendor orders if not yet done
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM vendor_orders WHERE parent_order_id = ?");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                if (intval($stmt->get_result()->fetch_assoc()['cnt']) === 0) {
                    $conn->query("CALL sp_create_vendor_orders($order_id)");
                }
                $stmt->close();
            }
            if ($new_status === 'Cancelled') {
                $stmt = $conn->prepare("UPDATE orders SET cancelled_at = NOW(), cancellation_reason = ? WHERE id = ?");
                $stmt->bind_param("si", $note, $order_id);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("UPDATE vendor_orders SET status = 'cancelled' WHERE parent_order_id = ?");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $stmt->close();
            }

            // Notify buyer
            $stmt = $conn->prepare("SELECT user_id FROM orders WHERE id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $buyer_id = intval($stmt->get_result()->fetch_assoc()['user_id']);
            $stmt->close();

            $notif_msg  = "Your order #$order_id status has been updated to: " . str_replace('_',' ',$new_status) . ($note ? " — $note" : "");
            $notif_link = "/user/order_details.php?id=$order_id";
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'order_status_update', ?, ?, ?)");
            $title = "Order #$order_id Status Update";
            $stmt->bind_param("isss", $buyer_id, $title, $notif_msg, $notif_link);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $status_success = "Status updated to " . str_replace('_',' ',$new_status) . ".";
            header("Location: order_details.php?id=$order_id&updated=1");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $status_error = $e->getMessage();
        }
    }
}

// ── ADD TRACKING EVENT ────────────────────────────────────────────────────────
if (isset($_POST['add_tracking'])) {
    $shipment_id  = intval($_POST['shipment_id']);
    $track_status = trim(strip_tags($_POST['track_status'] ?? ''));
    $track_loc    = trim(strip_tags($_POST['track_location'] ?? ''));
    $track_desc   = trim(strip_tags($_POST['track_description'] ?? ''));

    if (empty($track_status)) {
        $track_error = "Status label is required.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO shipment_tracking_events (shipment_id, status, location, description)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $shipment_id, $track_status, $track_loc, $track_desc);
        $stmt->execute();
        $stmt->close();
        header("Location: order_details.php?id=$order_id&tracked=1");
        exit();
    }
}

// ── ASSIGN SHIPMENT ───────────────────────────────────────────────────────────
if (isset($_POST['assign_shipment'])) {
    $courier    = trim(strip_tags($_POST['courier_name'] ?? ''));
    $tracking   = trim(strip_tags($_POST['tracking_number'] ?? ''));
    $est_date   = $_POST['estimated_delivery'] ?? '';

    if (!empty($courier) || !empty($tracking)) {
        // Check if shipment row exists
        $stmt = $conn->prepare("SELECT id FROM shipments WHERE order_id = ? LIMIT 1");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $existing_ship = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing_ship) {
            $stmt = $conn->prepare("
                UPDATE shipments SET courier_name = ?, tracking_number = ?,
                    estimated_delivery = ?, shipping_status = 'processing',
                    shipped_at = IF(shipped_at IS NULL AND ? != '', NOW(), shipped_at)
                WHERE id = ?
            ");
            $stmt->bind_param("ssssi", $courier, $tracking, $est_date, $tracking, $existing_ship['id']);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO shipments (order_id, courier_name, tracking_number, estimated_delivery, shipping_status)
                VALUES (?, ?, ?, ?, 'processing')
            ");
            $stmt->bind_param("isss", $order_id, $courier, $tracking, $est_date);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: order_details.php?id=$order_id&shipped=1");
        exit();
    }
}

// ── FETCH ORDER ───────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT o.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE o.id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) { header("Location: manage_orders.php"); exit(); }

// ── DELIVERY ADDRESS — from order_addresses, fallback to JSON notes ───────────
$stmt = $conn->prepare("SELECT * FROM order_addresses WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$addr = $stmt->get_result()->fetch_assoc();
$stmt->close();

$shipping = [];
if ($addr) {
    $shipping = [
        'name'    => $addr['recipient_name'],
        'phone'   => $addr['phone'],
        'email'   => $addr['email'] ?? '',
        'address' => $addr['address_line'],
        'city'    => $addr['city'],
        'zip'     => $addr['zip'] ?? '',
        'country' => $addr['country'] ?? 'Bangladesh',
        'notes'   => $addr['delivery_notes'] ?? '',
        'method'  => '',
    ];
} elseif (!empty($order['notes'])) {
    $decoded = json_decode($order['notes'], true);
    if (is_array($decoded)) $shipping = $decoded;
}

// ── ORDER ITEMS ───────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT oi.*, p.name AS product_name, p.image,
           u.name AS seller_name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    LEFT JOIN users u ON u.id = oi.seller_id
    WHERE oi.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── PAYMENT ───────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM payments WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── SHIPMENT + TRACKING ───────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM shipments WHERE order_id = ? LIMIT 1");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
$stmt->close();

$tracking_events = [];
if ($shipment) {
    $stmt = $conn->prepare("SELECT * FROM shipment_tracking_events WHERE shipment_id = ? ORDER BY created_at ASC");
    $stmt->bind_param("i", $shipment['id']);
    $stmt->execute();
    $tracking_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ── STATUS LOG ────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT osl.*, u.name AS changed_by_name
    FROM order_status_logs osl
    LEFT JOIN users u ON u.id = osl.changed_by
    WHERE osl.order_id = ?
    ORDER BY osl.created_at ASC
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$status_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── VENDOR ORDERS ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT vo.*, u.name AS seller_name, sp.shop_name
    FROM vendor_orders vo
    JOIN users u ON u.id = vo.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = vo.seller_id
    WHERE vo.parent_order_id = ?
    ORDER BY vo.id
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$vendor_orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── REFUND REQUESTS FOR THIS ORDER ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT r.*, u.name AS customer_name FROM refunds r JOIN users u ON u.id = r.user_id WHERE r.order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$refunds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Price breakdown
$total_amount    = floatval($order['total_amount']    ?? 0);
$shipping_cost   = floatval($order['shipping_cost']   ?? 0);
$discount_amount = floatval($order['discount_amount'] ?? 0);
$subtotal        = $total_amount - $shipping_cost + $discount_amount;

$status = in_array($order['status'], $valid_statuses) ? $order['status'] : 'Unknown';
$badge_map = [
    'Delivered' => ['success','#065f46','#d1fae5'],
    'Cancelled' => ['danger','#991b1b','#fee2e2'],
    'Refunded'  => ['purple','#6d28d9','#f3e8ff'],
    'Shipped'   => ['primary','#1e40af','#dbeafe'],
    'Confirmed' => ['info','#0369a1','#e0f2fe'],
    'Processing'=> ['info','#0369a1','#e0f2fe'],
    'Ready_to_Ship' => ['warning','#854d0e','#fef9c3'],
    'Disputed'  => ['orange','#c2410c','#fff7ed'],
    'Pending'   => ['warning','#854d0e','#fef9c3'],
];
$bm = $badge_map[$status] ?? ['warning','#854d0e','#fef9c3'];
?>
<?php include("../includes/header.php"); ?>

<style>
:root{--ink:#0f0f0f;--muted:#6b7280;--border:#e5e7eb;--surface:#f9fafb;--accent:#131921;--amber:#f59e0b;--green:#16a34a;--radius:12px;}
*,*::before,*::after{box-sizing:border-box;}
.od-page{font-family:'DM Sans',sans-serif;background:#f3f4f6;min-height:100vh;padding:2rem 0 5rem;}
.od-grid{display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start;}
@media(max-width:960px){.od-grid{grid-template-columns:1fr;}}
.od-card{background:#fff;border-radius:var(--radius);padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.1rem;}
.od-card-title{font-size:1rem;font-weight:700;color:var(--ink);margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;}
.back-link{display:inline-flex;align-items:center;gap:.35rem;font-size:.83rem;color:var(--muted);text-decoration:none;margin-bottom:1rem;font-weight:500;}
.back-link:hover{color:var(--ink);}
.page-heading{font-size:1.6rem;font-weight:800;color:var(--ink);margin-bottom:1.25rem;}
.page-heading span{color:var(--amber);}
.st-badge{display:inline-block;border-radius:6px;padding:4px 12px;font-size:.8rem;font-weight:700;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;}
@media(max-width:600px){.info-grid{grid-template-columns:1fr;}}
.info-row{display:flex;flex-direction:column;gap:.12rem;}
.info-label{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;}
.info-value{font-size:.88rem;color:var(--ink);font-weight:500;}
.items-table{width:100%;border-collapse:collapse;}
.items-table th{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;padding:.45rem 0;border-bottom:.5px solid var(--border);text-align:left;}
.items-table td{padding:.75rem 0;border-bottom:.5px solid var(--border);vertical-align:middle;font-size:.85rem;}
.items-table tr:last-child td{border-bottom:none;}
.prod-cell{display:flex;align-items:center;gap:.65rem;}
.prod-img{width:46px;height:46px;object-fit:cover;border-radius:6px;border:.5px solid var(--border);flex-shrink:0;}
.sum-row{display:flex;justify-content:space-between;font-size:.85rem;color:var(--muted);margin-bottom:.35rem;}
.sum-row.total{font-size:.95rem;font-weight:700;color:var(--ink);padding-top:.4rem;border-top:.5px solid var(--border);margin-top:.4rem;}
.sum-row.green{color:var(--green);}
/* Status update */
.status-form select{width:100%;padding:.6rem .8rem;border:1.5px solid var(--border);border-radius:8px;font-size:.88rem;outline:none;margin-bottom:.6rem;font-weight:600;}
.status-form select:focus{border-color:var(--accent);}
.status-form textarea{width:100%;padding:.6rem .8rem;border:1.5px solid var(--border);border-radius:8px;font-size:.85rem;outline:none;resize:vertical;min-height:64px;font-family:inherit;}
.status-form textarea:focus{border-color:var(--accent);}
.btn-update{display:block;width:100%;margin-top:.6rem;padding:.75rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;transition:background .13s;}
.btn-update:hover{background:#232f3e;}
/* Tracking */
.track-form input,.track-form textarea{width:100%;padding:.55rem .75rem;border:1.5px solid var(--border);border-radius:7px;font-size:.85rem;outline:none;font-family:inherit;margin-bottom:.55rem;}
.track-form input:focus,.track-form textarea:focus{border-color:var(--accent);}
.btn-track{display:block;width:100%;padding:.65rem;background:#2d6a4f;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;}
.btn-track:hover{background:#1b4332;}
/* Timeline */
.tl{display:flex;flex-direction:column;}
.tl-item{display:flex;gap:.6rem;}
.tl-dot-col{display:flex;flex-direction:column;align-items:center;}
.tl-dot{width:10px;height:10px;border-radius:50%;background:var(--border);flex-shrink:0;margin-top:4px;}
.tl-dot.done{background:var(--accent);}
.tl-dot.latest{background:var(--amber);box-shadow:0 0 0 3px rgba(245,158,11,.2);}
.tl-line{width:1px;flex:1;background:var(--border);margin:.2rem 0;min-height:16px;}
.tl-content{padding-bottom:.8rem;}
.tl-label{font-size:.83rem;font-weight:600;color:var(--ink);}
.tl-meta{font-size:.72rem;color:var(--muted);margin-top:1px;}
/* Vendor orders */
.vo-row{display:flex;align-items:center;justify-content:space-between;padding:.65rem .85rem;border:.5px solid var(--border);border-radius:8px;margin-bottom:.5rem;font-size:.83rem;}
.vo-row .vo-id{font-weight:700;color:#2d6a4f;}
.vo-status{display:inline-block;border-radius:5px;padding:2px 8px;font-size:.72rem;font-weight:700;}
/* Flash */
.flash-ok{background:#d1fae5;color:#065f46;border-radius:8px;padding:.6rem 1rem;font-size:.83rem;font-weight:500;margin-bottom:1rem;}
.flash-err{background:#fee2e2;color:#991b1b;border-radius:8px;padding:.6rem 1rem;font-size:.83rem;font-weight:500;margin-bottom:1rem;}
/* Refund row */
.refund-row{padding:.65rem .85rem;border:.5px solid #fca5a5;border-radius:8px;background:#fff7f7;margin-bottom:.5rem;font-size:.82rem;}
.refund-row .rf-amount{font-weight:700;color:#dc2626;}
/* Shipment form */
.ship-form input{width:100%;padding:.55rem .75rem;border:1.5px solid var(--border);border-radius:7px;font-size:.85rem;outline:none;margin-bottom:.55rem;font-family:inherit;}
.ship-form input:focus{border-color:var(--accent);}
.btn-ship{display:block;width:100%;padding:.65rem;background:#1d4ed8;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;}
.btn-ship:hover{background:#1e40af;}
</style>

<div class="od-page"><div class="container">

<a href="manage_orders.php" class="back-link">← Back to Orders</a>
<h1 class="page-heading">Order <span>#<?= intval($order_id) ?></span></h1>

<?php if (isset($_GET['updated'])): ?>
    <div class="flash-ok">✓ Order status updated successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['tracked'])): ?>
    <div class="flash-ok">✓ Tracking event added — customer has been notified.</div>
<?php endif; ?>
<?php if (isset($_GET['shipped'])): ?>
    <div class="flash-ok">✓ Shipment details saved.</div>
<?php endif; ?>
<?php if (!empty($status_error)): ?>
    <div class="flash-err">❌ <?= htmlspecialchars($status_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($track_error)): ?>
    <div class="flash-err">❌ <?= htmlspecialchars($track_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="od-grid">

  <!-- ── LEFT ── -->
  <div>

    <!-- Order overview -->
    <div class="od-card">
      <div class="od-card-title">📋 Order Details</div>
      <div class="info-grid">
        <div class="info-row">
          <span class="info-label">Status</span>
          <span>
            <span class="st-badge" style="background:<?= $bm[2] ?>;color:<?= $bm[1] ?>">
              <?= htmlspecialchars(str_replace('_',' ',$status), ENT_QUOTES, 'UTF-8') ?>
            </span>
          </span>
        </div>
        <div class="info-row">
          <span class="info-label">Order Date</span>
          <span class="info-value"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Customer</span>
          <span class="info-value"><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Email</span>
          <span class="info-value"><?= htmlspecialchars($order['customer_email'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Phone</span>
          <span class="info-value"><?= htmlspecialchars($order['customer_phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Payment</span>
          <span class="info-value">
            <?= htmlspecialchars($payment['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
            — <strong><?= htmlspecialchars($payment['payment_status'] ?? 'Pending', ENT_QUOTES, 'UTF-8') ?></strong>
          </span>
        </div>
        <?php if (!empty($order['confirmed_at'])): ?>
        <div class="info-row">
          <span class="info-label">Confirmed At</span>
          <span class="info-value"><?= date('d M Y, h:i A', strtotime($order['confirmed_at'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($order['coupon_code'])): ?>
        <div class="info-row">
          <span class="info-label">Coupon</span>
          <span class="info-value"><?= htmlspecialchars($order['coupon_code'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Delivery address — from order_addresses, fallback JSON -->
    <?php if (!empty($shipping)): ?>
    <div class="od-card">
      <div class="od-card-title">📦 Delivery Address</div>
      <div class="info-grid">
        <div class="info-row">
          <span class="info-label">Recipient</span>
          <span class="info-value"><?= htmlspecialchars($shipping['name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Phone</span>
          <span class="info-value"><?= htmlspecialchars($shipping['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if (!empty($shipping['email'])): ?>
        <div class="info-row">
          <span class="info-label">Email</span>
          <span class="info-value"><?= htmlspecialchars($shipping['email'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row" style="grid-column:1/-1">
          <span class="info-label">Address</span>
          <span class="info-value">
            <?= htmlspecialchars($shipping['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars($shipping['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($shipping['zip'])): ?> – <?= htmlspecialchars($shipping['zip'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>,
            <?= htmlspecialchars($shipping['country'] ?? '', ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>
        <?php if (!empty($shipping['notes'])): ?>
        <div class="info-row" style="grid-column:1/-1">
          <span class="info-label">Notes</span>
          <span class="info-value" style="font-style:italic;color:var(--muted)">"<?= htmlspecialchars($shipping['notes'], ENT_QUOTES, 'UTF-8') ?>"</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Items -->
    <div class="od-card">
      <div class="od-card-title">🛍 Items Ordered</div>
      <table class="items-table">
        <thead><tr><th>Product</th><th>Seller</th><th>Price</th><th>Qty</th><th style="text-align:right">Subtotal</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
          <td>
            <div class="prod-cell">
              <img src="../<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>" class="prod-img" alt="">
              <span style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
          </td>
          <td style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($item['seller_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
          <td>৳ <?= number_format($item['price']) ?></td>
          <td><?= intval($item['quantity']) ?></td>
          <td style="text-align:right;font-weight:700">৳ <?= number_format($item['item_subtotal'] ?? ($item['price'] * $item['quantity'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Vendor orders breakdown -->
    <?php if (!empty($vendor_orders)): ?>
    <div class="od-card">
      <div class="od-card-title">🏪 Vendor Orders</div>
      <?php foreach ($vendor_orders as $vo):
          $vo_st = $vo['status'];
          $vo_colors = [
              'pending' => ['#fff8e1','#856404'], 'processing' => ['#e8f0ff','#084298'],
              'ready_to_ship' => ['#fef9e7','#5f4b00'], 'shipped' => ['#e6faf5','#0f5132'],
              'delivered' => ['#e9f7ef','#1a7a4a'], 'cancelled' => ['#fdecea','#842029'],
          ];
          $vc = $vo_colors[$vo_st] ?? ['#f0f0f0','#555'];
      ?>
      <div class="vo-row">
        <div>
          <a href="manage_orders.php" class="vo-id">#<?= intval($vo['id']) ?></a>
          <div style="font-size:.74rem;color:var(--muted)"><?= htmlspecialchars($vo['shop_name'] ?? $vo['seller_name'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div style="text-align:center">
          <div style="font-weight:700;color:#1a7a4a">৳ <?= number_format($vo['seller_net']) ?></div>
          <div style="font-size:.72rem;color:var(--muted)">net earnings</div>
        </div>
        <span class="vo-status" style="background:<?= $vc[0] ?>;color:<?= $vc[1] ?>">
          <?= ucfirst(str_replace('_',' ',$vo_st)) ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Refunds -->
    <?php if (!empty($refunds)): ?>
    <div class="od-card">
      <div class="od-card-title">↩ Refund Requests</div>
      <?php foreach ($refunds as $r): ?>
      <div class="refund-row">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
          <span>Refund #<?= intval($r['id']) ?> — <span class="rf-amount">৳ <?= number_format($r['amount']) ?></span></span>
          <a href="manage_refunds.php?search=<?= $r['id'] ?>" style="font-size:.75rem;color:#1e40af;text-decoration:none;font-weight:600">Review →</a>
        </div>
        <div style="font-size:.75rem;color:var(--muted)">
          <?= ucfirst(str_replace('_',' ',$r['reason'])) ?>
          · Status: <strong><?= ucfirst(str_replace('_',' ',$r['status'])) ?></strong>
          · <?= date('d M Y', strtotime($r['created_at'])) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Shipment tracking events -->
    <div class="od-card">
      <div class="od-card-title">🚚 Shipment Tracking</div>
      <?php if (!empty($tracking_events)): ?>
      <div class="tl" style="margin-bottom:1rem;">
        <?php foreach ($tracking_events as $i => $ev):
            $isLast = $i === count($tracking_events) - 1;
        ?>
        <div class="tl-item">
          <div class="tl-dot-col">
            <div class="tl-dot <?= $isLast ? 'latest' : 'done' ?>"></div>
            <?php if (!$isLast): ?><div class="tl-line"></div><?php endif; ?>
          </div>
          <div class="tl-content">
            <div class="tl-label"><?= htmlspecialchars($ev['status'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($ev['description']): ?>
              <div class="tl-meta"><?= htmlspecialchars($ev['description'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($ev['location']): ?>
              <div class="tl-meta">📍 <?= htmlspecialchars($ev['location'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="tl-meta"><?= date('d M Y, h:i A', strtotime($ev['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <p style="font-size:.82rem;color:var(--muted);margin-bottom:1rem">No tracking events yet.</p>
      <?php endif; ?>

      <!-- Add tracking event form -->
      <?php if ($shipment): ?>
      <details>
        <summary style="font-size:.82rem;font-weight:600;cursor:pointer;color:var(--accent);margin-bottom:.75rem;">+ Add Tracking Event</summary>
        <form method="POST" class="track-form" style="margin-top:.6rem;">
          <input type="hidden" name="add_tracking" value="1">
          <input type="hidden" name="shipment_id" value="<?= intval($shipment['id']) ?>">
          <input type="text" name="track_status" placeholder="Status label e.g. Picked up from seller" required>
          <input type="text" name="track_location" placeholder="Location e.g. Mirpur Hub, Dhaka (optional)">
          <textarea name="track_description" rows="2" placeholder="Description (optional)"></textarea>
          <button type="submit" class="btn-track">Add Event & Notify Customer</button>
        </form>
      </details>
      <?php endif; ?>
    </div>

  </div><!-- /left -->

  <!-- ── RIGHT ── -->
  <div>

    <!-- Price summary -->
    <div class="od-card">
      <div class="od-card-title">💰 Price Summary</div>
      <div class="sum-row"><span>Subtotal</span><span>৳ <?= number_format($subtotal) ?></span></div>
      <?php if ($discount_amount > 0): ?>
      <div class="sum-row green">
        <span>Discount<?php if ($order['coupon_code']): ?> (<?= htmlspecialchars($order['coupon_code'], ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?></span>
        <span>−৳ <?= number_format($discount_amount) ?></span>
      </div>
      <?php endif; ?>
      <div class="sum-row"><span>Shipping</span><span>৳ <?= number_format($shipping_cost) ?></span></div>
      <div class="sum-row total"><span>Total</span><span>৳ <?= number_format($total_amount) ?></span></div>
      <?php if (floatval($payment['refunded_amount'] ?? 0) > 0): ?>
      <div class="sum-row" style="color:#dc2626;margin-top:.3rem;">
        <span>Refunded</span>
        <span>−৳ <?= number_format($payment['refunded_amount']) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Update status -->
    <div class="od-card">
      <div class="od-card-title">✏️ Update Status</div>
      <form method="POST" class="status-form">
        <input type="hidden" name="update_status" value="1">
        <select name="status">
          <?php foreach ($valid_statuses as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>>
            <?= str_replace('_',' ',$s) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <textarea name="status_note" placeholder="Optional note (e.g. Shipped via Pathao #TRK123, Cancelled by customer)"></textarea>
        <button type="submit" class="btn-update">Update Status</button>
      </form>
    </div>

    <!-- Assign courier -->
    <div class="od-card">
      <div class="od-card-title">🚚 Shipment Details</div>
      <?php if ($shipment && $shipment['tracking_number']): ?>
        <div style="font-size:.82rem;margin-bottom:.75rem;">
          <strong>Courier:</strong> <?= htmlspecialchars($shipment['courier_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?><br>
          <strong>Tracking #:</strong> <?= htmlspecialchars($shipment['tracking_number'], ENT_QUOTES, 'UTF-8') ?><br>
          <?php if ($shipment['estimated_delivery']): ?>
          <strong>Est. Delivery:</strong> <?= date('d M Y', strtotime($shipment['estimated_delivery'])) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <form method="POST" class="ship-form">
        <input type="hidden" name="assign_shipment" value="1">
        <input type="text" name="courier_name" placeholder="Courier name e.g. Pathao, Steadfast" value="<?= htmlspecialchars($shipment['courier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" name="tracking_number" placeholder="Tracking number" value="<?= htmlspecialchars($shipment['tracking_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="date" name="estimated_delivery" value="<?= htmlspecialchars($shipment['estimated_delivery'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn-ship">Save Shipment Details</button>
      </form>
    </div>

    <!-- Status audit log -->
    <?php if (!empty($status_logs)): ?>
    <div class="od-card">
      <div class="od-card-title">🕐 Status History</div>
      <div class="tl">
        <?php foreach ($status_logs as $i => $log):
            $isLast = $i === count($status_logs) - 1;
        ?>
        <div class="tl-item">
          <div class="tl-dot-col">
            <div class="tl-dot <?= $isLast ? 'latest' : 'done' ?>"></div>
            <?php if (!$isLast): ?><div class="tl-line"></div><?php endif; ?>
          </div>
          <div class="tl-content">
            <div class="tl-label"><?= htmlspecialchars(str_replace('_',' ',$log['new_status']), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if (!empty($log['note'])): ?>
              <div class="tl-meta" style="font-style:italic">"<?= htmlspecialchars($log['note'], ENT_QUOTES, 'UTF-8') ?>"</div>
            <?php endif; ?>
            <div class="tl-meta">
              <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?>
              <?php if ($log['changed_by_name']): ?> · <?= htmlspecialchars($log['changed_by_name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /right -->

</div>
</div></div>

<?php include("../includes/footer.php"); ?>