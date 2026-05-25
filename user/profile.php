<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id  = intval($_SESSION['user_id']);          // hard-cast; safe to use in queries
$is_admin = ($_SESSION['role'] === 'admin');

// ── UPDATE PROFILE ────────────────────────────────────────────────────────────
// FIX: was using mysqli_real_escape_string + raw interpolation → SQL-injectable.
//      Now uses a prepared statement with typed placeholders.
if (isset($_POST['update_profile'])) {

    $name    = trim($_POST['name']    ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $address = trim($_POST['address'] ?? '');
    $city    = trim($_POST['city']    ?? '');

    // Basic server-side validation
    if (empty($name)) {
        $error = "Full name cannot be empty.";
    } else {
        $stmt = $conn->prepare(
            "UPDATE users SET name = ?, phone = ?, address = ?, city = ?
             WHERE id = ?"
        );
        // s = string, i = integer
        $stmt->bind_param("ssssi", $name, $phone, $address, $city, $user_id);

        if ($stmt->execute()) {
            $success = "Profile updated successfully!";
            $_SESSION['name'] = $name;   // keep session in sync
        } else {
            $error = "Update failed. Please try again.";
        }
        $stmt->close();
    }
}

// ── FETCH CURRENT USER ────────────────────────────────────────────────────────
// FIX: was raw "SELECT * FROM users WHERE id = $user_id" → prepared statement.
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1 class="mb-4">My Profile</h1>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- Personal Information -->
        <div class="col-lg-<?= $is_admin ? '12' : '6' ?>">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5>Personal Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <!-- Email is read-only; not submitted or updated -->
                            <input type="email" class="form-control"
                                   value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3"
                                      ><?= htmlspecialchars($user['address'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control"
                                   value="<?= htmlspecialchars($user['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            Update Profile
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Recent Orders – shown only for non-admin users -->
        <?php if (!$is_admin): ?>
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-success text-white">
                    <h5>My Recent Orders</h5>
                </div>
                <div class="card-body">
                    <?php
                    // FIX: was raw "SELECT * FROM orders WHERE user_id = $user_id" → prepared.
                    $stmt = $conn->prepare(
                        "SELECT id, total_amount, status, created_at
                         FROM orders
                         WHERE user_id = ?
                         ORDER BY created_at DESC
                         LIMIT 5"
                    );
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $orders = $stmt->get_result();
                    $stmt->close();
                    ?>

                    <?php if ($orders->num_rows > 0): ?>
                        <?php while ($order = $orders->fetch_assoc()): ?>
                        <div class="border-bottom pb-3 mb-3">
                            <div class="d-flex justify-content-between">
                                <strong>Order #<?= intval($order['id']) ?></strong>
                                <span class="badge bg-<?= $order['status'] === 'Delivered' ? 'success' : 'warning' ?>">
                                    <?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <small class="text-muted">
                                <?= date('d M, Y', strtotime($order['created_at'])) ?>
                            </small>
                            <p class="mb-0">
                                Total: <strong>৳ <?= number_format($order['total_amount']) ?></strong>
                            </p>
                            <a href="order_details.php?id=<?= intval($order['id']) ?>"
                               class="btn btn-sm btn-outline-primary mt-1">View</a>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted">No orders yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include("../includes/footer.php"); ?>