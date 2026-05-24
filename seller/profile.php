<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Update Shop Profile
if (isset($_POST['update_profile'])) {
    $shop_name = mysqli_real_escape_string($conn, trim($_POST['shop_name']));
    $seller_address = mysqli_real_escape_string($conn, trim($_POST['seller_address']));
    $phone = mysqli_real_escape_string($conn, trim($_POST['phone']));
    $city = mysqli_real_escape_string($conn, trim($_POST['city']));

    $sql = "UPDATE users SET 
            shop_name = '$shop_name',
            seller_address = '$seller_address',
            phone = '$phone',
            city = '$city'
            WHERE id = $user_id";

    if ($conn->query($sql)) {
        $success = "Shop profile updated successfully!";
    } else {
        $error = "Failed to update: " . $conn->error;
    }
}

$user = $conn->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Shop Settings & Profile</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Seller Dashboard</a>
    </div>

    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- Main Form -->
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-store"></i> Shop Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Shop Name <span class="text-danger">*</span></label>
                            <input type="text" name="shop_name" class="form-control form-control-lg" 
                                   value="<?= htmlspecialchars($user['shop_name'] ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Business Address <span class="text-danger">*</span></label>
                            <textarea name="seller_address" class="form-control" rows="4" required><?= htmlspecialchars($user['seller_address'] ?? '') ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" name="phone" class="form-control" 
                                       value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City</label>
                                <input type="text" name="city" class="form-control" 
                                       value="<?= htmlspecialchars($user['city'] ?? '') ?>">
                            </div>
                        </div>

                        <button type="submit" name="update_profile" class="btn btn-primary btn-lg px-5">
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-body text-center">
                    <h5 class="mb-4">Account Overview</h5>
                    
                    <?php if($user['seller_status'] == 'approved'): ?>
                        <span class="badge bg-success fs-5 p-3">✅ Approved Seller</span>
                    <?php elseif($user['seller_status'] == 'pending'): ?>
                        <span class="badge bg-warning fs-5 p-3">⏳ Pending Review</span>
                    <?php else: ?>
                        <span class="badge bg-danger fs-5 p-3">❌ Rejected</span>
                    <?php endif; ?>

                    <hr class="my-4">
                    <p><strong>Joined:</strong><br><?= date('d M, Y', strtotime($user['created_at'])) ?></p>
                    
                    <a href="manage_products.php" class="btn btn-success w-100 mt-3">Manage Products</a>
                    <a href="dashboard.php" class="btn btn-outline-secondary w-100 mt-2">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>