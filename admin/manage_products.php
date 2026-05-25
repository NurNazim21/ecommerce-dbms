<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'category_manager') {
    header("Location: ../user/home.php");
    exit();
}

// ── ADD / EDIT PRODUCT ────────────────────────────────────────────────────────
// Old (vulnerable):
//   $name = mysqli_real_escape_string($conn, $_POST['name'])
//   $conn->query("UPDATE products SET name='$name', ... WHERE id = $id")
//
// mysqli_real_escape_string() has two problems:
//   1. You must call it on every single value — miss one and you're vulnerable
//   2. It only escapes the string context — doesn't protect numeric columns or LIKE patterns
// Prepared statements handle all of this at the driver level, unconditionally.

if (isset($_POST['save_product'])) {
    $id          = intval($_POST['id'] ?? 0);
    // FIX: trim + strip tags for text fields, but DO NOT use mysqli_real_escape_string
    // — prepared statements handle escaping; double-escaping would corrupt the data
    $name        = trim(strip_tags($_POST['name']        ?? ''));
    $description = trim(strip_tags($_POST['description'] ?? ''));
    $price       = round(floatval($_POST['price'] ?? 0), 2);
    $stock       = max(0, intval($_POST['stock'] ?? 0));
    $brand       = trim(strip_tags($_POST['brand']       ?? ''));
    $category_id = intval($_POST['category_id'] ?? 0);

    // Validate required fields before hitting the DB
    if (empty($name) || $price <= 0 || $category_id === 0) {
        $error = "Name, price, and category are required.";
    } else {
        $image_path = trim($_POST['old_image'] ?? 'https://via.placeholder.com/600x400?text=No+Image');

        // ── Image upload ──────────────────────────────────────────────────────
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $target_dir = "../assets/images/products/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }

            $tmp       = $_FILES['image']['tmp_name'];
            $file_ext  = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            // FIX: check actual MIME type from the file content, not just the extension
            $finfo     = finfo_open(FILEINFO_MIME_TYPE);
            $mime      = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($file_ext, $allowed, true) || !in_array($mime, $allowed_mimes, true)) {
                $error = "Invalid image type. Allowed: jpg, jpeg, png, gif, webp.";
            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $error = "Image must be under 5 MB.";
            } else {
                // Use a cryptographically random filename — prevents path traversal
                $new_name  = bin2hex(random_bytes(8)) . '.' . $file_ext;
                $dest      = $target_dir . $new_name;
                if (move_uploaded_file($tmp, $dest)) {
                    $image_path = "assets/images/products/" . $new_name;
                } else {
                    $error = "Failed to save image.";
                }
            }
        }

        if (!isset($error)) {
            if ($id > 0) {
                // UPDATE — 7 parameters: s s d i s i i
                // order matches the ? placeholders left-to-right
                $stmt = $conn->prepare("
                    UPDATE products
                    SET name=?, description=?, price=?, stock=?, brand=?, category_id=?, image=?
                    WHERE id=?
                ");
                // "ssdissi i" → name(s) desc(s) price(d) stock(i) brand(s) cat(i) image(s) id(i)
                $stmt->bind_param("ssdiissi",
                    $name, $description, $price, $stock, $brand, $category_id, $image_path, $id
                );
                $label = "Product updated successfully!";
            } else {
                // INSERT — admin-added products go straight to 'approved'
                $status = 'approved';
                $stmt   = $conn->prepare("
                    INSERT INTO products
                        (name, description, price, stock, brand, category_id, image, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                // "ssdiisss" → name desc price stock brand cat image status
                $stmt->bind_param("ssdiisss",
                    $name, $description, $price, $stock, $brand, $category_id, $image_path, $status
                );
                $label = "Product added successfully!";
            }

            if ($stmt->execute()) {
                $stmt->close();
                header("Location: manage_products.php?success=1");
                exit();
            } else {
                // Log the real error; show the user a generic message
                error_log("Product save failed: " . $stmt->error);
                $error = "Could not save product. Please try again.";
                $stmt->close();
            }
        }
    }
}

// ── DELETE PRODUCT ────────────────────────────────────────────────────────────
// Old: $conn->query("DELETE FROM products WHERE id = $id")
if (isset($_GET['delete'])) {
    $id   = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: manage_products.php");
    exit();
}

// ── EDIT MODE ─────────────────────────────────────────────────────────────────
// Old: $conn->query("SELECT * FROM products WHERE id = $edit_id")
$edit_mode    = false;
$edit_product = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt    = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($edit_product) {
        $edit_mode = true;
    }
}

// ── FILTERS ───────────────────────────────────────────────────────────────────
// Old (vulnerable):
//   $filter_status = ... trim($_GET['status'])
//   $where_clauses[] = "p.status = '$filter_status'"     ← raw string in SQL
//   $where_clauses[] = "p.name LIKE '%$filter_search%'"  ← raw search in SQL
//
// Same dynamic-filter pattern as manage_orders.php:
// collect placeholders + values separately, bind all at once.

$valid_statuses_prod = ['pending', 'approved', 'rejected'];

$filter_category = intval($_GET['category'] ?? 0);
$filter_status   = $_GET['status']  ?? '';
$filter_search   = trim($_GET['search'] ?? '');

// Whitelist status
if (!in_array($filter_status, $valid_statuses_prod, true)) {
    $filter_status = '';
}

$where_clauses = ["1=1"];
$bind_types    = "";
$bind_values   = [];

if ($filter_category > 0) {
    $where_clauses[] = "p.category_id = ?";
    $bind_types     .= "i";
    $bind_values[]   = $filter_category;
}
if ($filter_status !== '') {
    $where_clauses[] = "p.status = ?";
    $bind_types     .= "s";
    $bind_values[]   = $filter_status;
}
if ($filter_search !== '') {
    $like            = "%{$filter_search}%";
    $where_clauses[] = "(p.name LIKE ? OR p.brand LIKE ? OR p.description LIKE ?)";
    $bind_types     .= "sss";
    $bind_values[]   = $like;
    $bind_values[]   = $like;
    $bind_values[]   = $like;
}

$where_sql = implode(' AND ', $where_clauses);

$products_sql = "
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE {$where_sql}
    ORDER BY p.id DESC
";

$stmt = $conn->prepare($products_sql);
if ($bind_types !== '') {
    $params = array_merge([$bind_types], $bind_values);
    $refs   = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// ── STATUS COUNTS ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM products GROUP BY status");
$stmt->execute();
$count_result = $stmt->get_result();
$stmt->close();

$counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
while ($crow = $count_result->fetch_assoc()) {
    if (isset($counts[$crow['status']])) {
        $counts[$crow['status']] = $crow['cnt'];
    }
    $counts['all'] += $crow['cnt'];
}

// ── CATEGORIES (for the form dropdown and filter) ─────────────────────────────
$stmt = $conn->prepare("SELECT * FROM categories ORDER BY name");
$stmt->execute();
$categories_result = $stmt->get_result();
$stmt->close();
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row;
}
?>

<?php include("../includes/header.php"); ?>

<style>
.products-page { background:#f4f6fb; min-height:100vh; padding:36px 0 60px; }
.page-header-card { background:linear-gradient(135deg,#1a1a2e 0%,#16213e 60%,#0f3460 100%); border-radius:16px; padding:28px 32px; margin-bottom:28px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px; box-shadow:0 8px 32px rgba(15,52,96,.18); }
.page-header-card h1 { color:#fff; font-size:1.65rem; font-weight:700; margin:0; }
.page-header-card .subtitle { color:#a8b8d8; font-size:.88rem; margin-top:3px; }
.header-actions { display:flex; gap:10px; flex-wrap:wrap; }
.header-actions .btn { font-size:.85rem; padding:8px 18px; border-radius:8px; font-weight:600; border:none; }
.btn-cat { background:#17a2b8; color:#fff; } .btn-cat:hover { background:#138496; color:#fff; }
.btn-back { background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.2)!important; } .btn-back:hover { background:rgba(255,255,255,.22); color:#fff; }
.stat-pills { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px; }
.stat-pill { display:flex; align-items:center; gap:8px; background:#fff; border-radius:50px; padding:8px 18px; font-size:.85rem; font-weight:600; color:#444; box-shadow:0 2px 8px rgba(0,0,0,.07); text-decoration:none; border:2px solid transparent; transition:all .18s; }
.stat-pill:hover { transform:translateY(-1px); }
.stat-pill.active { border-color:currentColor; }
.stat-pill .dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
.stat-pill.pill-all { color:#495057; } .stat-pill.pill-all .dot { background:#6c757d; } .stat-pill.pill-all.active { border-color:#6c757d; background:#f0f0f0; }
.stat-pill.pill-approved { color:#1a7a4a; } .stat-pill.pill-approved .dot { background:#28a745; } .stat-pill.pill-approved.active { border-color:#28a745; background:#e9f7ef; }
.stat-pill.pill-pending { color:#856404; } .stat-pill.pill-pending .dot { background:#ffc107; } .stat-pill.pill-pending.active { border-color:#ffc107; background:#fff8e1; }
.stat-pill.pill-rejected { color:#842029; } .stat-pill.pill-rejected .dot { background:#dc3545; } .stat-pill.pill-rejected.active { border-color:#dc3545; background:#fdecea; }
.stat-pill .count { background:#f0f0f0; border-radius:50px; padding:1px 9px; font-size:.78rem; color:#555; font-weight:700; }
.filter-card { background:#fff; border-radius:14px; padding:18px 22px; box-shadow:0 2px 12px rgba(0,0,0,.07); margin-bottom:24px; display:flex; gap:14px; flex-wrap:wrap; align-items:center; }
.filter-card .search-wrap { flex:1; min-width:200px; position:relative; }
.filter-card .search-wrap input { padding-left:38px; border-radius:8px; border:1.5px solid #dee2e6; height:40px; font-size:.9rem; width:100%; outline:none; }
.filter-card .search-wrap input:focus { border-color:#0f3460; }
.filter-card .search-wrap .search-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#aaa; font-size:.95rem; }
.filter-card select { border-radius:8px; border:1.5px solid #dee2e6; height:40px; font-size:.88rem; padding:0 12px; outline:none; min-width:160px; }
.btn-filter { background:#0f3460; color:#fff; border:none; border-radius:8px; height:40px; padding:0 22px; font-weight:600; font-size:.88rem; cursor:pointer; }
.btn-filter:hover { background:#1a5276; }
.btn-reset { background:#f0f0f0; color:#555; border:none; border-radius:8px; height:40px; padding:0 16px; font-weight:600; font-size:.85rem; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
.active-filter-badge { display:inline-flex; align-items:center; gap:5px; background:#e8f0fe; color:#1a5276; border-radius:50px; padding:4px 12px; font-size:.78rem; font-weight:600; }
.form-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); margin-bottom:28px; overflow:hidden; }
.form-card .card-header { padding:15px 22px; font-weight:700; font-size:1rem; border-bottom:none; }
.form-card .card-body { padding:24px 22px; }
.form-card .form-control, .form-card .form-select { border-radius:8px; border:1.5px solid #dee2e6; font-size:.9rem; }
.btn-submit { background:linear-gradient(90deg,#1a5276,#0f3460); color:#fff; border:none; border-radius:8px; padding:10px 32px; font-weight:700; font-size:.95rem; }
.btn-submit:hover { opacity:.9; color:#fff; }
.table-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); overflow:hidden; }
.table-card .table { margin-bottom:0; }
.table-card .table thead th { background:#1a1a2e; color:#c8d4e8; font-size:.8rem; text-transform:uppercase; letter-spacing:.7px; padding:13px 16px; border:none; font-weight:600; }
.table-card .table tbody tr { border-bottom:1px solid #f2f4f8; transition:background .12s; }
.table-card .table tbody tr:hover { background:#f8f9ff; }
.table-card .table tbody td { padding:12px 16px; vertical-align:middle; font-size:.9rem; border:none; }
.product-img { width:64px; height:64px; object-fit:cover; border-radius:10px; border:2px solid #eef0f5; }
.product-name { font-weight:700; color:#1a1a2e; font-size:.92rem; }
.product-brand { color:#888; font-size:.8rem; margin-top:1px; }
.price-tag { font-weight:700; color:#1a7a4a; font-size:.95rem; }
.stock-low { color:#c0392b; font-weight:600; }
.badge-status { border-radius:50px; padding:4px 12px; font-size:.78rem; font-weight:700; }
.rejection-reason { display:block; margin-top:4px; font-size:.75rem; color:#c0392b; font-style:italic; }
.btn-edit-sm { background:#fff3cd; color:#856404; border:1.5px solid #ffc107; border-radius:7px; padding:5px 13px; font-size:.8rem; font-weight:700; text-decoration:none; display:inline-block; }
.btn-edit-sm:hover { background:#ffc107; color:#333; }
.btn-del-sm { background:#fde8e8; color:#c0392b; border:1.5px solid #e74c3c; border-radius:7px; padding:5px 13px; font-size:.8rem; font-weight:700; text-decoration:none; display:inline-block; margin-left:5px; }
.btn-del-sm:hover { background:#e74c3c; color:#fff; }
.empty-state { padding:60px 20px; text-align:center; }
.empty-state .icon { font-size:3.5rem; margin-bottom:14px; color:#ccc; }
.empty-state h5 { color:#888; font-weight:600; }
.results-info { font-size:.85rem; color:#888; display:flex; align-items:center; gap:8px; padding:14px 18px 0; }
.results-info strong { color:#333; }
.alert-custom { border-radius:10px; font-size:.9rem; font-weight:500; padding:13px 18px; margin-bottom:18px; }
</style>

<div class="products-page">
<div class="container">

    <div class="page-header-card">
        <div>
            <h1>📦 <?= $edit_mode ? 'Edit Product' : 'Manage Products' ?></h1>
            <div class="subtitle">
                <?= $_SESSION['role'] === 'admin' ? 'Admin Panel — All Products' : 'Category Manager — Product Overview' ?>
            </div>
        </div>
        <div class="header-actions">
            <a href="manage_categories.php" class="btn btn-cat">⚙ Manage Categories</a>
            <a href="dashboard.php" class="btn btn-back">← Dashboard</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-custom shadow-sm">✅ Product saved successfully!</div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-custom shadow-sm">❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Add / Edit form -->
    <div class="form-card">
        <div class="card-header <?= $edit_mode ? 'bg-warning text-dark' : 'bg-primary text-white' ?>">
            <?= $edit_mode ? '✏️ Edit Product' : '＋ Add New Product' ?>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" class="row g-3">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="id"        value="<?= intval($edit_product['id']) ?>">
                    <input type="hidden" name="old_image" value="<?= htmlspecialchars($edit_product['image'], ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>

                <div class="col-md-4">
                    <input type="text" name="name" class="form-control" placeholder="Product Name"
                           value="<?= $edit_mode ? htmlspecialchars($edit_product['name'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                </div>
                <div class="col-md-3">
                    <input type="text" name="brand" class="form-control" placeholder="Brand"
                           value="<?= $edit_mode ? htmlspecialchars($edit_product['brand'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">
                </div>
                <div class="col-md-2">
                    <input type="number" step="0.01" min="0.01" name="price" class="form-control" placeholder="Price"
                           value="<?= $edit_mode ? htmlspecialchars($edit_product['price'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                </div>
                <div class="col-md-2">
                    <input type="number" min="0" name="stock" class="form-control" placeholder="Stock"
                           value="<?= $edit_mode ? intval($edit_product['stock']) : '' ?>" required>
                </div>
                <div class="col-md-3">
                    <select name="category_id" class="form-select" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= intval($cat['id']) ?>"
                                <?= $edit_mode && $edit_product['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <textarea name="description" class="form-control" rows="3" placeholder="Description"><?=
                        $edit_mode ? htmlspecialchars($edit_product['description'] ?? '', ENT_QUOTES, 'UTF-8') : ''
                    ?></textarea>
                </div>
                <div class="col-md-4">
                    <?php if ($edit_mode && !empty($edit_product['image'])): ?>
                        <div class="mb-2">
                            <img src="../<?= htmlspecialchars($edit_product['image'], ENT_QUOTES, 'UTF-8') ?>"
                                 class="img-thumbnail" style="max-height:120px;border-radius:10px">
                        </div>
                    <?php endif; ?>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                    <small class="text-muted">Allowed: jpg, jpeg, png, gif, webp | Max 5 MB</small>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" name="save_product" class="btn-submit btn">
                        <?= $edit_mode ? '💾 Update Product' : '＋ Add New Product' ?>
                    </button>
                    <?php if ($edit_mode): ?>
                        <a href="manage_products.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Stat pills -->
    <?php
    $pill_params = function($status) use ($filter_category, $filter_search) {
        $p = [];
        if ($status)          $p[] = 'status='   . urlencode($status);
        if ($filter_category) $p[] = 'category=' . $filter_category;
        if ($filter_search)   $p[] = 'search='   . urlencode($filter_search);
        return 'manage_products.php' . ($p ? '?' . implode('&', $p) : '');
    };
    ?>
    <div class="stat-pills">
        <a href="<?= $pill_params('') ?>"         class="stat-pill pill-all      <?= !$filter_status ? 'active' : '' ?>"><span class="dot"></span> All      <span class="count"><?= $counts['all']      ?></span></a>
        <a href="<?= $pill_params('approved') ?>" class="stat-pill pill-approved <?= $filter_status === 'approved' ? 'active' : '' ?>"><span class="dot"></span> Approved <span class="count"><?= $counts['approved'] ?></span></a>
        <a href="<?= $pill_params('pending') ?>"  class="stat-pill pill-pending  <?= $filter_status === 'pending'  ? 'active' : '' ?>"><span class="dot"></span> Pending  <span class="count"><?= $counts['pending']  ?></span></a>
        <a href="<?= $pill_params('rejected') ?>" class="stat-pill pill-rejected <?= $filter_status === 'rejected' ? 'active' : '' ?>"><span class="dot"></span> Rejected <span class="count"><?= $counts['rejected'] ?></span></a>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="manage_products.php" class="filter-card">
        <?php if ($edit_mode): ?>
            <input type="hidden" name="edit" value="<?= intval($_GET['edit']) ?>">
        <?php endif; ?>
        <div class="search-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" name="search" placeholder="Search by name, brand or description..."
                   value="<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <select name="category">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= intval($cat['id']) ?>" <?= $filter_category == $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Statuses</option>
            <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>✅ Approved</option>
            <option value="pending"  <?= $filter_status === 'pending'  ? 'selected' : '' ?>>⏳ Pending</option>
            <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>❌ Rejected</option>
        </select>
        <button type="submit" class="btn-filter">Apply Filters</button>
        <?php if ($filter_search || $filter_category || $filter_status): ?>
            <a href="manage_products.php" class="btn-reset">✕ Reset</a>
        <?php endif; ?>
    </form>

    <?php if ($filter_search || $filter_category || $filter_status): ?>
    <div class="mb-3 d-flex flex-wrap gap-2">
        <?php if ($filter_search): ?><span class="active-filter-badge">🔍 "<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>"</span><?php endif; ?>
        <?php if ($filter_category > 0):
            $cat_name = '';
            foreach ($categories as $cat) { if ($cat['id'] == $filter_category) { $cat_name = $cat['name']; break; } }
        ?><span class="active-filter-badge">📂 <?= htmlspecialchars($cat_name, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        <?php if ($filter_status): ?><span class="active-filter-badge">🏷 <?= htmlspecialchars(ucfirst($filter_status), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Products table -->
    <div class="table-card">
        <?php $total = $result->num_rows; ?>
        <div class="results-info">
            Showing <strong><?= $total ?></strong> product<?= $total != 1 ? 's' : '' ?>
            <?php if ($filter_search || $filter_category || $filter_status): ?>&nbsp;— filtered results<?php endif; ?>
        </div>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Image</th><th>Product</th><th>Price</th><th>Stock</th><th>Category</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if ($total > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                <?php
                    $status      = $row['status'] ?? 'pending';
                    $badge_class = match($status) { 'approved' => 'bg-success', 'rejected' => 'bg-danger', default => 'bg-warning text-dark' };
                    $stock_class = $row['stock'] <= 5 ? 'stock-low' : '';
                ?>
                <tr>
                    <td><img src="../<?= htmlspecialchars($row['image'], ENT_QUOTES, 'UTF-8') ?>" class="product-img" alt=""></td>
                    <td>
                        <div class="product-name"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($row['brand'])): ?>
                            <div class="product-brand">🏷 <?= htmlspecialchars($row['brand'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="price-tag">৳ <?= number_format($row['price']) ?></span></td>
                    <td>
                        <span class="<?= $stock_class ?>"><?= intval($row['stock']) ?>
                            <?php if ($row['stock'] <= 5): ?><br><small style="font-size:.72rem">⚠ Low</small><?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($row['category_name'])): ?>
                            <span style="background:#eef0f8;color:#1a1a2e;border-radius:6px;padding:3px 10px;font-size:.8rem;font-weight:600">
                                <?= htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?><span style="color:#aaa">—</span><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-status <?= $badge_class ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($status === 'rejected' && !empty($row['rejection_reason'])): ?>
                            <span class="rejection-reason">↳ <?= htmlspecialchars($row['rejection_reason'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?edit=<?= intval($row['id']) ?>" class="btn-edit-sm">✏ Edit</a>
                        <a href="?delete=<?= intval($row['id']) ?>" class="btn-del-sm"
                           onclick="return confirm('Delete this product?')">🗑 Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7">
                    <div class="empty-state">
                        <div class="icon">📦</div>
                        <h5><?= ($filter_search || $filter_category || $filter_status) ? 'No products match your filters' : 'No products found' ?></h5>
                        <p><?= ($filter_search || $filter_category || $filter_status) ? 'Try adjusting or resetting your filters.' : 'Use the form above to add your first product.' ?></p>
                        <?php if ($filter_search || $filter_category || $filter_status): ?>
                            <a href="manage_products.php" class="btn btn-sm btn-outline-secondary mt-2">Reset Filters</a>
                        <?php endif; ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>
</div>

<?php include("../includes/footer.php"); ?>