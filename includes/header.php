<?php
// ── Session: always start first ───────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── DB: include only if $conn not already set ────────────────────────────────
if (!isset($conn)) {
    $db_path = __DIR__ . "/../config/db.php";
    if (file_exists($db_path)) include($db_path);
}

// ── Role check ────────────────────────────────────────────────────────────────
$is_admin  = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$is_seller = isset($_SESSION['role']) && $_SESSION['role'] === 'seller';
$is_category_manager = isset($_SESSION['role']) && $_SESSION['role'] === 'category_manager';
$is_regular_user     = isset($_SESSION['user_id']) && !$is_admin && !$is_seller && !$is_category_manager;
// ↑ Only regular users get cart/wishlist/orders

// ── Cart count ────────────────────────────────────────────────────────────────
$cart_count  = 0;
$notif_count = 0;   // UPGRADE: unread notification badge
if (isset($_SESSION['user_id'])) {
    $user_id    = intval($_SESSION['user_id']);
 
    $cart_query = $conn->query("SELECT SUM(quantity) as total FROM cart WHERE user_id = $user_id");
    $cart_count = $cart_query->fetch_assoc()['total'] ?? 0;
 
    // UPGRADE: unread notifications count — used for bell badge in navbar
    $notif_q    = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0");
    $notif_q->bind_param("i", $user_id);
    $notif_q->execute();
    $notif_count = intval($notif_q->get_result()->fetch_assoc()['c']);
    $notif_q->close();
}

// ── Categories from DB (Only Approved) ───────────────────────────────────────
$categories = [];
$cat_result = $conn->query("SELECT id, name FROM categories 
                           WHERE status = 'approved' 
                           ORDER BY name");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// ── Products grouped by category_id ──────────────────────────────────────────
$products_by_cat = [];
$prod_result = $conn->query(
    "SELECT id, name, price, category_id
     FROM products
WHERE stock > 0
AND status='approved'
     ORDER BY name ASC"
);
while ($p = $prod_result->fetch_assoc()) {
    $products_by_cat[$p['category_id']][] = $p;
}

$first_name = isset($_SESSION['name']) ? htmlspecialchars(explode(' ', $_SESSION['name'])[0]) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Shop BD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        /* ── Navbar ──────────────────────────────────────────────────────── */
        .navbar-amazon { background-color: #131921; padding: 10px 0; overflow: visible !important; position: sticky; top: 0; z-index: 1030; }
        .navbar-amazon .container-fluid { overflow: visible; }
        .navbar-filter { z-index: 1020; position: sticky; top: 56px; }
        .logo { font-size: 1.9rem; font-weight: 700; color: #ff9900; }
        .all-btn {
            color: white; font-weight: 600;
            border: 2px solid transparent; border-radius: 4px;
            padding: 4px 8px; transition: border-color .15s;
        }
        .all-btn:hover { color: white; border-color: white; }

        /* ── Admin Panel button ───────────────────────────────────────────── */
        .admin-panel-btn {
            background: linear-gradient(135deg, #ff9900, #e68a00);
            color: #131921 !important;
            font-weight: 700;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: .85rem;
            text-decoration: none;
            display: flex; align-items: center; gap: 6px;
            transition: background .15s, box-shadow .15s;
            box-shadow: 0 2px 6px rgba(255,153,0,.35);
        }
        .admin-panel-btn:hover {
            background: linear-gradient(135deg, #ffb347, #ff9900);
            color: #131921 !important;
            box-shadow: 0 3px 10px rgba(255,153,0,.5);
        }

        /* ── Account hover popup ─────────────────────────────────────────── */
        .account-wrapper { position: relative; }

        .account-trigger {
            color: white;
            text-decoration: none;
            cursor: pointer;
            background: none;
            border: 2px solid transparent;
            border-radius: 4px;
            padding: 4px 8px;
            transition: border-color .15s;
            display: flex; flex-direction: column; align-items: flex-start;
            line-height: 1.25;
        }
        .account-wrapper:hover .account-trigger { border-color: white; }
        .account-trigger small  { font-size: .7rem;  color: #ccc; }
        .account-trigger strong { font-size: .85rem; color: white; }

        /* The popup card */
        .account-popup {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 340px;
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 6px 30px rgba(0,0,0,.22);
            z-index: 2000;
            padding: 20px;
            opacity: 0;
            transform: translateY(-8px);
            transition: opacity .18s ease, transform .18s ease;
            pointer-events: none;
        }
        /* Arrow tip */
        .account-popup::before {
            content: '';
            position: absolute;
            top: -7px; right: 28px;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-bottom: 8px solid #fff;
        }

        /* Sign-in button */
        .popup-signin-btn {
            display: block; width: 100%;
            background: #ff9900; color: #131921;
            font-weight: 700; font-size: .95rem;
            border: none; border-radius: 6px;
            padding: 9px 0; text-align: center;
            text-decoration: none;
            transition: background .15s; margin-bottom: 10px;
        }
        .popup-signin-btn:hover { background: #ffb347; color: #131921; }

        .popup-new-customer {
            text-align: center; font-size: .82rem; color: #555;
            border-bottom: 1px solid #e8e8e8;
            padding-bottom: 14px; margin-bottom: 14px;
        }
        .popup-new-customer a { color: #0066c0; text-decoration: none; }
        .popup-new-customer a:hover { color: #c45500; text-decoration: underline; }

        /* Two-column layout for logged-in */
        .popup-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }

        .popup-col-title {
            font-size: .78rem; font-weight: 700; color: #333;
            margin-bottom: 8px; padding-bottom: 4px;
            border-bottom: 1px solid #e8e8e8;
        }
        .popup-link {
            display: block; font-size: .83rem; color: #333;
            text-decoration: none; padding: 4px 0; transition: color .12s;
        }
        .popup-link:hover { color: #c45500; }

        .popup-divider {
            grid-column: 1 / -1;
            border: none; border-top: 1px solid #e8e8e8;
            margin: 12px 0 8px;
        }
        .popup-logout {
            grid-column: 1 / -1;
            display: flex; align-items: center; gap: 8px;
            background: #f5f5f5; border: 1px solid #ddd;
            border-radius: 5px; padding: 8px 14px;
            font-size: .87rem; font-weight: 600; color: #b12704;
            text-decoration: none; transition: background .13s, color .13s;
        }
        .popup-logout:hover { background: #ffe8e0; color: #b12704; border-color: #f5a488; }

        /* ── Sidebar shell ───────────────────────────────────────────────── */
        #allMenu {
            width: 320px; background-color: #232f3e; color: white;
            overflow: hidden; position: fixed; top: 0; left: 0;
            height: 100vh; z-index: 1055;
            transform: translateX(-100%);
            transition: transform .28s cubic-bezier(.4,0,.2,1);
            display: flex; flex-direction: column;
        }
        #allMenu.show { transform: translateX(0); }

        #sidebarBackdrop {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.55); z-index: 1054;
        }
        #sidebarBackdrop.show { display: block; }

        .menu-panel {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            background-color: #232f3e;
            transition: transform .26s cubic-bezier(.4,0,.2,1);
            will-change: transform;
        }
        #mainPanel { transform: translateX(0); }
        #subPanel  { transform: translateX(100%); }
        #allMenu.sub-open #mainPanel { transform: translateX(-30%); }
        #allMenu.sub-open #subPanel  { transform: translateX(0); }

        .panel-header {
            display: flex; align-items: center; gap: 10px;
            padding: 14px 16px; background: #37475a;
            font-size: 1rem; font-weight: 700; flex-shrink: 0;
            border-bottom: 1px solid #3a4f63;
        }
        .panel-header .back-btn {
            background: none; border: none; color: white;
            font-size: 1.1rem; cursor: pointer; padding: 0 4px; line-height: 1;
        }
        .panel-header .close-btn {
            background: none; border: none; color: #aaa;
            font-size: 1.2rem; cursor: pointer; margin-left: auto; line-height: 1;
        }
        .panel-header .close-btn:hover { color: white; }

        .menu-scroll { overflow-y: auto; flex: 1; }
        .menu-scroll::-webkit-scrollbar { width: 4px; }
        .menu-scroll::-webkit-scrollbar-thumb { background: #3a4f63; border-radius: 2px; }

        .menu-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 13px 20px; color: #ddd; text-decoration: none;
            font-size: .95rem; border-bottom: 1px solid #2e3d4e;
            cursor: pointer; transition: background .12s, color .12s;
        }
        .menu-item:hover { background: #37475a; color: #ff9900; }
        .menu-item .chevron { color: #888; font-size: .8rem; }
        .menu-item:hover .chevron { color: #ff9900; }

        .menu-section-label {
            padding: 10px 20px 6px; font-size: .72rem; font-weight: 700;
            letter-spacing: .08em; color: #8899aa; text-transform: uppercase;
            border-bottom: 1px solid #2e3d4e;
        }

        .menu-footer { flex-shrink: 0; border-top: 2px solid #3a4f63; }
        .menu-footer .menu-item { border-bottom: none; }

        .sub-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 11px 20px; color: #ddd; text-decoration: none;
            font-size: .9rem; border-bottom: 1px solid #2e3d4e;
            transition: background .12s, color .12s; gap: 8px;
        }
        .sub-item:hover { background: #37475a; color: #ff9900; }
        .sub-item .sub-price { font-size: .8rem; color: #ff9900; white-space: nowrap; flex-shrink: 0; }
        .sub-item-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .sub-see-all {
            display: block; padding: 12px 20px; font-weight: 700; color: #ff9900;
            text-decoration: none; border-bottom: 2px solid #3a4f63; font-size: .92rem;
        }
        .sub-see-all:hover { background: #37475a; color: #ffb347; }
        .sub-empty { padding: 30px 20px; color: #8899aa; font-size: .9rem; text-align: center; }

        /* ── Filter bar ──────────────────────────────────────────────────── */
        .navbar-filter { background-color: #232f3e; padding: 6px 0; border-top: 1px solid #3a4553; }
        .navbar-filter .filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .navbar-filter select,
        .navbar-filter input[type="number"] {
            background: #131921; color: #ddd; border: 1px solid #3a4553;
            border-radius: 4px; padding: 5px 10px; font-size: .85rem; height: 34px;
        }
        .navbar-filter select { min-width: 160px; }
        .navbar-filter input[type="number"] { width: 130px; }
        .navbar-filter select:focus,
        .navbar-filter input[type="number"]:focus {
            outline: none; border-color: #ff9900; box-shadow: 0 0 0 2px rgba(255,153,0,.25);
        }
        .navbar-filter select option { background: #232f3e; }
        .navbar-filter .filter-btn {
            background: #ff9900; color: #131921; border: none; border-radius: 4px;
            padding: 5px 16px; font-size: .85rem; font-weight: 700; height: 34px;
            cursor: pointer; transition: background .15s; white-space: nowrap;
        }
        .navbar-filter .filter-btn:hover { background: #ffb347; }
        .navbar-filter .filter-label { color: #aaa; font-size: .78rem; white-space: nowrap; }
        .filter-active-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #37475a; color: #ff9900; border-radius: 20px;
            padding: 3px 10px; font-size: .78rem; font-weight: 600; text-decoration: none;
        }
        .filter-active-badge:hover { background: #3a4f63; color: #ffb347; }
    </style>
</head>
<body>

<!-- ══ NAVBAR ══════════════════════════════════════════════════════════════ -->
<nav class="navbar navbar-expand-lg navbar-amazon sticky-top">
    <div class="container-fluid px-4">

        <!-- Hamburger -->
        <button class="btn all-btn me-3 d-flex align-items-center gap-2" id="openSidebar">
            <i class="fas fa-bars fa-lg"></i>
            <span class="fs-6">All</span>
        </button>

        <!-- Logo -->
        <a class="navbar-brand logo me-4" href="../user/home.php">
            E-Shop<span class="text-white">BD</span>
        </a>

        <!-- Search (hidden for admins) -->
        <?php if (!$is_admin): ?>
        <form class="d-flex flex-grow-1 mx-3" method="GET" action="../user/home.php" style="max-width:650px;">
            <input type="text" name="search" class="form-control rounded-start rounded-0"
                   placeholder="Search E-Shop BD…"
                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            <button class="btn btn-warning px-4 rounded-end rounded-0">
                <i class="fas fa-search"></i>
            </button>
        </form>
        <?php else: ?>
        <div class="flex-grow-1"></div>
        <?php endif; ?>

        <!-- Right side -->
        <div class="d-flex align-items-center gap-4 ms-auto">

            

            <!-- Seller Panel button -->
            <!-- Role Panel Buttons -->
<?php if ($is_admin): ?>
    <a href="../admin/dashboard.php" class="admin-panel-btn">
        <i class="fas fa-gauge-high"></i> Admin Panel
    </a>
<?php elseif ($is_seller): ?>
    <a href="../seller/dashboard.php" class="admin-panel-btn" style="background:#28a745;">
        <i class="fas fa-store"></i> Seller Panel
    </a>
<?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'category_manager'): ?>
    <a href="../category_manager/dashboard.php" class="admin-panel-btn" style="background:#6f42c1;">
        <i class="fas fa-tags"></i> Category Manager
    </a>
<?php endif; ?>


 
<!-- ── UPGRADE: Notification bell (all logged-in users) ─────────────────── -->
<?php if (isset($_SESSION['user_id'])): ?>
<a href="<?= $is_admin ? '../admin/notifications.php' : ($is_seller ? '../seller/notifications.php' : ($is_category_manager ? '../category_manager/notifications.php' : '../user/notifications.php')) ?>"
   class="position-relative text-white text-decoration-none d-flex align-items-center"
   style="padding:4px 2px;"
   title="Notifications">
    <i class="fas fa-bell fa-lg"></i>
    <?php if ($notif_count > 0): ?>
        <span class="position-absolute top-0 start-75 badge rounded-pill bg-danger"
              style="font-size:.65rem;padding:2px 5px;min-width:16px;text-align:center;">
            <?= $notif_count > 99 ? '99+' : $notif_count ?>
        </span>
    <?php endif; ?>
</a>
<?php endif; ?>



            <!-- ── Account & Lists hover popup ── -->
            <div class="account-wrapper">
                <button class="account-trigger">
                    <small><?= $first_name ? "Hello, $first_name" : 'Hello, sign in' ?></small>
                    <strong>Account &amp; Lists <i class="fas fa-caret-down" style="font-size:.7rem"></i></strong>
                </button>

                <div class="account-popup">

                    <?php if (!isset($_SESSION['user_id'])): ?>
                    <!-- ── Logged-out ── -->
                    <a href="../auth/login.php" class="popup-signin-btn">Sign in</a>
                    <p class="popup-new-customer">
                        New customer? <a href="../auth/register.php">Start here.</a>
                    </p>
                    <div class="popup-cols">
                        <div>
                            <div class="popup-col-title">Your Lists</div>
                            <a href="../auth/login.php" class="popup-link">Create a List</a>
                        </div>
                        <div>
                            <div class="popup-col-title">Your Account</div>
                            <a href="../auth/login.php" class="popup-link">Sign In</a>
                        </div>
                    </div>

                    <?php elseif ($is_admin): ?>
                    <!-- ── Admin logged-in ── -->
                    <div class="popup-cols">
                        <div>
                            <div class="popup-col-title">Admin</div>
                            <a href="../admin/dashboard.php"       class="popup-link">Dashboard</a>
                            <a href="../admin/manage_products.php" class="popup-link">Products</a>
                            <a href="../admin/manage_orders.php"   class="popup-link">Orders</a>
                            <a href="../admin/manage_users.php"    class="popup-link">Users</a>
                        </div>
                        <div>
                            <div class="popup-col-title">Your Account</div>
                            <a href="../user/profile.php" class="popup-link">Profile</a>
                            
                        </div>
                        <hr class="popup-divider">
                        <a href="../auth/logout.php" class="popup-logout">
                            <i class="fas fa-right-from-bracket"></i> Sign Out
                        </a>
                    </div>

                    <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'seller'): ?>
<!-- Seller logged-in -->
<div class="popup-cols">
    <div>
        <div class="popup-col-title">Seller</div>
        <a href="../seller/dashboard.php" class="popup-link">Dashboard</a>
        <a href="../seller/manage_products.php" class="popup-link">My Products</a>
        <a href="../seller/orders.php" class="popup-link">Shop Orders</a>
        <a href="../seller/profile.php" class="popup-link">Shop Settings</a>
    </div>
    <div>
        <div class="popup-col-title">Your Account</div>
        <a href="../user/profile.php" class="popup-link">Profile</a>
        
        
        
    </div>
    <hr class="popup-divider">
    <a href="../auth/logout.php" class="popup-logout">
        <i class="fas fa-right-from-bracket"></i> Sign Out
    </a>
</div>

<?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'category_manager'): ?>
<!-- Category Manager logged-in -->
<div class="popup-cols">
    <div>
        <div class="popup-col-title">Category Manager</div>
        <a href="../category_manager/dashboard.php" class="popup-link">Dashboard</a>
        <a href="../category_manager/manage_categories.php" class="popup-link">Manage Categories</a>
        <a href="../category_manager/manage_products.php" class="popup-link">Manage Products</a>
    </div>
    <div>
        <div class="popup-col-title">Your Account</div>
        <a href="../user/profile.php" class="popup-link">Profile</a>
    </div>
    <hr class="popup-divider">
    <a href="../auth/logout.php" class="popup-logout">
        <i class="fas fa-right-from-bracket"></i> Sign Out
    </a>
</div>

                    <?php else: ?>
                    <!-- ── Regular user logged-in ── -->
                    <div class="popup-cols">
                        <div>
                            <div class="popup-col-title">Your Lists</div>
                            <a href="../user/wishlist.php" class="popup-link">Wishlist</a>
                        </div>
                        <div>
                            <div class="popup-col-title">Your Account</div>
                            <a href="../user/profile.php"  class="popup-link">Profile</a>
                            <a href="../user/orders.php"   class="popup-link">My Orders</a>
                            
                        </div>
                        <hr class="popup-divider">
                        <a href="../auth/logout.php" class="popup-logout">
                            <i class="fas fa-right-from-bracket"></i> Sign Out
                        </a>
                    </div>
                    <?php endif; ?>

                </div>
            </div><!-- /.account-wrapper -->

            <!-- Wishlist (non-admins, non-sellers only) -->
            <?php if ($is_regular_user): ?>
            <a href="../user/wishlist.php" class="position-relative text-white text-decoration-none d-flex align-items-end gap-1">
                <i class="fas fa-heart fa-2x"></i>
                <span style="font-size:.85rem;font-weight:700">Wishlist</span>
            </a>
            <?php endif; ?>

            <!-- Cart (non-admins, non-sellers only) -->
            <?php if ($is_regular_user): ?>
            <a href="../user/cart.php" class="position-relative text-white text-decoration-none d-flex align-items-end gap-1">
                <i class="fas fa-shopping-cart fa-2x"></i>
                <?php if ($cart_count > 0): ?>
                    <span class="position-absolute top-0 start-75 badge rounded-pill bg-warning text-dark"
                          style="font-size:.7rem"><?= $cart_count ?></span>
                <?php endif; ?>
                <span style="font-size:.85rem;font-weight:700">Cart</span>
            </a>
            <?php endif; ?>

        </div>
    </div>
</nav>

<!-- ══ FILTER BAR — hidden for admins, sellers and pages with $hide_filter ═ -->
<?php if (!$is_admin && !$is_seller && !$is_category_manager && empty($hide_filter)): ?>
<div class="navbar-filter">
    <div class="container-fluid px-4">
        <form class="filter-form" method="GET" action="../user/home.php">
            <?php if (!empty($_GET['search'])): ?>
                <input type="hidden" name="search" value="<?= htmlspecialchars($_GET['search']) ?>">
            <?php endif; ?>

            <span class="filter-label"><i class="fas fa-filter me-1"></i>Filter:</span>

            <select name="category" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php
                $active_cat = isset($_GET['category']) ? intval($_GET['category']) : 0;
                foreach ($categories as $cat):
                ?>
                <option value="<?= $cat['id'] ?>" <?= $active_cat == $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <?php $active_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? floatval($_GET['max_price']) : ''; ?>
            <input type="number" name="max_price" placeholder="Max Price ৳"
                   value="<?= $active_price ?>" min="0" step="1">

            <button type="submit" class="filter-btn">
                <i class="fas fa-search me-1"></i>Go
            </button>

            <?php if ($active_cat > 0 || $active_price !== ''): ?>
                <a href="../user/home.php<?= !empty($_GET['search']) ? '?search='.urlencode($_GET['search']) : '' ?>"
                   class="filter-active-badge">
                    <i class="fas fa-times-circle"></i> Clear filters
                </a>
            <?php endif; ?>

            <?php if ($active_cat > 0):
                $cat_name = '';
                foreach ($categories as $c) { if ($c['id'] == $active_cat) $cat_name = $c['name']; }
            ?>
                <span class="filter-active-badge" style="pointer-events:none;">
                    <i class="fas fa-tag"></i> <?= htmlspecialchars($cat_name) ?>
                </span>
            <?php endif; ?>

            <?php if ($active_price !== ''): ?>
                <span class="filter-active-badge" style="pointer-events:none;">
                    <i class="fas fa-taka-sign"></i> Max ৳<?= number_format($active_price) ?>
                </span>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ══ BACKDROP ════════════════════════════════════════════════════════════ -->
<div id="sidebarBackdrop"></div>

<!-- ══ SIDEBAR ═════════════════════════════════════════════════════════════ -->
<div id="allMenu" role="dialog" aria-modal="true" aria-label="Shop by Department">

    <div id="mainPanel" class="menu-panel">

        <div class="panel-header">
            <i class="fas fa-user-circle fa-lg"></i>
            <span>Hello, <?= $first_name ?? 'sign in' ?></span>
            <button class="close-btn" id="closeSidebar" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="menu-scroll">

            <?php if (isset($_SESSION['user_id'])): ?>
            <div class="menu-section-label">Your Account</div>
            <a href="../user/profile.php" class="menu-item">
                <span><i class="fas fa-user me-2 text-muted"></i>Profile</span>
            </a>
            <?php if ($is_regular_user): ?>
            <a href="../user/orders.php" class="menu-item">
                <span><i class="fas fa-box me-2 text-muted"></i>My Orders</span>
            </a>
           
            <a href="../user/wishlist.php" class="menu-item">
                <span><i class="fas fa-heart me-2 text-muted"></i>Wishlist</span>
            </a>
            <?php endif; ?>
            <?php if ($is_seller): ?>
            <a href="../seller/profile.php" class="menu-item">
                <span><i class=" fas fa-store me-2"></i>Shop Profile</span>
                
            </a>
            <?php endif; ?>
            <?php else: ?>
            <a href="../auth/login.php" class="menu-item" style="color:#ff9900;font-weight:600">
                Sign In &nbsp;<i class="fas fa-chevron-right chevron"></i>
            </a>
            <?php endif; ?>

            <div class="menu-section-label">Shop by Department</div>

            <?php foreach ($categories as $cat):
                $has_products = !empty($products_by_cat[$cat['id']]);
            ?>
            <?php if ($has_products): ?>
                <div class="menu-item open-sub"
                     data-cat-id="<?= $cat['id'] ?>"
                     data-cat-name="<?= htmlspecialchars($cat['name']) ?>">
                    <span><?= htmlspecialchars($cat['name']) ?></span>
                    <i class="fas fa-chevron-right chevron"></i>
                </div>
            <?php else: ?>
                <a href="../user/home.php?category=<?= $cat['id'] ?>" class="menu-item">
                    <span><?= htmlspecialchars($cat['name']) ?></span>
                </a>
            <?php endif; ?>
            <?php endforeach; ?>

            
            <!-- Help & Settings: only Customer Service here -->
<div class="menu-section-label">Help &amp; Settings</div>
<a href="#" class="menu-item" id="openChatbot">
    <span><i class="fas fa-headset me-2 text-muted"></i>Customer Service</span>
</a>


        </div><!-- /.menu-scroll -->

        <!-- Logout pinned to bottom -->
        <?php if (isset($_SESSION['user_id'])): ?>
        <div class="menu-footer">
            <a href="../auth/logout.php" class="menu-item" style="color:#f08080;">
                <span><i class="fas fa-right-from-bracket me-2"></i>Sign Out</span>
            </a>
        </div>
        <?php endif; ?>

    </div><!-- /mainPanel -->

    <div id="subPanel" class="menu-panel">
        <div class="panel-header">
            <button class="back-btn" id="backToMain" aria-label="Back">
                <i class="fas fa-arrow-left"></i>
            </button>
            <span id="subPanelTitle">Category</span>
            <button class="close-btn" id="closeFromSub" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="menu-scroll" id="subItemList"></div>
    </div>

</div><!-- /allMenu -->

<!-- ══ BOOTSTRAP JS ════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ══ ACCOUNT POPUP — JS hover with gap tolerance ════════════════════════ -->
<script>
(function () {
    const wrapper = document.querySelector('.account-wrapper');
    const popup   = document.querySelector('.account-popup');
    if (!wrapper || !popup) return;

    let hideTimer = null;

    function showPopup() {
        clearTimeout(hideTimer);
        popup.style.display = 'block';
        popup.offsetHeight; // force reflow for CSS transition
        popup.style.opacity = '1';
        popup.style.transform = 'translateY(0)';
        popup.style.pointerEvents = 'auto';
    }

    function hidePopup() {
        hideTimer = setTimeout(() => {
            popup.style.opacity = '0';
            popup.style.transform = 'translateY(-8px)';
            popup.style.pointerEvents = 'none';
            setTimeout(() => { popup.style.display = 'none'; }, 180);
        }, 120);
    }

    wrapper.addEventListener('mouseenter', showPopup);
    wrapper.addEventListener('mouseleave', hidePopup);
    popup.addEventListener('mouseenter',   showPopup);
    popup.addEventListener('mouseleave',   hidePopup);
})();
</script>

<!-- ══ SIDEBAR SCRIPT ══════════════════════════════════════════════════════ -->
<script>
(function () {
    const productsByCat = <?= json_encode($products_by_cat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const baseUrl = '../user/home.php';
    const prodUrl = '../user/product.php';

    const sidebar  = document.getElementById('allMenu');
    const backdrop = document.getElementById('sidebarBackdrop');
    const subTitle = document.getElementById('subPanelTitle');
    const subList  = document.getElementById('subItemList');

    function openSidebar() {
        sidebar.classList.add('show');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        sidebar.classList.remove('sub-open');
        setTimeout(() => {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }, 10);
    }
    function openSub(catId, catName) {
        subTitle.textContent = catName;
        subList.innerHTML = '';
        const seeAll = document.createElement('a');
        seeAll.href      = `${baseUrl}?category=${catId}`;
        seeAll.className = 'sub-see-all';
        seeAll.innerHTML = `<i class="fas fa-store me-2"></i>See all in ${catName}`;
        subList.appendChild(seeAll);

        const products = productsByCat[catId] || [];
        if (products.length === 0) {
            const empty = document.createElement('div');
            empty.className   = 'sub-empty';
            empty.textContent = 'No products available yet.';
            subList.appendChild(empty);
        } else {
            products.forEach(p => {
                const a = document.createElement('a');
                a.href      = `${prodUrl}?id=${p.id}`;
                a.className = 'sub-item';
                a.innerHTML = `<span class="sub-item-name">${p.name}</span>
                               <span class="sub-price">৳ ${Number(p.price).toLocaleString()}</span>`;
                subList.appendChild(a);
            });
        }
        sidebar.classList.add('sub-open');
    }
    function closeSub() { sidebar.classList.remove('sub-open'); }

    document.getElementById('openSidebar').addEventListener('click', openSidebar);
    document.getElementById('closeSidebar').addEventListener('click', closeSidebar);
    document.getElementById('closeFromSub').addEventListener('click', closeSidebar);
    document.getElementById('backToMain').addEventListener('click', closeSub);
    backdrop.addEventListener('click', closeSidebar);

    document.querySelectorAll('.open-sub').forEach(el => {
        el.addEventListener('click', () => openSub(el.dataset.catId, el.dataset.catName));
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            sidebar.classList.contains('sub-open') ? closeSub() : closeSidebar();
        }
    });
})();
</script>

<script>
document.getElementById('openChatbot').addEventListener('click', function(e) {
    e.preventDefault();
    
    // 1. Close the sidebar menu first
    const sidebar = document.getElementById('allMenu');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar) sidebar.classList.remove('show', 'sub-open');
    if (backdrop) backdrop.classList.remove('show');
    document.body.style.overflow = '';

    // 2. Trigger the chatbot FAB click to open the window
    const chatbotFab = document.getElementById('chatbot-fab');
    if (chatbotFab) {
        chatbotFab.click();
    } else {
        console.error("Chatbot widget not found. Make sure chatbot_widget.php is included.");
    }
});
</script>