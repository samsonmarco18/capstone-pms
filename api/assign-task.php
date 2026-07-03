<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/project-workflow.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user = getCurrentUser();
$userId = (int) ($user['id'] ?? 0);
if (($user['role'] ?? '') !== 'sul') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit();
}

$conn = getConnection();
workflowEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);
$taskId = (int) ($_POST['task_id'] ?? 0);
$assigneeId = (int) ($_POST['user_id'] ?? 0);
$sulDepartment = trim((string) ($user['department'] ?? ''));
$sulDepartmentLabel = projectWorkflowDepartmentLabel($sulDepartment);
$isExpertDepartmentSul = $sulDepartmentLabel === 'Expert Department';

if ($taskId <= 0 || $assigneeId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please select a valid task and assignee.']);
    exit();
}

$departmentFilter = $sulDepartment !== '' ? ' AND ' . projectWorkflowUserDepartmentClause($conn, 'users', $sulDepartment) : ' AND 1 = 0';
$userStmt = $conn->prepare("SELECT id, role, full_name FROM users WHERE id = ? AND role IN ('expert', 'developer') AND status = 'active'{$departmentFilter}");
$userStmt->bind_param('i', $assigneeId);
$userStmt->execute();
$assignee = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$assignee) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please select an active expert or developer.']);
    exit();
}

$hasSalesApproval = dbColumnExists($conn, 'tasks', 'sales_approved');
$hasSalesChoice = dbColumnExists($conn, 'tasks', 'sales_estimate_days');
$projectFilter = $sulDepartment !== '' ? projectWorkflowProjectDepartmentClause($conn, 'p', $sulDepartment) : '1 = 0';
$taskDepartmentFilter = $sulDepartment !== '' ? projectWorkflowTaskDepartmentClause($conn, 't', $sulDepartment, true) : '1 = 0';

$salesApprovedSelect = $hasSalesApproval ? "t.sales_approved" : "0 AS sales_approved";
$salesEstimateDaysSelect = $hasSalesChoice ? "t.sales_estimate_days" : "0 AS sales_estimate_days";
$taskStmt = $conn->prepare("
    SELECT t.id, t.title, t.project_id, t.assigned_to, t.status, t.architect_reviewed_at, t.merged_at,
           current_assignee.role AS current_assignee_role,
           {$salesApprovedSelect}, {$salesEstimateDaysSelect},
           CASE WHEN EXISTS (
               SELECT 1
               FROM feature_estimations fe
               WHERE fe.task_id = t.id AND fe.status = 'completed'
           ) THEN 1 ELSE 0 END AS has_completed_estimation
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users current_assignee ON current_assignee.id = t.assigned_to
    WHERE t.id = ?
      AND {$projectFilter}
");
$taskStmt->bind_param('i', $taskId);
$taskStmt->execute();
$task = $taskStmt->get_result()->fetch_assoc();
$taskStmt->close();

if (!$task) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Task not found.']);
    exit();
}

if ($assignee['role'] === 'expert') {
    if (!$isExpertDepartmentSul) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only the Expert Department SUL can assign tasks to experts.']);
        exit();
    }

    if (($task['status'] ?? '') !== 'todo' || !empty($task['assigned_to'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only unassigned to-do tasks can be assigned to experts.']);
        exit();
    }
} elseif ($assignee['role'] === 'developer') {
    if ($isExpertDepartmentSul) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Developer assignment is handled by the owning department SUL.']);
        exit();
    }

    $salesApproved = !$hasSalesApproval || (int) ($task['sales_approved'] ?? 0) === 1;
    $daysSelected = !$hasSalesChoice || (int) ($task['sales_estimate_days'] ?? 0) > 0;
    $currentAssigneeRole = (string) ($task['current_assignee_role'] ?? '');
    $isLegacyCompletedHandoff = ($task['status'] ?? '') === 'completed'
        && empty($task['architect_reviewed_at'] ?? null)
        && empty($task['merged_at'] ?? null);
    $isReadyAssignee = $isLegacyCompletedHandoff || empty($task['assigned_to']) || $currentAssigneeRole === 'expert';
    $projectId = (int) ($task['project_id'] ?? 0);
    $roadmapPhaseCount = dbTableExists($conn, 'methodology_phases') && dbTableExists($conn, 'methodology_tasks')
        ? (int) (($conn->query("SELECT COUNT(*) AS total FROM methodology_phases WHERE project_id = {$projectId}")->fetch_assoc()['total'] ?? 0))
        : 0;
    if ($roadmapPhaseCount > 0) {
        projectWorkflowSyncRoadmap($conn, $projectId);
    }
    $currentRoadmapTask = $roadmapPhaseCount > 0
        ? projectWorkflowCurrentRoadmapTask($conn, $projectId, $taskId, $sulDepartment)
        : null;
    $taskBelongsToDepartment = $roadmapPhaseCount > 0
        ? (bool) $currentRoadmapTask
        : (bool) ($conn->query("SELECT t.id FROM tasks t WHERE t.id = {$taskId} AND {$taskDepartmentFilter} LIMIT 1")->fetch_assoc() ?? null);

    if (
        !in_array((string) ($task['status'] ?? ''), ['todo', 'completed'], true)
        || (($task['status'] ?? '') === 'completed' && !$isLegacyCompletedHandoff)
        || !$salesApproved
        || !$daysSelected
        || !$isReadyAssignee
        || !$taskBelongsToDepartment
        || (int) ($task['has_completed_estimation'] ?? 0) <= 0
        || ($currentRoadmapTask && (int) ($currentRoadmapTask['assigned_developer_id'] ?? 0) > 0)
    ) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only the current unlocked, Sales-approved roadmap task can be assigned to a developer.']);
        exit();
    }
}

$stmt = $conn->prepare("
    UPDATE tasks
    SET assigned_to = ?,
        assigned_by = ?,
        status = 'todo',
        completed_at = NULL,
        pr_submitted_at = NULL,
        sul_reviewed_by = NULL,
        sul_reviewed_at = NULL
    WHERE id = ?
");
$stmt->bind_param('iii', $assigneeId, $userId, $taskId);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to assign task.']);
    exit();
}
$stmt->close();

if (($assignee['role'] ?? '') === 'developer' && dbTableExists($conn, 'methodology_tasks')) {
    $currentRoadmapTask = $currentRoadmapTask ?? projectWorkflowCurrentRoadmapTask($conn, (int) ($task['project_id'] ?? 0), $taskId, $sulDepartment);
    $currentRoadmapTaskId = (int) ($currentRoadmapTask['id'] ?? 0);
    $syncRoadmapStmt = $conn->prepare("
        UPDATE methodology_tasks
        SET assigned_developer_id = ?, status = 'planned', updated_by = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    if ($syncRoadmapStmt && $currentRoadmapTaskId > 0) {
        $syncRoadmapStmt->bind_param('iii', $assigneeId, $userId, $currentRoadmapTaskId);
        $syncRoadmapStmt->execute();
        $syncRoadmapStmt->close();
    }

    projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));
}

createNotification(
    $assigneeId,
    'New Task Assignment: ' . $task['title'],
    'You have been assigned a task by the Service Unit Leader.',
    'task',
    'tasks',
    $taskId,
    BASE_URL . (($assignee['role'] === 'developer') ? 'modules/developer/task.php?id=' . $taskId : 'modules/expert/feature-estimation.php')
);

logActivity($userId, 'assign_task', 'tasks', $taskId, null, ['assigned_to' => $assigneeId]);

echo json_encode(['success' => true]);
