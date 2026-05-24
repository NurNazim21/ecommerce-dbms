<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = $_SESSION['user_id'];

// Add to Wishlist
if (isset($_GET['add'])) {
    $product_id = intval($_GET['add']);
    $conn->query("INSERT IGNORE INTO wishlist (user_id, product_id) VALUES ($user_id, $product_id)");
    header("Location: wishlist.php");
    exit();
}

// Remove from Wishlist
if (isset($_GET['remove'])) {
    $product_id = intval($_GET['remove']);
    $conn->query("DELETE FROM wishlist WHERE user_id = $user_id AND product_id = $product_id");
    header("Location: wishlist.php");
    exit();
}

// Move to Cart with Stock Check
if (isset($_GET['move_to_cart'])) {
    $product_id = intval($_GET['move_to_cart']);
    
    $stock_check = $conn->query("SELECT stock FROM products WHERE id = $product_id")->fetch_assoc();
    
    if ($stock_check && $stock_check['stock'] > 0) {
        $sql = "INSERT INTO cart (user_id, product_id, quantity) 
                VALUES ($user_id, $product_id, 1)
                ON DUPLICATE KEY UPDATE quantity = quantity + 1";
        $conn->query($sql);
        header("Location: cart.php?added=1");
        exit();
    } else {
        header("Location: wishlist.php?error=out_of_stock");
        exit();
    }
}

$wishlist_items = $conn->query("
    SELECT w.*, p.name, p.price, p.image, p.stock 
    FROM wishlist w 
    JOIN products p ON w.product_id = p.id 
    WHERE w.user_id = $user_id
    ORDER BY w.created_at DESC
");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1 class="mb-4">My Wishlist</h1>

    <?php if(isset($_GET['error']) && $_GET['error'] == 'out_of_stock'): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <strong>Out of Stock!</strong> This item cannot be added to cart right now.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if($wishlist_items->num_rows == 0): ?>
        <div class="alert alert-info text-center py-5">
            <h4>Your wishlist is empty</h4>
            <a href="home.php" class="btn btn-primary mt-3">Browse Products</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php while($item = $wishlist_items->fetch_assoc()): ?>
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 shadow-sm">
                    <img src="../<?= htmlspecialchars($item['image']) ?>" 
                         class="card-img-top" style="height: 220px; object-fit: cover;" alt="">
                    
                    <div class="card-body d-flex flex-column">
                        <h5><?= htmlspecialchars($item['name']) ?></h5>
                        <h4 class="text-primary">৳ <?= number_format($item['price']) ?></h4>
                        
                        <div class="mt-auto">
                            <?php if($item['stock'] > 0): ?>
                                <a href="?move_to_cart=<?= $item['product_id'] ?>" class="btn btn-primary w-100 mb-2">
                                    Move to Cart
                                </a>
                            <?php else: ?>
                                <button class="btn btn-secondary w-100 mb-2" disabled>
                                    Out of Stock
                                </button>
                            <?php endif; ?>
                            
                            <a href="?remove=<?= $item['product_id'] ?>" class="btn btn-danger w-100">
                                Remove from Wishlist
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</div>

<?php include("../includes/footer.php"); ?>