<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = intval($_SESSION['user_id']);

// ── ADD TO CART ───────────────────────────────────────────────────────────────
if (isset($_GET['add'])) {
    $product_id = intval($_GET['add']);

    $stmt = $conn->prepare("
        SELECT * FROM products
        WHERE id = ? AND status = 'approved' AND stock > 0
    ");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        header("Location: home.php?error=unavailable");
        exit();
    }

    $stmt = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $cart_row    = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $current_qty = $cart_row ? $cart_row['quantity'] : 0;

    if ($current_qty >= $product['stock']) {
        header("Location: cart.php?error=max_stock");
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

// ── REMOVE FROM CART ──────────────────────────────────────────────────────────
if (isset($_GET['remove'])) {
    $product_id = intval($_GET['remove']);
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $stmt->close();
    header("Location: cart.php");
    exit();
}

// ── UPDATE QUANTITY ───────────────────────────────────────────────────────────
if (isset($_POST['update_qty'])) {
    $product_id = intval($_POST['product_id']);
    $quantity   = intval($_POST['quantity']);

    $stmt = $conn->prepare("SELECT stock, status FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        header("Location: cart.php?error=product_missing");
        exit();
    }
    if ($product['status'] !== 'approved') {
        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $user_id, $product_id);
        $stmt->execute();
        $stmt->close();
        header("Location: cart.php?error=unavailable");
        exit();
    }

    $quantity = max(1, min($quantity, $product['stock']));

    $stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("iii", $quantity, $user_id, $product_id);
    $stmt->execute();
    $stmt->close();

    header("Location: cart.php?updated=1");
    exit();
}

// ── FETCH CART ────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT c.product_id, c.quantity,
           p.name, p.price, p.image, p.stock
    FROM cart c
    JOIN products p ON c.product_id = p.id
    WHERE c.user_id = ? AND p.status = 'approved'
    ORDER BY c.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cart_result = $stmt->get_result();
$stmt->close();

$cart_items = [];
$grand_total = 0;
while ($row = $cart_result->fetch_assoc()) {
    $row['subtotal'] = $row['price'] * $row['quantity'];
    $grand_total    += $row['subtotal'];
    $cart_items[]    = $row;
}
?>
<?php include("../includes/header.php"); ?>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">

<style>
:root {
    --ink:    #0f0f0f;
    --muted:  #6b7280;
    --border: #e5e7eb;
    --surface:#f9fafb;
    --accent: #131921;
    --amber:  #f59e0b;
    --red:    #ef4444;
    --green:  #16a34a;
    --radius: 12px;
}
*, *::before, *::after { box-sizing: border-box; }
.cart-page { font-family: 'DM Sans', sans-serif; background: #f3f4f6; min-height: 100vh; padding: 2.5rem 0 4rem; }
.cart-heading { font-family: 'Playfair Display', serif; font-size: 2rem; color: var(--ink); margin-bottom: 1.75rem; }
.cart-heading span { color: var(--amber); }

/* ── Layout ── */
.cart-layout { display: grid; grid-template-columns: 1fr 340px; gap: 1.5rem; align-items: start; }
@media(max-width:900px){ .cart-layout { grid-template-columns:1fr; } }

/* ── Alerts ── */
.flash { display:flex; align-items:center; gap:.6rem; border-radius:8px; padding:.65rem 1rem; font-size:.85rem; font-weight:500; margin-bottom:1rem; }
.flash.ok  { background:#d1fae5; color:#065f46; }
.flash.err { background:#fee2e2; color:#991b1b; }

/* ── Item card ── */
.cart-card { background:#fff; border-radius:var(--radius); overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:1px; }
.cart-item { display:grid; grid-template-columns:24px 88px 1fr auto; gap:1rem; align-items:center; padding:1.1rem 1.25rem; border-bottom:.5px solid var(--border); transition:background .15s; }
.cart-item:last-child { border-bottom:none; }
.cart-item:hover { background:var(--surface); }
.cart-item.dimmed { opacity:.45; }

/* checkbox */
.item-check { width:18px; height:18px; accent-color:var(--accent); cursor:pointer; flex-shrink:0; }

/* image */
.item-img { width:88px; height:88px; object-fit:cover; border-radius:8px; border:.5px solid var(--border); }

/* info */
.item-name { font-size:.95rem; font-weight:600; color:var(--ink); line-height:1.35; margin-bottom:.25rem; }
.item-meta { font-size:.78rem; color:var(--muted); margin-bottom:.5rem; }
.item-price { font-size:.9rem; font-weight:600; color:var(--ink); }
.item-price .original { color:var(--muted); font-weight:400; font-size:.8rem; }
.out-badge { display:inline-block; background:#fee2e2; color:#991b1b; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:4px; margin-left:.4rem; }

/* qty + remove */
.item-actions { display:flex; flex-direction:column; align-items:flex-end; gap:.6rem; }
.qty-row { display:flex; align-items:center; gap:.4rem; }
.qty-btn { width:28px; height:28px; border-radius:6px; border:.5px solid var(--border); background:#fff; cursor:pointer; font-size:1rem; font-weight:600; display:flex; align-items:center; justify-content:center; transition:background .12s; }
.qty-btn:hover { background:var(--surface); }
.qty-input { width:44px; height:28px; text-align:center; border:.5px solid var(--border); border-radius:6px; font-size:.88rem; font-weight:600; }
.qty-input:focus { outline:none; border-color:var(--accent); }
.remove-btn { font-size:.78rem; color:var(--muted); background:none; border:none; cursor:pointer; padding:0; transition:color .12s; }
.remove-btn:hover { color:var(--red); }
.item-subtotal { font-size:.9rem; font-weight:700; color:var(--ink); white-space:nowrap; }

/* ── Select-all bar ── */
.select-bar { display:flex; align-items:center; justify-content:space-between; background:#fff; border-radius:var(--radius) var(--radius) 0 0; padding:.75rem 1.25rem; border-bottom:.5px solid var(--border); font-size:.85rem; font-weight:500; color:var(--ink); }
.select-bar label { display:flex; align-items:center; gap:.5rem; cursor:pointer; }
.selected-info { color:var(--muted); font-size:.82rem; }

/* ── Summary panel ── */
.summary-panel { background:#fff; border-radius:var(--radius); padding:1.5rem; box-shadow:0 1px 3px rgba(0,0,0,.08); position:sticky; top:80px; }
.summary-title { font-family:'Playfair Display',serif; font-size:1.15rem; color:var(--ink); margin-bottom:1.1rem; }
.summary-line { display:flex; justify-content:space-between; font-size:.88rem; color:var(--muted); margin-bottom:.5rem; }
.summary-line.total { font-size:1rem; font-weight:700; color:var(--ink); margin-top:.75rem; padding-top:.75rem; border-top:.5px solid var(--border); }
.checkout-btn { display:block; width:100%; margin-top:1.25rem; padding:.9rem; background:var(--accent); color:#fff; border:none; border-radius:8px; font-family:'DM Sans',sans-serif; font-size:.95rem; font-weight:600; cursor:pointer; text-align:center; text-decoration:none; transition:background .15s, transform .1s; }
.checkout-btn:hover { background:#232f3e; color:#fff; transform:translateY(-1px); }
.checkout-btn:disabled { background:#d1d5db; cursor:not-allowed; transform:none; }
.checkout-btn .arrow { margin-left:.4rem; }
.item-count-badge { display:inline-flex; align-items:center; justify-content:center; background:var(--amber); color:#fff; font-size:.72rem; font-weight:700; width:20px; height:20px; border-radius:50%; margin-left:.4rem; }
.continue-link { display:block; text-align:center; font-size:.82rem; color:var(--muted); margin-top:.75rem; text-decoration:none; }
.continue-link:hover { color:var(--ink); }

/* ── Empty state ── */
.empty-cart { text-align:center; padding:4rem 2rem; background:#fff; border-radius:var(--radius); }
.empty-cart .icon { font-size:3.5rem; margin-bottom:1rem; }
.empty-cart h3 { font-family:'Playfair Display',serif; font-size:1.4rem; color:var(--ink); margin-bottom:.5rem; }
.empty-cart p { color:var(--muted); font-size:.9rem; margin-bottom:1.5rem; }
.shop-btn { display:inline-block; padding:.75rem 2rem; background:var(--accent); color:#fff; border-radius:8px; font-weight:600; text-decoration:none; transition:background .15s; }
.shop-btn:hover { background:#232f3e; color:#fff; }
</style>

<div class="cart-page">
<div class="container">
    <h1 class="cart-heading">Your Cart <span>·</span></h1>

    <?php if (isset($_GET['added'])): ?>
        <div class="flash ok">✓ Item added to your cart.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="flash ok">✓ Quantity updated.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && $_GET['error'] === 'max_stock'): ?>
        <div class="flash err">⚠ You've reached the maximum available stock for that item.</div>
    <?php endif; ?>

    <?php if (empty($cart_items)): ?>
        <div class="empty-cart">
            <div class="icon">🛒</div>
            <h3>Your cart is empty</h3>
            <p>Looks like you haven't added anything yet.</p>
            <a href="home.php" class="shop-btn">Browse Products</a>
        </div>

    <?php else: ?>
    <div class="cart-layout">

        <!-- LEFT: item list -->
        <div>
            <div class="cart-card">
                <!-- Select-all bar -->
                <div class="select-bar">
                    <label>
                        <input type="checkbox" id="selectAll" class="item-check" checked>
                        Select all
                    </label>
                    <span class="selected-info" id="selectedInfo"></span>
                </div>

                <!-- Items -->
                <?php foreach ($cart_items as $item):
                    $out = $item['stock'] === 0;
                ?>
                <div class="cart-item <?= $out ? 'dimmed' : '' ?>" id="row-<?= $item['product_id'] ?>">

                    <!-- Checkbox -->
                    <input type="checkbox"
                           class="item-check item-selector"
                           data-id="<?= $item['product_id'] ?>"
                           data-subtotal="<?= $item['subtotal'] ?>"
                           data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>"
                           <?= $out ? 'disabled' : 'checked' ?>>

                    <!-- Image -->
                    <img src="../<?= htmlspecialchars($item['image'], ENT_QUOTES) ?>"
                         class="item-img"
                         alt="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>">

                    <!-- Info -->
                    <div>
                        <div class="item-name">
                            <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($out): ?><span class="out-badge">Out of stock</span><?php endif; ?>
                        </div>
                        <div class="item-meta">Unit price: ৳ <?= number_format($item['price']) ?></div>
                        <?php if (!$out): ?>
                        <!-- Inline qty form -->
                        <form method="POST" class="qty-row" id="qtyForm-<?= $item['product_id'] ?>">
                            <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                            <button type="button" class="qty-btn"
                                onclick="changeQty(<?= $item['product_id'] ?>, -1, <?= $item['stock'] ?>)">−</button>
                            <input type="number" name="quantity" class="qty-input"
                                   id="qty-<?= $item['product_id'] ?>"
                                   value="<?= $item['quantity'] ?>"
                                   min="1" max="<?= $item['stock'] ?>"
                                   onchange="submitQty(<?= $item['product_id'] ?>)">
                            <button type="button" class="qty-btn"
                                onclick="changeQty(<?= $item['product_id'] ?>, +1, <?= $item['stock'] ?>)">+</button>
                            <button type="submit" name="update_qty" style="display:none"></button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <!-- Right side: subtotal + remove -->
                    <div class="item-actions">
                        <div class="item-subtotal">৳ <?= number_format($item['subtotal']) ?></div>
                        <a href="?remove=<?= $item['product_id'] ?>" class="remove-btn"
                           onclick="return confirm('Remove this item?')">Remove</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- RIGHT: summary -->
        <div>
            <div class="summary-panel">
                <div class="summary-title">Order Summary</div>

                <div class="summary-line">
                    <span>Items selected</span>
                    <span id="summaryCount">—</span>
                </div>
                <div class="summary-line">
                    <span>Subtotal</span>
                    <span id="summarySubtotal">৳ 0</span>
                </div>
                <div class="summary-line">
                    <span>Shipping</span>
                    <span style="color:var(--green);font-weight:600">Calculated at checkout</span>
                </div>
                <div class="summary-line total">
                    <span>Estimated total</span>
                    <span id="summaryTotal">৳ 0</span>
                </div>

                <!-- Hidden form posts selected product IDs to checkout -->
                <form method="POST" action="checkout.php" id="checkoutForm">
                    <div id="selectedInputs"></div>
                    <button type="submit" class="checkout-btn" id="checkoutBtn" disabled>
                        Proceed to Checkout <span class="arrow">→</span>
                    </button>
                </form>

                <a href="home.php" class="continue-link">← Continue shopping</a>
            </div>
        </div>

    </div>
    <?php endif; ?>
</div>
</div>

<script>
const formatBDT = n => '৳ ' + Number(n).toLocaleString('en-BD');

function getChecked() {
    return [...document.querySelectorAll('.item-selector:checked')];
}

function updateSummary() {
    const checked  = getChecked();
    const subtotal = checked.reduce((s, cb) => s + parseFloat(cb.dataset.subtotal), 0);
    const count    = checked.length;

    document.getElementById('summaryCount').textContent    = count + ' item' + (count !== 1 ? 's' : '');
    document.getElementById('summarySubtotal').textContent = formatBDT(subtotal);
    document.getElementById('summaryTotal').textContent    = formatBDT(subtotal);
    document.getElementById('selectedInfo').textContent    = count + ' of <?= count($cart_items) ?> selected';

    // Rebuild hidden inputs for checkout form
    const container = document.getElementById('selectedInputs');
    container.innerHTML = '';
    checked.forEach(cb => {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'selected_products[]';
        inp.value = cb.dataset.id;
        container.appendChild(inp);
    });

    document.getElementById('checkoutBtn').disabled = count === 0;
}

// Select-all toggle
document.getElementById('selectAll').addEventListener('change', function () {
    document.querySelectorAll('.item-selector:not(:disabled)').forEach(cb => {
        cb.checked = this.checked;
    });
    updateSummary();
});

document.querySelectorAll('.item-selector').forEach(cb => {
    cb.addEventListener('change', function () {
        const allEnabled  = [...document.querySelectorAll('.item-selector:not(:disabled)')];
        const allChecked  = allEnabled.every(c => c.checked);
        document.getElementById('selectAll').checked = allChecked;
        updateSummary();
    });
});

// Qty controls
function changeQty(productId, delta, maxStock) {
    const input = document.getElementById('qty-' + productId);
    let val = parseInt(input.value) + delta;
    val = Math.max(1, Math.min(val, maxStock));
    input.value = val;
    submitQty(productId);
}

function submitQty(productId) {
    document.querySelector('#qtyForm-' + productId + ' button[name="update_qty"]').click();
}

// Init
updateSummary();
</script>

<?php include("../includes/footer.php"); ?>