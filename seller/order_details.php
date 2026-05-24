<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = $_SESSION['user_id'];

// Security Check: Only seller's products
$check = $conn->query("
    SELECT COUNT(*) as count 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = $order_id AND p.seller_id = $user_id
")->fetch_assoc();

if ($check['count'] == 0) {
    die("<div class='container mt-5 alert alert-danger'>Access Denied.</div>");
}

$order = $conn->query("
    SELECT o.*, u.name as customer_name, u.phone, u.address, u.city 
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    WHERE o.id = $order_id
")->fetch_assoc();

$items = $conn->query("
    SELECT oi.*, p.name as product_name, p.image 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = $order_id AND p.seller_id = $user_id
");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1>Order #<?= $order_id ?></h1>
    <a href="orders.php" class="btn btn-secondary mb-4">← Back to Orders</a>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-dark text-white">
                    <h5>Ordered Items</h5>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Qty</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($item = $items->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <img src="../<?= htmlspecialchars($item['image']) ?>" width="50" height="50" style="object-fit:cover;" class="me-2">
                                    <?= htmlspecialchars($item['product_name']) ?>
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
        </div>

        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-header bg-dark text-white">
                    <h5>Customer Details</h5>
                </div>
                <div class="card-body">
                    <p><strong>Name:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
                    <p><strong>Phone:</strong> <?= htmlspecialchars($order['phone'] ?? 'N/A') ?></p>
                    <p><strong>Address:</strong> <?= nl2br(htmlspecialchars($order['address'] ?? 'N/A')) ?></p>
                    <p><strong>City:</strong> <?= htmlspecialchars($order['city'] ?? 'N/A') ?></p>
                    <hr>
                    <p><strong>Order Status:</strong> <strong><?= $order['status'] ?></strong></p>
                    <p><strong>Total:</strong> <strong>৳ <?= number_format($order['total_amount']) ?></strong></p>
                    <p><strong>Date:</strong> <?= date('d M, Y h:i A', strtotime($order['created_at'])) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>