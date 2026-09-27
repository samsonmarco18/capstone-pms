<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/version-control.php';

requireLogin();

$taskId = (int) ($_GET['task_id'] ?? 0);

if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid task ID']);
    exit();
}

$conn = getConnection();

$taskStmt = $conn->prepare('SELECT id, project_id FROM tasks WHERE id = ? LIMIT 1');
$taskStmt->bind_param('i', $taskId);
$taskStmt->execute();
$task = $taskStmt->get_result()->fetch_assoc();
$taskExists = (bool) $task;
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

if ($updates && dbTableExists($conn, 'project_repositories') && dbTableExists($conn, 'repository_branches')) {
    $taskProjectId = (int) ($task['project_id'] ?? 0);
    $branchReviewLookup = [];
    foreach ($updates as $updateIndex => $update) {
        $branchName = trim((string) ($update['branch_name'] ?? ''));
        if ($branchName !== '' && !array_key_exists($branchName, $branchReviewLookup)) {
            $reviewBranchStmt = $conn->prepare(
                "SELECT rb.id AS branch_id, pr.id AS repository_id, pr.name AS repository_name
                 FROM project_repositories pr
                 INNER JOIN repository_branches rb ON rb.repository_id = pr.id
                 WHERE pr.project_id = ?
                   AND pr.repository_status = 'active'
                   AND rb.branch_status != 'deleted'
                   AND rb.name = ?
                 ORDER BY pr.id ASC, rb.id ASC
                 LIMIT 1"
            );
            $reviewBranchStmt->bind_param('is', $taskProjectId, $branchName);
            $reviewBranchStmt->execute();
            $branchReviewLookup[$branchName] = $reviewBranchStmt->get_result()->fetch_assoc() ?: [];
            $reviewBranchStmt->close();
        }

        $reviewBranch = $branchReviewLookup[$branchName] ?? [];
        $reviewRepositoryId = (int) ($reviewBranch['repository_id'] ?? 0);
        $reviewBranchId = (int) ($reviewBranch['branch_id'] ?? 0);
        $updates[$updateIndex]['review_repository_id'] = $reviewRepositoryId;
        $updates[$updateIndex]['review_branch_id'] = $reviewBranchId;
        $updates[$updateIndex]['branch_review_url'] = vcBuildRepositoryBrowserUrl($taskProjectId, $reviewRepositoryId, $reviewBranchId);
        $updates[$updateIndex]['repository_review_url'] = vcBuildRepositoryBrowserUrl($taskProjectId, $reviewRepositoryId);
        $updates[$updateIndex]['pull_request_review_url'] = $updates[$updateIndex]['branch_review_url'];
    }
}

echo json_encode($updates);
?>
