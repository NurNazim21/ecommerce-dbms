<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch orders containing seller's products
$orders = $conn->query("
    SELECT DISTINCT o.id, o.total_amount, o.status, o.created_at, 
                    u.name as customer_name, u.phone
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    JOIN users u ON o.user_id = u.id
    WHERE p.seller_id = $user_id
    ORDER BY o.created_at DESC
");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>My Received Orders</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
    </div>

    <?php if($orders->num_rows > 0): ?>
        <div class="card shadow">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($order = $orders->fetch_assoc()): ?>
                        <tr>
                            <td><strong>#<?= $order['id'] ?></strong></td>
                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                            <td><?= htmlspecialchars($order['phone'] ?? '-') ?></td>
                            <td><strong>৳ <?= number_format($order['total_amount']) ?></strong></td>
                            <td>
                                <span class="badge bg-<?= $order['status'] == 'Delivered' ? 'success' : 'warning' ?>">
                                    <?= $order['status'] ?>
                                </span>
                            </td>
                            <td><?= date('d M, Y', strtotime($order['created_at'])) ?></td>
                            <td>
                                <a href="order_details.php?id=<?= $order['id'] ?>" class="btn btn-info btn-sm">View Details</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info text-center py-5">
            <h5>No orders received yet</h5>
            <p>When customers buy your products, they will appear here.</p>
        </div>
    <?php endif; ?>
</div>

<?php include("../includes/footer.php"); ?>