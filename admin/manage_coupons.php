<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Add Coupon
if (isset($_POST['add_coupon'])) {
    $code = strtoupper(mysqli_real_escape_string($conn, trim($_POST['code'])));
    $type = $_POST['discount_type'];
    $value = floatval($_POST['discount_value']);
    $min_amount = floatval($_POST['min_order_amount'] ?? 0);
    $expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;

    $sql = "INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, expiry_date) 
            VALUES ('$code', '$type', $value, $min_amount, " . ($expiry ? "'$expiry'" : "NULL") . ")";
    
    if ($conn->query($sql)) {
        $success = "Coupon created successfully!";
    } else {
        $error = "Error: " . $conn->error;
    }
}

// Delete Coupon
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM coupons WHERE id = $id");
    header("Location: manage_coupons.php");
    exit();
}

$coupons = $conn->query("SELECT * FROM coupons ORDER BY created_at DESC");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Manage Coupons</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <!-- Create New Coupon -->
    <div class="card shadow mb-5">
        <div class="card-header bg-success text-white">
            <h5><i class="fas fa-plus"></i> Create New Coupon</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <input type="text" name="code" class="form-control text-uppercase" 
                           placeholder="COUPONCODE" maxlength="20" required>
                </div>
                <div class="col-md-2">
                    <select name="discount_type" class="form-select">
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed Amount (৳)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="number" step="0.01" name="discount_value" class="form-control" 
                           placeholder="Value" required>
                </div>
                <div class="col-md-2">
                    <input type="number" step="0.01" name="min_order_amount" class="form-control" 
                           placeholder="Min Order (৳)">
                </div>
                <div class="col-md-2">
                    <input type="date" name="expiry_date" class="form-control">
                </div>
                <div class="col-md-1">
                    <button type="submit" name="add_coupon" class="btn btn-success w-100">Create</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Coupons List -->
    <div class="card shadow">
        <div class="card-body">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Coupon Code</th>
                        <th>Type</th>
                        <th>Value</th>
                        <th>Min Order</th>
                        <th>Expiry Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($coupons->num_rows > 0): ?>
                        <?php while($c = $coupons->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($c['code']) ?></strong></td>
                            <td><?= ucfirst($c['discount_type']) ?></td>
                            <td><?= $c['discount_type'] == 'percentage' ? $c['discount_value'].'%' : '৳ '.$c['discount_value'] ?></td>
                            <td>৳ <?= number_format($c['min_order_amount']) ?></td>
                            <td><?= $c['expiry_date'] ? date('d M, Y', strtotime($c['expiry_date'])) : '<span class="text-muted">No expiry</span>' ?></td>
                            <td>
                                <span class="badge <?= $c['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <a href="?delete=<?= $c['id'] ?>" class="btn btn-danger btn-sm" 
                                   onclick="return confirm('Delete this coupon?')">Delete</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No coupons created yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>