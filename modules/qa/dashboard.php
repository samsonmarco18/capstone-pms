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

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-clipboard-check me-2 text-primary"></i>QA Testing Dashboard</h2>
            <p class="text-muted mb-0">Checklist-based testing after SUL approval. Failed items return to the assigned Developer.</p>
        </div>
        <span class="badge bg-primary fs-6"><?php echo $queue ? (int) $queue->num_rows : 0; ?> in QA</span>
    </div>

    <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">QA Queue</div>
                <div class="list-group list-group-flush">
                    <?php if($queue && $queue->num_rows > 0): ?>
                        <?php while($task = $queue->fetch_assoc()): ?>
                            <a href="dashboard.php?task_id=<?php echo (int) $task['id']; ?>" class="list-group-item list-group-item-action <?php echo (int) $task['id'] === $selectedTaskId ? 'active' : ''; ?>">
                                <div class="fw-semibold"><?php echo htmlspecialchars((string) $task['title']); ?></div>
                                <small><?php echo htmlspecialchars((string) $task['project_name']); ?> · <?php echo htmlspecialchars((string) ($task['developer_name'] ?? 'Unassigned')); ?></small>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">No Pull Requests approved for QA yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white fw-semibold">Recent QA Results</div>
                <div class="card-body">
                    <?php if($recentResults && $recentResults->num_rows > 0): ?>
                        <?php while($result = $recentResults->fetch_assoc()): ?>
                            <div class="border-bottom pb-2 mb-2">
                                <div class="fw-semibold"><?php echo htmlspecialchars((string) $result['title']); ?></div>
                                <small class="text-muted"><?php echo workflowStatusLabel((string) $result['status']); ?> · <?php echo htmlspecialchars((string) $result['developer_name']); ?></small>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted mb-0">No QA results yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <?php if($selectedTask): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <div>
                                <h5 class="mb-1"><?php echo htmlspecialchars((string) $selectedTask['title']); ?></h5>
                                <small class="text-muted"><?php echo htmlspecialchars((string) $selectedTask['project_name']); ?> · Developer: <?php echo htmlspecialchars((string) ($selectedTask['developer_name'] ?? 'Unassigned')); ?></small>
                            </div>
                            <span class="badge bg-warning text-dark align-self-start">QA Testing</span>
                        </div>
                    </div>
                    <div class="card-body">
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
