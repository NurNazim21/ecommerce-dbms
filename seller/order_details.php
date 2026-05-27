<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$seller_id = intval($_SESSION['user_id']);
$vo_id     = isset($_GET['vo_id']) ? intval($_GET['vo_id']) : 0;

if (!$vo_id) { header("Location: orders.php"); exit(); }

// ── VALID STATUSES FOR SELLER ─────────────────────────────────────────────────
// Sellers can only move forward through the workflow.
// Admin handles: Cancelled, Refunded, Disputed.
$valid_statuses = ['pending', 'processing', 'ready_to_ship'];

// ── UPDATE VENDOR ORDER STATUS ────────────────────────────────────────────────
// UPGRADE: sellers update vendor_orders.status, not orders.status directly.
//          When seller marks ready_to_ship, admin assigns the shipment.
if (isset($_POST['update_status'])) {
    $new_status = $_POST['vo_status'] ?? '';
    if (!in_array($new_status, $valid_statuses, true)) {
        $status_error = "Invalid status.";
    } else {
        // Verify ownership before updating
        $stmt = $conn->prepare("SELECT id FROM vendor_orders WHERE id = ? AND seller_id = ?");
        $stmt->bind_param("ii", $vo_id, $seller_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $status_error = "Access denied.";
        } else {
            $stmt->close();
            $stmt = $conn->prepare("UPDATE vendor_orders SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $vo_id);
            $stmt->execute();
            $stmt->close();

            // Notify buyer when seller marks processing or ready_to_ship
            $stmt = $conn->prepare("SELECT parent_order_id FROM vendor_orders WHERE id = ?");
            $stmt->bind_param("i", $vo_id);
            $stmt->execute();
            $parent_id = intval($stmt->get_result()->fetch_assoc()['parent_order_id']);
            $stmt->close();

            $stmt = $conn->prepare("SELECT user_id FROM orders WHERE id = ?");
            $stmt->bind_param("i", $parent_id);
            $stmt->execute();
            $buyer_id = intval($stmt->get_result()->fetch_assoc()['user_id']);
            $stmt->close();

            $msg = match($new_status) {
                'processing'    => 'Your seller is preparing your items.',
                'ready_to_ship' => 'Your items are packed and ready for pickup.',
                default         => "Vendor order status updated to $new_status."
            };
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, 'order_status_update', 'Order Update', ?, ?)
            ");
            $notif_link = "/user/order_details.php?id=$parent_id";
            $stmt->bind_param("iss", $buyer_id, $msg, $notif_link);
            $stmt->execute();
            $stmt->close();

            $status_success = "Status updated to: " . ucfirst(str_replace('_', ' ', $new_status));
            header("Location: order_details.php?vo_id=$vo_id&updated=1");
            exit();
        }
    }
}

// ── FETCH VENDOR ORDER ────────────────────────────────────────────────────────
// UPGRADE: security check is now "vo.seller_id = ?" on vendor_orders directly,
//          instead of the old join through products.
$stmt = $conn->prepare("
    SELECT
        vo.*,
        o.created_at        AS order_placed_at,
        o.coupon_code,
        u.name              AS customer_name,
        u.phone             AS customer_phone,
        u.email             AS customer_email,
        COALESCE(oa.recipient_name, u.name) AS ship_name,
        COALESCE(oa.phone, u.phone)         AS ship_phone,
        COALESCE(oa.address_line, '')       AS ship_address,
        COALESCE(oa.city, '')               AS ship_city,
        COALESCE(oa.zip, '')                AS ship_zip,
        COALESCE(oa.country, 'Bangladesh')  AS ship_country,
        COALESCE(oa.delivery_notes, '')     AS ship_notes,
        p.payment_method,
        p.payment_status
    FROM vendor_orders vo
    JOIN orders o              ON o.id            = vo.parent_order_id
    JOIN users u               ON u.id            = o.user_id
    LEFT JOIN order_addresses oa ON oa.order_id   = o.id
    LEFT JOIN payments p       ON p.order_id      = o.id
    WHERE vo.id = ? AND vo.seller_id = ?
");
$stmt->bind_param("ii", $vo_id, $seller_id);
$stmt->execute();
$vo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vo) {
    header("Location: orders.php");
    exit();
}

// ── FETCH ITEMS for this vendor order ────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT oi.quantity, oi.price, oi.item_status,
           p.name AS product_name, p.image
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.vendor_order_id = ?
");
$stmt->bind_param("i", $vo_id);
$stmt->execute();
$items = $stmt->get_result();
$stmt->close();

// ── FETCH SHIPMENT + TRACKING EVENTS ─────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT * FROM shipments
    WHERE vendor_order_id = ? OR order_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $vo_id, $vo['parent_order_id']);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
$stmt->close();

$tracking_events = [];
if ($shipment) {
    $stmt = $conn->prepare("
        SELECT * FROM shipment_tracking_events
        WHERE shipment_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $shipment['id']);
    $stmt->execute();
    $te_result = $stmt->get_result();
    while ($te = $te_result->fetch_assoc()) $tracking_events[] = $te;
    $stmt->close();
}

// ── FETCH ORDER STATUS LOG ────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT osl.new_status, osl.note, osl.created_at, u.name AS changed_by_name
    FROM order_status_logs osl
    LEFT JOIN users u ON u.id = osl.changed_by
    WHERE osl.order_id = ?
    ORDER BY osl.created_at ASC
");
$stmt->bind_param("i", $vo['parent_order_id']);
$stmt->execute();
$status_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$status_map = [
    'pending'       => ['Pending',       'vs-pending'],
    'processing'    => ['Processing',    'vs-processing'],
    'ready_to_ship' => ['Ready to Ship', 'vs-ready_to_ship'],
    'shipped'       => ['Shipped',       'vs-shipped'],
    'delivered'     => ['Delivered',     'vs-delivered'],
    'cancelled'     => ['Cancelled',     'vs-cancelled'],
];
[$vo_status_label, $vo_status_cls] = $status_map[$vo['status']] ?? ['Unknown', 'vs-pending'];
?>
<?php include("../includes/header.php"); ?>

<style>
:root { --green-dark:#1b4332; --green-mid:#2d6a4f; --green-light:#95d5b2; --ink:#0f0f0f; --muted:#6b7280; --border:#e5e7eb; --radius:12px; --amber:#f59e0b; }
*,*::before,*::after{box-sizing:border-box;}
.sod-page { font-family:'DM Sans',sans-serif; background:#f3f4f6; min-height:100vh; padding:2rem 0 5rem; }
.sod-grid { display:grid; grid-template-columns:1fr 320px; gap:1.25rem; align-items:start; }
@media(max-width:900px){ .sod-grid { grid-template-columns:1fr; } }
.sod-card { background:#fff; border-radius:var(--radius); padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:1.1rem; }
.sod-card-title { font-size:1rem; font-weight:700; color:var(--ink); margin-bottom:1rem; display:flex; align-items:center; gap:.4rem; }
.back-link { display:inline-flex; align-items:center; gap:.35rem; font-size:.83rem; color:var(--muted); text-decoration:none; margin-bottom:1rem; font-weight:500; }
.back-link:hover { color:var(--ink); }
.page-heading { font-size:1.6rem; font-weight:800; color:var(--ink); margin-bottom:1.25rem; }
.page-heading span { color:var(--green-mid); }
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:.55rem; }
@media(max-width:600px){ .info-grid { grid-template-columns:1fr; } }
.info-row { display:flex; flex-direction:column; gap:.12rem; }
.info-label { font-size:.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.info-value { font-size:.88rem; color:var(--ink); font-weight:500; }
.items-table { width:100%; border-collapse:collapse; }
.items-table th { font-size:.73rem; font-weight:700; color:var(--muted); text-transform:uppercase; padding:.45rem 0; border-bottom:.5px solid var(--border); text-align:left; }
.items-table td { padding:.75rem 0; border-bottom:.5px solid var(--border); vertical-align:middle; font-size:.86rem; }
.items-table tr:last-child td { border-bottom:none; }
.prod-cell { display:flex; align-items:center; gap:.65rem; }
.prod-img { width:48px; height:48px; object-fit:cover; border-radius:6px; border:.5px solid var(--border); flex-shrink:0; }
.vo-status { display:inline-block; border-radius:7px; padding:4px 12px; font-size:.8rem; font-weight:700; }
.vs-pending       { background:#fff8e1; color:#856404; }
.vs-processing    { background:#e8f0ff; color:#084298; }
.vs-ready_to_ship { background:#fef9e7; color:#5f4b00; }
.vs-shipped       { background:#e6faf5; color:#0f5132; }
.vs-delivered     { background:#e9f7ef; color:#1a7a4a; }
.vs-cancelled     { background:#fdecea; color:#842029; }
.sum-row { display:flex; justify-content:space-between; font-size:.85rem; color:var(--muted); margin-bottom:.35rem; }
.sum-row.total { font-size:.95rem; font-weight:700; color:var(--ink); margin-top:.4rem; padding-top:.4rem; border-top:.5px solid var(--border); }
.sum-row.green { color:#1a7a4a; font-weight:600; }
.status-form select { width:100%; padding:.6rem .8rem; border:1.5px solid var(--border); border-radius:8px; font-size:.88rem; outline:none; margin-bottom:.75rem; font-weight:600; }
.status-form select:focus { border-color:var(--green-mid); }
.btn-update { display:block; width:100%; padding:.75rem; background:var(--green-mid); color:#fff; border:none; border-radius:8px; font-size:.9rem; font-weight:700; cursor:pointer; transition:background .14s; }
.btn-update:hover { background:var(--green-dark); }
.tl { display:flex; flex-direction:column; }
.tl-item { display:flex; gap:.65rem; }
.tl-dot-col { display:flex; flex-direction:column; align-items:center; }
.tl-dot { width:10px; height:10px; border-radius:50%; background:var(--border); flex-shrink:0; margin-top:4px; }
.tl-dot.done   { background:var(--green-mid); }
.tl-dot.latest { background:var(--amber); box-shadow:0 0 0 3px rgba(245,158,11,.2); }
.tl-line { width:1px; flex:1; background:var(--border); margin:.2rem 0; min-height:16px; }
.tl-label { font-size:.83rem; font-weight:600; color:var(--ink); }
.tl-meta  { font-size:.73rem; color:var(--muted); margin-top:1px; }
.tl-content { padding-bottom:.85rem; }
.flash-ok  { background:#d1fae5; color:#065f46; border-radius:8px; padding:.55rem .85rem; font-size:.83rem; font-weight:500; margin-bottom:1rem; }
.flash-err { background:#fee2e2; color:#991b1b; border-radius:8px; padding:.55rem .85rem; font-size:.83rem; font-weight:500; margin-bottom:1rem; }
.track-event { display:flex; flex-direction:column; gap:.1rem; padding:.6rem 0; border-bottom:.5px solid var(--border); font-size:.82rem; }
.track-event:last-child { border-bottom:none; }
.track-status { font-weight:700; color:var(--ink); }
.track-loc    { color:var(--muted); font-size:.76rem; }
.track-time   { color:#aaa; font-size:.73rem; }
</style>

<div class="sod-page"><div class="container">

<a href="orders.php" class="back-link">← Back to Orders</a>
<div class="page-heading">Vendor Order <span>#<?= intval($vo_id) ?></span> <span style="font-size:1rem;color:var(--muted)">— Parent Order #<?= intval($vo['parent_order_id']) ?></span></div>

<?php if (isset($_GET['updated'])): ?>
    <div class="flash-ok">✓ Status updated successfully.</div>
<?php endif; ?>
<?php if (!empty($status_success)): ?>
    <div class="flash-ok">✓ <?= htmlspecialchars($status_success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($status_error)): ?>
    <div class="flash-err">⚠ <?= htmlspecialchars($status_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="sod-grid">

  <!-- LEFT -->
  <div>

    <!-- Order Info -->
    <div class="sod-card">
      <div class="sod-card-title">📋 Order Info</div>
      <div class="info-grid">
        <div class="info-row">
          <span class="info-label">Vendor Order Status</span>
          <span><span class="vo-status <?= $vo_status_cls ?>"><?= $vo_status_label ?></span></span>
        </div>
        <div class="info-row">
          <span class="info-label">Parent Order Status</span>
          <span class="info-value"><?= htmlspecialchars(ucfirst($vo['status']), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Order Placed</span>
          <span class="info-value"><?= date('d M Y, h:i A', strtotime($vo['order_placed_at'])) ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Payment</span>
          <span class="info-value">
            <?= htmlspecialchars($vo['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
            — <?= htmlspecialchars($vo['payment_status'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>
        <?php if (!empty($vo['confirmed_at'])): ?>
        <div class="info-row">
          <span class="info-label">Confirmed At</span>
          <span class="info-value"><?= date('d M Y, h:i A', strtotime($vo['confirmed_at'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($vo['shipped_at'])): ?>
        <div class="info-row">
          <span class="info-label">Shipped At</span>
          <span class="info-value"><?= date('d M Y, h:i A', strtotime($vo['shipped_at'])) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Delivery Address — from order_addresses table -->
    <div class="sod-card">
      <div class="sod-card-title">📦 Delivery Address</div>
      <div class="info-grid">
        <div class="info-row">
          <span class="info-label">Recipient</span>
          <span class="info-value"><?= htmlspecialchars($vo['ship_name'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Phone</span>
          <span class="info-value"><?= htmlspecialchars($vo['ship_phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row" style="grid-column:1/-1">
          <span class="info-label">Address</span>
          <span class="info-value">
            <?= htmlspecialchars($vo['ship_address'], ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars($vo['ship_city'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($vo['ship_zip']): ?> – <?= htmlspecialchars($vo['ship_zip'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>,
            <?= htmlspecialchars($vo['ship_country'], ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>
        <?php if (!empty($vo['ship_notes'])): ?>
        <div class="info-row" style="grid-column:1/-1">
          <span class="info-label">Delivery Notes</span>
          <span class="info-value" style="font-style:italic;color:var(--muted)">
            "<?= htmlspecialchars($vo['ship_notes'], ENT_QUOTES, 'UTF-8') ?>"
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Items -->
    <div class="sod-card">
      <div class="sod-card-title">🛍 Your Items in This Order</div>
      <table class="items-table">
        <thead>
          <tr><th>Product</th><th>Price</th><th>Qty</th><th style="text-align:right">Subtotal</th></tr>
        </thead>
        <tbody>
          <?php while ($item = $items->fetch_assoc()): ?>
          <tr>
            <td>
              <div class="prod-cell">
                <img src="../<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>" class="prod-img" alt="">
                <span style="font-weight:600"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
              </div>
            </td>
            <td>৳ <?= number_format($item['price']) ?></td>
            <td><?= intval($item['quantity']) ?></td>
            <td style="text-align:right;font-weight:700">৳ <?= number_format($item['price'] * $item['quantity']) ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <!-- Tracking events -->
    <?php if (!empty($tracking_events)): ?>
    <div class="sod-card">
      <div class="sod-card-title">🗺 Shipment Tracking</div>
      <?php if ($shipment && $shipment['tracking_number']): ?>
        <div style="font-size:.8rem;color:var(--muted);margin-bottom:.75rem;">
          Tracking #: <strong><?= htmlspecialchars($shipment['tracking_number'], ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
      <?php endif; ?>
      <?php foreach ($tracking_events as $i => $ev): ?>
      <div class="track-event">
        <div class="track-status"><?= htmlspecialchars($ev['status'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php if ($ev['description']): ?>
          <div><?= htmlspecialchars($ev['description'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($ev['location']): ?>
          <div class="track-loc">📍 <?= htmlspecialchars($ev['location'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="track-time"><?= date('d M Y, h:i A', strtotime($ev['created_at'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div><!-- /left -->

  <!-- RIGHT -->
  <div>

    <!-- Earnings breakdown -->
    <div class="sod-card">
      <div class="sod-card-title">💰 Earnings Breakdown</div>
      <div class="sum-row"><span>Subtotal</span><span>৳ <?= number_format($vo['subtotal']) ?></span></div>
      <div class="sum-row"><span>Commission (<?= number_format($vo['commission_rate'], 1) ?>%)</span><span>−৳ <?= number_format($vo['commission_amount']) ?></span></div>
      <div class="sum-row green total"><span>Your Net</span><span>৳ <?= number_format($vo['seller_net']) ?></span></div>
      <div style="margin-top:.75rem;font-size:.78rem;color:var(--muted)">
        Payout status: <strong><?= ucfirst(htmlspecialchars($vo['payout_status'], ENT_QUOTES, 'UTF-8')) ?></strong>
      </div>
    </div>

    <!-- Status update — only for forward-moving statuses -->
    <?php if (in_array($vo['status'], ['pending', 'processing'], true)): ?>
    <div class="sod-card">
      <div class="sod-card-title">✏ Update Status</div>
      <form method="POST" class="status-form">
        <input type="hidden" name="update_status" value="1">
        <select name="vo_status">
          <?php foreach ($valid_statuses as $s):
            if ($s === 'pending' && $vo['status'] !== 'pending') continue;
          ?>
          <option value="<?= $s ?>" <?= $vo['status'] === $s ? 'selected' : '' ?>>
            <?= ucfirst(str_replace('_', ' ', $s)) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-update">Update Status</button>
      </form>
      <p style="font-size:.75rem;color:var(--muted);margin:0">
        Once marked <strong>Ready to Ship</strong>, admin will assign a courier.
      </p>
    </div>
    <?php endif; ?>

    <!-- Order status audit log -->
    <?php if (!empty($status_logs)): ?>
    <div class="sod-card">
      <div class="sod-card-title">🕐 Order Timeline</div>
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