<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = $_SESSION['user_id'];

// ==================== COUPON SESSION HANDLING ====================
if (!isset($_SESSION['applied_coupon'])) {
    $_SESSION['applied_coupon'] = null;
}

// Remove Coupon
if (isset($_POST['remove_coupon'])) {
    $_SESSION['applied_coupon'] = null;
    header("Location: checkout.php");
    exit();
}

// Get Cart Items
$cart_result = $conn->query("
    SELECT c.*, p.name, p.price, p.stock 
    FROM cart c 
    JOIN products p ON c.product_id = p.id 
    WHERE c.user_id = $user_id
");

if ($cart_result->num_rows == 0) {
    header("Location: cart.php");
    exit();
}

$items = [];
$total = 0;
while($item = $cart_result->fetch_assoc()) {
    $subtotal = $item['price'] * $item['quantity'];
    $total += $subtotal;
    $items[] = $item;
}

// ==================== COUPON LOGIC ====================
$discount = 0;
$coupon_code = $_SESSION['applied_coupon'] ?? '';
$coupon_error = '';
$coupon_success = '';

if (isset($_POST['apply_coupon']) && !empty($_POST['coupon_code'])) {
    $coupon_code_input = strtoupper(mysqli_real_escape_string($conn, trim($_POST['coupon_code'])));
    
    $coupon = $conn->query("SELECT * FROM coupons 
                           WHERE code = '$coupon_code_input' 
                           AND is_active = 1 
                           AND (expiry_date IS NULL OR expiry_date >= CURDATE())")->fetch_assoc();

    if ($coupon && $total >= ($coupon['min_order_amount'] ?? 0)) {
        $_SESSION['applied_coupon'] = $coupon_code_input;   // ← Save in session
        $coupon_success = "Coupon applied successfully!";
    } else {
        $coupon_error = "Invalid or expired coupon or minimum amount not met!";
    }
}

// Re-validate coupon from session on every load
if (!empty($_SESSION['applied_coupon'])) {
    $coupon = $conn->query("SELECT * FROM coupons 
                           WHERE code = '" . mysqli_real_escape_string($conn, $_SESSION['applied_coupon']) . "' 
                           AND is_active = 1 
                           AND (expiry_date IS NULL OR expiry_date >= CURDATE())")->fetch_assoc();

    if ($coupon && $total >= ($coupon['min_order_amount'] ?? 0)) {
        if ($coupon['discount_type'] == 'percentage') {
            $discount = $total * ($coupon['discount_value'] / 100);
        } else {
            $discount = $coupon['discount_value'];
        }
        $coupon_code = $_SESSION['applied_coupon'];
    } else {
        $_SESSION['applied_coupon'] = null; // Invalid now
    }
}

$final_total = max(0, $total - $discount);

// ==================== PLACE ORDER ====================
if (isset($_POST['place_order'])) {

    try {

        // START TRANSACTION
        $conn->begin_transaction();

        // FINAL STOCK + PRODUCT VALIDATION
        foreach ($items as $item) {

            $product_id = $item['product_id'];

            $latest = $conn->query("
                SELECT stock, status
                FROM products
                WHERE id = $product_id
            ")->fetch_assoc();

            // Product removed/rejected
            if (!$latest || $latest['status'] != 'approved') {

                $conn->query("
                    DELETE FROM cart
                    WHERE user_id = $user_id
                    AND product_id = $product_id
                ");

                throw new Exception("One product is no longer available.");
            }

            // Stock changed
            if ($latest['stock'] < $item['quantity']) {

                $new_qty = max(1, $latest['stock']);

                $conn->query("
                    UPDATE cart
                    SET quantity = $new_qty
                    WHERE user_id = $user_id
                    AND product_id = $product_id
                ");

                throw new Exception("Some product quantities changed due to stock availability.");
            }
        }

        // Coupon handling
        if ($discount > 0 && !empty($coupon_code)) {
            $coupon_used = $coupon_code;
        } else {
            $coupon_used = NULL;
        }

        // CREATE ORDER
        $conn->query("
            INSERT INTO orders 
            (user_id, total_amount, coupon_code, status) 
            VALUES (
                $user_id,
                $final_total,
                " . ($coupon_used ? "'$coupon_used'" : "NULL") . ",
                'Pending'
            )
        ");

        $order_id = $conn->insert_id;

        // ORDER ITEMS + STOCK UPDATE
        foreach ($items as $item) {

            // Insert order item
            $conn->query("
                INSERT INTO order_items 
                (order_id, product_id, quantity, price) 
                VALUES (
                    $order_id,
                    {$item['product_id']},
                    {$item['quantity']},
                    {$item['price']}
                )
            ");

            // Safe stock deduction
            $conn->query("
                UPDATE products 
                SET stock = stock - {$item['quantity']}
                WHERE id = {$item['product_id']}
                AND stock >= {$item['quantity']}
            ");

            // Prevent overselling
            if ($conn->affected_rows == 0) {
                throw new Exception("Stock update failed. Product out of stock.");
            }
        }

        // PAYMENT RECORD
        $conn->query("
            INSERT INTO payments 
            (order_id, payment_method, payment_status) 
            VALUES (
                $order_id,
                'Cash on Delivery',
                'Pending'
            )
        ");

        // CLEAR CART
        $conn->query("
            DELETE FROM cart
            WHERE user_id = $user_id
        ");

        // CLEAR COUPON
        $_SESSION['applied_coupon'] = null;

        // COMMIT TRANSACTION
        $conn->commit();

        header("Location: orders.php?success=1&order_id=$order_id");
        exit();

    } catch (Exception $e) {

        // ROLLBACK EVERYTHING
        $conn->rollback();

        die($e->getMessage());
    }
}
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1 class="mb-4">Checkout</h1>

    <div class="row">
        <div class="col-lg-8">
            <!-- Order Summary -->
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5>Order Summary</h5>
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
                            <?php foreach($items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['name']) ?></td>
                                <td>৳ <?= number_format($item['price']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td>৳ <?= number_format($item['price'] * $item['quantity']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow sticky-top" style="top: 20px;">
                <div class="card-body">
                    <h5>Apply Coupon</h5>
                    
                    <?php if(empty($coupon_code)): ?>
                    <form method="POST" class="input-group mb-3">
                        <input type="text" name="coupon_code" class="form-control text-uppercase" 
                               placeholder="Enter Coupon Code" required>
                        <button type="submit" name="apply_coupon" class="btn btn-outline-primary">Apply</button>
                    </form>
                    <?php else: ?>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control text-uppercase" value="<?= htmlspecialchars($coupon_code) ?>" readonly>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="remove_coupon" class="btn btn-outline-danger">Remove</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if($coupon_success): ?>
                        <div class="alert alert-success"><?= $coupon_success ?></div>
                    <?php endif; ?>
                    <?php if($coupon_error): ?>
                        <div class="alert alert-danger"><?= $coupon_error ?></div>
                    <?php endif; ?>

                    <hr>

                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal</span>
                        <span>৳ <?= number_format($total) ?></span>
                    </div>
                    
                    <?php if($discount > 0): ?>
                    <div class="d-flex justify-content-between mb-2 text-success">
                        <span>Discount (<?= htmlspecialchars($coupon_code) ?>)</span>
                        <span>- ৳ <?= number_format($discount) ?></span>
                    </div>
                    <?php endif; ?>

                    <hr>
                    <div class="d-flex justify-content-between fs-5 fw-bold">
                        <span>Final Total</span>
                        <span>৳ <?= number_format($final_total) ?></span>
                    </div>

                    <form method="POST">
                        <button type="submit" name="place_order" class="btn btn-success btn-lg w-100 mt-4 py-3">
                            Place Order (Cash on Delivery)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>