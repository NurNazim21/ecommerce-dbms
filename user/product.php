<?php
include("../config/db.php");

// Start session safely for public pages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Check if user is admin (safely)
$is_admin = false;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $is_admin = true;
}

if (!$product_id) {
    header("Location: home.php");
    exit();
}

// Get Product Details
$product = $conn->query("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.id = $product_id
    AND p.status='approved'
")->fetch_assoc();

if (!$product) {
    header("Location: home.php");
    exit();
}

// Get Reviews
$reviews = $conn->query("
    SELECT r.*, u.name as user_name 
    FROM reviews r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.product_id = $product_id 
    ORDER BY r.created_at DESC
");

$avg_rating = $conn->query("SELECT AVG(rating) as avg FROM reviews WHERE product_id = $product_id")
                    ->fetch_assoc()['avg'] ?? 0;

// ==================== CUSTOMERS ALSO BOUGHT ====================
$recommended = $conn->query("
    SELECT p.id, p.name, p.price, p.image, p.stock,
           COUNT(oi2.order_id) as freq
    FROM order_items oi1
    JOIN order_items oi2 ON oi1.order_id = oi2.order_id
    JOIN products p ON oi2.product_id = p.id
   WHERE oi1.product_id = $product_id 
  AND oi2.product_id != $product_id
  AND p.status='approved'
    GROUP BY p.id, p.name, p.price, p.image, p.stock
    ORDER BY freq DESC, p.name ASC
    LIMIT 6
");

// Fallback: Same category if no recommendations
if ($recommended->num_rows == 0) {
    $recommended = $conn->query("
        SELECT id, name, price, image, stock 
        FROM products 
       WHERE category_id = {$product['category_id']} 
AND id != $product_id
AND status='approved'
        ORDER BY RAND() 
        LIMIT 6
    ");
}
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <a href="home.php" class="btn btn-secondary mb-4">← Back to Shop</a>

    <div class="row">
        <!-- Product Image -->
        <div class="col-lg-5">
            <div class="card shadow">
                <img src="../<?= htmlspecialchars($product['image']) ?>" 
                     class="img-fluid rounded" 
                     alt="<?= htmlspecialchars($product['name']) ?>">
            </div>
        </div>

        <!-- Product Info -->
        <div class="col-lg-7">
            <h1 class="fw-bold"><?= htmlspecialchars($product['name']) ?></h1>
            
            <?php if(!empty($product['brand'])): ?>
                <p class="text-muted fs-5"><?= htmlspecialchars($product['brand']) ?></p>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-3 mb-3">
                <h2 class="text-primary mb-0">৳ <?= number_format($product['price']) ?></h2>
                <?php if($product['stock'] > 0): ?>
                    <span class="badge bg-success fs-6">In Stock (<?= $product['stock'] ?> left)</span>
                <?php else: ?>
                    <span class="badge bg-danger fs-6">Out of Stock</span>
                <?php endif; ?>
            </div>

            <?php if($avg_rating > 0): ?>
                <p class="fs-5">
                    <strong><?= number_format($avg_rating, 1) ?> ★</strong> 
                    (<?= $reviews->num_rows ?> reviews)
                </p>
            <?php endif; ?>

            <hr>

            <h5 class="fw-semibold">Description</h5>
            <p class="lead"><?= nl2br(htmlspecialchars($product['description'] ?? 'No description available.')) ?></p>

            <?php if(!$is_admin): ?>
                <div class="mt-4">
                    <?php if($product['stock'] > 0): ?>
                        <a href="cart.php?add=<?= $product['id'] ?>" class="btn btn-primary btn-lg px-5 me-3">
                            <i class="fas fa-cart-plus"></i> Add to Cart
                        </a>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-lg px-5 me-3" disabled>
                            Out of Stock
                        </button>
                    <?php endif; ?>

                    <a href="wishlist.php?add=<?= $product['id'] ?>" class="btn btn-outline-danger btn-lg">
                        <i class="fas fa-heart"></i> Add to Wishlist
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== CUSTOMERS ALSO BOUGHT SECTION ==================== -->
    <div class="mt-5">
        <h3 class="mb-4">Customers Also Bought</h3>
        
        <?php if($recommended->num_rows > 0): ?>
            <div class="row g-4">
                <?php while($rec = $recommended->fetch_assoc()): ?>
                <div class="col-md-4 col-lg-3 col-xl-2">
                    <div class="card h-100 shadow-sm product-card">
                        <img src="../<?= htmlspecialchars($rec['image']) ?>" 
                             class="card-img-top" 
                             style="height: 180px; object-fit: cover;"
                             alt="<?= htmlspecialchars($rec['name']) ?>">
                        <div class="card-body d-flex flex-column">
                            <h6 class="card-title"><?= htmlspecialchars($rec['name']) ?></h6>
                            <p class="text-primary fw-bold mb-2">৳ <?= number_format($rec['price']) ?></p>
                            
                            <?php if($rec['stock'] > 0): ?>
                                <a href="cart.php?add=<?= $rec['id'] ?>" 
                                   class="btn btn-primary btn-sm mt-auto">
                                    <i class="fas fa-cart-plus"></i> Add to Cart
                                </a>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm mt-auto" disabled>
                                    Out of Stock
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No recommendations available yet.</div>
        <?php endif; ?>
    </div>

    <!-- Reviews Section -->
    <div class="mt-5">
        <h3 class="mb-4">Customer Reviews</h3>
        
        <?php if(!$is_admin): ?>
            <a href="review.php?id=<?= $product['id'] ?>" class="btn btn-outline-primary mb-4">
                Write a Review
            </a>
        <?php endif; ?>

        <?php if($reviews->num_rows > 0): ?>
            <?php while($r = $reviews->fetch_assoc()): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <strong><?= htmlspecialchars($r['user_name']) ?></strong>
                        <span class="text-warning fs-5"><?= str_repeat('★', $r['rating']) ?></span>
                    </div>
                    <p class="mt-3"><?= htmlspecialchars($r['comment']) ?></p>
                    <small class="text-muted"><?= date('d M, Y', strtotime($r['created_at'])) ?></small>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="alert alert-info">No reviews yet. Be the first to review!</div>
        <?php endif; ?>
    </div>
</div>

<style>
.product-card:hover {
    transform: translateY(-5px);
    transition: all 0.3s ease;
    box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
}
</style>

<?php include("../includes/footer.php"); ?>