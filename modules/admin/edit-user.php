<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
requireRole('admin');

$conn = getConnection();
workflowEnsureSchema($conn);
$hasDeveloperClassification = ensureUserDeveloperClassificationColumn();
$admin = getCurrentUser();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$routeUserId = (int) ($_GET['id'] ?? 0);
$postedUserId = (int) ($_POST['id'] ?? 0);
$userId = $routeUserId > 0 ? $routeUserId : $postedUserId;
$departments = getDepartmentOptions();
$developerClassificationOptions = getDeveloperClassificationOptions();
$errors = [];
$formValues = [];
$saveAttempted = false;

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
    $saveAttempted = true;
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
    $developerClassification = $role === 'developer'
        ? normalizeDeveloperClassification($_POST['developer_classification'] ?? null)
        : null;

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
    if ($role === 'developer' && $developerClassification === null) {
        $errors[] = 'Select a developer classification.';
    }
    if ($role === 'developer' && !$hasDeveloperClassification) {
        $errors[] = 'Unable to prepare developer classifications. Please try again.';
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
        $updateSql = $hasDeveloperClassification
            ? 'UPDATE users SET full_name = ?, username = ?, email = ?, department = ?, role = ?, status = ?, designation = ?, developer_classification = ? WHERE id = ?'
            : 'UPDATE users SET full_name = ?, username = ?, email = ?, department = ?, role = ?, status = ?, designation = ? WHERE id = ?';
        $stmt = $conn->prepare($updateSql);
        if ($stmt === false) {
            $errors[] = 'Unable to prepare the user update. Please try again.';
            error_log('edit-user prepare failed: ' . ($conn->error ?? 'unknown error'));
        } else {
            if ($hasDeveloperClassification) {
                $stmt->bind_param(
                    'ssssssssi',
                    $fullName,
                    $username,
                    $email,
                    $department,
                    $role,
                    $status,
                    $designation,
                    $developerClassification,
                    $userId
                );
            } else {
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
            }

            if (!$stmt->execute()) {
                $errors[] = 'Unable to save changes: ' . ($stmt->error ?: 'database update failed');
                error_log('edit-user execute failed for user_id=' . $userId . ': ' . ($stmt->error ?: 'unknown error'));
            } else {
                $changedRows = $stmt->affected_rows;
                logActivity((int) $admin['id'], 'edit_user', 'users', $userId, null, [
                    'full_name' => $fullName,
                    'username' => $username,
                    'email' => $email,
                    'department' => $department,
                    'role' => $role,
                    'status' => $status,
                    'designation' => $designation,
                    'developer_classification' => $developerClassification,
                ]);

                $_SESSION['success'] = 'User profile saved successfully.';
                $stmt->close();
                header('Location: view-user.php?id=' . $userId);
                exit();
            }

            $stmt->close();
        }
    }
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $selectedUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$usePostedValues = $isPost && $postedUserId === $userId && $errors !== [];
$formValues = [
    'full_name' => (string) ($usePostedValues ? ($_POST['full_name'] ?? '') : ($selectedUser['full_name'] ?? '')),
    'username' => (string) ($usePostedValues ? ($_POST['username'] ?? '') : ($selectedUser['username'] ?? '')),
    'email' => (string) ($usePostedValues ? ($_POST['email'] ?? '') : ($selectedUser['email'] ?? '')),
    'department' => (string) ($usePostedValues ? ($_POST['department'] ?? '') : ($selectedUser['department'] ?? '')),
    'role' => (string) ($usePostedValues ? ($_POST['role'] ?? '') : ($selectedUser['role'] ?? '')),
    'status' => (string) ($usePostedValues ? ($_POST['status'] ?? '') : ($selectedUser['status'] ?? '')),
    'designation' => (string) ($usePostedValues ? ($_POST['designation'] ?? '') : ($selectedUser['designation'] ?? '')),
    'developer_classification' => (string) ($usePostedValues ? ($_POST['developer_classification'] ?? '') : ($selectedUser['developer_classification'] ?? '')),
];

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
                <?php if ($saveAttempted && !$errors): ?>
                    <div class="alert alert-warning">
                        The save request was received, but no update was completed. Please try again.
                    </div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="edit-user.php?id=<?php echo (int) $selectedUser['id']; ?>" autocomplete="off" novalidate data-generic-draft-disabled="true" data-confirm-skip="true" onsubmit="this.dataset.confirmedSubmit = '1';">
                    <input type="hidden" name="id" value="<?php echo (int) $selectedUser['id']; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="editFullName">Full Name</label>
                            <input type="text" id="editFullName" name="full_name" class="form-control" value="<?php echo htmlspecialchars($formValues['full_name']); ?>" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editUsername">Username</label>
                            <input type="text" id="editUsername" name="username" class="form-control" value="<?php echo htmlspecialchars($formValues['username']); ?>" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editEmail">Email Address</label>
                            <input type="email" id="editEmail" name="email" class="form-control" value="<?php echo htmlspecialchars($formValues['email']); ?>" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editDepartment">Department</label>
                            <select name="department" id="editDepartment" class="form-select">
                                <option value="">Select department</option>
                                <?php $selectedDepartment = $formValues['department']; ?>
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
                            <label class="form-label" for="editRole">Role</label>
                            <select name="role" id="editRole" class="form-select">
                                <?php $selectedRole = $formValues['role']; ?>
                                <?php foreach (['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer'] as $role): ?>
                                    <option value="<?php echo $role; ?>" <?php echo $selectedRole === $role ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(getRoleDisplayName($role)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editStatus">Status</label>
                            <select name="status" id="editStatus" class="form-select">
                                <?php $selectedStatus = $formValues['status']; ?>
                                <?php foreach (['active', 'inactive', 'suspended'] as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo $selectedStatus === $status ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($status)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editDesignation">Designation</label>
                            <input type="text" id="editDesignation" name="designation" class="form-control" value="<?php echo htmlspecialchars($formValues['designation']); ?>" autocomplete="off">
                        </div>
                        <div class="col-md-6<?php echo $formValues['role'] === 'developer' ? '' : ' d-none'; ?>" data-developer-classification-field>
                            <label class="form-label" for="developerClassification">Developer Classification</label>
                            <select name="developer_classification" id="developerClassification" class="form-select" <?php echo $formValues['role'] === 'developer' ? 'required' : 'disabled'; ?>>
                                <option value="">Select classification</option>
                                <?php foreach ($developerClassificationOptions as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $formValues['developer_classification'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="button" class="btn btn-primary" name="save_user" value="1" onclick="HTMLFormElement.prototype.submit.call(this.form);">Save Changes</button>
                        <a href="view-user.php?id=<?php echo (int) $selectedUser['id']; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const roleSelect = document.querySelector('select[name="role"]');
    const classificationField = document.querySelector('[data-developer-classification-field]');
    const classificationSelect = document.getElementById('developerClassification');
    if (!roleSelect || !classificationField || !classificationSelect) return;

    const toggleClassification = function () {
        const isDeveloper = roleSelect.value === 'developer';
        classificationField.classList.toggle('d-none', !isDeveloper);
        classificationSelect.disabled = !isDeveloper;
        classificationSelect.required = isDeveloper;
        if (!isDeveloper) classificationSelect.value = '';
    };

    roleSelect.addEventListener('change', toggleClassification);
    toggleClassification();
});
</script>

<?php include '../../includes/footer.php'; ?>
