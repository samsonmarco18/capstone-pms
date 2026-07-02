<?php
require_once '../../includes/auth.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();
$sulDepartment = trim((string) ($user['department'] ?? ''));
$sulDepartmentEscaped = $conn->real_escape_string($sulDepartment);
$sulMissingDepartment = $sulDepartment === '';
$resourceDepartmentFilter = $sulDepartment !== '' ? " AND u.department = '{$sulDepartmentEscaped}'" : ' AND 1 = 0';
$resourceDepartmentWhere = $sulDepartment !== '' ? " AND department = '{$sulDepartmentEscaped}'" : ' AND 1 = 0';

if ($sulMissingDepartment) {
    $_SESSION['error'] = 'Your SUL account must be assigned to a department before managing resources.';
}

function sulQueryResultOrNull($conn, $sql, $label)
{
    $result = $conn->query($sql);
    if ($result === false) {
        error_log("Resource Management {$label} query failed: " . $conn->error);
        return null;
    }

    return $result;
}

function sulQueryAssocOrDefault($conn, $sql, array $default, $label)
{
    $result = $conn->query($sql);
    if ($result === false) {
        error_log("Resource Management {$label} query failed: " . $conn->error);
        return $default;
    }

    $row = $result->fetch_assoc();
    return $row ?: $default;
}

function sulResourceProjectFilter($conn, string $projectAlias, string $department): string
{
    $department = trim($department);
    if ($department === '') {
        return '1 = 0';
    }

    $escapedDepartment = $conn->real_escape_string($department);
    $clauses = [];
    if (dbTableExists($conn, 'developer_allocations')) {
        $clauses[] = "EXISTS (
            SELECT 1
            FROM developer_allocations da_scope
            WHERE da_scope.project_id = {$projectAlias}.id
              AND da_scope.role = '{$escapedDepartment}'
        )";
    }
    if (dbColumnExists($conn, 'projects', 'included_departments')) {
        $clauses[] = "{$projectAlias}.included_departments LIKE '%\"{$escapedDepartment}\"%'";
    }

    return count($clauses) > 0 ? '(' . implode(' OR ', $clauses) . ')' : '1 = 1';
}

$projectDepartmentFilter = sulResourceProjectFilter($conn, 'p', $sulDepartment);

// Get developers
$developers = sulQueryResultOrNull($conn, "
    SELECT u.*, 
           COUNT(t.id) as total_tasks,
           SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
           SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as active_tasks,
           SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END) as pending_tasks,
           SUM(CASE WHEN t.status != 'completed' AND t.deadline < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
           ROUND(AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL 
               THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) / 24 
               ELSE NULL END), 1) as avg_completion_days
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role = 'developer' AND u.status = 'active'{$resourceDepartmentFilter}
    GROUP BY u.id
    ORDER BY active_tasks DESC
", 'developers');

// Get experts
$experts = sulQueryResultOrNull($conn, "
    SELECT u.*, 
           COUNT(t.id) as total_tasks,
           SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
           SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as active_tasks,
           SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END) as pending_tasks,
           SUM(CASE WHEN t.status != 'completed' AND t.deadline < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
           ROUND(AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL 
               THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) / 24 
               ELSE NULL END), 1) as avg_completion_days
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role = 'expert' AND u.status = 'active'{$resourceDepartmentFilter}
    GROUP BY u.id
    ORDER BY active_tasks DESC
", 'experts');

// Get workload distribution by role
$workloadByRole = sulQueryResultOrNull($conn, "
    SELECT u.role, 
           COUNT(DISTINCT u.id) as member_count,
           COUNT(t.id) as total_tasks,
           SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'{$resourceDepartmentFilter}
    GROUP BY u.role
", 'workloadByRole');

// Get skill distribution
$skills = sulQueryAssocOrDefault($conn, "
    SELECT 
        SUM(CASE WHEN role = 'expert' THEN 1 ELSE 0 END) as experts,
        SUM(CASE WHEN role = 'developer' THEN 1 ELSE 0 END) as developers,
        COUNT(*) as total
    FROM users 
    WHERE role IN ('expert', 'developer') AND status = 'active'{$resourceDepartmentWhere}
", ['experts' => 0, 'developers' => 0, 'total' => 0], 'skills');

// Get available developers (not already in SUL's team)
$availableDevelopers = sulQueryResultOrNull($conn, "
    SELECT u.id, u.full_name, u.email, u.department
    FROM users u
    WHERE u.role = 'developer' AND u.status = 'active'{$resourceDepartmentFilter}
    ORDER BY u.full_name
", 'availableDevelopers');

// Get available experts (not already in SUL's team)
$availableExperts = sulQueryResultOrNull($conn, "
    SELECT u.id, u.full_name, u.email, u.department
    FROM users u
    WHERE u.role = 'expert' AND u.status = 'active'{$resourceDepartmentFilter}
    ORDER BY u.full_name
", 'availableExperts');

// Get projects with resource allocation
$projects = sulQueryResultOrNull($conn, "
    SELECT p.*, c.name as client_name,
           COUNT(DISTINCT t.id) as total_tasks,
           COUNT(DISTINCT t.assigned_to) as assigned_resources,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN tasks t ON p.id = t.project_id
    WHERE p.status != 'completed'
      AND {$projectDepartmentFilter}
    GROUP BY p.id
    ORDER BY p.created_at DESC
    LIMIT 10
", 'projects');

// Handle resource updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_allocation'])) {
        $userId = $_POST['user_id'];
        $maxTasks = $_POST['max_tasks'];
        
        $stmt = $conn->prepare("UPDATE users SET max_concurrent_tasks = ? WHERE id = ?{$resourceDepartmentWhere}");
        $stmt->bind_param("ii", $maxTasks, $userId);
        $stmt->execute();
        
        logActivity($user['id'], 'update_resource_allocation', 'users', $userId, null, $_POST);
        $_SESSION['success'] = "Resource allocation updated successfully";
        header('Location: resource-management.php');
        exit();
    }
    
    if (isset($_POST['set_availability'])) {
        $userId = $_POST['user_id'];
        $status = $_POST['availability_status'];
        
        $stmt = $conn->prepare("UPDATE users SET availability_status = ? WHERE id = ?{$resourceDepartmentWhere}");
        $stmt->bind_param("si", $status, $userId);
        $stmt->execute();
        
        logActivity($user['id'], 'update_availability', 'users', $userId, null, $_POST);
        $_SESSION['success'] = "Availability status updated successfully";
        header('Location: resource-management.php');
        exit();
    }

    if (isset($_POST['add_resource'])) {
        $userId = (int) $_POST['user_id'];
        $role = $_POST['role'];

        if (!in_array($role, ['developer', 'expert'])) {
            $_SESSION['error'] = "Invalid role selected";
        } else {
            // Check if user exists and is the correct role
            $stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE id = ? AND role = ? AND status = 'active'{$resourceDepartmentWhere}");
            $stmt->bind_param("is", $userId, $role);
            $stmt->execute();
            $existingUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$existingUser) {
                $_SESSION['error'] = "Selected user not found or not available";
            } else {
                // Check if user is already in SUL's team (you might want to add a team assignment table)
                // For now, just log the assignment
                logActivity($user['id'], 'assign_resource_to_team', 'users', $userId, null, ['assigned_by_sul' => $user['id'], 'role' => $role]);
                $_SESSION['success'] = ucfirst($role) . " " . htmlspecialchars($existingUser['full_name']) . " has been assigned to your team";
            }
        }
        header('Location: resource-management.php');
        exit();
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<?php
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
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

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card-modern {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
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
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stat-card-modern .stat-sub {
    font-size: 0.7rem;
    color: var(--gray-600);
}

/* Cards */
.card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-header h5 i {
    color: var(--theme-primary);
}

.card-body {
    padding: 1.5rem;
}

/* Table Styles */
.table-container {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--gray-50);
}

.data-table th {
    padding: 1rem 1.25rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
}

.data-table tr:hover {
    background: var(--gray-50);
}

/* Progress Bar */
.progress-modern {
    height: 8px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar-modern {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

/* Role Workload */
.role-card-body {
    display: block !important;
    text-align: left !important;
}

.role-chart {
    position: relative;
    height: 140px;
    width: 100%;
    margin-top: 0.75rem;
}

.role-breakdown {
    text-align: left;
}

/* Badges */
.badge-role-expert {
    background: #fef3c7;
    color: #d97706;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-role-developer {
    background: #dbeafe;
    color: #2563eb;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status-overloaded {
    background: #fee2e2;
    color: #dc2626;
}

.badge-status-busy {
    background: #fef3c7;
    color: #d97706;
}

.badge-status-available {
    background: #d1fae5;
    color: #059669;
}

.badge-status-free {
    background: #d1fae5;
    color: #059669;
}

/* Buttons */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-secondary-modern {
    background: #e9ecef;
    color: var(--gray-700);
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
}

.btn-secondary-modern:hover {
    background: var(--gray-300);
}

/* Keep the navbar burger neutral on this page */
#menu-toggle-desktop,
#menu-toggle-mobile {
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
}

#menu-toggle-desktop:hover,
#menu-toggle-mobile:hover,
#menu-toggle-desktop:focus,
#menu-toggle-mobile:focus {
    background: #eef7fd !important;
    box-shadow: none !important;
}

#menu-toggle-desktop:active,
#menu-toggle-mobile:active,
#menu-toggle-desktop.is-active,
#menu-toggle-mobile.is-active {
    background: transparent !important;
    box-shadow: none !important;
}

/* Tabs */
.nav-tabs {
    border-bottom: 2px solid var(--gray-200);
    margin-bottom: 0;
}

.nav-tabs .nav-link {
    border: none;
    border-bottom: 3px solid transparent;
    color: var(--gray-600);
    font-weight: 500;
    padding: 0.75rem 1.5rem;
    transition: all 0.2s;
}

.nav-tabs .nav-link:hover {
    border-bottom-color: var(--theme-light);
    color: var(--theme-primary);
}

.nav-tabs .nav-link.active {
    background: none;
    border-bottom-color: var(--theme-primary);
    color: var(--theme-primary);
}

.nav-tabs .nav-link .badge {
    font-size: 0.65rem;
    padding: 0.2rem 0.5rem;
}

/* Tab Content */
.tab-content {
    padding-top: 1rem;
}

/* List Group */
.list-group-item {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--gray-200);
    border-left: none;
    border-right: none;
    border-top: none;
}

.list-group-item:last-child {
    border-bottom: none;
}

.list-group-item:hover {
    background: var(--gray-50);
}

/* Modal */
.modal-modern .modal-content {
    border-radius: 24px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    padding: 1.5rem;
    border: none;
}

.modal-modern .modal-header .modal-title,
.modal-modern .modal-header .modal-title i {
    color: #ffffff !important;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-modern .modal-body {
    padding: 2rem;
}

.modal-modern .modal-footer {
    padding: 1rem 2rem 2rem;
    border-top: 1px solid var(--gray-200);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content {
    background: #071321 !important;
    border: 1px solid rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.16));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern h2 i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item strong {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-sub,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select {
    background-color: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .nav-tabs {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .nav-tabs .nav-link {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .nav-tabs .nav-link.active {
    background: rgba(31, 99, 146, 0.16);
    border-color: rgba(31, 99, 146, 0.24);
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-light {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

/* Chat launcher override for resource management */
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #chat-widget-minimized,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #chat-widget {
    z-index: 99999 !important;
    position: fixed !important;
    right: 28px !important;
    bottom: 28px !important;
    transform: none !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #chat-widget-minimized {
    display: flex !important;
    align-items: center;
    justify-content: center;
    width: 74px !important;
    height: 74px !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #chat-widget {
    display: none !important;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .stat-card-modern .stat-number {
        font-size: 2rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-users me-2"></i> 
                Resource Management
            </h2>
            <p>Manage team resources, workload, and availability</p>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flashSuccess): ?><div class="alert alert-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger border-0"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card-modern">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Total Resources</div>
            <div class="stat-number"><?php echo $skills['total']; ?></div>
            <div class="stat-sub"><?php echo $skills['experts']; ?> Experts | <?php echo $skills['developers']; ?> Developers</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-tasks stat-icon"></i>
            <div class="stat-label">Active Tasks</div>
            <div class="stat-number"><?php 
                $activeTotal = 0;
                if ($workloadByRole instanceof mysqli_result) {
                    $workloadByRole->data_seek(0);
                    while($row = $workloadByRole->fetch_assoc()) {
                        $activeTotal += (int) $row['active_tasks'];
                    }
                }
                echo $activeTotal;
            ?></div>
            <div class="stat-sub">Across all resources</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-clock stat-icon"></i>
            <div class="stat-label">Avg Completion Time</div>
            <div class="stat-number"><?php 
                $avgTimeRow = sulQueryAssocOrDefault($conn, "
                    SELECT AVG(TIMESTAMPDIFF(DAY, created_at, completed_at)) as avg_days 
                    FROM tasks 
                    WHERE status = 'completed' AND completed_at IS NOT NULL
                ", ['avg_days' => 0], 'avgCompletionTime');
                $avgTime = $avgTimeRow['avg_days'];
                echo round($avgTime ?? 0, 1);
            ?></div>
            <div class="stat-sub">days per task</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-exclamation-triangle stat-icon"></i>
            <div class="stat-label">Overdue Tasks</div>
            <div class="stat-number" style="color: #dc2626;"><?php 
                $overdueRow = sulQueryAssocOrDefault($conn, "
                    SELECT COUNT(*) as count FROM tasks 
                    WHERE status != 'completed' AND deadline < NOW()
                ", ['count' => 0], 'overdueTasks');
                $overdue = $overdueRow['count'];
                echo $overdue;
            ?></div>
            <div class="stat-sub">Need immediate attention</div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar me-2"></i>Resource Workload Overview</h5>
                </div>
                <div class="card-body">
                    <canvas id="workloadChart" style="height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Workload by Role</h5>
                </div>
                <div class="card-body role-card-body">
                    <div class="role-breakdown">
                        <?php if ($workloadByRole instanceof mysqli_result): ?>
                            <?php $workloadByRole->data_seek(0); ?>
                            <?php while($role = $workloadByRole->fetch_assoc()): 
                                $avgPerMember = $role['member_count'] > 0 ? round($role['active_tasks'] / $role['member_count'], 1) : 0;
                            ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><strong><?php echo ucfirst($role['role']); ?></strong> (<?php echo $role['member_count']; ?> members)</span>
                                    <span><?php echo $role['active_tasks']; ?> active tasks</span>
                                </div>
                                <div class="progress-modern">
                                    <div class="progress-bar-modern" style="width: <?php echo min(100, ($role['active_tasks'] / ($role['member_count'] * 5)) * 100); ?>%; background: var(--theme-primary);"></div>
                                </div>
                                <small class="text-muted"><?php echo $avgPerMember; ?> avg per member</small>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-muted small">No role workload data available.</div>
                        <?php endif; ?>
                    </div>
                    <div class="role-chart">
                        <canvas id="roleWorkloadChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Resource Details Table -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-list me-2"></i>Resource Details & Workload</h5>
        </div>
        <div class="card-body">
            <!-- Nav tabs -->
            <ul class="nav nav-tabs" id="resourceTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="developers-tab" data-bs-toggle="tab" data-bs-target="#developers" type="button" role="tab" aria-controls="developers" aria-selected="true">
                        <i class="fas fa-code me-2"></i>Developers
                        <span class="badge bg-primary ms-2"><?php echo $skills['developers']; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="experts-tab" data-bs-toggle="tab" data-bs-target="#experts" type="button" role="tab" aria-controls="experts" aria-selected="false">
                        <i class="fas fa-brain me-2"></i>Experts
                        <span class="badge bg-warning ms-2"><?php echo $skills['experts']; ?></span>
                    </button>
                </li>
            </ul>

            <!-- Tab content -->
            <div class="tab-content mt-4" id="resourceTabsContent">
                <!-- Developers Tab -->
                <div class="tab-pane fade show active" id="developers" role="tabpanel" aria-labelledby="developers-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Developer Resources</h6>
                        <button class="btn-primary-modern" data-bs-toggle="modal" data-bs-target="#addDeveloperModal">
                            <i class="fas fa-user-plus me-2"></i>Assign Developer
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Resource</th>
                                    <th>Role</th>
                                    <th>Active</th>
                                    <th>Completed</th>
                                    <th>Pending</th>
                                    <th>Overdue</th>
                                    <th>Avg Days</th>
                                    <th>Workload</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($developers instanceof mysqli_result): ?>
                                    <?php while($resource = $developers->fetch_assoc()): 
                                        $workloadPercent = min(100, ($resource['active_tasks'] / 5) * 100);
                                        $statusClass = $resource['active_tasks'] >= 5 ? 'overloaded' : ($resource['active_tasks'] >= 3 ? 'busy' : ($resource['active_tasks'] > 0 ? 'available' : 'free'));
                                        $statusText = $resource['active_tasks'] >= 5 ? 'Overloaded' : ($resource['active_tasks'] >= 3 ? 'Busy' : ($resource['active_tasks'] > 0 ? 'Available' : 'Free'));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($resource['full_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($resource['email']); ?></small>
                                        </td>
                                        <td><span class="badge-role-<?php echo $resource['role']; ?>"><?php echo strtoupper($resource['role']); ?></span></td>
                                        <td><?php echo $resource['active_tasks']; ?></td>
                                        <td><?php echo $resource['completed_tasks']; ?></td>
                                        <td><?php echo $resource['pending_tasks']; ?></td>
                                        <td><?php if($resource['overdue_tasks'] > 0): ?><span class="badge-status-overloaded"><?php echo $resource['overdue_tasks']; ?></span><?php else: ?>0<?php endif; ?></td>
                                        <td><?php echo $resource['avg_completion_days'] ?? 'N/A'; ?>d</td>
                                        <td>
                                            <div class="progress-modern" style="width: 100px;">
                                                <div class="progress-bar-modern" style="width: <?php echo $workloadPercent; ?>%; background: <?php echo $resource['active_tasks'] >= 5 ? '#dc2626' : ($resource['active_tasks'] >= 3 ? '#f59e0b' : '#10b981'); ?>;"></div>
                                            </div>
                                            <small><?php echo $workloadPercent; ?>%</small>
                                        </td>
                                        <td><span class="badge-status-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                        <td>
                                            <button class="btn-primary-modern btn-sm-modern" onclick="editAllocation(<?php echo $resource['id']; ?>, '<?php echo htmlspecialchars($resource['full_name']); ?>', <?php echo $resource['active_tasks']; ?>)">
                                                <i class="fas fa-sliders-h"></i>
                                            </button>
                                            <button class="btn-secondary-modern btn-sm-modern" onclick="viewResourceTasks(<?php echo $resource['id']; ?>)">
                                                <i class="fas fa-tasks"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="10" class="text-center text-muted py-4">No developer data available.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Experts Tab -->
                <div class="tab-pane fade" id="experts" role="tabpanel" aria-labelledby="experts-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Expert Resources</h6>
                        <button class="btn-primary-modern" data-bs-toggle="modal" data-bs-target="#addExpertModal">
                            <i class="fas fa-user-plus me-2"></i>Assign Expert
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Resource</th>
                                    <th>Role</th>
                                    <th>Active</th>
                                    <th>Completed</th>
                                    <th>Pending</th>
                                    <th>Overdue</th>
                                    <th>Avg Days</th>
                                    <th>Workload</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($experts instanceof mysqli_result): ?>
                                    <?php while($resource = $experts->fetch_assoc()): 
                                        $workloadPercent = min(100, ($resource['active_tasks'] / 5) * 100);
                                        $statusClass = $resource['active_tasks'] >= 5 ? 'overloaded' : ($resource['active_tasks'] >= 3 ? 'busy' : ($resource['active_tasks'] > 0 ? 'available' : 'free'));
                                        $statusText = $resource['active_tasks'] >= 5 ? 'Overloaded' : ($resource['active_tasks'] >= 3 ? 'Busy' : ($resource['active_tasks'] > 0 ? 'Available' : 'Free'));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($resource['full_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($resource['email']); ?></small>
                                        </td>
                                        <td><span class="badge-role-<?php echo $resource['role']; ?>"><?php echo strtoupper($resource['role']); ?></span></td>
                                        <td><?php echo $resource['active_tasks']; ?></td>
                                        <td><?php echo $resource['completed_tasks']; ?></td>
                                        <td><?php echo $resource['pending_tasks']; ?></td>
                                        <td><?php if($resource['overdue_tasks'] > 0): ?><span class="badge-status-overloaded"><?php echo $resource['overdue_tasks']; ?></span><?php else: ?>0<?php endif; ?></td>
                                        <td><?php echo $resource['avg_completion_days'] ?? 'N/A'; ?>d</td>
                                        <td>
                                            <div class="progress-modern" style="width: 100px;">
                                                <div class="progress-bar-modern" style="width: <?php echo $workloadPercent; ?>%; background: <?php echo $resource['active_tasks'] >= 5 ? '#dc2626' : ($resource['active_tasks'] >= 3 ? '#f59e0b' : '#10b981'); ?>;"></div>
                                            </div>
                                            <small><?php echo $workloadPercent; ?>%</small>
                                        </td>
                                        <td><span class="badge-status-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                        <td>
                                            <button class="btn-primary-modern btn-sm-modern" onclick="editAllocation(<?php echo $resource['id']; ?>, '<?php echo htmlspecialchars($resource['full_name']); ?>', <?php echo $resource['active_tasks']; ?>)">
                                                <i class="fas fa-sliders-h"></i>
                                            </button>
                                            <button class="btn-secondary-modern btn-sm-modern" onclick="viewResourceTasks(<?php echo $resource['id']; ?>)">
                                                <i class="fas fa-tasks"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="10" class="text-center text-muted py-4">No expert data available.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-project-diagram me-2"></i>Projects with Active Resources</h5>
                </div>
                <div class="card-body p-0">
                    <?php if ($projects instanceof mysqli_result): ?>
                    <?php while($project = $projects->fetch_assoc()): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($project['name']); ?></h6>
                                <small class="text-muted">Client: <?php echo htmlspecialchars($project['client_name']); ?></small>
                                <div class="mt-1">
                                    <small><i class="fas fa-users me-1"></i> <?php echo $project['assigned_resources']; ?> resources</small>
                                    <small class="ms-2"><i class="fas fa-tasks me-1"></i> <?php echo $project['active_tasks']; ?> active tasks</small>
                                </div>
                            </div>
                            <a href="task-allocation.php?project_id=<?php echo $project['id']; ?>" class="btn-primary-modern">
                                <i class="fas fa-chart-line"></i> Manage
                            </a>
                        </div>
                        <?php 
                        $completionPercent = $project['total_tasks'] > 0 ? round(($project['total_tasks'] - $project['active_tasks']) / $project['total_tasks'] * 100) : 0;
                        ?>
                        <div class="mt-2">
                            <div class="progress-modern">
                                <div class="progress-bar-modern" style="width: <?php echo $completionPercent; ?>%; background: #10b981;"></div>
                            </div>
                            <small class="text-muted"><?php echo $completionPercent; ?>% Complete</small>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <div class="list-group-item text-muted py-4 text-center">No project data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Resource Utilization</h5>
                </div>
                <div class="card-body">
                    <canvas id="utilizationChart" style="height: 220px; width: 100%;"></canvas>
                    <div class="mt-3 p-3 bg-light rounded-3">
                        <i class="fas fa-info-circle text-primary me-2"></i>
                        <strong>Utilization Rate:</strong> 
                        <?php 
                        $totalResources = (int) $skills['total'];
                        $overloadedRow = sulQueryAssocOrDefault($conn, "
                            SELECT COUNT(*) as count FROM (
                                SELECT u.id, COUNT(t.id) as task_count
                                FROM users u
                                LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
                                WHERE u.role IN ('expert', 'developer')
                                GROUP BY u.id
                                HAVING task_count >= 5
                            ) as overloaded
                        ", ['count' => 0], 'overloadedResources');
                        $overloaded = (int) $overloadedRow['count'];
                        
                        $utilizationRate = $totalResources > 0 ? round(($overloaded / $totalResources) * 100) : 0;
                        ?>
                        <strong><?php echo $overloaded; ?></strong> out of <strong><?php echo $totalResources; ?></strong> resources are overloaded (<?php echo $utilizationRate; ?>%)
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Developer Modal -->
<div class="modal fade modal-modern" id="addDeveloperModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-code me-2"></i>Assign Developer to Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="role" value="developer">
                    <div class="mb-3">
                        <label class="form-label">Select Developer</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">Choose a developer...</option>
                            <?php if ($availableDevelopers instanceof mysqli_result): ?>
                                <?php $availableDevelopers->data_seek(0); while($dev = $availableDevelopers->fetch_assoc()): ?>
                                <option value="<?php echo $dev['id']; ?>">
                                    <?php echo htmlspecialchars($dev['full_name']); ?> 
                                    (<?php echo htmlspecialchars($dev['email']); ?>)
                                    <?php if($dev['department']): ?> - <?php echo htmlspecialchars($dev['department']); ?><?php endif; ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            </select>
                        <small class="text-muted">Only developers created by admin are available for assignment</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_resource" class="btn-primary-modern">Assign Developer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Expert Modal -->
<div class="modal fade modal-modern" id="addExpertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-brain me-2"></i>Assign Expert to Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="role" value="expert">
                    <div class="mb-3">
                        <label class="form-label">Select Expert</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">Choose an expert...</option>
                            <?php if ($availableExperts instanceof mysqli_result): ?>
                                <?php $availableExperts->data_seek(0); while($exp = $availableExperts->fetch_assoc()): ?>
                                <option value="<?php echo $exp['id']; ?>">
                                    <?php echo htmlspecialchars($exp['full_name']); ?> 
                                    (<?php echo htmlspecialchars($exp['email']); ?>)
                                    <?php if($exp['department']): ?> - <?php echo htmlspecialchars($exp['department']); ?><?php endif; ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            </select>
                        <small class="text-muted">Only experts created by admin are available for assignment</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_resource" class="btn-primary-modern">Assign Expert</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Allocation Modal -->
<div class="modal fade modal-modern" id="editAllocationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sliders-h me-2"></i>Edit Resource Allocation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="alloc_user_id">
                    <p><strong>Resource:</strong> <span id="alloc_user_name"></span></p>
                    <p><strong>Current Active Tasks:</strong> <span id="alloc_current_tasks"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Maximum Concurrent Tasks</label>
                        <input type="number" name="max_tasks" id="alloc_max_tasks" class="form-control" min="1" max="10" required>
                        <small class="text-muted">Recommended: 3-5 tasks per resource</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Availability Status</label>
                        <select name="availability_status" id="alloc_status" class="form-select">
                            <option value="available">Available</option>
                            <option value="busy">Busy</option>
                            <option value="on_leave">On Leave</option>
                            <option value="sick">Sick Leave</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_allocation" class="btn-primary-modern">Update Allocation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Resource Tasks Modal -->
<div class="modal fade modal-modern" id="resourceTasksModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-tasks me-2"></i>Resource Tasks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="resourceTasksContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Workload Chart
const workloadData = <?php 
    $names = [];
    $active = [];
    $completed = [];
    if ($developers instanceof mysqli_result) {
        $developers->data_seek(0);
        while($row = $developers->fetch_assoc()) {
            $names[] = $row['full_name'] . ' (Dev)';
            $active[] = $row['active_tasks'];
            $completed[] = $row['completed_tasks'];
        }
    }
    if ($experts instanceof mysqli_result) {
        $experts->data_seek(0);
        while($row = $experts->fetch_assoc()) {
            $names[] = $row['full_name'] . ' (Exp)';
            $active[] = $row['active_tasks'];
            $completed[] = $row['completed_tasks'];
        }
    }
    echo json_encode(['names' => $names, 'active' => $active, 'completed' => $completed]);
?>;

const workloadCtx = document.getElementById('workloadChart').getContext('2d');
new Chart(workloadCtx, {
    type: 'bar',
    data: {
        labels: workloadData.names,
        datasets: [
            { label: 'Active Tasks', data: workloadData.active, backgroundColor: '#f59e0b', borderRadius: 8 },
            { label: 'Completed Tasks', data: workloadData.completed, backgroundColor: '#10b981', borderRadius: 8 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Tasks' } } },
        plugins: { legend: { position: 'top' } }
    }
});

// Role Workload Chart
const roleData = <?php 
    $roles = [];
    $activeTasks = [];
    if ($workloadByRole instanceof mysqli_result) {
        $workloadByRole->data_seek(0);
        while($row = $workloadByRole->fetch_assoc()) {
            $roles[] = ucfirst($row['role']);
            $activeTasks[] = $row['active_tasks'];
        }
    }
    echo json_encode(['roles' => $roles, 'active' => $activeTasks]);
?>;

const roleCtx = document.getElementById('roleWorkloadChart').getContext('2d');
new Chart(roleCtx, {
    type: 'pie',
    data: {
        labels: roleData.roles,
        datasets: [{ data: roleData.active, backgroundColor: ['#1f6392', '#3a7ca3'], borderWidth: 0 }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } }
    }
});

// Utilization Chart
const utilCtx = document.getElementById('utilizationChart').getContext('2d');
new Chart(utilCtx, {
    type: 'doughnut',
    data: {
        labels: ['Underutilized (0-2 tasks)', 'Optimal (3-4 tasks)', 'Overloaded (5+ tasks)'],
        datasets: [{
            data: [
                <?php 
                    $underutilizedRow = sulQueryAssocOrDefault($conn, "SELECT COUNT(*) as count FROM (SELECT u.id, COUNT(t.id) as task_count FROM users u LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed' WHERE u.role IN ('expert', 'developer') GROUP BY u.id HAVING task_count <= 2) as under", ['count' => 0], 'underutilizedResources');
                    $underutilized = $underutilizedRow['count'];
                    echo $underutilized;
                ?>,
                <?php 
                    $optimalRow = sulQueryAssocOrDefault($conn, "SELECT COUNT(*) as count FROM (SELECT u.id, COUNT(t.id) as task_count FROM users u LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed' WHERE u.role IN ('expert', 'developer') GROUP BY u.id HAVING task_count BETWEEN 3 AND 4) as opt", ['count' => 0], 'optimalResources');
                    $optimal = $optimalRow['count'];
                    echo $optimal;
                ?>,
                <?php 
                    $overloadedRow = sulQueryAssocOrDefault($conn, "SELECT COUNT(*) as count FROM (SELECT u.id, COUNT(t.id) as task_count FROM users u LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed' WHERE u.role IN ('expert', 'developer') GROUP BY u.id HAVING task_count >= 5) as over", ['count' => 0], 'overloadedResourcesChart');
                    $overloaded = $overloadedRow['count'];
                    echo $overloaded;
                ?>
            ],
            backgroundColor: ['#10b981', '#f59e0b', '#dc2626'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        cutout: '60%',
        plugins: { legend: { position: 'bottom' } }
    }
});

function editAllocation(userId, userName, currentTasks) {
    document.getElementById('alloc_user_id').value = userId;
    document.getElementById('alloc_user_name').textContent = userName;
    document.getElementById('alloc_current_tasks').textContent = currentTasks;
    document.getElementById('alloc_max_tasks').value = Math.max(5, currentTasks + 1);
    new bootstrap.Modal(document.getElementById('editAllocationModal')).show();
}

function viewResourceTasks(userId) {
    $.ajax({
        url: '../../api/get-resource-tasks.php',
        method: 'GET',
        data: { user_id: userId },
        success: function(response) {
            const tasks = JSON.parse(response);
            let html = '<div class="table-responsive"><table class="data-table">';
            html += '<thead><tr><th>Task</th><th>Project</th><th>Priority</th><th>Status</th><th>Deadline</th><th>Actions</th></tr></thead><tbody>';
            tasks.forEach(task => {
                html += `
                    <tr>
                        <td><div class="fw-semibold">${escapeHtml(task.title)}</div><small>${escapeHtml(task.description.substring(0, 60))}</small></td>
                        <td>${escapeHtml(task.project_name)}</td>
                        <td><span class="badge-priority-${task.priority}">${task.priority}</span></td>
                        <td><span class="badge-status-${task.status}">${task.status}</span></td>
                        <td>${new Date(task.deadline).toLocaleDateString()}</td>
                        <td><button class="btn-primary-modern btn-sm-modern" onclick="viewTaskDetail(${task.id})"><i class="fas fa-eye"></i></button></td>
                    </tr>
                `;
            });
            html += '</tbody></table></div>';
            if(tasks.length === 0) html = '<p class="text-center text-muted py-4">No tasks assigned to this resource</p>';
            $('#resourceTasksContent').html(html);
            new bootstrap.Modal(document.getElementById('resourceTasksModal')).show();
        }
    });
}

function viewTaskDetail(taskId) {
    window.location.href = '../../modules/developer/task.php?id=' + taskId;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include '../../includes/footer.php'; ?>
