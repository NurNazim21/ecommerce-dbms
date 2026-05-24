<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

// Add New Category
if (isset($_POST['add_category'])) {
    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));

    if (empty($name)) {
        $error = "Category name is required!";
    } else {
        $sql = "INSERT INTO categories (name, description) VALUES ('$name', '$description')";
        
        if ($conn->query($sql)) {
            $success = "Category added successfully!";
        } else {
            $error = "Error: " . $conn->error;
        }
    }
}

// Fetch All Categories
$categories_result = $conn->query("SELECT * FROM categories ORDER BY name");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Manage Categories</h1>
        <a href="manage_products.php" class="btn btn-secondary">← Back to My Products</a>
    </div>

    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <!-- Add New Category Form -->
    <div class="card shadow mb-5">
        <div class="card-header bg-info text-white">
            <h5><i class="fas fa-plus"></i> Add New Category</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-5">
                    <input type="text" name="name" class="form-control" placeholder="Category Name *" required>
                </div>
                <div class="col-md-5">
                    <input type="text" name="description" class="form-control" placeholder="Description (optional)">
                </div>
                <div class="col-md-2">
                    <button type="submit" name="add_category" class="btn btn-success w-100">Add Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Categories List -->
    <div class="card shadow">
        <div class="card-body">
            <table class="table table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Category Name</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($categories_result && $categories_result->num_rows > 0): ?>
                        <?php while($cat = $categories_result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $cat['id'] ?></td>
                            <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                            <td><?= htmlspecialchars($cat['description'] ?? 'No description available.') ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="text-center py-4 text-muted">No categories found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>