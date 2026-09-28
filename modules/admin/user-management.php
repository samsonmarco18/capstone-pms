<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
requireRole('admin');

$conn = getConnection();
workflowEnsureSchema($conn);
$user = getCurrentUser();
$hasDeveloperClassification = ensureUserDeveloperClassificationColumn();

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $passwordInput = (string) ($_POST['password'] ?? '');
        $password = password_hash($passwordInput, PASSWORD_DEFAULT);
        $full_name = trim((string) ($_POST['full_name'] ?? ''));
        $department = trim((string) $_POST['department']);
        $role = trim((string) $_POST['role']);
        $developerClassification = in_array($role, ['developer', 'qa'], true)
            ? normalizeDeveloperClassification($_POST['developer_classification'] ?? null)
            : null;
        $hasPasswordChangedAt = ensureUserPasswordSecurityColumns();

        if ($username === '' || $full_name === '' || $passwordInput === '') {
            $_SESSION['error'] = 'Username, full name, and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Enter a valid email address.';
        } elseif (!in_array($role, ['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer'], true)) {
            $_SESSION['error'] = 'Select a valid role.';
        } elseif ($role === 'sul' && $department === '') {
            $_SESSION['error'] = "SUL users must be assigned to a department.";
        } elseif (in_array($role, ['developer', 'qa'], true) && $developerClassification === null) {
            $_SESSION['error'] = 'Select a classification.';
        } elseif (in_array($role, ['developer', 'qa'], true) && !$hasDeveloperClassification) {
            $_SESSION['error'] = 'Unable to prepare classifications. Please try again.';
        } else {
            $uniqueStmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            if ($uniqueStmt === false) {
                $_SESSION['error'] = 'Unable to validate the new user. Please try again.';
                error_log('user-management create unique prepare failed: ' . ($conn->error ?? 'unknown error'));
            } else {
                $uniqueStmt->bind_param('ss', $username, $email);
                $uniqueStmt->execute();
                $duplicateExists = $uniqueStmt->get_result()->num_rows > 0;
                $uniqueStmt->close();

                if ($duplicateExists) {
                    $_SESSION['error'] = 'Username or email is already used by another account.';
                } else {
                    if ($hasDeveloperClassification) {
                        $sql = $hasPasswordChangedAt
                            ? "INSERT INTO users (username, email, password, full_name, department, role, developer_classification, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
                            : "INSERT INTO users (username, email, password, full_name, department, role, developer_classification) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    } elseif ($hasPasswordChangedAt) {
                        $sql = "INSERT INTO users (username, email, password, full_name, department, role, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                    } else {
                        $sql = "INSERT INTO users (username, email, password, full_name, department, role) VALUES (?, ?, ?, ?, ?, ?)";
                    }
                    $stmt = $conn->prepare($sql);
                    if ($stmt === false) {
                        $_SESSION['error'] = 'Unable to prepare user creation. Please try again.';
                        error_log('user-management create prepare failed: ' . ($conn->error ?? 'unknown error'));
                    } else {
                        if ($hasDeveloperClassification) {
                            $stmt->bind_param("sssssss", $username, $email, $password, $full_name, $department, $role, $developerClassification);
                        } else {
                            $stmt->bind_param("ssssss", $username, $email, $password, $full_name, $department, $role);
                        }

                        if ($stmt->execute()) {
                            $userId = $stmt->insert_id;
                            logActivity((int) $user['id'], 'create_user', 'users', (int) $userId, null, [
                                'username' => $username,
                                'email' => $email,
                                'full_name' => $full_name,
                                'department' => $department,
                                'role' => $role,
                                'developer_classification' => $developerClassification,
                            ]);
                            $_SESSION['success'] = "User created successfully";
                        } else {
                            $_SESSION['error'] = "Error creating user: " . ($stmt->error ?: ($conn->error ?? 'database error'));
                        }
                        $stmt->close();
                    }
                }
            }
        }
    } elseif ($_POST['action'] == 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $full_name = trim((string) ($_POST['full_name'] ?? ''));
        $department = trim((string) $_POST['department']);
        $role = trim((string) $_POST['role']);
        $status = trim((string) ($_POST['status'] ?? ''));
        $developerClassification = in_array($role, ['developer', 'qa'], true)
            ? normalizeDeveloperClassification($_POST['developer_classification'] ?? null)
            : null;
        
        if ($id <= 0 || $full_name === '') {
            $_SESSION['error'] = 'Select a valid user and enter a full name.';
        } elseif (!in_array($role, ['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer'], true)) {
            $_SESSION['error'] = 'Select a valid role.';
        } elseif (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $_SESSION['error'] = 'Select a valid account status.';
        } elseif ($role === 'sul' && $department === '') {
            $_SESSION['error'] = "SUL users must be assigned to a department.";
        } elseif (in_array($role, ['developer', 'qa'], true) && $developerClassification === null) {
            $_SESSION['error'] = 'Select a classification.';
        } elseif (in_array($role, ['developer', 'qa'], true) && !$hasDeveloperClassification) {
            $_SESSION['error'] = 'Unable to prepare classifications. Please try again.';
        } else {
            $oldData = $conn->query("SELECT * FROM users WHERE id = {$id}")->fetch_assoc();

            $updateSql = $hasDeveloperClassification
                ? "UPDATE users SET full_name=?, department=?, role=?, status=?, developer_classification=? WHERE id=?"
                : "UPDATE users SET full_name=?, department=?, role=?, status=? WHERE id=?";
            $stmt = $conn->prepare($updateSql);
            if ($stmt === false) {
                $_SESSION['error'] = 'Unable to prepare user update. Please try again.';
                error_log('user-management update prepare failed: ' . ($conn->error ?? 'unknown error'));
            } else {
                if ($hasDeveloperClassification) {
                    $stmt->bind_param("sssssi", $full_name, $department, $role, $status, $developerClassification, $id);
                } else {
                    $stmt->bind_param("ssssi", $full_name, $department, $role, $status, $id);
                }

                if ($stmt->execute()) {
                    logActivity((int) $user['id'], 'update_user', 'users', $id, $oldData, [
                        'full_name' => $full_name,
                        'department' => $department,
                        'role' => $role,
                        'status' => $status,
                        'developer_classification' => $developerClassification,
                    ]);
                    $_SESSION['success'] = "User updated successfully";
                } else {
                    $_SESSION['error'] = "Error updating user: " . ($stmt->error ?: ($conn->error ?? 'database error'));
                }
                $stmt->close();
            }
        }
    } elseif ($_POST['action'] == 'delete') {
        $id = $_POST['id'];
        
        $oldData = $conn->query("SELECT * FROM users WHERE id = $id")->fetch_assoc();
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'delete_user', 'users', $id, $oldData, null);
            $_SESSION['success'] = "User deleted successfully";
        } else {
            $_SESSION['error'] = "Error deleting user";
        }
    }
    
    header('Location: user-management.php');
    exit();
}

// Get all users
$search = trim((string) ($_GET['search'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$allowedUserPageSizes = [10, 25, 50];
$userPageSize = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
if (!in_array($userPageSize, $allowedUserPageSizes, true)) {
    $userPageSize = 10;
}
$userCurrentPage = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);

$userWhereSql = ' WHERE 1=1';
$params = [];
$types = '';

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    if (DB_DRIVER === 'pgsql') {
        $userWhereSql .= ' AND (username ILIKE ? OR email ILIKE ? OR full_name ILIKE ?)';
    } else {
        $userWhereSql .= ' AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)';
    }
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= 'sss';
}

if ($roleFilter !== '') {
    $userWhereSql .= ' AND role = ?';
    $params[] = $roleFilter;
    $types .= 's';
}

if ($statusFilter !== '') {
    $userWhereSql .= ' AND status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

$totalFilteredUsers = 0;
$userCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM users' . $userWhereSql);
if ($userCountStmt !== false) {
    if ($types !== '') {
        $userCountStmt->bind_param($types, ...$params);
    }
    $userCountStmt->execute();
    $userCountResult = $userCountStmt->get_result();
    $userCountRow = $userCountResult ? $userCountResult->fetch_assoc() : null;
    $totalFilteredUsers = (int) ($userCountRow['total'] ?? 0);
    $userCountStmt->close();
}

$userTotalPages = max(1, (int) ceil($totalFilteredUsers / $userPageSize));
$userCurrentPage = min($userCurrentPage, $userTotalPages);
$userPageOffset = ($userCurrentPage - 1) * $userPageSize;

$query = 'SELECT * FROM users'
    . $userWhereSql
    . ' ORDER BY created_at DESC'
    . ' LIMIT ' . $userPageSize
    . ' OFFSET ' . $userPageOffset;

$usersStmt = $conn->prepare($query);
if ($usersStmt === false) {
    $users = false;
} elseif ($types !== '') {
    $usersStmt->bind_param($types, ...$params);
    $usersStmt->execute();
    $users = $usersStmt->get_result();
    $usersStmt->close();
} else {
    $usersStmt->execute();
    $users = $usersStmt->get_result();
    $usersStmt->close();
}

$userPageStart = $totalFilteredUsers > 0 ? $userPageOffset + 1 : 0;
$userPageEnd = min($userPageOffset + ($users ? $users->num_rows : 0), $totalFilteredUsers);
$buildUserPageUrl = static function (int $page, ?int $pageSize = null) use ($search, $roleFilter, $statusFilter, $userPageSize): string {
    $queryParams = [];

    if ($search !== '') {
        $queryParams['search'] = $search;
    }
    if ($roleFilter !== '') {
        $queryParams['role'] = $roleFilter;
    }
    if ($statusFilter !== '') {
        $queryParams['status'] = $statusFilter;
    }

    $queryParams['per_page'] = $pageSize ?? $userPageSize;
    if ($page > 1) {
        $queryParams['page'] = $page;
    }

    return 'user-management.php?' . http_build_query($queryParams) . '#userDirectory';
};

if ($userTotalPages <= 5) {
    $userPaginationPages = range(1, $userTotalPages);
} else {
    $userPaginationStart = max(1, min($userCurrentPage - 2, $userTotalPages - 4));
    $userPaginationPages = range($userPaginationStart, $userPaginationStart + 4);
}

// Get statistics
$totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$activeUsers = $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'];
$adminUsers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'];

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
    --panel-surface: #ffffff;
    --panel-surface-alt: #f8fbff;
    --panel-border: var(--gray-200);
    --panel-text: var(--gray-900);
    --panel-muted: var(--gray-600);
    --panel-shadow: var(--shadow-sm);
    --panel-shadow-hover: var(--shadow-md);
    --panel-row-hover: var(--gray-50);
    --panel-input-bg: #ffffff;
    --panel-input-text: var(--gray-900);
    --panel-badge-bg: #eef5fb;
    --panel-badge-text: #1f6392;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) {
    --panel-surface: rgba(8, 20, 33, 0.94);
    --panel-surface-alt: rgba(10, 24, 38, 0.98);
    --panel-border: rgba(138, 210, 255, 0.12);
    --panel-text: #d8e6f3;
    --panel-muted: #90aeca;
    --panel-shadow: 0 10px 28px rgba(0, 0, 0, 0.32);
    --panel-shadow-hover: 0 18px 38px rgba(0, 0, 0, 0.42);
    --panel-row-hover: rgba(31, 99, 146, 0.12);
    --panel-input-bg: rgba(10, 24, 38, 0.98);
    --panel-input-text: #d8e6f3;
    --panel-badge-bg: rgba(31, 99, 146, 0.18);
    --panel-badge-text: #bfe6ff;
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: white;
}

.page-header-modern h2 i {
    color: white;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9);
}

/* Stats Row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-modern {
    background: var(--panel-surface);
    border-radius: 20px;
    padding: 1.25rem 1.5rem;
    flex: 1;
    min-width: 180px;
    box-shadow: var(--panel-shadow);
    border: 1px solid var(--panel-border);
    transition: all 0.2s ease;
    position: relative;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--panel-shadow-hover);
}

.stat-card-modern .stat-icon {
    position: absolute;
    right: 1rem;
    top: 1rem;
    opacity: 0.65;
    font-size: 1.6rem;
    color: var(--theme-primary);
}

.stat-card-modern .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--panel-muted);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

/* Main Card */
.main-card {
    background: var(--panel-surface);
    border-radius: 24px;
    border: 1px solid var(--panel-border);
    overflow: hidden;
    box-shadow: var(--panel-shadow);
}

.card-header-modern {
    padding: 1.5rem 2rem;
    background: var(--panel-surface);
    border-bottom: 1px solid var(--panel-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.card-header-modern h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--panel-text);
    margin: 0;
}

.card-header-modern .text-muted {
    color: var(--panel-muted) !important;
}

.user-directory-count {
    color: var(--panel-muted);
    font-size: 0.78rem;
    white-space: nowrap;
}

/* Filter Section */
.filter-section {
    padding: 1rem 2rem 1.15rem;
    background: var(--panel-surface);
}

.filter-group {
    display: grid;
    grid-template-columns: minmax(280px, 1.45fr) minmax(155px, 0.65fr) minmax(155px, 0.65fr) auto;
    align-items: center;
    gap: 1rem;
}

.filter-input {
    position: relative;
    min-width: 0;
}

.filter-input > i {
    position: absolute;
    top: 50%;
    left: 0.1rem;
    z-index: 1;
    color: #668096;
    font-size: 0.82rem;
    transform: translateY(-50%);
    pointer-events: none;
}

.filter-input input,
.filter-input select {
    width: 100%;
    min-height: 42px;
    padding: 0.52rem 1.8rem 0.52rem 1.65rem;
    border: 0;
    border-bottom: 1px solid #cbd8e3;
    border-radius: 0;
    outline: 0;
    background: transparent;
    color: var(--panel-input-text);
    font-size: 0.82rem;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.filter-input input:focus,
.filter-input select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 2px 0 rgba(31, 99, 146, 0.12);
}

.user-filter-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.65rem;
    white-space: nowrap;
}

.user-filter-clear {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    min-height: 42px;
    padding: 0.55rem 0.3rem;
    color: var(--panel-muted);
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
}

.user-filter-clear:hover {
    color: var(--theme-primary);
}

.btn-add-user-toolbar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 42px;
    padding: 0.62rem 1.05rem;
    border: 1px solid var(--theme-primary);
    border-radius: 10px;
    background: var(--theme-primary);
    color: #fff;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
    box-shadow: 0 6px 14px rgba(31, 99, 146, 0.16);
    transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.btn-add-user-toolbar:hover,
.btn-add-user-toolbar:focus-visible {
    border-color: var(--theme-primary-dark);
    background: var(--theme-primary-dark);
    color: #fff;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.22);
    transform: translateY(-1px);
}

/* Table Styles */
.table-container {
    padding: 0;
    overflow: visible;
}

.user-table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
}

.user-table thead {
    background: var(--panel-surface-alt);
}

.user-table th {
    padding: 0.9rem 0.65rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--panel-muted);
    border-bottom: 1px solid var(--panel-border);
}

.user-table td {
    padding: 0.9rem 0.65rem;
    overflow-wrap: anywhere;
    vertical-align: middle;
    border-bottom: 1px solid var(--panel-border);
}

.user-table tr:hover {
    background: var(--panel-row-hover);
}

.user-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 1.5rem;
    border-top: 1px solid var(--panel-border);
    background: var(--panel-surface);
}

.user-pagination__meta,
.user-pagination__controls,
.user-pagination__pages {
    display: flex;
    align-items: center;
}

.user-pagination__meta {
    gap: 0.75rem;
    min-width: 0;
}

.user-pagination__summary {
    color: var(--panel-muted);
    font-size: 0.78rem;
    white-space: nowrap;
}

.user-pagination__size {
    height: 36px;
    min-width: 94px;
    padding: 0 1.8rem 0 0.7rem;
    border: 1px solid var(--panel-border);
    border-radius: 9px;
    background: var(--panel-surface);
    color: var(--panel-text);
    font-size: 0.78rem;
    font-weight: 600;
}

.user-pagination__controls {
    gap: 0.55rem;
}

.user-pagination__pages {
    overflow: hidden;
    border: 1px solid var(--panel-border);
    border-radius: 9px;
    background: var(--panel-surface);
}

.user-pagination__page,
.user-pagination__step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    color: var(--panel-text);
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
}

.user-pagination__page {
    min-width: 36px;
    padding: 0 0.55rem;
}

.user-pagination__page:hover,
.user-pagination__page.is-current {
    background: #edf5fa;
    color: var(--theme-primary);
}

.user-pagination__page.is-current {
    box-shadow: inset 0 0 0 1px #cfe1ec;
    pointer-events: none;
}

.user-pagination__step {
    min-width: 76px;
    padding: 0 0.75rem;
    border: 1px solid var(--panel-border);
    border-radius: 9px;
    background: var(--panel-surface);
}

.user-pagination__step:hover {
    border-color: #9bbdd1;
    color: var(--theme-primary);
}

.user-pagination__step.is-disabled {
    background: var(--panel-surface-alt);
    color: var(--panel-muted);
    opacity: 0.55;
    pointer-events: none;
}

.user-name {
    font-weight: 600;
    color: var(--panel-text);
    margin-bottom: 0.25rem;
}

.user-email {
    font-size: 0.75rem;
    color: var(--panel-muted);
}

.user-directory-profile {
    align-items: center;
    display: flex;
    gap: 0.75rem;
    min-width: 0;
}

.user-directory-avatar {
    align-items: center;
    background: linear-gradient(145deg, #dff1fb 0%, #c8e4f4 100%);
    border: 1px solid #bedcec;
    border-radius: 50%;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 38px;
    font-size: 0.72rem;
    font-weight: 700;
    height: 38px;
    justify-content: center;
    letter-spacing: 0.02em;
    overflow: hidden;
    width: 38px;
}

.user-directory-avatar img {
    display: block;
    height: 100%;
    object-fit: cover;
    width: 100%;
}

.user-directory-avatar.has-profile-image span {
    display: none;
}

.user-directory-copy {
    min-width: 0;
}
.user-table th:nth-child(1) { width: 26%; }
.user-table th:nth-child(2) { width: 13%; }
.user-table th:nth-child(3) { width: 11%; }
.user-table th:nth-child(4) { width: 15%; }
.user-table th:nth-child(5) { width: 10%; }
.user-table th:nth-child(6) { width: 12%; }
.user-table th:nth-child(7) { width: 13%; }
.user-table .user-name, .user-table .user-email { white-space: normal; overflow-wrap: anywhere; }
.user-table .badge-role, .user-table .badge-status { max-width: 100%; white-space: normal; padding-inline: 7px; }
.user-table .action-buttons { flex-wrap: wrap; gap: 4px; }
.user-table .btn-icon { flex: 0 0 28px; width: 28px; height: 30px; }
.user-table .user-directory-profile { gap: 8px; }
@media (max-width: 650px) {
    .user-table thead { display: none; }
    .user-table, .user-table tbody { display: block; }
    .user-table tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 12px; border-bottom: 1px solid var(--panel-border); }
    .user-table td { display: block; border: 0; padding: 6px !important; min-width: 0; }
    .user-table td:first-child { grid-column: 1 / -1; }
    .user-table td::before { display: block; font-size: 10px; color: var(--panel-muted); text-transform: uppercase; margin-bottom: 3px; }
    .user-table td:nth-child(2)::before { content: 'Department'; }
    .user-table td:nth-child(3)::before { content: 'Role'; }
    .user-table td:nth-child(4)::before { content: 'Classification'; }
    .user-table td:nth-child(5)::before { content: 'Status'; }
    .user-table td:nth-child(6)::before { content: 'Created'; }
    .user-table td:nth-child(7)::before { content: 'Actions'; }
    .user-table .action-buttons { flex-direction: row; }
}

/* Badges */
.badge-role {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    background: var(--panel-badge-bg);
    color: var(--panel-badge-text);
    border: 1px solid transparent;
}

.badge-role.admin {
    background: rgba(220, 38, 38, 0.10);
    color: #b91c1c;
    border-color: rgba(220, 38, 38, 0.18);
}

.badge-role.sales {
    background: rgba(37, 99, 235, 0.10);
    color: #1d4ed8;
    border-color: rgba(37, 99, 235, 0.18);
}

.badge-role.architect {
    background: rgba(79, 70, 229, 0.10);
    color: #4f46e5;
    border-color: rgba(79, 70, 229, 0.18);
}

.badge-role.sul {
    background: rgba(217, 119, 6, 0.10);
    color: #b45309;
    border-color: rgba(217, 119, 6, 0.18);
}

.badge-role.qa {
    background: rgba(14, 116, 144, 0.10);
    color: #0f766e;
    border-color: rgba(14, 116, 144, 0.18);
}

.badge-role.expert {
    background: rgba(5, 150, 105, 0.10);
    color: #047857;
    border-color: rgba(5, 150, 105, 0.18);
}

.badge-role.developer {
    background: rgba(73, 80, 87, 0.10);
    color: #475569;
    border-color: rgba(73, 80, 87, 0.18);
}

.badge-status {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    border: 1px solid transparent;
}

.badge-status.active {
    background: rgba(5, 150, 105, 0.10);
    color: #047857;
    border-color: rgba(5, 150, 105, 0.18);
}

.badge-status.inactive {
    background: rgba(217, 119, 6, 0.10);
    color: #b45309;
    border-color: rgba(217, 119, 6, 0.18);
}

.badge-status.suspended {
    background: rgba(220, 38, 38, 0.10);
    color: #b91c1c;
    border-color: rgba(220, 38, 38, 0.18);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    cursor: pointer;
    border: none;
}

.btn-icon.view {
    background: var(--panel-surface-alt);
    color: var(--panel-muted);
}

.btn-icon.view:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
}

.btn-icon.edit {
    background: var(--panel-surface-alt);
    color: var(--theme-primary);
}

.btn-icon.edit:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
}

.btn-icon.delete {
    background: rgba(220, 38, 38, 0.08);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.18);
}

.btn-icon.delete:hover {
    background: #dc2626;
    color: white;
}

/* Add Button */
.btn-add-modern {
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white !important;
    padding: 0.7rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s ease;
    backdrop-filter: blur(10px);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-add-modern:hover {
    background: white;
    color: var(--theme-primary) !important;
    transform: translateY(-2px);
    border-color: white;
}

.btn-add-modern i {
    color: white !important;
}

.btn-add-modern:hover i {
    color: var(--theme-primary) !important;
}

/* Primary Button (Solid) */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.4rem;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state-modern i {
    font-size: 4rem;
    color: var(--panel-muted);
    margin-bottom: 1rem;
}

.empty-state-modern h4 {
    color: var(--panel-text);
    margin-bottom: 0.5rem;
}

.empty-state-modern p {
    color: var(--panel-muted);
}

/* Modal Styling */
.modal-modern .modal-content {
    background: var(--panel-surface);
    border: 1px solid var(--panel-border);
    border-radius: 18px;
    box-shadow: 0 24px 70px rgba(15, 43, 67, 0.24);
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, #1f6392 0%, #0f4a6e 100%) !important;
    border: 0;
    color: #ffffff;
    padding: 1.1rem 1.35rem;
}

.modal-modern .modal-header .modal-title {
    align-items: center;
    color: #ffffff !important;
    display: flex;
    font-size: 1.05rem;
    font-weight: 700;
    gap: 0.7rem;
    margin: 0;
}

.modal-title-icon {
    align-items: center;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 9px;
    display: inline-flex;
    height: 34px;
    justify-content: center;
    width: 34px;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.75;
}

.modal-modern .modal-header .btn-close:hover {
    opacity: 1;
}

.modal-modern .modal-body {
    padding: 1.5rem;
}

.modal-modern .modal-footer {
    background: var(--panel-surface-alt);
    border-top: 1px solid var(--panel-border);
    gap: 0.65rem;
    padding: 1rem 1.5rem;
}

.modal-modern .form-label {
    color: var(--panel-text);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.045em;
    margin-bottom: 0.45rem;
    text-transform: uppercase;
}

.modal-modern .form-control,
.modal-modern .form-select {
    border-radius: 10px;
    border: 1px solid var(--panel-border);
    background: var(--panel-input-bg);
    color: var(--panel-input-text);
    min-height: 46px;
    padding: 0.65rem 0.85rem;
}

.modal-modern .form-control:focus,
.modal-modern .form-select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.account-create-modal .modal-dialog {
    max-width: 760px;
}

.modal-secondary-action {
    align-items: center;
    background: transparent;
    border: 1px solid #cbd9e4;
    border-radius: 10px;
    color: #405a70;
    display: inline-flex;
    font-size: 0.85rem;
    font-weight: 700;
    justify-content: center;
    min-height: 44px;
    padding: 0.65rem 1.05rem;
}

.modal-secondary-action:hover {
    background: #eef5f9;
    border-color: #abc1d1;
    color: #183f59;
}

.modal-modern .modal-content {
    background: var(--panel-surface);
    color: var(--panel-text);
    box-shadow: var(--panel-shadow-hover);
}

.modal-modern .modal-body,
.modal-modern .modal-footer {
    background: var(--panel-surface);
}

.modal-modern .btn-close {
    filter: brightness(0) invert(1);
}

.delete-confirm-note {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1rem;
    border-radius: 16px;
    background: rgba(220, 38, 38, 0.06);
    border: 1px solid rgba(220, 38, 38, 0.12);
    color: var(--panel-text);
}

.delete-confirm-note i {
    color: #dc2626;
    margin-top: 0.15rem;
    flex-shrink: 0;
}

.btn-delete-cancel {
    background: var(--panel-surface-alt);
    border: 1px solid var(--panel-border);
    color: var(--panel-text);
    border-radius: 12px;
    padding: 0.65rem 1.15rem;
    font-weight: 600;
}

.btn-delete-cancel:hover {
    background: var(--panel-row-hover);
    color: var(--panel-text);
}

.btn-delete-confirm {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    border: 1px solid #dc2626;
    color: #fff;
    border-radius: 12px;
    padding: 0.65rem 1.15rem;
    font-weight: 600;
    box-shadow: 0 10px 22px rgba(220, 38, 38, 0.18);
}

.btn-delete-confirm:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    box-shadow: 0 16px 38px rgba(0, 0, 0, 0.26);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content {
    background: var(--panel-surface);
    border-color: var(--panel-border);
    box-shadow: var(--panel-shadow);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer {
    background: var(--panel-surface-alt);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__pages,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__size {
    background: var(--panel-surface);
    border-color: var(--panel-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern h4 {
    color: var(--panel-text) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-directory-avatar {
    background: #102a40;
    border-color: #214764;
    color: #8cc7ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-title {
    color: #ffffff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-secondary-action {
    border-color: rgba(143, 211, 255, 0.32);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-secondary-action:hover {
    background: rgba(31, 99, 146, 0.18);
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-email,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-table td {
    color: var(--panel-muted) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input input,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select {
    background: transparent;
    border-color: var(--panel-border);
    color: var(--panel-input-text);
    color-scheme: dark;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select option,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__size option,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select option {
    background-color: #071725;
    color: var(--panel-input-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select option:checked,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__size option:checked,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select option:checked {
    background-color: var(--theme-primary);
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input input:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select:focus {
    background: transparent;
    border-color: var(--theme-primary);
    color: var(--panel-input-text);
    box-shadow: 0 2px 0 rgba(138, 210, 255, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-select {
    background: var(--panel-input-bg);
    border-color: var(--panel-border);
    color: var(--panel-input-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-select:focus {
    background: var(--panel-input-bg);
    border-color: var(--theme-primary);
    color: var(--panel-input-text);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-table tr:hover {
    background: var(--panel-row-hover);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__page,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__size {
    color: var(--panel-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__page:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__page.is-current {
    background: rgba(31, 99, 146, 0.28);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-pagination__step.is-disabled {
    background: var(--panel-surface-alt);
    color: var(--panel-muted);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-role,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-status {
    border: 1px solid rgba(255, 255, 255, 0.06);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.view,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.edit {
    background: rgba(144, 174, 202, 0.12);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.delete {
    background: rgba(220, 38, 38, 0.15);
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.view:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.edit:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.delete:hover {
    background: #dc2626;
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-note {
    background: rgba(220, 38, 38, 0.10);
    border-color: rgba(220, 38, 38, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-delete-cancel {
    background: var(--panel-surface-alt);
    border-color: var(--panel-border);
    color: var(--panel-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-delete-cancel:hover {
    background: rgba(144, 174, 202, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-role.admin {
    background: rgba(220, 38, 38, 0.18);
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-role.sales {
    background: rgba(37, 99, 235, 0.16);
    color: #8fc1ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-role.architect {
    background: rgba(79, 70, 229, 0.16);
    color: #b7b8ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-role.sul {
    background: rgba(217, 119, 6, 0.16);
    color: #ffd58a;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-role.qa {
    background: rgba(14, 116, 144, 0.16);
    color: #8de7ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-role.expert {
    background: rgba(5, 150, 105, 0.16);
    color: #94f1c6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-role.developer {
    background: rgba(73, 80, 87, 0.16);
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-status.active {
    background: rgba(5, 150, 105, 0.16);
    color: #94f1c6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-status.inactive {
    background: rgba(217, 119, 6, 0.16);
    color: #ffd58a;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-status.suspended {
    background: rgba(220, 38, 38, 0.15);
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .btn.btn-light {
    background: rgba(144, 174, 202, 0.12);
    border-color: rgba(144, 174, 202, 0.16);
    color: var(--panel-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .btn.btn-light:hover {
    background: rgba(144, 174, 202, 0.18);
}

/* Responsive */
@media (max-width: 1100px) {
    .filter-group {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .filter-input--search,
    .user-filter-actions {
        grid-column: 1 / -1;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-row {
        flex-direction: column;
    }
    
    .stat-card-modern {
        min-width: auto;
    }
    
    .card-header-modern {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        grid-template-columns: 1fr;
    }

    .filter-input--search,
    .user-filter-actions {
        grid-column: auto;
    }
    
    .filter-input {
        width: 100%;
    }

    .user-filter-actions {
        justify-content: space-between;
        flex-wrap: wrap;
    }

    .user-pagination {
        align-items: stretch;
        flex-direction: column;
        padding: 0.9rem 1rem;
    }

    .user-pagination__meta,
    .user-pagination__controls {
        justify-content: center;
        flex-wrap: wrap;
    }

    .user-pagination__pages {
        max-width: 100%;
        overflow-x: auto;
    }

    .user-pagination__step {
        min-width: 40px;
        padding: 0 0.65rem;
    }
    
    .user-table th,
    .user-table td {
        padding: 0.75rem 1rem;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 0.25rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success shadow-sm">
            <?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger shadow-sm">
            <?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header-modern">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2>
                    <i class="fas fa-users me-2"></i> 
                    User Management
                </h2>
                <p>Manage user accounts, roles, and permissions across your organization</p>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Total Users</div>
            <div class="stat-number"><?php echo $totalUsers; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-user-check stat-icon"></i>
            <div class="stat-label">Active Users</div>
            <div class="stat-number"><?php echo $activeUsers; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-user-shield stat-icon"></i>
            <div class="stat-label">Administrators</div>
            <div class="stat-number"><?php echo $adminUsers; ?></div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card" id="userDirectory">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list me-2" style="color: var(--theme-primary);"></i>
                User Directory
            </h5>
            <span class="user-directory-count">
                <?php echo $totalFilteredUsers; ?> user<?php echo $totalFilteredUsers === 1 ? '' : 's'; ?>
            </span>
        </div>
        
        <!-- Filter Section -->
        <form method="GET" action="user-management.php#userDirectory" class="filter-section" id="userFilterForm">
            <input type="hidden" name="per_page" value="<?php echo $userPageSize; ?>">
            <div class="filter-group">
                <div class="filter-input filter-input--search">
                    <label class="visually-hidden" for="searchInput">Search users</label>
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="searchInput" name="search" placeholder="Search by name, email, or username" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                </div>
                <div class="filter-input">
                    <label class="visually-hidden" for="roleFilter">Filter users by role</label>
                    <i class="fas fa-user-tag" aria-hidden="true"></i>
                    <select id="roleFilter" name="role">
                        <option value="">All roles</option>
                        <option value="admin" <?php echo $roleFilter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="sales" <?php echo $roleFilter == 'sales' ? 'selected' : ''; ?>>Sales</option>
                        <option value="architect" <?php echo $roleFilter == 'architect' ? 'selected' : ''; ?>>Architect</option>
                        <option value="sul" <?php echo $roleFilter == 'sul' ? 'selected' : ''; ?>>SUL</option>
                        <option value="qa" <?php echo $roleFilter == 'qa' ? 'selected' : ''; ?>>QA</option>
                        <option value="expert" <?php echo $roleFilter == 'expert' ? 'selected' : ''; ?>>Expert/Senior</option>
                        <option value="developer" <?php echo $roleFilter == 'developer' ? 'selected' : ''; ?>>Developer</option>
                    </select>
                </div>
                <div class="filter-input">
                    <label class="visually-hidden" for="statusFilter">Filter users by status</label>
                    <i class="fas fa-filter" aria-hidden="true"></i>
                    <select id="statusFilter" name="status">
                        <option value="">All statuses</option>
                        <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo $statusFilter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>
                <div class="user-filter-actions">
                    <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
                    <a href="user-management.php?per_page=<?php echo $userPageSize; ?>#userDirectory" class="user-filter-clear">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                        <span>Clear</span>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn-add-user-toolbar" data-bs-toggle="modal" data-bs-target="#createUserModal">
                        <i class="fas fa-plus" aria-hidden="true"></i>
                        <span>Add New</span>
                    </button>
                </div>
            </div>
            <button type="submit" class="visually-hidden">Apply filters</button>
        </form>
        
        <!-- Users Table -->
        <div class="table-container">
            <?php if($users && $users->num_rows > 0): ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Classification</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $users->fetch_assoc()): ?>
                    <?php
                        $rowProfileImageUrl = getUserProfileImageUrl($row['profile_pic'] ?? null);
                        $rowInitials = getUserInitials($row['full_name'] ?? 'User');
                    ?>
                    <tr>
                        <td>
                            <div class="user-directory-profile">
                                <span class="user-directory-avatar <?php echo $rowProfileImageUrl !== '' ? 'has-profile-image' : ''; ?>" aria-hidden="true">
                                    <?php if ($rowProfileImageUrl !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($rowProfileImageUrl); ?>" alt="" loading="lazy" onerror="this.remove(); this.parentElement.classList.remove('has-profile-image');">
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($rowInitials); ?></span>
                                </span>
                                <div class="user-directory-copy">
                                    <div class="user-name"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($row['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($row['department'] ?: '—'); ?></td>
                        <td>
                            <span class="badge-role <?php echo $row['role']; ?>">
                                <?php echo strtoupper($row['role']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (in_array((string) ($row['role'] ?? ''), ['developer', 'qa'], true)): ?>
                                <span class="badge-role developer">
                                    <?php echo htmlspecialchars(getDeveloperClassificationLabel($row['developer_classification'] ?? null)); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge-status <?php echo $row['status']; ?>">
                                <?php echo ucfirst($row['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <a class="btn-icon view" href="view-user.php?id=<?php echo (int) $row['id']; ?>" aria-label="View <?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a class="btn-icon edit" href="edit-user.php?id=<?php echo (int) $row['id']; ?>" aria-label="Edit <?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button
                                    type="button"
                                    class="btn-icon delete"
                                    data-user-id="<?php echo (int) $row['id']; ?>"
                                    data-user-name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>"
                                    onclick="openDeleteUserModal(this)"
                                >
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-user-friends"></i>
                <h4>No Users Found</h4>
                <p>Try adjusting your filters or add a new user</p>
                <button class="btn-add-user-toolbar mt-2" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-plus me-2"></i> Add Your First User
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($totalFilteredUsers > 0): ?>
        <div class="user-pagination" aria-label="User directory pagination">
            <div class="user-pagination__meta">
                <span class="user-pagination__summary">
                    <?php echo $userPageStart; ?>&ndash;<?php echo $userPageEnd; ?> of <?php echo $totalFilteredUsers; ?>
                </span>
                <label class="visually-hidden" for="userPageSize">Users per page</label>
                <select class="user-pagination__size" id="userPageSize" aria-label="Users per page">
                    <?php foreach ($allowedUserPageSizes as $pageSizeOption): ?>
                    <option value="<?php echo $pageSizeOption; ?>" <?php echo $pageSizeOption === $userPageSize ? 'selected' : ''; ?>>
                        <?php echo $pageSizeOption; ?> items
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <nav class="user-pagination__controls" aria-label="User pages">
                <?php if ($userCurrentPage > 1): ?>
                <a class="user-pagination__step" href="<?php echo htmlspecialchars($buildUserPageUrl($userCurrentPage - 1)); ?>">Previous</a>
                <?php else: ?>
                <span class="user-pagination__step is-disabled" aria-disabled="true">Previous</span>
                <?php endif; ?>
                <span class="user-pagination__pages">
                    <?php foreach ($userPaginationPages as $pageNumber): ?>
                    <a
                        class="user-pagination__page<?php echo $pageNumber === $userCurrentPage ? ' is-current' : ''; ?>"
                        href="<?php echo htmlspecialchars($buildUserPageUrl($pageNumber)); ?>"
                        <?php echo $pageNumber === $userCurrentPage ? 'aria-current="page"' : ''; ?>
                    ><?php echo $pageNumber; ?></a>
                    <?php endforeach; ?>
                </span>
                <?php if ($userCurrentPage < $userTotalPages): ?>
                <a class="user-pagination__step" href="<?php echo htmlspecialchars($buildUserPageUrl($userCurrentPage + 1)); ?>">Next</a>
                <?php else: ?>
                <span class="user-pagination__step is-disabled" aria-disabled="true">Next</span>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete User Modal -->
<div class="modal fade modal-modern" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-slash me-2"></i> Delete User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="deleteUserForm" data-app-loading data-loading-title="Deleting user" data-loading-text="Please wait while the account is removed.">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_user_id">
                <div class="modal-body">
                    <div class="delete-confirm-note mb-0">
                        <i class="fas fa-triangle-exclamation fa-lg"></i>
                        <div>
                            <div class="fw-semibold mb-1" id="deleteUserName">Selected user will appear here.</div>
                            <div class="small text-muted">This action cannot be undone and the account will be removed permanently.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-delete-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-delete-confirm">
                        <i class="fas fa-trash-alt me-1"></i> Yes, delete it
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade modal-modern account-create-modal" id="createUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-title-icon"><i class="fas fa-user-plus"></i></span>
                    Create New User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" autocomplete="off" novalidate data-app-loading data-loading-title="Creating user" data-loading-text="Please wait while the user account is created." data-confirm-skip="true" onsubmit="this.dataset.confirmedSubmit = '1';">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="createUsername">Username</label>
                            <input type="text" id="createUsername" name="username" class="form-control" placeholder="Enter username" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="createEmail">Email</label>
                            <input type="email" id="createEmail" name="email" class="form-control" placeholder="name@company.com" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="createPassword">Password</label>
                            <div class="password-field">
                                <input type="password" id="createPassword" name="password" class="form-control" placeholder="Create a secure password" autocomplete="new-password" required>
                                <button type="button" class="password-toggle-btn" data-password-toggle title="Show password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="createFullName">Full Name</label>
                            <input type="text" id="createFullName" name="full_name" class="form-control" placeholder="Enter full name" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="createDepartment">Department</label>
                            <select name="department" id="createDepartment" class="form-select">
                                <option value="">Select Department</option>
                                <?php foreach (getDepartmentOptions() as $department): ?>
                                    <option value="<?php echo htmlspecialchars($department); ?>">
                                        <?php echo htmlspecialchars($department); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="createRole">Role</label>
                            <select name="role" id="createRole" class="form-select" data-create-user-role required>
                                <option value="admin">Admin</option>
                                <option value="sales">Sales</option>
                                <option value="architect">Architect</option>
                                <option value="sul">Service Unit Leader</option>
                                <option value="qa">QA</option>
                                <option value="expert">Expert/Senior</option>
                                <option value="developer">Developer</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-none" data-create-developer-classification>
                            <label class="form-label" for="createDeveloperClassification">Classification</label>
                            <select name="developer_classification" id="createDeveloperClassification" class="form-select" disabled>
                                <option value="">Select classification</option>
                                <?php foreach (getDeveloperClassificationOptions() as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-secondary-action" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn-primary-modern" onclick="HTMLFormElement.prototype.submit.call(this.form);">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const userFilterForm = document.getElementById('userFilterForm');
const userSearchInput = document.getElementById('searchInput');
const userRoleFilter = document.getElementById('roleFilter');
const userStatusFilter = document.getElementById('statusFilter');
const userPageSize = document.getElementById('userPageSize');
let userSearchTimer = null;

function submitUserFilters() {
    userFilterForm?.requestSubmit();
}

userSearchInput?.addEventListener('input', () => {
    window.clearTimeout(userSearchTimer);
    userSearchTimer = window.setTimeout(submitUserFilters, 450);
});

userRoleFilter?.addEventListener('change', submitUserFilters);
userStatusFilter?.addEventListener('change', submitUserFilters);

userPageSize?.addEventListener('change', function() {
    const params = new URLSearchParams(window.location.search);
    params.set('per_page', this.value);
    params.delete('page');
    window.location.href = 'user-management.php?' + params.toString() + '#userDirectory';
});

function openDeleteUserModal(button) {
    const userId = button.getAttribute('data-user-id') || '';
    const userName = button.getAttribute('data-user-name') || 'this user';

    document.getElementById('delete_user_id').value = userId;
    document.getElementById('deleteUserName').textContent = `User: ${userName}`;

    new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}

const createUserRole = document.querySelector('[data-create-user-role]');
const createDeveloperClassification = document.querySelector('[data-create-developer-classification]');
const createDeveloperClassificationSelect = document.getElementById('createDeveloperClassification');

function toggleCreateDeveloperClassification() {
    const needsClassification = ['developer', 'qa'].includes(createUserRole?.value || '');
    createDeveloperClassification?.classList.toggle('d-none', !needsClassification);
    if (createDeveloperClassificationSelect) {
        createDeveloperClassificationSelect.disabled = !needsClassification;
        createDeveloperClassificationSelect.required = needsClassification;
        if (!needsClassification) {
            createDeveloperClassificationSelect.value = '';
        } else if (!createDeveloperClassificationSelect.value) {
            createDeveloperClassificationSelect.value = 'professional';
        }
    }
}

createUserRole?.addEventListener('change', toggleCreateDeveloperClassification);
toggleCreateDeveloperClassification();
</script>

<?php
include '../../includes/footer.php';
?>
