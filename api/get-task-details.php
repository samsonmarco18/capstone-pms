<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = getCurrentUser();
$conn = getConnection();

$taskId = intval($_GET['id'] ?? 0);

if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid task ID']);
    exit;
}

// Get task details
$stmt = $conn->prepare("
    SELECT t.*, 
           p.name as project_name, p.status as project_status, c.name as client_name,
           u.full_name as assigned_to_name, au.full_name as assigned_by_name,
           fe.min_days, fe.likely_days, fe.max_days, fe.expectation_description as estimation_notes
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN users u ON t.assigned_to = u.id
    LEFT JOIN users au ON t.assigned_by = au.id
    LEFT JOIN feature_estimations fe ON t.id = fe.task_id AND fe.expert_id = ?
    WHERE t.id = ?
");
$stmt->bind_param("ii", $user['id'], $taskId);
$stmt->execute();
$result = $stmt->get_result();

if ($task = $result->fetch_assoc()) {
    // Calculate days left
    $deadlineTimestamp = $task['deadline'] ? strtotime($task['deadline']) : false;
    $daysLeft = $deadlineTimestamp ? (int) floor(($deadlineTimestamp - time()) / 86400) : null;
    $task['days_left'] = $daysLeft;
    $task['is_overdue'] = $daysLeft !== null && $daysLeft < 0;
    
    // Priority color
    $priorityColors = ['low' => 'success', 'medium' => 'warning', 'high' => 'danger', 'critical' => 'dark'];
    $task['priority_color'] = $priorityColors[$task['priority']] ?? 'secondary';
    
    echo json_encode($task);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found']);
}
?>

