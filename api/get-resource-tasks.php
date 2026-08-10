<?php
require_once '../includes/auth.php';
require_once '../includes/project-workflow.php';
requireRole(['architect', 'sul']);

$conn = getConnection();
$viewer = getCurrentUser();

header('Content-Type: application/json');

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit();
}

$userId = (int) $_GET['user_id'];

$isSul = ($viewer['role'] ?? '') === 'sul';
$sulDepartment = $isSul
    ? projectWorkflowDepartmentLabel((string) ($viewer['department'] ?? ''))
    : '';

if ($isSul && $sulDepartment === '') {
    http_response_code(403);
    echo json_encode(['error' => 'Your SUL account is not assigned to a department.']);
    exit();
}

// SULs can inspect only active developers in their own department.
$resourceScope = '';
if ($isSul) {
    $resourceScope = " AND u.role = 'developer' AND "
        . projectWorkflowUserDepartmentClause($conn, 'u', $sulDepartment);
} else {
    $resourceScope = " AND u.role IN ('developer', 'expert')";
}

$stmt = $conn->prepare("
    SELECT u.id, u.full_name, u.role
    FROM users u
    WHERE u.id = ?
      AND u.status = 'active'
      {$resourceScope}
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit();
}

$taskScope = '';
if ($isSul) {
    $taskScope = ' AND '
        . projectWorkflowTaskDepartmentClause($conn, 't', $sulDepartment, true)
        . ' AND '
        . projectWorkflowProjectDepartmentClause($conn, 'p', $sulDepartment);
}

// Get only tasks visible to the current viewer.
$stmt = $conn->prepare("
    SELECT t.*, p.name as project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = ?
      AND p.status != 'cancelled'
      {$taskScope}
    ORDER BY t.created_at DESC
    LIMIT 20
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($tasks);
