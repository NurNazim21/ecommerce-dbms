<?php
include("../includes/auth_check.php");
include("../config/db.php");

if ($_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// ── DELETE USER ──────────────────────────────────────────────────────────────
// Old (vulnerable):
//   $id = intval($_GET['delete']);
//   $conn->query("SELECT role FROM users WHERE id = $id")
//   $conn->query("DELETE FROM cart WHERE user_id = $id")
//   ... etc — raw $id in every query
//
// intval() alone is not enough. $id goes into 8 separate queries.
// Any one of them being built differently in future breaks the assumption.
// Prepared statements make each query safe unconditionally.

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Safety: can't delete yourself
    if ($id === intval($_SESSION['user_id'])) {
        $error = "You cannot delete your own account!";
    } else {
        // Check the role of the user being deleted
        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $check = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($check && $check['role'] === 'admin') {
            $error = "Cannot delete another Administrator for security reasons!";
        } else {
            // Each DELETE is a separate prepared statement.
            // Grouped in an array so adding future tables is one line.
            $delete_queries = [
                "DELETE FROM cart      WHERE user_id = ?",
                "DELETE FROM wishlist  WHERE user_id = ?",
                "DELETE FROM reviews   WHERE user_id = ?",
            ];

            foreach ($delete_queries as $sql) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }

            // Delete order-related data — subquery approach still safe
            // because the inner SELECT is on orders.user_id = ? (bound).
            // Alternative: two queries (get order IDs, then delete by ID list).
            // The subquery version is fine here since $id is an integer.
            $order_related = [
                "DELETE FROM payments    WHERE order_id IN (SELECT id FROM orders WHERE user_id = ?)",
                "DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE user_id = ?)",
                "DELETE FROM orders      WHERE user_id = ?",
            ];

            foreach ($order_related as $sql) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }

            // Finally delete the user
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $success = "User and all associated data deleted successfully!";
            } else {
                // FIX: never expose $conn->error to the page — log it instead
                error_log("User delete failed for id=$id: " . $stmt->error);
                $error = "Error deleting user. Please try again.";
            }
            $stmt->close();
        }
    }
}

// ── FETCH ALL USERS ──────────────────────────────────────────────────────────
// Old: $conn->query("SELECT * FROM users ORDER BY role DESC, created_at DESC")
// No user input here so a plain query is technically safe,
// but we use prepare() consistently for uniformity.
$stmt = $conn->prepare("SELECT * FROM users ORDER BY role DESC, created_at DESC");
$stmt->execute();
$users = $stmt->get_result();
$stmt->close();
?>

<?php include("../includes/header.php"); ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Manage Users</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card shadow">
        <div class="card-body">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th><th>Name</th><th>Email</th><th>Role</th>
                        <th>Phone</th><th>City</th><th>Joined</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $users->fetch_assoc()): ?>
                    <tr>
                        <td><?= intval($row['id']) ?></td>
                        <td><?= htmlspecialchars($row['name'],  ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="badge <?= $row['role'] === 'admin' ? 'bg-danger' : 'bg-primary' ?>">
                                <?= htmlspecialchars(strtoupper($row['role']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($row['phone'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['city']  ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d M, Y', strtotime($row['created_at'])) ?></td>
                        <td>
                            <?php if ($row['role'] === 'user' && $row['id'] != $_SESSION['user_id']): ?>
                                <!-- FIX: intval() in the URL so no string can reach the GET handler -->
                                <a href="?delete=<?= intval($row['id']) ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Delete this user and all their data?')">
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