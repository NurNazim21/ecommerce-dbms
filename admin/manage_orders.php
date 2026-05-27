<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = intval($_SESSION['user_id']);

// ── VALID STATUS WHITELIST ────────────────────────────────────────────────────
// Upgraded to match the new orders.status enum from upgrade_order_system.sql
// Old: ['Pending','Processing','Shipped','Delivered','Cancelled']
// New: adds 'Confirmed', 'Ready_to_Ship', 'Refunded', 'Disputed'
$valid_statuses = [
    'Pending', 'Confirmed', 'Processing', 'Ready_to_Ship',
    'Shipped', 'Delivered', 'Cancelled', 'Refunded', 'Disputed'
];

// ── UPDATE ORDER STATUS ───────────────────────────────────────────────────────
// UPGRADE: replaced plain "UPDATE orders SET status = ?" with a call to
// sp_log_order_status() stored procedure, which:
//   1. Updates orders.status
//   2. Writes an immutable row to order_status_logs (audit trail)
//   3. Sets confirmed_at / cancelled_at timestamps automatically
// The admin can also supply an optional note shown in the order timeline.
if (isset($_POST['update_status'])) {
    $order_id   = intval($_POST['order_id']);
    $new_status = $_POST['status'] ?? '';
    $note       = trim(strip_tags($_POST['status_note'] ?? ''));

    if (!in_array($new_status, $valid_statuses, true)) {
        $error = "Invalid status value.";
    } else {
        try {
            $conn->begin_transaction();

            // UPGRADE: call stored procedure instead of plain UPDATE
            // sp_log_order_status(order_id, new_status, changed_by, note)
            $stmt = $conn->prepare("CALL sp_log_order_status(?, ?, ?, ?)");
            $stmt->bind_param("isis", $order_id, $new_status, $admin_id, $note);
            $stmt->execute();
            $stmt->close();

            // UPGRADE: set lifecycle timestamps on the orders row
            if ($new_status === 'Confirmed') {
                $stmt = $conn->prepare("UPDATE orders SET confirmed_at = NOW() WHERE id = ? AND confirmed_at IS NULL");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $stmt->close();

                // UPGRADE: trigger vendor order creation if not already done
                // sp_create_vendor_orders() is idempotent — it only creates
                // vendor_orders rows that don't already exist for this order.
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM vendor_orders WHERE parent_order_id = ?");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $vo_count = intval($stmt->get_result()->fetch_assoc()['cnt']);
                $stmt->close();

                if ($vo_count === 0) {
                    $conn->query("CALL sp_create_vendor_orders($order_id)");
                }
            }

            if ($new_status === 'Cancelled') {
                $stmt = $conn->prepare("UPDATE orders SET cancelled_at = NOW() WHERE id = ? AND cancelled_at IS NULL");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $stmt->close();

                // Store cancellation reason if provided
                if (!empty($note)) {
                    $stmt = $conn->prepare("UPDATE orders SET cancellation_reason = ? WHERE id = ?");
                    $stmt->bind_param("si", $note, $order_id);
                    $stmt->execute();
                    $stmt->close();
                }

                // Also cancel all related vendor_orders
                $stmt = $conn->prepare("UPDATE vendor_orders SET status = 'cancelled' WHERE parent_order_id = ?");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $stmt->close();
            }

            // UPGRADE: notify buyer of status change via notifications table
            $stmt = $conn->prepare("SELECT user_id FROM orders WHERE id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $buyer_id = intval($stmt->get_result()->fetch_assoc()['user_id']);
            $stmt->close();

            $notif_title = "Order #$order_id Status Update";
            $notif_msg   = "Your order status has been updated to: $new_status" . ($note ? " — $note" : "");
            $notif_link  = "/user/order_details.php?id=$order_id";
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, 'order_status_update', ?, ?, ?)
            ");
            $stmt->bind_param("isss", $buyer_id, $notif_title, $notif_msg, $notif_link);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $success = "Order #$order_id status updated to $new_status.";

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Update failed: " . $e->getMessage();
        }
    }
}

// ── FILTERS ───────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['search'] ?? '');
$filter_date   = $_GET['date']   ?? '';

if (!in_array($filter_status, $valid_statuses, true)) {
    $filter_status = '';
}
$valid_dates = ['today', 'week', 'month'];
if (!in_array($filter_date, $valid_dates, true)) {
    $filter_date = '';
}

$where_clauses = ["1=1"];
$bind_types    = "";
$bind_values   = [];

if ($filter_status !== '') {
    $where_clauses[] = "o.status = ?";
    $bind_types     .= "s";
    $bind_values[]   = $filter_status;
}

if ($filter_search !== '') {
    $like      = "%{$filter_search}%";
    $search_id = is_numeric($filter_search) ? intval($filter_search) : 0;

    if ($search_id > 0) {
        $where_clauses[] = "(u.name LIKE ? OR u.email LIKE ? OR o.id = ?)";
        $bind_types     .= "ssi";
        $bind_values[]   = $like;
        $bind_values[]   = $like;
        $bind_values[]   = $search_id;
    } else {
        $where_clauses[] = "(u.name LIKE ? OR u.email LIKE ?)";
        $bind_types     .= "ss";
        $bind_values[]   = $like;
        $bind_values[]   = $like;
    }
}

if ($filter_date === 'today') {
    $where_clauses[] = "DATE(o.created_at) = CURDATE()";
} elseif ($filter_date === 'week') {
    $where_clauses[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($filter_date === 'month') {
    $where_clauses[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$where_sql = implode(' AND ', $where_clauses);

// ── FETCH ORDERS ──────────────────────────────────────────────────────────────
// UPGRADE: joins payments table for payment_status column
//          joins order_addresses for recipient name (replaces JSON parsing)
//          joins shipments for current shipping_status
$orders_sql = "
    SELECT
        o.id, o.total_amount, o.coupon_code, o.status,
        o.created_at, o.confirmed_at, o.cancelled_at,
        o.shipping_cost, o.discount_amount,
        u.name  AS customer_name,
        u.email AS customer_email,
        COUNT(DISTINCT oi.id)  AS item_count,
        SUM(oi.quantity)       AS total_items,
        COUNT(DISTINCT oi.seller_id) AS seller_count,
        p.payment_method,
        p.payment_status,
        COALESCE(oa.recipient_name, u.name) AS ship_name,
        COALESCE(oa.city, '')               AS ship_city,
        s.shipping_status,
        s.tracking_number
    FROM orders o
    JOIN users u             ON o.user_id    = u.id
    LEFT JOIN order_items oi ON o.id         = oi.order_id
    LEFT JOIN payments p     ON p.order_id   = o.id
    LEFT JOIN order_addresses oa ON oa.order_id = o.id
    LEFT JOIN shipments s    ON s.order_id   = o.id
    WHERE {$where_sql}
    GROUP BY o.id
    ORDER BY o.created_at DESC
";

$stmt = $conn->prepare($orders_sql);
if ($bind_types !== '') {
    $params = array_merge([$bind_types], $bind_values);
    $refs   = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// ── STATUS COUNTS ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
$stmt->execute();
$count_result = $stmt->get_result();
$stmt->close();

$counts = ['all' => 0];
foreach ($valid_statuses as $s) $counts[$s] = 0;
while ($crow = $count_result->fetch_assoc()) {
    if (isset($counts[$crow['status']])) {
        $counts[$crow['status']] = $crow['cnt'];
    }
    $counts['all'] += $crow['cnt'];
}

// ── REVENUE TOTALS ────────────────────────────────────────────────────────────
$rev_sql = "
    SELECT SUM(o.total_amount) AS total_revenue
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE {$where_sql}
";
$stmt = $conn->prepare($rev_sql);
if ($bind_types !== '') {
    $params = array_merge([$bind_types], $bind_values);
    $refs   = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}
$stmt->execute();
$total_revenue = $stmt->get_result()->fetch_assoc()['total_revenue'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT SUM(total_amount) AS t FROM orders WHERE status = 'Delivered'");
$stmt->execute();
$delivered_revenue = $stmt->get_result()->fetch_assoc()['t'] ?? 0;
$stmt->close();

// UPGRADE: pending refund count badge for admin awareness
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM refunds WHERE status = 'requested'");
$stmt->execute();
$pending_refunds = intval($stmt->get_result()->fetch_assoc()['cnt']);
$stmt->close();
?>

<?php include("../includes/header.php"); ?>

<style>
.orders-page { background:#f4f6fb; min-height:100vh; padding:36px 0 60px; }
.page-header-card { background:linear-gradient(135deg,#1a1a2e 0%,#16213e 60%,#0f3460 100%); border-radius:16px; padding:28px 32px; margin-bottom:28px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px; box-shadow:0 8px 32px rgba(15,52,96,.18); }
.page-header-card h1 { color:#fff; font-size:1.65rem; font-weight:700; margin:0; }
.page-header-card .subtitle { color:#a8b8d8; font-size:.88rem; margin-top:3px; }
.btn-back { background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.2) !important; border-radius:8px; padding:8px 18px; font-size:.85rem; font-weight:600; text-decoration:none; transition:background .15s; }
.btn-back:hover { background:rgba(255,255,255,.22); color:#fff; }
.summary-tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr)); gap:14px; margin-bottom:24px; }
.summary-tile { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 2px 10px rgba(0,0,0,.06); }
.summary-tile .tile-label { font-size:.75rem; color:#999; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.summary-tile .tile-value { font-size:1.45rem; font-weight:800; color:#1a1a2e; }
.summary-tile .tile-icon  { font-size:1.5rem; margin-bottom:4px; }
.tile-revenue .tile-value { color:#1a7a4a; }
.tile-refund .tile-value  { color:#c0392b; }
.stat-pills { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:22px; }
.stat-pill { display:flex; align-items:center; gap:7px; background:#fff; border-radius:50px; padding:7px 16px; font-size:.83rem; font-weight:600; color:#444; box-shadow:0 2px 8px rgba(0,0,0,.07); text-decoration:none; border:2px solid transparent; transition:all .17s; }
.stat-pill:hover { transform:translateY(-1px); }
.stat-pill.active { border-color:currentColor; }
.stat-pill .dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
.stat-pill .count { background:#f0f0f0; border-radius:50px; padding:1px 8px; font-size:.75rem; color:#555; font-weight:700; }
.pill-all        { color:#495057; } .pill-all .dot        { background:#6c757d; } .pill-all.active        { border-color:#6c757d; background:#f0f0f0; }
.pill-pending    { color:#856404; } .pill-pending .dot    { background:#ffc107; } .pill-pending.active    { border-color:#ffc107; background:#fff8e1; }
.pill-confirmed  { color:#0a3d62; } .pill-confirmed .dot  { background:#2980b9; } .pill-confirmed.active  { border-color:#2980b9; background:#ebf5fb; }
.pill-processing { color:#084298; } .pill-processing .dot { background:#0d6efd; } .pill-processing.active { border-color:#0d6efd; background:#e8f0ff; }
.pill-ready      { color:#5f4b00; } .pill-ready .dot      { background:#f39c12; } .pill-ready.active      { border-color:#f39c12; background:#fef9e7; }
.pill-shipped    { color:#0f5132; } .pill-shipped .dot    { background:#20c997; } .pill-shipped.active    { border-color:#20c997; background:#e6faf5; }
.pill-delivered  { color:#1a7a4a; } .pill-delivered .dot  { background:#28a745; } .pill-delivered.active  { border-color:#28a745; background:#e9f7ef; }
.pill-cancelled  { color:#842029; } .pill-cancelled .dot  { background:#dc3545; } .pill-cancelled.active  { border-color:#dc3545; background:#fdecea; }
.pill-refunded   { color:#5a2d82; } .pill-refunded .dot   { background:#8e44ad; } .pill-refunded.active   { border-color:#8e44ad; background:#f5eef8; }
.pill-disputed   { color:#7b2d00; } .pill-disputed .dot   { background:#e67e22; } .pill-disputed.active   { border-color:#e67e22; background:#fef5ec; }
.filter-card { background:#fff; border-radius:14px; padding:18px 22px; box-shadow:0 2px 12px rgba(0,0,0,.07); margin-bottom:24px; display:flex; gap:14px; flex-wrap:wrap; align-items:center; }
.filter-card .search-wrap { flex:1; min-width:200px; position:relative; }
.filter-card .search-wrap input { padding-left:38px; border-radius:8px; border:1.5px solid #dee2e6; height:40px; font-size:.9rem; width:100%; outline:none; }
.filter-card .search-wrap input:focus { border-color:#0f3460; }
.filter-card .search-wrap .search-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#aaa; font-size:.95rem; }
.filter-card select { border-radius:8px; border:1.5px solid #dee2e6; height:40px; font-size:.88rem; padding:0 12px; outline:none; min-width:150px; }
.btn-filter { background:#0f3460; color:#fff; border:none; border-radius:8px; height:40px; padding:0 22px; font-weight:600; font-size:.88rem; cursor:pointer; }
.btn-filter:hover { background:#1a5276; }
.btn-reset { background:#f0f0f0; color:#555; border:none; border-radius:8px; height:40px; padding:0 16px; font-weight:600; font-size:.85rem; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
.btn-reset:hover { background:#e0e0e0; }
.active-filter-badge { display:inline-flex; align-items:center; gap:5px; background:#e8f0fe; color:#1a5276; border-radius:50px; padding:4px 12px; font-size:.78rem; font-weight:600; }
.table-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); overflow:hidden; }
.table-card .table { margin-bottom:0; }
.table-card .table thead th { background:#1a1a2e; color:#c8d4e8; font-size:.78rem; text-transform:uppercase; letter-spacing:.7px; padding:13px 16px; border:none; font-weight:600; white-space:nowrap; }
.table-card .table tbody tr { border-bottom:1px solid #f2f4f8; transition:background .12s; }
.table-card .table tbody tr:hover { background:#f8f9ff; }
.table-card .table tbody td { padding:13px 16px; vertical-align:middle; font-size:.88rem; border:none; }
.order-id { font-weight:800; color:#0f3460; font-size:.95rem; }
.customer-name { font-weight:700; color:#1a1a2e; }
.customer-email { color:#888; font-size:.78rem; margin-top:1px; }
.price-tag { font-weight:700; color:#1a7a4a; font-size:.95rem; }
.date-tag { color:#777; font-size:.82rem; }
.pay-badge { display:inline-block; font-size:.72rem; font-weight:700; border-radius:5px; padding:2px 8px; margin-top:3px; }
.pay-paid    { background:#d1fae5; color:#065f46; }
.pay-pending { background:#fef9c3; color:#854d0e; }
.pay-failed  { background:#fee2e2; color:#991b1b; }
.ship-badge  { display:inline-block; font-size:.72rem; font-weight:600; border-radius:5px; padding:2px 8px; background:#e0f2fe; color:#0369a1; margin-top:2px; }

/* Status select — colours for all 9 statuses */
.status-select { border-radius:7px; border:1.5px solid #dee2e6; font-size:.82rem; font-weight:600; padding:5px 10px; outline:none; cursor:pointer; min-width:140px; }
.status-select.status-pending       { border-color:#ffc107; background:#fffdf0; color:#856404; }
.status-select.status-confirmed     { border-color:#2980b9; background:#ebf5fb; color:#0a3d62; }
.status-select.status-processing    { border-color:#0d6efd; background:#f0f4ff; color:#084298; }
.status-select.status-ready_to_ship { border-color:#f39c12; background:#fef9e7; color:#5f4b00; }
.status-select.status-shipped       { border-color:#20c997; background:#f0faf7; color:#0f5132; }
.status-select.status-delivered     { border-color:#28a745; background:#f0faf3; color:#1a7a4a; }
.status-select.status-cancelled     { border-color:#dc3545; background:#fff0f0; color:#842029; }
.status-select.status-refunded      { border-color:#8e44ad; background:#f5eef8; color:#5a2d82; }
.status-select.status-disputed      { border-color:#e67e22; background:#fef5ec; color:#7b2d00; }

.coupon-badge { background:#e9f7ef; color:#1a7a4a; border:1px solid #b8dfc8; border-radius:6px; padding:3px 10px; font-size:.78rem; font-weight:700; }
.btn-view { background:#e8f0fe; color:#1a5276; border:1.5px solid #aac4e8; border-radius:7px; padding:5px 14px; font-size:.8rem; font-weight:700; text-decoration:none; transition:all .15s; display:inline-flex; align-items:center; gap:4px; }
.btn-view:hover { background:#0f3460; color:#fff; border-color:#0f3460; }
.empty-state { padding:60px 20px; text-align:center; }
.empty-state .icon { font-size:3.5rem; margin-bottom:14px; color:#ccc; }
.empty-state h5 { color:#888; font-weight:600; }
.results-info { font-size:.85rem; color:#888; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; padding:14px 18px 0; }
.results-info strong { color:#333; }
.revenue-summary { font-size:.85rem; font-weight:700; color:#1a7a4a; }
.alert-custom { border-radius:10px; font-size:.9rem; font-weight:500; padding:13px 18px; margin-bottom:18px; }

/* Note modal */
.note-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center; }
.note-modal.show { display:flex; }
.note-modal-box { background:#fff; border-radius:14px; padding:28px; width:420px; max-width:95vw; box-shadow:0 12px 48px rgba(0,0,0,.2); }
.note-modal-box h5 { font-weight:700; margin-bottom:16px; }
.note-modal-box textarea { width:100%; border:1.5px solid #dee2e6; border-radius:8px; padding:10px 12px; font-size:.9rem; resize:vertical; min-height:90px; outline:none; font-family:inherit; }
.note-modal-box textarea:focus { border-color:#0f3460; }
.note-modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:14px; }
</style>

<div class="orders-page">
<div class="container">

    <div class="page-header-card">
        <div>
            <h1>🛍 Manage Orders</h1>
            <div class="subtitle">Admin Panel — All customer orders</div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <!-- UPGRADE: pending refund alert badge -->
            <?php if ($pending_refunds > 0): ?>
            <a href="manage_refunds.php" class="btn btn-sm btn-danger fw-bold" style="border-radius:8px;">
                ⚠ <?= $pending_refunds ?> Refund<?= $pending_refunds > 1 ? 's' : '' ?> Pending
            </a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn-back">← Dashboard</a>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-custom shadow-sm">✅ <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-custom shadow-sm">❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Summary tiles -->
    <div class="summary-tiles">
        <div class="summary-tile"><div class="tile-icon">📦</div><div class="tile-label">Total Orders</div><div class="tile-value"><?= intval($counts['all']) ?></div></div>
        <div class="summary-tile"><div class="tile-icon">⏳</div><div class="tile-label">Pending</div><div class="tile-value" style="color:#856404"><?= intval($counts['Pending']) ?></div></div>
        <div class="summary-tile"><div class="tile-icon">🚚</div><div class="tile-label">Shipped</div><div class="tile-value" style="color:#0f5132"><?= intval($counts['Shipped']) ?></div></div>
        <div class="summary-tile"><div class="tile-icon">✅</div><div class="tile-label">Delivered</div><div class="tile-value" style="color:#1a7a4a"><?= intval($counts['Delivered']) ?></div></div>
        <div class="summary-tile tile-revenue"><div class="tile-icon">💰</div><div class="tile-label">Delivered Revenue</div><div class="tile-value">৳ <?= number_format($delivered_revenue) ?></div></div>
        <!-- UPGRADE: refund tile -->
        <div class="summary-tile tile-refund"><div class="tile-icon">↩</div><div class="tile-label">Refund Requests</div><div class="tile-value"><?= $pending_refunds ?></div></div>
    </div>

    <!-- Stat pills — UPGRADE: now includes all 9 statuses -->
    <?php
    $pill_params = function($status) use ($filter_search, $filter_date) {
        $p = [];
        if ($status)        $p[] = 'status=' . urlencode($status);
        if ($filter_search) $p[] = 'search=' . urlencode($filter_search);
        if ($filter_date)   $p[] = 'date='   . urlencode($filter_date);
        return 'manage_orders.php' . ($p ? '?' . implode('&', $p) : '');
    };
    $pills = [
        ''              => ['All',           'pill-all',        $counts['all']],
        'Pending'       => ['Pending',        'pill-pending',    $counts['Pending']],
        'Confirmed'     => ['Confirmed',      'pill-confirmed',  $counts['Confirmed']],
        'Processing'    => ['Processing',     'pill-processing', $counts['Processing']],
        'Ready_to_Ship' => ['Ready to Ship',  'pill-ready',      $counts['Ready_to_Ship']],
        'Shipped'       => ['Shipped',        'pill-shipped',    $counts['Shipped']],
        'Delivered'     => ['Delivered',      'pill-delivered',  $counts['Delivered']],
        'Cancelled'     => ['Cancelled',      'pill-cancelled',  $counts['Cancelled']],
        'Refunded'      => ['Refunded',       'pill-refunded',   $counts['Refunded']],
        'Disputed'      => ['Disputed',       'pill-disputed',   $counts['Disputed']],
    ];
    ?>
    <div class="stat-pills">
        <?php foreach ($pills as $val => [$label, $cls, $cnt]): ?>
        <a href="<?= $pill_params($val) ?>"
           class="stat-pill <?= $cls ?> <?= $filter_status === $val ? 'active' : '' ?>">
            <span class="dot"></span>
            <?= $label ?>
            <span class="count"><?= intval($cnt) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="manage_orders.php" class="filter-card">
        <div class="search-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" name="search" placeholder="Search by name, email or order ID..."
                   value="<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach ($valid_statuses as $s): ?>
                <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $filter_status === $s ? 'selected' : '' ?>>
                    <?= htmlspecialchars(str_replace('_', ' ', $s), ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="date">
            <option value="">All Time</option>
            <option value="today" <?= $filter_date === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="week"  <?= $filter_date === 'week'  ? 'selected' : '' ?>>Last 7 Days</option>
            <option value="month" <?= $filter_date === 'month' ? 'selected' : '' ?>>Last 30 Days</option>
        </select>
        <button type="submit" class="btn-filter">Apply Filters</button>
        <?php if ($filter_search || $filter_status || $filter_date): ?>
            <a href="manage_orders.php" class="btn-reset">✕ Reset</a>
        <?php endif; ?>
    </form>

    <?php if ($filter_search || $filter_status || $filter_date): ?>
    <div class="mb-3 d-flex flex-wrap gap-2">
        <?php if ($filter_search): ?><span class="active-filter-badge">🔍 "<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>"</span><?php endif; ?>
        <?php if ($filter_status): ?><span class="active-filter-badge">🏷 <?= htmlspecialchars(str_replace('_',' ',$filter_status), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        <?php if ($filter_date):
            $date_labels = ['today' => 'Today', 'week' => 'Last 7 Days', 'month' => 'Last 30 Days']; ?>
            <span class="active-filter-badge">📅 <?= $date_labels[$filter_date] ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Orders table -->
    <div class="table-card">
        <?php $total = $result->num_rows; ?>
        <div class="results-info">
            <span>Showing <strong><?= $total ?></strong> order<?= $total != 1 ? 's' : '' ?></span>
            <?php if ($total > 0): ?>
                <span class="revenue-summary">💰 Subtotal: ৳ <?= number_format($total_revenue) ?></span>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Payment</th>   <!-- UPGRADE: payment status column -->
                    <th>Shipment</th>  <!-- UPGRADE: shipment status column -->
                    <th>Coupon</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($total > 0): ?>
                <?php while ($order = $result->fetch_assoc()):
                    $st           = $order['status'];
                    $select_class = 'status-' . strtolower(str_replace('_', '_', $st));
                    $pay_cls      = match($order['payment_status'] ?? '') {
                        'Paid'    => 'pay-paid',
                        'Failed'  => 'pay-failed',
                        default   => 'pay-pending'
                    };
                ?>
                <tr>
                    <td>
                        <span class="order-id">#<?= intval($order['id']) ?></span>
                        <?php if ($order['seller_count'] > 1): ?>
                            <div style="font-size:.7rem;color:#888;margin-top:2px">
                                <?= intval($order['seller_count']) ?> sellers
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="customer-name"><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="customer-email"><?= htmlspecialchars($order['customer_email'], ENT_QUOTES, 'UTF-8') ?></div>
                        <!-- UPGRADE: shows delivery city from order_addresses table -->
                        <?php if (!empty($order['ship_city'])): ?>
                            <div style="font-size:.72rem;color:#aaa;margin-top:1px">
                                📍 <?= htmlspecialchars($order['ship_city'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>📦 <?= intval($order['item_count']) ?> item<?= $order['item_count'] != 1 ? 's' : '' ?></td>
                    <td>
                        <span class="price-tag">৳ <?= number_format($order['total_amount']) ?></span>
                        <?php if ($order['discount_amount'] > 0): ?>
                            <div style="font-size:.72rem;color:#16a34a;margin-top:2px">
                                −৳ <?= number_format($order['discount_amount']) ?> off
                            </div>
                        <?php endif; ?>
                    </td>
                    <!-- UPGRADE: payment status badge -->
                    <td>
                        <div style="font-size:.78rem;color:#555;"><?= htmlspecialchars($order['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                        <span class="pay-badge <?= $pay_cls ?>">
                            <?= htmlspecialchars($order['payment_status'] ?? 'Pending', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <!-- UPGRADE: shipment status badge -->
                    <td>
                        <?php if (!empty($order['shipping_status'])): ?>
                            <span class="ship-badge">
                                <?= htmlspecialchars(ucfirst(str_replace('_',' ',$order['shipping_status'])), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if (!empty($order['tracking_number'])): ?>
                                <div style="font-size:.7rem;color:#888;margin-top:2px">
                                    <?= htmlspecialchars($order['tracking_number'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#ccc;font-size:.78rem">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($order['coupon_code'])): ?>
                            <span class="coupon-badge">🏷 <?= htmlspecialchars($order['coupon_code'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            <span style="color:#ccc">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <!-- UPGRADE: status select now triggers a note modal before submitting
                             so admin can optionally add a reason (e.g. cancellation reason).
                             The note is stored in order_status_logs via sp_log_order_status(). -->
                        <div style="font-size:.82rem;font-weight:600;margin-bottom:4px;color:var(--muted,#888)">
                            <?= htmlspecialchars(str_replace('_',' ',$st), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <button type="button"
                                class="btn-view"
                                style="font-size:.75rem;padding:4px 10px;"
                                onclick="openNoteModal(<?= intval($order['id']) ?>, '<?= htmlspecialchars($st, ENT_QUOTES) ?>')">
                            ✏ Change Status
                        </button>
                    </td>
                    <td>
                        <span class="date-tag"><?= date('d M, Y', strtotime($order['created_at'])) ?></span>
                        <div style="font-size:.72rem;color:#bbb;margin-top:1px"><?= date('h:i A', strtotime($order['created_at'])) ?></div>
                    </td>
                    <td>
                        <a href="order_details.php?id=<?= intval($order['id']) ?>" class="btn-view">👁 View</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="10">
                    <div class="empty-state">
                        <div class="icon">🛍</div>
                        <h5><?= ($filter_search || $filter_status || $filter_date) ? 'No orders match your filters' : 'No orders yet' ?></h5>
                        <p><?= ($filter_search || $filter_status || $filter_date) ? 'Try adjusting or resetting your filters.' : 'Orders will appear here once customers place them.' ?></p>
                        <?php if ($filter_search || $filter_status || $filter_date): ?>
                            <a href="manage_orders.php" class="btn btn-sm btn-outline-secondary mt-2">Reset Filters</a>
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

<!-- UPGRADE: Status change modal — collects new status + optional note
     before posting. The note is passed to sp_log_order_status() and stored
     in order_status_logs for the audit trail. Also stores cancellation_reason. -->
<div class="note-modal" id="noteModal">
    <div class="note-modal-box">
        <h5>✏ Change Order Status</h5>
        <form method="POST" action="manage_orders.php" id="noteForm">
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="order_id" id="modalOrderId">

            <div style="margin-bottom:12px;">
                <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:6px;">
                    New Status
                </label>
                <select name="status" id="modalStatus" class="status-select" style="width:100%;min-width:unset;">
                    <?php foreach ($valid_statuses as $s): ?>
                        <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>">
                            <?= htmlspecialchars(str_replace('_',' ',$s), ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:6px;">
                Note <span style="color:#aaa;font-weight:400">(optional — stored in audit log)</span>
            </label>
            <textarea name="status_note" id="modalNote"
                      placeholder="e.g. Cancelled by customer request, Shipped via Pathao #TRK12345…"></textarea>

            <div class="note-modal-actions">
                <button type="button" class="btn-reset" onclick="closeNoteModal()">Cancel</button>
                <button type="submit" class="btn-filter" style="border-radius:8px;height:38px;">
                    Confirm Update
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// UPGRADE: Modal helpers for status change with optional note
function openNoteModal(orderId, currentStatus) {
    document.getElementById('modalOrderId').value = orderId;
    const sel = document.getElementById('modalStatus');
    // Pre-select current status
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === currentStatus) { sel.selectedIndex = i; break; }
    }
    document.getElementById('modalNote').value = '';
    document.getElementById('noteModal').classList.add('show');
}
function closeNoteModal() {
    document.getElementById('noteModal').classList.remove('show');
}
// Close on backdrop click
document.getElementById('noteModal').addEventListener('click', function(e) {
    if (e.target === this) closeNoteModal();
});
// Close on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNoteModal(); });
</script>

<?php include("../includes/footer.php"); ?>