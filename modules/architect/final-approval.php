<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/task-reopen.php';
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
        header('Location: final-approval.php#reopen-requests');
        exit();
    }

    $result = taskReopenArchitectDecision($conn, $requestId, (int) ($user['id'] ?? 0), $decision, $comment);
    $_SESSION[$result['success'] ? 'success' : 'error'] = $result['message'];
    header('Location: final-approval.php#reopen-requests');
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
        INSERT INTO task_git_updates (task_id, user_id, branch_name, git_action, status, notes)
        VALUES (?, ?, 'main', 'merged', 'merged', ?)
    ");
    $gitNote = $mergeNotes !== '' ? $mergeNotes : 'Architect approved and merged to main.';
    $gitStmt->bind_param('iis', $taskId, $user['id'], $gitNote);
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
    .approval-open-button,
    .merge-button {
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

    <form method="GET" class="approval-project-picker">
        <div class="project-picker-copy">
            <i class="fas fa-folder-tree" aria-hidden="true"></i>
            <div><strong>Project workspace</strong><small>Choose a project with QA-passed work.</small></div>
        </div>
        <label class="visually-hidden" for="approvalProjectId">Project</label>
        <select class="form-select approval-project-select" id="approvalProjectId" name="project_id">
            <option value="0">Select a project to view merge-ready features</option>
            <?php if ($approvalProjects): while ($approvalProject = $approvalProjects->fetch_assoc()): ?>
                <option value="<?php echo (int) $approvalProject['id']; ?>" <?php echo (int) $approvalProject['id'] === $selectedProjectId ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) $approvalProject['name'] . ' - ' . (string) $approvalProject['client_name']); ?>
                </option>
            <?php endwhile; endif; ?>
        </select>
        <button class="approval-open-button" type="submit"><i class="fas fa-folder-open"></i> Open Project</button>
    </form>

    <section class="approval-panel reopen-panel" id="reopen-requests">
        <header class="approval-panel-header">
            <div class="approval-panel-title"><i class="fas fa-rotate-left" aria-hidden="true"></i><span>Task Reopen Requests</span></div>
            <span class="panel-count warning"><?php echo $reopenRequestCount; ?> pending</span>
        </header>
        <div class="approval-panel-body">
            <?php if($reopenRequestCount > 0): ?>
                <?php foreach($reopenRequests as $request): ?>
                    <?php $severity = strtolower((string) ($request['severity'] ?? 'minor')) === 'major' ? 'major' : 'minor'; ?>
                    <form method="POST" class="reopen-request-card">
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
    </section>

    <div class="approval-content-grid">
        <section class="approval-panel merge-workspace">
            <header class="approval-panel-header">
                <div class="approval-panel-title"><i class="fas fa-code-merge" aria-hidden="true"></i><span>Merge-ready Features</span></div>
                <span class="panel-count success"><?php echo $selectedProjectId > 0 ? $readyTaskCount : 0; ?> ready</span>
            </header>
            <?php if($selectedProjectId <= 0): ?>
                <div class="approval-empty">
                    <span class="approval-empty-icon" aria-hidden="true"><i class="fas fa-folder-open"></i></span>
                    <div>
                        <strong>Select a project to begin</strong>
                        <p>Choose a project above to review its QA-passed features.</p>
                    </div>
                </div>
            <?php elseif($readyTasks && $readyTaskCount > 0): ?>
                <div class="merge-task-list">
                    <?php while($task = $readyTasks->fetch_assoc()): ?>
                        <form method="POST" class="merge-task-card">
                            <input type="hidden" name="project_id" value="<?php echo (int) $selectedProjectId; ?>">
                            <input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>">
                            <div class="merge-task-head">
                                <div>
                                    <h5><?php echo htmlspecialchars((string) $task['title']); ?></h5>
                                    <div class="merge-task-meta"><?php echo htmlspecialchars((string) $task['project_name']); ?> &middot; Developer: <?php echo htmlspecialchars((string) ($task['developer_name'] ?? 'Unassigned')); ?></div>
                                </div>
                                <span class="qa-ready-badge"><i class="fas fa-shield-check" aria-hidden="true"></i> QA passed</span>
                            </div>
                            <div class="git-reference-list">
                                <?php if (!empty($task['branch_name'])): ?><span class="git-reference"><i class="fas fa-code-branch"></i> Branch: <?php echo htmlspecialchars((string) $task['branch_name']); ?></span><?php endif; ?>
                                <?php if (!empty($task['commit_hash'])): ?><span class="git-reference"><i class="fas fa-code-commit"></i> Commit: <?php echo htmlspecialchars(substr((string) $task['commit_hash'], 0, 7)); ?></span><?php endif; ?>
                                <?php if (!empty($task['pull_request_url'])): ?><a class="git-reference" href="<?php echo htmlspecialchars((string) $task['pull_request_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Pull Request</a><?php endif; ?>
                                <?php if (!empty($task['repository_url'])): ?><a class="git-reference" href="<?php echo htmlspecialchars((string) $task['repository_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-database"></i> Repository</a><?php endif; ?>
                            </div>
                            <div class="merge-task-action">
                                <textarea name="merge_notes" class="form-control" rows="2" placeholder="Final technical review or merge notes"></textarea>
                                <button type="submit" name="merge_task" class="merge-button"><i class="fas fa-code-merge"></i> Merge to Main</button>
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
        </section>

        <aside class="approval-panel recent-merges-panel">
            <header class="approval-panel-header">
                <div class="approval-panel-title"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i><span>Recent Merges</span></div>
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
        </aside>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
