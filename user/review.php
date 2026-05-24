<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = $_SESSION['user_id'];
$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$product_id) {
    header("Location: home.php");
    exit();
}

// Submit Review
if (isset($_POST['submit_review'])) {
    $rating = intval($_POST['rating']);
    $comment = mysqli_real_escape_string($conn, $_POST['comment']);

    $sql = "INSERT INTO reviews (user_id, product_id, rating, comment) 
            VALUES ($user_id, $product_id, $rating, '$comment')
            ON DUPLICATE KEY UPDATE rating = $rating, comment = '$comment'";
    
    if ($conn->query($sql)) {
        $success = "Thank you for your review!";
    }
}

// Get Product
$product = $conn->query("SELECT * FROM products WHERE id = $product_id")->fetch_assoc();

// Get Reviews
$reviews = $conn->query("
    SELECT r.*, u.name as user_name 
    FROM reviews r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.product_id = $product_id 
    ORDER BY r.created_at DESC
");

$avg_rating = $conn->query("SELECT AVG(rating) as avg FROM reviews WHERE product_id = $product_id")->fetch_assoc()['avg'];
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <a href="home.php" class="btn btn-secondary mb-4">← Back to Shop</a>

    <div class="row">
        <div class="col-md-5">
            <img src="../<?= htmlspecialchars($product['image']) ?>" class="img-fluid rounded shadow" alt="">
        </div>
        <div class="col-md-7">
            <h2><?= htmlspecialchars($product['name']) ?></h2>
            <h4 class="text-primary">৳ <?= number_format($product['price']) ?></h4>
            
            <?php if($avg_rating): ?>
                <p><strong>Average Rating:</strong> <?= number_format($avg_rating, 1) ?> ★</p>
            <?php endif; ?>

            <hr>

            <h5>Write a Review</h5>
            <?php if(isset($success)) echo "<div class='alert alert-success'>$success</div>"; ?>

            <form method="POST">
                <div class="mb-3">
                    <label>Rating</label><br>
                    <select name="rating" class="form-select w-50" required>
                        <option value="5">5 ★ Excellent</option>
                        <option value="4">4 ★ Good</option>
                        <option value="3">3 ★ Average</option>
                        <option value="2">2 ★ Poor</option>
                        <option value="1">1 ★ Very Poor</option>
                    </select>
                </div>
                <div class="mb-3">
                    <textarea name="comment" class="form-control" rows="4" placeholder="Write your review..." required></textarea>
                </div>
                <button type="submit" name="submit_review" class="btn btn-primary">Submit Review</button>
            </form>
        </div>
    </div>

    <hr class="my-5">

    <h4>Customer Reviews</h4>
    <?php if($reviews->num_rows > 0): ?>
        <?php while($review = $reviews->fetch_assoc()): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <strong><?= htmlspecialchars($review['user_name']) ?></strong>
                        <span><?= str_repeat('★', $review['rating']) ?></span>
                    </div>
                    <p class="mt-2"><?= htmlspecialchars($review['comment']) ?></p>
                    <small class="text-muted"><?= date('d M, Y', strtotime($review['created_at'])) ?></small>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p>No reviews yet. Be the first to review!</p>
    <?php endif; ?>
</div>

<?php include("../includes/footer.php"); ?>