<?php
//include("../includes/auth_check.php");
include("../config/db.php");

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = $_SESSION['user_id'];

if (!$order_id) {
    header("Location: orders.php");
    exit();
}

// Get Order Details
$order = $conn->query("
    SELECT o.*, u.name as customer_name 
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    WHERE o.id = $order_id AND o.user_id = $user_id
")->fetch_assoc();

if (!$order) {
    header("Location: orders.php");
    exit();
}

// Get Order Items
$items = $conn->query("
    SELECT oi.*, p.name, p.image 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = $order_id
");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <a href="orders.php" class="btn btn-secondary mb-4">← Back to My Orders</a>

    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
            <h4>Order #<?= $order['id'] ?> | <?= date('d M, Y h:i A', strtotime($order['created_at'])) ?></h4>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h5>Order Status</h5>
                    <h3><span class="badge bg-<?= $order['status'] == 'Delivered' ? 'success' : ($order['status'] == 'Cancelled' ? 'danger' : 'warning') ?>">
                        <?= $order['status'] ?>
                    </span></h3>
                </div>
                <div class="col-md-6 text-end">
                    <h5>Total Amount</h5>
                    <h3 class="text-primary">৳ <?= number_format($order['total_amount']) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Items -->
    <h5 class="mb-3">Order Items</h5>
    <div class="card shadow mb-4">
        <div class="card-body">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($item = $items->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <img src="../<?= htmlspecialchars($item['image']) ?>" width="50" height="50" style="object-fit:cover;border-radius:5px;" alt="">
                            <?= htmlspecialchars($item['name']) ?>
                        </td>
                        <td>৳ <?= number_format($item['price']) ?></td>
                        <td><?= $item['quantity'] ?></td>
                        <td><strong>৳ <?= number_format($item['price'] * $item['quantity']) ?></strong></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="text-center">
        <a href="home.php" class="btn btn-primary">Continue Shopping</a>
    </div>
</div>

<?php include("../includes/footer.php"); ?>