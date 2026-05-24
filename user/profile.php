<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Update Profile
if (isset($_POST['update_profile'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $city = mysqli_real_escape_string($conn, $_POST['city']);

    $sql = "UPDATE users SET name='$name', phone='$phone', address='$address', city='$city' 
            WHERE id = $user_id";
    
    if ($conn->query($sql)) {
        $success = "Profile updated successfully!";
        $_SESSION['name'] = $name;
    } else {
        $error = "Update failed!";
    }
}

$user = $conn->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <h1 class="mb-4">My Profile</h1>

    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- Profile Information -->
        <div class="col-lg-<?= $is_admin ? '12' : '6' ?>">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5>Personal Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($user['city'] ?? '') ?>">
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- My Orders - Show only for normal users -->
        <?php if(!$is_admin): ?>
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-success text-white">
                    <h5>My Recent Orders</h5>
                </div>
                <div class="card-body">
                    <?php
                    $orders = $conn->query("SELECT * FROM orders WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5");
                    if ($orders->num_rows > 0):
                        while($order = $orders->fetch_assoc()):
                    ?>
                        <div class="border-bottom pb-3 mb-3">
                            <div class="d-flex justify-content-between">
                                <strong>Order #<?= $order['id'] ?></strong>
                                <span class="badge bg-<?= $order['status'] == 'Delivered' ? 'success' : 'warning' ?>">
                                    <?= $order['status'] ?>
                                </span>
                            </div>
                            <small class="text-muted"><?= date('d M, Y', strtotime($order['created_at'])) ?></small>
                            <p class="mb-0">Total: <strong>৳ <?= number_format($order['total_amount']) ?></strong></p>
                        </div>
                    <?php endwhile; else: ?>
                        <p class="text-muted">No orders yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include("../includes/footer.php"); ?>