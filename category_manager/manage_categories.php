<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'category_manager' && $_SESSION['role'] !== 'admin') {
    header("Location: ../user/home.php");
    exit();
}

$manager_id = $_SESSION['user_id'];

// ====================== AJAX UPDATE ======================
if (isset($_POST['ajax_update'])) {
    $category_id = intval($_POST['category_id']);
    $new_status  = mysqli_real_escape_string($conn, $_POST['status']);
    $reason      = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));

    $sql = "UPDATE categories SET 
                status = '$new_status',
                rejection_reason = " . ($new_status == 'rejected' ? "'$reason'" : "NULL") . "
            WHERE id = $category_id";
    
    if ($conn->query($sql)) {
        $conn->query("INSERT INTO product_moderation_logs 
                     (product_id, moderator_id, action, reason) 
                     VALUES (NULL, $manager_id, 'category_$new_status', '$reason')");
        
        echo json_encode(['status' => 'success', 'message' => 'Category updated successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    exit();
}

// ====================== ADD NEW CATEGORY ======================
if (isset($_POST['add_category'])) {
    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));

    if (empty($name)) {
        $error = "Category name is required!";
    } else {
        $sql = "INSERT INTO categories (name, description, status) 
                VALUES ('$name', '$description', 'pending')";
        
        if ($conn->query($sql)) {
            $success = "Category submitted successfully! Waiting for review.";
        } else {
            $error = "Error: " . $conn->error;
        }
    }
}

// ====================== FILTERS ======================
$status_filter = $_GET['status'] ?? 'pending';
$search = $_GET['search'] ?? '';

// Build Query
$where = [];
if ($status_filter !== 'all') {
    $where[] = "status = '$status_filter'";
}
if (!empty($search)) {
    $where[] = "name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Fetch Categories
$categories_result = $conn->query("SELECT * FROM categories $where_clause ORDER BY id DESC");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Category Moderation Center</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
    </div>

    <div id="alertContainer"></div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Categories</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search category name..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Apply Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add New Category -->
    <div class="card shadow mb-5">
        <div class="card-header bg-primary text-white">
            <h5>Add New Category</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-5">
                    <input type="text" name="name" class="form-control" placeholder="Category Name *" required>
                </div>
                <div class="col-md-5">
                    <input type="text" name="description" class="form-control" placeholder="Description (optional)">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="add_category" class="btn btn-success w-100">Submit Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Categories Table -->
    <div class="card shadow">
        <div class="card-body p-0">
            <table class="table table-hover mb-0" id="categoriesTable">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Category Name</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Products</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories_result && $categories_result->num_rows > 0): ?>
                        <?php while($cat = $categories_result->fetch_assoc()): 
                            $count_result = $conn->query("SELECT COUNT(*) as c FROM products WHERE category_id = " . intval($cat['id']));
                            $count = ($count_result) ? $count_result->fetch_assoc()['c'] : 0;
                        ?>
                        <tr data-id="<?= $cat['id'] ?>">
                            <td><?= $cat['id'] ?></td>
                            <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                            <td><?= htmlspecialchars($cat['description'] ?? '-') ?></td>
                            <td class="status-cell">
                                <span class="badge bg-<?= ($cat['status'] ?? 'pending') == 'approved' ? 'success' : (($cat['status'] ?? 'pending') == 'rejected' ? 'danger' : 'warning') ?>">
                                    <?= ucfirst($cat['status'] ?? 'pending') ?>
                                </span>
                                <?php if(($cat['status'] ?? '') == 'rejected' && !empty($cat['rejection_reason'])): ?>
                                    <br><small class="text-danger">Reason: <?= htmlspecialchars($cat['rejection_reason']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-primary"><?= $count ?></span></td>
                            <td>
                                <form class="status-form">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <select name="status" class="form-select form-select-sm d-inline w-auto me-2">
                                        <option value="pending">Pending</option>
                                        <option value="approved">✅ Approve</option>
                                        <option value="rejected">❌ Reject</option>
                                    </select>
                                    <input type="text" name="reason" class="form-control form-control-sm d-inline w-50 me-2" 
                                           placeholder="Reason (if rejecting)">
                                    <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-4">No categories found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// AJAX Update (Same as manage_products.php)
document.querySelectorAll('.status-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_update', '1');

        const row = this.closest('tr');
        const statusCell = row.querySelector('.status-cell');

        fetch('manage_categories.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const newStatus = formData.get('status');
                let badgeClass = newStatus === 'approved' ? 'success' : 
                               (newStatus === 'rejected' ? 'danger' : 'warning');

                statusCell.innerHTML = `
                    <span class="badge bg-${badgeClass}">${newStatus.charAt(0).toUpperCase() + newStatus.slice(1)}</span>
                    ${newStatus === 'rejected' && formData.get('reason') ? 
                        `<br><small class="text-danger">Reason: ${formData.get('reason')}</small>` : ''}
                `;
                showAlert(data.message, 'success');
            } else {
                showAlert(data.message, 'danger');
            }
        });
    });
});

function showAlert(msg, type) {
    const div = document.createElement('div');
    div.className = `alert alert-${type} alert-dismissible fade show`;
    div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.getElementById('alertContainer').appendChild(div);
    setTimeout(() => div.remove(), 4000);
}
</script>

<?php include("../includes/footer.php"); ?>