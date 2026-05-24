<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Advanced Analytics Queries
$total_sales     = $conn->query("SELECT COALESCE(SUM(total_amount),0) as total FROM orders")->fetch_assoc()['total'];
$total_orders    = $conn->query("SELECT COUNT(*) as count FROM orders")->fetch_assoc()['count'];
$total_products  = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'];
$total_users     = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user'")->fetch_assoc()['count'];
$total_sellers   = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='seller'")->fetch_assoc()['count'];

// Pending seller requests count (for badge)
$pending_sellers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='seller' AND seller_status='pending'")->fetch_assoc()['count'];

// Pending category manager requests count (for badge)
$pending_cms = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='category_manager' AND (seller_status='pending' OR seller_status IS NULL)")->fetch_assoc()['count'];

// Top 5 Selling Products
$top_products = $conn->query("
    SELECT p.name, p.brand, SUM(oi.quantity) as total_sold, SUM(oi.quantity * oi.price) as revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    GROUP BY p.id
    ORDER BY total_sold DESC
    LIMIT 5
");

// Sales by Category
$sales_by_category = $conn->query("
    SELECT c.name as category, COUNT(oi.id) as items_sold, SUM(oi.quantity * oi.price) as revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY revenue DESC
    LIMIT 5
");
?>

<?php include("../includes/header.php"); ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="container py-5">
    <h1 class="mb-4">Admin Analytics Dashboard</h1>

    <!-- Key Metrics -->
    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="stat-card p-4 shadow-sm border-0 bg-white rounded-4">
                <h5 class="text-muted">Total Revenue</h5>
                <h2 class="text-primary fw-bold">৳ <?= number_format($total_sales) ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card p-4 shadow-sm border-0 bg-white rounded-4">
                <h5 class="text-muted">Total Orders</h5>
                <h2 class="text-success fw-bold"><?= $total_orders ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card p-4 shadow-sm border-0 bg-white rounded-4">
                <h5 class="text-muted">Total Products</h5>
                <h2 class="text-info fw-bold"><?= $total_products ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card p-4 shadow-sm border-0 bg-white rounded-4">
                <h5 class="text-muted">Registered Users</h5>
                <h2 class="text-warning fw-bold"><?= $total_users ?></h2>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Top Selling Products -->
        <div class="col-lg-7">
            <div class="card shadow">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0">Top Selling Products</h5>
                </div>
                <div class="card-body">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Brand</th>
                                <th>Units Sold</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($p = $top_products->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['name']) ?></td>
                                <td><?= htmlspecialchars($p['brand'] ?? '-') ?></td>
                                <td><strong><?= $p['total_sold'] ?></strong></td>
                                <td>৳ <?= number_format($p['revenue']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sales by Category -->
        <div class="col-lg-5">
            <div class="card shadow">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0">Sales by Category</h5>
                </div>
                <div class="card-body">
                    <table class="table">
                        <?php while ($cat = $sales_by_category->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($cat['category']) ?></td>
                            <td><?= $cat['items_sold'] ?> items</td>
                            <td class="text-end"><strong>৳ <?= number_format($cat['revenue']) ?></strong></td>
                        </tr>
                        <?php endwhile; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Navigation -->
    <div class="row g-4 mt-5">
        <div class="col-md-3">
            <a href="manage_products.php" class="btn btn-primary btn-lg w-100 py-4 text-center rounded-4">
                <i class="fas fa-box fa-2x mb-2 d-block"></i><br>Manage Products
            </a>
        </div>
        <div class="col-md-3">
            <a href="manage_orders.php" class="btn btn-success btn-lg w-100 py-4 text-center rounded-4">
                <i class="fas fa-list-check fa-2x mb-2 d-block"></i><br>Manage Orders
            </a>
        </div>
        <div class="col-md-3">
            <a href="manage_users.php" class="btn btn-warning btn-lg w-100 py-4 text-center rounded-4">
                <i class="fas fa-users fa-2x mb-2 d-block"></i><br>Manage Users
            </a>
        </div>
        <div class="col-md-3">
            <!-- Manage Sellers with pending badge -->
            <a href="manage_sellers.php" class="btn btn-info btn-lg w-100 py-4 text-center rounded-4 position-relative">
                <i class="fas fa-store fa-2x mb-2 d-block"></i><br>Manage Sellers
                <?php if ($pending_sellers > 0): ?>
                    <span class="position-absolute top-0 end-0 translate-middle badge rounded-pill bg-danger" style="margin-top:8px;margin-right:8px;">
                        <?= $pending_sellers ?>
                    </span>
                <?php endif; ?>
            </a>
        </div>
        <div class="col-md-3">
            <!-- FIXED: was linking to category_manager/dashboard.php — now correctly manages CM applications -->
            <a href="manage_category_managers.php" class="btn btn-secondary btn-lg w-100 py-4 text-center rounded-4 position-relative">
                <i class="bi bi-grid-1x2 fs-2 mb-2 d-block"></i><br>Manage Cat. Managers
                <?php if ($pending_cms > 0): ?>
                    <span class="position-absolute top-0 end-0 translate-middle badge rounded-pill bg-danger" style="margin-top:8px;margin-right:8px;">
                        <?= $pending_cms ?>
                    </span>
                <?php endif; ?>
            </a>
        </div>
        <div class="col-md-3">
            <a href="manage_coupons.php" class="btn btn-outline-primary btn-lg w-100 py-4 text-center rounded-4">
                <i class="fas fa-tags fa-2x mb-2 d-block"></i><br>Manage Coupons
            </a>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>