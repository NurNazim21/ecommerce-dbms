<?php
// ── MUST be first: session + DB ───────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("../config/db.php");   // $conn available here AND inside header.php

// ── Admin check ───────────────────────────────────────────────────────────────
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = isset($_GET['search'])    ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
$category_id = isset($_GET['category'])  ? intval($_GET['category'])  : 0;
$max_price   = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? floatval($_GET['max_price']) : 0;

// ── Featured products (latest 6 in-stock) ────────────────────────────────────
$featured = $conn->query(
    "SELECT * FROM products 
     WHERE stock > 0 
     AND status='approved'
     ORDER BY id DESC 
     LIMIT 6"
);

// ── Filtered products ─────────────────────────────────────────────────────────
$where = "WHERE p.status='approved'";
if (!empty($search)) {
    $where .= " AND (p.name LIKE '%$search%' OR p.brand LIKE '%$search%' OR p.description LIKE '%$search%')";
}
if ($category_id > 0) {
    $where .= " AND p.category_id = $category_id";
}
if ($max_price > 0) {
    $where .= " AND p.price <= $max_price";
}

$result = $conn->query(
    "SELECT p.*, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     $where
     ORDER BY p.id DESC"
);

// ── header.php uses $conn for its own queries (cart, categories, products) ────
include("../includes/header.php");
?>

<!-- ══ Hero ════════════════════════════════════════════════════════════════ -->
<header class="text-white py-5 mb-5"
        style="background: linear-gradient(rgba(0,0,0,0.65),rgba(0,0,0,0.65)),
               url('https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=1350&q=80')
               center/cover no-repeat;">
    <div class="container py-5 text-center">
        <h1 class="display-3 fw-bold mb-3">Elevate Your Lifestyle</h1>
        <p class="lead mb-4 text-white-50">
            Discover premium quality products with the best prices in Bangladesh.
        </p>
        <div class="d-flex justify-content-center gap-3">
            <a href="#products" class="btn btn-primary btn-lg px-5">Shop Now</a>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <a href="../auth/register.php" class="btn btn-outline-light btn-lg px-5">Join Us</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="container">

    <!-- ── Feature badges ───────────────────────────────────────────────── -->
    <div class="row text-center g-4 mb-5">
        <div class="col-md-4">
            <div class="p-4 bg-white shadow-sm rounded-4">
                <i class="fas fa-truck-fast fa-3x text-primary mb-3"></i>
                <h5>Fast Delivery</h5>
                <p class="text-muted small">24-hour delivery in Dhaka Metro</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-4 bg-white shadow-sm rounded-4">
                <i class="fas fa-shield-halved fa-3x text-primary mb-3"></i>
                <h5>Secure Payment</h5>
                <p class="text-muted small">100% secure checkout</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-4 bg-white shadow-sm rounded-4">
                <i class="fas fa-rotate-left fa-3x text-primary mb-3"></i>
                <h5>Easy Returns</h5>
                <p class="text-muted small">7 days easy return policy</p>
            </div>
        </div>
    </div>



    <!-- ── All Products ─────────────────────────────────────────────────── -->
    <div id="products">
        <h2 class="fw-bold mb-4">Our Collection</h2>

        <!-- Products grid -->
        <div class="row g-4">
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="card h-100 shadow-sm border-0">

                        <div class="position-relative">
                            <a href="product.php?id=<?= $row['id'] ?>">
                                <img src="../<?= htmlspecialchars($row['image']) ?>"
                                     class="card-img-top"
                                     style="height:240px;object-fit:cover;"
                                     alt="<?= htmlspecialchars($row['name']) ?>">
                            </a>
                            <span class="badge position-absolute top-0 end-0 m-3
                                         <?= $row['stock'] > 0 ? 'bg-success' : 'bg-danger' ?>">
                                <?= $row['stock'] > 0 ? 'In Stock' : 'Out of Stock' ?>
                            </span>
                        </div>

                        <div class="card-body d-flex flex-column p-4">
                            <a href="product.php?id=<?= $row['id'] ?>" class="text-decoration-none">
                                <h5 class="card-title fw-semibold">
                                    <?= htmlspecialchars($row['name']) ?>
                                </h5>
                            </a>

                            <?php if (!empty($row['brand'])): ?>
                                <p class="text-muted small mb-1">
                                    <?= htmlspecialchars($row['brand']) ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($row['category_name'])): ?>
                                <p class="text-muted small">
                                    <i class="fas fa-tag me-1"></i>
                                    <?= htmlspecialchars($row['category_name']) ?>
                                </p>
                            <?php endif; ?>

                            <div class="mt-auto">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h4 class="text-primary fw-bold mb-0">
                                        ৳ <?= number_format($row['price']) ?>
                                    </h4>
                                    <small class="text-<?= $row['stock'] > 0 ? 'success' : 'danger' ?>">
                                        <?= $row['stock'] ?> left
                                    </small>
                                </div>

                                <?php if (!$is_admin): ?>
                                    <?php if ($row['stock'] > 0): ?>
                                        <a href="cart.php?add=<?= $row['id'] ?>"
                                           class="btn btn-primary w-100 py-2 mb-2">
                                            <i class="fas fa-cart-plus me-2"></i>Add to Cart
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary w-100 py-2 mb-2" disabled>
                                            Out of Stock
                                        </button>
                                    <?php endif; ?>

                                    <a href="wishlist.php?add=<?= $row['id'] ?>"
                                       class="btn btn-outline-danger w-100 mb-2">
                                        <i class="fas fa-heart me-1"></i>Add to Wishlist
                                    </a>
                                <?php endif; ?>

                                <a href="product.php?id=<?= $row['id'] ?>"
                                   class="btn btn-outline-secondary w-100">
                                    View Details
                                </a>
                            </div>
                        </div>

                    </div>
                </div>
                <?php endwhile; ?>

            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No products found.</h4>
                    <a href="home.php" class="btn btn-primary mt-3">
                        <i class="fas fa-rotate-left me-1"></i> Clear Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /#products -->
    
        <!-- ── Trending / Featured ──────────────────────────────────────────── -->
    <?php if ($featured && $featured->num_rows > 0): ?>
    <h2 class="fw-bold mb-4">Trending Now</h2>
    <div class="row g-4 mb-5">
        <?php while ($row = $featured->fetch_assoc()): ?>
        <div class="col-md-4 col-lg-3 col-xl-2">
            <div class="card h-100 shadow-sm border-0">
                <a href="product.php?id=<?= $row['id'] ?>">
                    <img src="../<?= htmlspecialchars($row['image']) ?>"
                         class="card-img-top"
                         style="height:220px;object-fit:cover;"
                         alt="<?= htmlspecialchars($row['name']) ?>">
                </a>
                <div class="card-body">
                    <h6 class="fw-semibold"><?= htmlspecialchars($row['name']) ?></h6>
                    <p class="text-primary fw-bold">৳ <?= number_format($row['price']) ?></p>
                    <a href="product.php?id=<?= $row['id'] ?>"
                       class="btn btn-outline-primary btn-sm w-100">View Details</a>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
    <hr class="my-5">
    <?php endif; ?>
</div><!-- /.container -->

<?php include("../includes/footer.php"); ?>