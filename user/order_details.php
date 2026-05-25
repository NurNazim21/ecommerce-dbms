<?php
include("../includes/auth_check.php");
include("../config/db.php");

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id  = intval($_SESSION['user_id']);

if (!$order_id) { header("Location: orders.php"); exit(); }

// Fetch order — ownership enforced by AND o.user_id = ?
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

// Decode shipping snapshot from notes
$shipping = [];
if (!empty($order['notes'])) {
    $decoded = json_decode($order['notes'], true);
    if (is_array($decoded)) $shipping = $decoded;
}

// Order items
$stmt = $conn->prepare("
    SELECT oi.*, p.name, p.image
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items_result = $stmt->get_result();
$stmt->close();

// Payment info
$stmt = $conn->prepare("SELECT payment_method, payment_status FROM payments WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Price breakdown ───────────────────────────────────────────────────────────
// FIX: use the stored shipping_cost and discount_amount columns (added via
//      migration_add_order_columns.sql) to reconstruct the full breakdown.
//      Gracefully falls back to 0 if the columns don't exist yet (old rows).
$total_amount    = floatval($order['total_amount']    ?? 0);
$shipping_cost   = floatval($order['shipping_cost']   ?? 0);   // new column
$discount_amount = floatval($order['discount_amount'] ?? 0);   // new column
$subtotal        = $total_amount - $shipping_cost + $discount_amount;

// Shipping method label map
$shipping_labels = [
    'standard' => 'Standard (5–7 days)',
    'express'  => 'Express (2–3 days)',
    'same_day' => 'Same-Day Delivery',
];

$allowed_statuses = ['Pending','Processing','Shipped','Delivered','Cancelled'];
$status = in_array($order['status'], $allowed_statuses) ? $order['status'] : 'Unknown';
$badge  = match($status) {
    'Delivered'  => 'success',
    'Cancelled'  => 'danger',
    'Shipped'    => 'primary',
    'Processing' => 'info',
    default      => 'warning'
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
.back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--muted);text-decoration:none;margin-bottom:1.25rem;font-weight:500;transition:color .12s;}
.back-link:hover{color:var(--ink);}
.page-heading{font-family:'Playfair Display',serif;font-size:1.75rem;color:var(--ink);margin-bottom:1.5rem;}
.page-heading span{color:var(--amber);}
.status-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-size:.8rem;font-weight:700;letter-spacing:.04em;}
.badge-success{background:#d1fae5;color:#065f46;}.badge-danger{background:#fee2e2;color:#991b1b;}.badge-primary{background:#dbeafe;color:#1e40af;}.badge-info{background:#e0f2fe;color:#0369a1;}.badge-warning{background:#fef9c3;color:#854d0e;}
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
.timeline{display:flex;flex-direction:column;gap:0;}
.tl-item{display:flex;gap:.75rem;}
.tl-dot-col{display:flex;flex-direction:column;align-items:center;}
.tl-dot{width:12px;height:12px;border-radius:50%;background:var(--border);flex-shrink:0;margin-top:3px;}
.tl-dot.done{background:var(--accent);}.tl-dot.active{background:var(--amber);box-shadow:0 0 0 3px rgba(245,158,11,.2);}
.tl-line{width:1px;flex:1;background:var(--border);margin:.25rem 0;min-height:20px;}
.tl-content{padding-bottom:1rem;}
.tl-label{font-size:.85rem;font-weight:600;color:var(--ink);}
.tl-sub{font-size:.76rem;color:var(--muted);margin-top:1px;}
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
          <span><span class="status-badge badge-<?= $badge ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></span>
        </div>
        <div class="info-row">
          <span class="info-label">Order Date</span>
          <span class="info-value"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Payment Method</span>
          <!--
            FIX: payment_method column now stores the full label ("Cash on Delivery")
            because checkout.php was updated to save $payment_label instead of $payment_key.
            Old orders that stored the short key (e.g. "cod") will still display as-is.
          -->
          <span class="info-value"><?= htmlspecialchars($payment['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Payment Status</span>
          <span class="info-value"><?= htmlspecialchars($payment['payment_status'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>
    </div>

    <!-- Delivery information -->
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
                $shipping_labels[$shipping['method'] ?? ''] ?? ($shipping['method'] ?? '—'),
                ENT_QUOTES, 'UTF-8'
            ) ?>
          </span>
        </div>
        <div class="info-row" style="grid-column:1/-1">
          <span class="info-label">Delivery Address</span>
          <span class="info-value">
            <?= htmlspecialchars($shipping['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars($shipping['city']    ?? '', ENT_QUOTES, 'UTF-8') ?>
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

    <!-- Items -->
    <div class="od-card">
      <div class="od-card-title">🛍 Items Ordered</div>
      <table class="items-table">
        <thead>
          <tr>
            <th>Product</th><th>Price</th><th>Qty</th>
            <th style="text-align:right">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($item = $items_result->fetch_assoc()): ?>
          <tr>
            <td>
              <div class="prod-cell">
                <img src="../<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>"
                     class="prod-img" alt="">
                <span class="prod-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></span>
              </div>
            </td>
            <td>৳ <?= number_format($item['price']) ?></td>
            <td><?= intval($item['quantity']) ?></td>
            <td style="text-align:right;font-weight:700">
              ৳ <?= number_format($item['price'] * $item['quantity']) ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- RIGHT -->
  <div>
    <!-- Price summary -->
    <div class="od-card">
      <div class="od-card-title">💰 Price Summary</div>

      <!--
        FIX: Previously both rows showed total_amount with no breakdown.
        Now we display subtotal, optional discount, shipping, and final total
        using the new shipping_cost and discount_amount DB columns.
      -->
      <div class="sum-row">
        <span>Subtotal</span>
        <span>৳ <?= number_format($subtotal) ?></span>
      </div>

      <?php if ($discount_amount > 0): ?>
      <div class="sum-row discount">
        <span>
          Coupon discount
          <?php if (!empty($order['coupon_code'])): ?>
            (<?= htmlspecialchars($order['coupon_code'], ENT_QUOTES, 'UTF-8') ?>)
          <?php endif; ?>
        </span>
        <span>−৳ <?= number_format($discount_amount) ?></span>
      </div>
      <?php endif; ?>

      <div class="sum-row">
        <span>
          Shipping
          <?php if (!empty($shipping['method'])): ?>
            (<?= htmlspecialchars(
                $shipping_labels[$shipping['method']] ?? $shipping['method'],
                ENT_QUOTES, 'UTF-8'
            ) ?>)
          <?php endif; ?>
        </span>
        <span>৳ <?= number_format($shipping_cost) ?></span>
      </div>

      <div class="sum-row total">
        <span>Total Paid</span>
        <span>৳ <?= number_format($total_amount) ?></span>
      </div>
    </div>

    <!-- Timeline -->
    <div class="od-card">
      <div class="od-card-title">🕐 Order Timeline</div>
      <?php
      $timeline = [
          'Pending'    => ['Placed',     'Order received'],
          'Processing' => ['Processing', 'Preparing your order'],
          'Shipped'    => ['Shipped',    'On the way'],
          'Delivered'  => ['Delivered',  'Delivered to you'],
      ];
      $statusIndex = array_search($status, array_keys($timeline));
      $i = 0;
      ?>
      <div class="timeline">
        <?php foreach ($timeline as $key => [$label, $sub]):
            $isDone   = $i < $statusIndex;
            $isActive = $i === $statusIndex;
            $isLast   = $i === count($timeline) - 1;
        ?>
        <div class="tl-item">
          <div class="tl-dot-col">
            <div class="tl-dot <?= $isDone ? 'done' : ($isActive ? 'active' : '') ?>"></div>
            <?php if (!$isLast): ?><div class="tl-line"></div><?php endif; ?>
          </div>
          <div class="tl-content">
            <div class="tl-label" style="<?= (!$isDone && !$isActive) ? 'color:var(--muted)' : '' ?>">
              <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="tl-sub"><?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
        <?php $i++; endforeach; ?>
      </div>
    </div>

    <a href="home.php" style="display:block;text-align:center;margin-top:.5rem;font-size:.85rem;color:var(--muted);text-decoration:none">
      Continue Shopping →
    </a>
  </div>
</div>
</div></div>
<?php include("../includes/footer.php"); ?>