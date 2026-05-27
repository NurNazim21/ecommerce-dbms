<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = intval($_SESSION['user_id']);

// ── FETCH ORDERS ──────────────────────────────────────────────────────────────
// UPGRADE: joins payments for payment_status and shipments for shipping_status.
//          Also checks for unread notifications per order.
$stmt = $conn->prepare("
    SELECT
        o.id,
        o.total_amount,
        o.status,
        o.created_at,
        o.shipping_cost,
        o.discount_amount,
        COUNT(DISTINCT oi.id)    AS item_count,
        p.payment_method,
        p.payment_status,
        s.shipping_status,
        s.tracking_number,
        COALESCE(oa.city, '')    AS ship_city,
        (
            SELECT COUNT(*) FROM notifications
            WHERE user_id = o.user_id
              AND link LIKE CONCAT('%order_details.php?id=', o.id, '%')
              AND is_read = 0
        ) AS unread_notifs
    FROM orders o
    LEFT JOIN order_items oi     ON oi.order_id   = o.id
    LEFT JOIN payments p         ON p.order_id    = o.id
    LEFT JOIN shipments s        ON s.order_id    = o.id
    LEFT JOIN order_addresses oa ON oa.order_id   = o.id
    WHERE o.user_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// ── STATUS COUNTS ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM orders WHERE user_id = ? GROUP BY status");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$count_rows = $stmt->get_result();
$stmt->close();

$counts = ['all' => 0, 'Pending' => 0, 'Processing' => 0, 'Shipped' => 0, 'Delivered' => 0, 'Cancelled' => 0];
while ($cr = $count_rows->fetch_assoc()) {
    if (isset($counts[$cr['status']])) $counts[$cr['status']] = $cr['cnt'];
    $counts['all'] += $cr['cnt'];
}

// Badge helpers
function status_badge(string $status): string {
    return match($status) {
        'Delivered'                   => 'bg-success',
        'Cancelled', 'Refunded'       => 'bg-danger',
        'Shipped'                     => 'bg-primary',
        'Confirmed', 'Processing'     => 'bg-info',
        default                       => 'bg-warning text-dark'
    };
}
function payment_cls(string $ps): string {
    return match($ps) {
        'Paid'   => 'pay-paid',
        'Failed' => 'pay-fail',
        default  => 'pay-pend'
    };
}
?>
<?php include("../includes/header.php"); ?>

<style>
.orders-page { background:#f3f4f6; min-height:100vh; padding:2rem 0 5rem; }
.page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; flex-wrap:wrap; gap:.75rem; }
.page-header h1 { font-size:1.6rem; font-weight:800; color:#0f0f0f; margin:0; }
.stat-pills { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.25rem; }
.pill { display:flex; align-items:center; gap:6px; background:#fff; border-radius:50px; padding:5px 14px; font-size:.81rem; font-weight:600; color:#444; box-shadow:0 1px 5px rgba(0,0,0,.07); text-decoration:none; border:2px solid transparent; transition:all .14s; }
.pill.active { background:#131921; color:#fff; border-color:#131921; }
.pill .cnt { background:rgba(0,0,0,.08); border-radius:50px; padding:1px 7px; font-size:.72rem; font-weight:700; }
.pill.active .cnt { background:rgba(255,255,255,.2); }
.order-card { background:#fff; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.08); margin-bottom:1rem; overflow:hidden; transition:box-shadow .14s; border:1.5px solid transparent; }
.order-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.11); border-color:#e5e7eb; }
.order-card.has-unread { border-left:3px solid #f59e0b; }
.order-card-header { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem .75rem; flex-wrap:wrap; gap:.5rem; border-bottom:.5px solid #f2f4f8; }
.order-id { font-size:1rem; font-weight:800; color:#131921; }
.order-date { font-size:.78rem; color:#9ca3af; margin-top:2px; }
.order-card-body { padding:.85rem 1.25rem 1rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; }
.order-meta { display:flex; gap:1.5rem; flex-wrap:wrap; align-items:center; }
.meta-item { display:flex; flex-direction:column; gap:1px; }
.meta-label { font-size:.7rem; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em; }
.meta-value { font-size:.88rem; font-weight:600; color:#0f0f0f; }
.price-val { font-size:1rem; font-weight:800; color:#1a7a4a; }
.pay-badge { display:inline-block; font-size:.71rem; font-weight:700; border-radius:5px; padding:2px 8px; }
.pay-paid  { background:#d1fae5; color:#065f46; }
.pay-pend  { background:#fef9c3; color:#854d0e; }
.pay-fail  { background:#fee2e2; color:#991b1b; }
.ship-badge { display:inline-block; font-size:.71rem; font-weight:700; border-radius:5px; padding:2px 8px; background:#e0f2fe; color:#0369a1; }
.unread-dot { width:8px; height:8px; border-radius:50%; background:#f59e0b; display:inline-block; margin-left:4px; }
.btn-view { display:inline-flex; align-items:center; gap:.35rem; padding:.55rem 1.1rem; background:#131921; color:#fff; border-radius:7px; font-size:.82rem; font-weight:700; text-decoration:none; transition:background .13s; white-space:nowrap; }
.btn-view:hover { background:#232f3e; color:#fff; }
.btn-refund { display:inline-flex; align-items:center; gap:.35rem; padding:.5rem .9rem; background:#fee2e2; color:#991b1b; border-radius:7px; font-size:.78rem; font-weight:700; text-decoration:none; transition:background .13s; border:1px solid #fca5a5; }
.btn-refund:hover { background:#fecaca; color:#7f1d1d; }
.empty-state { text-align:center; padding:4rem 1rem; background:#fff; border-radius:12px; }
.success-alert { background:#d1fae5; color:#065f46; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.25rem; font-weight:600; font-size:.9rem; display:flex; align-items:center; gap:.5rem; }
</style>

<div class="orders-page">
<div class="container">

    <div class="page-header">
        <h1>My Orders</h1>
        <a href="home.php" class="btn btn-outline-secondary btn-sm">← Continue Shopping</a>
    </div>

    <!-- UPGRADE: success alert moved here (above list) -->
    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
        <div class="success-alert">
            <i class="fas fa-check-circle"></i>
            Your order #<?= intval($_GET['order_id'] ?? 0) ?> has been placed successfully!
        </div>
    <?php endif; ?>

    <!-- Status filter pills -->
    <?php
    $filter = $_GET['filter'] ?? '';
    $filter_map = [
        ''           => ['All Orders', $counts['all']],
        'Pending'    => ['Pending',    $counts['Pending']],
        'Processing' => ['Processing', $counts['Processing']],
        'Shipped'    => ['Shipped',    $counts['Shipped']],
        'Delivered'  => ['Delivered',  $counts['Delivered']],
        'Cancelled'  => ['Cancelled',  $counts['Cancelled']],
    ];
    ?>
    <div class="stat-pills">
        <?php foreach ($filter_map as $val => [$label, $cnt]): ?>
        <a href="orders.php?filter=<?= urlencode($val) ?><?= isset($_GET['success']) ? '&success='.$_GET['success'].'&order_id='.intval($_GET['order_id']??0) : '' ?>"
           class="pill <?= $filter === $val ? 'active' : '' ?>">
            <?= $label ?> <span class="cnt"><?= intval($cnt) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php
    // Client-side filter — re-fetch if filter is set
    $orders = [];
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        if ($filter === '' || $row['status'] === $filter) {
            $orders[] = $row;
        }
    }
    ?>

    <?php if (!empty($orders)): ?>
        <?php foreach ($orders as $order):
            $days_since = (time() - strtotime($order['created_at'])) / 86400;
            $can_refund = $order['status'] === 'Delivered' && $days_since <= 7;
        ?>
        <div class="order-card <?= $order['unread_notifs'] > 0 ? 'has-unread' : '' ?>">

            <div class="order-card-header">
                <div>
                    <div class="order-id">
                        Order #<?= intval($order['id']) ?>
                        <?php if ($order['unread_notifs'] > 0): ?>
                            <span class="unread-dot" title="New update"></span>
                        <?php endif; ?>
                    </div>
                    <div class="order-date"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></div>
                </div>
                <span class="badge <?= status_badge($order['status']) ?> rounded-pill px-3">
                    <?= htmlspecialchars(str_replace('_',' ',$order['status']), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <div class="order-card-body">
                <div class="order-meta">
                    <div class="meta-item">
                        <span class="meta-label">Items</span>
                        <span class="meta-value">📦 <?= intval($order['item_count']) ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Total</span>
                        <span class="price-val">৳ <?= number_format($order['total_amount']) ?></span>
                    </div>
                    <!-- UPGRADE: payment status -->
                    <div class="meta-item">
                        <span class="meta-label">Payment</span>
                        <div>
                            <span class="meta-value" style="font-size:.78rem"><?= htmlspecialchars($order['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span><br>
                            <span class="pay-badge <?= payment_cls($order['payment_status'] ?? '') ?>">
                                <?= htmlspecialchars($order['payment_status'] ?? 'Pending', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                    <!-- UPGRADE: shipment status -->
                    <?php if (!empty($order['shipping_status'])): ?>
                    <div class="meta-item">
                        <span class="meta-label">Shipment</span>
                        <span class="ship-badge">
                            <?= htmlspecialchars(ucfirst(str_replace('_',' ',$order['shipping_status'])), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if (!empty($order['tracking_number'])): ?>
                            <div style="font-size:.7rem;color:#aaa;margin-top:1px"><?= htmlspecialchars($order['tracking_number'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['ship_city'])): ?>
                    <div class="meta-item">
                        <span class="meta-label">Ship to</span>
                        <span class="meta-value" style="font-size:.82rem">📍 <?= htmlspecialchars($order['ship_city'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <a href="order_details.php?id=<?= intval($order['id']) ?>" class="btn-view">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    <!-- UPGRADE: refund button visible for 7 days after delivery -->
                    <?php if ($can_refund): ?>
                    <a href="refund_request.php?order_id=<?= intval($order['id']) ?>" class="btn-refund">
                        ↩ Refund
                    </a>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="empty-state">
            <div style="font-size:3rem;margin-bottom:.75rem">🛒</div>
            <h5 style="font-weight:700"><?= $filter ? "No $filter orders" : "No orders yet" ?></h5>
            <p class="text-muted">
                <?= $filter ? 'Try a different filter above.' : "Looks like you haven't placed any orders yet." ?>
            </p>
            <?php if (!$filter): ?>
            <a href="home.php" class="btn btn-primary mt-2">Start Shopping</a>
            <?php else: ?>
            <a href="orders.php" class="btn btn-outline-secondary mt-2">View All Orders</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
</div>

<?php include("../includes/footer.php"); ?>