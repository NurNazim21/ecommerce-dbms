<?php
include("../includes/auth_check.php");
include("../config/db.php");

$user_id = intval($_SESSION['user_id']);

// ── MARK ALL AS READ ──────────────────────────────────────────────────────────
if (isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: notifications.php?cleared=1");
    exit();
}

// ── MARK SINGLE AS READ ───────────────────────────────────────────────────────
if (isset($_GET['read']) && intval($_GET['read']) > 0) {
    $nid  = intval($_GET['read']);
    $redir = $_GET['link'] ?? '';
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $nid, $user_id);
    $stmt->execute();
    $stmt->close();
    if ($redir) {
    $app_base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    header("Location: " . $app_base . $redir);
    exit();
}
}

// ── FETCH NOTIFICATIONS ───────────────────────────────────────────────────────
$filter   = $_GET['filter'] ?? 'all';
$where    = "WHERE user_id = ?";
if ($filter === 'unread') $where .= " AND is_read = 0";
if ($filter === 'read')   $where .= " AND is_read = 1";

$stmt = $conn->prepare("
    SELECT * FROM notifications
    $where
    ORDER BY created_at DESC
    LIMIT 60
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Unread count
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_count = intval($stmt->get_result()->fetch_assoc()['c']);
$stmt->close();

// Icon map by type
$type_icons = [
    'order_placed'       => ['🛍',  '#d1fae5', '#065f46'],
    'order_status_update'=> ['📦',  '#dbeafe', '#1e40af'],
    'shipment_update'    => ['🚚',  '#e0f2fe', '#0369a1'],
    'refund_request'     => ['↩',  '#fee2e2', '#991b1b'],
    'refund_submitted'   => ['✅',  '#d1fae5', '#065f46'],
    'refund_update'      => ['↩',  '#fef9c3', '#854d0e'],
    'new_vendor_order'   => ['🏪',  '#f3e8ff', '#6d28d9'],
    'order_update'       => ['📋',  '#fff7ed', '#c2410c'],
];
?>
<?php include("../includes/header.php"); ?>

<style>
.notif-page{background:#f3f4f6;min-height:100vh;padding:2rem 0 5rem;font-family:'DM Sans',sans-serif;}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;}
.page-header h1{font-size:1.5rem;font-weight:800;color:#0f0f0f;margin:0;}
.filter-tabs{display:flex;gap:8px;margin-bottom:1.25rem;}
.ftab{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:50px;font-size:.81rem;font-weight:600;text-decoration:none;color:#6b7280;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.07);border:2px solid transparent;transition:all .13s;}
.ftab.active{background:#131921;color:#fff;border-color:#131921;}
.ftab .cnt{background:rgba(0,0,0,.08);border-radius:50px;padding:1px 7px;font-size:.71rem;font-weight:700;}
.ftab.active .cnt{background:rgba(255,255,255,.2);}
.notif-list{display:flex;flex-direction:column;gap:6px;}
.notif-item{display:flex;align-items:flex-start;gap:.9rem;background:#fff;border-radius:12px;padding:1rem 1.1rem;box-shadow:0 1px 3px rgba(0,0,0,.07);border:.5px solid #e5e7eb;text-decoration:none;color:inherit;transition:box-shadow .13s,border-color .13s;position:relative;}
.notif-item:hover{box-shadow:0 3px 12px rgba(0,0,0,.1);border-color:#d1d5db;}
.notif-item.unread{border-left:3px solid #131921;background:#fafbff;}
.notif-icon{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.notif-body{flex:1;min-width:0;}
.notif-title{font-size:.88rem;font-weight:700;color:#0f0f0f;margin-bottom:2px;}
.notif-msg{font-size:.81rem;color:#6b7280;line-height:1.5;}
.notif-time{font-size:.72rem;color:#9ca3af;margin-top:4px;}
.unread-dot{width:8px;height:8px;border-radius:50%;background:#131921;position:absolute;top:12px;right:12px;flex-shrink:0;}
.btn-mark-all{background:#131921;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:.82rem;font-weight:600;cursor:pointer;transition:background .13s;}
.btn-mark-all:hover{background:#232f3e;}
.empty-state{text-align:center;padding:4rem;background:#fff;border-radius:12px;color:#9ca3af;}
.empty-state .ei{font-size:2.5rem;margin-bottom:.5rem;}
.flash-ok{background:#d1fae5;color:#065f46;border-radius:8px;padding:.6rem 1rem;font-size:.83rem;font-weight:500;margin-bottom:1rem;}
</style>

<div class="notif-page">
<div class="container" style="max-width:720px;">

    <div class="page-header">
        <div>
            <h1>🔔 Notifications</h1>
            <?php if ($unread_count > 0): ?>
                <div style="font-size:.82rem;color:#6b7280;margin-top:2px"><?= $unread_count ?> unread</div>
            <?php endif; ?>
        </div>
        <?php if ($unread_count > 0): ?>
        <form method="POST">
            <button type="submit" name="mark_all_read" class="btn-mark-all">Mark all as read</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['cleared'])): ?>
        <div class="flash-ok">✓ All notifications marked as read.</div>
    <?php endif; ?>

    <!-- Filter tabs -->
    <?php
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $total_count = intval($stmt->get_result()->fetch_assoc()['c']);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $read_count = intval($stmt->get_result()->fetch_assoc()['c']);
    $stmt->close();
    ?>
    <div class="filter-tabs">
        <a href="notifications.php?filter=all"    class="ftab <?= $filter === 'all'    ? 'active' : '' ?>">All <span class="cnt"><?= $total_count ?></span></a>
        <a href="notifications.php?filter=unread" class="ftab <?= $filter === 'unread' ? 'active' : '' ?>">Unread <span class="cnt"><?= $unread_count ?></span></a>
        <a href="notifications.php?filter=read"   class="ftab <?= $filter === 'read'   ? 'active' : '' ?>">Read <span class="cnt"><?= $read_count ?></span></a>
    </div>

    <!-- Notification list -->
    <?php if (!empty($notifications)): ?>
    <div class="notif-list">
        <?php foreach ($notifications as $n):
            $ti   = $type_icons[$n['type']] ?? ['🔔', '#f3f4f6', '#374151'];
            $href = 'notifications.php?read=' . intval($n['id']) . ($n['link'] ? '&link=' . urlencode($n['link']) : '');
        ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
           class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>">
            <div class="notif-icon" style="background:<?= $ti[1] ?>;color:<?= $ti[2] ?>"><?= $ti[0] ?></div>
            <div class="notif-body">
                <div class="notif-title"><?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="notif-msg"><?= htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="notif-time"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
            </div>
            <?php if (!$n['is_read']): ?><div class="unread-dot"></div><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="ei">🔔</div>
        <h5><?= $filter === 'unread' ? 'No unread notifications' : 'No notifications yet' ?></h5>
        <p style="font-size:.83rem">Order updates, shipment tracking and refund decisions will appear here.</p>
    </div>
    <?php endif; ?>

</div>
</div>

<?php include("../includes/footer.php"); ?>