<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
requireRole('qa');

$conn = getConnection();
$user = getCurrentUser();
workflowEnsureSchema($conn);

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
        header('Location: dashboard.php');
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
    ORDER BY t.sul_reviewed_at ASC, t.updated_at ASC
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

<div class="container-fluid px-4 py-4 qa-dashboard">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <span class="badge qa-overview-badge mb-2 px-3 py-2">QA Overview</span>
                    <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars((string) $user['full_name']); ?>!</h1>
                    <p class="text-muted mb-0 small">Review SUL-approved work, manage checklist coverage, and return clear findings to the right team.</p>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
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
                            <span class="badge bg-warning text-dark align-self-start">QA Testing</span>
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

<?php include '../../includes/footer.php'; ?>
