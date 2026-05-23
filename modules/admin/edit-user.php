<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$admin = getCurrentUser();
$userId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$departments = [
    'Administration',
    'Sales',
    'Architecture',
    'Service Unit Leadership',
    'Expert',
    'Development'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId > 0) {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, department = ?, role = ?, status = ?, designation = ? WHERE id = ?');
    $stmt->bind_param(
        'sssssi',
        $_POST['full_name'],
        $_POST['department'],
        $_POST['role'],
        $_POST['status'],
        $_POST['designation'],
        $userId
    );
    $stmt->execute();
    $stmt->close();

    logActivity((int) $admin['id'], 'edit_user', 'users', $userId, null, $_POST);
    header('Location: view-user.php?id=' . $userId);
    exit();
}

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
            <div class="card-header">
                <h5 class="mb-0">Edit User</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="id" value="<?php echo (int) $selectedUser['id']; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($selectedUser['full_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select">
                                <option value="">Select department</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?php echo htmlspecialchars($department); ?>" <?php echo $selectedUser['department'] === $department ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($selectedUser['department'] && !in_array($selectedUser['department'], $departments, true)): ?>
                                    <option value="<?php echo htmlspecialchars($selectedUser['department']); ?>" selected>
                                        <?php echo htmlspecialchars($selectedUser['department']); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                <?php foreach (['admin', 'sales', 'architect', 'sul', 'expert', 'developer'] as $role): ?>
                                    <option value="<?php echo $role; ?>" <?php echo $selectedUser['role'] === $role ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(getRoleDisplayName($role)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['active', 'inactive', 'suspended'] as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo $selectedUser['status'] === $status ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($status)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Designation</label>
                            <input type="text" name="designation" class="form-control" value="<?php echo htmlspecialchars($selectedUser['designation']); ?>">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="view-user.php?id=<?php echo (int) $selectedUser['id']; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
