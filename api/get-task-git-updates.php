<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$taskId = (int) ($_GET['task_id'] ?? 0);

if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid task ID']);
    exit();
}

$conn = getConnection();

$taskStmt = $conn->prepare('SELECT id FROM tasks WHERE id = ? LIMIT 1');
$taskStmt->bind_param('i', $taskId);
$taskStmt->execute();
$taskExists = (bool) $taskStmt->get_result()->fetch_assoc();
$taskStmt->close();

if (!$taskExists) {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found']);
    exit();
}

$stmt = $conn->prepare(
    'SELECT tgu.*, u.full_name AS user_name
     FROM task_git_updates tgu
     INNER JOIN users u ON u.id = tgu.user_id
     WHERE tgu.task_id = ?
     ORDER BY tgu.created_at DESC, tgu.id DESC'
);
$stmt->bind_param('i', $taskId);
$stmt->execute();
$updates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($updates);
?>
