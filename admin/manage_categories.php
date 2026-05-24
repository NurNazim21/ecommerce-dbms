<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Add Category
if (isset($_POST['add_category'])) {
    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description']));
    
    $sql = "INSERT INTO categories (name, description) VALUES ('$name', '$description')";
    
    if ($conn->query($sql)) {
        $success = "Category added successfully!";
    } else {
        $error = "Error: " . $conn->error;
    }
}

// Delete Category
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    $check = $conn->query("SELECT COUNT(*) as count FROM products WHERE category_id = $id")->fetch_assoc();
    
    if ($check['count'] == 0) {
        $conn->query("DELETE FROM categories WHERE id = $id");
        $success = "Category deleted successfully!";
    } else {
        $error = "Cannot delete! Category has products.";
    }
}

// Fetch Categories - Use clear variable name
$categories_result = $conn->query("SELECT * FROM categories ORDER BY name");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Manage Categories</h1>
        <a href="manage_products.php" class="btn btn-secondary">← Back to Products</a>
    </div>

    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <!-- Add New Category -->
    <div class="card mb-5 shadow">
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
                    <button type="submit" name="add_category" class="btn btn-success w-100">Add</button>
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
                        <th>Products</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($categories_result && $categories_result->num_rows > 0): ?>
                        <?php while($cat = $categories_result->fetch_assoc()): 
                            $count = $conn->query("SELECT COUNT(*) as c FROM products WHERE category_id=".$cat['id'])->fetch_assoc()['c'];
                        ?>
                        <tr>
                            <td><?= $cat['id'] ?></td>
                            <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                            <td><?= htmlspecialchars($cat['description'] ?? '-') ?></td>
                            <td><span class="badge bg-primary"><?= $count ?></span></td>
                            <td>
                                <?php if($count == 0): ?>
                                    <a href="?delete=<?= $cat['id'] ?>" class="btn btn-danger btn-sm" 
                                       onclick="return confirm('Are you sure?')">Delete</a>
                                <?php else: ?>
                                    <small class="text-muted">Protected</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">No categories found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>