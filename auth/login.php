<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("../config/db.php");

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
    } elseif ($_SESSION['role'] === 'seller') {
        header("Location: ../seller/dashboard.php");
    } elseif ($_SESSION['role'] === 'category_manager') {
        header("Location: ../category_manager/dashboard.php");
    } else {
        header("Location: ../user/home.php");
    }
    exit();
}

if (isset($_POST['login'])) {
    $email    = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = $_POST['password'];

    $result = $conn->query("SELECT * FROM users WHERE email = '$email'");

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {

            // Block pending sellers from logging in
            if ($user['role'] === 'seller' && $user['seller_status'] === 'pending') {
                $error = "Your seller account is pending admin approval. Please wait for confirmation.";

            // Block rejected sellers
            } elseif ($user['role'] === 'seller' && $user['seller_status'] === 'rejected') {
                $error = "Your seller application was rejected. Please contact support.";

            // Block pending category managers from logging in
            } elseif ($user['role'] === 'category_manager' && ($user['seller_status'] === 'pending' || $user['seller_status'] === null)) {
                $error = "Your Category Manager account is pending admin approval. Please wait for confirmation.";

            // Block rejected category managers
            } elseif ($user['role'] === 'category_manager' && $user['seller_status'] === 'rejected') {
                $error = "Your Category Manager application was rejected. Please contact support.";

            } else {
                // All good — set session and redirect
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['name']          = $user['name'];
                $_SESSION['seller_status'] = $user['seller_status'] ?? null;

                if ($user['role'] === 'admin') {
                    header("Location: ../admin/dashboard.php");
                } elseif ($user['role'] === 'seller') {
                    header("Location: ../seller/dashboard.php");
                } elseif ($user['role'] === 'category_manager') {
                    header("Location: ../category_manager/dashboard.php");
                } else {
                    header("Location: ../user/home.php");
                }
                exit();
            }

        } else {
            $error = "Invalid email or password!";
        }
    } else {
        $error = "Invalid email or password!";
    }
}

$hide_filter = true;
include("../includes/header.php");
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
/* ===== THEME ===== */
:root {
    --accent:       #0f3460;
    --accent-mid:   #16213e;
    --accent-dark:  #1a1a2e;
    --accent-rgb:   15, 52, 96;
    --panel-start:  #1a1a2e;
    --panel-mid:    #16213e;
    --panel-end:    #0f3460;
}

.auth-wrapper {
    min-height: calc(100vh - 65px);
    display: flex;
    align-items: stretch;
    background: #f0f2f8;
    overflow: hidden;
}

.auth-left {
    width: 42%;
    background: linear-gradient(150deg, var(--panel-start) 0%, var(--panel-mid) 50%, var(--panel-end) 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 40px;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
}

.deco { position: absolute; border-radius: 50%; background: #fff; }
.deco-1 { width: 320px; height: 320px; opacity: 0.05; top: -100px; right: -100px; animation: floatA 7s ease-in-out infinite; }
.deco-2 { width: 220px; height: 220px; opacity: 0.06; bottom: -70px; left: -70px; animation: floatA 9s ease-in-out infinite reverse; }
.deco-3 { width: 130px; height: 130px; opacity: 0.04; bottom: 25%; right: -30px; animation: floatA 5.5s ease-in-out infinite 1.5s; }
.deco-4 { width: 80px;  height: 80px;  opacity: 0.06; top: 30%;  left: 20px;  animation: floatA 6s ease-in-out infinite 0.5s; }
@keyframes floatA { 0%, 100% { transform: translateY(0) scale(1); } 50% { transform: translateY(-22px) scale(1.04); } }

.left-badge { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.18); color: rgba(255,255,255,0.85); border-radius: 50px; padding: 6px 18px; font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 28px; backdrop-filter: blur(6px); animation: fadeUp 0.5s ease 0.2s both; }
.left-icon-wrap { font-size: 4.5rem; margin-bottom: 22px; animation: popIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.35s both; filter: drop-shadow(0 8px 24px rgba(0,0,0,0.3)); }
.left-title { color: #fff; font-size: 1.9rem; font-weight: 800; text-align: center; margin-bottom: 10px; line-height: 1.25; animation: fadeUp 0.5s ease 0.45s both; letter-spacing: -0.3px; }
.left-desc { color: rgba(255,255,255,0.62); font-size: 0.88rem; text-align: center; line-height: 1.7; margin-bottom: 36px; max-width: 280px; animation: fadeUp 0.5s ease 0.55s both; }
.left-roles { display: flex; flex-direction: column; gap: 10px; width: 100%; animation: fadeUp 0.5s ease 0.65s both; }
.left-role { display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 11px 16px; color: rgba(255,255,255,0.88); font-size: 0.85rem; font-weight: 500; backdrop-filter: blur(4px); transition: background 0.2s; }
.left-role:hover { background: rgba(255,255,255,0.14); }
.left-role i { font-size: 1.2rem; opacity: 0.85; flex-shrink: 0; }
.left-role span { opacity: 0.65; font-size: 0.75rem; display: block; margin-top: 1px; }

.auth-right { flex: 1; display: flex; align-items: center; justify-content: center; padding: 44px 28px; animation: fadeUp 0.5s ease 0.1s both; }
.auth-form-wrap { width: 100%; max-width: 400px; }

.form-eyebrow { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1.2px; color: var(--accent); font-weight: 800; margin-bottom: 6px; }
.form-title { font-size: 1.75rem; font-weight: 800; color: #1a1a2e; margin-bottom: 4px; letter-spacing: -0.4px; line-height: 1.2; }
.form-subtitle { color: #9aa3b5; font-size: 0.87rem; margin-bottom: 30px; }

.auth-input { position: relative; margin-bottom: 14px; }
.auth-input .inp-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #c0c8d8; font-size: 1rem; pointer-events: none; z-index: 2; transition: color 0.2s; }
.auth-input input { width: 100%; padding: 13px 44px; border-radius: 10px; border: 1.5px solid #e4e8f0; font-size: 0.9rem; background: #f8fafd; color: #1a1a2e; outline: none; transition: border-color 0.2s, box-shadow 0.2s, background 0.2s; }
.auth-input input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.1); background: #fff; }
.auth-input:focus-within .inp-icon { color: var(--accent); }
.auth-input .pw-toggle { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; padding: 0; color: #c0c8d8; font-size: 1rem; cursor: pointer; z-index: 2; transition: color 0.2s; line-height: 1; }
.auth-input .pw-toggle:hover { color: var(--accent); }

.btn-auth { width: 100%; padding: 13px; border-radius: 10px; border: none; background: linear-gradient(135deg, var(--accent-mid), var(--accent)); color: #fff; font-size: 0.95rem; font-weight: 700; letter-spacing: 0.2px; cursor: pointer; transition: transform 0.15s, box-shadow 0.2s; margin-top: 8px; position: relative; overflow: hidden; }
.btn-auth::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.08) 50%, transparent 100%); transform: translateX(-100%); transition: transform 0.5s ease; }
.btn-auth:hover::before { transform: translateX(100%); }
.btn-auth:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(var(--accent-rgb), 0.35); }
.btn-auth:active { transform: translateY(0); }

.auth-alert { border-radius: 10px; padding: 12px 15px; font-size: 0.86rem; font-weight: 500; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 9px; animation: slideDown 0.3s ease; }
.auth-alert.is-error   { background: #fdecea; color: #842029; border: 1px solid #f5c2c7; }
.auth-alert.is-warning { background: #fff8e1; color: #7c5700; border: 1px solid #ffe082; }
.auth-alert i { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }

.auth-divider { display: flex; align-items: center; gap: 12px; color: #d0d5e0; font-size: 0.78rem; margin: 22px 0; }
.auth-divider::before, .auth-divider::after { content: ''; flex: 1; height: 1px; background: #e8ecf4; }

.auth-links { text-align: center; font-size: 0.84rem; color: #9aa3b5; line-height: 2.1; }
.auth-links a { color: var(--accent); font-weight: 700; text-decoration: none; transition: opacity 0.15s; }
.auth-links a:hover { opacity: 0.75; }

.role-link-row { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
.role-link-pill { background: #f0f2f8; color: var(--accent) !important; border-radius: 50px; padding: 5px 14px; font-size: 0.78rem !important; font-weight: 700 !important; text-decoration: none; border: 1.5px solid #e0e5f0; transition: all 0.15s !important; display: inline-flex; align-items: center; gap: 4px; }
.role-link-pill:hover { background: var(--accent) !important; color: #fff !important; border-color: var(--accent) !important; opacity: 1 !important; }

@keyframes fadeUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }
@keyframes popIn { from { opacity: 0; transform: scale(0.55) rotate(-8deg); } to { opacity: 1; transform: scale(1) rotate(0deg); } }
@keyframes slideDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 768px) {
    .auth-wrapper { flex-direction: column; min-height: auto; }
    .auth-left { width: 100%; padding: 36px 24px 32px; min-height: auto; }
    .left-roles, .left-desc { display: none; }
    .left-title { font-size: 1.45rem; margin-bottom: 4px; }
    .left-icon-wrap { font-size: 3rem; margin-bottom: 12px; }
    .auth-right { padding: 32px 20px 48px; }
}
</style>

<div class="auth-wrapper">

    <!-- LEFT PANEL -->
    <div class="auth-left">
        <div class="deco deco-1"></div><div class="deco deco-2"></div>
        <div class="deco deco-3"></div><div class="deco deco-4"></div>

        <div class="left-badge">Your Marketplace</div>
        <div class="left-icon-wrap">🛒</div>
        <div class="left-title">Welcome Back</div>
        <div class="left-desc">Sign in to manage your store, orders, and everything in between.</div>

        <div class="left-roles">
            <div class="left-role"><i class="bi bi-shield-fill-check"></i><div>Admin Panel<span>Full platform control</span></div></div>
            <div class="left-role"><i class="bi bi-shop-window"></i><div>Seller Dashboard<span>Manage products & orders</span></div></div>
            <div class="left-role"><i class="bi bi-person-fill"></i><div>Buyer Account<span>Shop and track orders</span></div></div>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="auth-right">
        <div class="auth-form-wrap">

            <div class="form-eyebrow">Sign In</div>
            <div class="form-title">Good to see you again</div>
            <div class="form-subtitle">Enter your credentials to continue</div>

            <?php if (isset($error)): ?>
                <?php
                // Use warning style for pending/rejected, error style for wrong credentials
                $isWarning = (isset($error) && (
                    str_contains($error, 'pending') || str_contains($error, 'rejected')
                ));
                ?>
                <div class="auth-alert <?= $isWarning ? 'is-warning' : 'is-error' ?>">
                    <i class="bi <?= $isWarning ? 'bi-hourglass-split' : 'bi-exclamation-circle-fill' ?>"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <div class="auth-input">
                    <i class="bi bi-envelope inp-icon"></i>
                    <input type="email" name="email" placeholder="Email address" required autocomplete="email">
                </div>
                <div class="auth-input">
                    <i class="bi bi-lock inp-icon"></i>
                    <input type="password" name="password" id="pw-login" placeholder="Password" required autocomplete="current-password">
                    <button type="button" class="pw-toggle" onclick="togglePw('pw-login', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <button type="submit" name="login" class="btn-auth">
                    Sign In &nbsp;<i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <div class="auth-divider">or register as</div>

            <div class="auth-links">
                <div class="role-link-row">
                    <a href="register.php" class="role-link-pill"><i class="bi bi-person"></i> Buyer</a>
                    <a href="seller_register.php" class="role-link-pill"><i class="bi bi-shop"></i> Seller</a>
                    <a href="category_manager_register.php" class="role-link-pill"><i class="bi bi-grid"></i> Category Manager</a>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') { input.type = 'text'; icon.className = 'bi bi-eye-slash'; }
    else { input.type = 'password'; icon.className = 'bi bi-eye'; }
}
</script>

<?php include("../includes/footer.php"); ?>