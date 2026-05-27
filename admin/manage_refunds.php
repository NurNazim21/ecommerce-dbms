<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = intval($_SESSION['user_id']);

// ── VALID STATUSES ────────────────────────────────────────────────────────────
$valid_statuses = ['requested','under_review','approved','rejected','processing','completed','cancelled'];

// ── HANDLE STATUS UPDATE ──────────────────────────────────────────────────────
if (isset($_POST['update_refund'])) {
    $refund_id  = intval($_POST['refund_id']);
    $new_status = $_POST['new_status'] ?? '';
    $admin_note = trim(strip_tags($_POST['admin_note'] ?? ''));

    if (!in_array($new_status, $valid_statuses, true)) {
        $action_error = "Invalid status.";
    } else {
        try {
            $conn->begin_transaction();

            // Fetch refund
            $stmt = $conn->prepare("SELECT * FROM refunds WHERE id = ?");
            $stmt->bind_param("i", $refund_id);
            $stmt->execute();
            $refund = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$refund) throw new Exception("Refund not found.");

            // Update refund record
            $stmt = $conn->prepare("
                UPDATE refunds
                SET status      = ?,
                    admin_note  = ?,
                    reviewed_by = ?,
                    reviewed_at = IF(reviewed_at IS NULL, NOW(), reviewed_at),
                    completed_at = IF(? = 'completed', NOW(), completed_at)
                WHERE id = ?
            ");
            $stmt->bind_param("ssiis",
                $new_status, $admin_note, $admin_id, $new_status, $refund_id
            );
            $stmt->execute();
            $stmt->close();

            // If completed → update payments table
            if ($new_status === 'completed') {
                $stmt = $conn->prepare("
                    UPDATE payments
                    SET refunded_amount  = refunded_amount + ?,
                        refunded_at      = NOW(),
                        payment_status   = IF(
                            refunded_amount + ? >= amount,
                            'Refunded',
                            'Partially_Refunded'
                        )
                    WHERE order_id = ?
                ");
                $stmt->bind_param("ddi",
                    $refund['amount'], $refund['amount'], $refund['order_id']
                );
                $stmt->execute();
                $stmt->close();

                // Update order status to Refunded
                $stmt = $conn->prepare("CALL sp_log_order_status(?, 'Refunded', ?, ?)");
                $note = "Refund #$refund_id completed — ৳" . number_format($refund['amount']);
                $stmt->bind_param("iis", $refund['order_id'], $admin_id, $note);
                $stmt->execute();
                $stmt->close();
            }

            // Notify buyer
            $notif_msgs = [
                'under_review' => 'Your refund request is under review. We will update you shortly.',
                'approved'     => 'Great news! Your refund has been approved and is being processed.',
                'rejected'     => 'Your refund request has been reviewed. Please check the admin note for details.',
                'completed'    => "Your refund of ৳" . number_format($refund['amount']) . " has been completed.",
                'processing'   => 'Your refund is being processed. Funds will arrive soon.',
            ];
            if (isset($notif_msgs[$new_status])) {
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link)
                    VALUES (?, 'refund_update', 'Refund Update', ?, ?)
                ");
                $msg  = $notif_msgs[$new_status];
                $link = "/user/order_details.php?id={$refund['order_id']}";
                $stmt->bind_param("iss", $refund['user_id'], $msg, $link);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $action_success = "Refund #$refund_id updated to " . ucfirst(str_replace('_',' ',$new_status)) . ".";

        } catch (Exception $e) {
            $conn->rollback();
            $action_error = $e->getMessage();
        }
    }
}

// ── FILTERS ───────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['search'] ?? '');
if (!in_array($filter_status, array_merge([''], $valid_statuses), true)) {
    $filter_status = '';
}

$where_clauses = ["1=1"];
$bind_types    = "";
$bind_values   = [];

if ($filter_status !== '') {
    $where_clauses[] = "r.status = ?";
    $bind_types     .= "s";
    $bind_values[]   = $filter_status;
}
if ($filter_search !== '') {
    $like = "%$filter_search%";
    $search_id = is_numeric($filter_search) ? intval($filter_search) : 0;
    if ($search_id > 0) {
        $where_clauses[] = "(u.name LIKE ? OR r.order_id = ? OR r.id = ?)";
        $bind_types     .= "sii";
        $bind_values[]   = $like;
        $bind_values[]   = $search_id;
        $bind_values[]   = $search_id;
    } else {
        $where_clauses[] = "u.name LIKE ?";
        $bind_types     .= "s";
        $bind_values[]   = $like;
    }
}

$where_sql = implode(' AND ', $where_clauses);

// ── FETCH REFUNDS ─────────────────────────────────────────────────────────────
$sql = "
    SELECT r.*,
           u.name       AS customer_name,
           u.email      AS customer_email,
           o.total_amount AS order_total,
           p.name       AS product_name,
           adm.name     AS reviewed_by_name,
           su.name      AS seller_name
    FROM refunds r
    JOIN users u          ON u.id = r.user_id
    JOIN orders o         ON o.id = r.order_id
    LEFT JOIN order_items oi ON oi.id  = r.order_item_id
    LEFT JOIN products p     ON p.id   = oi.product_id
    LEFT JOIN users adm      ON adm.id = r.reviewed_by
    LEFT JOIN users su        ON su.id  = r.seller_id
    WHERE $where_sql
    ORDER BY r.created_at DESC
";

$stmt = $conn->prepare($sql);
if ($bind_types !== '') {
    $params = array_merge([$bind_types], $bind_values);
    $refs   = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}
$stmt->execute();
$refunds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── STATUS COUNTS ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt, SUM(amount) as total FROM refunds GROUP BY status");
$stmt->execute();
$count_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = ['all' => ['cnt' => 0, 'total' => 0]];
foreach ($valid_statuses as $s) $counts[$s] = ['cnt' => 0, 'total' => 0];
foreach ($count_rows as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']] = ['cnt' => $r['cnt'], 'total' => $r['total']];
    }
    $counts['all']['cnt']   += $r['cnt'];
    $counts['all']['total'] += $r['total'];
}

// Status badge colour map
$badge_map = [
    'requested'   => ['bg' => '#fef9c3', 'color' => '#854d0e'],
    'under_review'=> ['bg' => '#e0f2fe', 'color' => '#0369a1'],
    'approved'    => ['bg' => '#dbeafe', 'color' => '#1e40af'],
    'rejected'    => ['bg' => '#fee2e2', 'color' => '#991b1b'],
    'processing'  => ['bg' => '#fef9c3', 'color' => '#854d0e'],
    'completed'   => ['bg' => '#d1fae5', 'color' => '#065f46'],
    'cancelled'   => ['bg' => '#f3e8ff', 'color' => '#6d28d9'],
];

// Next allowed statuses per current status
$next_statuses = [
    'requested'    => ['under_review', 'approved', 'rejected', 'cancelled'],
    'under_review' => ['approved', 'rejected', 'cancelled'],
    'approved'     => ['processing', 'rejected'],
    'processing'   => ['completed'],
    'rejected'     => [],
    'completed'    => [],
    'cancelled'    => [],
];
?>
<?php include("../includes/header.php"); ?>

<style>
.rf-page{background:#f3f4f6;min-height:100vh;padding:2rem 0 5rem;font-family:'DM Sans',sans-serif;}
.page-header{background:linear-gradient(135deg,#1a1a2e,#0f3460);border-radius:14px;padding:22px 28px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;box-shadow:0 6px 24px rgba(15,52,96,.18);}
.page-header h1{color:#fff;font-size:1.45rem;font-weight:800;margin:0;}
.page-header .sub{color:#a8b8d8;font-size:.83rem;margin-top:2px;}
.btn-back{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.22)!important;border-radius:8px;padding:7px 16px;font-size:.83rem;font-weight:600;text-decoration:none;}
.metric-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;}
.metric-tile{background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.07);}
.m-label{font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;}
.m-val{font-size:1.3rem;font-weight:800;color:#0f0f0f;margin-top:2px;}
.m-sub{font-size:.73rem;color:#aaa;}
.stat-pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;}
.pill{display:flex;align-items:center;gap:6px;background:#fff;border-radius:50px;padding:5px 14px;font-size:.8rem;font-weight:600;color:#444;box-shadow:0 1px 5px rgba(0,0,0,.07);text-decoration:none;border:2px solid transparent;transition:all .13s;}
.pill.active{border-color:#0f3460;background:#e8f0fe;color:#0f3460;}
.pill .cnt{background:#f0f0f0;border-radius:50px;padding:1px 7px;font-size:.72rem;font-weight:700;}
.filter-bar{background:#fff;border-radius:12px;padding:14px 18px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;}
.filter-bar .sw{flex:1;min-width:180px;position:relative;}
.filter-bar .sw input{padding-left:32px;border-radius:7px;border:1.5px solid #dee2e6;height:36px;font-size:.87rem;width:100%;outline:none;}
.filter-bar .sw input:focus{border-color:#0f3460;}
.filter-bar .sw .si{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#aaa;font-size:.85rem;}
.filter-bar select{border-radius:7px;border:1.5px solid #dee2e6;height:36px;font-size:.85rem;padding:0 10px;outline:none;min-width:130px;}
.btn-apply{background:#0f3460;color:#fff;border:none;border-radius:7px;height:36px;padding:0 16px;font-size:.85rem;font-weight:600;cursor:pointer;}
.btn-reset{background:#f0f0f0;color:#555;border:none;border-radius:7px;height:36px;padding:0 12px;font-size:.83rem;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}
.table-card{background:#fff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden;}
.results-bar{padding:12px 16px 0;font-size:.82rem;color:#888;display:flex;justify-content:space-between;}
.rf-table{width:100%;border-collapse:collapse;}
.rf-table th{font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;padding:10px 14px;border-bottom:.5px solid #f2f4f8;text-align:left;white-space:nowrap;background:#fafbfc;}
.rf-table td{padding:12px 14px;border-bottom:.5px solid #f7f8fa;font-size:.85rem;vertical-align:middle;}
.rf-table tr:last-child td{border-bottom:none;}
.rf-table tbody tr:hover{background:#f9fafb;}
.st-badge{display:inline-block;border-radius:5px;padding:2px 9px;font-size:.72rem;font-weight:700;}
.rf-id{font-weight:800;color:#0f3460;}
.cust-name{font-weight:600;color:#0f0f0f;}
.cust-email{font-size:.74rem;color:#9ca3af;margin-top:1px;}
.amount-val{font-weight:700;color:#dc2626;font-size:.9rem;}
.reason-text{font-size:.78rem;color:#555;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.evidence-thumb{width:40px;height:40px;object-fit:cover;border-radius:5px;border:.5px solid #e5e7eb;cursor:pointer;}
.btn-action{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;font-size:.76rem;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:all .12s;}
.btn-view{background:#e8f0fe;color:#1e40af;border:1px solid #93c5fd;}
.btn-view:hover{background:#1e40af;color:#fff;}
.empty-state{text-align:center;padding:4rem;color:#9ca3af;}
.alert-ok{background:#d1fae5;color:#065f46;border-radius:8px;padding:.65rem 1rem;font-size:.85rem;font-weight:500;margin-bottom:14px;}
.alert-err{background:#fee2e2;color:#991b1b;border-radius:8px;padding:.65rem 1rem;font-size:.85rem;font-weight:500;margin-bottom:14px;}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:14px;padding:26px;width:480px;max-width:95vw;box-shadow:0 12px 48px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto;}
.modal-box h5{font-size:1rem;font-weight:700;margin-bottom:16px;color:#0f0f0f;}
.modal-box .field{margin-bottom:14px;}
.modal-box .field label{font-size:.78rem;font-weight:700;color:#0f0f0f;display:block;margin-bottom:4px;}
.modal-box select,.modal-box textarea{width:100%;padding:.6rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.88rem;outline:none;font-family:inherit;}
.modal-box select:focus,.modal-box textarea:focus{border-color:#0f3460;}
.modal-box textarea{resize:vertical;min-height:80px;}
.modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;}
.btn-confirm{background:#0f3460;color:#fff;border:none;border-radius:8px;padding:8px 20px;font-size:.88rem;font-weight:600;cursor:pointer;}
.btn-confirm:hover{background:#1a5276;}
.btn-cancel-modal{background:#f0f0f0;color:#555;border:none;border-radius:8px;padding:8px 16px;font-size:.86rem;font-weight:600;cursor:pointer;}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;}
.detail-row{display:flex;flex-direction:column;gap:2px;}
.detail-label{font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;}
.detail-value{font-size:.85rem;color:#0f0f0f;font-weight:500;}
</style>

<div class="rf-page">
<div class="container">

    <div class="page-header">
        <div>
            <h1>↩ Manage Refunds</h1>
            <div class="sub">Admin Panel — review and process refund requests</div>
        </div>
        <a href="dashboard.php" class="btn-back">← Dashboard</a>
    </div>

    <?php if (isset($action_success)): ?>
        <div class="alert-ok">✅ <?= htmlspecialchars($action_success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (isset($action_error)): ?>
        <div class="alert-err">❌ <?= htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Metric tiles -->
    <div class="metric-grid">
        <div class="metric-tile">
            <div class="m-label">Total Requests</div>
            <div class="m-val"><?= intval($counts['all']['cnt']) ?></div>
            <div class="m-sub">৳ <?= number_format($counts['all']['total']) ?> total</div>
        </div>
        <div class="metric-tile" style="border-left:3px solid #854d0e">
            <div class="m-label">Requested</div>
            <div class="m-val" style="color:#854d0e"><?= intval($counts['requested']['cnt']) ?></div>
            <div class="m-sub">Awaiting review</div>
        </div>
        <div class="metric-tile" style="border-left:3px solid #1e40af">
            <div class="m-label">Approved</div>
            <div class="m-val" style="color:#1e40af"><?= intval($counts['approved']['cnt'] + $counts['processing']['cnt']) ?></div>
        </div>
        <div class="metric-tile" style="border-left:3px solid #065f46">
            <div class="m-label">Completed</div>
            <div class="m-val" style="color:#065f46"><?= intval($counts['completed']['cnt']) ?></div>
            <div class="m-sub">৳ <?= number_format($counts['completed']['total']) ?> refunded</div>
        </div>
        <div class="metric-tile" style="border-left:3px solid #991b1b">
            <div class="m-label">Rejected</div>
            <div class="m-val" style="color:#991b1b"><?= intval($counts['rejected']['cnt']) ?></div>
        </div>
    </div>

    <!-- Status pills -->
    <?php
    $pp = function($s) use ($filter_search) {
        $p = $s ? "status=$s" : '';
        if ($filter_search) $p .= ($p ? '&' : '') . 'search=' . urlencode($filter_search);
        return 'manage_refunds.php' . ($p ? "?$p" : '');
    };
    $pill_labels = [
        '' => 'All', 'requested' => 'Requested', 'under_review' => 'Under Review',
        'approved' => 'Approved', 'processing' => 'Processing',
        'completed' => 'Completed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
    ];
    ?>
    <div class="stat-pills">
        <?php foreach ($pill_labels as $val => $label): ?>
        <a href="<?= $pp($val) ?>" class="pill <?= $filter_status === $val ? 'active' : '' ?>">
            <?= $label ?>
            <span class="cnt"><?= $val === '' ? $counts['all']['cnt'] : intval($counts[$val]['cnt'] ?? 0) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="manage_refunds.php" class="filter-bar">
        <div class="sw">
            <span class="si">🔍</span>
            <input type="text" name="search" placeholder="Customer name or order/refund ID…"
                   value="<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach ($valid_statuses as $s): ?>
            <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>>
                <?= ucfirst(str_replace('_',' ',$s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-apply">Apply</button>
        <?php if ($filter_search || $filter_status): ?>
            <a href="manage_refunds.php" class="btn-reset">✕ Reset</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="table-card">
        <div class="results-bar">
            <span>Showing <strong><?= count($refunds) ?></strong> refund<?= count($refunds) !== 1 ? 's' : '' ?></span>
        </div>
        <div style="overflow-x:auto">
        <table class="rf-table">
            <thead>
                <tr>
                    <th>Refund</th>
                    <th>Customer</th>
                    <th>Order</th>
                    <th>Item</th>
                    <th>Amount</th>
                    <th>Reason</th>
                    <th>Method</th>
                    <th>Evidence</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($refunds)): ?>
                <?php foreach ($refunds as $r):
                    $st   = $r['status'];
                    $bm   = $badge_map[$st] ?? ['bg' => '#f0f0f0', 'color' => '#555'];
                    $next = $next_statuses[$st] ?? [];
                ?>
                <tr>
                    <td><span class="rf-id">#<?= intval($r['id']) ?></span></td>
                    <td>
                        <div class="cust-name"><?= htmlspecialchars($r['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="cust-email"><?= htmlspecialchars($r['customer_email'], ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td>
                        <a href="order_details.php?id=<?= intval($r['order_id']) ?>"
                           style="font-weight:700;color:#0f3460;text-decoration:none">
                            #<?= intval($r['order_id']) ?>
                        </a>
                        <div style="font-size:.73rem;color:#aaa">৳ <?= number_format($r['order_total']) ?></div>
                    </td>
                    <td style="font-size:.78rem;color:#555">
                        <?= $r['product_name'] ? htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8') : '<span style="color:#bbb">Full order</span>' ?>
                        <?php if ($r['seller_name']): ?>
                            <div style="font-size:.71rem;color:#aaa">by <?= htmlspecialchars($r['seller_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="amount-val">৳ <?= number_format($r['amount']) ?></span></td>
                    <td>
                        <div class="reason-text" title="<?= htmlspecialchars($r['reason'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars(str_replace('_',' ',$r['reason']), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </td>
                    <td style="font-size:.78rem;color:#555">
                        <?= htmlspecialchars(ucfirst(str_replace('_',' ',$r['refund_method'] ?? '—')), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <?php if (!empty($r['evidence_image'])): ?>
                            <img src="../<?= htmlspecialchars($r['evidence_image'], ENT_QUOTES, 'UTF-8') ?>"
                                 class="evidence-thumb"
                                 onclick="viewEvidence('../<?= htmlspecialchars($r['evidence_image'], ENT_QUOTES) ?>')"
                                 alt="Evidence">
                        <?php else: ?>
                            <span style="color:#ccc;font-size:.75rem">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="st-badge" style="background:<?= $bm['bg'] ?>;color:<?= $bm['color'] ?>">
                            <?= ucfirst(str_replace('_',' ',$st)) ?>
                        </span>
                        <?php if (!empty($r['reviewed_by_name'])): ?>
                            <div style="font-size:.7rem;color:#aaa;margin-top:2px">by <?= htmlspecialchars($r['reviewed_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.78rem;color:#888"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                    <td>
                        <button type="button" class="btn-action btn-view"
                                onclick='openModal(<?= json_encode([
                                    "id"         => $r['id'],
                                    "order_id"   => $r['order_id'],
                                    "customer"   => $r['customer_name'],
                                    "amount"     => number_format($r['amount']),
                                    "reason"     => str_replace('_',' ',$r['reason']),
                                    "details"    => $r['details'] ?? '',
                                    "method"     => $r['refund_method'] ?? '—',
                                    "status"     => $st,
                                    "admin_note" => $r['admin_note'] ?? '',
                                    "next"       => $next,
                                ], JSON_HEX_QUOT | JSON_HEX_TAG) ?>)'>
                            Review
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="11">
                    <div class="empty-state">
                        <div style="font-size:2.5rem;margin-bottom:.5rem">↩</div>
                        <h5 style="color:#9ca3af"><?= $filter_search || $filter_status ? 'No refunds match your filters' : 'No refund requests yet' ?></h5>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>
</div>

<!-- Review Modal -->
<div class="modal-overlay" id="refundModal">
  <div class="modal-box">
    <h5>↩ Review Refund Request</h5>
    <div class="detail-grid" id="modalDetails"></div>
    <div id="modalDetailsExtra" style="font-size:.82rem;color:#555;background:#f9fafb;border-radius:8px;padding:.65rem .85rem;margin-bottom:14px;line-height:1.6;"></div>

    <form method="POST" id="refundForm">
        <input type="hidden" name="update_refund" value="1">
        <input type="hidden" name="refund_id"  id="modalRefundId">

        <div class="field" id="statusFieldWrap">
            <label>Update Status</label>
            <select name="new_status" id="modalNewStatus">
                <option value="">— no change —</option>
            </select>
        </div>

        <div class="field">
            <label>Admin Note <span style="color:#9ca3af;font-weight:400">(stored on record, sent to customer)</span></label>
            <textarea name="admin_note" id="modalNote" placeholder="Reason for decision, tracking number, etc."></textarea>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-cancel-modal" onclick="closeModal()">Cancel</button>
            <button type="submit" class="btn-confirm">Save Update</button>
        </div>
    </form>
  </div>
</div>

<!-- Evidence lightbox -->
<div class="modal-overlay" id="evidenceModal" onclick="document.getElementById('evidenceModal').classList.remove('show')">
  <img id="evidenceImg" src="" alt="Evidence"
       style="max-width:90vw;max-height:85vh;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.4);">
</div>

<script>
const statusLabels = {
    under_review:'Under Review', approved:'Approved', rejected:'Rejected',
    processing:'Processing', completed:'Completed', cancelled:'Cancelled'
};

function openModal(data) {
    document.getElementById('modalRefundId').value  = data.id;
    document.getElementById('modalNote').value      = data.admin_note;

    // Details grid
    document.getElementById('modalDetails').innerHTML = `
        <div class="detail-row"><span class="detail-label">Refund #</span><span class="detail-value">#${data.id}</span></div>
        <div class="detail-row"><span class="detail-label">Order</span><span class="detail-value">#${data.order_id}</span></div>
        <div class="detail-row"><span class="detail-label">Customer</span><span class="detail-value">${data.customer}</span></div>
        <div class="detail-row"><span class="detail-label">Amount</span><span class="detail-value" style="color:#dc2626;font-weight:700">৳ ${data.amount}</span></div>
        <div class="detail-row"><span class="detail-label">Reason</span><span class="detail-value">${data.reason}</span></div>
        <div class="detail-row"><span class="detail-label">Refund Method</span><span class="detail-value">${data.method}</span></div>
    `;

    // Customer description
    if (data.details) {
        document.getElementById('modalDetailsExtra').innerHTML =
            `<strong style="font-size:.75rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em">Customer description</strong><br>${data.details}`;
        document.getElementById('modalDetailsExtra').style.display = 'block';
    } else {
        document.getElementById('modalDetailsExtra').style.display = 'none';
    }

    // Populate next status options
    const sel = document.getElementById('modalNewStatus');
    sel.innerHTML = '<option value="">— no change —</option>';
    if (data.next && data.next.length > 0) {
        data.next.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s;
            opt.textContent = statusLabels[s] || s;
            sel.appendChild(opt);
        });
        document.getElementById('statusFieldWrap').style.display = 'block';
    } else {
        document.getElementById('statusFieldWrap').style.display = 'none';
    }

    document.getElementById('refundModal').classList.add('show');
}

function closeModal() {
    document.getElementById('refundModal').classList.remove('show');
}

function viewEvidence(src) {
    document.getElementById('evidenceImg').src = src;
    document.getElementById('evidenceModal').classList.add('show');
}

document.getElementById('refundModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); document.getElementById('evidenceModal').classList.remove('show'); }});
</script>

<?php include("../includes/footer.php"); ?>