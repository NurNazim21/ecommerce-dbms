<?php
include("../includes/auth_check.php");
include("../config/db.php");

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id  = intval($_SESSION['user_id']);

if (!$order_id) { header("Location: orders.php"); exit(); }

// Ownership check
$stmt = $conn->prepare("
    SELECT o.*, u.name AS customer_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.id = ? AND o.user_id = ?
");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) { header("Location: orders.php"); exit(); }

// ── UPGRADE: read delivery address from order_addresses table ─────────────────
// Falls back to JSON in orders.notes for old orders placed before the upgrade.
$stmt = $conn->prepare("SELECT * FROM order_addresses WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$addr = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Backward-compat: parse old JSON snapshot if order_addresses row doesn't exist
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
        'method'  => '', // derived from shipment below
    ];
} elseif (!empty($order['notes'])) {
    $decoded = json_decode($order['notes'], true);
    if (is_array($decoded)) $shipping = $decoded;
}

// Order items (with seller info for multi-seller display)
$stmt = $conn->prepare("
    SELECT oi.quantity, oi.price, oi.item_status,
           p.name AS product_name, p.image,
           u.name AS seller_name
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN users u ON u.id = p.seller_id
    WHERE oi.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items_result = $stmt->get_result();
$stmt->close();

// Payment info
$stmt = $conn->prepare("
    SELECT payment_method, payment_status, amount, paid_at
    FROM payments WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── UPGRADE: shipment + tracking events ──────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM shipments WHERE order_id = ? LIMIT 1");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
$stmt->close();

$tracking_events = [];
if ($shipment) {
    $stmt = $conn->prepare("
        SELECT * FROM shipment_tracking_events
        WHERE shipment_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->bind_param("i", $shipment['id']);
    $stmt->execute();
    $te = $stmt->get_result();
    while ($row = $te->fetch_assoc()) $tracking_events[] = $row;
    $stmt->close();

    // Fill shipping method from shipment if not in address snapshot
    if (empty($shipping['method']) && !empty($shipment['shipping_status'])) {
        $shipping['method'] = $shipment['shipping_status'];
    }
}

// ── UPGRADE: order status audit log ──────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT new_status, note, created_at
    FROM order_status_logs
    WHERE order_id = ?
    ORDER BY created_at ASC
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$status_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Mark notifications as read ────────────────────────────────────────────────
$conn->query("UPDATE notifications SET is_read = 1
              WHERE user_id = $user_id
                AND link LIKE '%order_details.php?id=$order_id%'
                AND is_read = 0");

// ── Price breakdown ───────────────────────────────────────────────────────────
$total_amount    = floatval($order['total_amount']    ?? 0);
$shipping_cost   = floatval($order['shipping_cost']   ?? 0);
$discount_amount = floatval($order['discount_amount'] ?? 0);
$subtotal        = $total_amount - $shipping_cost + $discount_amount;

$shipping_labels = [
    'standard' => 'Standard (5–7 days)',
    'express'  => 'Express (2–3 days)',
    'same_day' => 'Same-Day Delivery',
];

// Full 9-status set from upgrade
$allowed_statuses = [
    'Pending','Confirmed','Processing','Ready_to_Ship',
    'Shipped','Delivered','Cancelled','Refunded','Disputed'
];
$status = in_array($order['status'], $allowed_statuses) ? $order['status'] : 'Unknown';
$badge  = match($status) {
    'Delivered'               => 'success',
    'Cancelled', 'Refunded'   => 'danger',
    'Shipped'                 => 'primary',
    'Processing','Confirmed'  => 'info',
    'Disputed'                => 'warning',
    default                   => 'warning'
};
?>
<?php include("../includes/header.php"); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
:root{--ink:#0f0f0f;--muted:#6b7280;--border:#e5e7eb;--surface:#f9fafb;--accent:#131921;--amber:#f59e0b;--green:#16a34a;--radius:12px;}
*,*::before,*::after{box-sizing:border-box;}
.od-page{font-family:'DM Sans',sans-serif;background:#f3f4f6;min-height:100vh;padding:2.5rem 0 5rem;}
.od-grid{display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start;}
@media(max-width:900px){.od-grid{grid-template-columns:1fr;}}
.od-card{background:#fff;border-radius:var(--radius);padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.25rem;}
.od-card-title{font-family:'Playfair Display',serif;font-size:1.05rem;color:var(--ink);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}
.back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--muted);text-decoration:none;margin-bottom:1.25rem;font-weight:500;}
.back-link:hover{color:var(--ink);}
.page-heading{font-family:'Playfair Display',serif;font-size:1.75rem;color:var(--ink);margin-bottom:1.5rem;}
.page-heading span{color:var(--amber);}
.status-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-size:.8rem;font-weight:700;letter-spacing:.04em;}
.badge-success{background:#d1fae5;color:#065f46;}
.badge-danger{background:#fee2e2;color:#991b1b;}
.badge-primary{background:#dbeafe;color:#1e40af;}
.badge-info{background:#e0f2fe;color:#0369a1;}
.badge-warning{background:#fef9c3;color:#854d0e;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;}
@media(max-width:600px){.info-grid{grid-template-columns:1fr;}}
.info-row{display:flex;flex-direction:column;gap:.15rem;}
.info-label{font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;}
.info-value{font-size:.9rem;color:var(--ink);font-weight:500;}
.items-table{width:100%;border-collapse:collapse;}
.items-table th{font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;padding:.5rem 0;border-bottom:.5px solid var(--border);text-align:left;}
.items-table td{padding:.85rem 0;border-bottom:.5px solid var(--border);vertical-align:middle;font-size:.88rem;}
.items-table tr:last-child td{border-bottom:none;}
.prod-cell{display:flex;align-items:center;gap:.75rem;}
.prod-img{width:52px;height:52px;object-fit:cover;border-radius:7px;border:.5px solid var(--border);flex-shrink:0;}
.prod-name{font-weight:600;color:var(--ink);font-size:.88rem;}
.divider{border:none;border-top:.5px solid var(--border);margin:.75rem 0;}
.sum-row{display:flex;justify-content:space-between;font-size:.87rem;color:var(--muted);margin-bottom:.4rem;}
.sum-row.discount{color:var(--green);}
.sum-row.total{font-size:1rem;font-weight:700;color:var(--ink);margin-top:.5rem;padding-top:.5rem;border-top:.5px solid var(--border);}

/* UPGRADE: shipment timeline styles */
.track-tl{display:flex;flex-direction:column;}
.track-item{display:flex;gap:.65rem;}
.track-dot-col{display:flex;flex-direction:column;align-items:center;}
.track-dot{width:12px;height:12px;border-radius:50%;background:var(--border);flex-shrink:0;margin-top:4px;}
.track-dot.done{background:#131921;}
.track-dot.latest{background:var(--amber);box-shadow:0 0 0 3px rgba(245,158,11,.2);}
.track-line{width:1px;flex:1;background:var(--border);margin:.25rem 0;min-height:18px;}
.track-content{padding-bottom:.9rem;}
.track-label{font-size:.85rem;font-weight:600;color:var(--ink);}
.track-desc{font-size:.78rem;color:var(--muted);margin-top:1px;}
.track-loc{font-size:.75rem;color:#aaa;margin-top:1px;}
.track-time{font-size:.73rem;color:#bbb;margin-top:2px;}
.no-tracking{padding:.75rem;background:var(--surface);border-radius:8px;font-size:.83rem;color:var(--muted);text-align:center;}

/* UPGRADE: status log timeline */
.tl{display:flex;flex-direction:column;}
.tl-item{display:flex;gap:.65rem;}
.tl-dot-col{display:flex;flex-direction:column;align-items:center;}
.tl-dot{width:10px;height:10px;border-radius:50%;background:var(--border);flex-shrink:0;margin-top:4px;}
.tl-dot.done{background:var(--accent);}
.tl-dot.active{background:var(--amber);box-shadow:0 0 0 3px rgba(245,158,11,.2);}
.tl-line{width:1px;flex:1;background:var(--border);margin:.2rem 0;min-height:16px;}
.tl-content{padding-bottom:.8rem;}
.tl-label{font-size:.83rem;font-weight:600;color:var(--ink);}
.tl-note{font-size:.75rem;color:var(--muted);font-style:italic;margin-top:1px;}
.tl-time{font-size:.72rem;color:#bbb;margin-top:2px;}

/* Refund button */
.btn-refund{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.25rem;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;transition:background .13s;}
.btn-refund:hover{background:#fecaca;color:#7f1d1d;}
</style>

<div class="od-page"><div class="container">

<a href="orders.php" class="back-link">← Back to My Orders</a>
<h1 class="page-heading">Order <span>#<?= intval($order['id']) ?></span></h1>

<div class="od-grid">

  <!-- LEFT -->
  <div>

    <!-- Order details -->
    <div class="od-card">
      <div class="od-card-title">📋 Order Details</div>
      <div class="info-grid">
        <div class="info-row">
          <span class="info-label">Status</span>
          <span><span class="status-badge badge-<?= $badge ?>">
            <?= htmlspecialchars(str_replace('_',' ',$status), ENT_QUOTES, 'UTF-8') ?>
          </span></span>
        </div>
        <div class="info-row">
          <span class="info-label">Order Date</span>
          <span class="info-value"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Payment Method</span>
          <span class="info-value"><?= htmlspecialchars($payment['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Payment Status</span>
          <span class="info-value"><?= htmlspecialchars($payment['payment_status'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if (!empty($payment['paid_at'])): ?>
        <div class="info-row">
          <span class="info-label">Paid At</span>
          <span class="info-value"><?= date('d M Y, h:i A', strtotime($payment['paid_at'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($shipment['tracking_number'])): ?>
        <div class="info-row">
          <span class="info-label">Tracking Number</span>
          <span class="info-value" style="font-weight:700;color:var(--accent)">
            <?= htmlspecialchars($shipment['tracking_number'], ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Delivery info — UPGRADE: from order_addresses, not JSON notes -->
    <?php if (!empty($shipping)): ?>
    <div class="od-card">
      <div class="od-card-title">📦 Delivery Information</div>
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
        <div class="info-row">
          <span class="info-label">Shipping Method</span>
          <span class="info-value">
            <?= htmlspecialchars(
                $shipping_labels[$shipping['method'] ?? ''] ?? ucfirst(str_replace('_',' ',$shipping['method'] ?? '—')),
                ENT_QUOTES, 'UTF-8'
            ) ?>
          </span>
        </div>
        <div class="info-row" style="grid-column:1/-1">
          <span class="info-label">Delivery Address</span>
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
          <span class="info-value" style="font-style:italic;color:var(--muted)">
            "<?= htmlspecialchars($shipping['notes'], ENT_QUOTES, 'UTF-8') ?>"
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Items ordered -->
    <div class="od-card">
      <div class="od-card-title">🛍 Items Ordered</div>
      <table class="items-table">
        <thead>
          <tr><th>Product</th><th>Price</th><th>Qty</th><th style="text-align:right">Subtotal</th></tr>
        </thead>
        <tbody>
          <?php while ($item = $items_result->fetch_assoc()): ?>
          <tr>
            <td>
              <div class="prod-cell">
                <img src="../<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>"
                     class="prod-img" alt="">
                <div>
                  <div class="prod-name"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php if ($item['seller_name']): ?>
                    <div style="font-size:.72rem;color:var(--muted)">by <?= htmlspecialchars($item['seller_name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php endif; ?>
                </div>
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

    <!-- UPGRADE: Live shipment tracking timeline from shipment_tracking_events -->
    <div class="od-card">
      <div class="od-card-title">🚚 Shipment Tracking</div>
      <?php if (!empty($tracking_events)): ?>
        <div class="track-tl">
          <?php foreach ($tracking_events as $i => $ev):
              $isLast = $i === count($tracking_events) - 1;
          ?>
          <div class="track-item">
            <div class="track-dot-col">
              <div class="track-dot <?= $isLast ? 'latest' : 'done' ?>"></div>
              <?php if (!$isLast): ?><div class="track-line"></div><?php endif; ?>
            </div>
            <div class="track-content">
              <div class="track-label"><?= htmlspecialchars($ev['status'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php if ($ev['description']): ?>
                <div class="track-desc"><?= htmlspecialchars($ev['description'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
              <?php if ($ev['location']): ?>
                <div class="track-loc">📍 <?= htmlspecialchars($ev['location'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
              <div class="track-time"><?= date('d M Y, h:i A', strtotime($ev['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="no-tracking">
          <?php if ($shipment): ?>
            Your shipment has been created. Tracking updates will appear here once the courier picks it up.
          <?php else: ?>
            Tracking information will appear here once your order is shipped.
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- /left -->

  <!-- RIGHT -->
  <div>

    <!-- Price summary -->
    <div class="od-card">
      <div class="od-card-title">💰 Price Summary</div>
      <div class="sum-row">
        <span>Subtotal</span>
        <span>৳ <?= number_format($subtotal) ?></span>
      </div>
      <?php if ($discount_amount > 0): ?>
      <div class="sum-row discount">
        <span>Coupon<?php if (!empty($order['coupon_code'])): ?> (<?= htmlspecialchars($order['coupon_code'], ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?></span>
        <span>−৳ <?= number_format($discount_amount) ?></span>
      </div>
      <?php endif; ?>
      <div class="sum-row">
        <span>Shipping<?php if (!empty($shipping['method'])): ?> (<?= htmlspecialchars($shipping_labels[$shipping['method']] ?? $shipping['method'], ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?></span>
        <span>৳ <?= number_format($shipping_cost) ?></span>
      </div>
      <div class="sum-row total">
        <span>Total Paid</span>
        <span>৳ <?= number_format($total_amount) ?></span>
      </div>
    </div>

    <!-- UPGRADE: Order status audit log (from order_status_logs table) -->
    <div class="od-card">
      <div class="od-card-title">🕐 Order History</div>
      <?php if (!empty($status_logs)): ?>
      <div class="tl">
        <?php foreach ($status_logs as $i => $log):
            $isLast   = $i === count($status_logs) - 1;
            $isDone   = !$isLast;
        ?>
        <div class="tl-item">
          <div class="tl-dot-col">
            <div class="tl-dot <?= $isLast ? 'active' : 'done' ?>"></div>
            <?php if (!$isLast): ?><div class="tl-line"></div><?php endif; ?>
          </div>
          <div class="tl-content">
            <div class="tl-label">
              <?= htmlspecialchars(str_replace('_',' ',$log['new_status']), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php if (!empty($log['note'])): ?>
              <div class="tl-note">"<?= htmlspecialchars($log['note'], ENT_QUOTES, 'UTF-8') ?>"</div>
            <?php endif; ?>
            <div class="tl-time"><?= date('d M Y, h:i A', strtotime($log['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <p style="font-size:.83rem;color:var(--muted)">No status history available.</p>
      <?php endif; ?>
    </div>

    <!-- UPGRADE: Refund request button (shown only after delivery, within 7 days) -->
    <?php
    $days_since = $order['created_at']
        ? (time() - strtotime($order['created_at'])) / 86400
        : 999;
    $can_refund = $status === 'Delivered' && $days_since <= 7;
    ?>
    <?php if ($can_refund): ?>
    <div class="od-card">
      <div class="od-card-title">↩ Return / Refund</div>
      <p style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem">
        Not satisfied? You can request a return within 7 days of delivery.
      </p>
      <a href="refund_request.php?order_id=<?= intval($order_id) ?>" class="btn-refund">
        ↩ Request Refund / Return
      </a>
    </div>
    <?php endif; ?>

    <a href="home.php" style="display:block;text-align:center;margin-top:.5rem;font-size:.85rem;color:var(--muted);text-decoration:none">
      Continue Shopping →
    </a>

  </div><!-- /right -->

</div>
</div></div>

<?php include("../includes/footer.php"); ?>