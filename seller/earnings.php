<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Only count APPROVED products
$total_revenue = $conn->query("
    SELECT COALESCE(SUM(oi.quantity * oi.price), 0) as revenue 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE p.seller_id = $user_id AND p.status = 'approved'
")->fetch_assoc()['revenue'] ?? 0;

$total_orders = $conn->query("
    SELECT COUNT(DISTINCT o.id) as count 
    FROM orders o 
    JOIN order_items oi ON o.id = oi.order_id 
    JOIN products p ON oi.product_id = p.id 
    WHERE p.seller_id = $user_id AND p.status = 'approved'
")->fetch_assoc()['count'] ?? 0;

$total_products = $conn->query("SELECT COUNT(*) as count FROM products WHERE seller_id = $user_id AND status = 'approved'")->fetch_assoc()['count'] ?? 0;

// Recent Sales (only from approved products)
$recent_sales = $conn->query("
    SELECT o.id, o.created_at, o.total_amount, o.status, 
           u.name as customer_name
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    JOIN users u ON o.user_id = u.id
    WHERE p.seller_id = $user_id AND p.status = 'approved'
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 10
");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1 class="mb-4">Earnings & Sales Overview</h1>

    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="card shadow text-center">
                <div class="card-body">
                    <h2 class="text-success">৳ <?= number_format($total_revenue) ?></h2>
                    <p class="text-muted">Total Revenue</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow text-center">
                <div class="card-body">
                    <h2 class="text-primary"><?= $total_orders ?></h2>
                    <p class="text-muted">Total Orders</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow text-center">
                <div class="card-body">
                    <h2 class="text-info"><?= $total_products ?></h2>
                    <p class="text-muted">Approved Products</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header bg-dark text-white">
            <h5>Recent Sales</h5>
        </div>
        <div class="card-body">
            <table class="table table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($recent_sales->num_rows > 0): ?>
                        <?php while($sale = $recent_sales->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= $sale['id'] ?></td>
                            <td><?= htmlspecialchars($sale['customer_name']) ?></td>
                            <td><strong>৳ <?= number_format($sale['total_amount']) ?></strong></td>
                            <td>
                                <span class="badge bg-<?= $sale['status'] == 'Delivered' ? 'success' : 'warning' ?>">
                                    <?= $sale['status'] ?>
                                </span>
                            </td>
                            <td><?= date('d M, Y', strtotime($sale['created_at'])) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4">No sales yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>