<?php
// ── Session + DB must come before auth_check ─────────────────────────────────
include("../includes/auth_check.php");   // redirects guests to login
include("../config/db.php");

$user_id    = intval($_SESSION['user_id']);
$user_role  = $_SESSION['role'] ?? 'user';

// ── GATE 1: Only role='user' may write reviews ────────────────────────────────
// Admins, sellers, and category managers are redirected silently.
if ($user_role !== 'user') {
    header("Location: home.php");
    exit();
}

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$product_id) { header("Location: home.php"); exit(); }

// ── Verify product exists and is approved ────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT id, name, price, image FROM products WHERE id = ? AND status = 'approved'"
);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) { header("Location: home.php"); exit(); }

// ── GATE 2: User must have at least one DELIVERED order for this product ──────
$stmt = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.product_id = ?
      AND o.user_id     = ?
      AND o.status      = 'Delivered'
");
$stmt->bind_param("ii", $product_id, $user_id);
$stmt->execute();
$has_delivered = intval($stmt->get_result()->fetch_assoc()['cnt']) > 0;
$stmt->close();

if (!$has_delivered) {
    // Redirect back to product page with a clear message
    header("Location: product.php?id={$product_id}&review_error=not_delivered");
    exit();
}

// ── Submit / update review ────────────────────────────────────────────────────
$errors  = [];
$success = '';

if (isset($_POST['submit_review'])) {
    $rating  = intval($_POST['rating']  ?? 0);
    $comment = trim(strip_tags($_POST['comment'] ?? ''));

    if ($rating < 1 || $rating > 5) $errors[] = "Please select a rating between 1 and 5.";
    if (empty($comment))            $errors[] = "Please write a comment.";
    if (strlen($comment) < 10)      $errors[] = "Comment must be at least 10 characters.";

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO reviews (user_id, product_id, rating, comment, is_verified_purchase, status)
            VALUES (?, ?, ?, ?, 1, 'approved')
            ON DUPLICATE KEY UPDATE
                rating               = VALUES(rating),
                comment              = VALUES(comment),
                is_verified_purchase = 1
        ");
        $stmt->bind_param("iiis", $user_id, $product_id, $rating, $comment);
        $stmt->execute();
        $stmt->close();
        $success = "Thank you! Your review has been saved.";
    }
}

// ── Fetch this user's existing review ────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT rating, comment FROM reviews WHERE user_id = ? AND product_id = ?"
);
$stmt->bind_param("ii", $user_id, $product_id);
$stmt->execute();
$my_review = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── All approved reviews for this product ────────────────────────────────────
$stmt = $conn->prepare("
    SELECT r.rating, r.comment, r.is_verified_purchase, r.created_at,
           u.name AS user_name
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.product_id = ? AND r.status = 'approved'
    ORDER BY r.created_at DESC
");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$reviews    = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$avg_rating = count($reviews)
    ? array_sum(array_column($reviews, 'rating')) / count($reviews)
    : 0;
?>
<?php include("../includes/header.php"); ?>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">

<style>
:root{--ink:#0f0f0f;--muted:#6b7280;--border:#e5e7eb;--surface:#f9fafb;--accent:#131921;--amber:#f59e0b;--green:#16a34a;--radius:12px;}
*,*::before,*::after{box-sizing:border-box;}
.rv-page{font-family:'DM Sans',sans-serif;background:#f3f4f6;min-height:100vh;padding:2.5rem 0 5rem;}
.rv-card{background:#fff;border-radius:var(--radius);padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.25rem;}
.rv-card-title{font-family:'Playfair Display',serif;font-size:1.05rem;color:var(--ink);margin-bottom:1.1rem;}
.back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--muted);text-decoration:none;margin-bottom:1.25rem;font-weight:500;}
.back-link:hover{color:var(--ink);}
.page-heading{font-family:'Playfair Display',serif;font-size:1.65rem;color:var(--ink);margin-bottom:1.5rem;}
.prod-row{display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;}
.prod-row img{width:64px;height:64px;object-fit:cover;border-radius:8px;border:.5px solid var(--border);}
.prod-name{font-size:1rem;font-weight:700;color:var(--ink);}
.prod-price{font-size:.85rem;color:var(--muted);}
.verified-badge{display:inline-flex;align-items:center;gap:.3rem;background:#d1fae5;color:#065f46;border-radius:5px;padding:4px 10px;font-size:.75rem;font-weight:700;margin-bottom:1rem;}
.star-select{display:flex;flex-direction:row-reverse;gap:4px;margin-bottom:1rem;}
.star-select input{display:none;}
.star-select label{font-size:2rem;color:#d1d5db;cursor:pointer;transition:color .1s;line-height:1;}
.star-select input:checked ~ label,
.star-select label:hover,
.star-select label:hover ~ label{color:#f59e0b;}
.field{display:flex;flex-direction:column;gap:.3rem;margin-bottom:1rem;}
.field label{font-size:.8rem;font-weight:700;color:var(--ink);}
.field textarea{padding:.65rem .85rem;border:.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--ink);background:#fff;transition:border-color .15s;outline:none;width:100%;resize:vertical;min-height:100px;}
.field textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(19,25,33,.08);}
.submit-btn{display:block;width:100%;padding:.85rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;transition:background .14s;}
.submit-btn:hover{background:#232f3e;}
.flash-ok{background:#d1fae5;color:#065f46;border-radius:8px;padding:.75rem 1rem;font-size:.85rem;font-weight:600;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}
.flash-err{background:#fee2e2;color:#991b1b;border-radius:8px;padding:.65rem 1rem;font-size:.83rem;font-weight:500;margin-bottom:1rem;}
.error-list{margin:0;padding-left:1.1rem;}
.error-list li{margin-bottom:.2rem;}
.review-item{padding:.9rem 0;border-bottom:.5px solid var(--border);}
.review-item:last-child{border-bottom:none;}
.review-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;}
.reviewer-name{font-size:.88rem;font-weight:700;color:var(--ink);}
.review-stars{color:#f59e0b;font-size:1rem;letter-spacing:1px;}
.review-comment{font-size:.85rem;color:#374151;line-height:1.6;margin:.3rem 0;}
.review-meta{font-size:.72rem;color:#9ca3af;}
.avg-row{display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;}
.avg-score{font-size:2.5rem;font-weight:800;color:var(--ink);line-height:1;}
.avg-stars{color:#f59e0b;font-size:1.2rem;}
.avg-count{font-size:.83rem;color:var(--muted);}
</style>

<div class="rv-page"><div class="container" style="max-width:700px">

  <a href="product.php?id=<?= $product_id ?>" class="back-link">← Back to Product</a>
  <h1 class="page-heading">Customer Reviews</h1>

  <!-- Product summary -->
  <div class="rv-card">
    <div class="prod-row">
      <img src="../<?= htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
      <div>
        <div class="prod-name"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="prod-price">৳ <?= number_format($product['price']) ?></div>
      </div>
    </div>
    <?php if ($avg_rating > 0): ?>
    <div class="avg-row">
      <div class="avg-score"><?= number_format($avg_rating, 1) ?></div>
      <div>
        <div class="avg-stars"><?= str_repeat('★', round($avg_rating)) ?><?= str_repeat('☆', 5 - round($avg_rating)) ?></div>
        <div class="avg-count"><?= count($reviews) ?> review<?= count($reviews) !== 1 ? 's' : '' ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Write / edit review (only shown to verified-purchase users — gate above ensures this) -->
  <div class="rv-card">
    <div class="rv-card-title"><?= $my_review ? '✏️ Edit Your Review' : '✍️ Write a Review' ?></div>

    <!-- Always verified at this point -->
    <div class="verified-badge">✅ Verified Purchase — you bought this product</div>

    <?php if ($success): ?>
      <div class="flash-ok">✓ <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="flash-err">
        <ul class="error-list">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="field">
        <label>Your Rating *</label>
        <div class="star-select">
          <?php for ($i = 5; $i >= 1; $i--):
              $checked = (($my_review['rating'] ?? intval($_POST['rating'] ?? 0)) === $i) ? 'checked' : '';
          ?>
          <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>" <?= $checked ?> required>
          <label for="star<?= $i ?>" title="<?= $i ?> star<?= $i !== 1 ? 's' : '' ?>">★</label>
          <?php endfor; ?>
        </div>
      </div>

      <div class="field">
        <label>Your Comment *</label>
        <textarea name="comment" placeholder="Share your experience with this product…" required><?=
          htmlspecialchars($_POST['comment'] ?? $my_review['comment'] ?? '', ENT_QUOTES, 'UTF-8')
        ?></textarea>
      </div>

      <button type="submit" name="submit_review" class="submit-btn">
        <?= $my_review ? 'Update Review' : 'Submit Review' ?>
      </button>
    </form>
  </div>

  <!-- All reviews -->
  <?php if (!empty($reviews)): ?>
  <div class="rv-card">
    <div class="rv-card-title">💬 All Reviews</div>
    <?php foreach ($reviews as $r): ?>
    <div class="review-item">
      <div class="review-header">
        <div>
          <span class="reviewer-name"><?= htmlspecialchars($r['user_name'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($r['is_verified_purchase']): ?>
            <span style="font-size:.7rem;background:#d1fae5;color:#065f46;border-radius:4px;padding:1px 6px;font-weight:700;margin-left:5px;">✓ Verified</span>
          <?php endif; ?>
        </div>
        <div class="review-stars">
          <?= str_repeat('★', intval($r['rating'])) ?><?= str_repeat('☆', 5 - intval($r['rating'])) ?>
        </div>
      </div>
      <div class="review-comment"><?= htmlspecialchars($r['comment'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="review-meta"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="rv-card" style="text-align:center;color:var(--muted);padding:2.5rem 1rem;">
    No reviews yet. Be the first!
  </div>
  <?php endif; ?>

</div></div>
<?php include("../includes/footer.php"); ?>