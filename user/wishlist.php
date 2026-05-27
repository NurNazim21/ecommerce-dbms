<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = intval($_SESSION['user_id']);

// ── ADD TO WISHLIST ───────────────────────────────────────────────────────────
if (isset($_GET['add'])) {
    $product_id = intval($_GET['add']);

    // Verify product exists and is approved
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND status = 'approved'");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $stmt = $conn->prepare("INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $product_id);
        $stmt->execute();
    }
    $stmt->close();
    header("Location: wishlist.php?added=1");
    exit();
}

// ── REMOVE FROM WISHLIST ──────────────────────────────────────────────────────
if (isset($_GET['remove'])) {
    $product_id = intval($_GET['remove']);
    $stmt = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $stmt->close();
    header("Location: wishlist.php");
    exit();
}

// ── MOVE TO CART ──────────────────────────────────────────────────────────────
if (isset($_GET['move_to_cart'])) {
    $product_id = intval($_GET['move_to_cart']);

    $stmt = $conn->prepare("SELECT stock, status FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product || $product['status'] !== 'approved') {
        header("Location: wishlist.php?error=unavailable");
        exit();
    }
    if ($product['stock'] <= 0) {
        header("Location: wishlist.php?error=out_of_stock");
        exit();
    }

    // Check current cart qty
    $stmt = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $cart_row    = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $current_qty = $cart_row ? $cart_row['quantity'] : 0;

    if ($current_qty >= $product['stock']) {
        header("Location: wishlist.php?error=max_stock");
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO cart (user_id, product_id, quantity)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE quantity = quantity + 1
    ");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $stmt->close();

    header("Location: cart.php?added=1");
    exit();
}

// ── FETCH WISHLIST ────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT w.product_id, w.created_at,
           p.name, p.price, p.image, p.stock, p.status
    FROM wishlist w
    JOIN products p ON w.product_id = p.id
    WHERE w.user_id = ?
    ORDER BY w.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$wishlist_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<?php include("../includes/header.php"); ?>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">

<style>
:root{--ink:#0f0f0f;--muted:#6b7280;--border:#e5e7eb;--surface:#f9fafb;--accent:#131921;--amber:#f59e0b;--red:#ef4444;--green:#16a34a;--radius:12px;}
*,*::before,*::after{box-sizing:border-box;}
.wl-page{font-family:'DM Sans',sans-serif;background:#f3f4f6;min-height:100vh;padding:2.5rem 0 5rem;}
.wl-heading{font-family:'Playfair Display',serif;font-size:2rem;color:var(--ink);margin-bottom:1.75rem;}
.wl-heading span{color:var(--amber);}
.flash{display:flex;align-items:center;gap:.6rem;border-radius:8px;padding:.65rem 1rem;font-size:.85rem;font-weight:500;margin-bottom:1rem;}
.flash.ok  {background:#d1fae5;color:#065f46;}
.flash.err {background:#fee2e2;color:#991b1b;}
.wl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1.25rem;}
.wl-card{background:#fff;border-radius:var(--radius);box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .14s;}
.wl-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.11);}
.wl-card img{width:100%;height:200px;object-fit:cover;}
.wl-card-body{padding:1rem;display:flex;flex-direction:column;flex:1;gap:.4rem;}
.wl-name{font-size:.92rem;font-weight:700;color:var(--ink);line-height:1.3;}
.wl-price{font-size:1rem;font-weight:800;color:var(--green);}
.wl-added{font-size:.72rem;color:var(--muted);}
.wl-actions{display:flex;flex-direction:column;gap:.5rem;margin-top:auto;padding-top:.75rem;}
.btn-cart{display:block;padding:.6rem;background:var(--accent);color:#fff;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;text-align:center;text-decoration:none;transition:background .13s;cursor:pointer;}
.btn-cart:hover{background:#232f3e;color:#fff;}
.btn-cart.disabled{background:#d1d5db;color:#6b7280;cursor:not-allowed;pointer-events:none;}
.btn-remove{display:block;padding:.55rem;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:7px;font-size:.82rem;font-weight:600;text-align:center;text-decoration:none;transition:background .13s;}
.btn-remove:hover{background:#fecaca;color:#7f1d1d;}
.btn-view{display:block;padding:.55rem;background:var(--surface);color:var(--muted);border:.5px solid var(--border);border-radius:7px;font-size:.82rem;font-weight:600;text-align:center;text-decoration:none;transition:background .13s;}
.btn-view:hover{background:var(--border);color:var(--ink);}
.empty-wl{text-align:center;padding:4rem 2rem;background:#fff;border-radius:var(--radius);}
.empty-wl .icon{font-size:3.5rem;margin-bottom:1rem;}
.empty-wl h3{font-family:'Playfair Display',serif;font-size:1.4rem;color:var(--ink);margin-bottom:.5rem;}
.out-badge{display:inline-block;background:#fee2e2;color:#991b1b;font-size:.7rem;font-weight:700;padding:1px 7px;border-radius:4px;}
.unavail-badge{display:inline-block;background:#f3f4f6;color:#6b7280;font-size:.7rem;font-weight:700;padding:1px 7px;border-radius:4px;}
</style>

<div class="wl-page">
<div class="container">
    <h1 class="wl-heading">My Wishlist <span>·</span></h1>

    <?php if (isset($_GET['added'])): ?>
        <div class="flash ok">✓ Item added to your wishlist.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <?php $err = $_GET['error']; ?>
        <div class="flash err">
            <?php if ($err === 'out_of_stock'): ?>⚠ This item is out of stock and can't be added to cart.
            <?php elseif ($err === 'max_stock'): ?>⚠ You've reached the maximum available stock for that item.
            <?php elseif ($err === 'unavailable'): ?>⚠ This product is no longer available.
            <?php else: ?>⚠ Something went wrong. Please try again.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($wishlist_items)): ?>
        <div class="empty-wl">
            <div class="icon">❤️</div>
            <h3>Your wishlist is empty</h3>
            <p style="color:var(--muted);font-size:.9rem;margin-bottom:1.5rem">Save products you love and come back to them anytime.</p>
            <a href="home.php" style="display:inline-block;padding:.75rem 2rem;background:var(--accent);color:#fff;border-radius:8px;font-weight:600;text-decoration:none;">Browse Products</a>
        </div>

    <?php else: ?>
        <div style="font-size:.83rem;color:var(--muted);margin-bottom:1.25rem">
            <?= count($wishlist_items) ?> item<?= count($wishlist_items) !== 1 ? 's' : '' ?> saved
        </div>
        <div class="wl-grid">
            <?php foreach ($wishlist_items as $item):
                $unavailable = $item['status'] !== 'approved';
                $out_of_stock = $item['stock'] <= 0;
            ?>
            <div class="wl-card">
                <a href="product.php?id=<?= intval($item['product_id']) ?>">
                    <img src="../<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>">
                </a>
                <div class="wl-card-body">
                    <div class="wl-name">
                        <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($unavailable): ?>
                            <span class="unavail-badge">Unavailable</span>
                        <?php elseif ($out_of_stock): ?>
                            <span class="out-badge">Out of stock</span>
                        <?php endif; ?>
                    </div>
                    <div class="wl-price">৳ <?= number_format($item['price']) ?></div>
                    <div class="wl-added">Added <?= date('d M Y', strtotime($item['created_at'])) ?></div>

                    <div class="wl-actions">
                        <?php if ($unavailable || $out_of_stock): ?>
                            <span class="btn-cart disabled">
                                <?= $unavailable ? 'Unavailable' : 'Out of Stock' ?>
                            </span>
                        <?php else: ?>
                            <a href="?move_to_cart=<?= intval($item['product_id']) ?>" class="btn-cart">
                                🛒 Move to Cart
                            </a>
                        <?php endif; ?>
                        <a href="product.php?id=<?= intval($item['product_id']) ?>" class="btn-view">View Product</a>
                        <a href="?remove=<?= intval($item['product_id']) ?>"
                           class="btn-remove"
                           onclick="return confirm('Remove from wishlist?')">
                           ✕ Remove
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</div>
<?php include("../includes/footer.php"); ?>