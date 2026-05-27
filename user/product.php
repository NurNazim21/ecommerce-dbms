<?php
include("../config/db.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$is_admin            = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$is_regular_user     = isset($_SESSION['user_id'])
    && !$is_admin
    && !(isset($_SESSION['role']) && in_array($_SESSION['role'], ['seller','category_manager']));

if (!$product_id) { header("Location: home.php"); exit(); }

// Product details
$product = $conn->query("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = $product_id AND p.status = 'approved'
")->fetch_assoc();

if (!$product) { header("Location: home.php"); exit(); }

// Reviews
$reviews = $conn->query("
    SELECT r.*, u.name as user_name
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.product_id = $product_id AND r.status = 'approved'
    ORDER BY r.created_at DESC
");

$avg_rating = $conn->query(
    "SELECT AVG(rating) as avg FROM reviews WHERE product_id = $product_id AND status = 'approved'"
)->fetch_assoc()['avg'] ?? 0;

// ── Delivery-gate check for the review button ─────────────────────────────────
// Only run this query for role='user' — admins/sellers never get the button.
$user_has_delivered = false;
if ($is_regular_user) {
    $uid  = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.product_id = ? AND o.user_id = ? AND o.status = 'Delivered'
    ");
    $stmt->bind_param("ii", $product_id, $uid);
    $stmt->execute();
    $user_has_delivered = intval($stmt->get_result()->fetch_assoc()['cnt']) > 0;
    $stmt->close();
}

// Recommendations
$recommended = $conn->query("
    SELECT p.id, p.name, p.price, p.image, p.stock,
           COUNT(oi2.order_id) as freq
    FROM order_items oi1
    JOIN order_items oi2 ON oi1.order_id = oi2.order_id
    JOIN products p ON oi2.product_id = p.id
    WHERE oi1.product_id = $product_id
      AND oi2.product_id != $product_id
      AND p.status = 'approved'
    GROUP BY p.id, p.name, p.price, p.image, p.stock
    ORDER BY freq DESC, p.name ASC
    LIMIT 6
");

if ($recommended->num_rows == 0) {
    $recommended = $conn->query("
        SELECT id, name, price, image, stock
        FROM products
        WHERE category_id = {$product['category_id']}
          AND id != $product_id
          AND status = 'approved'
        ORDER BY RAND()
        LIMIT 6
    ");
}

// Flash message when redirected back from review.php
$review_error = $_GET['review_error'] ?? '';
?>
<?php include("../includes/header.php"); ?>

<div class="container py-5">
  <a href="home.php" class="btn btn-secondary mb-4">← Back to Shop</a>

  <?php if ($review_error === 'not_delivered'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>You need to receive this product first.</strong>
    Reviews are only available after your order status is <em>Delivered</em>.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

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

      <?php if (!empty($product['brand'])): ?>
        <p class="text-muted fs-5"><?= htmlspecialchars($product['brand']) ?></p>
      <?php endif; ?>

      <div class="d-flex align-items-center gap-3 mb-3">
        <h2 class="text-primary mb-0">৳ <?= number_format($product['price']) ?></h2>
        <?php if ($product['stock'] > 0): ?>
          <span class="badge bg-success fs-6">In Stock (<?= $product['stock'] ?> left)</span>
        <?php else: ?>
          <span class="badge bg-danger fs-6">Out of Stock</span>
        <?php endif; ?>
      </div>

      <?php if ($avg_rating > 0): ?>
        <p class="fs-5">
          <strong><?= number_format($avg_rating, 1) ?> ★</strong>
          (<?= $reviews->num_rows ?> reviews)
        </p>
      <?php endif; ?>

      <hr>

      <h5 class="fw-semibold">Description</h5>
      <p class="lead"><?= nl2br(htmlspecialchars($product['description'] ?? 'No description available.')) ?></p>

      <?php if ($is_regular_user): ?>
        <div class="mt-4">
          <?php if ($product['stock'] > 0): ?>
            <a href="cart.php?add=<?= $product['id'] ?>" class="btn btn-primary btn-lg px-5 me-3">
              <i class="fas fa-cart-plus"></i> Add to Cart
            </a>
          <?php else: ?>
            <button class="btn btn-secondary btn-lg px-5 me-3" disabled>Out of Stock</button>
          <?php endif; ?>
          <a href="wishlist.php?add=<?= $product['id'] ?>" class="btn btn-outline-danger btn-lg">
            <i class="fas fa-heart"></i> Add to Wishlist
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Customers Also Bought -->
  <div class="mt-5">
    <h3 class="mb-4">Customers Also Bought</h3>
    <?php if ($recommended->num_rows > 0): ?>
      <div class="row g-4">
        <?php while ($rec = $recommended->fetch_assoc()): ?>
        <div class="col-md-4 col-lg-3 col-xl-2">
          <div class="card h-100 shadow-sm product-card">
            <img src="../<?= htmlspecialchars($rec['image']) ?>"
                 class="card-img-top"
                 style="height:180px;object-fit:cover;"
                 alt="<?= htmlspecialchars($rec['name']) ?>">
            <div class="card-body d-flex flex-column">
              <h6 class="card-title"><?= htmlspecialchars($rec['name']) ?></h6>
              <p class="text-primary fw-bold mb-2">৳ <?= number_format($rec['price']) ?></p>
              <?php if ($is_regular_user): ?>
                <?php if ($rec['stock'] > 0): ?>
                  <a href="cart.php?add=<?= $rec['id'] ?>" class="btn btn-primary btn-sm mt-auto">
                    <i class="fas fa-cart-plus"></i> Add to Cart
                  </a>
                <?php else: ?>
                  <button class="btn btn-secondary btn-sm mt-auto" disabled>Out of Stock</button>
                <?php endif; ?>
              <?php else: ?>
                <a href="product.php?id=<?= $rec['id'] ?>" class="btn btn-outline-secondary btn-sm mt-auto">View Details</a>
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

    <?php
    // ── Review button logic ────────────────────────────────────────────────────
    // Guest → prompt to log in
    // Logged-in non-user roles (admin/seller/category_manager) → no button
    // role='user', no delivered order → disabled tooltip button
    // role='user', has delivered order → active link to review.php
    if (!isset($_SESSION['user_id'])): ?>
      <a href="../auth/login.php" class="btn btn-outline-primary mb-4">
        <i class="fas fa-sign-in-alt me-1"></i> Log in to Write a Review
      </a>

    <?php elseif ($is_regular_user && $user_has_delivered): ?>
      <a href="review.php?id=<?= $product['id'] ?>" class="btn btn-outline-primary mb-4">
        <i class="fas fa-pen me-1"></i> Write a Review
      </a>

    <?php elseif ($is_regular_user && !$user_has_delivered): ?>
      <button class="btn btn-outline-secondary mb-4"
              disabled
              title="You can review this product after your order is delivered."
              data-bs-toggle="tooltip">
        <i class="fas fa-lock me-1"></i> Purchase &amp; Receive to Review
      </button>
      <p class="text-muted small mb-4">
        Reviews are unlocked once your order status is <strong>Delivered</strong>.
      </p>

    <?php endif;
    // Admin / seller / category_manager: no review button rendered at all
    ?>

    <?php
    $reviews->data_seek(0); // reset pointer in case it was read above
    if ($reviews->num_rows > 0):
        while ($r = $reviews->fetch_assoc()): ?>
      <div class="card mb-3">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <strong><?= htmlspecialchars($r['user_name']) ?></strong>
              <?php if ($r['is_verified_purchase']): ?>
                <span class="badge bg-success ms-2" style="font-size:.7rem;">✓ Verified Purchase</span>
              <?php endif; ?>
            </div>
            <span class="text-warning fs-5"><?= str_repeat('★', intval($r['rating'])) ?></span>
          </div>
          <p class="mt-2 mb-1"><?= htmlspecialchars($r['comment']) ?></p>
          <small class="text-muted"><?= date('d M, Y', strtotime($r['created_at'])) ?></small>
        </div>
      </div>
    <?php endwhile;
    else: ?>
      <div class="alert alert-info">No reviews yet. Be the first to review!</div>
    <?php endif; ?>
  </div>
</div>

<style>
.product-card:hover{transform:translateY(-5px);transition:all .3s ease;box-shadow:0 10px 20px rgba(0,0,0,.1)!important;}
</style>

<script>
// Enable Bootstrap tooltips (for the disabled review button)
document.addEventListener('DOMContentLoaded', function () {
    var tooltips = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltips.forEach(function (el) { new bootstrap.Tooltip(el); });
});
</script>

<?php include("../includes/footer.php"); ?>