<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/task-reopen.php';
require_once '../../includes/version-control-actions.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
workflowEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);
taskReopenEnsureSchema($conn);
$selectedProjectId = max(0, (int) ($_GET['project_id'] ?? $_POST['project_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['architect_approve_reopen']) || isset($_POST['architect_reject_reopen']))) {
    $requestId = (int) ($_POST['reopen_request_id'] ?? 0);
    $comment = trim((string) ($_POST['architect_comment'] ?? ''));
    $decision = isset($_POST['architect_approve_reopen']) ? 'approve' : 'reject';

    if ($requestId <= 0 || ($decision === 'reject' && $comment === '')) {
        $_SESSION['error'] = $decision === 'reject'
            ? 'Please provide a reason when rejecting a reopen request.'
            : 'Please select a valid reopen request.';
        header('Location: final-approval.php' . ($selectedProjectId > 0 ? '?project_id=' . $selectedProjectId . '&reopen=1' : '?reopen=1'));
        exit();
    }

    $result = taskReopenArchitectDecision($conn, $requestId, (int) ($user['id'] ?? 0), $decision, $comment);
    $_SESSION[$result['success'] ? 'success' : 'error'] = $result['message'];
    header('Location: final-approval.php' . ($selectedProjectId > 0 ? '?project_id=' . $selectedProjectId . '&reopen=1' : '?reopen=1'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_task'])) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $mergeNotes = trim((string) ($_POST['merge_notes'] ?? ''));

    $taskStmt = $conn->prepare("
        SELECT t.id, t.title, t.status, t.project_id, t.assigned_to, p.name AS project_name
        FROM tasks t
        JOIN projects p ON p.id = t.project_id
        WHERE t.id = ? AND t.status = 'qa_passed'
        LIMIT 1
    ");
    $taskStmt->bind_param('i', $taskId);
    $taskStmt->execute();
    $task = $taskStmt->get_result()->fetch_assoc();
    $taskStmt->close();

    if (!$task) {
        $_SESSION['error'] = 'Only QA-passed tasks can be merged by Architect.';
        header('Location: final-approval.php' . ($selectedProjectId > 0 ? '?project_id=' . $selectedProjectId : ''));
        exit();
    }

    $sourceBranchNameStmt = $conn->prepare(
        "SELECT branch_name
         FROM task_git_updates
         WHERE task_id = ? AND branch_name <> 'main'
         ORDER BY created_at DESC, id DESC
         LIMIT 1"
    );
    $sourceBranchNameStmt->bind_param('i', $taskId);
    $sourceBranchNameStmt->execute();
    $sourceBranchName = (string) ($sourceBranchNameStmt->get_result()->fetch_assoc()['branch_name'] ?? '');
    $sourceBranchNameStmt->close();

    $repositoryBranchStmt = $conn->prepare(
        "SELECT pr.id AS repository_id, source_branch.id AS source_branch_id, main_branch.id AS target_branch_id
         FROM project_repositories pr
         INNER JOIN repository_branches source_branch
             ON source_branch.repository_id = pr.id AND source_branch.name = ?
         INNER JOIN repository_branches main_branch
             ON main_branch.repository_id = pr.id AND main_branch.is_default = 1
         WHERE pr.project_id = ? AND pr.repository_status = 'active'
         LIMIT 1"
    );
    $repositoryBranchStmt->bind_param('si', $sourceBranchName, $task['project_id']);
    $repositoryBranchStmt->execute();
    $repositoryBranch = $repositoryBranchStmt->get_result()->fetch_assoc() ?: null;
    $repositoryBranchStmt->close();
    if (!$repositoryBranch) {
        $_SESSION['error'] = 'The feature branch or main branch is unavailable. Verify the project repository before final merge.';
        header('Location: final-approval.php' . ($selectedProjectId > 0 ? '?project_id=' . $selectedProjectId : ''));
        exit();
    }

    try {
        $mergeResult = vcHandlePostAction($conn, $user, [
            'action' => 'merge_branches',
            'project_id' => (int) $task['project_id'],
            'repository_id' => (int) $repositoryBranch['repository_id'],
            'source_branch_id' => (int) $repositoryBranch['source_branch_id'],
            'target_branch_id' => (int) $repositoryBranch['target_branch_id'],
            'merge_summary' => 'Merge ' . $task['title'] . ' into main',
            'merge_notes' => $mergeNotes,
            'close_source_branch' => '1',
        ]);
    } catch (Throwable $mergeError) {
        $_SESSION['error'] = $mergeError->getMessage();
        header('Location: final-approval.php' . ($selectedProjectId > 0 ? '?project_id=' . $selectedProjectId : ''));
        exit();
    }

    $stmt = $conn->prepare("
        UPDATE tasks
        SET status = 'completed',
            architect_reviewed_by = ?,
            architect_reviewed_at = NOW(),
            merged_at = NOW(),
            release_notified_at = NOW(),
            completed_at = NOW()
        WHERE id = ? AND status = 'qa_passed'
    ");
    $stmt->bind_param('ii', $user['id'], $taskId);
    $stmt->execute();
    $stmt->close();

    $gitStmt = $conn->prepare("
        INSERT INTO task_git_updates (task_id, user_id, branch_name, commit_hash, git_action, status, notes)
        VALUES (?, ?, 'main', ?, 'merged', 'merged', ?)
    ");
    $gitNote = $mergeNotes !== '' ? $mergeNotes : 'Architect approved and merged to main.';
    $actualMergeHash = (string) ($mergeResult['commit_hash'] ?? '');
    $gitStmt->bind_param('iiss', $taskId, $user['id'], $actualMergeHash, $gitNote);
    $gitStmt->execute();
    $gitStmt->close();

    workflowAddTaskComment($conn, $taskId, (int) $user['id'], 'Architect merged to main: ' . $gitNote);
    workflowLogStatusChange((int) $user['id'], $taskId, 'qa_passed', 'completed', 'architect_merge_to_main', $mergeNotes);
    projectWorkflowSyncRoadmap($conn, (int) $task['project_id']);
    workflowMaybeCompleteProject($conn, (int) $task['project_id'], (int) $user['id']);

    workflowNotifyRoles(
        $conn,
        ['sales'],
        'Feature Ready for Release: ' . $task['title'],
        'Architect merged the approved feature to main. Sales can view the completed release status.',
        BASE_URL . 'modules/sales/release-status.php?task_id=' . $taskId,
        $taskId
    );
    workflowNotifyUser(
        (int) $task['assigned_to'],
        'Feature Merged: ' . $task['title'],
        'Architect approved and merged your QA-passed work to main.',
        BASE_URL . 'modules/developer/task.php?id=' . $taskId,
        $taskId
    );

    $_SESSION['success'] = 'Feature merged to main and Sales notified.';
    header('Location: final-approval.php' . ($selectedProjectId > 0 ? '?project_id=' . $selectedProjectId : ''));
    exit();
}

$reopenRequests = taskReopenLoadArchitectRequests($conn);
$reopenRequestCount = count($reopenRequests);
$projectFilterSql = $selectedProjectId > 0 ? ' AND t.project_id = ' . $selectedProjectId : '';
$approvalProjects = $conn->query("
    SELECT DISTINCT p.id, p.name, c.name AS client_name
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    JOIN tasks t ON t.project_id = p.id
    WHERE t.status = 'qa_passed'
    ORDER BY p.name ASC
");

$readyTasks = $conn->query("
    SELECT t.*, p.name AS project_name, u.full_name AS developer_name,
           latest_git.branch_name, latest_git.commit_hash, latest_git.pull_request_url, latest_git.repository_url
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    LEFT JOIN task_git_updates latest_git ON latest_git.id = (
        SELECT tgu.id
        FROM task_git_updates tgu
        WHERE tgu.task_id = t.id
        ORDER BY tgu.created_at DESC, tgu.id DESC
        LIMIT 1
    )
    WHERE t.status = 'qa_passed'{$projectFilterSql}
    ORDER BY t.qa_reviewed_at ASC, t.updated_at ASC
");
$readyTaskCount = $readyTasks ? (int) $readyTasks->num_rows : 0;

$recentMerges = $conn->query("
    SELECT t.id, t.title, t.completed_at, p.name AS project_name, u.full_name AS developer_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status = 'completed' AND t.architect_reviewed_at IS NOT NULL
    ORDER BY t.merged_at DESC
    LIMIT 8
");

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
.approval-page {
    --approval-primary: #1f6392;
    --approval-dark: #174f73;
    --approval-text: #20364d;
    --approval-muted: #6f8499;
    --approval-border: #dce7ef;
    --approval-surface: #ffffff;
    --approval-soft: #f6f9fc;
}

.approval-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
    position: relative;
    overflow: hidden;
    margin-bottom: 1.25rem;
    padding: 1.2rem 4.5rem 1.25rem 1.3rem;
    border-radius: 16px;
    background: linear-gradient(135deg, var(--approval-primary) 0%, var(--approval-dark) 100%);
    box-shadow: 0 12px 28px rgba(31, 99, 146, 0.17);
}

.approval-header::after {
    content: "";
    position: absolute;
    inset: 0 0 0 auto;
    width: 32%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.08));
    pointer-events: none;
}

.approval-title {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    position: relative;
    z-index: 1;
    min-width: 0;
}

.approval-title-icon {
    display: inline-grid;
    place-items: center;
    flex: 0 0 46px;
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
    font-size: 1.15rem;
}

.approval-title h2 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.45rem, 2vw, 1.85rem);
    font-weight: 700;
}

.approval-title p {
    margin: 0.28rem 0 0;
    color: rgba(255, 255, 255, 0.84);
    font-size: 0.84rem;
}

.approval-header.page-help-host .page-header-help-btn {
    border-color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.94);
    color: #15567f;
    box-shadow: 0 8px 20px rgba(5, 32, 55, 0.22);
}

.approval-header.page-help-host .page-header-help-btn:hover,
.approval-header.page-help-host .page-header-help-btn:focus-visible {
    background: #fff;
    color: #0f496e;
}

.approval-project-picker,
.approval-panel {
    border: 1px solid var(--approval-border);
    border-radius: 16px;
    background: var(--approval-surface);
    box-shadow: 0 11px 28px rgba(15, 42, 64, 0.055);
}

.approval-project-picker {
    display: grid;
    grid-template-columns: auto minmax(280px, 1fr) auto;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.25rem;
    padding: 1rem 1.15rem;
}

.project-picker-copy {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 190px;
}

.project-picker-copy > i {
    display: inline-grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border-radius: 11px;
    background: #edf6fb;
    color: var(--approval-primary);
}

.project-picker-copy strong {
    display: block;
    color: var(--approval-text);
    font-size: 0.84rem;
    font-weight: 700;
}

.project-picker-copy small {
    display: block;
    margin-top: 0.12rem;
    color: var(--approval-muted);
    font-size: 0.68rem;
}

.approval-project-select {
    min-height: 44px;
    border: 1px solid #d5e2ec;
    border-radius: 11px;
    color: #344b61;
    font-size: 0.84rem;
    box-shadow: none;
}

.approval-project-select:focus {
    border-color: var(--approval-primary);
    box-shadow: 0 0 0 0.16rem rgba(31, 99, 146, 0.12);
}

.approval-open-button,
.merge-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    min-height: 42px;
    padding: 0.65rem 0.9rem;
    border: 0;
    border-radius: 10px;
    background: var(--approval-primary);
    color: #fff;
    font-size: 0.78rem;
    font-weight: 650;
    white-space: nowrap;
    transition: background 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.approval-open-button:hover,
.merge-button:hover {
    background: var(--approval-dark);
    color: #fff;
    box-shadow: 0 7px 16px rgba(31, 99, 146, 0.2);
    transform: translateY(-1px);
}

.approval-panel {
    overflow: hidden;
}

.approval-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    min-height: 60px;
    padding: 0.85rem 1.05rem;
    border-bottom: 1px solid var(--approval-border);
    background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
}

.approval-panel-title {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    min-width: 0;
    color: var(--approval-text);
    font-size: 0.88rem;
    font-weight: 700;
}

.approval-panel-title > i {
    display: inline-grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 9px;
    background: #edf6fb;
    color: var(--approval-primary);
    font-size: 0.78rem;
}

.panel-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    min-height: 28px;
    padding: 0.28rem 0.55rem;
    border-radius: 999px;
    background: #eef4f8;
    color: #496479;
    font-size: 0.68rem;
    font-weight: 650;
}

.panel-count.warning {
    background: #fff3cd;
    color: #996200;
}

.panel-count.success {
    background: #e4f7ed;
    color: #18794a;
}

.approval-panel-body {
    padding: 1rem;
}

.reopen-panel {
    margin-bottom: 1.25rem;
}

.approval-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.8rem;
    min-height: 190px;
    padding: 1.4rem;
    color: var(--approval-muted);
    text-align: center;
}

.approval-empty.compact {
    justify-content: flex-start;
    min-height: 0;
    padding: 0.15rem 0;
    text-align: left;
}

.approval-empty-icon {
    display: inline-grid;
    place-items: center;
    flex: 0 0 46px;
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: #edf4f8;
    color: #7894aa;
    font-size: 1rem;
}

.approval-empty strong {
    display: block;
    color: var(--approval-text);
    font-size: 0.84rem;
    font-weight: 650;
}

.approval-empty p {
    margin: 0.18rem 0 0;
    font-size: 0.76rem;
}

.approval-content-grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(290px, 0.9fr);
    gap: 1.25rem;
    align-items: start;
}

.merge-task-list {
    display: grid;
    gap: 0.75rem;
    padding: 0.85rem;
    background: var(--approval-soft);
}

.merge-task-card,
.reopen-request-card {
    border: 1px solid var(--approval-border);
    border-radius: 13px;
    background: #fff;
}

.merge-task-card {
    padding: 1rem;
}

.merge-task-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.merge-task-head h5,
.reopen-request-card h5 {
    margin: 0;
    color: var(--approval-text);
    font-size: 0.9rem;
    font-weight: 700;
}

.merge-task-meta,
.reopen-request-meta {
    margin-top: 0.28rem;
    color: var(--approval-muted);
    font-size: 0.74rem;
}

.qa-ready-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.34rem 0.55rem;
    border-radius: 999px;
    background: #e4f7ed;
    color: #18794a;
    font-size: 0.66rem;
    font-weight: 650;
    white-space: nowrap;
}

.git-reference-list {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 0.75rem;
}

.git-reference {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    min-height: 30px;
    padding: 0.32rem 0.55rem;
    border: 1px solid #d8e5ee;
    border-radius: 8px;
    background: #f8fbfd;
    color: #526b80;
    font-size: 0.68rem;
    text-decoration: none;
}

a.git-reference:hover {
    border-color: #a8c9dc;
    color: var(--approval-primary);
}

.merge-task-action {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.65rem;
    align-items: stretch;
    margin-top: 0.8rem;
}

.merge-task-action textarea,
.reopen-request-card textarea {
    min-height: 42px;
    resize: vertical;
    border-color: #d5e2ec;
    border-radius: 10px;
    font-size: 0.78rem;
}

.merge-button {
    background: #198754;
}

.merge-button:hover {
    background: #146c43;
    box-shadow: 0 7px 16px rgba(25, 135, 84, 0.2);
}

.recent-merge-list {
    display: grid;
    gap: 0;
}

.recent-merge-item {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 0.65rem;
    padding: 0.85rem 0;
    border-bottom: 1px solid #e7eef4;
}

.recent-merge-item:first-child { padding-top: 0; }
.recent-merge-item:last-child { padding-bottom: 0; border-bottom: 0; }

.recent-merge-icon {
    display: inline-grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #e4f7ed;
    color: #198754;
    font-size: 0.74rem;
}

.recent-merge-item strong {
    display: block;
    color: var(--approval-text);
    font-size: 0.78rem;
    font-weight: 650;
}

.recent-merge-item small,
.recent-merge-item time {
    display: block;
    margin-top: 0.15rem;
    color: var(--approval-muted);
    font-size: 0.68rem;
}

.reopen-request-card {
    padding: 1rem;
}

.reopen-request-card + .reopen-request-card {
    margin-top: 0.75rem;
}

.reopen-reason {
    margin: 0.8rem 0;
    padding: 0.75rem;
    border-left: 3px solid #e6a700;
    border-radius: 0 9px 9px 0;
    background: #fffaf0;
    color: #4c5e70;
    font-size: 0.76rem;
}

.reopen-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 0.7rem;
}

.approval-workspace {
    overflow: hidden;
    border: 1px solid var(--approval-border);
    border-radius: 18px;
    background: var(--approval-surface);
    box-shadow: 0 14px 34px rgba(15, 42, 64, 0.07);
}

.approval-workspace-toolbar {
    display: grid;
    grid-template-columns: auto minmax(280px, 1fr) auto;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--approval-border);
    background: #fff;
}

.approval-workspace .approval-panel-header {
    min-height: 56px;
    padding: 0.78rem 1.1rem;
    background: #f8fbfd;
}

.reopen-attention-button {
    position: relative;
    display: inline-grid;
    place-items: center;
    width: 44px;
    height: 44px;
    padding: 0;
    border: 1px solid #f0d28c;
    border-radius: 12px;
    background: #fff8e6;
    color: #9a6500;
    font-size: 0.92rem;
    transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
}

.reopen-attention-button:hover,
.reopen-attention-button:focus-visible {
    background: #fff1c7;
    color: #815300;
    box-shadow: 0 7px 18px rgba(176, 119, 10, 0.16);
    transform: translateY(-1px);
}

.reopen-attention-button.has-pending::before {
    content: "";
    position: absolute;
    inset: -5px;
    border: 2px solid rgba(230, 167, 0, 0.3);
    border-radius: 15px;
    animation: reopenAttentionPulse 1.8s ease-out 2;
}

.reopen-attention-count {
    position: absolute;
    top: -7px;
    right: -7px;
    display: inline-grid;
    place-items: center;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border: 2px solid #fff;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 0.62rem;
    font-weight: 700;
    line-height: 1;
}

.reopen-attention-count.is-empty {
    background: #7f93a5;
}

@keyframes reopenAttentionPulse {
    0% { opacity: 0.85; transform: scale(0.94); }
    100% { opacity: 0; transform: scale(1.22); }
}

.approval-workspace .merge-task-list {
    display: block;
    padding: 0;
    background: #fff;
}

.approval-workspace .merge-task-card {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) minmax(350px, 0.95fr);
    align-items: center;
    gap: 1.25rem;
    padding: 1rem 1.1rem;
    border: 0;
    border-bottom: 1px solid var(--approval-border);
    border-radius: 0;
    transition: background 0.18s ease;
}

.approval-workspace .merge-task-card:hover {
    background: #fbfdff;
}

.approval-workspace .merge-task-card:last-child {
    border-bottom: 0;
}

.merge-task-summary {
    min-width: 0;
}

.approval-workspace .merge-task-action {
    margin: 0;
    align-items: start;
}

.approval-workspace .merge-task-action textarea {
    min-height: 48px;
}

.merge-task-buttons {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0.45rem;
    width: 150px;
}

.approval-workspace .merge-button,
.merge-task-buttons > [data-generic-save-draft] {
    width: 100%;
    min-height: 38px;
    height: 38px;
    margin: 0 !important;
    padding: 0.45rem 0.75rem;
}

.merge-task-buttons > [data-generic-save-draft] {
    border-color: #c9dce9;
    border-radius: 9px;
    background: #fff;
    color: var(--approval-primary);
    font-size: 0.74rem;
    font-weight: 650;
    box-shadow: 0 2px 0 #d5e3ec;
}

.merge-task-buttons > [data-generic-draft-status] {
    margin: 0 !important;
    font-size: 0.66rem !important;
    line-height: 1.2;
    text-align: center;
}

.recent-merges-section {
    border-top: 8px solid #f3f7fa;
}

.recent-merges-section .approval-panel-header {
    border-bottom: 1px solid var(--approval-border);
}

.recent-merges-section .approval-panel-body {
    padding: 0 1.1rem;
}

.recent-merges-section .recent-merge-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 1.5rem;
}

.recent-merges-section .recent-merge-item {
    min-width: 0;
    padding: 0.8rem 0;
}

.recent-merges-section .recent-merge-item:nth-last-child(-n + 2) {
    border-bottom: 0;
}

.reopen-drawer-shell {
    position: fixed;
    z-index: 1085;
    inset: 0;
    visibility: hidden;
    pointer-events: none;
}

.reopen-drawer-shell.is-open {
    visibility: visible;
    pointer-events: auto;
}

.reopen-drawer-backdrop {
    position: absolute;
    inset: 0;
    border: 0;
    background: rgba(5, 17, 29, 0.5);
    opacity: 0;
    transition: opacity 0.22s ease;
}

.reopen-drawer-shell.is-open .reopen-drawer-backdrop {
    opacity: 1;
}

.reopen-drawer {
    position: absolute;
    top: 0;
    right: 0;
    display: flex;
    flex-direction: column;
    width: min(540px, 94vw);
    height: 100%;
    background: #fff;
    box-shadow: -18px 0 42px rgba(4, 25, 42, 0.2);
    transform: translateX(102%);
    transition: transform 0.24s ease;
}

.reopen-drawer-shell.is-open .reopen-drawer {
    transform: translateX(0);
}

.reopen-drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--approval-border);
}

.reopen-drawer-title {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 0;
}

.reopen-drawer-title > span:first-child {
    display: inline-grid;
    place-items: center;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: #fff3cd;
    color: #996200;
}

.reopen-drawer-title h3 {
    margin: 0;
    color: var(--approval-text);
    font-size: 1rem;
    font-weight: 680;
}

.reopen-drawer-title p {
    margin: 0.15rem 0 0;
    color: var(--approval-muted);
    font-size: 0.7rem;
}

.reopen-drawer-close {
    display: inline-grid;
    place-items: center;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    padding: 0;
    border: 1px solid var(--approval-border);
    border-radius: 10px;
    background: #fff;
    color: #60778b;
}

.reopen-drawer-body {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 1rem;
}

body.reopen-drawer-open {
    overflow: hidden;
}

@media (max-width: 1100px) {
    .approval-project-picker {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    .project-picker-copy {
        grid-column: 1 / -1;
    }
}

@media (max-width: 991.98px) {
    .approval-content-grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .approval-workspace .merge-task-card {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 767.98px) {
    .approval-page {
        padding-right: 0.8rem !important;
        padding-left: 0.8rem !important;
    }
    .approval-project-picker,
    .merge-task-action {
        grid-template-columns: minmax(0, 1fr);
    }
    .approval-workspace-toolbar {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    .approval-workspace-toolbar .project-picker-copy {
        grid-column: 1 / -1;
    }
    .approval-open-button,
    .merge-button {
        width: 100%;
    }
    .merge-task-buttons {
        width: 100%;
    }
    .merge-task-head {
        flex-direction: column;
    }
    .reopen-actions {
        align-items: stretch;
        flex-direction: column;
    }
    .reopen-actions .btn {
        width: 100%;
    }
    .recent-merges-section .recent-merge-list {
        grid-template-columns: minmax(0, 1fr);
    }
    .recent-merges-section .recent-merge-item:nth-last-child(2) {
        border-bottom: 1px solid #e7eef4;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-page {
    --approval-text: #d7e4f2;
    --approval-muted: #90a9c0;
    --approval-border: #20384d;
    --approval-surface: #0b1b2b;
    --approval-soft: #071522;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-title-icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-picker-copy > i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-panel-title > i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-empty-icon {
    background: #102f45;
    color: #7fc3e8;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-header .approval-title-icon {
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-panel-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .merge-task-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-request-card {
    background: #0b1b2b;
    border-color: #20384d;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-project-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .merge-task-action textarea,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-request-card textarea {
    background-color: #0d2133;
    border-color: #29465e;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-reference {
    background: #0d2133;
    border-color: #29465e;
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-reason {
    background: #2b2413;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recent-merge-item {
    border-color: #20384d;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-workspace,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-workspace-toolbar,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-workspace .merge-task-list,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-drawer {
    background: #0b1b2b;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-workspace .merge-task-card:hover {
    background: #0d2133;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recent-merges-section {
    border-top-color: #071522;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-attention-count {
    border-color: #0b1b2b;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-drawer-close {
    background: #0d2133;
    color: #aac0d4;
}
</style>

<div class="container-fluid px-4 py-4 approval-page">
    <?php di_render_project_attention_banner($conn, $user, $selectedProjectId); ?>

    <header class="approval-header" data-page-header>
        <div class="approval-title">
            <span class="approval-title-icon" aria-hidden="true"><i class="fas fa-code-merge"></i></span>
            <div>
                <h2>Final Approval &amp; Merge</h2>
                <p>Review QA-passed features and approve them for the main branch.</p>
            </div>
        </div>
    </header>

    <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <section class="approval-workspace">
        <form method="GET" class="approval-workspace-toolbar" id="approvalProjectForm">
            <div class="project-picker-copy">
                <i class="fas fa-folder-tree" aria-hidden="true"></i>
                <div><strong>Project workspace</strong></div>
            </div>
            <label class="visually-hidden" for="approvalProjectId">Project</label>
            <select class="form-select approval-project-select" id="approvalProjectId" name="project_id" data-approval-project-select>
                <option value="0">Select a project to view merge-ready features</option>
                <?php if ($approvalProjects): while ($approvalProject = $approvalProjects->fetch_assoc()): ?>
                    <option value="<?php echo (int) $approvalProject['id']; ?>" <?php echo (int) $approvalProject['id'] === $selectedProjectId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string) $approvalProject['name'] . ' - ' . (string) $approvalProject['client_name']); ?>
                    </option>
                <?php endwhile; endif; ?>
            </select>
            <button
                class="reopen-attention-button <?php echo $reopenRequestCount > 0 ? 'has-pending' : ''; ?>"
                type="button"
                data-reopen-open
                aria-controls="reopenRequestsDrawer"
                aria-label="View <?php echo $reopenRequestCount; ?> pending task reopen request<?php echo $reopenRequestCount === 1 ? '' : 's'; ?>"
                title="Task reopen requests"
            >
                <i class="fas fa-rotate-left" aria-hidden="true"></i>
                <span class="reopen-attention-count <?php echo $reopenRequestCount === 0 ? 'is-empty' : ''; ?>"><?php echo $reopenRequestCount; ?></span>
            </button>
        </form>

        <header class="approval-panel-header">
            <div class="approval-panel-title"><i class="fas fa-code-merge" aria-hidden="true"></i><span>Merge-ready Features</span></div>
            <span class="panel-count success"><?php echo $selectedProjectId > 0 ? $readyTaskCount : 0; ?> ready</span>
        </header>

        <?php if($selectedProjectId <= 0): ?>
            <div class="approval-empty">
                <span class="approval-empty-icon" aria-hidden="true"><i class="fas fa-folder-open"></i></span>
                <div>
                    <strong>Select a project to begin</strong>
                    <p>The merge queue will update immediately when a project is selected.</p>
                </div>
            </div>
        <?php elseif($readyTasks && $readyTaskCount > 0): ?>
            <div class="merge-task-list">
                <?php while($task = $readyTasks->fetch_assoc()): ?>
                    <form method="POST" class="merge-task-card">
                        <input type="hidden" name="project_id" value="<?php echo (int) $selectedProjectId; ?>">
                        <input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>">
                        <div class="merge-task-summary">
                            <div class="merge-task-head">
                                <div>
                                    <h5><?php echo htmlspecialchars((string) $task['title']); ?></h5>
                                    <div class="merge-task-meta">Developer: <?php echo htmlspecialchars((string) ($task['developer_name'] ?? 'Unassigned')); ?></div>
                                </div>
                                <span class="qa-ready-badge"><i class="fas fa-shield-check" aria-hidden="true"></i> QA passed</span>
                            </div>
                            <div class="git-reference-list">
                                <?php if (!empty($task['branch_name'])): ?><span class="git-reference"><i class="fas fa-code-branch"></i> Branch: <?php echo htmlspecialchars((string) $task['branch_name']); ?></span><?php endif; ?>
                                <?php if (!empty($task['commit_hash'])): ?><span class="git-reference"><i class="fas fa-code-commit"></i> Commit: <?php echo htmlspecialchars(substr((string) $task['commit_hash'], 0, 7)); ?></span><?php endif; ?>
                                <?php if (!empty($task['pull_request_url'])): ?><a class="git-reference" href="<?php echo htmlspecialchars((string) $task['pull_request_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Pull Request</a><?php endif; ?>
                                <?php if (!empty($task['repository_url'])): ?><a class="git-reference" href="<?php echo htmlspecialchars((string) $task['repository_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-database"></i> Repository</a><?php endif; ?>
                            </div>
                        </div>
                        <div class="merge-task-action">
                            <textarea name="merge_notes" class="form-control" rows="2" placeholder="Optional final technical or merge note"></textarea>
                            <div class="merge-task-buttons">
                                <button type="submit" name="merge_task" class="merge-button"><i class="fas fa-code-merge"></i> Merge to Main</button>
                            </div>
                        </div>
                    </form>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="approval-empty">
                <span class="approval-empty-icon" aria-hidden="true"><i class="fas fa-shield-check"></i></span>
                <div>
                    <strong>No features awaiting merge</strong>
                    <p>This project has no QA-passed features waiting for Architect approval.</p>
                </div>
            </div>
        <?php endif; ?>

        <section class="recent-merges-section" aria-labelledby="recentMergesTitle">
            <header class="approval-panel-header">
                <div class="approval-panel-title" id="recentMergesTitle"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i><span>Recent Merges</span></div>
            </header>
            <div class="approval-panel-body">
                <?php if($recentMerges && $recentMerges->num_rows > 0): ?>
                    <div class="recent-merge-list">
                    <?php while($merge = $recentMerges->fetch_assoc()): ?>
                        <?php $mergedAt = !empty($merge['completed_at']) ? strtotime((string) $merge['completed_at']) : false; ?>
                        <article class="recent-merge-item">
                            <span class="recent-merge-icon" aria-hidden="true"><i class="fas fa-code-merge"></i></span>
                            <div>
                                <strong><?php echo htmlspecialchars((string) $merge['title']); ?></strong>
                                <small><?php echo htmlspecialchars((string) $merge['project_name']); ?> &middot; <?php echo htmlspecialchars((string) ($merge['developer_name'] ?? 'Developer')); ?></small>
                                <?php if ($mergedAt): ?><time datetime="<?php echo htmlspecialchars(date('c', $mergedAt)); ?>"><?php echo htmlspecialchars(date('M d, Y', $mergedAt)); ?></time><?php endif; ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="approval-empty compact">
                        <span class="approval-empty-icon" aria-hidden="true"><i class="fas fa-code-merge"></i></span>
                        <div><strong>No recent merges</strong><p>Approved merges will appear here.</p></div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </section>

    <div
        class="reopen-drawer-shell"
        id="reopenRequestsDrawer"
        aria-hidden="true"
        data-auto-open="<?php echo isset($_GET['reopen']) ? 'true' : 'false'; ?>"
    >
        <button class="reopen-drawer-backdrop" type="button" data-reopen-close aria-label="Close task reopen requests"></button>
        <aside class="reopen-drawer" role="dialog" aria-modal="true" aria-labelledby="reopenDrawerTitle" tabindex="-1">
            <header class="reopen-drawer-header">
                <div class="reopen-drawer-title">
                    <span aria-hidden="true"><i class="fas fa-rotate-left"></i></span>
                    <div>
                        <h3 id="reopenDrawerTitle">Task Reopen Requests</h3>
                        <p><?php echo $reopenRequestCount; ?> decision<?php echo $reopenRequestCount === 1 ? '' : 's'; ?> awaiting Architect review</p>
                    </div>
                </div>
                <button class="reopen-drawer-close" type="button" data-reopen-close aria-label="Close task reopen requests"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </header>
            <div class="reopen-drawer-body" id="reopen-requests">
                <?php if($reopenRequestCount > 0): ?>
                    <?php foreach($reopenRequests as $request): ?>
                        <?php $severity = strtolower((string) ($request['severity'] ?? 'minor')) === 'major' ? 'major' : 'minor'; ?>
                        <form method="POST" class="reopen-request-card">
                            <input type="hidden" name="project_id" value="<?php echo (int) $selectedProjectId; ?>">
                            <input type="hidden" name="reopen_request_id" value="<?php echo (int) $request['id']; ?>">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="badge <?php echo $severity === 'major' ? 'bg-danger' : 'bg-info text-dark'; ?>"><?php echo htmlspecialchars(taskReopenSeverityLabel($severity)); ?></span>
                                <h5><?php echo htmlspecialchars((string) $request['task_title']); ?></h5>
                            </div>
                            <div class="reopen-request-meta">
                                <?php echo htmlspecialchars((string) $request['project_name']); ?> &middot;
                                Developer: <?php echo htmlspecialchars((string) ($request['requester_name'] ?? 'Developer')); ?> &middot;
                                SUL: <?php echo htmlspecialchars((string) ($request['sul_name'] ?? 'SUL')); ?>
                            </div>
                            <div class="reopen-reason">
                                <strong>Developer reason</strong>
                                <div><?php echo nl2br(htmlspecialchars((string) ($request['reason'] ?? ''))); ?></div>
                            </div>
                            <?php if (!empty($request['sul_comment'])): ?>
                                <div class="small mb-2"><strong>SUL note:</strong> <?php echo nl2br(htmlspecialchars((string) $request['sul_comment'])); ?></div>
                            <?php endif; ?>
                            <div class="small text-muted mb-2">
                                <?php if($severity === 'major'): ?>
                                    Approval will reopen this task and reset later roadmap phases/tasks so affected downstream work can be redone.
                                <?php else: ?>
                                    Approval will reopen only this task and send it back to developer revision.
                                <?php endif; ?>
                            </div>
                            <textarea name="architect_comment" class="form-control" rows="2" placeholder="Architect decision note"></textarea>
                            <div class="reopen-actions">
                                <button type="submit" name="architect_approve_reopen" class="btn btn-warning"><i class="fas fa-rotate-left me-1"></i> Approve Reopen</button>
                                <button type="submit" name="architect_reject_reopen" class="btn btn-outline-danger"><i class="fas fa-ban me-1"></i> Reject</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="approval-empty compact">
                        <span class="approval-empty-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
                        <div><strong>No reopen decisions pending</strong><p>No SUL-accepted reopen requests are waiting for Architect review.</p></div>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const projectSelect = document.querySelector('[data-approval-project-select]');
    const drawerShell = document.getElementById('reopenRequestsDrawer');
    const drawer = drawerShell ? drawerShell.querySelector('.reopen-drawer') : null;
    const openButton = document.querySelector('[data-reopen-open]');
    const closeButtons = drawerShell ? drawerShell.querySelectorAll('[data-reopen-close]') : [];
    let restoreFocusTo = null;

    if (projectSelect) {
        projectSelect.addEventListener('change', function () {
            const url = new URL(window.location.href);
            if (this.value && this.value !== '0') {
                url.searchParams.set('project_id', this.value);
            } else {
                url.searchParams.delete('project_id');
            }
            url.searchParams.delete('reopen');
            url.hash = '';
            window.location.assign(url.toString());
        });
    }

    const openDrawer = function () {
        if (!drawerShell || !drawer) return;
        restoreFocusTo = document.activeElement;
        drawerShell.classList.add('is-open');
        drawerShell.setAttribute('aria-hidden', 'false');
        document.body.classList.add('reopen-drawer-open');
        window.setTimeout(function () { drawer.focus(); }, 40);
    };

    const closeDrawer = function () {
        if (!drawerShell) return;
        drawerShell.classList.remove('is-open');
        drawerShell.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('reopen-drawer-open');
        if (restoreFocusTo && typeof restoreFocusTo.focus === 'function') {
            restoreFocusTo.focus();
        }
    };

    if (openButton) {
        openButton.addEventListener('click', openDrawer);
    }

    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeDrawer);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawerShell && drawerShell.classList.contains('is-open')) {
            closeDrawer();
        }
    });

    if (drawerShell && drawerShell.dataset.autoOpen === 'true') {
        openDrawer();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
