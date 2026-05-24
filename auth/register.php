<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("../config/db.php");

if (isset($_SESSION['user_id'])) {
    header("Location: ../user/home.php");
    exit();
}

if (isset($_POST['register'])) {
    $name     = mysqli_real_escape_string($conn, trim($_POST['name']));
    $email    = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = $_POST['password'];
    $phone    = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $city     = mysqli_real_escape_string($conn, trim($_POST['city'] ?? ''));

    if (empty($name) || empty($email) || empty($password)) {
        $error = "All fields are required!";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters!";
    } else {
        $check = $conn->query("SELECT id FROM users WHERE email = '$email'");
        if ($check->num_rows > 0) {
            $error = "Email already registered!";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (name, email, password, phone, city, role) 
                    VALUES ('$name', '$email', '$hashed', '$phone', '$city', 'user')";
            if ($conn->query($sql)) {
                $success = "Account created! You can now <a href='login.php' style='color:#1a7a4a;font-weight:700;'>sign in here</a>.";
            } else {
                $error = "Registration failed: " . $conn->error;
            }
        }
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
    --panel-start:  #0d2137;
    --panel-mid:    #0f3460;
    --panel-end:    #1565c0;
}

/* ===== WRAPPER ===== */
.auth-wrapper {
    min-height: calc(100vh - 65px);
    display: flex;
    align-items: stretch;
    background: #f0f2f8;
    overflow: hidden;
}

/* ===== LEFT PANEL ===== */
.auth-left {
    width: 38%;
    background: linear-gradient(150deg, var(--panel-start) 0%, var(--panel-mid) 55%, var(--panel-end) 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 36px;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
}

.deco { position: absolute; border-radius: 50%; background: #fff; }
.deco-1 { width: 300px; height: 300px; opacity: 0.05; top: -90px; right: -90px; animation: floatA 7s ease-in-out infinite; }
.deco-2 { width: 200px; height: 200px; opacity: 0.06; bottom: -60px; left: -60px; animation: floatA 9s ease-in-out infinite reverse; }
.deco-3 { width: 110px; height: 110px; opacity: 0.04; bottom: 28%; right: -20px; animation: floatA 5.5s ease-in-out infinite 1.5s; }
.deco-4 { width: 70px;  height: 70px;  opacity: 0.06; top: 28%;  left: 18px;  animation: floatA 6s ease-in-out infinite 0.5s; }

@keyframes floatA {
    0%, 100% { transform: translateY(0) scale(1); }
    50%       { transform: translateY(-20px) scale(1.04); }
}

.left-badge {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.18);
    color: rgba(255,255,255,0.85);
    border-radius: 50px;
    padding: 6px 18px;
    font-size: 0.73rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 24px;
    backdrop-filter: blur(6px);
    animation: fadeUp 0.5s ease 0.2s both;
}
.left-icon-wrap {
    font-size: 4.2rem;
    margin-bottom: 20px;
    animation: popIn 0.6s cubic-bezier(0.34,1.56,0.64,1) 0.35s both;
    filter: drop-shadow(0 8px 24px rgba(0,0,0,0.3));
}
.left-title {
    color: #fff;
    font-size: 1.75rem;
    font-weight: 800;
    text-align: center;
    margin-bottom: 10px;
    line-height: 1.25;
    animation: fadeUp 0.5s ease 0.45s both;
    letter-spacing: -0.3px;
}
.left-desc {
    color: rgba(255,255,255,0.62);
    font-size: 0.86rem;
    text-align: center;
    line-height: 1.7;
    margin-bottom: 32px;
    max-width: 260px;
    animation: fadeUp 0.5s ease 0.55s both;
}
.left-perks {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    animation: fadeUp 0.5s ease 0.65s both;
}
.left-perk {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 10px 14px;
    color: rgba(255,255,255,0.88);
    font-size: 0.83rem;
    font-weight: 500;
    backdrop-filter: blur(4px);
    transition: background 0.2s;
}
.left-perk:hover { background: rgba(255,255,255,0.14); }
.left-perk i { font-size: 1.15rem; opacity: 0.85; flex-shrink: 0; }
.left-perk span { opacity: 0.65; font-size: 0.73rem; display: block; margin-top: 1px; }

/* ===== RIGHT PANEL ===== */
.auth-right {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 36px 28px 44px;
    overflow-y: auto;
    animation: fadeUp 0.5s ease 0.1s both;
}

.auth-form-wrap { width: 100%; max-width: 420px; }

.form-eyebrow {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--accent);
    font-weight: 800;
    margin-bottom: 6px;
}
.form-title {
    font-size: 1.7rem;
    font-weight: 800;
    color: #1a1a2e;
    margin-bottom: 4px;
    letter-spacing: -0.4px;
    line-height: 1.2;
}
.form-subtitle {
    color: #9aa3b5;
    font-size: 0.86rem;
    margin-bottom: 26px;
}

/* ===== INPUTS ===== */
.form-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}

.auth-input {
    position: relative;
    margin-bottom: 14px;
}
.auth-input.no-mb { margin-bottom: 0; }

.auth-input .inp-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #c0c8d8;
    font-size: 1rem;
    pointer-events: none;
    z-index: 2;
    transition: color 0.2s;
}
.auth-input input {
    width: 100%;
    padding: 13px 14px 13px 42px;
    border-radius: 10px;
    border: 1.5px solid #e4e8f0;
    font-size: 0.88rem;
    background: #f8fafd;
    color: #1a1a2e;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    box-sizing: border-box;
}
.auth-input input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.1);
    background: #fff;
}
.auth-input:focus-within .inp-icon { color: var(--accent); }
.auth-input .pw-toggle {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    padding: 0;
    color: #c0c8d8;
    font-size: 1rem;
    cursor: pointer;
    z-index: 2;
    transition: color 0.2s;
    line-height: 1;
}
.auth-input .pw-toggle:hover { color: var(--accent); }

/* Password strength */
.pw-strength { margin-top: 6px; display: flex; gap: 5px; align-items: center; }
.pw-strength-bar {
    flex: 1; height: 3px; border-radius: 99px; background: #e4e8f0;
    transition: background 0.3s;
}
.pw-strength-label { font-size: 0.72rem; color: #b0bac8; min-width: 44px; text-align: right; }

/* ===== BUTTON ===== */
.btn-auth {
    width: 100%;
    padding: 13px;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, var(--accent-mid), var(--accent));
    color: #fff;
    font-size: 0.93rem;
    font-weight: 700;
    letter-spacing: 0.2px;
    cursor: pointer;
    transition: transform 0.15s, box-shadow 0.2s;
    margin-top: 6px;
    position: relative;
    overflow: hidden;
}
.btn-auth::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.08) 50%, transparent 100%);
    transform: translateX(-100%);
    transition: transform 0.5s ease;
}
.btn-auth:hover::before { transform: translateX(100%); }
.btn-auth:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(var(--accent-rgb), 0.35);
}
.btn-auth:active { transform: translateY(0); }

/* ===== ALERTS ===== */
.auth-alert {
    border-radius: 10px;
    padding: 12px 15px;
    font-size: 0.86rem;
    font-weight: 500;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 9px;
    animation: slideDown 0.3s ease;
}
.auth-alert.is-error   { background: #fdecea; color: #842029; border: 1px solid #f5c2c7; }
.auth-alert.is-success { background: #e9f7ef; color: #1a7a4a; border: 1px solid #b8dfc8; }
.auth-alert i { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }

/* ===== DIVIDER ===== */
.auth-divider {
    display: flex; align-items: center; gap: 12px;
    color: #d0d5e0; font-size: 0.78rem; margin: 20px 0;
}
.auth-divider::before, .auth-divider::after {
    content: ''; flex: 1; height: 1px; background: #e8ecf4;
}

/* ===== LINKS ===== */
.auth-links {
    text-align: center;
    font-size: 0.84rem;
    color: #9aa3b5;
    line-height: 2.1;
}
.auth-links a {
    color: var(--accent);
    font-weight: 700;
    text-decoration: none;
    transition: opacity 0.15s;
}
.auth-links a:hover { opacity: 0.75; }

.role-link-row { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
.role-link-pill {
    background: #f0f2f8;
    color: var(--accent) !important;
    border-radius: 50px;
    padding: 5px 14px;
    font-size: 0.78rem !important;
    font-weight: 700 !important;
    text-decoration: none;
    border: 1.5px solid #e0e5f0;
    transition: all 0.15s !important;
    display: inline-flex; align-items: center; gap: 4px;
}
.role-link-pill:hover {
    background: var(--accent) !important;
    color: #fff !important;
    border-color: var(--accent) !important;
    opacity: 1 !important;
}

/* ===== SECTION LABEL ===== */
.section-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #b0bac8;
    margin-bottom: 10px;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-label::after { content: ''; flex: 1; height: 1px; background: #edf0f7; }

/* ===== ANIMATIONS ===== */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes popIn {
    from { opacity: 0; transform: scale(0.55) rotate(-8deg); }
    to   { opacity: 1; transform: scale(1) rotate(0deg); }
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ===== MOBILE ===== */
@media (max-width: 768px) {
    .auth-wrapper   { flex-direction: column; min-height: auto; }
    .auth-left      { width: 100%; padding: 32px 24px 28px; }
    .left-perks     { display: none; }
    .left-desc      { display: none; }
    .left-title     { font-size: 1.4rem; margin-bottom: 4px; }
    .left-icon-wrap { font-size: 3rem; margin-bottom: 10px; }
    .auth-right     { padding: 28px 20px 44px; }
    .form-row-2     { grid-template-columns: 1fr; }
}
</style>

<div class="auth-wrapper">

    <!-- LEFT PANEL -->
    <div class="auth-left">
        <div class="deco deco-1"></div>
        <div class="deco deco-2"></div>
        <div class="deco deco-3"></div>
        <div class="deco deco-4"></div>

        <div class="left-badge">Buyer Account</div>
        <div class="left-icon-wrap">🛍️</div>
        <div class="left-title">Join Us Today</div>
        <div class="left-desc">Create your free buyer account and start shopping from thousands of products.</div>

        <div class="left-perks">
            <div class="left-perk">
                <i class="bi bi-bag-heart-fill"></i>
                <div>Shop with Ease
                    <span>Browse thousands of products</span>
                </div>
            </div>
            <div class="left-perk">
                <i class="bi bi-truck"></i>
                <div>Track Orders
                    <span>Real-time order updates</span>
                </div>
            </div>
            <div class="left-perk">
                <i class="bi bi-shield-check"></i>
                <div>Secure Checkout
                    <span>Safe & protected payments</span>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="auth-right">
        <div class="auth-form-wrap">

            <div class="form-eyebrow">Create Account</div>
            <div class="form-title">Register as Buyer</div>
            <div class="form-subtitle">Fill in your details to get started — it's free!</div>

            <?php if (isset($success)): ?>
                <div class="auth-alert is-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?= $success ?></div>
                </div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="auth-alert is-error">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="registerForm">

                <div class="section-label">Account Info</div>

                <div class="auth-input">
                    <i class="bi bi-person inp-icon"></i>
                    <input type="text" name="name" placeholder="Full Name" required autocomplete="name">
                </div>

                <div class="auth-input">
                    <i class="bi bi-envelope inp-icon"></i>
                    <input type="email" name="email" placeholder="Email Address" required autocomplete="email">
                </div>

                <div class="auth-input">
                    <i class="bi bi-lock inp-icon"></i>
                    <input type="password" name="password" id="pw-reg" placeholder="Password (min 6 chars)" required autocomplete="new-password" oninput="checkStrength(this.value)">
                    <button type="button" class="pw-toggle" onclick="togglePw('pw-reg', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="pw-strength" id="pwStrength" style="display:none;">
                    <div class="pw-strength-bar" id="bar1"></div>
                    <div class="pw-strength-bar" id="bar2"></div>
                    <div class="pw-strength-bar" id="bar3"></div>
                    <div class="pw-strength-bar" id="bar4"></div>
                    <div class="pw-strength-label" id="pwLabel"></div>
                </div>

                <div class="section-label" style="margin-top:18px;">Optional Details</div>

                <div class="form-row-2">
                    <div class="auth-input no-mb">
                        <i class="bi bi-telephone inp-icon"></i>
                        <input type="text" name="phone" placeholder="Phone Number" autocomplete="tel">
                    </div>
                    <div class="auth-input no-mb">
                        <i class="bi bi-geo-alt inp-icon"></i>
                        <input type="text" name="city" placeholder="City" autocomplete="address-level2">
                    </div>
                </div>

                <button type="submit" name="register" class="btn-auth" style="margin-top:20px;">
                    Create Buyer Account &nbsp;<i class="bi bi-arrow-right"></i>
                </button>

            </form>

            <div class="auth-divider">already have an account?</div>

            <div class="auth-links">
                <a href="login.php" style="font-size:0.9rem;">← Sign In to your account</a>

                <div style="margin-top:14px; font-size:0.8rem; color:#b0bac8;">Want a different role?</div>
                <div class="role-link-row">
                    <a href="seller_register.php" class="role-link-pill">
                        <i class="bi bi-shop"></i> Seller
                    </a>
                    <a href="category_manager_register.php" class="role-link-pill">
                        <i class="bi bi-grid"></i> Category Manager
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function checkStrength(val) {
    const wrap = document.getElementById('pwStrength');
    if (!val) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';

    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val) && /[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const colors = ['#e53935','#fb8c00','#fdd835','#43a047'];
    const labels = ['Weak','Fair','Good','Strong'];
    const bars   = ['bar1','bar2','bar3','bar4'];

    bars.forEach((id, i) => {
        document.getElementById(id).style.background = i < score ? colors[score - 1] : '#e4e8f0';
    });
    document.getElementById('pwLabel').textContent  = labels[score - 1] || '';
    document.getElementById('pwLabel').style.color  = colors[score - 1] || '#b0bac8';
}
</script>

<?php include("../includes/footer.php"); ?>