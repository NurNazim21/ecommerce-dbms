<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// ==================== UPDATE ORDER STATUS ====================
if (isset($_POST['update_status'])) {
    $order_id   = intval($_POST['order_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['status']);
    $conn->query("UPDATE orders SET status = '$new_status' WHERE id = $order_id");
    $success = "Order status updated!";
}

// ==================== FILTERS ====================
$filter_status = isset($_GET['status']) ? mysqli_real_escape_string($conn, trim($_GET['status'])) : '';
$filter_search = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
$filter_date   = isset($_GET['date'])   ? $_GET['date'] : '';

$valid_statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];

$where_clauses = ["1=1"];

if (!empty($filter_status) && in_array($filter_status, $valid_statuses)) {
    $where_clauses[] = "o.status = '$filter_status'";
}

if (!empty($filter_search)) {
    $search_id       = is_numeric($filter_search) ? intval($filter_search) : 0;
    $where_clauses[] = "(u.name LIKE '%$filter_search%' OR u.email LIKE '%$filter_search%'"
                     . ($search_id > 0 ? " OR o.id = $search_id" : "") . ")";
}

if (!empty($filter_date)) {
    switch ($filter_date) {
        case 'today':
            $where_clauses[] = "DATE(o.created_at) = CURDATE()";
            break;
        case 'week':
            $where_clauses[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $where_clauses[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
    }
}

$where_sql = implode(' AND ', $where_clauses);

// ==================== FETCH ORDERS ====================
$result = $conn->query("
    SELECT o.id, o.total_amount, o.coupon_code, o.status, o.created_at,
           u.name as customer_name, u.email,
           COUNT(oi.id) as item_count,
           SUM(oi.quantity) as total_items
    FROM orders o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE $where_sql
    GROUP BY o.id
    ORDER BY o.created_at DESC
");

// ==================== STATUS COUNTS (for stat pills) ====================
$counts = ['all' => 0, 'Pending' => 0, 'Processing' => 0, 'Shipped' => 0, 'Delivered' => 0, 'Cancelled' => 0];
$count_result = $conn->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
while ($crow = $count_result->fetch_assoc()) {
    if (isset($counts[$crow['status']])) {
        $counts[$crow['status']] = $crow['cnt'];
    }
    $counts['all'] += $crow['cnt'];
}

// ==================== REVENUE TOTAL (filtered) ====================
$revenue_result = $conn->query("
    SELECT SUM(o.total_amount) as total_revenue
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE $where_sql
");
$total_revenue = $revenue_result->fetch_assoc()['total_revenue'] ?? 0;
?>

<?php include("../includes/header.php"); ?>

<style>
/* ===== PAGE WRAPPER ===== */
.orders-page { background: #f4f6fb; min-height: 100vh; padding: 36px 0 60px; }

/* ===== PAGE HEADER ===== */
.page-header-card {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
    border-radius: 16px;
    padding: 28px 32px;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    box-shadow: 0 8px 32px rgba(15,52,96,0.18);
}
.page-header-card h1 {
    color: #fff;
    font-size: 1.65rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: 0.3px;
}
.page-header-card .subtitle { color: #a8b8d8; font-size: 0.88rem; margin-top: 3px; }
.header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-back {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.2) !important;
    border-radius: 8px;
    padding: 8px 18px;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.15s;
}
.btn-back:hover { background: rgba(255,255,255,0.22); color: #fff; }

/* ===== SUMMARY TILES ===== */
.summary-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.summary-tile {
    background: #fff;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.summary-tile .tile-label { font-size: 0.75rem; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.summary-tile .tile-value { font-size: 1.45rem; font-weight: 800; color: #1a1a2e; }
.summary-tile .tile-icon  { font-size: 1.5rem; margin-bottom: 4px; }
.tile-revenue .tile-value { color: #1a7a4a; }

/* ===== STAT PILLS ===== */
.stat-pills { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 22px; }
.stat-pill {
    display: flex; align-items: center; gap: 7px;
    background: #fff;
    border-radius: 50px;
    padding: 7px 16px;
    font-size: 0.83rem;
    font-weight: 600;
    color: #444;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    cursor: pointer;
    text-decoration: none;
    border: 2px solid transparent;
    transition: all 0.17s;
}
.stat-pill:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.12); }
.stat-pill.active { border-color: currentColor; }
.stat-pill .dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.stat-pill .count {
    background: #f0f0f0;
    border-radius: 50px;
    padding: 1px 8px;
    font-size: 0.75rem;
    color: #555;
    font-weight: 700;
}
/* Pill color themes */
.pill-all        { color: #495057; }
.pill-all .dot   { background: #6c757d; }
.pill-all.active { border-color: #6c757d; background: #f0f0f0; }

.pill-pending        { color: #856404; }
.pill-pending .dot   { background: #ffc107; }
.pill-pending.active { border-color: #ffc107; background: #fff8e1; }

.pill-processing        { color: #084298; }
.pill-processing .dot   { background: #0d6efd; }
.pill-processing.active { border-color: #0d6efd; background: #e8f0ff; }

.pill-shipped        { color: #0f5132; }
.pill-shipped .dot   { background: #20c997; }
.pill-shipped.active { border-color: #20c997; background: #e6faf5; }

.pill-delivered        { color: #1a7a4a; }
.pill-delivered .dot   { background: #28a745; }
.pill-delivered.active { border-color: #28a745; background: #e9f7ef; }

.pill-cancelled        { color: #842029; }
.pill-cancelled .dot   { background: #dc3545; }
.pill-cancelled.active { border-color: #dc3545; background: #fdecea; }

/* ===== FILTER BAR ===== */
.filter-card {
    background: #fff;
    border-radius: 14px;
    padding: 18px 22px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.07);
    margin-bottom: 24px;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    align-items: center;
}
.filter-card .search-wrap { flex: 1; min-width: 200px; position: relative; }
.filter-card .search-wrap input {
    padding-left: 38px;
    border-radius: 8px;
    border: 1.5px solid #dee2e6;
    height: 40px;
    font-size: 0.9rem;
    width: 100%;
    outline: none;
    transition: border-color 0.15s;
}
.filter-card .search-wrap input:focus { border-color: #0f3460; }
.filter-card .search-wrap .search-icon {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: #aaa; font-size: 0.95rem;
}
.filter-card select {
    border-radius: 8px;
    border: 1.5px solid #dee2e6;
    height: 40px;
    font-size: 0.88rem;
    padding: 0 12px;
    outline: none;
    min-width: 150px;
    transition: border-color 0.15s;
    background: #fff;
}
.filter-card select:focus { border-color: #0f3460; }
.btn-filter {
    background: #0f3460;
    color: #fff;
    border: none;
    border-radius: 8px;
    height: 40px;
    padding: 0 22px;
    font-weight: 600;
    font-size: 0.88rem;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-filter:hover { background: #1a5276; }
.btn-reset {
    background: #f0f0f0;
    color: #555;
    border: none;
    border-radius: 8px;
    height: 40px;
    padding: 0 16px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: background 0.15s;
}
.btn-reset:hover { background: #e0e0e0; color: #333; }
.active-filter-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: #e8f0fe; color: #1a5276; border-radius: 50px;
    padding: 4px 12px; font-size: 0.78rem; font-weight: 600;
}

/* ===== TABLE CARD ===== */
.table-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.07);
    overflow: hidden;
}
.table-card .table { margin-bottom: 0; }
.table-card .table thead th {
    background: #1a1a2e;
    color: #c8d4e8;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    padding: 13px 16px;
    border: none;
    font-weight: 600;
    white-space: nowrap;
}
.table-card .table tbody tr {
    border-bottom: 1px solid #f2f4f8;
    transition: background 0.12s;
}
.table-card .table tbody tr:hover { background: #f8f9ff; }
.table-card .table tbody td {
    padding: 13px 16px;
    vertical-align: middle;
    font-size: 0.88rem;
    border: none;
}
.order-id    { font-weight: 800; color: #0f3460; font-size: 0.95rem; }
.customer-name  { font-weight: 700; color: #1a1a2e; }
.customer-email { color: #888; font-size: 0.78rem; margin-top: 1px; }
.price-tag   { font-weight: 700; color: #1a7a4a; font-size: 0.95rem; }
.items-tag   { color: #555; font-size: 0.85rem; }
.date-tag    { color: #777; font-size: 0.82rem; }

/* Status select styling */
.status-select {
    border-radius: 7px;
    border: 1.5px solid #dee2e6;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 5px 10px;
    outline: none;
    cursor: pointer;
    transition: border-color 0.15s;
    min-width: 130px;
}
.status-select:focus { border-color: #0f3460; }
.status-select.status-pending    { border-color: #ffc107; background: #fffdf0; color: #856404; }
.status-select.status-processing { border-color: #0d6efd; background: #f0f4ff; color: #084298; }
.status-select.status-shipped    { border-color: #20c997; background: #f0faf7; color: #0f5132; }
.status-select.status-delivered  { border-color: #28a745; background: #f0faf3; color: #1a7a4a; }
.status-select.status-cancelled  { border-color: #dc3545; background: #fff0f0; color: #842029; }

/* Coupon badge */
.coupon-badge {
    background: #e9f7ef;
    color: #1a7a4a;
    border: 1px solid #b8dfc8;
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.3px;
}

/* Action button */
.btn-view {
    background: #e8f0fe;
    color: #1a5276;
    border: 1.5px solid #aac4e8;
    border-radius: 7px;
    padding: 5px 14px;
    font-size: 0.8rem;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.15s;
    white-space: nowrap;
}
.btn-view:hover { background: #0f3460; color: #fff; border-color: #0f3460; }

/* Empty state */
.empty-state { padding: 60px 20px; text-align: center; }
.empty-state .icon { font-size: 3.5rem; margin-bottom: 14px; color: #ccc; }
.empty-state h5 { color: #888; font-weight: 600; }
.empty-state p  { color: #aaa; font-size: 0.88rem; }

/* Alerts */
.alert-custom {
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 500;
    padding: 13px 18px;
    margin-bottom: 18px;
}

/* Results info */
.results-info {
    font-size: 0.85rem;
    color: #888;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    padding: 14px 18px 0;
}
.results-info strong { color: #333; }
.revenue-summary {
    font-size: 0.85rem;
    font-weight: 700;
    color: #1a7a4a;
}
</style>

<div class="orders-page">
<div class="container">

    <!-- ===== PAGE HEADER ===== -->
    <div class="page-header-card">
        <div>
            <h1>🛍 Manage Orders</h1>
            <div class="subtitle">Admin Panel — All customer orders</div>
        </div>
        <div class="header-actions">
            <a href="dashboard.php" class="btn-back">← Dashboard</a>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-custom shadow-sm">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- ===== SUMMARY TILES ===== -->
    <div class="summary-tiles">
        <div class="summary-tile">
            <div class="tile-icon">📦</div>
            <div class="tile-label">Total Orders</div>
            <div class="tile-value"><?= $counts['all'] ?></div>
        </div>
        <div class="summary-tile">
            <div class="tile-icon">⏳</div>
            <div class="tile-label">Pending</div>
            <div class="tile-value" style="color:#856404;"><?= $counts['Pending'] ?></div>
        </div>
        <div class="summary-tile">
            <div class="tile-icon">🚚</div>
            <div class="tile-label">Shipped</div>
            <div class="tile-value" style="color:#0f5132;"><?= $counts['Shipped'] ?></div>
        </div>
        <div class="summary-tile">
            <div class="tile-icon">✅</div>
            <div class="tile-label">Delivered</div>
            <div class="tile-value" style="color:#1a7a4a;"><?= $counts['Delivered'] ?></div>
        </div>
        <div class="summary-tile tile-revenue">
            <div class="tile-icon">💰</div>
            <div class="tile-label">Total Revenue</div>
            <div class="tile-value">৳ <?= number_format(array_sum(
                array_column(
                    iterator_to_array((function() use ($conn) {
                        $r = $conn->query("SELECT SUM(total_amount) as t FROM orders WHERE status='Delivered'");
                        yield $r->fetch_assoc();
                    })()),
                    't'
                )
            )) ?></div>
        </div>
    </div>

    <!-- ===== STATUS STAT PILLS ===== -->
    <?php
        $pill_params = function($status) use ($filter_search, $filter_date) {
            $p = [];
            if ($status)       $p[] = 'status=' . urlencode($status);
            if ($filter_search) $p[] = 'search=' . urlencode($filter_search);
            if ($filter_date)  $p[] = 'date='   . urlencode($filter_date);
            return 'manage_orders.php' . ($p ? '?' . implode('&', $p) : '');
        };
    ?>
    <div class="stat-pills">
        <a href="<?= $pill_params('') ?>"
           class="stat-pill pill-all <?= !$filter_status ? 'active' : '' ?>">
            <span class="dot"></span> All Orders <span class="count"><?= $counts['all'] ?></span>
        </a>
        <a href="<?= $pill_params('Pending') ?>"
           class="stat-pill pill-pending <?= $filter_status === 'Pending' ? 'active' : '' ?>">
            <span class="dot"></span> Pending <span class="count"><?= $counts['Pending'] ?></span>
        </a>
        <a href="<?= $pill_params('Processing') ?>"
           class="stat-pill pill-processing <?= $filter_status === 'Processing' ? 'active' : '' ?>">
            <span class="dot"></span> Processing <span class="count"><?= $counts['Processing'] ?></span>
        </a>
        <a href="<?= $pill_params('Shipped') ?>"
           class="stat-pill pill-shipped <?= $filter_status === 'Shipped' ? 'active' : '' ?>">
            <span class="dot"></span> Shipped <span class="count"><?= $counts['Shipped'] ?></span>
        </a>
        <a href="<?= $pill_params('Delivered') ?>"
           class="stat-pill pill-delivered <?= $filter_status === 'Delivered' ? 'active' : '' ?>">
            <span class="dot"></span> Delivered <span class="count"><?= $counts['Delivered'] ?></span>
        </a>
        <a href="<?= $pill_params('Cancelled') ?>"
           class="stat-pill pill-cancelled <?= $filter_status === 'Cancelled' ? 'active' : '' ?>">
            <span class="dot"></span> Cancelled <span class="count"><?= $counts['Cancelled'] ?></span>
        </a>
    </div>

    <!-- ===== FILTER BAR ===== -->
    <form method="GET" action="manage_orders.php" class="filter-card">

        <!-- Search -->
        <div class="search-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" name="search"
                   placeholder="Search by name, email or order ID..."
                   value="<?= htmlspecialchars($filter_search) ?>">
        </div>

        <!-- Status Filter -->
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach ($valid_statuses as $s): ?>
                <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>>
                    <?= $s ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Date Filter -->
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

    <!-- Active filter indicators -->
    <?php if ($filter_search || $filter_status || $filter_date): ?>
        <div class="mb-3 d-flex flex-wrap gap-2">
            <?php if ($filter_search): ?>
                <span class="active-filter-badge">🔍 "<?= htmlspecialchars($filter_search) ?>"</span>
            <?php endif; ?>
            <?php if ($filter_status): ?>
                <span class="active-filter-badge">🏷 <?= $filter_status ?></span>
            <?php endif; ?>
            <?php if ($filter_date): ?>
                <?php $date_labels = ['today' => 'Today', 'week' => 'Last 7 Days', 'month' => 'Last 30 Days']; ?>
                <span class="active-filter-badge">📅 <?= $date_labels[$filter_date] ?? '' ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ===== ORDERS TABLE ===== -->
    <div class="table-card">

        <?php $total = $result->num_rows; ?>
        <div class="results-info">
            <span>
                Showing <strong><?= $total ?></strong> order<?= $total != 1 ? 's' : '' ?>
                <?= ($filter_search || $filter_status || $filter_date) ? '— filtered results' : '' ?>
            </span>
            <?php if ($total > 0): ?>
                <span class="revenue-summary">
                    💰 Subtotal: ৳ <?= number_format($total_revenue) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Total Amount</th>
                    <th>Coupon</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>

                <?php if ($total > 0): ?>

                    <?php while ($order = $result->fetch_assoc()): ?>
                        <?php
                            $st           = $order['status'];
                            $select_class = 'status-' . strtolower($st);
                        ?>
                        <tr>

                            <td>
                                <span class="order-id">#<?= $order['id'] ?></span>
                            </td>

                            <td>
                                <div class="customer-name"><?= htmlspecialchars($order['customer_name']) ?></div>
                                <div class="customer-email"><?= htmlspecialchars($order['email']) ?></div>
                            </td>

                            <td>
                                <span class="items-tag">
                                    📦 <?= $order['item_count'] ?> item<?= $order['item_count'] != 1 ? 's' : '' ?>
                                </span>
                            </td>

                            <td>
                                <span class="price-tag">৳ <?= number_format($order['total_amount']) ?></span>
                            </td>

                            <td>
                                <?php if (!empty($order['coupon_code'])): ?>
                                    <span class="coupon-badge">🏷 <?= htmlspecialchars($order['coupon_code']) ?></span>
                                <?php else: ?>
                                    <span style="color:#ccc;">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <!-- Inline status update form — logic unchanged -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="order_id"      value="<?= $order['id'] ?>">
                                    <input type="hidden" name="update_status" value="1">
                                    <select name="status"
                                            class="status-select <?= $select_class ?>"
                                            onchange="this.form.submit()">
                                        <?php foreach ($valid_statuses as $s): ?>
                                            <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>>
                                                <?= $s ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>

                            <td>
                                <span class="date-tag">
                                    <?= date('d M, Y', strtotime($order['created_at'])) ?>
                                </span>
                                <div style="font-size:0.72rem; color:#bbb; margin-top:1px;">
                                    <?= date('h:i A', strtotime($order['created_at'])) ?>
                                </div>
                            </td>

                            <td>
                                <a href="order_details.php?id=<?= $order['id'] ?>" class="btn-view">
                                    👁 View
                                </a>
                            </td>

                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <div class="icon">🛍</div>
                                <h5>
                                    <?= ($filter_search || $filter_status || $filter_date)
                                        ? 'No orders match your filters'
                                        : 'No orders yet' ?>
                                </h5>
                                <p>
                                    <?= ($filter_search || $filter_status || $filter_date)
                                        ? 'Try adjusting or resetting your filters.'
                                        : 'Orders will appear here once customers place them.' ?>
                                </p>
                                <?php if ($filter_search || $filter_status || $filter_date): ?>
                                    <a href="manage_orders.php" class="btn btn-sm btn-outline-secondary mt-2">Reset Filters</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

            </tbody>
        </table>
        </div><!-- /table-responsive -->

    </div><!-- /table-card -->

</div><!-- /container -->
</div><!-- /orders-page -->

<?php include("../includes/footer.php"); ?>