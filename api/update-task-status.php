<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/methodology-roadmap.php';
require_once __DIR__ . '/../includes/project-workflow.php';

requireLogin();

$user = getCurrentUser();
if (!$user || ($user['role'] ?? '') !== 'developer') {
    http_response_code(403);
    echo json_encode(['error' => 'Only assigned developers can update task status.']);
    exit;
}

$conn = getConnection();
workflowEnsureSchema($conn);
methodologyRoadmapEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);

$taskId = (int) ($_POST['task_id'] ?? 0);
$newStatus = trim((string) ($_POST['new_status'] ?? ''));
$userDepartmentCanonical = projectWorkflowDepartmentLabel((string) ($user['department'] ?? ''));
$allowedStatuses = ['todo', 'in_progress'];
if (in_array($userDepartmentCanonical, ['UI/UX', 'QA'], true)) {
    $allowedStatuses[] = 'for_review';
}

if ($taskId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid task status update.']);
    exit;
}

$taskCheck = $conn->prepare(
    'SELECT t.id, t.title, t.status, t.assigned_by, t.project_id, p.name AS project_name, p.status AS project_status, p.sul_id
     FROM tasks t
     LEFT JOIN projects p ON p.id = t.project_id
     WHERE t.id = ? AND t.assigned_to = ?
     LIMIT 1'
);
if ($taskCheck === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to validate task.']);
    exit;
}

$userId = (int) $user['id'];
$taskCheck->bind_param('ii', $taskId, $userId);
$taskCheck->execute();
$task = $taskCheck->get_result()->fetch_assoc();
$taskCheck->close();

if (!$task) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only update tasks assigned to you.']);
    exit;
}

if ((string) ($task['project_status'] ?? '') === 'cancelled') {
    http_response_code(409);
    echo json_encode(['error' => 'This project is cancelled. Restore the project before updating task progress.']);
    exit;
}

$currentStatus = (string) ($task['status'] ?? '');
if (!in_array($currentStatus, ['todo', 'in_progress', 'sul_revision', 'qa_failed', 'blocked'], true)) {
    http_response_code(409);
    echo json_encode(['error' => 'This task is already in the controlled review workflow and cannot be moved by Developer.']);
    exit;
}

if ($newStatus === 'for_review' && !in_array($userDepartmentCanonical, ['UI/UX', 'QA'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Submit a Pull Request from the Git update form to request SUL review.']);
    exit;
}
if (
    $newStatus === 'for_review'
    && $userDepartmentCanonical === 'QA'
    && !in_array($currentStatus, ['in_progress', 'sul_revision', 'qa_failed'], true)
) {
    http_response_code(409);
    echo json_encode(['error' => 'Start testing before submitting QA test results to the QA SUL.']);
    exit;
}

if ($newStatus === 'in_progress') {
    projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));
    $roadmapBlockReason = methodologyRoadmapStartBlockReason($conn, $taskId, $userId);
    if ($roadmapBlockReason !== null) {
        http_response_code(409);
        echo json_encode(['error' => $roadmapBlockReason]);
        exit;
    }
}

$stmt = $conn->prepare('UPDATE tasks SET status = ?, completed_at = NULL WHERE id = ?');
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to prepare task status update.']);
    exit;
}

$stmt->bind_param('si', $newStatus, $taskId);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['error' => 'Task status could not be updated. Please try again.']);
    exit;
}
$stmt->close();

projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));

workflowLogStatusChange(
    $userId,
    $taskId,
    $currentStatus,
    $newStatus,
    $newStatus === 'for_review'
        ? ($userDepartmentCanonical === 'QA' ? 'qa_submit_test_results' : 'uiux_submit_design')
        : 'developer_update_task_status'
);

if ($newStatus === 'for_review') {
    $isQaSubmission = $userDepartmentCanonical === 'QA';
    workflowAddTaskComment(
        $conn,
        $taskId,
        $userId,
        $isQaSubmission
            ? 'QA Developer submitted test results for QA SUL review.'
            : 'UI/UX submitted design work for SUL review.'
    );
    $reviewActionUrl = BASE_URL . 'modules/sul/task-allocation.php?project_id=' . (int) ($task['project_id'] ?? 0) . '&task_id=' . $taskId . '#developer-review-panel';
    $reviewerIds = array_values(array_filter([(int) ($task['assigned_by'] ?? 0)]));
    if ($reviewerIds === [] && (int) ($task['sul_id'] ?? 0) > 0) {
        $reviewerIds[] = (int) $task['sul_id'];
    }
    if ($userDepartmentCanonical !== '') {
        $departmentSulWhere = projectWorkflowUserDepartmentClause($conn, 'u', $userDepartmentCanonical);
        $departmentSulRows = $conn->query("SELECT u.id FROM users u WHERE u.role = 'sul' AND u.status = 'active' AND {$departmentSulWhere}");
        if ($departmentSulRows) {
            while ($departmentSul = $departmentSulRows->fetch_assoc()) {
                $reviewerIds[] = (int) ($departmentSul['id'] ?? 0);
            }
        }
    }
    foreach (array_unique(array_filter($reviewerIds)) as $reviewerId) {
        workflowNotifyUser(
            (int) $reviewerId,
            ($isQaSubmission ? 'QA Test Results Submitted: ' : 'Design Submitted for Review: ')
                . (string) ($task['title'] ?? 'Task'),
            $isQaSubmission
                ? 'A QA Developer submitted testing notes and evidence for QA SUL review.'
                : 'UI/UX submitted design files or references for review.',
            $reviewActionUrl,
            $taskId
        );
    }
}

echo json_encode([
    'success' => true,
    'task_id' => $taskId,
    'old_status' => $currentStatus,
    'new_status' => $newStatus,
    'message' => $newStatus === 'in_progress'
        ? ($userDepartmentCanonical === 'QA'
            ? 'Testing started. Review the requirements, suggested tests, and existing handoffs.'
            : 'Task started. UI/UX designs and uploads are available in the task details.')
        : ($newStatus === 'for_review' && $userDepartmentCanonical === 'QA'
            ? 'QA test results submitted to the QA SUL for review.'
            : 'Task status updated.'),
]);
?>
