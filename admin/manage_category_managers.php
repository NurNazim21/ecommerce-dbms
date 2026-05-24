<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Handle Approval / Rejection
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id     = intval($_GET['id']);
    $action = $_GET['action'];

    if ($action === 'approve') {
        // Set seller_status = approved so they can now log in as category_manager
        $conn->query("UPDATE users SET 
            seller_status = 'approved',
            seller_approved_at = NOW()
            WHERE id = $id AND role = 'category_manager'");
        $success = "Category Manager approved successfully!";
    } elseif ($action === 'reject') {
        $conn->query("UPDATE users SET seller_status = 'rejected' 
                      WHERE id = $id AND role = 'category_manager'");
        $success = "Category Manager request rejected.";
    }
}

// Fetch all category manager accounts
$managers = $conn->query("
    SELECT id, name, email, phone, city, seller_status, seller_request_at, seller_approved_at
    FROM users
    WHERE role = 'category_manager'
    ORDER BY seller_request_at DESC, created_at DESC
");
?>

<?php include("../includes/header.php"); ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">Category Manager Requests</h1>
            <p class="text-muted mb-0">Review and approve or reject category manager applications</p>
        </div>
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary badges -->
    <?php
    $counts = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(seller_status = 'pending'  OR seller_status IS NULL) as pending,
            SUM(seller_status = 'approved') as approved,
            SUM(seller_status = 'rejected') as rejected
        FROM users WHERE role = 'category_manager'
    ")->fetch_assoc();
    ?>
    <div class="d-flex gap-3 mb-4 flex-wrap">
        <span class="badge bg-secondary fs-6 px-3 py-2">Total: <?= $counts['total'] ?></span>
        <span class="badge bg-warning text-dark fs-6 px-3 py-2">Pending: <?= $counts['pending'] ?></span>
        <span class="badge bg-success fs-6 px-3 py-2">Approved: <?= $counts['approved'] ?></span>
        <span class="badge bg-danger fs-6 px-3 py-2">Rejected: <?= $counts['rejected'] ?></span>
    </div>

    <div class="card shadow">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>City</th>
                        <th>Applied</th>
                        <th>Status</th>
                        <th class="pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Reset result pointer
                    $managers->data_seek(0);
                    while ($m = $managers->fetch_assoc()):
                        $status = $m['seller_status'] ?? 'pending';
                    ?>
                    <tr>
                        <td class="ps-4"><?= $m['id'] ?></td>
                        <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                        <td><?= htmlspecialchars($m['email']) ?></td>
                        <td><?= htmlspecialchars($m['phone'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($m['city'] ?? '-') ?></td>
                        <td>
                            <?php if ($m['seller_request_at']): ?>
                                <?= date('d M, Y', strtotime($m['seller_request_at'])) ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($status === 'approved'): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approved</span>
                            <?php elseif ($status === 'rejected'): ?>
                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rejected</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4">
                            <div class="d-flex gap-2">
                                <?php if ($status !== 'approved'): ?>
                                    <a href="?action=approve&id=<?= $m['id'] ?>"
                                       class="btn btn-success btn-sm"
                                       onclick="return confirm('Approve <?= htmlspecialchars(addslashes($m['name'])) ?> as Category Manager?')">
                                        <i class="bi bi-check-lg me-1"></i>Approve
                                    </a>
                                <?php endif; ?>
                                <?php if ($status !== 'rejected'): ?>
                                    <a href="?action=reject&id=<?= $m['id'] ?>"
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('Reject this Category Manager application?')">
                                        <i class="bi bi-x-lg me-1"></i>Reject
                                    </a>
                                <?php endif; ?>
                                <?php if ($status === 'approved' || $status === 'rejected'): ?>
                                    <!-- Both actions done: show both to allow reversal -->
                                    <?php if ($status === 'approved'): ?>
                                        <a href="?action=reject&id=<?= $m['id'] ?>"
                                           class="btn btn-outline-danger btn-sm"
                                           onclick="return confirm('Revoke this approval?')">
                                            <i class="bi bi-x-lg me-1"></i>Revoke
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>

                    <?php if ($counts['total'] == 0): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            No category manager applications yet.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>