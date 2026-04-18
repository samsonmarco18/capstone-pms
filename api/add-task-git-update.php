<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$user = getCurrentUser();

if (!$user || !in_array($user['role'], ['developer', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only developers and administrators can add Git updates']);
    exit();
}

$taskId = (int) ($_POST['task_id'] ?? 0);
$repositoryUrl = trim((string) ($_POST['repository_url'] ?? ''));
$branchName = trim((string) ($_POST['branch_name'] ?? ''));
$commitHash = trim((string) ($_POST['commit_hash'] ?? ''));
$commitMessage = trim((string) ($_POST['commit_message'] ?? ''));
$pullRequestUrl = trim((string) ($_POST['pull_request_url'] ?? ''));
$gitAction = trim((string) ($_POST['git_action'] ?? 'commit_pushed'));
$status = trim((string) ($_POST['status'] ?? 'active'));
$notes = trim((string) ($_POST['notes'] ?? ''));

$allowedActions = ['branch_created', 'commit_pushed', 'pull_request_opened', 'merged', 'deployed', 'note'];
$allowedStatuses = ['active', 'in_review', 'merged', 'closed'];

if ($taskId <= 0 || $branchName === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Task and branch name are required']);
    exit();
}

if (!in_array($gitAction, $allowedActions, true) || !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Git action or status']);
    exit();
}

$conn = getConnection();

$taskStmt = $conn->prepare('SELECT id, title, assigned_to FROM tasks WHERE id = ? LIMIT 1');
$taskStmt->bind_param('i', $taskId);
$taskStmt->execute();
$task = $taskStmt->get_result()->fetch_assoc();
$taskStmt->close();

if (!$task) {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found']);
    exit();
}

if ($user['role'] === 'developer' && (int) ($task['assigned_to'] ?? 0) !== (int) $user['id']) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only update Git activity for your assigned tasks']);
    exit();
}

$repositoryUrl = $repositoryUrl !== '' ? $repositoryUrl : null;
$commitHash = $commitHash !== '' ? substr($commitHash, 0, 64) : null;
$commitMessage = $commitMessage !== '' ? $commitMessage : null;
$pullRequestUrl = $pullRequestUrl !== '' ? $pullRequestUrl : null;
$notes = $notes !== '' ? $notes : null;

$stmt = $conn->prepare(
    'INSERT INTO task_git_updates (
        task_id, user_id, repository_url, branch_name, commit_hash,
        commit_message, pull_request_url, git_action, status, notes
     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param(
    'iissssssss',
    $taskId,
    $user['id'],
    $repositoryUrl,
    $branchName,
    $commitHash,
    $commitMessage,
    $pullRequestUrl,
    $gitAction,
    $status,
    $notes
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save Git update']);
    exit();
}

$updateId = $stmt->insert_id;
$stmt->close();

logActivity(
    (int) $user['id'],
    'add_task_git_update',
    'task_git_updates',
    $updateId,
    null,
    [
        'task_id' => $taskId,
        'branch_name' => $branchName,
        'git_action' => $gitAction,
        'status' => $status,
    ]
);

echo json_encode([
    'success' => true,
    'git_update_id' => $updateId,
]);
?>
