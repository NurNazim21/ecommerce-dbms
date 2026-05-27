<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$seller_id = intval($_SESSION['user_id']);

// ── UPGRADE: use v_seller_earnings view for summary row ──────────────────────
// Old: manually summed oi.quantity * oi.price through products.seller_id.
//      Didn't know about commissions, net earnings, or payout status.
// New: reads pre-aggregated columns from the view, which was built in
//      upgrade_order_system.sql from vendor_orders rows.
$stmt = $conn->prepare("
    SELECT * FROM v_seller_earnings WHERE seller_id = ?
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$earnings = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fallback zeros if no vendor orders yet
$gross_revenue     = floatval($earnings['gross_revenue']     ?? 0);
$total_commission  = floatval($earnings['total_commission']  ?? 0);
$net_earnings      = floatval($earnings['net_earnings']      ?? 0);
$paid_out          = floatval($earnings['paid_out']          ?? 0);
$pending_payout    = floatval($earnings['pending_payout']    ?? 0);
$total_vo          = intval($earnings['total_vendor_orders'] ?? 0);

// ── UPGRADE: approved product count ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM products WHERE seller_id = ? AND status = 'approved'");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$total_products = intval($stmt->get_result()->fetch_assoc()['c']);
$stmt->close();

// ── UPGRADE: payout breakdown by status from vendor_orders ───────────────────
$stmt = $conn->prepare("
    SELECT payout_status, COUNT(*) as cnt, SUM(seller_net) as total
    FROM vendor_orders
    WHERE seller_id = ? AND status NOT IN ('cancelled')
    GROUP BY payout_status
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$payout_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$payout_by_status = [];
foreach ($payout_rows as $r) $payout_by_status[$r['payout_status']] = $r;

// ── UPGRADE: recent sales via vendor_orders + order_addresses ────────────────
// Old: raw join through products.seller_id — could double-count multi-item orders.
// New: one row per vendor_order, with city from order_addresses.
$stmt = $conn->prepare("
    SELECT
        vo.id           AS vo_id,
        vo.parent_order_id,
        vo.subtotal,
        vo.seller_net,
        vo.commission_amount,
        vo.status       AS vo_status,
        vo.payout_status,
        vo.created_at,
        u.name          AS customer_name,
        COALESCE(oa.city, '') AS ship_city,
        p_pay.payment_status
    FROM vendor_orders vo
    JOIN orders o              ON o.id           = vo.parent_order_id
    JOIN users u               ON u.id           = o.user_id
    LEFT JOIN order_addresses oa ON oa.order_id  = o.id
    LEFT JOIN payments p_pay   ON p_pay.order_id = o.id
    WHERE vo.seller_id = ?
    ORDER BY vo.created_at DESC
    LIMIT 15
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$recent_sales = $stmt->get_result();
$stmt->close();

// ── UPGRADE: top products by revenue for this seller ─────────────────────────
$stmt = $conn->prepare("
    SELECT p.name, SUM(oi.quantity) as units_sold,
           SUM(oi.quantity * oi.price) as product_revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE p.seller_id = ? AND p.status = 'approved'
    GROUP BY p.id
    ORDER BY product_revenue DESC
    LIMIT 5
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$top_products = $stmt->get_result();
$stmt->close();

// ── UPGRADE: pending payout request check ────────────────────────────────────
$stmt = $conn->prepare("
    SELECT COUNT(*) as c FROM seller_payout_requests
    WHERE seller_id = ? AND status IN ('pending','approved','processing')
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$has_pending_request = intval($stmt->get_result()->fetch_assoc()['c']) > 0;
$stmt->close();

$status_colors = [
    'pending'       => ['bg' => '#fff8e1', 'color' => '#856404'],
    'processing'    => ['bg' => '#e8f0ff', 'color' => '#084298'],
    'ready_to_ship' => ['bg' => '#fef9e7', 'color' => '#5f4b00'],
    'shipped'       => ['bg' => '#e6faf5', 'color' => '#0f5132'],
    'delivered'     => ['bg' => '#e9f7ef', 'color' => '#1a7a4a'],
    'cancelled'     => ['bg' => '#fdecea', 'color' => '#842029'],
];
?>
<?php include("../includes/header.php"); ?>

<style>
.earnings-page { background:#f4f6fb; min-height:100vh; padding:32px 0 60px; }
.page-header { background:linear-gradient(135deg,#1b4332,#2d6a4f); border-radius:14px; padding:22px 28px; margin-bottom:22px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; box-shadow:0 6px 24px rgba(27,67,50,.18); }
.page-header h1 { color:#fff; font-size:1.45rem; font-weight:800; margin:0; }
.page-header .sub { color:#95d5b2; font-size:.83rem; margin-top:2px; }
.btn-back { background:rgba(255,255,255,.15); color:#fff; border:1px solid rgba(255,255,255,.25) !important; border-radius:8px; padding:7px 16px; font-size:.83rem; font-weight:600; text-decoration:none; }
.metric-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
.metric-card { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.mc-left { border-left:4px solid #28a745; }
.mc-blue { border-left:4px solid #0d6efd; }
.mc-orange { border-left:4px solid #fd7e14; }
.mc-purple { border-left:4px solid #6f42c1; }
.mc-teal { border-left:4px solid #20c997; }
.mc-yellow { border-left:4px solid #ffc107; }
.m-label { font-size:.71rem; font-weight:700; color:#999; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.m-value { font-size:1.35rem; font-weight:800; color:#1a1a2e; }
.m-sub { font-size:.73rem; color:#aaa; margin-top:2px; }
.payout-box { background:#fff; border-radius:12px; padding:18px 20px; box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px; border-left:4px solid #2d6a4f; }
.payout-amount { font-size:1.6rem; font-weight:800; color:#1a7a4a; }
.payout-label { font-size:.78rem; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.btn-withdraw { background:#2d6a4f; color:#fff; border:none; border-radius:8px; padding:10px 22px; font-size:.88rem; font-weight:700; cursor:pointer; text-decoration:none; transition:background .14s; }
.btn-withdraw:hover { background:#1b4332; color:#fff; }
.btn-withdraw:disabled, .btn-withdraw.disabled { background:#aaa; cursor:not-allowed; }
.section-title { font-size:.95rem; font-weight:700; color:#1a1a2e; margin-bottom:10px; display:flex; align-items:center; gap:.4rem; }
.dash-card { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.07); overflow:hidden; margin-bottom:20px; }
.dash-table { width:100%; border-collapse:collapse; }
.dash-table th { font-size:.72rem; font-weight:700; color:#999; text-transform:uppercase; padding:10px 14px; border-bottom:1px solid #f2f4f8; text-align:left; background:#fafbfc; }
.dash-table td { padding:11px 14px; border-bottom:1px solid #f7f8fa; font-size:.85rem; vertical-align:middle; }
.dash-table tr:last-child td { border-bottom:none; }
.dash-table tbody tr:hover { background:#f7faf8; }
.vo-status { display:inline-block; border-radius:6px; padding:2px 9px; font-size:.72rem; font-weight:700; }
.pay-ok   { background:#d1fae5; color:#065f46; border-radius:5px; padding:2px 7px; font-size:.71rem; font-weight:700; display:inline-block; }
.pay-pend { background:#fef9c3; color:#854d0e; border-radius:5px; padding:2px 7px; font-size:.71rem; font-weight:700; display:inline-block; }
.payout-pill { display:inline-block; border-radius:5px; padding:2px 8px; font-size:.71rem; font-weight:700; }
.pp-paid    { background:#d1fae5; color:#065f46; }
.pp-pending { background:#fef9c3; color:#854d0e; }
.pp-on_hold { background:#fee2e2; color:#991b1b; }
.btn-view { background:#e6faf5; color:#0f5132; border:1.5px solid #95d5b2; border-radius:6px; padding:4px 11px; font-size:.77rem; font-weight:700; text-decoration:none; }
.btn-view:hover { background:#2d6a4f; color:#fff; border-color:#2d6a4f; }
.empty-row td { text-align:center; padding:30px; color:#aaa; }
</style>

<div class="earnings-page">
<div class="container">

    <div class="page-header">
        <div>
            <h1>💰 Earnings & Sales</h1>
            <div class="sub">Seller Panel — your revenue overview</div>
        </div>
        <a href="dashboard.php" class="btn-back">← Dashboard</a>
    </div>

    <!-- Metric tiles -->
    <div class="metric-grid">
        <div class="metric-card mc-left">
            <div class="m-label">Gross Revenue</div>
            <div class="m-value">৳ <?= number_format($gross_revenue) ?></div>
            <div class="m-sub"><?= $total_vo ?> vendor orders</div>
        </div>
        <div class="metric-card mc-blue">
            <div class="m-label">Commission Paid</div>
            <div class="m-value">৳ <?= number_format($total_commission) ?></div>
            <div class="m-sub">Platform fee</div>
        </div>
        <div class="metric-card mc-teal">
            <div class="m-label">Net Earnings</div>
            <div class="m-value">৳ <?= number_format($net_earnings) ?></div>
            <div class="m-sub">After commission</div>
        </div>
        <div class="metric-card mc-purple">
            <div class="m-label">Paid Out</div>
            <div class="m-value">৳ <?= number_format($paid_out) ?></div>
        </div>
        <div class="metric-card mc-yellow">
            <div class="m-label">Pending Payout</div>
            <div class="m-value" style="color:#c05621">৳ <?= number_format($pending_payout) ?></div>
        </div>
        <div class="metric-card mc-orange">
            <div class="m-label">Products</div>
            <div class="m-value"><?= $total_products ?></div>
            <div class="m-sub">Approved & live</div>
        </div>
    </div>

    <!-- UPGRADE: Payout withdrawal box -->
    <div class="payout-box">
        <div>
            <div class="payout-label">Available for Withdrawal</div>
            <div class="payout-amount">৳ <?= number_format($pending_payout) ?></div>
            <div style="font-size:.75rem;color:#aaa;margin-top:3px">
                Paid out so far: ৳ <?= number_format($paid_out) ?>
            </div>
        </div>
        <?php if ($pending_payout > 0 && !$has_pending_request): ?>
            <a href="payout_request.php" class="btn-withdraw">Request Payout →</a>
        <?php elseif ($has_pending_request): ?>
            <span class="btn-withdraw disabled">Payout Request Pending</span>
        <?php else: ?>
            <span style="font-size:.83rem;color:#aaa">No balance to withdraw.</span>
        <?php endif; ?>
    </div>

    <div class="row g-4">

        <!-- Recent sales from vendor_orders -->
        <div class="col-12">
            <div class="section-title">📋 Recent Sales</div>
            <div class="dash-card">
                <div style="overflow-x:auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Vendor Order</th><th>Customer</th><th>Subtotal</th>
                            <th>Your Earnings</th><th>Payment</th><th>Payout</th>
                            <th>Status</th><th>Date</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($recent_sales->num_rows > 0): ?>
                        <?php while ($s = $recent_sales->fetch_assoc()):
                            $vs = $s['vo_status'];
                            $sc = $status_colors[$vs] ?? ['bg' => '#f0f0f0', 'color' => '#555'];
                            $po = $s['payout_status'];
                        ?>
                        <tr>
                            <td>
                                <span style="font-weight:800;color:#2d6a4f">#<?= intval($s['vo_id']) ?></span>
                                <div style="font-size:.72rem;color:#aaa">Order #<?= intval($s['parent_order_id']) ?></div>
                            </td>
                            <td>
                                <span style="font-weight:600"><?= htmlspecialchars($s['customer_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($s['ship_city']): ?>
                                    <div style="font-size:.73rem;color:#aaa">📍 <?= htmlspecialchars($s['ship_city'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700;color:#1a7a4a">৳ <?= number_format($s['subtotal']) ?></td>
                            <td>
                                <span style="font-weight:800;color:#2d6a4f">৳ <?= number_format($s['seller_net']) ?></span>
                                <div style="font-size:.71rem;color:#aaa">−৳ <?= number_format($s['commission_amount']) ?> comm.</div>
                            </td>
                            <td>
                                <span class="<?= $s['payment_status'] === 'Paid' ? 'pay-ok' : 'pay-pend' ?>">
                                    <?= htmlspecialchars($s['payment_status'] ?? 'Pending', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td>
                                <span class="payout-pill pp-<?= htmlspecialchars($po, ENT_QUOTES) ?>">
                                    <?= ucfirst(htmlspecialchars($po, ENT_QUOTES, 'UTF-8')) ?>
                                </span>
                            </td>
                            <td>
                                <span class="vo-status"
                                      style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', htmlspecialchars($vs, ENT_QUOTES, 'UTF-8'))) ?>
                                </span>
                            </td>
                            <td style="font-size:.78rem;color:#aaa">
                                <?= date('d M, Y', strtotime($s['created_at'])) ?>
                            </td>
                            <td>
                                <a href="order_details.php?vo_id=<?= intval($s['vo_id']) ?>" class="btn-view">View →</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-row"><td colspan="9">No sales yet. Your vendor orders will appear here once customers place orders.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <div style="padding:10px 14px;border-top:1px solid #f2f4f8;">
                    <a href="orders.php" style="font-size:.82rem;font-weight:700;color:#2d6a4f;text-decoration:none;">View all orders →</a>
                </div>
            </div>
        </div>

        <!-- Top products by revenue -->
        <div class="col-md-6">
            <div class="section-title">🏆 Your Top Products</div>
            <div class="dash-card">
                <table class="dash-table">
                    <thead><tr><th>Product</th><th>Units</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php if ($top_products->num_rows > 0):
                        while ($tp = $top_products->fetch_assoc()): ?>
                        <tr>
                            <td style="font-weight:600"><?= htmlspecialchars($tp['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= intval($tp['units_sold']) ?></td>
                            <td style="font-weight:700;color:#1a7a4a">৳ <?= number_format($tp['product_revenue']) ?></td>
                        </tr>
                        <?php endwhile;
                    else: ?>
                        <tr class="empty-row"><td colspan="3">No product sales yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payout status breakdown -->
        <div class="col-md-6">
            <div class="section-title">💳 Payout Breakdown</div>
            <div class="dash-card">
                <table class="dash-table">
                    <thead><tr><th>Payout Status</th><th>Orders</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php
                    $payout_labels = [
                        'paid'       => ['✅ Paid',       'pp-paid'],
                        'pending'    => ['⏳ Pending',    'pp-pending'],
                        'on_hold'    => ['🔒 On Hold',    'pp-on_hold'],
                        'processing' => ['⚙ Processing', 'pay-pend'],
                    ];
                    if (!empty($payout_by_status)):
                        foreach ($payout_labels as $key => [$label, $cls]):
                            if (isset($payout_by_status[$key])):
                                $row = $payout_by_status[$key];
                    ?>
                    <tr>
                        <td><span class="payout-pill <?= $cls ?>"><?= $label ?></span></td>
                        <td><?= intval($row['cnt']) ?></td>
                        <td style="font-weight:700">৳ <?= number_format($row['total']) ?></td>
                    </tr>
                    <?php
                            endif;
                        endforeach;
                    else: ?>
                        <tr class="empty-row"><td colspan="3">No payout data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
</div>

<?php include("../includes/footer.php"); ?>