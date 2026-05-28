<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] !== 'seller') {
    header("Location: ../user/home.php");
    exit();
}

$seller_id = intval($_SESSION['user_id']);

// ── Check available balance ───────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN payout_status = 'pending' THEN seller_net ELSE 0 END), 0) AS available,
        COALESCE(SUM(CASE WHEN payout_status = 'paid'    THEN seller_net ELSE 0 END), 0) AS paid_out
    FROM vendor_orders
    WHERE seller_id = ? AND status NOT IN ('cancelled')
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$balance = $stmt->get_result()->fetch_assoc();
$stmt->close();

$available = floatval($balance['available']);

// ── Check for existing pending request ───────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, amount, status, created_at FROM seller_payout_requests
    WHERE seller_id = ? AND status IN ('pending','approved','processing')
    ORDER BY created_at DESC LIMIT 1
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$existing_request = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Payout history ────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT * FROM seller_payout_requests
    WHERE seller_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Seller payout info (from seller_profiles) ─────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM seller_profiles WHERE user_id = ?");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

$errors      = [];
$submit_done = false;

// ── HANDLE SUBMISSION ─────────────────────────────────────────────────────────
if (isset($_POST['submit_request']) && !$existing_request) {

    $method       = $_POST['method'] ?? '';
    $account_info = trim(strip_tags($_POST['account_info'] ?? ''));
    $amount       = floatval($_POST['amount'] ?? 0);

    $valid_methods = ['bkash', 'nagad', 'bank'];

    if (!in_array($method, $valid_methods))  $errors[] = "Please select a valid payout method.";
    if (empty($account_info))                $errors[] = "Account / number is required.";
    if ($amount <= 0)                        $errors[] = "Please enter a valid amount.";
    if ($amount > $available)               $errors[] = "Amount exceeds your available balance of ৳" . number_format($available) . ".";
    if ($available <= 0)                    $errors[] = "You have no balance available to withdraw.";

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO seller_payout_requests
                (seller_id, amount, method, account_info, status)
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("idss", $seller_id, $amount, $method, $account_info);
        $stmt->execute();
        $request_id = $conn->insert_id;
        $stmt->close();

        // Notify admin
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        if ($admin) {
            $admin_id   = intval($admin['id']);
            $notif_msg  = "Seller payout request of ৳" . number_format($amount) . " via $method.";
            $notif_link = "/admin/manage_payout_requests.php";
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, 'payout_update', 'New Payout Request', ?, ?)
            ");
            $stmt->bind_param("iss", $admin_id, $notif_msg, $notif_link);
            $stmt->execute();
            $stmt->close();
        }

        $submit_done    = true;
        $existing_request = ['id' => $request_id, 'status' => 'pending'];
    }
}

$method_labels = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'bank' => 'Bank Transfer'];
$status_styles = [
    'pending'    => ['#fef9c3', '#854d0e'],
    'approved'   => ['#dbeafe', '#1e40af'],
    'processing' => ['#e0f2fe', '#0369a1'],
    'paid'       => ['#d1fae5', '#065f46'],
    'rejected'   => ['#fee2e2', '#991b1b'],
];
?>
<?php include("../includes/header.php"); ?>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">

<style>
:root{--ink:#0f0f0f;--muted:#6b7280;--border:#e5e7eb;--surface:#f9fafb;--accent:#2d6a4f;--radius:12px;}
*,*::before,*::after{box-sizing:border-box;}
.po-page{font-family:'DM Sans',sans-serif;background:#f3f4f6;min-height:100vh;padding:2.5rem 0 5rem;}
.po-grid{display:grid;grid-template-columns:1fr 300px;gap:1.5rem;align-items:start;}
@media(max-width:860px){.po-grid{grid-template-columns:1fr;}}
.po-card{background:#fff;border-radius:var(--radius);padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.1rem;}
.po-card-title{font-family:'Playfair Display',serif;font-size:1.05rem;color:var(--ink);margin-bottom:1.1rem;display:flex;align-items:center;gap:.5rem;}
.back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--muted);text-decoration:none;margin-bottom:1.25rem;font-weight:500;}
.back-link:hover{color:var(--ink);}
.page-heading{font-family:'Playfair Display',serif;font-size:1.6rem;color:var(--ink);margin-bottom:.35rem;}
.page-sub{font-size:.85rem;color:var(--muted);margin-bottom:1.5rem;}
.balance-box{background:linear-gradient(135deg,#1b4332,#2d6a4f);border-radius:var(--radius);padding:1.4rem 1.6rem;margin-bottom:1.25rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;}
.balance-label{font-size:.78rem;font-weight:700;color:#95d5b2;text-transform:uppercase;letter-spacing:.06em;}
.balance-amount{font-size:2rem;font-weight:800;color:#fff;}
.balance-sub{font-size:.78rem;color:#95d5b2;margin-top:2px;}
.field{display:flex;flex-direction:column;gap:.3rem;margin-bottom:1rem;}
.field label{font-size:.8rem;font-weight:700;color:var(--ink);}
.field label .req{color:#ef4444;margin-left:2px;}
.field input,.field select,.field textarea{padding:.65rem .85rem;border:.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--ink);background:#fff;outline:none;width:100%;transition:border-color .15s;}
.field input:focus,.field select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(45,106,79,.1);}
.field-hint{font-size:.75rem;color:var(--muted);}
.method-options{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;margin-bottom:1rem;}
.method-opt{position:relative;}
.method-opt input[type=radio]{position:absolute;opacity:0;pointer-events:none;}
.method-opt label{display:flex;flex-direction:column;align-items:center;gap:.3rem;padding:.85rem .5rem;border:.5px solid var(--border);border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600;color:var(--muted);text-align:center;transition:all .14s;}
.method-opt label .mi{font-size:1.4rem;}
.method-opt input:checked + label{border-color:var(--accent);background:#e9f7ef;color:var(--accent);}
.submit-btn{display:block;width:100%;padding:.9rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;transition:background .14s;}
.submit-btn:hover{background:#1b4332;}
.error-box{background:#fee2e2;border-radius:8px;padding:.8rem 1rem;margin-bottom:1rem;}
.error-box ul{margin:0;padding-left:1.1rem;}
.error-box li{font-size:.83rem;color:#991b1b;font-weight:500;margin-bottom:.2rem;}
.success-box{background:#d1fae5;border-radius:10px;padding:1.5rem;text-align:center;margin-bottom:1.25rem;}
.success-box h4{font-family:'Playfair Display',serif;color:#065f46;margin-bottom:.35rem;}
.success-box p{font-size:.84rem;color:#047857;margin:0;}
.existing-box{background:#fef9c3;border-radius:10px;padding:1.1rem 1.25rem;border:.5px solid #fde68a;margin-bottom:1.25rem;}
.existing-box h5{font-size:.92rem;font-weight:700;color:#854d0e;margin-bottom:.25rem;}
.existing-box p{font-size:.82rem;color:#92400e;margin:0;}
.sum-row{display:flex;justify-content:space-between;font-size:.85rem;color:var(--muted);margin-bottom:.35rem;}
.sum-row.total{font-weight:700;color:var(--ink);padding-top:.4rem;border-top:.5px solid var(--border);margin-top:.4rem;}
.hist-table{width:100%;border-collapse:collapse;}
.hist-table th{font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;padding:.4rem 0;border-bottom:.5px solid var(--border);text-align:left;}
.hist-table td{padding:.65rem 0;border-bottom:.5px solid #f7f8fa;font-size:.82rem;vertical-align:middle;}
.hist-table tr:last-child td{border-bottom:none;}
.st-pill{display:inline-block;border-radius:5px;padding:2px 8px;font-size:.72rem;font-weight:700;}
</style>

<div class="po-page"><div class="container">

<a href="earnings.php" class="back-link">← Back to Earnings</a>
<h1 class="page-heading">Request Payout</h1>
<div class="page-sub">Withdraw your earned balance to your preferred account.</div>

<!-- Balance box -->
<div class="balance-box">
    <div>
        <div class="balance-label">Available Balance</div>
        <div class="balance-amount">৳ <?= number_format($available) ?></div>
        <div class="balance-sub">Paid out so far: ৳ <?= number_format($balance['paid_out']) ?></div>
    </div>
    <?php if ($available <= 0): ?>
        <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:.6rem 1rem;font-size:.82rem;color:#95d5b2;font-weight:600;">
            No balance available
        </div>
    <?php endif; ?>
</div>

<?php if ($submit_done): ?>
<div class="success-box">
    <div style="font-size:2rem;margin-bottom:.4rem">✅</div>
    <h4>Payout Request Submitted</h4>
    <p>Our team will review and process your request within 2–3 business days. You'll be notified when it's approved.</p>
</div>
<?php elseif ($existing_request): ?>
<div class="existing-box">
    <h5>⏳ Payout Request In Progress</h5>
    <p>You already have an active payout request (৳<?= number_format($existing_request['amount'] ?? 0) ?>) with status: <strong><?= ucfirst($existing_request['status']) ?></strong>. Please wait for it to complete before submitting another.</p>
</div>
<?php elseif ($available <= 0): ?>
<div class="existing-box" style="background:#fff7ed;border-color:#fdba74;">
    <h5 style="color:#9a3412;">No Balance Available</h5>
    <p style="color:#9a3412;">You don't have any pending earnings to withdraw yet. Complete more orders to build your balance.</p>
</div>
<?php else: ?>

<?php if (!empty($errors)): ?>
<div class="error-box"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST">
<div class="po-grid">

  <!-- LEFT -->
  <div>
    <div class="po-card">
      <div class="po-card-title">💳 Payout Method</div>
      <div class="method-options">
        <div class="method-opt">
          <input type="radio" name="method" id="m-bkash" value="bkash"
                 <?= ($_POST['method'] ?? '') === 'bkash' ? 'checked' : '' ?> required>
          <label for="m-bkash"><span class="mi">📱</span>bKash</label>
        </div>
        <div class="method-opt">
          <input type="radio" name="method" id="m-nagad" value="nagad"
                 <?= ($_POST['method'] ?? '') === 'nagad' ? 'checked' : '' ?>>
          <label for="m-nagad"><span class="mi">📲</span>Nagad</label>
        </div>
        <div class="method-opt">
          <input type="radio" name="method" id="m-bank" value="bank"
                 <?= ($_POST['method'] ?? '') === 'bank' ? 'checked' : '' ?>>
          <label for="m-bank"><span class="mi">🏦</span>Bank</label>
        </div>
      </div>

      <div class="field">
        <label>Account Number / Details <span class="req">*</span></label>
        <input type="text" name="account_info"
               placeholder="e.g. 01XXXXXXXXX or Bank: XXXXXXXX / Branch: Mirpur"
               value="<?= htmlspecialchars($_POST['account_info'] ?? $profile['bkash_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               required>
        <span class="field-hint">Enter the mobile number for bKash/Nagad, or account number + branch for bank transfer.</span>
      </div>

      <div class="field">
        <label>Amount to Withdraw <span class="req">*</span></label>
        <input type="number" name="amount" step="0.01" min="100"
               max="<?= $available ?>"
               placeholder="Minimum ৳100"
               value="<?= htmlspecialchars($_POST['amount'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               required>
        <span class="field-hint">Available: ৳ <?= number_format($available) ?></span>
      </div>

      <button type="submit" name="submit_request" class="submit-btn">Submit Payout Request →</button>
    </div>
  </div>

  <!-- RIGHT -->
  <div>
    <div class="po-card">
      <div class="po-card-title">📜 Payout Policy</div>
      <div style="font-size:.81rem;color:var(--muted);line-height:1.7;">
        <p>✅ Minimum withdrawal: <strong>৳ 100</strong></p>
        <p>⏱ Processing time: <strong>2–3 business days</strong></p>
        <p>📱 bKash/Nagad: instant after approval</p>
        <p>🏦 Bank transfer: 1–2 extra days</p>
        <p>🔒 Only one active request at a time</p>
        <p>💰 Commission is deducted before your net earnings are calculated</p>
      </div>
    </div>

    <div class="po-card">
      <div class="po-card-title">💰 Balance Summary</div>
      <div class="sum-row"><span>Gross Revenue</span><span>৳ <?= number_format(floatval($balance['available']) + floatval($balance['paid_out'])) ?></span></div>
      <div class="sum-row"><span>Already Paid Out</span><span>৳ <?= number_format($balance['paid_out']) ?></span></div>
      <div class="sum-row total"><span>Available Now</span><span style="color:#2d6a4f">৳ <?= number_format($available) ?></span></div>
    </div>
  </div>

</div>
</form>
<?php endif; ?>

<!-- Payout history -->
<?php if (!empty($history)): ?>
<div class="po-card" style="margin-top:.5rem;">
    <div class="po-card-title">📋 Payout History</div>
    <div style="overflow-x:auto;">
    <table class="hist-table">
        <thead>
            <tr><th>ID</th><th>Amount</th><th>Method</th><th>Account</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h):
            $ss = $status_styles[$h['status']] ?? ['#f0f0f0','#555'];
        ?>
        <tr>
            <td style="font-weight:700;color:#2d6a4f">#<?= intval($h['id']) ?></td>
            <td style="font-weight:700;color:#1a7a4a">৳ <?= number_format($h['amount']) ?></td>
            <td><?= htmlspecialchars($method_labels[$h['method']] ?? $h['method'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="color:#6b7280;font-size:.79rem"><?= htmlspecialchars($h['account_info'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>
                <span class="st-pill" style="background:<?= $ss[0] ?>;color:<?= $ss[1] ?>">
                    <?= ucfirst(htmlspecialchars($h['status'], ENT_QUOTES, 'UTF-8')) ?>
                </span>
            </td>
            <td style="color:#9ca3af"><?= date('d M Y', strtotime($h['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div></div>
<?php include("../includes/footer.php"); ?>