<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/project-workflow.php';
requireRole('qa');

$conn = getConnection();
$user = getCurrentUser();
workflowEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);

function qaEvortixSuggestedTests(array $task): array
{
    $taskTitle = trim((string) ($task['title'] ?? 'Feature'));
    $taskText = strtolower(implode(' ', [
        $taskTitle,
        (string) ($task['description'] ?? ''),
        (string) ($task['requested_features'] ?? ''),
    ]));
    $tests = [
        'Verify the feature matches the approved requirements and acceptance criteria',
        'Test empty, invalid, boundary, and unexpected input values',
        'Confirm validation and error messages are clear and accurate',
        'Verify saved data remains correct after refresh and reopening the feature',
        'Run a focused regression check on related existing workflows',
    ];

    if (preg_match('/auth|login|password|account|user|role|permission|access/', $taskText)) {
        $tests[] = 'Verify authorized and unauthorized roles have the correct access';
        $tests[] = 'Test expired sessions, invalid credentials, and protected actions';
    }
    if (preg_match('/api|backend|database|integration|sync|webhook/', $taskText)) {
        $tests[] = 'Verify API success, validation, authorization, and error responses';
        $tests[] = 'Confirm database changes are complete, consistent, and not duplicated';
    }
    if (preg_match('/payment|billing|checkout|invoice|price|amount|subscription/', $taskText)) {
        $tests[] = 'Test successful, declined, cancelled, and duplicate payment attempts';
        $tests[] = 'Verify totals, currency, discounts, and transaction records';
    }
    if (preg_match('/ui|ux|frontend|page|screen|form|dashboard|modal|mobile/', $taskText)) {
        $tests[] = 'Test responsive layouts on desktop, tablet, and mobile sizes';
        $tests[] = 'Check keyboard navigation, labels, focus states, and basic accessibility';
    }
    if (preg_match('/upload|attachment|document|image|file|download/', $taskText)) {
        $tests[] = 'Test allowed, blocked, oversized, empty, and corrupted files';
        $tests[] = 'Verify uploaded files can only be accessed by permitted users';
    }
    if (preg_match('/search|filter|sort|catalog|list|pagination/', $taskText)) {
        $tests[] = 'Test search and filters with exact, partial, empty, and no-result values';
        $tests[] = 'Verify sorting and pagination remain consistent after filtering';
    }
    if (preg_match('/notification|email|message|alert|reminder/', $taskText)) {
        $tests[] = 'Verify notifications use the correct recipient, content, link, and timing';
        $tests[] = 'Confirm retrying an action does not create duplicate notifications';
    }

    return array_values(array_unique(array_map(
        static fn(string $test): string => trim($test),
        $tests
    )));
}

function qaEvortixRisk(array $task, int $previousReturns, int $issueCount): array
{
    $score = match (strtolower((string) ($task['priority'] ?? 'medium'))) {
        'critical' => 35,
        'high' => 25,
        'medium' => 10,
        default => 5,
    };
    $reasons = [ucfirst((string) ($task['priority'] ?? 'medium')) . ' priority'];
    $deadline = trim((string) ($task['deadline'] ?? ''));
    if ($deadline !== '') {
        $daysRemaining = (int) floor((strtotime($deadline) - strtotime(date('Y-m-d'))) / 86400);
        if ($daysRemaining < 0) {
            $score += 30;
            $reasons[] = 'deadline is overdue';
        } elseif ($daysRemaining <= 2) {
            $score += 20;
            $reasons[] = 'deadline is within 2 days';
        } elseif ($daysRemaining <= 5) {
            $score += 10;
            $reasons[] = 'deadline is within 5 days';
        }
    }
    if ($previousReturns > 0) {
        $score += min(25, 10 + ($previousReturns * 5));
        $reasons[] = $previousReturns . ' previous QA return' . ($previousReturns === 1 ? '' : 's');
    }
    if ($issueCount > 0) {
        $score += min(25, 10 + ($issueCount * 5));
        $reasons[] = $issueCount . ' current checklist issue' . ($issueCount === 1 ? '' : 's');
    }
    $score = min(100, $score);

    return [
        'score' => $score,
        'level' => $score >= 60 ? 'High' : ($score >= 30 ? 'Medium' : 'Low'),
        'reasons' => $reasons,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId = (int) ($_POST['task_id'] ?? 0);

    $taskStmt = $conn->prepare("
        SELECT t.id, t.title, t.description, t.priority, t.deadline, t.status, t.assigned_to,
               t.project_id, p.name AS project_name, p.requested_features
        FROM tasks t
        JOIN projects p ON p.id = t.project_id
        WHERE t.id = ? AND t.status = 'qa_testing'
        LIMIT 1
    ");
    $taskStmt->bind_param('i', $taskId);
    $taskStmt->execute();
    $task = $taskStmt->get_result()->fetch_assoc();
    $taskStmt->close();

    if (!$task) {
        $_SESSION['error'] = 'Only tasks currently in QA Testing can be updated by QA.';
        header('Location: dashboard.php');
        exit();
    }

    if (isset($_POST['apply_evortix_qa_suggestions'])) {
        header('Content-Type: application/json; charset=UTF-8');
        $suggestions = qaEvortixSuggestedTests($task);
        $existingItems = [];
        $existingStmt = $conn->prepare('SELECT item_text FROM qa_checklist_items WHERE task_id = ?');
        $existingStmt->bind_param('i', $taskId);
        $existingStmt->execute();
        $existingResult = $existingStmt->get_result();
        while ($existingItem = $existingResult->fetch_assoc()) {
            $existingItems[strtolower(trim((string) ($existingItem['item_text'] ?? '')))] = true;
        }
        $existingStmt->close();

        $inserted = 0;
        $insertStmt = $conn->prepare('INSERT INTO qa_checklist_items (task_id, feature_name, item_text, developer_id) VALUES (?, ?, ?, ?)');
        foreach ($suggestions as $suggestion) {
            $normalizedSuggestion = strtolower(trim($suggestion));
            if ($normalizedSuggestion === '' || isset($existingItems[$normalizedSuggestion])) {
                continue;
            }
            $insertStmt->bind_param('issi', $taskId, $task['title'], $suggestion, $task['assigned_to']);
            $insertStmt->execute();
            $existingItems[$normalizedSuggestion] = true;
            $inserted++;
        }
        $insertStmt->close();

        echo json_encode([
            'success' => true,
            'inserted' => $inserted,
            'message' => $inserted > 0
                ? $inserted . ' Evortix test suggestion' . ($inserted === 1 ? '' : 's') . ' added.'
                : 'All Evortix test suggestions are already in the checklist.',
        ]);
        exit();
    }

    if (isset($_POST['add_checklist_item'])) {
        $featureName = trim((string) ($_POST['feature_name'] ?? ''));
        $itemText = trim((string) ($_POST['item_text'] ?? ''));
        if ($featureName === '' || $itemText === '') {
            $_SESSION['error'] = 'Feature/module and checklist item are required.';
        } else {
            $stmt = $conn->prepare('INSERT INTO qa_checklist_items (task_id, feature_name, item_text, developer_id) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('issi', $taskId, $featureName, $itemText, $task['assigned_to']);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success'] = 'Checklist item added.';
        }
        header('Location: dashboard.php?task_id=' . $taskId);
        exit();
    }

    $itemStatuses = $_POST['item_status'] ?? [];
    $itemNotes = $_POST['item_notes'] ?? [];
    if (is_array($itemStatuses)) {
        $updateStmt = $conn->prepare("UPDATE qa_checklist_items SET status = ?, notes = ?, qa_user_id = ?, reviewed_at = NOW() WHERE id = ? AND task_id = ?");
        foreach ($itemStatuses as $itemId => $itemStatus) {
            $itemId = (int) $itemId;
            $status = in_array($itemStatus, ['pending', 'passed', 'failed', 'needs_revision'], true) ? $itemStatus : 'pending';
            $notes = trim((string) ($itemNotes[$itemId] ?? ''));
            $updateStmt->bind_param('ssiii', $status, $notes, $user['id'], $itemId, $taskId);
            $updateStmt->execute();
        }
        $updateStmt->close();
    }

    if (isset($_POST['save_checklist'])) {
        $_SESSION['success'] = 'QA checklist saved.';
        header('Location: dashboard.php?task_id=' . $taskId);
        exit();
    }

    if (isset($_POST['qa_decision'])) {
        $decision = (string) ($_POST['decision'] ?? '');
        $qaComment = trim((string) ($_POST['qa_comment'] ?? ''));
        $decisionCounts = ['total' => 0, 'pending' => 0, 'failed' => 0, 'needs_revision' => 0];
        $decisionItemsStmt = $conn->prepare('SELECT status FROM qa_checklist_items WHERE task_id = ?');
        $decisionItemsStmt->bind_param('i', $taskId);
        $decisionItemsStmt->execute();
        $decisionItems = $decisionItemsStmt->get_result();
        while ($decisionItem = $decisionItems->fetch_assoc()) {
            $itemStatus = (string) ($decisionItem['status'] ?? 'pending');
            $decisionCounts['total']++;
            if (isset($decisionCounts[$itemStatus])) {
                $decisionCounts[$itemStatus]++;
            }
        }
        $decisionItemsStmt->close();

        if (
            $decision === 'passed'
            && (
                $decisionCounts['total'] === 0
                || $decisionCounts['pending'] > 0
                || $decisionCounts['failed'] > 0
                || $decisionCounts['needs_revision'] > 0
            )
        ) {
            $_SESSION['error'] = 'QA cannot pass this feature until every checklist item is marked Passed.';
            header('Location: dashboard.php?task_id=' . $taskId);
            exit();
        }
        if ($decision === 'failed' && ($decisionCounts['failed'] === 0 || $qaComment === '')) {
            $_SESSION['error'] = 'Mark at least one checklist item Failed and provide a QA decision comment.';
            header('Location: dashboard.php?task_id=' . $taskId);
            exit();
        }
        if (
            $decision === 'needs_revision'
            && (
                ($decisionCounts['failed'] + $decisionCounts['needs_revision']) === 0
                || $qaComment === ''
            )
        ) {
            $_SESSION['error'] = 'Mark at least one issue item and provide a QA decision comment.';
            header('Location: dashboard.php?task_id=' . $taskId);
            exit();
        }

        if ($decision === 'passed') {
            $stmt = $conn->prepare("UPDATE tasks SET status = 'qa_passed', qa_reviewed_by = ?, qa_reviewed_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $user['id'], $taskId);
            $stmt->execute();
            $stmt->close();

            workflowAddTaskComment($conn, $taskId, (int) $user['id'], $qaComment !== '' ? 'QA passed: ' . $qaComment : 'QA passed this feature.');
            workflowLogStatusChange((int) $user['id'], $taskId, 'qa_testing', 'qa_passed', 'qa_pass_feature', $qaComment);
            workflowNotifyRoles(
                $conn,
                ['architect'],
                'QA Passed: ' . $task['title'],
                'QA passed the feature. Architect final review and merge can begin.',
                BASE_URL . 'modules/architect/final-approval.php?task_id=' . $taskId,
                $taskId
            );
            projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));
            $_SESSION['success'] = 'Feature passed QA and moved to Architect approval.';
        } elseif (in_array($decision, ['failed', 'needs_revision'], true)) {
            $stmt = $conn->prepare("UPDATE tasks SET status = 'qa_failed', completed_at = NULL, qa_reviewed_by = ?, qa_reviewed_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $user['id'], $taskId);
            $stmt->execute();
            $stmt->close();

            $label = $decision === 'failed' ? 'QA failed' : 'QA needs revision';
            workflowAddTaskComment($conn, $taskId, (int) $user['id'], $label . ': ' . ($qaComment !== '' ? $qaComment : 'Please review failed checklist items.'));
            workflowLogStatusChange((int) $user['id'], $taskId, 'qa_testing', 'qa_failed', 'qa_reject_feature', $qaComment);
            workflowNotifyUser(
                (int) $task['assigned_to'],
                $label . ': ' . $task['title'],
                'QA returned this feature to Developer. ' . ($qaComment !== '' ? 'Comment: ' . $qaComment : 'Review the QA checklist for failed items.'),
                BASE_URL . 'modules/developer/task.php?id=' . $taskId,
                $taskId
            );
            projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));
            $_SESSION['success'] = 'Feature returned to Developer for fixing.';
        } else {
            $_SESSION['error'] = 'Invalid QA decision.';
            header('Location: dashboard.php?task_id=' . $taskId);
            exit();
        }

        header('Location: dashboard.php');
        exit();
    }
}

$queue = $conn->query("
    SELECT t.*, p.name AS project_name, u.full_name AS developer_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status = 'qa_testing'
    ORDER BY
        CASE t.priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,
        CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END,
        t.deadline ASC,
        t.sul_reviewed_at ASC,
        t.updated_at ASC
");

$selectedTaskId = (int) ($_GET['task_id'] ?? 0);
$selectedTask = null;
if ($selectedTaskId <= 0 && $queue && $queue->num_rows > 0) {
    $first = $queue->fetch_assoc();
    $selectedTaskId = (int) $first['id'];
    $queue->data_seek(0);
}

if ($selectedTaskId > 0) {
    $selectedStmt = $conn->prepare("
        SELECT t.*, p.name AS project_name, p.requested_features, u.full_name AS developer_name
        FROM tasks t
        JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE t.id = ? AND t.status = 'qa_testing'
        LIMIT 1
    ");
    $selectedStmt->bind_param('i', $selectedTaskId);
    $selectedStmt->execute();
    $selectedTask = $selectedStmt->get_result()->fetch_assoc();
    $selectedStmt->close();

    if ($selectedTask) {
        workflowEnsureDefaultQaChecklist($conn, $selectedTask);
    }
}

$checklistByFeature = [];
if ($selectedTask) {
    $itemsStmt = $conn->prepare('SELECT * FROM qa_checklist_items WHERE task_id = ? ORDER BY feature_name ASC, id ASC');
    $itemsStmt->bind_param('i', $selectedTaskId);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result();
    while ($item = $items->fetch_assoc()) {
        $checklistByFeature[(string) $item['feature_name']][] = $item;
    }
    $itemsStmt->close();
}

$recentResults = $conn->query("
    SELECT t.id, t.title, t.status, p.name AS project_name, u.full_name AS developer_name, t.qa_reviewed_at
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status IN ('qa_passed', 'qa_failed', 'architect_review', 'completed')
      AND t.qa_reviewed_at IS NOT NULL
    ORDER BY t.qa_reviewed_at DESC
    LIMIT 8
");

$qaQueueCount = $queue ? (int) $queue->num_rows : 0;
$qaPassedCount = (int) $conn->query("SELECT COUNT(*) AS count FROM tasks WHERE status IN ('qa_passed', 'architect_review', 'completed') AND qa_reviewed_at IS NOT NULL")->fetch_assoc()['count'];
$qaReturnedCount = (int) $conn->query("SELECT COUNT(*) AS count FROM tasks WHERE status = 'qa_failed' AND qa_reviewed_at IS NOT NULL")->fetch_assoc()['count'];
$qaReviewedThisMonthQuery = (defined('DB_DRIVER') && DB_DRIVER === 'pgsql')
    ? "SELECT COUNT(*) AS count FROM tasks WHERE qa_reviewed_at IS NOT NULL AND qa_reviewed_at >= DATE_TRUNC('month', CURRENT_DATE)"
    : "SELECT COUNT(*) AS count FROM tasks WHERE qa_reviewed_at IS NOT NULL AND qa_reviewed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
$qaReviewedThisMonth = (int) $conn->query($qaReviewedThisMonthQuery)->fetch_assoc()['count'];
$selectedChecklistTotal = 0;
$selectedChecklistPassed = 0;
$selectedChecklistIssues = 0;
$selectedChecklistPending = 0;
$selectedChecklistFailed = 0;
$selectedChecklistNeedsRevision = 0;
$selectedChecklistSignatureParts = [];
foreach ($checklistByFeature as $items) {
    foreach ($items as $item) {
        $selectedChecklistTotal++;
        $itemStatus = (string) ($item['status'] ?? 'pending');
        $selectedChecklistSignatureParts[] = (int) ($item['id'] ?? 0) . ':' . $itemStatus . ':' . trim((string) ($item['notes'] ?? ''));
        if ($itemStatus === 'passed') {
            $selectedChecklistPassed++;
        }
        if ($itemStatus === 'pending') {
            $selectedChecklistPending++;
        }
        if ($itemStatus === 'failed') {
            $selectedChecklistFailed++;
        }
        if ($itemStatus === 'needs_revision') {
            $selectedChecklistNeedsRevision++;
        }
        if (in_array($itemStatus, ['failed', 'needs_revision'], true)) {
            $selectedChecklistIssues++;
        }
    }
}
$selectedChecklistProgress = $selectedChecklistTotal > 0 ? (int) round(($selectedChecklistPassed / $selectedChecklistTotal) * 100) : 0;

$qaEvortixPayload = null;
if ($selectedTask) {
    $previousReturns = 0;
    $returnStmt = $conn->prepare("
        SELECT COUNT(*) AS return_count
        FROM task_comments tc
        INNER JOIN users u ON u.id = tc.user_id
        WHERE tc.task_id = ? AND u.role = 'qa'
          AND (tc.comment LIKE 'QA failed:%' OR tc.comment LIKE 'QA needs revision:%')
    ");
    if ($returnStmt) {
        $returnStmt->bind_param('i', $selectedTaskId);
        $returnStmt->execute();
        $previousReturns = (int) (($returnStmt->get_result()->fetch_assoc()['return_count'] ?? 0));
        $returnStmt->close();
    }

    $suggestedTests = qaEvortixSuggestedTests($selectedTask);
    $existingTestMap = [];
    foreach ($checklistByFeature as $items) {
        foreach ($items as $item) {
            $existingTestMap[strtolower(trim((string) ($item['item_text'] ?? '')))] = true;
        }
    }
    $missingTests = array_values(array_filter(
        $suggestedTests,
        static fn(string $suggestion): bool => !isset($existingTestMap[strtolower(trim($suggestion))])
    ));
    $risk = qaEvortixRisk($selectedTask, $previousReturns, $selectedChecklistIssues);

    if ($selectedChecklistFailed > 0) {
        $recommendedVerdict = 'Failed';
        $verdictReason = 'At least one checklist item failed.';
    } elseif ($selectedChecklistNeedsRevision > 0) {
        $recommendedVerdict = 'Needs Revision';
        $verdictReason = 'The checklist contains items that require developer revision.';
    } elseif ($selectedChecklistTotal > 0 && $selectedChecklistPending === 0) {
        $recommendedVerdict = 'Passed';
        $verdictReason = 'Every recorded checklist item passed.';
    } else {
        $recommendedVerdict = 'Continue Testing';
        $verdictReason = $selectedChecklistPending . ' checklist item' . ($selectedChecklistPending === 1 ? '' : 's') . ' still pending.';
    }

    $draftComment = match ($recommendedVerdict) {
        'Failed' => 'QA failed this feature. ' . $selectedChecklistFailed . ' check'
            . ($selectedChecklistFailed === 1 ? '' : 's') . ' failed. Review the failed checklist notes, reproduce each issue, and submit the corrected implementation for QA retesting.',
        'Needs Revision' => 'QA needs revision. ' . $selectedChecklistNeedsRevision . ' checklist item'
            . ($selectedChecklistNeedsRevision === 1 ? '' : 's') . ' require changes. Please address the recorded notes and confirm the affected regression paths before resubmission.',
        'Passed' => 'QA passed this feature. All ' . $selectedChecklistTotal
            . ' recorded checks passed, including the focused regression coverage.',
        default => 'QA testing is still in progress. Complete the remaining ' . $selectedChecklistPending
            . ' pending check' . ($selectedChecklistPending === 1 ? '' : 's') . ' before making the final QA decision.',
    };

    $qaEvortixPayload = [
        'task_id' => $selectedTaskId,
        'task_title' => (string) ($selectedTask['title'] ?? 'Feature'),
        'project_name' => (string) ($selectedTask['project_name'] ?? ''),
        'risk' => $risk,
        'suggested_tests' => $suggestedTests,
        'missing_tests' => $missingTests,
        'recommended_verdict' => $recommendedVerdict,
        'verdict_reason' => $verdictReason,
        'draft_comment' => $draftComment,
        'counts' => [
            'total' => $selectedChecklistTotal,
            'passed' => $selectedChecklistPassed,
            'pending' => $selectedChecklistPending,
            'failed' => $selectedChecklistFailed,
            'needs_revision' => $selectedChecklistNeedsRevision,
        ],
        'triggers' => [
            'task_opened',
            'checklist_changed',
            'manual_request',
        ],
        'version' => hash('sha256', implode('|', [
            $selectedTaskId,
            (string) ($selectedTask['updated_at'] ?? ''),
            (string) ($selectedTask['priority'] ?? ''),
            (string) ($selectedTask['deadline'] ?? ''),
            implode(',', $selectedChecklistSignatureParts),
            implode(',', $missingTests),
        ])),
    ];
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --gray-50: #f8f9fa;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

.qa-dashboard .card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 20px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.qa-dashboard .card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.qa-dashboard .card-header {
    background: #fff;
    border-bottom: 1px solid var(--gray-200);
    padding: 1.2rem 1.5rem;
}

.qa-dashboard .card-header h5 {
    align-items: center;
    color: var(--gray-900);
    display: flex;
    font-size: 1rem;
    font-weight: 700;
    gap: 0.5rem;
    margin: 0;
}

.qa-dashboard .card-header h5 i,
.qa-stat-icon {
    color: var(--theme-primary);
}

.qa-overview-badge {
    background-color: var(--theme-primary) !important;
    color: #fff !important;
}

.qa-stat-card {
    min-height: 158px;
}

.qa-stat-icon {
    font-size: 2.4rem;
    opacity: 0.65;
}

.qa-stat-label {
    color: var(--gray-600);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.4px;
    margin-bottom: 0.45rem;
    text-transform: uppercase;
}

.qa-stat-number {
    font-size: 2.45rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 0.35rem;
}

.qa-stat-sub,
.qa-meta {
    color: var(--gray-600);
    font-size: 0.76rem;
}

.qa-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem 0.85rem;
}

.qa-queue-list {
    display: grid;
    gap: 0.75rem;
}

.qa-queue-item {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    color: inherit;
    display: block;
    padding: 1rem;
    text-decoration: none;
    transition: all 0.18s ease;
}

.qa-queue-item:hover,
.qa-queue-item.active {
    background: rgba(31, 99, 146, 0.07);
    border-color: rgba(31, 99, 146, 0.28);
    color: inherit;
}

.qa-queue-item.active {
    box-shadow: inset 4px 0 0 var(--theme-primary);
}

.qa-queue-title,
.qa-check-title {
    color: var(--gray-900);
    font-weight: 700;
}

.qa-queue-title {
    font-size: 0.94rem;
    margin-bottom: 0.35rem;
}

.qa-check-summary {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 1rem;
}

.qa-progress {
    background: var(--gray-200);
    border-radius: 999px;
    height: 8px;
    overflow: hidden;
}

.qa-progress-bar {
    background: linear-gradient(90deg, #10b981 0%, var(--theme-primary) 100%);
    height: 100%;
}

.qa-feature-block {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    margin-bottom: 1rem;
    overflow: hidden;
}

.qa-feature-header {
    align-items: center;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    gap: 0.75rem;
    justify-content: space-between;
    padding: 0.9rem 1rem;
}

.qa-feature-title {
    color: var(--theme-primary);
    font-size: 0.92rem;
    font-weight: 800;
    margin: 0;
}

.qa-check-item {
    border-bottom: 1px solid var(--gray-200);
    padding: 1rem;
}

.qa-check-item:last-child,
.qa-result-item:last-child {
    border-bottom: none;
}

.qa-check-title {
    font-size: 0.9rem;
    margin-bottom: 0.75rem;
}

.qa-result-item {
    border-bottom: 1px solid var(--gray-200);
    padding: 0.9rem 0;
}

.qa-result-item:first-child {
    padding-top: 0;
}

.qa-empty {
    color: var(--gray-600);
    padding: 2.5rem 1.5rem;
    text-align: center;
}

.qa-empty i {
    color: var(--gray-300);
    font-size: 2.6rem;
    margin-bottom: 0.8rem;
}

.btn-qa-primary,
.btn-qa-primary:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border: none;
    color: #fff;
}

.btn-qa-primary:hover {
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.25);
    transform: translateY(-1px);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-dashboard,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-dashboard .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-stat-card {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-dashboard .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-check-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-feature-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-queue-item:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-queue-item.active {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-queue-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-feature-block,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-check-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-result-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) hr {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-dashboard .card-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-queue-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-check-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h1,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h3,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-stat-sub,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-empty,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-progress {
    background: rgba(157, 182, 207, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select {
    background-color: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control::placeholder {
    color: #7f9bb6;
}

@media (max-width: 767.98px) {
    .qa-dashboard .card-header,
    .qa-dashboard .card-body {
        padding: 1rem;
    }

    .qa-stat-number {
        font-size: 2rem;
    }
}
</style>

<div class="container-fluid px-4 py-4 qa-dashboard role-dashboard-shell">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm role-dashboard-hero">
                <div class="card-body p-4">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-8 role-dashboard-hero-copy">
                            <span class="badge qa-overview-badge mb-2 px-3 py-2">QA Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars((string) $user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Review SUL-approved work, manage checklist coverage, and return clear findings to the right team.</p>
                        </div>
                        <div class="col-lg-4 d-none d-lg-flex justify-content-end">
                            <div class="role-3d-scene role-3d-scene--qa" aria-hidden="true">
                                <div class="role-3d-platform">
                                    <span class="role-3d-node role-3d-node--one"><i class="fas fa-bug"></i></span>
                                    <span class="role-3d-node role-3d-node--two"><i class="fas fa-check-circle"></i></span>
                                    <span class="role-3d-orbit"></span>
                                    <span class="role-3d-core"><i class="fas fa-clipboard-check"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="row g-3 mb-4 role-stats-row">
        <div class="col-xl-3 col-md-6">
            <div class="card qa-stat-card h-100">
                <div class="card-body p-4 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="qa-stat-label">In QA Queue</div>
                        <div class="qa-stat-number" style="color: #1f6392;"><?php echo $qaQueueCount; ?></div>
                        <div class="qa-stat-sub">Ready for testing</div>
                    </div>
                    <i class="fas fa-clipboard-check qa-stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card qa-stat-card h-100">
                <div class="card-body p-4 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="qa-stat-label">Passed</div>
                        <div class="qa-stat-number" style="color: #10b981;"><?php echo $qaPassedCount; ?></div>
                        <div class="qa-stat-sub">Moved forward after QA</div>
                    </div>
                    <i class="fas fa-circle-check qa-stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card qa-stat-card h-100">
                <div class="card-body p-4 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="qa-stat-label">Returned</div>
                        <div class="qa-stat-number" style="color: #dc2626;"><?php echo $qaReturnedCount; ?></div>
                        <div class="qa-stat-sub">Sent back for fixes</div>
                    </div>
                    <i class="fas fa-rotate-left qa-stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card qa-stat-card h-100">
                <div class="card-body p-4 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="qa-stat-label">This Month</div>
                        <div class="qa-stat-number" style="color: #f59e0b;"><?php echo $qaReviewedThisMonth; ?></div>
                        <div class="qa-stat-sub">QA decisions recorded</div>
                    </div>
                    <i class="fas fa-calendar-check qa-stat-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-list-check"></i> QA Queue</h5>
                </div>
                <div class="card-body">
                    <div class="qa-queue-list">
                    <?php if($queue && $queue->num_rows > 0): ?>
                        <?php while($task = $queue->fetch_assoc()): ?>
                            <a href="dashboard.php?task_id=<?php echo (int) $task['id']; ?>" class="qa-queue-item <?php echo (int) $task['id'] === $selectedTaskId ? 'active' : ''; ?>">
                                <div class="qa-queue-title"><?php echo htmlspecialchars((string) $task['title']); ?></div>
                                <small><?php echo htmlspecialchars((string) $task['project_name']); ?> · <?php echo htmlspecialchars((string) ($task['developer_name'] ?? 'Unassigned')); ?></small>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="qa-empty">
                            <i class="fas fa-check-circle"></i>
                            <p class="mb-0">No pull requests approved for QA yet.</p>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-clock-rotate-left"></i> Recent QA Results</h5>
                </div>
                <div class="card-body">
                    <?php if($recentResults && $recentResults->num_rows > 0): ?>
                        <?php while($result = $recentResults->fetch_assoc()): ?>
                            <div class="qa-result-item">
                                <div class="fw-semibold"><?php echo htmlspecialchars((string) $result['title']); ?></div>
                                <small class="text-muted"><?php echo workflowStatusLabel((string) $result['status']); ?> · <?php echo htmlspecialchars((string) $result['developer_name']); ?></small>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="qa-empty py-3">
                            <i class="fas fa-inbox"></i>
                            <p class="mb-0">No QA results yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <?php if($selectedTask): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <div>
                                <h5 class="mb-1"><i class="fas fa-vial-circle-check"></i><?php echo htmlspecialchars((string) $selectedTask['title']); ?></h5>
                                <small class="text-muted"><?php echo htmlspecialchars((string) $selectedTask['project_name']); ?> · Developer: <?php echo htmlspecialchars((string) ($selectedTask['developer_name'] ?? 'Unassigned')); ?></small>
                            </div>
                            <div class="d-flex gap-2 align-items-start flex-wrap">
                                <button type="button" class="btn btn-outline-info btn-sm" id="askEvortixQa">
                                    <i class="fas fa-robot me-1"></i> Ask Evortix
                                </button>
                                <a class="btn btn-outline-primary btn-sm" href="<?php echo BASE_URL; ?>modules/version-control/repositories.php?project_id=<?php echo (int) $selectedTask['project_id']; ?>">
                                    <i class="fas fa-code-branch me-1"></i> Version Control
                                </a>
                                <span class="badge bg-warning text-dark align-self-start">QA Testing</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="qa-check-summary mb-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <div>
                                    <div class="fw-bold">Checklist Progress</div>
                                    <small class="text-muted"><?php echo $selectedChecklistPassed; ?> of <?php echo $selectedChecklistTotal; ?> items passed<?php echo $selectedChecklistIssues > 0 ? ' | ' . $selectedChecklistIssues . ' issue item' . ($selectedChecklistIssues === 1 ? '' : 's') : ''; ?></small>
                                </div>
                                <span class="badge bg-primary"><?php echo $selectedChecklistProgress; ?>%</span>
                            </div>
                            <div class="qa-progress">
                                <div class="qa-progress-bar" style="width: <?php echo $selectedChecklistProgress; ?>%;"></div>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="task_id" value="<?php echo (int) $selectedTask['id']; ?>">
                            <?php foreach($checklistByFeature as $featureName => $items): ?>
                                <div class="mb-4">
                                    <h6 class="fw-bold text-primary">Feature: <?php echo htmlspecialchars($featureName); ?></h6>
                                    <?php foreach($items as $item): ?>
                                        <div class="border rounded-3 p-3 mb-2 bg-light">
                                            <div class="fw-semibold mb-2"><?php echo htmlspecialchars((string) $item['item_text']); ?></div>
                                            <div class="row g-2">
                                                <div class="col-md-4">
                                                    <select name="item_status[<?php echo (int) $item['id']; ?>]" class="form-select">
                                                        <?php foreach(['pending' => 'Pending', 'passed' => 'Passed', 'failed' => 'Failed', 'needs_revision' => 'Needs Revision'] as $value => $label): ?>
                                                            <option value="<?php echo $value; ?>" <?php echo (string) $item['status'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-8">
                                                    <input name="item_notes[<?php echo (int) $item['id']; ?>]" class="form-control" value="<?php echo htmlspecialchars((string) ($item['notes'] ?? '')); ?>" placeholder="Notes / issue details">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">QA Decision Comment</label>
                                <textarea name="qa_comment" class="form-control" rows="3" placeholder="Summarize pass/fail findings for the developer or architect"></textarea>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" name="save_checklist" class="btn btn-outline-primary">Save Checklist</button>
                                <button type="submit" name="qa_decision" value="1" onclick="this.form.decision.value='passed'" class="btn btn-success">Passed</button>
                                <button type="submit" name="qa_decision" value="1" onclick="this.form.decision.value='failed'" class="btn btn-danger">Failed</button>
                                <button type="submit" name="qa_decision" value="1" onclick="this.form.decision.value='needs_revision'" class="btn btn-warning">Needs Revision</button>
                                <input type="hidden" name="decision" value="">
                            </div>
                        </form>

                        <hr>
                        <form method="POST" class="row g-2 align-items-end">
                            <input type="hidden" name="task_id" value="<?php echo (int) $selectedTask['id']; ?>">
                            <div class="col-md-4">
                                <label class="form-label">Feature / Module</label>
                                <input name="feature_name" class="form-control" value="<?php echo htmlspecialchars((string) $selectedTask['title']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Checklist Item</label>
                                <input name="item_text" class="form-control" placeholder="Example: Password validation works">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="add_checklist_item" class="btn btn-primary w-100">Add</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fas fa-check-circle fa-3x mb-3"></i>
                        <p class="mb-0">Nothing to test right now. QA will be notified after SUL approval.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($qaEvortixPayload): ?>
<style>
body.qa-evortix-page .chat-bot-speech.evortix-qa-advisor {
    top: auto;
    right: calc(100% + 8px);
    bottom: 22px;
    width: min(390px, calc(100vw - 180px));
    min-width: 310px;
    max-height: min(500px, calc(100vh - 100px));
    padding: 0.9rem;
    overflow: hidden;
    pointer-events: auto;
    transform: none;
}

body.qa-evortix-page .chat-bot-float.is-speaking .chat-bot-speech.evortix-qa-advisor {
    animation: none;
    opacity: 1;
    transform: none;
    visibility: visible;
}

body.qa-evortix-page .chat-bot-speech.evortix-qa-advisor::after {
    top: auto;
    bottom: 48px;
    transform: rotate(45deg);
}

.evortix-qa-head,
.evortix-qa-title-row,
.evortix-qa-actions,
.evortix-qa-test {
    display: flex;
    align-items: center;
}

.evortix-qa-head,
.evortix-qa-title-row {
    justify-content: space-between;
    gap: 0.65rem;
}

.evortix-qa-eyebrow {
    color: #157baa;
    font-size: 0.6rem;
    font-weight: 850;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.chat-bot-speech .evortix-qa-title {
    min-height: 0;
    padding: 0;
    color: #083a5d;
    font-size: 0.95rem;
    line-height: 1.3;
}

.evortix-qa-close {
    display: grid;
    place-items: center;
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.72);
    color: #116c9e;
}

.evortix-qa-copy {
    margin: 0.45rem 0 0;
    color: #3f6e8d;
    font-size: 0.69rem;
    line-height: 1.42;
}

.evortix-qa-summary {
    display: grid;
    grid-template-columns: 0.8fr 1.2fr;
    gap: 0.45rem;
    margin-top: 0.6rem;
}

.evortix-qa-panel {
    padding: 0.55rem 0.6rem;
    border: 1px solid rgba(8, 123, 185, 0.18);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.68);
}

.evortix-qa-panel span,
.evortix-qa-test small {
    color: #4c7691;
    font-size: 0.58rem;
    font-weight: 750;
}

.evortix-qa-panel strong {
    display: block;
    margin-top: 0.18rem;
    color: #083a5d;
    font-size: 0.76rem;
    line-height: 1.25;
}

.evortix-qa-risk-high strong { color: #b42318; }
.evortix-qa-risk-medium strong { color: #b25e09; }
.evortix-qa-risk-low strong { color: #087443; }

.evortix-qa-tests {
    display: grid;
    gap: 0.35rem;
    max-height: 210px;
    margin-top: 0.6rem;
    padding-right: 0.2rem;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.evortix-qa-test {
    align-items: flex-start;
    gap: 0.45rem;
    padding: 0.46rem 0.5rem;
    border: 1px solid rgba(8, 123, 185, 0.14);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.56);
    color: #164f73;
    font-size: 0.65rem;
    line-height: 1.35;
}

.evortix-qa-test i {
    margin-top: 0.12rem;
    color: #1492c6;
}

.evortix-qa-test.is-added i {
    color: #149657;
}

.evortix-qa-verdict-reason {
    margin-top: 0.5rem;
    padding: 0.5rem 0.6rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.55);
    color: #356b8d;
    font-size: 0.64rem;
    line-height: 1.4;
}

.evortix-qa-actions {
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.6rem;
}

.evortix-qa-actions button {
    min-height: 32px;
    padding: 0.38rem 0.65rem;
    border: 1px solid rgba(31, 99, 146, 0.24);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.78);
    color: #175f8f;
    font-size: 0.64rem;
    font-weight: 800;
}

.evortix-qa-actions button.is-primary {
    border-color: #1877b1;
    background: #1877b1;
    color: #fff;
}

@media (max-width: 600px) {
    body.qa-evortix-page .chat-bot-speech.evortix-qa-advisor {
        right: calc(100% + 4px);
        bottom: 18px;
        width: min(285px, calc(100vw - 132px));
        min-width: 225px;
    }

    .evortix-qa-summary {
        grid-template-columns: 1fr;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const qaEvortixBase = <?php echo json_encode($qaEvortixPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    if (!qaEvortixBase) return;
    document.body.classList.add('qa-evortix-page');

    const escapeQaHtml = (value) => {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    };
    const qaStateKey = `evortix.qa.advisor.v1.${qaEvortixBase.task_id}.${qaEvortixBase.version}`;
    let checklistChangeTimer = null;

    const currentQaGuidance = () => {
        const counts = { total: 0, passed: 0, pending: 0, failed: 0, needs_revision: 0 };
        const issueDetails = [];
        document.querySelectorAll('select[name^="item_status["]').forEach((select) => {
            const status = String(select.value || 'pending');
            counts.total++;
            if (Object.prototype.hasOwnProperty.call(counts, status)) counts[status]++;
            if (status === 'failed' || status === 'needs_revision') {
                const itemRow = select.closest('.border');
                issueDetails.push({
                    status,
                    check: String(itemRow?.querySelector('.fw-semibold')?.textContent || 'Checklist issue').trim(),
                    actual: String(itemRow?.querySelector('input[name^="item_notes["]')?.value || '').trim(),
                });
            }
        });

        let verdict = 'Continue Testing';
        let reason = `${counts.pending} checklist item${counts.pending === 1 ? '' : 's'} still pending.`;
        if (counts.failed > 0) {
            verdict = 'Failed';
            reason = 'At least one checklist item failed.';
        } else if (counts.needs_revision > 0) {
            verdict = 'Needs Revision';
            reason = 'The checklist contains items that require developer revision.';
        } else if (counts.total > 0 && counts.pending === 0) {
            verdict = 'Passed';
            reason = 'Every recorded checklist item passed.';
        }

        const defectLines = issueDetails.map((issue, issueIndex) =>
            `${issueIndex + 1}. ${issue.check}\nActual: ${issue.actual || 'Add the observed result before submitting this decision.'}`
        ).join('\n');
        const severity = String(qaEvortixBase.risk?.level || 'Medium');
        const draft = verdict === 'Failed'
            ? `QA DEFECT REPORT\nFeature: ${qaEvortixBase.task_title}\nSeverity: ${severity}\n\nFailed checks:\n${defectLines}\n\nExpected: The feature should satisfy the approved requirement and pass the listed checks.\nSteps to reproduce: Repeat each failed checklist scenario using the recorded inputs and environment.\nDeveloper action: Correct the failed behavior, run focused regression tests, and resubmit for QA.`
            : verdict === 'Needs Revision'
                ? `QA REVISION REPORT\nFeature: ${qaEvortixBase.task_title}\nSeverity: ${severity}\n\nIssues requiring revision:\n${defectLines}\n\nExpected: The feature should satisfy the approved requirement without the recorded issue.\nDeveloper action: Address each item, verify related regression paths, and resubmit for QA.`
                : verdict === 'Passed'
                    ? `QA passed this feature. All ${counts.total} recorded checks passed, including the focused regression coverage.`
                    : `QA testing is still in progress. Complete the remaining ${counts.pending} pending check${counts.pending === 1 ? '' : 's'} before making the final QA decision.`;

        const baseIssues = Number(qaEvortixBase.counts?.failed || 0) + Number(qaEvortixBase.counts?.needs_revision || 0);
        const currentIssues = counts.failed + counts.needs_revision;
        const issueRiskPoints = (issueCount) => issueCount > 0 ? Math.min(25, 10 + (issueCount * 5)) : 0;
        const liveRiskScore = Math.max(
            0,
            Math.min(100, Number(qaEvortixBase.risk?.score || 0) - issueRiskPoints(baseIssues) + issueRiskPoints(currentIssues))
        );
        const riskReasons = (qaEvortixBase.risk?.reasons || [])
            .filter((riskReason) => !String(riskReason).includes('current checklist issue'));
        if (currentIssues > 0) {
            riskReasons.push(`${currentIssues} current checklist issue${currentIssues === 1 ? '' : 's'}`);
        }
        const risk = {
            ...(qaEvortixBase.risk || {}),
            score: liveRiskScore,
            level: liveRiskScore >= 60 ? 'High' : (liveRiskScore >= 30 ? 'Medium' : 'Low'),
            reasons: riskReasons,
        };

        return { ...qaEvortixBase, counts, risk, recommended_verdict: verdict, verdict_reason: reason, draft_comment: draft };
    };

    const showQaEvortix = (trigger = 'manual_request') => {
        const payload = currentQaGuidance();
        const bot = document.getElementById('chat-bot-float');
        const speech = bot?.querySelector('.chat-bot-speech');
        if (!bot || !speech) {
            window.setTimeout(() => showQaEvortix(trigger), 300);
            return;
        }

        const missingSet = new Set((payload.missing_tests || []).map((test) => String(test).toLowerCase()));
        const testMarkup = (payload.suggested_tests || []).map((test) => {
            const isMissing = missingSet.has(String(test).toLowerCase());
            return `
                <div class="evortix-qa-test ${isMissing ? '' : 'is-added'}">
                    <i class="fas ${isMissing ? 'fa-flask' : 'fa-circle-check'}" aria-hidden="true"></i>
                    <div>${escapeQaHtml(test)}<small class="d-block">${isMissing ? 'Suggested test' : 'Already in checklist'}</small></div>
                </div>`;
        }).join('');
        const triggerCopy = {
            task_opened: 'I reviewed this task because it was opened for QA.',
            checklist_changed: 'I updated the guidance because a checklist result changed.',
            manual_request: 'Here is the latest QA guidance you requested.',
        }[trigger] || 'Here is the latest QA guidance.';
        const riskClass = `evortix-qa-risk-${String(payload.risk?.level || 'Low').toLowerCase()}`;

        speech.className = 'chat-bot-speech evortix-qa-advisor';
        speech.hidden = false;
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        speech.innerHTML = `
            <div class="evortix-qa-head">
                <div>
                    <div class="evortix-qa-eyebrow">QA recommendation</div>
                    <strong class="evortix-qa-title">${escapeQaHtml(payload.task_title)}</strong>
                </div>
                <button type="button" class="evortix-qa-close" data-qa-evortix-action="close" aria-label="Close Evortix recommendation"><i class="fas fa-xmark"></i></button>
            </div>
            <p class="evortix-qa-copy">${escapeQaHtml(triggerCopy)} QA keeps control of the final decision.</p>
            <div class="evortix-qa-summary">
                <div class="evortix-qa-panel ${riskClass}">
                    <span>Testing risk</span>
                    <strong>${escapeQaHtml(payload.risk?.level || 'Low')} · ${Number(payload.risk?.score || 0)}/100</strong>
                </div>
                <div class="evortix-qa-panel">
                    <span>Recommended verdict</span>
                    <strong>${escapeQaHtml(payload.recommended_verdict)}</strong>
                </div>
            </div>
            <div class="evortix-qa-verdict-reason">${escapeQaHtml(payload.verdict_reason)} ${escapeQaHtml((payload.risk?.reasons || []).join(' · '))}</div>
            <div class="evortix-qa-tests">${testMarkup}</div>
            <div class="evortix-qa-actions">
                <button type="button" data-qa-evortix-action="draft">Use draft comment</button>
                ${(payload.missing_tests || []).length > 0
                    ? `<button type="button" class="is-primary" data-qa-evortix-action="apply-tests">Add ${payload.missing_tests.length} suggested test${payload.missing_tests.length === 1 ? '' : 's'}</button>`
                    : '<button type="button" class="is-primary" data-qa-evortix-action="close">Checklist ready</button>'}
            </div>`;

        bot.hidden = false;
        bot.setAttribute('aria-hidden', 'false');
        bot.classList.remove('is-returning-bed', 'is-preparing-speech');
        bot.classList.add('is-speaking');
        window.chatBotFloatRobot?.wake?.();
        window.chatBotFloatRobot?.setEmotion?.('thinking', 0);
        try { window.localStorage.setItem(qaStateKey, trigger); } catch (error) {}
    };

    document.getElementById('askEvortixQa')?.addEventListener('click', () => showQaEvortix('manual_request'));
    document.querySelectorAll('select[name^="item_status["]').forEach((select) => {
        select.addEventListener('change', () => {
            window.clearTimeout(checklistChangeTimer);
            checklistChangeTimer = window.setTimeout(() => showQaEvortix('checklist_changed'), 350);
        });
    });
    document.querySelectorAll('input[name^="item_notes["]').forEach((input) => {
        input.addEventListener('input', () => {
            window.clearTimeout(checklistChangeTimer);
            checklistChangeTimer = window.setTimeout(() => showQaEvortix('checklist_changed'), 700);
        });
    });

    document.addEventListener('click', async (event) => {
        const actionButton = event.target.closest('[data-qa-evortix-action]');
        if (!actionButton) return;
        const action = actionButton.getAttribute('data-qa-evortix-action');
        if (action === 'close') {
            const speech = document.querySelector('#chat-bot-float .chat-bot-speech');
            if (speech) {
                speech.hidden = true;
                speech.setAttribute('aria-hidden', 'true');
            }
            return;
        }
        if (action === 'draft') {
            const comment = document.querySelector('textarea[name="qa_comment"]');
            if (comment) {
                comment.value = currentQaGuidance().draft_comment;
                comment.dispatchEvent(new Event('input', { bubbles: true }));
                comment.scrollIntoView({ behavior: 'smooth', block: 'center' });
                comment.focus();
                window.appToast?.('Evortix QA draft', 'The recommended QA comment is ready for your review.', 'info');
            }
            return;
        }
        if (action === 'apply-tests') {
            actionButton.disabled = true;
            actionButton.textContent = 'Adding tests...';
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        task_id: String(qaEvortixBase.task_id),
                        apply_evortix_qa_suggestions: '1',
                    }),
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Unable to add the suggested tests.');
                window.appToast?.('Evortix QA checklist', result.message, 'success');
                window.setTimeout(() => {
                    window.location.href = `${window.location.pathname}?task_id=${encodeURIComponent(qaEvortixBase.task_id)}`;
                }, 500);
            } catch (error) {
                actionButton.disabled = false;
                actionButton.textContent = 'Try adding tests again';
                window.appToast?.('Evortix QA checklist', error.message || 'Unable to add the suggested tests.', 'danger');
            }
        }
    });

    let alreadyShown = false;
    try { alreadyShown = window.localStorage.getItem(qaStateKey) !== null; } catch (error) {}
    if (!alreadyShown) window.setTimeout(() => showQaEvortix('task_opened'), 1400);
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
