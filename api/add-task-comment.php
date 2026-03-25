<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$user = getCurrentUser();
$taskId = (int) ($_POST['task_id'] ?? 0);
$comment = trim((string) ($_POST['comment'] ?? ''));

if ($taskId <= 0 || $comment === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Task and comment are required']);
    exit();
}

$conn = getConnection();
$stmt = $conn->prepare('INSERT INTO task_comments (task_id, user_id, comment) VALUES (?, ?, ?)');
$stmt->bind_param('iis', $taskId, $user['id'], $comment);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save comment']);
    exit();
}

$commentId = $stmt->insert_id;
$stmt->close();

logActivity((int) $user['id'], 'add_task_comment', 'task_comments', $commentId, null, ['task_id' => $taskId]);

echo json_encode(['success' => true, 'comment_id' => $commentId]);
?>
