<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Delete User - Safe & Smart Logic
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Safety Checks
    if ($id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account!";
    } else {
        // Check the role of the user being deleted
        $check = $conn->query("SELECT role FROM users WHERE id = $id")->fetch_assoc();
        
        if ($check && $check['role'] === 'admin') {
            $error = "Cannot delete another Administrator for security reasons!";
        } else {
            // Safe deletion for normal users only
            $conn->query("DELETE FROM cart WHERE user_id = $id");
            $conn->query("DELETE FROM wishlist WHERE user_id = $id");
            $conn->query("DELETE FROM reviews WHERE user_id = $id");
            
            // Delete order related data
            $conn->query("DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE user_id = $id)");
            $conn->query("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE user_id = $id)");
            $conn->query("DELETE FROM orders WHERE user_id = $id");

            // Finally delete the user
            if ($conn->query("DELETE FROM users WHERE id = $id")) {
                $success = "User and all associated data deleted successfully!";
            } else {
                $error = "Error deleting user: " . $conn->error;
            }
        }
    }
}

// Fetch Users
$users = $conn->query("SELECT * FROM users ORDER BY role DESC, created_at DESC");
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Manage Users</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="card shadow">
        <div class="card-body">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Phone</th>
                        <th>City</th>
                        <th>Joined</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $users->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['email']) ?></td>
                        <td>
                            <span class="badge <?= $row['role'] == 'admin' ? 'bg-danger' : 'bg-primary' ?>">
                                <?= strtoupper($row['role']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['city'] ?? '-') ?></td>
                        <td><?= date('d M, Y', strtotime($row['created_at'])) ?></td>
                        <td>
                            <?php if ($row['role'] === 'user' && $row['id'] != $_SESSION['user_id']): ?>
                                <a href="?delete=<?= $row['id'] ?>" 
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Delete this user and all their data (orders, cart, reviews)?')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">Protected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include("../includes/footer.php"); ?>