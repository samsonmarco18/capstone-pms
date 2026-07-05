<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/project-workflow.php';

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

if ($user['role'] === 'developer' && (in_array($gitAction, ['merged', 'deployed'], true) || $status === 'merged')) {
    http_response_code(403);
    echo json_encode(['error' => 'Only Architect can perform final merge/release workflow actions']);
    exit();
}

$conn = getConnection();
workflowEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);

$taskStmt = $conn->prepare(
    'SELECT t.id, t.title, t.status, t.project_id, t.assigned_to, t.assigned_by, p.name AS project_name, p.sul_id
     FROM tasks t
     LEFT JOIN projects p ON p.id = t.project_id
     WHERE t.id = ?
     LIMIT 1'
);
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

$isPullRequestSubmission = $gitAction === 'pull_request_opened' || $pullRequestUrl !== null || $status === 'in_review';
if ($isPullRequestSubmission) {
    $currentStatus = (string) ($task['status'] ?? '');
    $developerOwnedStatuses = ['todo', 'in_progress', 'sul_revision', 'qa_failed', 'blocked'];

    if (!in_array($currentStatus, $developerOwnedStatuses, true)) {
        http_response_code(409);
        echo json_encode(['error' => 'This task is already in review/testing and cannot be resubmitted by Developer.']);
        exit();
    }
}

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

if ($isPullRequestSubmission) {
    $currentStatus = (string) ($task['status'] ?? '');

    $updateStmt = $conn->prepare("UPDATE tasks SET status = 'for_review', completed_at = NULL, pr_submitted_at = NOW() WHERE id = ?");
    if ($updateStmt !== false) {
        $updateStmt->bind_param('i', $taskId);
        $updateStmt->execute();
        $updateStmt->close();
    }
    projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));

    workflowLogStatusChange((int) $user['id'], $taskId, $currentStatus, 'for_review', 'developer_submit_pull_request', $notes);
    workflowAddTaskComment(
        $conn,
        $taskId,
        (int) $user['id'],
        'Pull Request submitted for SUL review' . ($pullRequestUrl ? ': ' . $pullRequestUrl : '.')
    );

    $recipientIds = [];
    if (!empty($task['sul_id'])) {
        $recipientIds[] = (int) $task['sul_id'];
    }
    if (!empty($task['assigned_by'])) {
        $recipientIds[] = (int) $task['assigned_by'];
    }

    foreach (array_unique(array_filter($recipientIds)) as $recipientId) {
        workflowNotifyUser(
            $recipientId,
            'Pull Request For Review: ' . ($task['title'] ?? 'Developer task'),
            'Developer ' . ($user['full_name'] ?? 'Developer') . ' submitted a Pull Request'
                . (!empty($task['project_name']) ? ' for ' . $task['project_name'] : '') . '.',
            BASE_URL . 'modules/sul/task-allocation.php?task_id=' . $taskId,
            $taskId
        );
    }

    workflowNotifyRoles(
        $conn,
        ['sul'],
        'Pull Request For Review: ' . ($task['title'] ?? 'Developer task'),
        'A developer submitted a Pull Request and it is waiting for SUL approval.',
        BASE_URL . 'modules/sul/task-allocation.php?task_id=' . $taskId,
        $taskId
    );
}

$isReleaseMovement = !$isPullRequestSubmission && (in_array($gitAction, ['merged', 'deployed'], true) || $status === 'merged');
if ($isReleaseMovement) {
    $currentStatus = (string) ($task['status'] ?? '');
    $movementLabel = $gitAction === 'deployed' ? 'Deployment Update' : 'Merge Update';
    $movementVerb = $gitAction === 'deployed' ? 'deployed' : 'merged';

    $releaseStmt = $conn->prepare("
        UPDATE tasks
        SET status = 'completed',
            merged_at = COALESCE(merged_at, NOW()),
            release_notified_at = NOW(),
            completed_at = COALESCE(completed_at, NOW())
        WHERE id = ?
    ");
    if ($releaseStmt !== false) {
        $releaseStmt->bind_param('i', $taskId);
        $releaseStmt->execute();
        $releaseStmt->close();
    }

    if ($currentStatus !== 'completed') {
        workflowLogStatusChange((int) $user['id'], $taskId, $currentStatus, 'completed', 'git_' . $movementVerb, $notes);
    }
    projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));

    workflowAddTaskComment(
        $conn,
        $taskId,
        (int) $user['id'],
        $movementLabel . ': ' . ($notes ?: ucfirst($movementVerb) . ' update recorded.')
    );

    workflowMaybeCompleteProject($conn, (int) ($task['project_id'] ?? 0), (int) $user['id']);

    workflowNotifyRoles(
        $conn,
        ['sales'],
        $movementLabel . ': ' . ($task['title'] ?? 'Developer task'),
        'A task was ' . $movementVerb . (!empty($task['project_name']) ? ' for ' . $task['project_name'] : '') . '. Sales can view the release status.',
        BASE_URL . 'modules/sales/release-status.php?task_id=' . $taskId,
        $taskId
    );

    $developerId = (int) ($task['assigned_to'] ?? 0);
    if ($developerId > 0 && $developerId !== (int) $user['id']) {
        workflowNotifyUser(
            $developerId,
            $movementLabel . ': ' . ($task['title'] ?? 'Developer task'),
            'The task was ' . $movementVerb . (!empty($task['project_name']) ? ' for ' . $task['project_name'] : '') . '.',
            BASE_URL . 'modules/developer/task.php?id=' . $taskId,
            $taskId
        );
    }

    $reviewerIds = array_unique(array_filter([
        (int) ($task['assigned_by'] ?? 0),
        (int) ($task['sul_id'] ?? 0),
    ]));

    foreach ($reviewerIds as $recipientId) {
        if ($recipientId === (int) $user['id']) {
            continue;
        }

        workflowNotifyUser(
            $recipientId,
            $movementLabel . ': ' . ($task['title'] ?? 'Developer task'),
            'The task was ' . $movementVerb . (!empty($task['project_name']) ? ' for ' . $task['project_name'] : '') . '.',
            BASE_URL . 'modules/sul/task-allocation.php?task_id=' . $taskId,
            $taskId
        );
    }
}

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
    'pull_request_submitted' => $isPullRequestSubmission,
    'task_status' => $isPullRequestSubmission ? 'for_review' : (string) ($task['status'] ?? ''),
    'message' => $isPullRequestSubmission
        ? 'Pull Request submitted. Task moved to Workflow Review.'
        : 'Git update saved successfully.',
]);
?>
