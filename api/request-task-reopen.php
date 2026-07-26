<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/task-reopen.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = getCurrentUser();
if (($user['role'] ?? '') !== 'developer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only developers can request a task reopen.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$conn = getConnection();
$taskId = (int) ($_POST['task_id'] ?? 0);
$severity = (string) ($_POST['severity'] ?? 'minor');
$reason = (string) ($_POST['reason'] ?? '');

$result = taskReopenCreateRequest($conn, $taskId, (int) ($user['id'] ?? 0), $severity, $reason);
if (!$result['success']) {
    http_response_code(422);
}

echo json_encode($result);
?>
