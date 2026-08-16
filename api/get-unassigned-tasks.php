<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/project-workflow.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = getCurrentUser();
if (($user['role'] ?? '') !== 'sul') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$conn = getConnection();
projectWorkflowEnsureSchema($conn);
$sulDepartment = trim((string) ($user['department'] ?? ''));
$projectFilter = $sulDepartment !== '' ? projectWorkflowProjectDepartmentClause($conn, 'p', $sulDepartment) : '1 = 0';
$result = $conn->query("
    SELECT t.id, t.title, p.name AS project_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE (t.assigned_to IS NULL OR t.assigned_to = 0)
      AND t.status = 'todo'
      AND {$projectFilter}
      AND p.status != 'cancelled'
    ORDER BY t.deadline ASC, t.created_at DESC
    LIMIT 50
");

$tasks = [];
while ($row = $result->fetch_assoc()) {
    $tasks[] = $row;
}

echo json_encode($tasks);
