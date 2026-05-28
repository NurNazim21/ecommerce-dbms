<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = intval($_SESSION['user_id']);
$errors  = [];
$success = '';

// ── ADD / EDIT ADDRESS ────────────────────────────────────────────────────────
if (isset($_POST['save_address'])) {
    $edit_id      = isset($_POST['edit_id']) && intval($_POST['edit_id']) > 0
                        ? intval($_POST['edit_id']) : null;
    $label        = trim(strip_tags($_POST['label']      ?? 'Home'));
    $recip_name   = trim(strip_tags($_POST['recipient_name'] ?? ''));
    $phone        = trim(strip_tags($_POST['phone']      ?? ''));
    $address_line = trim(strip_tags($_POST['address_line'] ?? ''));
    $city         = trim(strip_tags($_POST['city']       ?? ''));
    $zip          = trim(strip_tags($_POST['zip']        ?? ''));
    $country      = trim(strip_tags($_POST['country']    ?? 'Bangladesh'));
    $is_default   = isset($_POST['is_default']) ? 1 : 0;

    if (empty($recip_name))   $errors[] = "Recipient name is required.";
    if (empty($phone))        $errors[] = "Phone is required.";
    if (empty($address_line)) $errors[] = "Address is required.";
    if (empty($city))         $errors[] = "City is required.";

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            if ($is_default) {
                $stmt = $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }

            if ($edit_id) {
                // Verify ownership
                $stmt = $conn->prepare("SELECT id FROM addresses WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $edit_id, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) throw new Exception("Access denied.");
                $stmt->close();

                $stmt = $conn->prepare("
                    UPDATE addresses SET label=?,recipient_name=?,phone=?,
                    address_line=?,city=?,zip=?,country=?,is_default=?
                    WHERE id=? AND user_id=?
                ");
                $stmt->bind_param("ssssssssii",
                    $label,$recip_name,$phone,$address_line,$city,$zip,$country,$is_default,
                    $edit_id,$user_id);
                $stmt->execute();
                $stmt->close();
                $success = "Address updated.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO addresses
                        (user_id,label,recipient_name,phone,address_line,city,zip,country,is_default)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");
                $stmt->bind_param("isssssssi",
                    $user_id,$label,$recip_name,$phone,$address_line,$city,$zip,$country,$is_default);
                $stmt->execute();
                $stmt->close();
                $success = "Address saved.";
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = $e->getMessage();
        }
    }
}

// ── SET DEFAULT ───────────────────────────────────────────────────────────────
if (isset($_GET['set_default'])) {
    $aid = intval($_GET['set_default']);
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id); $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare("UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $aid, $user_id); $stmt->execute(); $stmt->close();
    $conn->commit();
    header("Location: addresses.php?updated=1"); exit();
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $aid = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $aid, $user_id); $stmt->execute(); $stmt->close();
    header("Location: addresses.php"); exit();
}

// ── FETCH FOR EDIT ────────────────────────────────────────────────────────────
$edit_addr = null;
if (isset($_GET['edit'])) {
    $aid = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM addresses WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $aid, $user_id); $stmt->execute();
    $edit_addr = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

// ── FETCH ALL ADDRESSES ───────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$addresses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
?>
<?php include("../includes/header.php"); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
:root{--ink:#0f0f0f;--muted:#6b7280;--border:#e5e7eb;--accent:#131921;--green:#16a34a;--red:#ef4444;--radius:12px;}
*,*::before,*::after{box-sizing:border-box;}
.addr-page{font-family:'DM Sans',sans-serif;background:#f3f4f6;min-height:100vh;padding:2.5rem 0 5rem;}
.addr-grid{display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;}
@media(max-width:860px){.addr-grid{grid-template-columns:1fr;}}
.addr-card{background:#fff;border-radius:var(--radius);padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1rem;}
.addr-card-title{font-family:'Playfair Display',serif;font-size:1.05rem;color:var(--ink);margin-bottom:1rem;}
.back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--muted);text-decoration:none;margin-bottom:1.25rem;}
.back-link:hover{color:var(--ink);}
.page-heading{font-family:'Playfair Display',serif;font-size:1.65rem;color:var(--ink);margin-bottom:1.5rem;}
.field{display:flex;flex-direction:column;gap:.3rem;margin-bottom:.85rem;}
.field label{font-size:.8rem;font-weight:700;color:var(--ink);}
.field label .req{color:var(--red);margin-left:2px;}
.field input,.field select,.field textarea{padding:.6rem .85rem;border:.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.88rem;color:var(--ink);background:#fff;outline:none;transition:border-color .15s;width:100%;}
.field input:focus,.field select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(19,25,33,.08);}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;}
@media(max-width:480px){.field-row{grid-template-columns:1fr;}}
.save-btn{display:block;width:100%;padding:.8rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.92rem;font-weight:700;cursor:pointer;margin-top:.25rem;}
.save-btn:hover{background:#232f3e;}
.flash-ok{background:#d1fae5;color:#065f46;border-radius:8px;padding:.65rem 1rem;font-size:.84rem;font-weight:500;margin-bottom:1rem;}
.flash-err{background:#fee2e2;color:#991b1b;border-radius:8px;padding:.65rem 1rem;font-size:.84rem;font-weight:500;margin-bottom:1rem;}
.error-list{margin:0;padding-left:1.1rem;}
.error-list li{margin-bottom:.2rem;}
.addr-item{background:#fff;border-radius:10px;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:.75rem;display:flex;align-items:flex-start;gap:.75rem;border:1.5px solid transparent;position:relative;}
.addr-item.default{border-color:#131921;background:#f9fafb;}
.addr-icon{font-size:1.3rem;flex-shrink:0;margin-top:2px;}
.addr-info{flex:1;min-width:0;}
.addr-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:2px;}
.addr-name{font-size:.9rem;font-weight:700;color:var(--ink);}
.addr-line{font-size:.83rem;color:#555;margin-top:1px;line-height:1.4;}
.addr-phone{font-size:.78rem;color:var(--muted);margin-top:2px;}
.default-badge{display:inline-block;background:#131921;color:#fff;font-size:.68rem;font-weight:700;border-radius:4px;padding:1px 7px;margin-left:5px;}
.addr-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;}
.aa{display:inline-block;padding:4px 10px;border-radius:6px;font-size:.75rem;font-weight:600;text-decoration:none;cursor:pointer;border:none;transition:all .12s;}
.aa-edit{background:#e0f2fe;color:#0369a1;}
.aa-edit:hover{background:#0369a1;color:#fff;}
.aa-default{background:#d1fae5;color:#065f46;}
.aa-default:hover{background:#065f46;color:#fff;}
.aa-del{background:#fee2e2;color:#991b1b;}
.aa-del:hover{background:#991b1b;color:#fff;}
.check-row{display:flex;align-items:center;gap:.5rem;font-size:.83rem;color:var(--ink);cursor:pointer;margin-top:.25rem;}
.check-row input{accent-color:var(--accent);width:15px;height:15px;}
.empty-addr{text-align:center;padding:3rem 1rem;background:#fff;border-radius:10px;color:var(--muted);}
</style>

<div class="addr-page"><div class="container">
<a href="profile.php" class="back-link">← Back to Profile</a>
<h1 class="page-heading">My Address Book</h1>

<?php if (isset($_GET['updated'])): ?>
    <div class="flash-ok">✓ Default address updated.</div>
<?php endif; ?>

<div class="addr-grid">

  <!-- LEFT: Saved addresses -->
  <div>
    <?php if (!empty($addresses)): ?>
        <?php foreach ($addresses as $a): ?>
        <div class="addr-item <?= $a['is_default'] ? 'default' : '' ?>">
            <div class="addr-icon"><?= $a['is_default'] ? '🏠' : '📍' ?></div>
            <div class="addr-info">
                <div class="addr-label">
                    <?= htmlspecialchars($a['label'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($a['is_default']): ?>
                        <span class="default-badge">Default</span>
                    <?php endif; ?>
                </div>
                <div class="addr-name"><?= htmlspecialchars($a['recipient_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="addr-line">
                    <?= htmlspecialchars($a['address_line'], ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars($a['city'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!empty($a['zip'])): ?> – <?= htmlspecialchars($a['zip'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>,
                    <?= htmlspecialchars($a['country'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="addr-phone"><?= htmlspecialchars($a['phone'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="addr-actions">
                <a href="addresses.php?edit=<?= intval($a['id']) ?>" class="aa aa-edit">✏ Edit</a>
                <?php if (!$a['is_default']): ?>
                    <a href="addresses.php?set_default=<?= intval($a['id']) ?>" class="aa aa-default">★ Default</a>
                <?php endif; ?>
                <a href="addresses.php?delete=<?= intval($a['id']) ?>"
                   class="aa aa-del"
                   onclick="return confirm('Delete this address?')">✕</a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-addr">
            <div style="font-size:2.5rem;margin-bottom:.5rem">📭</div>
            <h5 style="font-weight:700;color:#0f0f0f">No saved addresses</h5>
            <p style="font-size:.85rem">Add your first delivery address using the form.</p>
        </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT: Form -->
  <div>
    <div class="addr-card">
      <div class="addr-card-title"><?= $edit_addr ? '✏️ Edit Address' : '➕ Add New Address' ?></div>

      <?php if (!empty($success)): ?>
        <div class="flash-ok">✓ <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if (!empty($errors)): ?>
        <div class="flash-err"><ul class="error-list"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form method="POST">
        <?php if ($edit_addr): ?>
            <input type="hidden" name="edit_id" value="<?= intval($edit_addr['id']) ?>">
        <?php endif; ?>

        <div class="field-row">
            <div class="field">
                <label>Label</label>
                <select name="label">
                    <?php foreach (['Home','Office','Other'] as $lbl): ?>
                    <option value="<?= $lbl ?>" <?= ($edit_addr['label'] ?? 'Home') === $lbl ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Recipient Name <span class="req">*</span></label>
                <input type="text" name="recipient_name" required
                       value="<?= htmlspecialchars($edit_addr['recipient_name'] ?? $_POST['recipient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <div class="field">
            <label>Phone <span class="req">*</span></label>
            <input type="tel" name="phone" required
                   value="<?= htmlspecialchars($edit_addr['phone'] ?? $_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="field">
            <label>Street Address <span class="req">*</span></label>
            <input type="text" name="address_line" required
                   placeholder="House / Road / Block / Area"
                   value="<?= htmlspecialchars($edit_addr['address_line'] ?? $_POST['address_line'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="field-row">
            <div class="field">
                <label>City <span class="req">*</span></label>
                <input type="text" name="city" required
                       value="<?= htmlspecialchars($edit_addr['city'] ?? $_POST['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>ZIP Code</label>
                <input type="text" name="zip" placeholder="e.g. 1207"
                       value="<?= htmlspecialchars($edit_addr['zip'] ?? $_POST['zip'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <div class="field">
            <label>Country</label>
            <select name="country">
                <?php foreach (['Bangladesh','India','Pakistan','Nepal','Sri Lanka','Other'] as $c): ?>
                <option value="<?= $c ?>" <?= ($edit_addr['country'] ?? 'Bangladesh') === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <label class="check-row">
            <input type="checkbox" name="is_default" value="1"
                   <?= !empty($edit_addr['is_default']) ? 'checked' : '' ?>>
            Set as default delivery address
        </label>

        <button type="submit" name="save_address" class="save-btn" style="margin-top:1rem">
            <?= $edit_addr ? 'Update Address' : 'Save Address' ?>
        </button>

        <?php if ($edit_addr): ?>
            <a href="addresses.php" style="display:block;text-align:center;font-size:.82rem;color:var(--muted);margin-top:.6rem;text-decoration:none">
                Cancel edit
            </a>
        <?php endif; ?>
      </form>
    </div>
  </div>

</div>
</div></div>
<?php include("../includes/footer.php"); ?>