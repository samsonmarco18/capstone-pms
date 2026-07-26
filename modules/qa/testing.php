<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/project-workflow.php';
requireRole('qa');

$conn = getConnection();
$user = getCurrentUser();
workflowEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId = (int) ($_POST['task_id'] ?? 0);

    $taskStmt = $conn->prepare("
        SELECT t.id, t.title, t.status, t.assigned_to, t.project_id, p.name AS project_name
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
        header('Location: testing.php');
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
        header('Location: testing.php?task_id=' . $taskId);
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
        header('Location: testing.php?task_id=' . $taskId);
        exit();
    }

    if (isset($_POST['qa_decision'])) {
        $decision = (string) ($_POST['decision'] ?? '');
        $qaComment = trim((string) ($_POST['qa_comment'] ?? ''));

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
            header('Location: testing.php?task_id=' . $taskId);
            exit();
        }

        header('Location: testing.php');
        exit();
    }
}

$queue = $conn->query("
    SELECT t.*, p.name AS project_name, u.full_name AS developer_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status = 'qa_testing'
    ORDER BY t.sul_reviewed_at ASC, t.updated_at ASC
");
$queueCount = $queue ? $queue->num_rows : 0;

$selectedTaskId = (int) ($_GET['task_id'] ?? 0);
$selectedTask = null;
if ($selectedTaskId <= 0 && $queue && $queue->num_rows > 0) {
    $first = $queue->fetch_assoc();
    $selectedTaskId = (int) $first['id'];
    $queue->data_seek(0);
}

if ($selectedTaskId > 0) {
    $selectedStmt = $conn->prepare("
        SELECT t.*, p.name AS project_name, u.full_name AS developer_name
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

$selectedChecklistTotal = 0;
$selectedChecklistPassed = 0;
$selectedChecklistIssues = 0;
foreach ($checklistByFeature as $items) {
    foreach ($items as $item) {
        $selectedChecklistTotal++;
        if ((string) $item['status'] === 'passed') {
            $selectedChecklistPassed++;
        }
        if (in_array((string) $item['status'], ['failed', 'needs_revision'], true)) {
            $selectedChecklistIssues++;
        }
    }
}
$selectedChecklistProgress = $selectedChecklistTotal > 0 ? (int) round(($selectedChecklistPassed / $selectedChecklistTotal) * 100) : 0;

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

.qa-dashboard .card:hover {
    transform: none;
}

.qa-page-banner {
    align-items: center;
    background: linear-gradient(135deg, #19567f 0%, #1f6392 55%, #2f83b9 100%);
    border: 1px solid rgba(255, 255, 255, 0.13);
    border-radius: 22px;
    box-shadow: 0 14px 34px rgba(24, 79, 115, 0.16);
    color: #fff;
    display: flex;
    gap: 1.5rem;
    justify-content: space-between;
    margin-bottom: 1.4rem;
    min-height: 148px;
    overflow: hidden;
    padding: 1.75rem 2rem;
    position: relative;
}

.qa-page-banner::before,
.qa-page-banner::after {
    background: rgba(255, 255, 255, 0.09);
    border-radius: 999px;
    content: "";
    pointer-events: none;
    position: absolute;
}

.qa-page-banner::before {
    height: 210px;
    right: -48px;
    top: -105px;
    width: 210px;
}

.qa-page-banner::after {
    bottom: -105px;
    height: 170px;
    right: 145px;
    width: 170px;
}

.qa-page-banner-copy,
.qa-page-banner-actions {
    position: relative;
    z-index: 1;
}

.qa-page-banner-kicker {
    color: rgba(255, 255, 255, 0.76);
    font-size: 0.72rem;
    font-weight: 650;
    letter-spacing: 0.08em;
    margin-bottom: 0.45rem;
    text-transform: uppercase;
}

.qa-page-banner h1 {
    align-items: center;
    display: flex;
    font-size: clamp(1.55rem, 2.2vw, 2rem);
    font-weight: 650;
    gap: 0.75rem;
    letter-spacing: -0.02em;
    margin: 0 0 0.45rem;
}

.qa-page-banner p {
    color: rgba(255, 255, 255, 0.82);
    font-size: 0.95rem;
    margin: 0;
    max-width: 680px;
}

.qa-page-banner-actions {
    align-items: center;
    display: flex;
    gap: 0.65rem;
}

.qa-page-banner-status {
    align-items: center;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.24);
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.82rem;
    gap: 0.5rem;
    padding: 0.58rem 0.85rem;
    white-space: nowrap;
}

.qa-page-banner-status strong {
    font-weight: 700;
}

.qa-workspace {
    align-items: stretch;
    background: #fff;
    border: 1px solid #d8e5ee;
    border-radius: 22px;
    box-shadow: 0 14px 34px rgba(24, 79, 115, 0.09);
    display: grid;
    gap: 0;
    grid-template-columns: minmax(270px, 320px) minmax(0, 1fr);
    overflow: hidden;
}

.qa-workspace-rail {
    align-self: stretch;
    background: #fbfdff;
    border: 0;
    border-right: 1px solid #dbe6ee;
    border-radius: 0;
    box-shadow: none;
    min-width: 0;
    overflow: hidden;
    position: static;
}

.qa-review-panel {
    background: #fff;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    min-width: 0;
    overflow: hidden;
}

.qa-queue-panel-head,
.qa-review-header,
.qa-checklist-head {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.qa-queue-panel-head {
    border-bottom: 1px solid #dbe6ee;
    gap: 1rem;
    padding: 1.15rem 1.2rem;
}

.qa-section-heading {
    align-items: center;
    color: #17354e;
    display: flex;
    font-size: 1rem;
    font-weight: 650;
    gap: 0.65rem;
    margin: 0;
}

.qa-section-heading i {
    color: var(--theme-primary);
}

.qa-queue-count,
.qa-status-badge,
.qa-progress-percent {
    align-items: center;
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 650;
    justify-content: center;
    white-space: nowrap;
}

.qa-queue-count {
    background: #eaf4fa;
    color: var(--theme-primary);
    min-width: 34px;
    padding: 0.35rem 0.6rem;
}

.qa-queue-list {
    display: block;
    max-height: 630px;
    overflow-y: auto;
    padding: 0;
    scrollbar-color: #a9c8db transparent;
    scrollbar-width: thin;
}

.qa-queue-item {
    align-items: center;
    border: 0;
    border-bottom: 1px solid #e0e9ef;
    border-radius: 0;
    display: grid;
    gap: 0.75rem;
    grid-template-columns: 38px minmax(0, 1fr) 20px;
    min-height: 72px;
    padding: 0.75rem 1rem;
}

.qa-queue-item:hover {
    background: #f2f8fc;
    border-color: #e0e9ef;
}

.qa-queue-item.active {
    background: #eaf5fb;
    border-color: #cfe3ef;
    box-shadow: none;
}

.qa-queue-icon {
    align-items: center;
    background: #e7f2f8;
    border-radius: 10px;
    color: var(--theme-primary);
    display: inline-flex;
    height: 38px;
    justify-content: center;
    width: 38px;
}

.qa-queue-item.active .qa-queue-icon {
    background: var(--theme-primary);
    color: #fff;
}

.qa-queue-title {
    font-size: 0.88rem;
    font-weight: 650;
    line-height: 1.25;
    margin: 0 0 0.2rem;
}

.qa-queue-meta {
    color: #71879a;
    display: block;
    font-size: 0.72rem;
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.qa-queue-arrow {
    color: #8ca4b7;
    font-size: 0.75rem;
    text-align: right;
}

.qa-recent-panel {
    border-top: 1px solid #dbe6ee;
}

.qa-recent-panel summary {
    align-items: center;
    color: #294760;
    cursor: pointer;
    display: flex;
    font-size: 0.8rem;
    font-weight: 650;
    gap: 0.55rem;
    justify-content: space-between;
    list-style: none;
    padding: 0.95rem 1.1rem;
}

.qa-recent-panel summary::-webkit-details-marker {
    display: none;
}

.qa-recent-list {
    border-top: 1px solid #e3ebf0;
    padding: 0 1.1rem;
}

.qa-result-item {
    padding: 0.75rem 0;
}

.qa-result-item strong {
    color: #1e3850;
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
}

.qa-result-item span {
    color: #7d91a3;
    font-size: 0.69rem;
}

.qa-review-panel {
    min-width: 0;
}

.qa-review-header {
    border-bottom: 1px solid #dbe6ee;
    gap: 1rem;
    min-height: 86px;
    padding: 1.15rem 1.4rem;
}

.qa-review-title {
    color: #17354e;
    font-size: 1.15rem;
    font-weight: 650;
    line-height: 1.25;
    margin: 0 0 0.3rem;
}

.qa-review-meta {
    align-items: center;
    color: #71879a;
    display: flex;
    flex-wrap: wrap;
    font-size: 0.74rem;
    gap: 0.5rem 1rem;
}

.qa-review-meta span {
    align-items: center;
    display: inline-flex;
    gap: 0.35rem;
}

.qa-review-tools {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
}

.qa-version-link {
    align-items: center;
    border: 1px solid #bfd6e5;
    border-radius: 10px;
    color: var(--theme-primary);
    display: inline-flex;
    font-size: 0.76rem;
    font-weight: 600;
    gap: 0.4rem;
    min-height: 38px;
    padding: 0.55rem 0.75rem;
    text-decoration: none;
}

.qa-version-link:hover {
    background: #eff7fb;
    color: var(--theme-dark);
}

.qa-status-badge {
    background: #fff1c4;
    color: #9a5a00;
    padding: 0.48rem 0.7rem;
}

.qa-progress-strip {
    align-items: center;
    background: #f8fbfd;
    border-bottom: 1px solid #dbe6ee;
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(190px, auto) minmax(160px, 1fr) auto;
    padding: 0.95rem 1.4rem;
}

.qa-progress-label strong {
    color: #203d56;
    display: block;
    font-size: 0.82rem;
    font-weight: 650;
}

.qa-progress-label span {
    color: #71879a;
    font-size: 0.72rem;
}

.qa-progress-percent {
    background: #e6f2f9;
    color: var(--theme-primary);
    min-width: 50px;
    padding: 0.4rem 0.65rem;
}

.qa-review-form {
    padding: 0;
}

.qa-checklist-head {
    border-bottom: 1px solid #dbe6ee;
    gap: 1rem;
    padding: 1rem 1.4rem 0.8rem;
}

.qa-checklist-head h3 {
    color: #17354e;
    font-size: 0.96rem;
    font-weight: 650;
    margin: 0;
}

.qa-checklist-head span {
    color: #71879a;
    font-size: 0.72rem;
}

.qa-checklist-columns,
.qa-check-row {
    display: grid;
    grid-template-columns: minmax(260px, 1.2fr) 170px minmax(230px, 1fr);
}

.qa-checklist-columns {
    background: #f7f9fb;
    border-bottom: 1px solid #dbe6ee;
    color: #667b8e;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.045em;
    padding: 0.7rem 1.4rem;
    text-transform: uppercase;
}

.qa-checklist-columns span:nth-child(n+2) {
    padding-left: 1rem;
}

.qa-check-row {
    align-items: center;
    border-bottom: 1px solid #e0e8ee;
    min-height: 78px;
    padding: 0 1.4rem;
    transition: background 0.16s ease;
}

.qa-check-row:hover {
    background: #f9fcfe;
}

.qa-check-copy {
    align-items: flex-start;
    display: flex;
    gap: 0.7rem;
    min-width: 0;
    padding: 0.9rem 0;
}

.qa-check-state {
    align-items: center;
    background: #eef3f6;
    border-radius: 50%;
    color: #7d91a3;
    display: inline-flex;
    flex: 0 0 auto;
    height: 26px;
    justify-content: center;
    margin-top: 0.05rem;
    width: 26px;
}

.qa-check-row[data-state="passed"] .qa-check-state {
    background: #dcf7e8;
    color: #11884d;
}

.qa-check-row[data-state="failed"] .qa-check-state,
.qa-check-row[data-state="needs_revision"] .qa-check-state {
    background: #fff0e8;
    color: #d35f24;
}

.qa-check-text {
    color: #29445b;
    font-size: 0.82rem;
    font-weight: 500;
    line-height: 1.4;
}

.qa-check-module {
    color: #8496a6;
    display: block;
    font-size: 0.68rem;
    font-weight: 400;
    margin-top: 0.18rem;
}

.qa-check-control {
    border-left: 1px solid #e4ebf0;
    padding: 0.75rem 0 0.75rem 1rem;
}

.qa-check-control .form-select,
.qa-check-control .form-control {
    border-color: #ccdae4;
    border-radius: 9px;
    box-shadow: none;
    font-size: 0.78rem;
    min-height: 42px;
}

.qa-check-control .form-select:focus,
.qa-check-control .form-control:focus {
    border-color: #78add0;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.09);
}

.qa-decision-panel {
    background: #fbfdff;
    border-top: 1px solid #dbe6ee;
    padding: 1rem 1.4rem 1.2rem;
}

.qa-decision-panel label {
    color: #29445b;
    display: block;
    font-size: 0.76rem;
    font-weight: 650;
    margin-bottom: 0.45rem;
}

.qa-decision-panel textarea {
    border-color: #cad9e3;
    border-radius: 10px;
    box-shadow: none;
    min-height: 76px;
    resize: vertical;
}

.qa-action-bar {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    justify-content: flex-end;
    margin-top: 0.8rem;
}

.qa-action-bar .btn {
    align-items: center;
    border-radius: 9px;
    display: inline-flex;
    font-size: 0.76rem;
    font-weight: 600;
    gap: 0.4rem;
    justify-content: center;
    min-height: 38px;
    padding: 0.5rem 0.8rem;
}

.qa-add-checklist {
    border-top: 1px solid #dbe6ee;
}

.qa-add-checklist summary {
    align-items: center;
    color: var(--theme-primary);
    cursor: pointer;
    display: flex;
    font-size: 0.78rem;
    font-weight: 650;
    gap: 0.45rem;
    list-style: none;
    padding: 0.9rem 1.4rem;
}

.qa-add-checklist summary::-webkit-details-marker {
    display: none;
}

.qa-add-checklist[open] summary {
    background: #f7fbfd;
    border-bottom: 1px solid #dbe6ee;
}

.qa-add-row {
    align-items: end;
    display: grid;
    gap: 0.75rem;
    grid-template-columns: minmax(170px, 0.65fr) minmax(260px, 1.35fr) auto;
    padding: 1rem 1.4rem 1.25rem;
}

.qa-add-field label {
    color: #657b8e;
    display: block;
    font-size: 0.68rem;
    font-weight: 650;
    margin-bottom: 0.35rem;
    text-transform: uppercase;
}

.qa-add-field .form-control {
    border-color: #cad9e3;
    border-radius: 9px;
    min-height: 42px;
}

.qa-add-row .btn {
    border-radius: 9px;
    min-height: 42px;
    min-width: 92px;
}

.qa-review-empty {
    align-items: center;
    color: #74899b;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    justify-content: center;
    min-height: 500px;
    padding: 3rem;
    text-align: center;
}

.qa-review-empty i {
    color: #bfd1de;
    font-size: 2.5rem;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-workspace-rail,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-review-panel {
    background: #071321;
    border-color: rgba(109, 162, 198, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-workspace {
    background: #071321;
    border-color: rgba(109, 162, 198, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-queue-panel-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-review-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-progress-strip,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-checklist-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-checklist-columns,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-check-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-decision-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-add-checklist,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-recent-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-result-item {
    border-color: rgba(109, 162, 198, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-progress-strip,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-checklist-columns,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-decision-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-add-checklist[open] summary {
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-queue-item:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-check-row:hover {
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-queue-item.active {
    background: #0d263b;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-section-heading,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-review-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-progress-label strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-checklist-head h3,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-check-text,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-decision-panel label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-result-item strong {
    color: #d8e7f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-check-control {
    border-color: rgba(109, 162, 198, 0.16);
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

@media (max-width: 991.98px) {
    .qa-workspace {
        grid-template-columns: 1fr;
    }

    .qa-workspace-rail {
        border-bottom: 1px solid #dbe6ee;
        border-right: 0;
        position: static;
    }

    .qa-queue-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        max-height: 300px;
    }

    .qa-queue-item:nth-child(odd) {
        border-right: 1px solid #e0e9ef;
    }
}

@media (max-width: 767.98px) {
    .qa-dashboard {
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }

    .qa-workspace {
        border-radius: 16px;
    }

    .qa-page-banner {
        align-items: flex-start;
        border-radius: 18px;
        flex-direction: column;
        min-height: 0;
        padding: 1.4rem;
    }

    .qa-page-banner h1 {
        font-size: 1.45rem;
    }

    .qa-queue-list {
        display: block;
        max-height: 280px;
    }

    .qa-queue-item:nth-child(odd) {
        border-right: 0;
    }

    .qa-review-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .qa-progress-strip {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .qa-progress-strip .qa-progress {
        grid-column: 1 / -1;
        grid-row: 2;
    }

    .qa-checklist-columns {
        display: none;
    }

    .qa-check-row {
        gap: 0.6rem;
        grid-template-columns: 1fr;
        padding: 0.85rem 1rem;
    }

    .qa-check-copy {
        padding: 0;
    }

    .qa-check-control {
        border-left: 0;
        padding: 0;
    }

    .qa-add-row {
        grid-template-columns: 1fr;
    }

    .qa-action-bar {
        justify-content: flex-start;
    }
}
</style>

<div class="container-fluid px-4 py-4 qa-dashboard role-dashboard-shell">
    <section class="qa-page-banner" aria-labelledby="qa-testing-title">
        <div class="qa-page-banner-copy">
            <div class="qa-page-banner-kicker">Quality assurance workflow</div>
            <h1 id="qa-testing-title"><i class="fas fa-flask"></i> Testing Workspace</h1>
            <p>Review SUL-approved work, verify each requirement, and record a clear QA decision.</p>
        </div>
        <div class="qa-page-banner-actions">
            <span class="qa-page-banner-status">
                <i class="fas fa-list-check"></i>
                <strong><?php echo $queueCount; ?></strong>
                awaiting QA
            </span>
        </div>
    </section>

    <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <section class="qa-workspace" aria-label="QA testing workspace">
        <aside class="qa-workspace-rail">
            <div class="qa-queue-panel-head">
                <h2 class="qa-section-heading"><i class="fas fa-list-check"></i> Testing Queue</h2>
                <span class="qa-queue-count"><?php echo $queueCount; ?></span>
            </div>

            <nav class="qa-queue-list" aria-label="Tasks awaiting QA">
                <?php if($queue && $queue->num_rows > 0): ?>
                    <?php while($task = $queue->fetch_assoc()): ?>
                        <a href="testing.php?task_id=<?php echo (int) $task['id']; ?>" class="qa-queue-item <?php echo (int) $task['id'] === $selectedTaskId ? 'active' : ''; ?>">
                            <span class="qa-queue-icon"><i class="fas fa-vial"></i></span>
                            <span>
                                <span class="qa-queue-title"><?php echo htmlspecialchars((string) $task['title']); ?></span>
                                <span class="qa-queue-meta">
                                    <?php echo htmlspecialchars((string) $task['project_name']); ?>
                                    · <?php echo htmlspecialchars((string) ($task['developer_name'] ?? 'Unassigned')); ?>
                                </span>
                            </span>
                            <span class="qa-queue-arrow"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="qa-empty">
                        <i class="fas fa-circle-check"></i>
                        <p class="mb-0">No work is waiting for QA.</p>
                    </div>
                <?php endif; ?>
            </nav>

        </aside>

        <div class="qa-review-panel">
            <?php if($selectedTask): ?>
                <header class="qa-review-header">
                    <div>
                        <h2 class="qa-review-title"><?php echo htmlspecialchars((string) $selectedTask['title']); ?></h2>
                        <div class="qa-review-meta">
                            <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars((string) $selectedTask['project_name']); ?></span>
                            <span><i class="fas fa-user-check"></i> <?php echo htmlspecialchars((string) ($selectedTask['developer_name'] ?? 'Unassigned')); ?></span>
                        </div>
                    </div>
                    <div class="qa-review-tools">
                        <a class="qa-version-link" href="<?php echo BASE_URL; ?>modules/version-control/repositories.php?project_id=<?php echo (int) $selectedTask['project_id']; ?>">
                            <i class="fas fa-code-branch"></i> Version Control
                        </a>
                        <span class="qa-status-badge"><i class="fas fa-flask me-1"></i> QA Testing</span>
                    </div>
                </header>

                <div class="qa-progress-strip">
                    <div class="qa-progress-label">
                        <strong>Checklist progress</strong>
                        <span data-qa-progress-copy>
                            <?php echo $selectedChecklistPassed; ?> of <?php echo $selectedChecklistTotal; ?> passed
                            <?php echo $selectedChecklistIssues > 0 ? ' · ' . $selectedChecklistIssues . ' issue' . ($selectedChecklistIssues === 1 ? '' : 's') : ''; ?>
                        </span>
                    </div>
                    <div class="qa-progress" data-qa-progress-track aria-label="<?php echo $selectedChecklistProgress; ?> percent complete">
                        <div class="qa-progress-bar" data-qa-progress-bar style="width: <?php echo $selectedChecklistProgress; ?>%;"></div>
                    </div>
                    <span class="qa-progress-percent" data-qa-progress-percent><?php echo $selectedChecklistProgress; ?>%</span>
                </div>

                <form method="POST" class="qa-review-form" data-generic-draft-disabled="1">
                    <input type="hidden" name="task_id" value="<?php echo (int) $selectedTask['id']; ?>">
                    <section class="qa-checklist-section">
                        <div class="qa-checklist-head">
                            <h3>QA checklist</h3>
                            <span><?php echo $selectedChecklistTotal; ?> verification item<?php echo $selectedChecklistTotal === 1 ? '' : 's'; ?></span>
                        </div>
                        <div class="qa-checklist-columns" aria-hidden="true">
                            <span>Verification</span>
                            <span>Result</span>
                            <span>QA notes</span>
                        </div>
                        <div class="qa-checklist-body">
                            <?php foreach($checklistByFeature as $featureName => $featureItems): ?>
                                <?php foreach($featureItems as $item): ?>
                                    <?php $itemState = (string) ($item['status'] ?? 'pending'); ?>
                                    <div class="qa-check-row" data-state="<?php echo htmlspecialchars($itemState); ?>">
                                        <div class="qa-check-copy">
                                            <span class="qa-check-state">
                                                <i class="fas <?php echo $itemState === 'passed' ? 'fa-check' : ($itemState === 'pending' ? 'fa-minus' : 'fa-exclamation'); ?>"></i>
                                            </span>
                                            <div class="qa-check-text">
                                                <?php echo htmlspecialchars((string) $item['item_text']); ?>
                                                <?php if ((string) $featureName !== (string) $selectedTask['title']): ?>
                                                    <span class="qa-check-module"><?php echo htmlspecialchars((string) $featureName); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="qa-check-control">
                                            <select name="item_status[<?php echo (int) $item['id']; ?>]" class="form-select qa-status-select" aria-label="Checklist result">
                                                <?php foreach(['pending' => 'Pending', 'passed' => 'Passed', 'failed' => 'Failed', 'needs_revision' => 'Needs Revision'] as $value => $label): ?>
                                                    <option value="<?php echo $value; ?>" <?php echo $itemState === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="qa-check-control">
                                            <input name="item_notes[<?php echo (int) $item['id']; ?>]" class="form-control" value="<?php echo htmlspecialchars((string) ($item['notes'] ?? '')); ?>" placeholder="Add finding or issue details">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="qa-decision-panel">
                        <label for="qaDecisionComment">Decision note</label>
                        <textarea id="qaDecisionComment" name="qa_comment" class="form-control" rows="2" placeholder="Summarize the result for the developer or architect"></textarea>
                        <div class="qa-action-bar">
                            <button type="submit" name="save_checklist" class="btn btn-outline-primary">
                                <i class="fas fa-floppy-disk"></i> Save checklist
                            </button>
                            <button type="submit" name="qa_decision" value="1" onclick="this.form.decision.value='passed'" class="btn btn-success">
                                <i class="fas fa-check"></i> Pass
                            </button>
                            <button type="submit" name="qa_decision" value="1" onclick="this.form.decision.value='failed'" class="btn btn-outline-danger">
                                <i class="fas fa-xmark"></i> Fail
                            </button>
                            <button type="submit" name="qa_decision" value="1" onclick="this.form.decision.value='needs_revision'" class="btn btn-warning">
                                <i class="fas fa-rotate-left"></i> Request revision
                            </button>
                            <input type="hidden" name="decision" value="">
                        </div>
                    </section>
                </form>

                <details class="qa-add-checklist">
                    <summary><i class="fas fa-plus"></i> Add a custom checklist item</summary>
                    <form method="POST" class="qa-add-row" data-generic-draft-disabled="1">
                        <input type="hidden" name="task_id" value="<?php echo (int) $selectedTask['id']; ?>">
                        <div class="qa-add-field">
                            <label for="qaFeatureName">Feature / module</label>
                            <input id="qaFeatureName" name="feature_name" class="form-control" value="<?php echo htmlspecialchars((string) $selectedTask['title']); ?>">
                        </div>
                        <div class="qa-add-field">
                            <label for="qaChecklistItem">Checklist item</label>
                            <input id="qaChecklistItem" name="item_text" class="form-control" placeholder="Example: Password validation works">
                        </div>
                        <button type="submit" name="add_checklist_item" class="btn btn-qa-primary">
                            <i class="fas fa-plus me-1"></i> Add item
                        </button>
                    </form>
                </details>
            <?php else: ?>
                <div class="qa-review-empty">
                    <i class="fas fa-clipboard-check"></i>
                    <strong>Nothing to test right now</strong>
                    <span>QA will be notified after SUL approval.</span>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
const qaStatusSelects = Array.from(document.querySelectorAll('.qa-status-select'));
const qaProgressCopy = document.querySelector('[data-qa-progress-copy]');
const qaProgressBar = document.querySelector('[data-qa-progress-bar]');
const qaProgressPercent = document.querySelector('[data-qa-progress-percent]');
const qaProgressTrack = document.querySelector('[data-qa-progress-track]');

function updateQaChecklistProgress() {
    const total = qaStatusSelects.length;
    const passed = qaStatusSelects.filter((select) => select.value === 'passed').length;
    const issues = qaStatusSelects.filter((select) => ['failed', 'needs_revision'].includes(select.value)).length;
    const progress = total > 0 ? Math.round((passed / total) * 100) : 0;

    if (qaProgressCopy) {
        qaProgressCopy.textContent = `${passed} of ${total} passed${issues > 0 ? ` · ${issues} issue${issues === 1 ? '' : 's'}` : ''}`;
    }
    if (qaProgressBar) {
        qaProgressBar.style.width = `${progress}%`;
    }
    if (qaProgressPercent) {
        qaProgressPercent.textContent = `${progress}%`;
    }
    if (qaProgressTrack) {
        qaProgressTrack.setAttribute('aria-label', `${progress} percent complete`);
    }
}

qaStatusSelects.forEach((select) => {
    select.addEventListener('change', () => {
        const row = select.closest('.qa-check-row');
        const icon = row ? row.querySelector('.qa-check-state i') : null;
        if (!row) return;
        row.dataset.state = select.value;
        if (icon) {
            icon.className = select.value === 'passed'
                ? 'fas fa-check'
                : (select.value === 'pending' ? 'fas fa-minus' : 'fas fa-exclamation');
        }
        updateQaChecklistProgress();
    });
});

updateQaChecklistProgress();
</script>

<?php include '../../includes/footer.php'; ?>
