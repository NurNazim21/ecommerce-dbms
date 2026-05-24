<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Handle Approval / Rejection
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];

    if ($action === 'approve') {
        $conn->query("UPDATE users SET 
            seller_status = 'approved',
            seller_approved_at = NOW(),
            role = 'seller' 
            WHERE id = $id");
        $success = "Seller approved successfully!";
    } 
    elseif ($action === 'reject') {
        $conn->query("UPDATE users SET seller_status = 'rejected' WHERE id = $id");
        $success = "Seller request rejected.";
    }
}

// Fetch Seller Requests
$sellers = $conn->query("
    SELECT id, name, email, shop_name, seller_address, 
           seller_status, seller_request_at, phone, city 
    FROM users 
    WHERE role = 'seller' OR seller_status IS NOT NULL 
    ORDER BY seller_request_at DESC
");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Seller Management</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <div class="card shadow">
        <div class="card-body">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Seller Name</th>
                        <th>Shop Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Request Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($s = $sellers->fetch_assoc()): ?>
                    <tr>
                        <td><?= $s['id'] ?></td>
                        <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                        <td><?= htmlspecialchars($s['shop_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($s['email']) ?></td>
                        <td><?= htmlspecialchars($s['phone'] ?? '-') ?></td>
                        <td><?= $s['seller_request_at'] ? date('d M, Y', strtotime($s['seller_request_at'])) : '-' ?></td>
                        <td>
                            <?php if($s['seller_status'] == 'approved'): ?>
                                <span class="badge bg-success">Approved</span>
                            <?php elseif($s['seller_status'] == 'rejected'): ?>
                                <span class="badge bg-danger">Rejected</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s['seller_status'] != 'approved'): ?>
                                <a href="?action=approve&id=<?= $s['id'] ?>" 
                                   class="btn btn-success btn-sm"
                                   onclick="return confirm('Approve this seller?')">
                                    Approve
                                </a>
                            <?php endif; ?>
                            
                            <?php if($s['seller_status'] != 'rejected'): ?>
                                <a href="?action=reject&id=<?= $s['id'] ?>" 
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Reject this seller request?')">
                                    Reject
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>

                    <?php if($sellers->num_rows == 0): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">No seller requests yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>