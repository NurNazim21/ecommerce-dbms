<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = $_SESSION['user_id'];

// ==================== ADD TO CART ====================
if (isset($_GET['add'])) {

    $product_id = intval($_GET['add']);

    // Validate product
    $product_check = $conn->query("
        SELECT *
        FROM products
        WHERE id = $product_id
        AND status='approved'
        AND stock > 0
    ");

    // Product invalid
    if ($product_check->num_rows == 0) {
        header("Location: home.php?error=unavailable");
        exit();
    }

    $product = $product_check->fetch_assoc();

    // Check existing cart quantity
    $cart_check = $conn->query("
        SELECT quantity
        FROM cart
        WHERE user_id = $user_id
        AND product_id = $product_id
    ");

    $current_qty = 0;

    if ($cart_check->num_rows > 0) {
        $current_qty = $cart_check->fetch_assoc()['quantity'];
    }

    // Prevent exceeding stock
    if ($current_qty >= $product['stock']) {
        header("Location: cart.php?error=max_stock");
        exit();
    }

    // Add safely
    $conn->query("
        INSERT INTO cart (user_id, product_id, quantity)
        VALUES ($user_id, $product_id, 1)
        ON DUPLICATE KEY UPDATE quantity = quantity + 1
    ");

    header("Location: cart.php?added=1");
    exit();

}

// ==================== REMOVE FROM CART ====================
if (isset($_GET['remove'])) {
    $product_id = intval($_GET['remove']);
    $conn->query("DELETE FROM cart WHERE user_id = $user_id AND product_id = $product_id");
    header("Location: cart.php");
    exit();
}

// ==================== UPDATE QUANTITY ====================
if (isset($_POST['update_qty'])) {

    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);

    // Get product safely
    $product_check = $conn->query("
        SELECT stock, status
        FROM products
        WHERE id = $product_id
    ");

    if ($product_check->num_rows == 0) {
        header("Location: cart.php?error=product_missing");
        exit();
    }

    $product = $product_check->fetch_assoc();

    // Product unavailable
    if ($product['status'] != 'approved') {
        $conn->query("
            DELETE FROM cart
            WHERE user_id = $user_id
            AND product_id = $product_id
        ");

        header("Location: cart.php?error=unavailable");
        exit();
    }

    // Invalid quantity
    if ($quantity < 1) {
        $quantity = 1;
    }

    // Prevent exceeding stock
    if ($quantity > $product['stock']) {
        $quantity = $product['stock'];
    }

    // Update safely
    $conn->query("
        UPDATE cart
        SET quantity = $quantity
        WHERE user_id = $user_id
        AND product_id = $product_id
    ");

    header("Location: cart.php?updated=1");
    exit();
}

$cart_items = $conn->query("
    SELECT c.*, p.name, p.price, p.image, p.stock 
    FROM cart c 
    JOIN products p ON c.product_id = p.id 
    WHERE c.user_id = $user_id
    AND p.status='approved'
");

$total = 0;
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1 class="mb-4">Your Shopping Cart</h1>

    <?php if($cart_items->num_rows == 0): ?>
        <div class="alert alert-info text-center">
            Your cart is empty. <a href="home.php">Continue Shopping</a>
        </div>
    <?php else: ?>
        <form method="POST">
            <div class="card shadow">
                <div class="card-body">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Subtotal</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($item = $cart_items->fetch_assoc()): 
                                $subtotal = $item['price'] * $item['quantity'];
                                $total += $subtotal;
                            ?>
                            <tr>
                                <td>
                                    <img src="../<?= htmlspecialchars($item['image']) ?>" width="60" height="60" style="object-fit:cover; border-radius:6px;" alt="">
                                    <?= htmlspecialchars($item['name']) ?>
                                </td>
                                <td>৳ <?= number_format($item['price']) ?></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                        <input type="number" name="quantity" value="<?= $item['quantity'] ?>" min="1" max="<?= $item['stock'] ?>" style="width:80px;">
                                        <button type="submit" name="update_qty" class="btn btn-sm btn-outline-primary">Update</button>
                                    </form>
                                </td>
                                <td><strong>৳ <?= number_format($subtotal) ?></strong></td>
                                <td>
                                    <a href="?remove=<?= $item['product_id'] ?>" class="btn btn-danger btn-sm">Remove</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-body text-end">
                    <h4>Total Amount: <strong class="text-primary">৳ <?= number_format($total) ?></strong></h4>
                    <a href="checkout.php" class="btn btn-success btn-lg px-5 mt-3">Proceed to Checkout</a>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include("../includes/footer.php"); ?>