<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// ── CORE METRICS ──────────────────────────────────────────────────────────────
$total_sales    = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS total FROM orders WHERE status NOT IN ('Cancelled','Refunded')")->fetch_assoc()['total'];
$total_orders   = $conn->query("SELECT COUNT(*) AS count FROM orders")->fetch_assoc()['count'];
$total_products = $conn->query("SELECT COUNT(*) AS count FROM products WHERE status = 'approved'")->fetch_assoc()['count'];
$total_users    = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role = 'user'")->fetch_assoc()['count'];
$total_sellers  = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role = 'seller' AND seller_status = 'approved'")->fetch_assoc()['count'];

// ── PENDING APPROVAL COUNTS (for badges) ─────────────────────────────────────
// Sellers: role=seller AND seller_status=pending
$pending_sellers = $conn->query("
    SELECT COUNT(*) AS count FROM users
    WHERE role = 'seller' AND seller_status = 'pending'
")->fetch_assoc()['count'];

// Category managers: role=category_manager AND seller_status=pending
// (category_manager_register.php sets seller_status='pending' on registration)
$pending_cms = $conn->query("
    SELECT COUNT(*) AS count FROM users
    WHERE role = 'category_manager' AND seller_status = 'pending'
")->fetch_assoc()['count'];

// Pending product approvals
$pending_products = $conn->query("
    SELECT COUNT(*) AS count FROM products WHERE status = 'pending'
")->fetch_assoc()['count'];

// ── ORDER STATUS BREAKDOWN ────────────────────────────────────────────────────
$order_statuses = $conn->query("
    SELECT status, COUNT(*) AS cnt
    FROM orders
    GROUP BY status
    ORDER BY FIELD(status,'Pending','Confirmed','Processing','Ready_to_Ship','Shipped','Delivered','Cancelled','Refunded','Disputed')
")->fetch_all(MYSQLI_ASSOC);

$status_counts = [];
foreach ($order_statuses as $row) {
    $status_counts[$row['status']] = $row['cnt'];
}

// ── TOP 5 SELLING PRODUCTS (uses order_items) ─────────────────────────────────
$top_products = $conn->query("
    SELECT p.name, p.brand,
           SUM(oi.quantity) AS total_sold,
           SUM(oi.item_subtotal) AS revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    GROUP BY p.id
    ORDER BY total_sold DESC
    LIMIT 5
");

// ── SALES BY CATEGORY ─────────────────────────────────────────────────────────
$sales_by_category = $conn->query("
    SELECT c.name AS category,
           COUNT(oi.id) AS items_sold,
           SUM(oi.item_subtotal) AS revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY revenue DESC
    LIMIT 5
");

// ── LOW STOCK PRODUCTS (uses v_low_stock_products view) ───────────────────────
$low_stock = $conn->query("SELECT * FROM v_low_stock_products LIMIT 5");

// ── RECENT ORDERS (uses v_order_summary view) ─────────────────────────────────
$recent_orders = $conn->query("
    SELECT * FROM v_order_summary
    ORDER BY ordered_at DESC
    LIMIT 8
");

// ── SELLER EARNINGS SUMMARY (uses v_seller_earnings view) ─────────────────────
$seller_earnings = $conn->query("
    SELECT * FROM v_seller_earnings
    ORDER BY gross_revenue DESC
    LIMIT 5
");

// ── REVENUE LAST 7 DAYS ───────────────────────────────────────────────────────
$revenue_7days = $conn->query("
    SELECT DATE(created_at) AS day, SUM(total_amount) AS daily_total
    FROM orders
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND status NOT IN ('Cancelled','Refunded')
    GROUP BY DATE(created_at)
    ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<?php include("../includes/header.php"); ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
.dash-page { background:#f3f4f6; min-height:100vh; padding:2rem 0 5rem; font-family:'DM Sans',sans-serif; }
.dash-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.75rem; flex-wrap:wrap; gap:.75rem; }
.dash-header h1 { font-size:1.6rem; font-weight:800; color:#0f0f0f; margin:0; }
.dash-header .sub { font-size:.85rem; color:#6b7280; margin-top:2px; }

/* Metric tiles */
.metric-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1rem; margin-bottom:1.75rem; }
.metric-tile { background:#fff; border-radius:12px; padding:1.25rem 1.4rem; box-shadow:0 1px 3px rgba(0,0,0,.08); display:flex; flex-direction:column; gap:.25rem; }
.metric-tile .m-label { font-size:.72rem; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.06em; }
.metric-tile .m-val   { font-size:1.5rem; font-weight:800; color:#0f0f0f; line-height:1.2; }
.metric-tile .m-icon  { font-size:1.4rem; margin-bottom:.2rem; }
.metric-tile.green .m-val  { color:#16a34a; }
.metric-tile.blue .m-val   { color:#1d4ed8; }
.metric-tile.amber .m-val  { color:#b45309; }
.metric-tile.purple .m-val { color:#7c3aed; }
.metric-tile.red .m-val    { color:#dc2626; }

/* Status pills row */
.status-pills { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.75rem; }
.st-pill { display:flex; align-items:center; gap:6px; background:#fff; border-radius:50px; padding:5px 14px; font-size:.81rem; font-weight:600; color:#444; box-shadow:0 1px 5px rgba(0,0,0,.07); text-decoration:none; }
.st-pill .cnt { border-radius:50px; padding:1px 7px; font-size:.72rem; font-weight:700; }
.st-pill.pending   { color:#854d0e; } .st-pill.pending .cnt   { background:#fef9c3; }
.st-pill.confirmed { color:#1e40af; } .st-pill.confirmed .cnt { background:#dbeafe; }
.st-pill.shipped   { color:#0369a1; } .st-pill.shipped .cnt   { background:#e0f2fe; }
.st-pill.delivered { color:#065f46; } .st-pill.delivered .cnt { background:#d1fae5; }
.st-pill.cancelled { color:#991b1b; } .st-pill.cancelled .cnt { background:#fee2e2; }

/* Cards */
.dash-card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:1.25rem; overflow:hidden; }
.dash-card-header { padding:.9rem 1.25rem; font-size:.95rem; font-weight:700; color:#0f0f0f; border-bottom:.5px solid #f2f4f8; display:flex; align-items:center; justify-content:space-between; }
.dash-card-header a { font-size:.78rem; font-weight:600; color:#6b7280; text-decoration:none; }
.dash-card-header a:hover { color:#0f0f0f; }
.dash-table { width:100%; border-collapse:collapse; }
.dash-table th { font-size:.72rem; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em; padding:.6rem 1.25rem; border-bottom:.5px solid #f2f4f8; text-align:left; white-space:nowrap; }
.dash-table td { padding:.75rem 1.25rem; border-bottom:.5px solid #f9fafb; font-size:.85rem; vertical-align:middle; }
.dash-table tr:last-child td { border-bottom:none; }
.dash-table tbody tr:hover { background:#f9fafb; }

/* Quick nav */
.quick-nav { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:1rem; margin-bottom:1.75rem; }
.qn-btn { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.5rem; background:#fff; border-radius:12px; padding:1.25rem .75rem; box-shadow:0 1px 3px rgba(0,0,0,.08); text-decoration:none; color:#0f0f0f; font-size:.83rem; font-weight:600; transition:box-shadow .14s,transform .14s; position:relative; text-align:center; }
.qn-btn:hover { box-shadow:0 4px 16px rgba(0,0,0,.12); transform:translateY(-2px); color:#0f0f0f; }
.qn-btn .qn-icon { font-size:1.6rem; }
.qn-badge { position:absolute; top:8px; right:8px; background:#dc2626; color:#fff; border-radius:50px; padding:1px 6px; font-size:.68rem; font-weight:700; }

/* Status badge */
.ob { display:inline-block; border-radius:5px; padding:2px 8px; font-size:.72rem; font-weight:700; }
.ob-pending    { background:#fef9c3; color:#854d0e; }
.ob-confirmed  { background:#dbeafe; color:#1e40af; }
.ob-processing { background:#e0f2fe; color:#0369a1; }
.ob-shipped    { background:#e0f2fe; color:#0369a1; }
.ob-delivered  { background:#d1fae5; color:#065f46; }
.ob-cancelled  { background:#fee2e2; color:#991b1b; }
.ob-refunded   { background:#f3e8ff; color:#6d28d9; }
.ob-disputed   { background:#fff7ed; color:#c2410c; }

/* Low stock */
.stock-bar-wrap { width:80px; height:6px; background:#f3f4f6; border-radius:3px; display:inline-block; }
.stock-bar { height:6px; border-radius:3px; }
</style>

<div class="dash-page">
<div class="container-xl">

    <div class="dash-header">
        <div>
            <h1>Admin Dashboard</h1>
            <div class="sub">Welcome back — here's what's happening today.</div>
        </div>
        <span style="font-size:.8rem;color:#9ca3af"><?= date('d M Y, h:i A') ?></span>
    </div>

    <!-- ── METRIC TILES ── -->
    <div class="metric-grid">
        <div class="metric-tile green">
            <div class="m-icon">💰</div>
            <div class="m-label">Total Revenue</div>
            <div class="m-val">৳ <?= number_format($total_sales) ?></div>
        </div>
        <div class="metric-tile blue">
            <div class="m-icon">📦</div>
            <div class="m-label">Total Orders</div>
            <div class="m-val"><?= number_format($total_orders) ?></div>
        </div>
        <div class="metric-tile amber">
            <div class="m-icon">🛍</div>
            <div class="m-label">Approved Products</div>
            <div class="m-val"><?= number_format($total_products) ?></div>
        </div>
        <div class="metric-tile">
            <div class="m-icon">👤</div>
            <div class="m-label">Buyers</div>
            <div class="m-val"><?= number_format($total_users) ?></div>
        </div>
        <div class="metric-tile purple">
            <div class="m-icon">🏪</div>
            <div class="m-label">Active Sellers</div>
            <div class="m-val"><?= number_format($total_sellers) ?></div>
        </div>
        <?php if ($pending_products > 0): ?>
        <div class="metric-tile red">
            <div class="m-icon">⏳</div>
            <div class="m-label">Pending Products</div>
            <div class="m-val"><?= number_format($pending_products) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── ORDER STATUS PILLS ── -->
    <div class="status-pills">
        <?php
        $pill_cfg = [
            'Pending'       => 'pending',
            'Confirmed'     => 'confirmed',
            'Processing'    => 'confirmed',
            'Ready_to_Ship' => 'confirmed',
            'Shipped'       => 'shipped',
            'Delivered'     => 'delivered',
            'Cancelled'     => 'cancelled',
            'Refunded'      => 'cancelled',
        ];
        foreach ($pill_cfg as $st => $cls):
            $c = $status_counts[$st] ?? 0;
            if ($c === 0) continue;
        ?>
        <a href="manage_orders.php?status=<?= urlencode($st) ?>" class="st-pill <?= $cls ?>">
            <?= str_replace('_',' ', $st) ?>
            <span class="cnt"><?= $c ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ── QUICK NAV ── -->
    <div class="quick-nav">
        <a href="manage_products.php" class="qn-btn">
            <span class="qn-icon">📦</span> Products
            <?php if ($pending_products > 0): ?><span class="qn-badge"><?= $pending_products ?></span><?php endif; ?>
        </a>
        <a href="manage_orders.php" class="qn-btn">
            <span class="qn-icon">🗒</span> Orders
        </a>
        <a href="manage_users.php" class="qn-btn">
            <span class="qn-icon">👥</span> Users
        </a>
        <a href="manage_sellers.php" class="qn-btn">
            <span class="qn-icon">🏪</span> Sellers
            <?php if ($pending_sellers > 0): ?><span class="qn-badge"><?= $pending_sellers ?></span><?php endif; ?>
        </a>
        <a href="manage_category_managers.php" class="qn-btn">
            <span class="qn-icon">🗂</span> Cat. Managers
            <?php if ($pending_cms > 0): ?><span class="qn-badge"><?= $pending_cms ?></span><?php endif; ?>
        </a>
        <a href="manage_categories.php" class="qn-btn">
            <span class="qn-icon">🏷</span> Categories
        </a>
        <a href="manage_coupons.php" class="qn-btn">
            <span class="qn-icon">🎟</span> Coupons
        </a>
    </div>

    <div class="row g-4">

        <!-- ── RECENT ORDERS (v_order_summary) ── -->
        <div class="col-lg-8">
            <div class="dash-card">
                <div class="dash-card-header">
                    📋 Recent Orders
                    <a href="manage_orders.php">View all →</a>
                </div>
                <div style="overflow-x:auto">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($recent_orders && $recent_orders->num_rows > 0):
                        while ($row = $recent_orders->fetch_assoc()):
                            $st  = $row['order_status'];
                            $cls = strtolower(str_replace('_','-',$st));
                    ?>
                        <tr>
                            <td><a href="order_details.php?id=<?= $row['order_id'] ?>" style="font-weight:700;color:#131921;text-decoration:none">#<?= $row['order_id'] ?></a></td>
                            <td>
                                <div style="font-weight:600;font-size:.84rem"><?= htmlspecialchars($row['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="font-size:.72rem;color:#9ca3af"><?= htmlspecialchars($row['customer_email'], ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td style="font-weight:700;color:#16a34a">৳ <?= number_format($row['total_amount']) ?></td>
                            <td>
                                <span style="font-size:.75rem;color:#6b7280"><?= htmlspecialchars($row['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span><br>
                                <span class="ob ob-<?= strtolower($row['payment_status'] ?? 'pending') ?>"><?= htmlspecialchars($row['payment_status'] ?? 'Pending', ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><span class="ob ob-<?= $cls ?>"><?= str_replace('_',' ',$st) ?></span></td>
                            <td style="font-size:.78rem;color:#9ca3af"><?= date('d M, Y', strtotime($row['ordered_at'])) ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:2rem">No orders yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- ── RIGHT COLUMN ── -->
        <div class="col-lg-4">

            <!-- Low stock (v_low_stock_products) -->
            <div class="dash-card">
                <div class="dash-card-header">
                    ⚠️ Low Stock
                    <a href="manage_products.php">View all →</a>
                </div>
                <table class="dash-table">
                    <thead><tr><th>Product</th><th>Stock</th></tr></thead>
                    <tbody>
                    <?php if ($low_stock && $low_stock->num_rows > 0):
                        while ($p = $low_stock->fetch_assoc()):
                            $pct = min(100, ($p['stock'] / 5) * 100);
                            $bar_color = $p['stock'] === 0 ? '#dc2626' : ($p['stock'] <= 2 ? '#f59e0b' : '#16a34a');
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.83rem"><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="font-size:.72rem;color:#9ca3af"><?= htmlspecialchars($p['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td>
                                <span style="font-weight:700;color:<?= $bar_color ?>"><?= $p['stock'] ?></span>
                                <div class="stock-bar-wrap"><div class="stock-bar" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div></div>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="2" style="text-align:center;color:#9ca3af;padding:1.5rem">All products well-stocked.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Seller earnings (v_seller_earnings) -->
            <div class="dash-card">
                <div class="dash-card-header">
                    💰 Seller Earnings
                    <a href="manage_sellers.php">View all →</a>
                </div>
                <table class="dash-table">
                    <thead><tr><th>Seller</th><th>Net</th><th>Pending</th></tr></thead>
                    <tbody>
                    <?php if ($seller_earnings && $seller_earnings->num_rows > 0):
                        while ($se = $seller_earnings->fetch_assoc()):
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.83rem"><?= htmlspecialchars($se['shop_name'] ?? $se['seller_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="font-size:.72rem;color:#9ca3af"><?= intval($se['total_vendor_orders']) ?> orders</div>
                            </td>
                            <td style="font-weight:700;color:#16a34a;font-size:.83rem">৳ <?= number_format($se['net_earnings'] ?? 0) ?></td>
                            <td style="font-size:.8rem;color:#b45309">৳ <?= number_format($se['pending_payout'] ?? 0) ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="3" style="text-align:center;color:#9ca3af;padding:1.5rem">No seller earnings yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div><!-- /col -->
    </div><!-- /row -->

    <div class="row g-4">

        <!-- Top products -->
        <div class="col-lg-6">
            <div class="dash-card">
                <div class="dash-card-header">🏆 Top Selling Products</div>
                <table class="dash-table">
                    <thead><tr><th>Product</th><th>Sold</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php if ($top_products && $top_products->num_rows > 0):
                        while ($p = $top_products->fetch_assoc()):
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.84rem"><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if (!empty($p['brand'])): ?><div style="font-size:.72rem;color:#9ca3af"><?= htmlspecialchars($p['brand'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                            </td>
                            <td style="font-weight:700"><?= intval($p['total_sold']) ?></td>
                            <td style="font-weight:700;color:#16a34a">৳ <?= number_format($p['revenue']) ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="3" style="text-align:center;color:#9ca3af;padding:1.5rem">No sales data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sales by category -->
        <div class="col-lg-6">
            <div class="dash-card">
                <div class="dash-card-header">🏷 Sales by Category</div>
                <table class="dash-table">
                    <thead><tr><th>Category</th><th>Items</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php if ($sales_by_category && $sales_by_category->num_rows > 0):
                        while ($cat = $sales_by_category->fetch_assoc()):
                    ?>
                        <tr>
                            <td style="font-weight:600;font-size:.84rem"><?= htmlspecialchars($cat['category'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= intval($cat['items_sold']) ?></td>
                            <td style="font-weight:700;color:#16a34a">৳ <?= number_format($cat['revenue']) ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="3" style="text-align:center;color:#9ca3af;padding:1.5rem">No category data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>
</div>

<?php include("../includes/footer.php"); ?>