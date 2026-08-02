<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-workflow.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();
$sulDepartment = projectWorkflowDepartmentLabel((string) ($user['department'] ?? ''));
$sulMissingDepartment = $sulDepartment === '';
$resourceDepartmentFilter = ' AND ' . projectWorkflowUserDepartmentClause($conn, 'u', $sulDepartment);
$resourceDepartmentWhere = ' AND ' . projectWorkflowUserDepartmentClause($conn, 'users', $sulDepartment);
$assignedTaskDepartmentFilter = projectWorkflowTaskDepartmentClause($conn, 't', $sulDepartment, true);
$taskProjectDepartmentFilter = projectWorkflowProjectDepartmentClause($conn, 'p_task_scope', $sulDepartment);
$developerTaskJoinFilter = "
    AND {$assignedTaskDepartmentFilter}
    AND EXISTS (
        SELECT 1
        FROM projects p_task_scope
        WHERE p_task_scope.id = t.project_id
          AND {$taskProjectDepartmentFilter}
    )";

if ($sulMissingDepartment) {
    $_SESSION['error'] = 'Your SUL account must be assigned to a department before managing resources.';
}

function sulQueryRows($conn, $sql, $label): array
{
    $result = $conn->query($sql);
    if ($result === false) {
        error_log("Resource Management {$label} query failed: " . $conn->error);
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

// Get developers
$developers = sulQueryRows($conn, "
    SELECT u.*, 
           COUNT(t.id) as total_tasks,
           SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks,
           SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END) as pending_tasks,
           SUM(CASE WHEN t.status != 'completed' AND t.deadline < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
           AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL
               THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) / 24 
               ELSE NULL END) as avg_completion_days
    FROM users u
     LEFT JOIN tasks t ON u.id = t.assigned_to{$developerTaskJoinFilter}
     WHERE u.role = 'developer' AND u.status = 'active'{$resourceDepartmentFilter}
    GROUP BY u.id
    ORDER BY active_tasks DESC
", 'developers');

// Get available developers (not already in SUL's team)
$availableDevelopers = sulQueryRows($conn, "
    SELECT u.id, u.full_name, u.email, u.department,
           COALESCE(u.availability_status, 'available') AS availability_status,
           COALESCE(u.max_concurrent_tasks, 5) AS max_concurrent_tasks
    FROM users u
    WHERE u.role = 'developer' AND u.status = 'active'{$resourceDepartmentFilter}
    ORDER BY u.full_name
", 'availableDevelopers');

$reallocationTaskDepartmentFilter = projectWorkflowTaskDepartmentClause($conn, 't', $sulDepartment, true);
$reallocationProjectDepartmentFilter = projectWorkflowProjectDepartmentClause($conn, 'p', $sulDepartment);
$reallocationUserDepartmentFilter = projectWorkflowUserDepartmentClause($conn, 'source_user', $sulDepartment);
$reallocationTasks = sulQueryRows($conn, "
    SELECT t.id, t.assigned_to, t.title, t.status, t.priority, t.deadline,
           p.name AS project_name
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
    INNER JOIN users source_user ON source_user.id = t.assigned_to
    WHERE source_user.role = 'developer'
      AND source_user.status = 'active'
      AND {$reallocationUserDepartmentFilter}
      AND {$reallocationTaskDepartmentFilter}
      AND {$reallocationProjectDepartmentFilter}
      AND t.status NOT IN ('completed', 'merged', 'ready_for_release')
    ORDER BY source_user.full_name, p.name, t.priority DESC, t.deadline ASC, t.id ASC
", 'reallocationTasks');

// Handle resource updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($sulMissingDepartment) {
        header('Location: resource-management.php');
        exit();
    }

    if (isset($_POST['update_allocation'])) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $maxTasks = max(1, min(10, (int) ($_POST['max_tasks'] ?? 5)));

        $stmt = $conn->prepare("UPDATE users SET max_concurrent_tasks = ? WHERE id = ? AND role = 'developer'{$resourceDepartmentWhere}");
        $stmt->bind_param("ii", $maxTasks, $userId);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            logActivity($user['id'], 'update_resource_allocation', 'users', $userId, null, $_POST);
            $_SESSION['success'] = "Resource allocation updated successfully";
        } else {
            $_SESSION['error'] = "Developer not found in your department or no changes were made";
        }
        $stmt->close();
        header('Location: resource-management.php');
        exit();
    }
    
    if (isset($_POST['set_availability'])) {
        $userId = $_POST['user_id'];
        $status = $_POST['availability_status'];
        
        $stmt = $conn->prepare("UPDATE users SET availability_status = ? WHERE id = ? AND role = 'developer'{$resourceDepartmentWhere}");
        $stmt->bind_param("si", $status, $userId);
        $stmt->execute();
        
        logActivity($user['id'], 'update_availability', 'users', $userId, null, $_POST);
        $_SESSION['success'] = "Availability status updated successfully";
        header('Location: resource-management.php');
        exit();
    }

    if (isset($_POST['reallocate_resources'])) {
        $sourceDeveloperId = (int) ($_POST['source_developer_id'] ?? 0);
        $destinationDeveloperId = (int) ($_POST['destination_developer_id'] ?? 0);
        $taskIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['task_ids'] ?? [])),
            static fn(int $taskId): bool => $taskId > 0
        )));

        try {
            if ($sourceDeveloperId <= 0 || $destinationDeveloperId <= 0 || $sourceDeveloperId === $destinationDeveloperId) {
                throw new RuntimeException('Choose different source and destination developers.');
            }
            if ($taskIds === []) {
                throw new RuntimeException('Select at least one task to reallocate.');
            }

            $developerStmt = $conn->prepare(
                "SELECT id, full_name, COALESCE(availability_status, 'available') AS availability_status,
                        COALESCE(max_concurrent_tasks, 5) AS max_concurrent_tasks
                 FROM users
                 WHERE id IN (?, ?) AND role = 'developer' AND status = 'active'{$resourceDepartmentWhere}"
            );
            $developerStmt->bind_param('ii', $sourceDeveloperId, $destinationDeveloperId);
            $developerStmt->execute();
            $developerRows = $developerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $developerStmt->close();
            $developersById = [];
            foreach ($developerRows as $developerRow) {
                $developersById[(int) $developerRow['id']] = $developerRow;
            }
            $sourceDeveloper = $developersById[$sourceDeveloperId] ?? null;
            $destinationDeveloper = $developersById[$destinationDeveloperId] ?? null;
            if (!$sourceDeveloper || !$destinationDeveloper) {
                throw new RuntimeException('Both developers must be active members of your department.');
            }
            if (in_array((string) $destinationDeveloper['availability_status'], ['on_leave', 'sick', 'unavailable'], true)) {
                throw new RuntimeException('The destination developer is currently unavailable.');
            }

            $taskIdList = implode(',', $taskIds);
            $taskScopeUserFilter = projectWorkflowUserDepartmentClause($conn, 'source_user', $sulDepartment);
            $taskRows = sulQueryRows($conn, "
                SELECT t.id, t.title
                FROM tasks t
                INNER JOIN projects p ON p.id = t.project_id
                INNER JOIN users source_user ON source_user.id = t.assigned_to
                WHERE t.id IN ({$taskIdList})
                  AND t.assigned_to = {$sourceDeveloperId}
                  AND t.status NOT IN ('completed', 'merged', 'ready_for_release')
                  AND {$taskScopeUserFilter}
                  AND {$reallocationTaskDepartmentFilter}
                  AND {$reallocationProjectDepartmentFilter}
            ", 'reallocationValidation');
            if (count($taskRows) !== count($taskIds)) {
                throw new RuntimeException('One or more selected tasks are no longer eligible for reallocation.');
            }

            $capacityStmt = $conn->prepare(
                "SELECT COUNT(*) AS active_tasks
                 FROM tasks
                 WHERE assigned_to = ? AND status NOT IN ('completed', 'merged', 'ready_for_release')"
            );
            $capacityStmt->bind_param('i', $destinationDeveloperId);
            $capacityStmt->execute();
            $destinationActiveTasks = (int) ($capacityStmt->get_result()->fetch_assoc()['active_tasks'] ?? 0);
            $capacityStmt->close();
            $destinationCapacity = max(1, (int) $destinationDeveloper['max_concurrent_tasks']);
            if ($destinationActiveTasks + count($taskIds) > $destinationCapacity) {
                throw new RuntimeException(
                    $destinationDeveloper['full_name'] . ' only has capacity for '
                    . max(0, $destinationCapacity - $destinationActiveTasks) . ' additional task(s).'
                );
            }

            $conn->begin_transaction();
            try {
                $updateTaskStmt = $conn->prepare(
                    'UPDATE tasks SET assigned_to = ?, assigned_by = ?, updated_at = NOW()
                     WHERE id = ? AND assigned_to = ?'
                );
                foreach ($taskIds as $taskId) {
                    $updateTaskStmt->bind_param(
                        'iiii',
                        $destinationDeveloperId,
                        $user['id'],
                        $taskId,
                        $sourceDeveloperId
                    );
                    $updateTaskStmt->execute();
                    if ($conn->affected_rows !== 1) {
                        throw new RuntimeException('A selected task changed while reallocation was being saved.');
                    }
                }
                $updateTaskStmt->close();

                if (dbTableExists($conn, 'methodology_tasks') && dbColumnExists($conn, 'methodology_tasks', 'assigned_developer_id')) {
                    $roadmapStmt = $conn->prepare(
                        'UPDATE methodology_tasks
                         SET assigned_developer_id = ?, updated_by = ?, updated_at = NOW()
                         WHERE task_id = ?'
                    );
                    foreach ($taskIds as $taskId) {
                        $roadmapStmt->bind_param('iii', $destinationDeveloperId, $user['id'], $taskId);
                        $roadmapStmt->execute();
                    }
                    $roadmapStmt->close();
                }

                if (
                    dbTableExists($conn, 'methodology_task_departments')
                    && dbColumnExists($conn, 'methodology_task_departments', 'assigned_developer_id')
                    && dbColumnExists($conn, 'methodology_task_departments', 'workflow_task_id')
                ) {
                    $departmentRoadmapStmt = $conn->prepare(
                        'UPDATE methodology_task_departments
                         SET assigned_developer_id = ?, updated_by = ?, updated_at = NOW()
                         WHERE workflow_task_id = ?'
                    );
                    foreach ($taskIds as $taskId) {
                        $departmentRoadmapStmt->bind_param('iii', $destinationDeveloperId, $user['id'], $taskId);
                        $departmentRoadmapStmt->execute();
                    }
                    $departmentRoadmapStmt->close();
                }

                logActivity(
                    $user['id'],
                    'reallocate_developer_tasks',
                    'tasks',
                    $taskIds[0],
                    ['assigned_to' => $sourceDeveloperId],
                    [
                        'assigned_to' => $destinationDeveloperId,
                        'task_ids' => $taskIds,
                        'source_developer' => $sourceDeveloper['full_name'],
                        'destination_developer' => $destinationDeveloper['full_name'],
                    ]
                );
                $conn->commit();
            } catch (Throwable $transactionError) {
                $conn->rollback();
                throw $transactionError;
            }

            $_SESSION['success'] = count($taskIds) . ' task(s) reallocated from '
                . $sourceDeveloper['full_name'] . ' to ' . $destinationDeveloper['full_name'] . '.';
        } catch (Throwable $reallocationError) {
            $_SESSION['error'] = $reallocationError->getMessage();
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

.resource-workload-card .card-body {
    padding: 0;
}

.resource-workload-card {
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(30, 64, 95, 0.06);
}

.resource-workload-header {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    padding: 1rem 1.25rem;
}

.resource-workload-heading {
    align-items: center;
    display: flex;
    gap: 0.75rem;
    min-width: 0;
}

.resource-workload-heading-icon {
    align-items: center;
    background: #eaf4fb;
    border-radius: 9px;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 36px;
    height: 36px;
    justify-content: center;
    width: 36px;
}

.resource-workload-heading h5 {
    margin: 0;
}

.resource-workload-heading small {
    color: var(--gray-600);
    display: block;
    font-size: 0.7rem;
    margin-top: 0.12rem;
}

.resource-workload-header .btn-primary-modern {
    flex: 0 0 auto;
    min-height: 38px;
    padding: 0.55rem 0.85rem;
}

.resource-workload-header .resource-reallocation-btn {
    color: #fff !important;
}

.resource-workload-header .resource-reallocation-icon {
    display: block;
    flex: 0 0 14px;
    height: 14px;
    stroke: #fff;
    width: 14px;
}

.resource-workload-card .table-container {
    border-top: 1px solid var(--gray-200);
}

.resource-workload-table thead {
    background: #fafbfd;
}

.resource-workload-table tbody {
    background: #ffffff;
}

.resource-workload-table th {
    font-size: 0.68rem;
    letter-spacing: 0.035em;
    white-space: nowrap;
}

.resource-workload-table td {
    color: #334e68;
    height: 66px;
}

.resource-workload-table th,
.resource-workload-table td,
.resource-workload-card .table-container {
    border-bottom: 1px solid var(--gray-200);
}

.resource-workload-table tbody tr:last-child td {
    border-bottom: 0;
}

.resource-workload-table {
    min-width: 1160px;
    table-layout: fixed;
}

.resource-workload-table th,
.resource-workload-table td {
    padding: 0.7rem 0.75rem;
}

.resource-workload-table th:first-child,
.resource-workload-table td:first-child {
    padding-left: 1.25rem;
    width: 26%;
}

.resource-workload-table th:nth-child(n+2):nth-child(-n+6),
.resource-workload-table td:nth-child(n+2):nth-child(-n+6) {
    text-align: center;
}

.resource-workload-table th:nth-child(2),
.resource-workload-table td:nth-child(2) { width: 6.5%; }
.resource-workload-table th:nth-child(3),
.resource-workload-table td:nth-child(3) { width: 9%; }
.resource-workload-table th:nth-child(4),
.resource-workload-table td:nth-child(4) { width: 7%; }
.resource-workload-table th:nth-child(5),
.resource-workload-table td:nth-child(5) { width: 7%; }
.resource-workload-table th:nth-child(6),
.resource-workload-table td:nth-child(6) { width: 7.5%; }

.resource-workload-table th:nth-child(7),
.resource-workload-table td:nth-child(7) {
    width: 14%;
}

.resource-workload-table th:nth-child(8),
.resource-workload-table td:nth-child(8) {
    width: 10%;
}

.resource-workload-table th:last-child,
.resource-workload-table td:last-child {
    padding-right: 1.25rem;
    text-align: right;
    width: 11%;
}

.resource-workload-table tbody tr {
    box-shadow: inset 3px 0 0 transparent;
    transition: background-color 0.18s ease, box-shadow 0.18s ease;
}

.resource-workload-table tbody tr:hover {
    background: #f7fafc;
    box-shadow: inset 2px 0 0 var(--theme-primary);
}

.resource-profile {
    align-items: center;
    display: flex;
    gap: 0.75rem;
    min-width: 0;
}

.resource-profile-avatar {
    align-items: center;
    background: linear-gradient(145deg, #dff1fb 0%, #c8e4f4 100%);
    border: 1px solid #bedcec;
    border-radius: 50%;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 36px;
    font-size: 0.7rem;
    font-weight: 700;
    height: 36px;
    justify-content: center;
    letter-spacing: 0.03em;
    width: 36px;
}

.resource-profile-copy {
    min-width: 0;
}

.resource-profile-name {
    color: var(--gray-900);
    font-size: 0.84rem;
    font-weight: 600;
    line-height: 1.3;
    margin: 0;
}

.resource-profile-email {
    color: var(--gray-600);
    display: block;
    font-size: 0.68rem;
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.resource-metric {
    color: #334e68;
    font-size: 0.84rem;
    font-variant-numeric: tabular-nums;
    font-weight: 500;
}

.resource-workload {
    min-width: 110px;
}

.resource-workload .progress-modern {
    width: 100%;
}

.resource-workload-value {
    color: var(--gray-600);
    display: block;
    font-size: 0.68rem;
    margin-top: 0.3rem;
}

.badge-status-overloaded,
.badge-status-busy,
.badge-status-available,
.badge-status-free {
    align-items: center;
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.68rem;
    font-weight: 600;
    justify-content: center;
    min-width: 68px;
    padding: 0.32rem 0.6rem;
}

.resource-actions {
    display: inline-flex;
    gap: 0.4rem;
    justify-content: flex-end;
    white-space: nowrap;
}

.resource-action-btn {
    align-items: center;
    border-radius: 8px;
    display: inline-flex;
    height: 34px;
    justify-content: center;
    padding: 0;
    width: 34px;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-workload-card .table-container,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-workload-header {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-workload-table tbody {
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-workload-heading-icon {
    background: #102a40;
    color: #8cc7ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-workload-table tbody tr:hover {
    background: rgba(31, 99, 146, 0.12) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-profile-avatar {
    background: #102a40;
    border-color: #214764;
    color: #8cc7ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-profile-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-metric {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-profile-email,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .resource-workload-value {
    color: #9db6cf;
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

    .resource-workload-header {
        align-items: stretch !important;
        flex-direction: column;
    }

    .resource-workload-header .btn-primary-modern {
        justify-content: center;
        width: 100%;
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

    <!-- Resource Details Table -->
    <div class="card mb-4 resource-workload-card" id="resource-workload">
        <div class="card-header resource-workload-header">
            <div class="resource-workload-heading">
                <span class="resource-workload-heading-icon"><i class="fas fa-list" aria-hidden="true"></i></span>
                <div>
                    <h5>Resource Details &amp; Workload</h5>
                    <small class="text-muted"><?php echo count($developers); ?> active profile<?php echo count($developers) === 1 ? '' : 's'; ?> in <?php echo htmlspecialchars($sulDepartment); ?></small>
                </div>
            </div>
            <button class="btn-primary-modern resource-reallocation-btn" data-bs-toggle="modal" data-bs-target="#addDeveloperModal">
                <svg class="resource-reallocation-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M7 7h11m0 0-3-3m3 3-3 3M17 17H6m0 0 3 3m-3-3 3-3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
                <span>Reallocation</span>
            </button>
        </div>
        <div class="card-body">
            <div class="table-container">
                        <table class="data-table resource-workload-table">
                            <thead>
                                <tr>
                                    <th>Resource</th>
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
                                <?php if ($developers !== []): ?>
                                    <?php foreach ($developers as $resource):
                                        $resourceName = trim((string) ($resource['full_name'] ?? 'Developer'));
                                        $resourceNameParts = preg_split('/\s+/', $resourceName) ?: [];
                                        $resourceInitial = static function (string $value): string {
                                            return strtoupper(function_exists('mb_substr') ? mb_substr($value, 0, 1) : substr($value, 0, 1));
                                        };
                                        $resourceInitials = $resourceInitial((string) ($resourceNameParts[0] ?? 'D'));
                                        if (count($resourceNameParts) > 1) {
                                            $resourceInitials .= $resourceInitial((string) end($resourceNameParts));
                                        }
                                        $resourceActiveTasks = (int) ($resource['active_tasks'] ?? 0);
                                        $workloadPercent = (int) round(min(100, ($resourceActiveTasks / 5) * 100));
                                        $statusClass = $resourceActiveTasks >= 5 ? 'overloaded' : ($resourceActiveTasks >= 3 ? 'busy' : ($resourceActiveTasks > 0 ? 'available' : 'free'));
                                        $statusText = $resourceActiveTasks >= 5 ? 'Overloaded' : ($resourceActiveTasks >= 3 ? 'Busy' : ($resourceActiveTasks > 0 ? 'Available' : 'Free'));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="resource-profile">
                                                <span class="resource-profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($resourceInitials); ?></span>
                                                <div class="resource-profile-copy">
                                                    <p class="resource-profile-name"><?php echo htmlspecialchars($resourceName); ?></p>
                                                    <span class="resource-profile-email" title="<?php echo htmlspecialchars((string) $resource['email']); ?>"><?php echo htmlspecialchars((string) $resource['email']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="resource-metric"><?php echo $resourceActiveTasks; ?></span></td>
                                        <td><span class="resource-metric"><?php echo (int) ($resource['completed_tasks'] ?? 0); ?></span></td>
                                        <td><span class="resource-metric"><?php echo (int) ($resource['pending_tasks'] ?? 0); ?></span></td>
                                        <td><span class="resource-metric<?php echo (int) ($resource['overdue_tasks'] ?? 0) > 0 ? ' text-danger' : ''; ?>"><?php echo (int) ($resource['overdue_tasks'] ?? 0); ?></span></td>
                                        <td><span class="resource-metric"><?php echo $resource['avg_completion_days'] !== null ? round((float) $resource['avg_completion_days'], 1) . 'd' : 'N/A'; ?></span></td>
                                        <td>
                                            <div class="resource-workload">
                                                <div class="progress-modern">
                                                    <div class="progress-bar-modern" style="width: <?php echo $workloadPercent; ?>%; background: <?php echo $resourceActiveTasks >= 5 ? '#dc2626' : ($resourceActiveTasks >= 3 ? '#f59e0b' : '#10b981'); ?>;"></div>
                                                </div>
                                                <span class="resource-workload-value"><?php echo $workloadPercent; ?>% capacity</span>
                                            </div>
                                        </td>
                                        <td><span class="badge-status-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                        <td>
                                            <div class="resource-actions">
                                                <button type="button" class="btn-primary-modern resource-action-btn" title="Edit allocation" aria-label="Edit allocation for <?php echo htmlspecialchars($resourceName); ?>" onclick="editAllocation(<?php echo (int) $resource['id']; ?>, <?php echo htmlspecialchars(json_encode($resourceName), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $resourceActiveTasks; ?>)">
                                                    <i class="fas fa-sliders-h" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="btn-secondary-modern resource-action-btn" title="View assigned tasks" aria-label="View tasks for <?php echo htmlspecialchars($resourceName); ?>" onclick="viewResourceTasks(<?php echo (int) $resource['id']; ?>)">
                                                    <i class="fas fa-list-check" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No developer data available.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
            </div>
        </div>
    </div>

</div>

<!-- Developer Reallocation Modal -->
<div class="modal fade modal-modern" id="addDeveloperModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-right-left me-2"></i>Developer Reallocation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="reallocationForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="reallocation_source">From Developer</label>
                        <select name="source_developer_id" id="reallocation_source" class="form-select" required>
                            <option value="">Choose the current developer...</option>
                            <?php foreach ($availableDevelopers as $dev): ?>
                                <option value="<?php echo $dev['id']; ?>">
                                    <?php echo htmlspecialchars($dev['full_name']); ?> 
                                    (<?php echo htmlspecialchars($dev['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tasks to Reallocate</label>
                        <div id="reallocation_task_list" class="border rounded p-3" style="max-height: 240px; overflow-y: auto;">
                            <p class="text-muted mb-0">Select a source developer to view eligible tasks.</p>
                        </div>
                        <small class="text-muted"><span id="reallocation_task_count">0</span> task(s) selected</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reallocation_destination">To Developer</label>
                        <select name="destination_developer_id" id="reallocation_destination" class="form-select" required disabled>
                            <option value="">Choose the destination developer...</option>
                            <?php foreach ($availableDevelopers as $dev): ?>
                                <option
                                    value="<?php echo $dev['id']; ?>"
                                    data-availability="<?php echo htmlspecialchars((string) $dev['availability_status']); ?>"
                                    data-capacity="<?php echo (int) $dev['max_concurrent_tasks']; ?>"
                                >
                                    <?php echo htmlspecialchars($dev['full_name']); ?>
                                    — <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $dev['availability_status']))); ?>
                                    (capacity <?php echo (int) $dev['max_concurrent_tasks']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Unavailable developers and the current owner cannot be selected.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reallocate_resources" id="reallocation_submit" class="btn-primary-modern" disabled>
                        Reallocate Selected Tasks
                    </button>
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

<script>
const reallocationTasks = <?php echo json_encode($reallocationTasks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function refreshReallocationForm() {
    const source = document.getElementById('reallocation_source');
    const destination = document.getElementById('reallocation_destination');
    const taskList = document.getElementById('reallocation_task_list');
    const sourceId = Number(source.value || 0);
    const eligibleTasks = reallocationTasks.filter(task => Number(task.assigned_to) === sourceId);

    destination.disabled = sourceId <= 0;
    destination.value = '';
    Array.from(destination.options).forEach(option => {
        if (!option.value) return;
        const unavailable = ['on_leave', 'sick', 'unavailable'].includes(option.dataset.availability || '');
        option.disabled = Number(option.value) === sourceId || unavailable;
    });

    if (sourceId <= 0) {
        taskList.innerHTML = '<p class="text-muted mb-0">Select a source developer to view eligible tasks.</p>';
    } else if (eligibleTasks.length === 0) {
        taskList.innerHTML = '<p class="text-muted mb-0">This developer has no eligible active tasks.</p>';
    } else {
        taskList.innerHTML = eligibleTasks.map(task => {
            const meta = [task.project_name, task.priority, String(task.status || '').replaceAll('_', ' ')].filter(Boolean).join(' • ');
            return `
                <label class="d-flex align-items-start gap-2 py-2 border-bottom">
                    <input type="checkbox" class="form-check-input reallocation-task-checkbox" name="task_ids[]" value="${Number(task.id)}">
                    <span>
                        <strong class="d-block">${escapeHtml(task.title || '')}</strong>
                        <small class="text-muted">${escapeHtml(meta)}</small>
                    </span>
                </label>
            `;
        }).join('');
    }
    refreshReallocationSelection();
}

function refreshReallocationSelection() {
    const selectedCount = document.querySelectorAll('.reallocation-task-checkbox:checked').length;
    const sourceId = Number(document.getElementById('reallocation_source').value || 0);
    const destinationId = Number(document.getElementById('reallocation_destination').value || 0);
    document.getElementById('reallocation_task_count').textContent = String(selectedCount);
    document.getElementById('reallocation_submit').disabled =
        selectedCount === 0 || sourceId <= 0 || destinationId <= 0 || sourceId === destinationId;
}

document.getElementById('reallocation_source').addEventListener('change', refreshReallocationForm);
document.getElementById('reallocation_destination').addEventListener('change', refreshReallocationSelection);
document.getElementById('reallocation_task_list').addEventListener('change', refreshReallocationSelection);

const requestedReallocationDeveloperId = Number(
    new URLSearchParams(window.location.search).get('reallocate_developer_id') || 0
);
if (requestedReallocationDeveloperId > 0) {
    const source = document.getElementById('reallocation_source');
    const matchingOption = Array.from(source.options).find(
        option => Number(option.value) === requestedReallocationDeveloperId
    );
    if (matchingOption) {
        source.value = String(requestedReallocationDeveloperId);
        refreshReallocationForm();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addDeveloperModal')).show();
    }
}

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
        dataType: 'json',
        success: function(response) {
            if (!Array.isArray(response)) {
                $('#resourceTasksContent').html(`<div class="alert alert-danger mb-0">${escapeHtml(response.error || 'Unable to load tasks.')}</div>`);
                new bootstrap.Modal(document.getElementById('resourceTasksModal')).show();
                return;
            }

            const tasks = response;
            let html = '<div class="table-responsive"><table class="data-table">';
            html += '<thead><tr><th>Task</th><th>Project</th><th>Priority</th><th>Status</th><th>Deadline</th><th>Actions</th></tr></thead><tbody>';
            tasks.forEach(task => {
                const description = String(task.description || '').substring(0, 60);
                const deadline = task.deadline ? new Date(task.deadline).toLocaleDateString() : 'Not set';
                html += `
                    <tr>
                        <td><div class="fw-semibold">${escapeHtml(task.title || '')}</div><small>${escapeHtml(description)}</small></td>
                        <td>${escapeHtml(task.project_name)}</td>
                        <td>${escapeHtml(task.priority || '')}</td>
                        <td>${escapeHtml(task.status || '')}</td>
                        <td>${escapeHtml(deadline)}</td>
                        <td><button class="btn-primary-modern btn-sm-modern" onclick="viewProjectTasks(${Number(task.project_id)})"><i class="fas fa-eye"></i></button></td>
                    </tr>
                `;
            });
            html += '</tbody></table></div>';
            if(tasks.length === 0) html = '<p class="text-center text-muted py-4">No tasks assigned to this resource</p>';
            $('#resourceTasksContent').html(html);
            new bootstrap.Modal(document.getElementById('resourceTasksModal')).show();
        },
        error: function(xhr) {
            const message = xhr.responseJSON?.error || 'Unable to load tasks for this resource.';
            $('#resourceTasksContent').html(`<div class="alert alert-danger mb-0">${escapeHtml(message)}</div>`);
            new bootstrap.Modal(document.getElementById('resourceTasksModal')).show();
        }
    });
}

function viewProjectTasks(projectId) {
    window.location.href = 'task-allocation.php?project_id=' + projectId;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include '../../includes/footer.php'; ?>
