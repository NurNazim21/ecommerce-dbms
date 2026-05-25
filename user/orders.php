<?php
include("../includes/auth_check.php");
include("../config/db.php");

// intval() is a hard cast — even if the session were poisoned,
// this can only ever produce an integer, never SQL syntax.
$user_id = intval($_SESSION['user_id']);

// ── Prepared statement replaces the raw interpolated query ──────────────────
// Old (vulnerable):
//   $conn->query("SELECT ... WHERE o.user_id = $user_id GROUP BY o.id");
//
// New: ? is a placeholder. bind_param("i", $user_id) binds it as an integer.
// The DB driver handles escaping — SQL and data are never concatenated.

$stmt = $conn->prepare("
    SELECT o.id, o.total_amount, o.status, o.created_at,
           COUNT(oi.id) as item_count
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.user_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
");

// "i" means the bound value is an integer.
// If you had two params you'd write "ii", $param1, $param2 etc.
$stmt->bind_param("i", $user_id);
$stmt->execute();

// get_result() gives you the familiar fetch_assoc() interface
$result = $stmt->get_result();
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1 class="mb-4">My Orders</h1>

    <?php if ($result->num_rows > 0): ?>
        <div class="row g-4">
            <?php while ($order = $result->fetch_assoc()): ?>
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <!-- FIX: intval() before echoing any integer from DB -->
                                <!-- Status comes from an enum — htmlspecialchars() anyway -->
                                <h5>Order #<?= intval($order['id']) ?></h5>
                                <p class="text-muted mb-1">
                                    <?= date('d M, Y h:i A', strtotime($order['created_at'])) ?>
                                </p>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?= $order['status'] === 'Delivered' ? 'success' : 'warning' ?>">
                                    <!-- FIX: htmlspecialchars on any string column echoed into HTML -->
                                    <?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>

                        <hr>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= intval($order['item_count']) ?> items</strong>
                            </div>
                            <div>
                                <h5 class="text-primary mb-0">
                                    ৳ <?= number_format($order['total_amount']) ?>
                                </h5>
                            </div>
                        </div>

                        <div class="mt-3">
                            <!-- FIX: intval() in the URL prevents any ID tampering -->
                            <a href="order_details.php?id=<?= intval($order['id']) ?>"
                               class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>

        <?php
        // FIX: Always close prepared statements when done
        $stmt->close();
        ?>

    <?php else: ?>
        <div class="alert alert-info">
            You haven't placed any orders yet.
            <a href="home.php" class="btn btn-primary mt-2">Start Shopping</a>
        </div>
    <?php endif; ?>

    <!-- FIX: Show success message that checkout.php redirects here with -->
    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
        <div class="alert alert-success mt-3">
            <i class="fas fa-check-circle me-2"></i>
            Your order #<?= intval($_GET['order_id'] ?? 0) ?> has been placed successfully!
        </div>
    <?php endif; ?>
</div>

<?php include("../includes/footer.php"); ?>