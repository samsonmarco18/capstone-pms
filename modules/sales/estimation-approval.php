<?php
require_once '../../includes/auth.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();

// Ensure sales approval columns exist
$hasSalesApproval = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_approved'")->num_rows > 0;
if (!$hasSalesApproval) {
    try {
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved TINYINT(1) DEFAULT 0");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_by INT NULL");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_at DATETIME NULL");
        $hasSalesApproval = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_approved'")->num_rows > 0;
    } catch (Throwable $e) {
        // Continue without sales approval columns
    }
}

$hasSalesChoice = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_estimate_choice'")->num_rows > 0;
if (!$hasSalesChoice) {
    try {
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_estimate_choice ENUM('min','likely','max') DEFAULT NULL");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_estimate_days INT DEFAULT NULL");
        $hasSalesChoice = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_estimate_choice'")->num_rows > 0;
    } catch (Throwable $e) {
        // Continue without sales choice columns
    }
}

$hasProjectPhases = $conn->query("SHOW COLUMNS FROM projects LIKE 'project_phases'")->num_rows > 0;

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_estimation'])) {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $estimateDays = (int) ($_POST['estimate_days'] ?? 0);
        if ($taskId > 0) {
            if ($estimateDays <= 0) {
                $_SESSION['error'] = "Please enter the number of days to finalize the timeline.";
                header('Location: estimation-approval.php');
                exit();
            }

            $deadlineSql = "";
            $taskCurrent = $conn->query("SELECT started_at FROM tasks WHERE id = $taskId")->fetch_assoc();
            $startedAt = $taskCurrent['started_at'] ?? null;
            if (!empty($startedAt) && $estimateDays > 0) {
                $deadline = (new DateTime($startedAt))->modify("+{$estimateDays} days")->format('Y-m-d');
                $deadlineSql = ", deadline = '" . $conn->real_escape_string($deadline) . "'";
            }

            if ($hasSalesApproval) {
                $choiceSql = $hasSalesChoice ? ", sales_estimate_choice = NULL, sales_estimate_days = " . (int) $estimateDays : "";
                $stmt = $conn->prepare("UPDATE tasks SET status = 'review', sales_approved = 1, sales_approved_by = ?, sales_approved_at = NOW(){$choiceSql}{$deadlineSql} WHERE id = ?");
                $stmt->bind_param("ii", $user['id'], $taskId);
            } else {
                $stmt = $conn->prepare("UPDATE tasks SET status = 'review' WHERE id = ?");
                $stmt->bind_param("i", $taskId);
            }

            if ($stmt->execute()) {
                $taskDetails = $conn->query("SELECT title, project_id FROM tasks WHERE id = $taskId")->fetch_assoc();
                $sulUsers = $conn->query("SELECT id FROM users WHERE role = 'sul' AND status = 'active'");
                while ($sul = $sulUsers->fetch_assoc()) {
                    createNotification(
                        (int) $sul['id'],
                        "Sales Approved Estimation: " . ($taskDetails['title'] ?? 'Task'),
                        "Sales approved the estimation. Task is ready for SUL review.",
                        'approval',
                        'tasks',
                        (int) $taskId,
                        BASE_URL . 'modules/sul/task-allocation.php?project_id=' . (int) ($taskDetails['project_id'] ?? 0)
                    );
                }
                logActivity($user['id'], 'sales_approve_estimation', 'tasks', $taskId, null, $_POST);
                $_SESSION['success'] = "Estimation approved and sent to SUL.";
            } else {
                $_SESSION['error'] = "Failed to approve estimation.";
            }
        }
        header('Location: estimation-approval.php');
        exit();
    }

    if (isset($_POST['reject_estimation'])) {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
        if ($taskId > 0) {
            if ($hasSalesApproval) {
                $stmt = $conn->prepare("UPDATE tasks SET status = 'todo', sales_approved = 0, sales_approved_by = NULL, sales_approved_at = NULL, sales_estimate_choice = NULL, sales_estimate_days = NULL WHERE id = ?");
                $stmt->bind_param("i", $taskId);
            } else {
                $stmt = $conn->prepare("UPDATE tasks SET status = 'todo' WHERE id = ?");
                $stmt->bind_param("i", $taskId);
            }
            if ($stmt->execute()) {
                $task = $conn->query("SELECT assigned_to, title FROM tasks WHERE id = $taskId")->fetch_assoc();
                if (!empty($task['assigned_to'])) {
                    createNotification(
                        (int) $task['assigned_to'],
                        "Sales Rejected Estimation: " . ($task['title'] ?? 'Task'),
                        $reason !== '' ? "Reason: {$reason}" : "Please revise your estimation based on sales feedback.",
                        'approval',
                        'tasks',
                        (int) $taskId,
                        BASE_URL . 'modules/expert/feature-estimation.php'
                    );
                }
                logActivity($user['id'], 'sales_reject_estimation', 'tasks', $taskId, null, $_POST);
                $_SESSION['success'] = "Estimation rejected and returned to expert.";
            } else {
                $_SESSION['error'] = "Failed to reject estimation.";
            }
        }
        header('Location: estimation-approval.php');
        exit();
    }
}

// Get tasks pending sales approval
$pendingTasks = $conn->query("
    SELECT t.*, p.name as project_name, p.id as project_id, p.timeline_start, p.timeline_end" . ($hasProjectPhases ? ", p.project_phases" : "") . ",
           u.full_name as expert_name, fe.min_days, fe.likely_days, fe.max_days, fe.expectation_description
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    JOIN users u ON t.assigned_to = u.id
    LEFT JOIN feature_estimations fe ON t.id = fe.task_id AND fe.status = 'completed'
    WHERE t.status = 'in_review'
    ORDER BY t.deadline ASC
");

$pendingTaskRows = [];
$tasksByProject = [];
while ($row = $pendingTasks->fetch_assoc()) {
    $pendingTaskRows[] = $row;
    $pid = (int) $row['project_id'];
    if (!isset($tasksByProject[$pid])) {
        $tasksByProject[$pid] = [];
    }
    $tasksByProject[$pid][] = $row;
}

$ganttProjects = [];
foreach ($tasksByProject as $pid => $tasks) {
    $project = $tasks[0];
    $ganttTasks = [];

    if ($hasProjectPhases && !empty($project['project_phases'])) {
        $phases = json_decode($project['project_phases'], true);
        if (is_array($phases)) {
            foreach ($phases as $idx => $phase) {
                if (empty($phase['start_date']) || empty($phase['end_date'])) {
                    continue;
                }
                $ganttTasks[] = [
                    'id' => 'phase-' . $pid . '-' . $idx,
                    'name' => $phase['name'] ?? 'Phase',
                    'start' => $phase['start_date'],
                    'end' => $phase['end_date'],
                    'progress' => 0,
                    'custom_class' => 'gantt-phase'
                ];
            }
        }
    }

    foreach ($tasks as $task) {
        $startRaw = $task['started_at'] ?? null;
        $endRaw = $task['deadline'] ?? null;
        $start = $startRaw ? date('Y-m-d', strtotime($startRaw)) : null;
        $end = $endRaw ? date('Y-m-d', strtotime($endRaw)) : null;

        if (empty($start)) {
            $start = !empty($project['timeline_start'])
                ? date('Y-m-d', strtotime($project['timeline_start']))
                : date('Y-m-d');
        }

        if (empty($end)) {
            $duration = (int) ($task['likely_days'] ?? 0);
            if ($duration <= 0) {
                $duration = (int) ($task['min_days'] ?? 0);
            }
            if ($duration <= 0) {
                $duration = (int) ($task['max_days'] ?? 0);
            }
            if ($duration <= 0) {
                $duration = 1;
            }
            $endDate = new DateTime($start);
            $endDate->modify('+' . max(1, $duration) . ' days');
            $end = $endDate->format('Y-m-d');
        }

        $ganttTasks[] = [
            'id' => 'task-' . (int) $task['id'],
            'name' => $task['title'],
            'start' => $start,
            'end' => $end,
            'progress' => 0,
            'custom_class' => 'gantt-task'
        ];
    }

    $ganttProjects[$pid] = [
        'project_name' => $project['project_name'],
        'tasks' => $ganttTasks
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
    --gray-600: #6c757d;
    --gray-800: #343a40;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: white;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9);
}

.task-card {
    background: white;
    border-radius: 16px;
    padding: 1.25rem;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
    margin-bottom: 1rem;
}

.task-card:hover {
    box-shadow: var(--shadow-md);
}

.task-meta {
    font-size: 0.75rem;
    color: var(--gray-600);
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 0.5rem;
}

.btn-action {
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 10px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-approve {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.btn-reject {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

.btn-view {
    background: #e9ecef;
    color: #495057;
}

.btn-action:hover {
    transform: translateY(-1px);
}

.gantt-preview {
    display: grid;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.gantt-row-preview {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.gantt-label {
    width: 60px;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--gray-600);
    text-transform: uppercase;
}

.gantt-bar-track {
    flex: 1;
    height: 10px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
    display: flex;
}

.gantt-bar {
    height: 100%;
}

.min-bar { background: #60a5fa; }
.likely-bar { background: #34d399; }
.max-bar { background: #f97316; }

.choice-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 0.75rem;
}

.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
}

.gantt-modal-body {
    padding: 1.5rem;
}

.gantt-phase .bar {
    fill: #1f6392;
}
</style>

<div class="container-fluid px-4 py-4">
    <div class="page-header-modern">
        <h2><i class="fas fa-clipboard-check me-2"></i> Estimation Approval</h2>
        <p>Review expert estimations and approve before SUL assignment.</p>
    </div>

    <?php if (count($ganttProjects) > 0): ?>
        <?php foreach ($ganttProjects as $pid => $projectData): ?>
            <div class="task-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h6 class="mb-0"><i class="fas fa-stream me-2"></i><?php echo htmlspecialchars($projectData['project_name']); ?></h6>
                    <span class="text-muted small">Gantt Chart</span>
                </div>
                <div class="gantt-modal-body">
                    <svg id="salesGantt_<?php echo (int) $pid; ?>"></svg>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (count($pendingTaskRows) > 0): ?>
        <?php foreach ($pendingTaskRows as $task): ?>
            <?php 
                $minDays = (float) ($task['min_days'] ?? 0);
                $likelyDays = (float) ($task['likely_days'] ?? 0);
                $maxDays = (float) ($task['max_days'] ?? 0);
                $pert = round(($minDays + 4*$likelyDays + $maxDays)/6, 1);
            ?>
            <div class="task-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h6 class="mb-1"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <div class="task-meta">
                            <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($task['expert_name']); ?></span>
                            <span><i class="far fa-calendar-alt"></i> Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></span>
                            <span><i class="fas fa-chart-line"></i> PERT: <?php echo $pert; ?> days</span>
                        </div>
                        <small class="text-muted d-block mt-2"><?php echo htmlspecialchars($task['expectation_description'] ?? ''); ?></small>
                        <div class="gantt-preview" data-task-id="<?php echo (int) $task['id']; ?>">
                            <div class="gantt-row-preview">
                                <span class="gantt-label">Days</span>
                                <div class="gantt-bar-track">
                                    <div class="gantt-bar min-bar" style="width: <?php echo $maxDays > 0 ? ($minDays / $maxDays * 100) : 0; ?>%;"></div>
                                    <div class="gantt-bar likely-bar" style="width: <?php echo $maxDays > 0 ? (max($likelyDays - $minDays, 0) / $maxDays * 100) : 0; ?>%;"></div>
                                    <div class="gantt-bar max-bar" style="width: <?php echo $maxDays > 0 ? (max($maxDays - max($likelyDays, $minDays), 0) / $maxDays * 100) : 0; ?>%;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="choice-group">
                            <label class="form-label mb-0">Finalize Days</label>
                            <input type="number" class="form-control" style="max-width: 140px;" min="1" step="1"
                                   name="estimate_days_<?php echo (int) $task['id']; ?>"
                                   value="<?php echo (int) ($likelyDays > 0 ? $likelyDays : $maxDays); ?>">
                            <small class="text-muted">Min: <?php echo (int) $minDays; ?>, Likely: <?php echo (int) $likelyDays; ?>, Max: <?php echo (int) $maxDays; ?></small>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn-action btn-view" onclick="openGanttModal(<?php echo (int) $task['project_id']; ?>)">
                            <i class="fas fa-stream me-1"></i> View Gantt
                        </button>
                        <button class="btn-action btn-approve" onclick="approveEstimation(<?php echo (int) $task['id']; ?>)">
                            <i class="fas fa-check me-1"></i> Approve
                        </button>
                        <button class="btn-action btn-reject" onclick="openRejectModal(<?php echo (int) $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">
                            <i class="fas fa-times me-1"></i> Reject
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-check-circle"></i>
            <p class="mt-2 mb-0">No estimations awaiting sales approval.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Gantt Modal -->
<div class="modal fade modal-modern" id="ganttModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-stream me-2"></i><span id="ganttProjectTitle">Project Timeline</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body gantt-modal-body">
                <div id="ganttChartContainer"></div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade modal-modern" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Estimation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="rejectTaskId">
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection</label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject_estimation" class="btn btn-warning">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const ganttProjects = <?php echo json_encode($ganttProjects, JSON_UNESCAPED_UNICODE); ?>;

function renderFallbackGantt(targetSelector, tasks) {
    if (typeof Chart === 'undefined') {
        return;
    }
    const target = document.querySelector(targetSelector);
    if (!target) return;
    target.innerHTML = '';
    const canvas = document.createElement('canvas');
    canvas.height = 240;
    target.appendChild(canvas);
    const ctx = canvas.getContext('2d');
    const labels = tasks.map(t => t.name);
    const starts = tasks.map(t => new Date(t.start + 'T00:00:00'));
    const ends = tasks.map(t => new Date(t.end + 'T00:00:00'));
    const minStart = new Date(Math.min(...starts.map(d => d.getTime())));
    const offsets = starts.map(start => Math.max(0, Math.round((start - minStart) / (1000 * 60 * 60 * 24))));
    const durations = starts.map((start, idx) => {
        const end = ends[idx];
        return Math.max(1, Math.round((end - start) / (1000 * 60 * 60 * 24)));
    });
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Offset',
                data: offsets,
                backgroundColor: 'rgba(0,0,0,0)',
                borderWidth: 0,
                stack: 'gantt'
            }, {
                label: 'Duration (days)',
                data: durations,
                backgroundColor: '#1f6392',
                stack: 'gantt'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, stacked: true },
                y: { stacked: true }
            }
        }
    });
}

function approveEstimation(taskId) {
    if (!confirm('Approve this estimation and send to SUL?')) return;
    const daysInput = document.querySelector(`input[name="estimate_days_${taskId}"]`);
    const daysValue = parseInt(daysInput?.value || '0', 10);
    if (!daysValue || daysValue <= 0) {
        alert('Please enter the number of days.');
        return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `<input type="hidden" name="approve_estimation" value="1"><input type="hidden" name="task_id" value="${taskId}"><input type="hidden" name="estimate_days" value="${daysValue}">`;
    document.body.appendChild(form);
    form.submit();
}

function openRejectModal(taskId, taskTitle) {
    document.getElementById('rejectTaskId').value = taskId;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function openGanttModal(projectId) {
    const project = ganttProjects[projectId];
    const container = document.getElementById('ganttChartContainer');
    if (!project || !project.tasks || project.tasks.length === 0) {
        container.innerHTML = '<p class="text-muted">No Gantt data available for this project.</p>';
    } else {
        if (typeof Gantt !== 'undefined') {
            container.innerHTML = '<svg id="ganttChart"></svg>';
            new Gantt("#ganttChart", project.tasks, {
                view_mode: 'Month',
                language: 'en'
            });
        } else {
            renderFallbackGantt('#ganttChartContainer', project.tasks);
        }
    }
    document.getElementById('ganttProjectTitle').textContent = project ? project.project_name : 'Project Timeline';
    new bootstrap.Modal(document.getElementById('ganttModal')).show();
}

Object.keys(ganttProjects).forEach((projectId) => {
    const data = ganttProjects[projectId];
    if (!data || !data.tasks || data.tasks.length === 0) return;
    const target = `#salesGantt_${projectId}`;
    if (document.querySelector(target)) {
        if (typeof Gantt !== 'undefined') {
            new Gantt(target, data.tasks, {
                view_mode: 'Month',
                language: 'en'
            });
        } else {
            renderFallbackGantt(target, data.tasks);
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
