<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
requireRole('admin');

$conn = getConnection();
workflowEnsureSchema($conn);
$user = getCurrentUser();

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'create') {
        $username = $_POST['username'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $full_name = $_POST['full_name'];
        $department = $_POST['department'];
        $role = $_POST['role'];
        $hasPasswordChangedAt = ensureUserPasswordSecurityColumns();
        
        $sql = $hasPasswordChangedAt
            ? "INSERT INTO users (username, email, password, full_name, department, role, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
            : "INSERT INTO users (username, email, password, full_name, department, role) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssss", $username, $email, $password, $full_name, $department, $role);
        
        if ($stmt->execute()) {
            $userId = $stmt->insert_id;
            logActivity($user['id'], 'create_user', 'users', $userId, null, $_POST);
            $_SESSION['success'] = "User created successfully";
        } else {
            $_SESSION['error'] = "Error creating user: " . $conn->error;
        }
    } elseif ($_POST['action'] == 'update') {
        $id = $_POST['id'];
        $full_name = $_POST['full_name'];
        $department = $_POST['department'];
        $role = $_POST['role'];
        $status = $_POST['status'];
        
        $oldData = $conn->query("SELECT * FROM users WHERE id = $id")->fetch_assoc();
        
        $stmt = $conn->prepare("UPDATE users SET full_name=?, department=?, role=?, status=? WHERE id=?");
        $stmt->bind_param("ssssi", $full_name, $department, $role, $status, $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'update_user', 'users', $id, $oldData, $_POST);
            $_SESSION['success'] = "User updated successfully";
        } else {
            $_SESSION['error'] = "Error updating user";
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
$search = isset($_GET['search']) ? $_GET['search'] : '';
$roleFilter = isset($_GET['role']) ? $_GET['role'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';

$query = "SELECT * FROM users WHERE 1=1";
if ($search) {
    $query .= " AND (username LIKE '%$search%' OR email LIKE '%$search%' OR full_name LIKE '%$search%')";
}
if ($roleFilter) {
    $query .= " AND role = '$roleFilter'";
}
if ($statusFilter) {
    $query .= " AND status = '$statusFilter'";
}
$query .= " ORDER BY created_at DESC";

$users = $conn->query($query);

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

/* Filter Section */
.filter-section {
    padding: 1.5rem 2rem;
    background: var(--panel-surface-alt);
    border-bottom: 1px solid var(--panel-border);
}

.filter-group {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-input {
    flex: 1;
    min-width: 200px;
}

.filter-input label {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--panel-muted);
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
    display: block;
}

.filter-input input,
.filter-input select {
    width: 100%;
    padding: 0.6rem 1rem;
    border: 1px solid var(--panel-border);
    border-radius: 12px;
    font-size: 0.85rem;
    transition: all 0.2s;
    background: var(--panel-input-bg);
    color: var(--panel-input-text);
}

.filter-input input:focus,
.filter-input select:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.btn-apply {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 12px;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-apply:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
}

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

.user-table {
    width: 100%;
    border-collapse: collapse;
}

.user-table thead {
    background: var(--panel-surface-alt);
}

.user-table th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--panel-muted);
    border-bottom: 1px solid var(--panel-border);
}

.user-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--panel-border);
}

.user-table tr:hover {
    background: var(--panel-row-hover);
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
}

.badge-role.admin {
    background: rgba(220, 38, 38, 0.15);
    color: #ff9ca0;
}

.badge-role.sales {
    background: rgba(37, 99, 235, 0.16);
    color: #8fc1ff;
}

.badge-role.architect {
    background: rgba(79, 70, 229, 0.16);
    color: #b7b8ff;
}

.badge-role.sul {
    background: rgba(217, 119, 6, 0.16);
    color: #ffd58a;
}

.badge-role.qa {
    background: rgba(14, 116, 144, 0.16);
    color: #8de7ff;
}

.badge-role.expert {
    background: rgba(5, 150, 105, 0.16);
    color: #94f1c6;
}

.badge-role.developer {
    background: rgba(73, 80, 87, 0.16);
    color: #d8e6f3;
}

.badge-status {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status.active {
    background: rgba(5, 150, 105, 0.16);
    color: #94f1c6;
}

.badge-status.inactive {
    background: rgba(217, 119, 6, 0.16);
    color: #ffd58a;
}

.badge-status.suspended {
    background: rgba(220, 38, 38, 0.15);
    color: #ff9ca0;
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
    background: rgba(220, 38, 38, 0.15);
    color: #ff9ca0;
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
    border-radius: 24px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    padding: 1.5rem;
    border: none;
}

.modal-modern .modal-header .modal-title {
    color: white;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-modern .modal-body {
    padding: 2rem;
}

.modal-modern .modal-footer {
    padding: 1rem 2rem 2rem;
    border-top: 1px solid var(--panel-border);
}

.modal-modern .form-label {
    font-weight: 500;
    color: var(--panel-text);
    margin-bottom: 0.5rem;
}


.modal-modern .form-control,
.modal-modern .form-select {
    border-radius: 12px;
    border: 1px solid var(--panel-border);
    padding: 0.6rem 1rem;
    background: var(--panel-input-bg);
    color: var(--panel-input-text);
}

.modal-modern .form-control:focus,
.modal-modern .form-select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer {
    background: var(--panel-surface-alt);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-title {
    color: var(--panel-text) !important;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-select {
    background: var(--panel-input-bg);
    border-color: var(--panel-border);
    color: var(--panel-input-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input input:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select:focus,
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
        flex-direction: column;
    }
    
    .filter-input {
        width: 100%;
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
            <div class="mt-3 mt-md-0">
                <button class="btn-add-modern" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-plus me-2"></i> Add New User
                </button>
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
    <div class="main-card">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list me-2" style="color: var(--theme-primary);"></i>
                User Directory
            </h5>
            <div>
                <span class="text-muted small">Showing <?php echo $users->num_rows; ?> users</span>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-group">
                <div class="filter-input">
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Name, email, or username..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-input">
                    <label>Role</label>
                    <select id="roleFilter">
                        <option value="">All Roles</option>
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
                    <label>Status</label>
                    <select id="statusFilter">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo $statusFilter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>
                <div>
                    <button class="btn-apply" onclick="applyFilters()">Apply Filters</button>
                </div>
            </div>
        </div>
        
        <!-- Users Table -->
        <div class="table-container">
            <?php if($users->num_rows > 0): ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $users->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="user-name"><?php echo htmlspecialchars($row['full_name']); ?></div>
                            <div class="user-email"><?php echo htmlspecialchars($row['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($row['department'] ?: '—'); ?></td>
                        <td>
                            <span class="badge-role <?php echo $row['role']; ?>">
                                <?php echo strtoupper($row['role']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge-status <?php echo $row['status']; ?>">
                                <?php echo ucfirst($row['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon view" onclick="viewUser(<?php echo $row['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-icon edit" onclick="editUser(<?php echo $row['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-icon delete" onclick="deleteUser(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>')">
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
                <button class="btn-add-modern mt-2" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-plus me-2"></i> Add Your First User
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade modal-modern" id="createUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i> Create New User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select">
                                <option value="">Select Department</option>
                                <?php foreach (getDepartmentOptions() as $department): ?>
                                    <option value="<?php echo htmlspecialchars($department); ?>">
                                        <?php echo htmlspecialchars($department); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select" required>
                                <option value="sales">Sales</option>
                                <option value="architect">Architect</option>
                                <option value="sul">Service Unit Leader</option>
                                <option value="qa">QA</option>
                                <option value="expert">Expert/Senior</option>
                                <option value="developer">Developer</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-modern">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const role = document.getElementById('roleFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    let url = 'user-management.php?';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    if (role) url += 'role=' + encodeURIComponent(role) + '&';
    if (status) url += 'status=' + encodeURIComponent(status);
    
    window.location.href = url;
}

function viewUser(id) {
    window.location.href = 'view-user.php?id=' + id;
}

function editUser(id) {
    window.location.href = 'edit-user.php?id=' + id;
}

function deleteUser(id, name) {
    if (confirm('Are you sure you want to delete user: ' + name + '? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-submit on enter in search
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        applyFilters();
    }
});
</script>

<?php
include '../../includes/footer.php';
?>
