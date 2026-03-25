<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$userId = (int) ($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$selectedUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$selectedUser) {
    header('Location: user-management.php');
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">User Details</h5>
                <a href="user-management.php" class="btn btn-outline-secondary btn-sm">Back</a>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><strong>Full Name:</strong><br><?php echo htmlspecialchars($selectedUser['full_name']); ?></div>
                    <div class="col-md-6"><strong>Email:</strong><br><?php echo htmlspecialchars($selectedUser['email']); ?></div>
                    <div class="col-md-6"><strong>Username:</strong><br><?php echo htmlspecialchars($selectedUser['username']); ?></div>
                    <div class="col-md-6"><strong>Role:</strong><br><?php echo htmlspecialchars(getRoleDisplayName($selectedUser['role'])); ?></div>
                    <div class="col-md-6"><strong>Department:</strong><br><?php echo htmlspecialchars($selectedUser['department'] ?: 'Not set'); ?></div>
                    <div class="col-md-6"><strong>Status:</strong><br><?php echo htmlspecialchars(ucfirst($selectedUser['status'])); ?></div>
                    <div class="col-md-6"><strong>Designation:</strong><br><?php echo htmlspecialchars($selectedUser['designation'] ?: 'Not set'); ?></div>
                    <div class="col-md-6"><strong>Last Login:</strong><br><?php echo htmlspecialchars($selectedUser['last_login'] ?: 'No recorded login'); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
