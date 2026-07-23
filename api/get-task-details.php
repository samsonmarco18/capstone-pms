<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/department-artifacts.php';
require_once __DIR__ . '/../includes/methodology-roadmap.php';
require_once __DIR__ . '/../includes/project-workflow.php';
require_once __DIR__ . '/../includes/task-reopen.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = getCurrentUser();
$conn = getConnection();
departmentArtifactEnsureSchema($conn);
methodologyRoadmapEnsureSchema($conn);
taskReopenEnsureSchema($conn);

$taskId = intval($_GET['id'] ?? 0);

if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid task ID']);
    exit;
}

$developerReopenFilter = (($user['role'] ?? '') === 'developer')
    ? ' AND rr_approved.requested_by = ' . (int) ($user['id'] ?? 0)
    : '';

// Get task details
$stmt = $conn->prepare("
    SELECT t.*, 
           p.name as project_name, p.status as project_status, p.description as project_description,
           p.requested_features, p.timeline_start, p.timeline_end,
           c.name as client_name,
           u.full_name as assigned_to_name, au.full_name as assigned_by_name,
           (
               SELECT COUNT(DISTINCT mt_bulk.task_id)
               FROM methodology_tasks mt_current
               INNER JOIN methodology_tasks mt_bulk
                   ON mt_bulk.project_id = mt_current.project_id
                  AND mt_bulk.developer_entity_key = mt_current.developer_entity_key
                  AND mt_bulk.task_id IS NOT NULL
                  AND mt_bulk.task_id > 0
               WHERE mt_current.project_id = t.project_id
                 AND mt_current.task_id = t.id
                 AND COALESCE(mt_current.developer_entity_key, '') <> ''
           ) AS bulk_entity_task_count,
           (
               SELECT COUNT(*)
               FROM task_reopen_requests rr
               WHERE rr.task_id = t.id
                 AND rr.status IN ('pending_sul', 'pending_architect')
           ) AS active_reopen_request_count,
           (
               SELECT MAX(rr_approved.architect_reviewed_at)
               FROM task_reopen_requests rr_approved
               WHERE rr_approved.task_id = t.id
                 AND rr_approved.status = 'architect_approved'
                 {$developerReopenFilter}
           ) AS last_reopened_at,
           fe.min_days, fe.likely_days, fe.max_days, fe.expectation_description as estimation_notes
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN users u ON t.assigned_to = u.id
    LEFT JOIN users au ON t.assigned_by = au.id
    LEFT JOIN feature_estimations fe ON fe.id = (
        SELECT fe_latest.id
        FROM feature_estimations fe_latest
        WHERE fe_latest.task_id = t.id AND fe_latest.status = 'completed'
        ORDER BY fe_latest.submitted_at DESC, fe_latest.id DESC
        LIMIT 1
    )
    WHERE t.id = ?
");
$stmt->bind_param("i", $taskId);
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

    $gitCountStmt = $conn->prepare('SELECT COUNT(*) AS git_updates_count FROM task_git_updates WHERE task_id = ?');
    $gitCountStmt->bind_param('i', $taskId);
    $gitCountStmt->execute();
    $gitCountRow = $gitCountStmt->get_result()->fetch_assoc();
    $gitCountStmt->close();
    $task['git_updates_count'] = (int) ($gitCountRow['git_updates_count'] ?? 0);

    $latestGitStmt = $conn->prepare(
        'SELECT tgu.*, u.full_name AS user_name
         FROM task_git_updates tgu
         INNER JOIN users u ON u.id = tgu.user_id
         WHERE tgu.task_id = ?
         ORDER BY tgu.created_at DESC, tgu.id DESC'
    );
    $latestGitStmt->bind_param('i', $taskId);
    $latestGitStmt->execute();
    $gitUpdatesResult = $latestGitStmt->get_result();
    $task['git_updates'] = [];
    while ($gitUpdate = $gitUpdatesResult->fetch_assoc()) {
        $task['git_updates'][] = $gitUpdate;
    }
    $task['latest_git_update'] = $task['git_updates'][0] ?? null;
    $latestGitStmt->close();

    $task['project_requirements'] = departmentArtifactParseRequirements($task['requested_features'] ?? '');
    $task['additional_requirements'] = departmentArtifactAdditionalRequirements($task['requested_features'] ?? '');
    if (($user['role'] ?? '') === 'developer' && (int) ($task['assigned_to'] ?? 0) === (int) ($user['id'] ?? 0)) {
        projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));
        $task['roadmap_start_block_reason'] = methodologyRoadmapStartBlockReason($conn, $taskId, (int) $user['id']);
    } else {
        $task['roadmap_start_block_reason'] = null;
    }
    $task['department_artifacts'] = departmentArtifactFetchVisibleForTask($conn, $taskId);

    $task['evortix_revision'] = null;
    if (in_array((string) ($task['status'] ?? ''), ['sul_revision', 'qa_failed'], true)) {
        $revisionStmt = $conn->prepare(
            "SELECT tc.comment, tc.created_at, u.full_name, u.role
             FROM task_comments tc
             INNER JOIN users u ON u.id = tc.user_id
             WHERE tc.task_id = ? AND u.role IN ('sul', 'qa', 'architect')
             ORDER BY tc.created_at DESC, tc.id DESC
             LIMIT 1"
        );
        $revisionStmt->bind_param('i', $taskId);
        $revisionStmt->execute();
        $task['evortix_revision'] = $revisionStmt->get_result()->fetch_assoc() ?: null;
        $revisionStmt->close();
    }

    $taskText = strtolower((string) ($task['title'] ?? '') . ' ' . (string) ($task['description'] ?? ''));
    $recommendedTests = ['Run the task acceptance criteria and a focused regression check.'];
    if (preg_match('/api|backend|payment|database|auth|login/', $taskText)) {
        $recommendedTests = ['Run unit tests for changed logic.', 'Run API integration and error-path tests.', 'Verify authentication, authorization, and data validation.'];
    } elseif (preg_match('/ui|ux|frontend|page|screen|form|dashboard/', $taskText)) {
        $recommendedTests = ['Check the main user flow and form validation.', 'Run responsive and cross-browser checks.', 'Verify accessibility and regression behavior.'];
    }
    $task['evortix_recommended_tests'] = $recommendedTests;

    $task['evortix_next_task'] = null;
    if (($user['role'] ?? '') === 'developer') {
        $developerId = (int) ($user['id'] ?? 0);
        $nextResult = $conn->query(
            "SELECT DISTINCT t.id, t.title, t.priority, t.status, t.started_at, t.deadline
             FROM tasks t
             LEFT JOIN methodology_tasks mt ON mt.task_id = t.id
             LEFT JOIN methodology_task_departments mtd ON mtd.workflow_task_id = t.id OR mtd.methodology_task_id = mt.id
             WHERE t.id <> {$taskId}
               AND t.status IN ('todo', 'in_progress', 'sul_revision', 'qa_failed', 'blocked')
               AND (t.assigned_to = {$developerId} OR mt.assigned_developer_id = {$developerId} OR mtd.assigned_developer_id = {$developerId})
             ORDER BY
               CASE t.priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,
               CASE WHEN t.started_at IS NULL THEN 1 ELSE 0 END,
               t.started_at ASC,
               CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END,
               t.deadline ASC,
               t.id ASC
             LIMIT 20"
        );
        if ($nextResult) {
            while ($candidate = $nextResult->fetch_assoc()) {
                $blocker = methodologyRoadmapStartBlockReason($conn, (int) $candidate['id'], $developerId);
                if ($blocker !== null && $blocker !== '') continue;
                $candidate['reason'] = ucfirst((string) ($candidate['priority'] ?? 'medium'))
                    . ' priority, dependencies are ready, and it has the earliest applicable start/deadline date.';
                $task['evortix_next_task'] = $candidate;
                break;
            }
        }
    }

    $task['evortix_suggestion_version'] = hash('sha256', implode('|', [
        (string) ($task['id'] ?? ''),
        (string) ($task['status'] ?? ''),
        (string) ($task['updated_at'] ?? ''),
        (string) ($task['deadline'] ?? ''),
        (string) ($task['evortix_revision']['created_at'] ?? ''),
    ]));
    
    echo json_encode($task);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found']);
}
?>

