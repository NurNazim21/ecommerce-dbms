<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$seller_id = intval($_SESSION['user_id']);

// ── VALID VENDOR ORDER STATUSES ───────────────────────────────────────────────
$valid_statuses = ['pending', 'processing', 'ready_to_ship', 'shipped', 'delivered', 'cancelled'];

// ── FILTERS ───────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['search'] ?? '');
$filter_date   = $_GET['date']   ?? '';

if (!in_array($filter_status, $valid_statuses, true)) $filter_status = '';
$valid_dates = ['today', 'week', 'month'];
if (!in_array($filter_date, $valid_dates, true))       $filter_date = '';

$where_clauses = ["vo.seller_id = ?"];
$bind_types    = "i";
$bind_values   = [$seller_id];

if ($filter_status !== '') {
    $where_clauses[] = "vo.status = ?";
    $bind_types     .= "s";
    $bind_values[]   = $filter_status;
}
if ($filter_search !== '') {
    $like = "%{$filter_search}%";
    $search_id = is_numeric($filter_search) ? intval($filter_search) : 0;
    if ($search_id > 0) {
        $where_clauses[] = "(u.name LIKE ? OR vo.id = ? OR vo.parent_order_id = ?)";
        $bind_types     .= "sii";
        $bind_values[]   = $like;
        $bind_values[]   = $search_id;
        $bind_values[]   = $search_id;
    } else {
        $where_clauses[] = "u.name LIKE ?";
        $bind_types     .= "s";
        $bind_values[]   = $like;
    }
}
if ($filter_date === 'today') {
    $where_clauses[] = "DATE(vo.created_at) = CURDATE()";
} elseif ($filter_date === 'week') {
    $where_clauses[] = "vo.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($filter_date === 'month') {
    $where_clauses[] = "vo.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$where_sql = implode(' AND ', $where_clauses);

// ── FETCH VENDOR ORDERS ───────────────────────────────────────────────────────
// UPGRADE: reads from vendor_orders (split per-seller orders) instead of
//          scanning all orders for products that happen to belong to this seller.
//          Also reads delivery address from order_addresses table.
$sql = "
    SELECT
        vo.id                               AS vo_id,
        vo.parent_order_id,
        vo.status                           AS vo_status,
        vo.subtotal,
        vo.shipping_cost,
        vo.commission_amount,
        vo.seller_net,
        vo.payout_status,
        vo.created_at,
        vo.confirmed_at,
        vo.shipped_at,
        vo.delivered_at,
        COUNT(oi.id)                        AS item_count,
        u.name                              AS customer_name,
        u.phone                             AS customer_phone,
        COALESCE(oa.recipient_name, u.name) AS ship_name,
        COALESCE(oa.city, '')               AS ship_city,
        p.payment_method,
        p.payment_status,
        s.tracking_number,
        s.shipping_status
    FROM vendor_orders vo
    JOIN orders o             ON o.id           = vo.parent_order_id
    JOIN users u              ON u.id           = o.user_id
    JOIN order_items oi       ON oi.vendor_order_id = vo.id
    LEFT JOIN order_addresses oa ON oa.order_id = o.id
    LEFT JOIN payments p      ON p.order_id     = o.id
    LEFT JOIN shipments s     ON s.vendor_order_id = vo.id
    WHERE {$where_sql}
    GROUP BY vo.id
    ORDER BY vo.created_at DESC
";

$stmt = $conn->prepare($sql);
$params = array_merge([$bind_types], $bind_values);
$refs   = [];
foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
call_user_func_array([$stmt, 'bind_param'], $refs);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// ── STATUS COUNTS ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT status, COUNT(*) as cnt
    FROM vendor_orders
    WHERE seller_id = ?
    GROUP BY status
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$count_rows = $stmt->get_result();
$stmt->close();

$counts = ['all' => 0];
foreach ($valid_statuses as $s) $counts[$s] = 0;
while ($cr = $count_rows->fetch_assoc()) {
    if (isset($counts[$cr['status']])) $counts[$cr['status']] = $cr['cnt'];
    $counts['all'] += $cr['cnt'];
}

// ── EARNINGS SUMMARY ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        SUM(subtotal)                                                    AS gross,
        SUM(commission_amount)                                           AS commission,
        SUM(seller_net)                                                  AS net,
        SUM(CASE WHEN payout_status = 'paid'    THEN seller_net ELSE 0 END) AS paid_out,
        SUM(CASE WHEN payout_status = 'pending' THEN seller_net ELSE 0 END) AS pending_pay
    FROM vendor_orders
    WHERE seller_id = ? AND status NOT IN ('cancelled')
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$earnings = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<?php include("../includes/header.php"); ?>

<style>
.seller-orders { background:#f4f6fb; min-height:100vh; padding:32px 0 60px; }
.page-header { background:linear-gradient(135deg,#1b4332,#2d6a4f); border-radius:14px; padding:24px 28px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; box-shadow:0 6px 24px rgba(27,67,50,.18); }
.page-header h1 { color:#fff; font-size:1.5rem; font-weight:700; margin:0; }
.page-header .sub { color:#95d5b2; font-size:.85rem; margin-top:2px; }
.btn-back { background:rgba(255,255,255,.15); color:#fff; border:1px solid rgba(255,255,255,.25) !important; border-radius:8px; padding:7px 16px; font-size:.85rem; font-weight:600; text-decoration:none; }
.btn-back:hover { background:rgba(255,255,255,.25); color:#fff; }
.summary-tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:22px; }
.tile { background:#fff; border-radius:12px; padding:14px 16px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.tile .t-label { font-size:.72rem; font-weight:700; color:#999; text-transform:uppercase; letter-spacing:.5px; }
.tile .t-val   { font-size:1.3rem; font-weight:800; color:#1a1a2e; margin-top:2px; }
.tile .t-icon  { font-size:1.3rem; margin-bottom:3px; }
.tile.green .t-val { color:#1a7a4a; }
.tile.orange .t-val { color:#c05621; }
.stat-pills { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
.pill { display:flex; align-items:center; gap:6px; background:#fff; border-radius:50px; padding:6px 14px; font-size:.82rem; font-weight:600; color:#444; box-shadow:0 2px 6px rgba(0,0,0,.07); text-decoration:none; border:2px solid transparent; transition:all .15s; }
.pill:hover { transform:translateY(-1px); }
.pill.active { border-color:currentColor; }
.pill .dot  { width:8px; height:8px; border-radius:50%; }
.pill .cnt  { background:#f0f0f0; border-radius:50px; padding:1px 7px; font-size:.73rem; font-weight:700; }
.pill-all       { color:#495057; } .pill-all .dot       { background:#6c757d; }
.pill-pending   { color:#856404; } .pill-pending .dot   { background:#ffc107; }
.pill-processing{ color:#084298; } .pill-processing .dot{ background:#0d6efd; }
.pill-ready     { color:#5f4b00; } .pill-ready .dot     { background:#f39c12; }
.pill-shipped   { color:#0f5132; } .pill-shipped .dot   { background:#20c997; }
.pill-delivered { color:#1a7a4a; } .pill-delivered .dot { background:#28a745; }
.pill-cancelled { color:#842029; } .pill-cancelled .dot { background:#dc3545; }
.filter-bar { background:#fff; border-radius:12px; padding:14px 18px; box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:20px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.filter-bar .sw { flex:1; min-width:180px; position:relative; }
.filter-bar .sw input { padding-left:34px; border-radius:7px; border:1.5px solid #dee2e6; height:38px; font-size:.88rem; width:100%; outline:none; }
.filter-bar .sw input:focus { border-color:#2d6a4f; }
.filter-bar .sw .si { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#aaa; }
.filter-bar select { border-radius:7px; border:1.5px solid #dee2e6; height:38px; font-size:.86rem; padding:0 10px; outline:none; min-width:140px; }
.btn-apply { background:#2d6a4f; color:#fff; border:none; border-radius:7px; height:38px; padding:0 18px; font-weight:600; font-size:.86rem; cursor:pointer; }
.btn-apply:hover { background:#1b4332; }
.btn-reset { background:#f0f0f0; color:#555; border:none; border-radius:7px; height:38px; padding:0 14px; font-size:.84rem; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
.table-wrap { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.07); overflow:hidden; }
.table-wrap table { width:100%; border-collapse:collapse; margin:0; }
.table-wrap thead th { background:#1b4332; color:#b7e4c7; font-size:.76rem; text-transform:uppercase; letter-spacing:.6px; padding:12px 14px; border:none; font-weight:600; white-space:nowrap; }
.table-wrap tbody tr { border-bottom:1px solid #f2f4f8; transition:background .1s; }
.table-wrap tbody tr:hover { background:#f7faf8; }
.table-wrap tbody td { padding:12px 14px; vertical-align:middle; font-size:.87rem; border:none; }
.vo-id { font-weight:800; color:#2d6a4f; }
.cust-name { font-weight:700; color:#1a1a2e; }
.cust-sub  { color:#888; font-size:.76rem; margin-top:1px; }
.price-green { font-weight:700; color:#1a7a4a; }
.vo-status { display:inline-block; border-radius:6px; padding:3px 10px; font-size:.76rem; font-weight:700; }
.vs-pending        { background:#fff8e1; color:#856404; }
.vs-processing     { background:#e8f0ff; color:#084298; }
.vs-ready_to_ship  { background:#fef9e7; color:#5f4b00; }
.vs-shipped        { background:#e6faf5; color:#0f5132; }
.vs-delivered      { background:#e9f7ef; color:#1a7a4a; }
.vs-cancelled      { background:#fdecea; color:#842029; }
.pay-badge { display:inline-block; font-size:.71rem; font-weight:700; border-radius:5px; padding:2px 7px; }
.pay-paid    { background:#d1fae5; color:#065f46; }
.pay-pending { background:#fef9c3; color:#854d0e; }
.payout-badge { display:inline-block; font-size:.71rem; font-weight:700; border-radius:5px; padding:2px 7px; }
.pb-paid    { background:#d1fae5; color:#065f46; }
.pb-pending { background:#fef9c3; color:#854d0e; }
.pb-on_hold { background:#fee2e2; color:#991b1b; }
.btn-view { background:#e6faf5; color:#0f5132; border:1.5px solid #95d5b2; border-radius:6px; padding:5px 12px; font-size:.78rem; font-weight:700; text-decoration:none; transition:all .14s; }
.btn-view:hover { background:#2d6a4f; color:#fff; border-color:#2d6a4f; }
.results-bar { font-size:.83rem; color:#888; padding:12px 16px 0; display:flex; justify-content:space-between; flex-wrap:wrap; gap:6px; }
.empty { padding:50px 20px; text-align:center; color:#999; }
.empty .ei { font-size:3rem; margin-bottom:10px; }
</style>

<div class="seller-orders">
<div class="container">

    <div class="page-header">
        <div>
            <h1>📦 My Received Orders</h1>
            <div class="sub">Seller Panel — your vendor orders</div>
        </div>
        <a href="dashboard.php" class="btn-back">← Dashboard</a>
    </div>

    <!-- Earnings summary tiles -->
    <div class="summary-tiles">
        <div class="tile"><div class="t-icon">📋</div><div class="t-label">Total Orders</div><div class="t-val"><?= intval($counts['all']) ?></div></div>
        <div class="tile"><div class="t-icon">⏳</div><div class="t-label">Pending</div><div class="t-val" style="color:#856404"><?= intval($counts['pending']) ?></div></div>
        <div class="tile"><div class="t-icon">🚚</div><div class="t-label">Shipped</div><div class="t-val" style="color:#0f5132"><?= intval($counts['shipped']) ?></div></div>
        <div class="tile green"><div class="t-icon">💰</div><div class="t-label">Net Earnings</div><div class="t-val">৳ <?= number_format($earnings['net'] ?? 0) ?></div></div>
        <div class="tile orange"><div class="t-icon">⏸</div><div class="t-label">Pending Payout</div><div class="t-val">৳ <?= number_format($earnings['pending_pay'] ?? 0) ?></div></div>
    </div>

    <!-- Status pills -->
    <?php
    $pp = function($s) use ($filter_search, $filter_date) {
        $p = [];
        if ($s)             $p[] = 'status=' . urlencode($s);
        if ($filter_search) $p[] = 'search=' . urlencode($filter_search);
        if ($filter_date)   $p[] = 'date='   . urlencode($filter_date);
        return 'orders.php' . ($p ? '?' . implode('&', $p) : '');
    };
    $pills_cfg = [
        ''              => ['All',          'pill-all',       $counts['all']],
        'pending'       => ['Pending',      'pill-pending',   $counts['pending']],
        'processing'    => ['Processing',   'pill-processing',$counts['processing']],
        'ready_to_ship' => ['Ready to Ship','pill-ready',     $counts['ready_to_ship']],
        'shipped'       => ['Shipped',      'pill-shipped',   $counts['shipped']],
        'delivered'     => ['Delivered',    'pill-delivered', $counts['delivered']],
        'cancelled'     => ['Cancelled',    'pill-cancelled', $counts['cancelled']],
    ];
    ?>
    <div class="stat-pills">
        <?php foreach ($pills_cfg as $val => [$label, $cls, $cnt]): ?>
        <a href="<?= $pp($val) ?>"
           class="pill <?= $cls ?> <?= $filter_status === $val ? 'active' : '' ?>">
            <span class="dot"></span>
            <?= $label ?>
            <span class="cnt"><?= intval($cnt) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="orders.php" class="filter-bar">
        <div class="sw">
            <span class="si">🔍</span>
            <input type="text" name="search" placeholder="Search customer or order ID…"
                   value="<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach ($valid_statuses as $s): ?>
            <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>>
                <?= ucfirst(str_replace('_',' ',$s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="date">
            <option value="">All Time</option>
            <option value="today" <?= $filter_date === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="week"  <?= $filter_date === 'week'  ? 'selected' : '' ?>>Last 7 Days</option>
            <option value="month" <?= $filter_date === 'month' ? 'selected' : '' ?>>Last 30 Days</option>
        </select>
        <button type="submit" class="btn-apply">Apply</button>
        <?php if ($filter_search || $filter_status || $filter_date): ?>
            <a href="orders.php" class="btn-reset">✕ Reset</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="table-wrap">
        <?php $total = $result->num_rows; ?>
        <div class="results-bar">
            <span>Showing <strong><?= $total ?></strong> order<?= $total != 1 ? 's' : '' ?></span>
        </div>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Vendor Order</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Subtotal</th>
                    <th>Your Earnings</th>
                    <th>Payment</th>
                    <th>Payout</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($total > 0): ?>
                <?php while ($vo = $result->fetch_assoc()):
                    $vs  = $vo['vo_status'];
                    $pay = $vo['payment_status'] ?? 'Pending';
                    $po  = $vo['payout_status']  ?? 'pending';
                ?>
                <tr>
                    <td>
                        <span class="vo-id">#<?= intval($vo['vo_id']) ?></span>
                        <div class="cust-sub">Order #<?= intval($vo['parent_order_id']) ?></div>
                    </td>
                    <td>
                        <div class="cust-name"><?= htmlspecialchars($vo['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($vo['ship_city'])): ?>
                            <div class="cust-sub">📍 <?= htmlspecialchars($vo['ship_city'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </td>
                    <td>📦 <?= intval($vo['item_count']) ?></td>
                    <td><span class="price-green">৳ <?= number_format($vo['subtotal']) ?></span></td>
                    <td>
                        <span class="price-green">৳ <?= number_format($vo['seller_net']) ?></span>
                        <div class="cust-sub">after <?= number_format($vo['commission_amount']) ?> comm.</div>
                    </td>
                    <td>
                        <div style="font-size:.76rem;color:#555"><?= htmlspecialchars($vo['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                        <span class="pay-badge <?= $pay === 'Paid' ? 'pay-paid' : 'pay-pending' ?>">
                            <?= htmlspecialchars($pay, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td>
                        <span class="payout-badge pb-<?= $po ?>">
                            <?= ucfirst(htmlspecialchars($po, ENT_QUOTES, 'UTF-8')) ?>
                        </span>
                    </td>
                    <td>
                        <span class="vo-status vs-<?= htmlspecialchars($vs, ENT_QUOTES) ?>">
                            <?= ucfirst(str_replace('_',' ', htmlspecialchars($vs, ENT_QUOTES, 'UTF-8'))) ?>
                        </span>
                    </td>
                    <td style="font-size:.8rem;color:#777">
                        <?= date('d M, Y', strtotime($vo['created_at'])) ?>
                    </td>
                    <td>
                        <a href="order_details.php?vo_id=<?= intval($vo['vo_id']) ?>" class="btn-view">
                            View →
                        </a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="10">
                    <div class="empty">
                        <div class="ei">📦</div>
                        <h5><?= ($filter_search || $filter_status || $filter_date) ? 'No orders match your filters' : 'No orders yet' ?></h5>
                        <p>When customers buy your products, they will appear here.</p>
                        <?php if ($filter_search || $filter_status || $filter_date): ?>
                            <a href="orders.php" class="btn-reset mt-2">Reset Filters</a>
                        <?php endif; ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>
</div>

<?php include("../includes/footer.php"); ?>