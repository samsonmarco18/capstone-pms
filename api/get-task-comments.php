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
$stmt = $conn->prepare(
    'SELECT tc.*, u.full_name AS user_name
     FROM task_comments tc
     INNER JOIN users u ON u.id = tc.user_id
     WHERE tc.task_id = ?
     ORDER BY tc.created_at DESC'
);
$stmt->bind_param('i', $taskId);
$stmt->execute();
$comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($comments);
?>
