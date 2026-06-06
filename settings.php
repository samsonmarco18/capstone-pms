<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();
$conn = getConnection();
$message = '';
$error = '';
$forcePasswordChange = !empty($_SESSION['force_password_change']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = trim((string) $_POST['password']);
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
    $currentPassword = (string) ($_POST['current_password'] ?? '');

    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $currentUserRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$currentUserRow || !password_verify($currentPassword, (string) $currentUserRow['password'])) {
        $error = 'Please verify your current password before changing it.';
    } elseif (strlen($password) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $hasPasswordChangedAt = ensureUserPasswordSecurityColumns();
        $sql = $hasPasswordChangedAt
            ? 'UPDATE users SET password = ?, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?'
            : 'UPDATE users SET password = ? WHERE id = ?';
        $stmt = $conn->prepare($sql);
        if ($hasPasswordChangedAt) {
            $stmt->bind_param('si', $hashedPassword, $user['id']);
        } else {
            $stmt->bind_param('si', $hashedPassword, $user['id']);
        }
        $stmt->execute();
        $stmt->close();
        $message = 'Password updated successfully.';
        unset($_SESSION['force_password_change']);
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
                    <?php if ($forcePasswordChange): ?>
                        <div class="alert alert-warning">Please update your password to continue.</div>
                    <?php endif; ?>
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control" minlength="6" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required>
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
