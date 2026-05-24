<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'category_manager' && $_SESSION['role'] !== 'admin') {
    header("Location: ../user/home.php");
    exit();
}

$moderator_id = $_SESSION['user_id'];

// ====================== AJAX STATUS UPDATE ======================
if (isset($_POST['ajax_update'])) {
    $product_id = intval($_POST['product_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['status']);
    $reason     = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));

    $sql = "UPDATE products SET 
                status = '$new_status',
                rejection_reason = " . ($new_status == 'rejected' ? "'$reason'" : "NULL") . "
            WHERE id = $product_id";
    
    if ($conn->query($sql)) {
        $conn->query("INSERT INTO product_moderation_logs 
                     (product_id, moderator_id, action, reason) 
                     VALUES ($product_id, $moderator_id, '$new_status', '$reason')");
        
        echo json_encode(['status' => 'success', 'message' => 'Updated successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    exit();
}

// ====================== FILTERS ======================
$status_filter = $_GET['status'] ?? 'pending';
$seller_filter = $_GET['seller_id'] ?? '';
$search        = $_GET['search'] ?? '';

// Build Query
$where = [];
if ($status_filter !== 'all') {
    $where[] = "p.status = '$status_filter'";
}
if (!empty($seller_filter)) {
    $where[] = "p.seller_id = " . intval($seller_filter);
}
if (!empty($search)) {
    $where[] = "p.name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Fetch Products
$products = $conn->query("
    SELECT p.*, u.name as seller_name, c.name as category_name 
    FROM products p 
    LEFT JOIN users u ON p.seller_id = u.id 
    LEFT JOIN categories c ON p.category_id = c.id 
    $where_clause
    ORDER BY p.id DESC
");

// Fetch Sellers for Filter
$sellers = $conn->query("SELECT id, name FROM users WHERE role = 'seller' ORDER BY name");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Product Moderation Center</h1>
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
                        <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Products</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="seller_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Sellers</option>
                        <?php while($s = $sellers->fetch_assoc()): ?>
                            <option value="<?= $s['id'] ?>" <?= $seller_filter == $s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search product name..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-body p-0">
            <table class="table table-hover mb-0" id="productsTable">
                <thead class="table-dark">
                    <tr>
                        <th>Image</th>
                        <th>Product</th>
                        <th>Seller</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($products->num_rows == 0): ?>
                        <tr><td colspan="7" class="text-center py-4">No products found.</td></tr>
                    <?php else: ?>
                        <?php while($p = $products->fetch_assoc()): ?>
                        <tr data-id="<?= $p['id'] ?>">
                            <td><img src="../<?= htmlspecialchars($p['image']) ?>" width="60" height="60" style="object-fit:cover;border-radius:6px;"></td>
                            <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                            <td><?= htmlspecialchars($p['seller_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                            <td>৳ <?= number_format($p['price']) ?></td>
                            <td class="status-cell">
                                <span class="badge bg-<?= ($p['status'] ?? 'pending') == 'approved' ? 'success' : (($p['status'] ?? 'pending') == 'rejected' ? 'danger' : 'warning') ?>">
                                    <?= ucfirst($p['status'] ?? 'pending') ?>
                                </span>
                                <?php if(($p['status'] ?? '') == 'rejected' && !empty($p['rejection_reason'])): ?>
                                    <br><small class="text-danger">Reason: <?= htmlspecialchars($p['rejection_reason']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="status-form">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <select name="status" class="form-select form-select-sm d-inline w-auto me-2">
                                        <option value="pending">Pending</option>
                                        <option value="approved">✅ Approve</option>
                                        <option value="rejected">❌ Reject</option>
                                    </select>
                                    <input type="text" name="reason" class="form-control form-control-sm d-inline w-50 me-2" 
                                           placeholder="Reason">
                                    <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// AJAX Update
document.querySelectorAll('.status-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_update', '1');

        const row = this.closest('tr');
        const statusCell = row.querySelector('.status-cell');

        fetch('manage_products.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const newStatus = formData.get('status');
                let badge = 'warning';
                if (newStatus === 'approved') badge = 'success';
                if (newStatus === 'rejected') badge = 'danger';

                statusCell.innerHTML = `
                    <span class="badge bg-${badge}">${newStatus.charAt(0).toUpperCase() + newStatus.slice(1)}</span>
                    ${newStatus === 'rejected' ? `<br><small class="text-danger">Reason: ${formData.get('reason')}</small>` : ''}
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
    setTimeout(() => div.remove(), 5000);
}
</script>

<?php include("../includes/footer.php"); ?>