<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch Order with Coupon Info
$order_query = $conn->query("
    SELECT o.*, u.name as customer_name, u.email, u.phone, u.city, u.address 
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    WHERE o.id = $order_id
");
$order = $order_query->fetch_assoc();

if (!$order) {
    die("<div class='container mt-5 alert alert-danger'>Order not found.</div>");
}

// Fetch Items
$items_query = $conn->query("
    SELECT oi.*, p.name as product_name, p.image 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = $order_id
");

$status_colors = [
    'Pending'    => 'bg-warning text-dark',
    'Processing' => 'bg-info text-white',
    'Shipped'    => 'bg-primary text-white',
    'Delivered'  => 'bg-success text-white',
    'Cancelled'  => 'bg-danger text-white'
];
$current_badge = $status_colors[$order['status']] ?? 'bg-secondary';
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
        <div>
            <h2 class="fw-bold mb-0">Order <span class="text-primary">#<?= $order['id'] ?></span></h2>
            <p class="text-muted mb-0">Placed on <?= date('F d, Y \a\t h:i A', strtotime($order['created_at'])) ?></p>
        </div>
        <div>
            <a href="manage_orders.php" class="btn btn-outline-secondary">
                <i class="fas fa-chevron-left me-2"></i>Back to List
            </a>
            <button class="btn btn-primary ms-2" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print Invoice
            </button>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Column -->
        <div class="col-md-4">
            <!-- Order Status -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted small fw-bold mb-3">Current Status</h6>
                    <span class="badge rounded-pill <?= $current_badge ?> px-3 py-2 fs-6">
                        <?= $order['status'] ?>
                    </span>
                </div>
            </div>

            <!-- Customer Details -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 fw-bold">Customer Details</h5>
                </div>
                <div class="card-body pt-0">
                    <ul class="list-unstyled">
                        <li class="mb-3"><strong>Name:</strong> <?= htmlspecialchars($order['customer_name']) ?></li>
                        <li class="mb-3"><strong>Email:</strong> <?= htmlspecialchars($order['email']) ?></li>
                        <li><strong>Phone:</strong> <?= htmlspecialchars($order['phone'] ?? 'N/A') ?></li>
                    </ul>
                </div>
            </div>

            <!-- Shipping Address -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 fw-bold">Shipping Address</h5>
                </div>
                <div class="card-body pt-0">
                    <p><?= htmlspecialchars($order['city'] ?? 'N/A') ?></p>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($order['address'] ?? 'No address')) ?></p>
                </div>
            </div>
        </div>

        <!-- Right Column - Order Items + Summary -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 fw-bold">Items Summary</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Product</th>
                                    <th>Price</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end pe-4">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $subtotal_total = 0;
                                while($item = $items_query->fetch_assoc()): 
                                    $subtotal = $item['price'] * $item['quantity'];
                                    $subtotal_total += $subtotal;
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <img src="../<?= htmlspecialchars($item['image']) ?>" class="rounded border me-3" style="width:50px;height:50px;object-fit:cover;">
                                            <div>
                                                <div class="fw-bold"><?= htmlspecialchars($item['product_name']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>৳ <?= number_format($item['price']) ?></td>
                                    <td class="text-center"><?= $item['quantity'] ?></td>
                                    <td class="text-end pe-4 fw-bold">৳ <?= number_format($subtotal) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pricing Summary -->
                <div class="card-footer bg-light py-4">
                    <div class="row justify-content-end">
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Subtotal</span>
                                <span>৳ <?= number_format($subtotal_total) ?></span>
                            </div>
                            
                            <?php if(!empty($order['coupon_code'])): ?>
                            <div class="d-flex justify-content-between mb-2 text-success">
                                <span>Coupon (<?= htmlspecialchars($order['coupon_code']) ?>)</span>
                                <span>- ৳ <?= number_format($subtotal_total - $order['total_amount']) ?></span>
                            </div>
                            <?php endif; ?>

                            <hr>
                            <div class="d-flex justify-content-between fs-5 fw-bold">
                                <span>Grand Total</span>
                                <span>৳ <?= number_format($order['total_amount']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>