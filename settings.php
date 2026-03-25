<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();
$conn = getConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = trim((string) $_POST['password']);
    if (strlen($password) >= 6) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hashedPassword, $user['id']);
        $stmt->execute();
        $stmt->close();
        $message = 'Password updated successfully.';
        logActivity((int) $user['id'], 'change_password', 'users', (int) $user['id']);
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/navbar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Account Settings</h5>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control" minlength="6" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>

                    <?php if ($user['role'] === 'admin'): ?>
                        <hr>
                        <a href="<?php echo BASE_URL; ?>modules/admin/settings.php" class="btn btn-outline-primary">Open Admin Settings</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
