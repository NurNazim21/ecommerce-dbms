<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = $_SESSION['user_id'];

$result = $conn->query("
    SELECT o.id, o.total_amount, o.status, o.created_at,
           COUNT(oi.id) as item_count
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.user_id = $user_id
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1 class="mb-4">My Orders</h1>

    <?php if($result->num_rows > 0): ?>
        <div class="row g-4">
            <?php while($order = $result->fetch_assoc()): ?>
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5>Order #<?= $order['id'] ?></h5>
                                <p class="text-muted mb-1">
                                    <?= date('d M, Y h:i A', strtotime($order['created_at'])) ?>
                                </p>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?= $order['status'] == 'Delivered' ? 'success' : 'warning' ?>">
                                    <?= $order['status'] ?>
                                </span>
                            </div>
                        </div>
                        
                        <hr>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= $order['item_count'] ?> items</strong>
                            </div>
                            <div>
                                <h5 class="text-primary mb-0">৳ <?= number_format($order['total_amount']) ?></h5>
                            </div>
                        </div>

                        <!-- View Details Button -->
                        <div class="mt-3">
                            <a href="order_details.php?id=<?= $order['id'] ?>" 
                               class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            You haven't placed any orders yet. 
            <a href="home.php" class="btn btn-primary mt-2">Start Shopping</a>
        </div>
    <?php endif; ?>
</div>

<?php include("../includes/footer.php"); ?>