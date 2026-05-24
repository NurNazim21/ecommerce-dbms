<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user = $conn->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();

// Quick Stats - Only APPROVED Products
$total_products = $conn->query("SELECT COUNT(*) as count FROM products WHERE seller_id = $user_id AND status = 'approved'")->fetch_assoc()['count'] ?? 0;

$total_orders = $conn->query("
    SELECT COUNT(DISTINCT o.id) as count 
    FROM orders o 
    JOIN order_items oi ON o.id = oi.order_id 
    JOIN products p ON oi.product_id = p.id 
    WHERE p.seller_id = $user_id AND p.status = 'approved'
")->fetch_assoc()['count'] ?? 0;

$total_revenue = $conn->query("
    SELECT COALESCE(SUM(oi.quantity * oi.price), 0) as revenue 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE p.seller_id = $user_id AND p.status = 'approved'
")->fetch_assoc()['revenue'] ?? 0;
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Welcome, <?= htmlspecialchars($user['shop_name'] ?? $user['name']) ?></h1>
        
        <?php if($user['seller_status'] == 'approved'): ?>
            <span class="badge bg-success px-3 py-2">✅ Approved Seller</span>
        <?php elseif($user['seller_status'] == 'pending'): ?>
            <span class="badge bg-warning px-3 py-2">⏳ Pending Approval</span>
        <?php else: ?>
            <span class="badge bg-danger px-3 py-2">❌ Rejected</span>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <!-- Stats Cards -->
        <div class="col-md-4">
            <div class="card shadow h-100 text-center">
                <div class="card-body">
                    <h2 class="text-primary"><?= $total_products ?></h2>
                    <p class="text-muted">Approved Products</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow h-100 text-center">
                <div class="card-body">
                    <h2 class="text-success"><?= $total_orders ?></h2>
                    <p class="text-muted">Orders Received</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow h-100 text-center">
                <div class="card-body">
                    <h2 class="text-warning">৳ <?= number_format($total_revenue) ?></h2>
                    <p class="text-muted">Total Revenue</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-4">
        <div class="col-md-4">
            <a href="manage_products.php" class="btn btn-success btn-lg w-100 py-4">
                <i class="fas fa-box fa-2x mb-2 d-block"></i> Manage Products
            </a>
        </div>
        <div class="col-md-4">
            <a href="orders.php" class="btn btn-info btn-lg w-100 py-4">
                <i class="fas fa-shopping-bag fa-2x mb-2 d-block"></i> My Orders
            </a>
        </div>
        <div class="col-md-4">
            <a href="earnings.php" class="btn btn-warning btn-lg w-100 py-4">
                <i class="fas fa-chart-bar fa-2x mb-2 d-block"></i> Earnings
            </a>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>