<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'category_manager' && $_SESSION['role'] !== 'admin') {
    header("Location: ../user/home.php");
    exit();
}

// === Professional Statistics ===
$total_categories   = $conn->query("SELECT COUNT(*) as c FROM categories")->fetch_assoc()['c'] ?? 0;
$pending_categories = $conn->query("SELECT COUNT(*) as c FROM categories WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0;
$approved_categories = $conn->query("SELECT COUNT(*) as c FROM categories WHERE status = 'approved'")->fetch_assoc()['c'] ?? 0;

$total_products     = $conn->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'] ?? 0;
$pending_products   = $conn->query("SELECT COUNT(*) as c FROM products WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0;
$approved_products  = $conn->query("SELECT COUNT(*) as c FROM products WHERE status = 'approved'")->fetch_assoc()['c'] ?? 0;
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">Category Manager Dashboard</h1>
        <span class="badge bg-info fs-6">Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'Manager') ?></span>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-5">
        <!-- Categories Stats -->
        <div class="col-md-3">
            <div class="card shadow h-100 border-0">
                <div class="card-body text-center">
                    <i class="fas fa-tags fa-3x text-info mb-3"></i>
                    <h2 class="fw-bold text-info"><?= $total_categories ?></h2>
                    <p class="text-muted mb-1">Total Categories</p>
                    <small class="text-success"><?= $approved_categories ?> Approved</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow h-100 border-0 bg-warning bg-opacity-10">
                <div class="card-body text-center">
                    <i class="fas fa-clock fa-3x text-warning mb-3"></i>
                    <h2 class="fw-bold text-warning"><?= $pending_categories ?></h2>
                    <p class="text-muted mb-0">Pending Categories</p>
                </div>
            </div>
        </div>

        <!-- Products Stats -->
        <div class="col-md-3">
            <div class="card shadow h-100 border-0">
                <div class="card-body text-center">
                    <i class="fas fa-box fa-3x text-primary mb-3"></i>
                    <h2 class="fw-bold text-primary"><?= $total_products ?></h2>
                    <p class="text-muted mb-1">Total Products</p>
                    <small class="text-success"><?= $approved_products ?> Approved</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow h-100 border-0 bg-warning bg-opacity-10">
                <div class="card-body text-center">
                    <i class="fas fa-clock fa-3x text-warning mb-3"></i>
                    <h2 class="fw-bold text-warning"><?= $pending_products ?></h2>
                    <p class="text-muted mb-0">Pending Products</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Action Cards -->
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card shadow h-100 hover-card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-tags fa-4x text-info mb-4"></i>
                    <h4 class="mb-3">Manage Categories</h4>
                    <p class="text-muted">Review, approve or reject category requests</p>
                    <a href="manage_categories.php" class="btn btn-info btn-lg px-5 py-3 mt-2">
                        <i class="fas fa-cogs me-2"></i> Go to Categories
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card shadow h-100 hover-card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-tasks fa-4x text-primary mb-4"></i>
                    <h4 class="mb-3">Moderate Products</h4>
                    <p class="text-muted">Review seller-submitted products</p>
                    <a href="manage_products.php" class="btn btn-primary btn-lg px-5 py-3 mt-2">
                        <i class="fas fa-clipboard-check me-2"></i> Review Products
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.hover-card {
    transition: all 0.3s ease;
}
.hover-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.12) !important;
}
</style>

<?php include("../includes/footer.php"); ?>