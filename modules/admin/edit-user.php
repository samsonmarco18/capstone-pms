<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
requireRole('admin');

$conn = getConnection();
workflowEnsureSchema($conn);
$admin = getCurrentUser();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$routeUserId = (int) ($_GET['id'] ?? 0);
$postedUserId = (int) ($_POST['id'] ?? 0);
$userId = $routeUserId > 0 ? $routeUserId : $postedUserId;
$departments = getDepartmentOptions();
$errors = [];

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$selectedUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$selectedUser) {
    header('Location: user-management.php');
    exit();
}

if ($isPost && $userId > 0) {
    if ($postedUserId !== $userId) {
        $errors[] = 'The selected account changed while editing. Please reopen the user and try again.';
    }

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $role = trim((string) ($_POST['role'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? ''));
    $designation = trim((string) ($_POST['designation'] ?? ''));

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }
    if ($username === '') {
        $errors[] = 'Username is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (!in_array($role, ['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer'], true)) {
        $errors[] = 'Please select a valid role.';
    }

    if ($role === 'sul' && $department === '') {
        $errors[] = 'SUL users must be assigned to a department.';
    }
    if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
        $errors[] = 'Please select a valid status.';
    }

    $uniqueStmt = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1');
    $uniqueStmt->bind_param('ssi', $username, $email, $userId);
    $uniqueStmt->execute();
    if ($uniqueStmt->get_result()->num_rows > 0) {
        $errors[] = 'Username or email is already used by another account.';
    }
    $uniqueStmt->close();

    if (!$errors) {
        $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, department = ?, role = ?, status = ?, designation = ? WHERE id = ?');
        $stmt->bind_param(
            'sssssssi',
            $fullName,
            $username,
            $email,
            $department,
            $role,
            $status,
            $designation,
            $userId
        );
        $stmt->execute();
        $stmt->close();

        logActivity((int) $admin['id'], 'edit_user', 'users', $userId, null, [
            'full_name' => $fullName,
            'username' => $username,
            'email' => $email,
            'department' => $department,
            'role' => $role,
            'status' => $status,
            'designation' => $designation,
        ]);
        header('Location: view-user.php?id=' . $userId);
        exit();
    }
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $selectedUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="alert alert-info">
                    Editing account #<?php echo (int) $selectedUser['id']; ?>:
                    <strong><?php echo htmlspecialchars((string) $selectedUser['full_name']); ?></strong>
                    (<?php echo htmlspecialchars(getRoleDisplayName((string) $selectedUser['role'])); ?>)
                </div>
                <form method="POST" action="edit-user.php?id=<?php echo (int) $selectedUser['id']; ?>" autocomplete="off">
                    <input type="hidden" name="id" value="<?php echo (int) $selectedUser['id']; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($_POST['full_name'] ?? $selectedUser['full_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($_POST['username'] ?? $selectedUser['username']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? $selectedUser['email']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select">
                                <option value="">Select department</option>
                                <?php $selectedDepartment = (string) ($_POST['department'] ?? $selectedUser['department']); ?>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?php echo htmlspecialchars($department); ?>" <?php echo $selectedDepartment === $department ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($selectedDepartment && !in_array($selectedDepartment, $departments, true)): ?>
                                    <option value="<?php echo htmlspecialchars($selectedDepartment); ?>" selected>
                                        <?php echo htmlspecialchars($selectedDepartment); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                <?php $selectedRole = (string) ($_POST['role'] ?? $selectedUser['role']); ?>
                                <?php foreach (['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer'] as $role): ?>
                                    <option value="<?php echo $role; ?>" <?php echo $selectedRole === $role ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(getRoleDisplayName($role)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php $selectedStatus = (string) ($_POST['status'] ?? $selectedUser['status']); ?>
                                <?php foreach (['active', 'inactive', 'suspended'] as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo $selectedStatus === $status ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($status)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Designation</label>
                            <input type="text" name="designation" class="form-control" value="<?php echo htmlspecialchars($_POST['designation'] ?? $selectedUser['designation']); ?>">
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
