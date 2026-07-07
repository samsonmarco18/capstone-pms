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
        header('Location: final-approval.php');
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
    header('Location: final-approval.php');
    exit();
}

$reopenRequests = taskReopenLoadArchitectRequests($conn);
$reopenRequestCount = count($reopenRequests);

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
    WHERE t.status = 'qa_passed'
    ORDER BY t.qa_reviewed_at ASC, t.updated_at ASC
");

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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge.bg-light {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) textarea {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-code-merge me-2 text-primary"></i>Final Approval & Merge</h2>
            <p class="text-muted mb-0">Architect can only merge features that passed QA testing.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-warning text-dark fs-6"><?php echo $reopenRequestCount; ?> Reopen Requests</span>
            <span class="badge bg-success fs-6"><?php echo $readyTasks ? (int) $readyTasks->num_rows : 0; ?> QA Passed</span>
        </div>
    </div>

    <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm mb-4" id="reopen-requests">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="fas fa-rotate-left me-2 text-warning"></i>Task Reopen Requests</span>
            <span class="badge bg-warning text-dark"><?php echo $reopenRequestCount; ?> pending</span>
        </div>
        <div class="card-body">
            <?php if($reopenRequestCount > 0): ?>
                <?php foreach($reopenRequests as $request): ?>
                    <?php $severity = strtolower((string) ($request['severity'] ?? 'minor')) === 'major' ? 'major' : 'minor'; ?>
                    <form method="POST" class="border-bottom pb-3 mb-3">
                        <input type="hidden" name="reopen_request_id" value="<?php echo (int) $request['id']; ?>">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge <?php echo $severity === 'major' ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                        <?php echo htmlspecialchars(taskReopenSeverityLabel($severity)); ?>
                                    </span>
                                    <h5 class="mb-0"><?php echo htmlspecialchars((string) $request['task_title']); ?></h5>
                                </div>
                                <div class="text-muted small mb-3">
                                    <?php echo htmlspecialchars((string) $request['project_name']); ?> &middot;
                                    Developer: <?php echo htmlspecialchars((string) ($request['requester_name'] ?? 'Developer')); ?> &middot;
                                    SUL: <?php echo htmlspecialchars((string) ($request['sul_name'] ?? 'SUL')); ?>
                                </div>
                                <div class="alert alert-light border mb-3">
                                    <div class="fw-semibold mb-1">Developer reason</div>
                                    <div><?php echo nl2br(htmlspecialchars((string) ($request['reason'] ?? ''))); ?></div>
                                </div>
                                <?php if (!empty($request['sul_comment'])): ?>
                                    <div class="small mb-3"><strong>SUL note:</strong> <?php echo nl2br(htmlspecialchars((string) $request['sul_comment'])); ?></div>
                                <?php endif; ?>
                                <div class="small text-muted mb-3">
                                    <?php if($severity === 'major'): ?>
                                        Approval will reopen this task and reset later roadmap phases/tasks so affected downstream work can be redone.
                                    <?php else: ?>
                                        Approval will reopen only this task and send it back to developer revision.
                                    <?php endif; ?>
                                </div>
                                <textarea name="architect_comment" class="form-control" rows="2" placeholder="Architect decision note"></textarea>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" name="architect_approve_reopen" class="btn btn-warning">
                                    <i class="fas fa-rotate-left me-1"></i> Approve Reopen
                                </button>
                                <button type="submit" name="architect_reject_reopen" class="btn btn-outline-danger">
                                    <i class="fas fa-ban me-1"></i> Reject
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted mb-0">No SUL-accepted reopen requests are waiting for Architect decision.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <?php if($readyTasks && $readyTasks->num_rows > 0): ?>
                <?php while($task = $readyTasks->fetch_assoc()): ?>
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?php echo htmlspecialchars((string) $task['title']); ?></h5>
                                        <div class="text-muted small mb-2">
                                            <?php echo htmlspecialchars((string) $task['project_name']); ?> · Developer: <?php echo htmlspecialchars((string) ($task['developer_name'] ?? 'Unassigned')); ?>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap mb-3">
                                            <?php if (!empty($task['branch_name'])): ?><span class="badge bg-light text-dark border">Branch: <?php echo htmlspecialchars((string) $task['branch_name']); ?></span><?php endif; ?>
                                            <?php if (!empty($task['commit_hash'])): ?><span class="badge bg-light text-dark border">Commit: <?php echo htmlspecialchars(substr((string) $task['commit_hash'], 0, 7)); ?></span><?php endif; ?>
                                            <?php if (!empty($task['pull_request_url'])): ?><a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars((string) $task['pull_request_url']); ?>" target="_blank" rel="noopener">Pull Request</a><?php endif; ?>
                                            <?php if (!empty($task['repository_url'])): ?><a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars((string) $task['repository_url']); ?>" target="_blank" rel="noopener">Repository</a><?php endif; ?>
                                        </div>
                                        <textarea name="merge_notes" class="form-control" rows="2" placeholder="Final technical review / merge notes"></textarea>
                                    </div>
                                    <button type="submit" name="merge_task" class="btn btn-success">
                                        <i class="fas fa-code-merge me-1"></i> Merge to Main
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fas fa-shield-check fa-3x mb-3"></i>
                        <p class="mb-0">No QA-passed tasks are waiting for Architect merge.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Recent Merges</div>
                <div class="card-body">
                    <?php if($recentMerges && $recentMerges->num_rows > 0): ?>
                        <?php while($merge = $recentMerges->fetch_assoc()): ?>
                            <div class="border-bottom pb-2 mb-2">
                                <div class="fw-semibold"><?php echo htmlspecialchars((string) $merge['title']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars((string) $merge['project_name']); ?> · <?php echo htmlspecialchars((string) ($merge['developer_name'] ?? 'Developer')); ?></small>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted mb-0">No architect merges yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
