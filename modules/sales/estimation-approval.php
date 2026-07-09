<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/methodology-roadmap.php';
require_once '../../includes/project-estimation.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();
projectWorkflowEnsureSchema($conn);
methodologyRoadmapEnsureSchema($conn);
projectEstimationEnsureSchema($conn);

// Ensure sales approval columns exist
$hasSalesApproval = dbColumnExists($conn, 'tasks', 'sales_approved');
if (!$hasSalesApproval) {
    try {
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved " . (DB_DRIVER === 'pgsql' ? "SMALLINT" : "TINYINT(1)") . " DEFAULT 0");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_by INT NULL");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_at " . (DB_DRIVER === 'pgsql' ? "TIMESTAMP" : "DATETIME") . " NULL");
        $hasSalesApproval = dbColumnExists($conn, 'tasks', 'sales_approved');
    } catch (Throwable $e) {
        // Continue without sales approval columns
    }
}

$hasSalesChoice = dbColumnExists($conn, 'tasks', 'sales_estimate_choice');
if (!$hasSalesChoice) {
    try {
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_estimate_choice " . (DB_DRIVER === 'pgsql' ? "TEXT" : "ENUM('min','likely','max')") . " DEFAULT NULL");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_estimate_days INT DEFAULT NULL");
        $hasSalesChoice = dbColumnExists($conn, 'tasks', 'sales_estimate_choice');
    } catch (Throwable $e) {
        // Continue without sales choice columns
    }
}

$hasProjectPhases = dbColumnExists($conn, 'projects', 'project_phases');

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_project_estimation'])) {
        $estimationId = (int) ($_POST['estimation_id'] ?? 0);
        $comment = trim((string) ($_POST['approval_comment'] ?? ''));

        $estimation = $estimationId > 0 ? $conn->query("
            SELECT e.*, p.name AS project_name
            FROM expert_project_estimations e
            JOIN projects p ON p.id = e.project_id
            WHERE e.id = {$estimationId} AND e.status = 'sul_approved'
            LIMIT 1
        ")->fetch_assoc() : null;

        if (!$estimation) {
            $_SESSION['error'] = "Please select a SUL-approved project estimation.";
            header('Location: estimation-approval.php');
            exit();
        }

        $stmt = $conn->prepare("
            UPDATE expert_project_estimations
            SET status = 'sales_approved', sales_reviewed_by = ?, sales_reviewed_at = NOW(), sales_comment = ?, approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('isi', $user['id'], $comment, $estimationId);
        if ($stmt->execute()) {
            $projectId = (int) $estimation['project_id'];
            projectEstimationCompletePlanningPhase($conn, $projectId, (int) $user['id']);
            $conn->query("UPDATE projects SET status = 'in_development' WHERE id = {$projectId} AND status != 'completed'");
            projectWorkflowSyncRoadmap($conn, $projectId);
            $projectDetails = $conn->query("SELECT * FROM projects WHERE id = {$projectId} LIMIT 1")->fetch_assoc() ?: [];
            $roadmapUrl = BASE_URL . 'modules/roadmap/methodology.php?project_id=' . $projectId;

            $architectId = (int) ($projectDetails['architect_id'] ?? 0);
            if ($architectId > 0) {
                createNotification(
                    $architectId,
                    'Project Estimation Approved',
                    'Sales approved the project estimation. Planning is complete and the next project phase is now active.',
                    'approval',
                    'projects',
                    $projectId,
                    $roadmapUrl
                );
            } else {
                workflowNotifyRoles(
                    $conn,
                    ['architect'],
                    'Project Estimation Approved',
                    'Sales approved the project estimation. Planning is complete and the next project phase is now active.',
                    $roadmapUrl,
                    $projectId,
                    'approval',
                    'projects'
                );
            }
            logActivity((int) $user['id'], 'sales_approve_project_estimation', 'expert_project_estimations', $estimationId, null, $_POST);
            $_SESSION['success'] = "Project estimation approved. Planning phase completed and the next phase is active.";
        } else {
            $_SESSION['error'] = "Failed to approve project estimation.";
        }
        $stmt->close();
        header('Location: estimation-approval.php');
        exit();
    }

    if (isset($_POST['reject_project_estimation'])) {
        $estimationId = (int) ($_POST['estimation_id'] ?? 0);
        $reason = trim((string) ($_POST['rejection_reason'] ?? ''));

        $estimation = $estimationId > 0 ? $conn->query("
            SELECT e.*, p.name AS project_name
            FROM expert_project_estimations e
            JOIN projects p ON p.id = e.project_id
            WHERE e.id = {$estimationId} AND e.status = 'sul_approved'
            LIMIT 1
        ")->fetch_assoc() : null;

        if (!$estimation || $reason === '') {
            $_SESSION['error'] = "Please provide a project estimation and rejection reason.";
            header('Location: estimation-approval.php');
            exit();
        }

        $stmt = $conn->prepare("
            UPDATE expert_project_estimations
            SET status = 'sales_rejected', sales_reviewed_by = ?, sales_reviewed_at = NOW(), sales_comment = ?
            WHERE id = ?
        ");
        $stmt->bind_param('isi', $user['id'], $reason, $estimationId);
        if ($stmt->execute()) {
            createNotification(
                (int) $estimation['expert_id'],
                "Sales Requested Project Estimation Revision: " . ($estimation['project_name'] ?? 'Project'),
                "Sales rejected the final estimation. Reason: " . $reason,
                'approval',
                'expert_project_estimations',
                $estimationId,
                BASE_URL . 'modules/expert/feature-estimation.php?project_estimation_id=' . (int) $estimation['project_id']
            );
            logActivity((int) $user['id'], 'sales_reject_project_estimation', 'expert_project_estimations', $estimationId, null, $_POST);
            $_SESSION['success'] = "Project estimation rejected and returned to Expert Developer.";
        } else {
            $_SESSION['error'] = "Failed to reject project estimation.";
        }
        $stmt->close();
        header('Location: estimation-approval.php');
        exit();
    }

    if (isset($_POST['approve_estimation'])) {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $estimateChoice = trim((string) ($_POST['estimate_choice'] ?? ''));
        $allowedChoices = ['min', 'likely', 'max'];
        $estimateDays = 0;

        if ($taskId > 0) {
            if (!in_array($estimateChoice, $allowedChoices, true)) {
                $_SESSION['error'] = "Please choose Minimum, Most Likely, or Maximum before approving.";
                header('Location: estimation-approval.php');
                exit();
            }

            $estimateSource = $conn->query("
                SELECT fe.min_days, fe.likely_days, fe.max_days
                FROM feature_estimations fe
                WHERE fe.task_id = $taskId AND fe.status = 'completed'
                ORDER BY fe.submitted_at DESC, fe.id DESC
                LIMIT 1
            ")->fetch_assoc();

            if ($estimateSource) {
                $estimateDays = (int) ($estimateSource[$estimateChoice . '_days'] ?? 0);
            }

            if ($estimateDays <= 0) {
                $_SESSION['error'] = "The selected timeline option does not have a valid number of days.";
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
                if ($hasSalesChoice) {
                    $stmt = $conn->prepare("UPDATE tasks SET status = 'review', sales_approved = 1, sales_approved_by = ?, sales_approved_at = NOW(), sales_estimate_choice = ?, sales_estimate_days = ?{$deadlineSql} WHERE id = ?");
                    $stmt->bind_param("isii", $user['id'], $estimateChoice, $estimateDays, $taskId);
                } else {
                    $stmt = $conn->prepare("UPDATE tasks SET status = 'review', sales_approved = 1, sales_approved_by = ?, sales_approved_at = NOW(){$deadlineSql} WHERE id = ?");
                    $stmt->bind_param("ii", $user['id'], $taskId);
                }
            } else {
                $stmt = $conn->prepare("UPDATE tasks SET status = 'review' WHERE id = ?");
                $stmt->bind_param("i", $taskId);
            }

            if ($stmt->execute()) {
                $taskDetails = $conn->query("SELECT title, project_id FROM tasks WHERE id = $taskId")->fetch_assoc();
                $targetDepartment = projectWorkflowTaskDepartment($conn, $taskId);
                if ($targetDepartment !== '') {
                    projectWorkflowNotifyDepartmentSuls(
                        $conn,
                        $targetDepartment,
                        "Sales Approved Estimation: " . ($taskDetails['title'] ?? 'Task'),
                        "Sales approved the estimation. Task is ready for SUL review.",
                        BASE_URL . 'modules/sul/task-allocation.php?project_id=' . (int) ($taskDetails['project_id'] ?? 0),
                        (int) ($taskDetails['project_id'] ?? 0)
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
$pendingProjectEstimations = $conn->query("
    SELECT e.*, p.name AS project_name, p.timeline_start, p.timeline_end, p.sales_budget, c.name AS client_name, u.full_name AS expert_name
    FROM expert_project_estimations e
    JOIN projects p ON p.id = e.project_id
    JOIN clients c ON c.id = p.client_id
    JOIN users u ON u.id = e.expert_id
    WHERE e.status = 'sul_approved'
    ORDER BY e.sul_reviewed_at ASC, e.id ASC
");

$projectEstimationsByProject = [];
while ($estimateRow = $pendingProjectEstimations->fetch_assoc()) {
    $projectEstimationsByProject[(int) $estimateRow['project_id']] = $estimateRow;
}

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

$approvalProjectIds = array_keys($projectEstimationsByProject);
foreach (array_keys($tasksByProject) as $taskProjectId) {
    if (!in_array($taskProjectId, $approvalProjectIds, true)) {
        $approvalProjectIds[] = $taskProjectId;
    }
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
                    'custom_class' => 'gantt-phase',
                    'is_phase' => true
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
            $duration = (int) ($task['max_days'] ?? 0);
            if ($duration <= 0) {
                $duration = (int) ($task['likely_days'] ?? 0);
            }
            if ($duration <= 0) {
                $duration = (int) ($task['min_days'] ?? 0);
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
            'custom_class' => 'gantt-task',
            'min_days' => (int) ($task['min_days'] ?? 0),
            'likely_days' => (int) ($task['likely_days'] ?? 0),
            'max_days' => (int) ($task['max_days'] ?? 0),
            'sales_estimate_choice' => $task['sales_estimate_choice'] ?? null,
            'sales_estimate_days' => (int) ($task['sales_estimate_days'] ?? 0),
            'is_phase' => false
        ];
    }

    $ganttProjects[$pid] = [
        'project_name' => $project['project_name'],
        'tasks' => $ganttTasks
    ];
}

$flashSuccess = trim((string) ($_SESSION['success'] ?? ''));
$flashError = trim((string) ($_SESSION['error'] ?? ''));
unset($_SESSION['success'], $_SESSION['error']);

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

.approval-feedback {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    margin-bottom: 1.25rem;
    padding: 0.9rem 1rem;
    border: 1px solid;
    border-radius: 14px;
    box-shadow: var(--shadow-sm);
}

.approval-feedback__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    color: #fff;
}

.approval-feedback__content strong,
.approval-feedback__content span {
    display: block;
}

.approval-feedback__content strong { margin-bottom: 0.08rem; font-size: 0.82rem; }
.approval-feedback__content span { font-size: 0.76rem; line-height: 1.4; }
.approval-feedback.is-success { border-color: #bbf7d0; background: #f0fdf4; color: #166534; }
.approval-feedback.is-success .approval-feedback__icon { background: #16a34a; }
.approval-feedback.is-error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
.approval-feedback.is-error .approval-feedback__icon { background: #dc2626; }

.project-approval-group {
    display: grid;
    gap: 0;
    padding: 0;
    margin-bottom: 1.35rem;
    border-radius: 24px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbfe 100%);
    border: 1px solid #dfe9f1;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.project-approval-toggle {
    width: 100%;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    padding: 1.25rem;
    border: none;
    background: transparent;
    text-align: left;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.project-approval-toggle:hover {
    background: rgba(248, 250, 252, 0.85);
}

.project-approval-toggle:focus-visible {
    outline: 3px solid rgba(31, 99, 146, 0.18);
    outline-offset: -3px;
}

.project-approval-toggle-main {
    min-width: 0;
    display: grid;
    gap: 0.55rem;
}

.project-approval-toggle-side {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.project-approval-kicker {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.38rem 0.72rem;
    border-radius: 999px;
    background: rgba(31, 99, 146, 0.08);
    color: var(--theme-primary);
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.project-approval-head h5 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1f2937;
}

.project-approval-head p {
    margin: 0;
    color: #64748b;
    font-size: 0.82rem;
    line-height: 1.55;
    max-width: 680px;
}

.project-approval-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
}

.project-approval-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.48rem 0.78rem;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #e3ebf2;
    color: #475569;
    font-size: 0.74rem;
    font-weight: 700;
    line-height: 1;
}

.project-approval-chevron {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.2rem;
    height: 2.2rem;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #e3ebf2;
    color: var(--theme-primary);
    font-size: 0.9rem;
    flex: 0 0 2.2rem;
    transition: transform 0.2s ease;
}

.project-approval-group.is-open .project-approval-chevron {
    transform: rotate(180deg);
}

.project-approval-body {
    display: none;
    padding: 0 1.25rem 1.25rem;
    background: linear-gradient(180deg, rgba(248, 251, 254, 0.55) 0%, rgba(255, 255, 255, 0) 100%);
}

.project-approval-group.is-open .project-approval-body {
    display: grid;
    gap: 1.1rem;
}

.sales-project-queue {
    margin-bottom: 1.35rem;
}

.sales-project-queue-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.7rem;
    padding: 0 0.15rem;
}

.sales-project-queue-head h5 {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
    color: #1f2937;
    font-size: 0.9rem;
    font-weight: 700;
}

.sales-project-queue-head h5 i {
    color: var(--theme-primary);
}

.sales-project-queue-count {
    padding: 0.32rem 0.62rem;
    border: 1px solid #dfe8ef;
    border-radius: 999px;
    background: #fff;
    color: #64748b;
    font-size: 0.7rem;
    font-weight: 700;
    white-space: nowrap;
}

.sales-cost-list {
    display: grid;
    gap: 0.7rem;
}

.sales-cost-card {
    overflow: hidden;
    border: 1px solid #e1eaf2;
    border-radius: 16px;
    background: #fff;
    box-shadow: var(--shadow-sm);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.sales-cost-card.is-open {
    border-color: rgba(31, 99, 146, 0.45);
    box-shadow: 0 12px 28px rgba(15, 42, 64, 0.08);
}

.sales-cost-toggle {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto auto;
    align-items: center;
    gap: 1rem;
    width: 100%;
    padding: 0.95rem 1rem;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.sales-cost-toggle:hover { background: #f8fbfd; }
.sales-cost-toggle:focus-visible { outline: 3px solid rgba(31, 99, 146, 0.2); outline-offset: -3px; }

.sales-cost-identity {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
}

.sales-cost-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: #eaf5fb;
    color: var(--theme-primary);
}

.sales-cost-copy { min-width: 0; }
.sales-cost-title { display: block; overflow: hidden; color: #1f2937; font-size: 0.92rem; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
.sales-cost-meta { display: flex; flex-wrap: wrap; gap: 0.3rem 0.8rem; margin-top: 0.22rem; color: #64748b; font-size: 0.7rem; }
.sales-cost-meta span { display: inline-flex; align-items: center; gap: 0.3rem; }

.sales-cost-amount {
    display: grid;
    gap: 0.08rem;
    text-align: right;
    white-space: nowrap;
}

.sales-cost-amount small { color: #64748b; font-size: 0.62rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
.sales-cost-amount strong { color: var(--theme-primary); font-size: 0.94rem; }

.sales-cost-status {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 0.68rem;
    font-weight: 700;
    white-space: nowrap;
}

.sales-cost-chevron {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: #f1f5f9;
    color: #64748b;
    transition: transform 0.2s ease, background 0.2s ease;
}

.sales-cost-toggle[aria-expanded="true"] .sales-cost-chevron { transform: rotate(180deg); background: #e8f3fa; color: var(--theme-primary); }

.sales-cost-details {
    padding: 1rem;
    border-top: 1px solid #e5edf5;
    background: #fbfcfe;
}

.sales-project-cost-review {
    padding: 1rem;
    border: 1px solid #dfe8ef;
    border-radius: 16px;
    background: #fbfcfe;
}

.sales-project-cost-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.8rem;
}

.sales-project-cost-head h6 {
    margin: 0;
    color: #1f2937;
    font-size: 0.9rem;
    font-weight: 700;
}

.sales-project-cost-head p {
    margin: 0.18rem 0 0;
    color: #64748b;
    font-size: 0.72rem;
}

.sales-project-cost-total {
    display: grid;
    gap: 0.08rem;
    text-align: right;
    white-space: nowrap;
}

.sales-project-cost-total span {
    color: #64748b;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.sales-project-cost-total strong {
    color: var(--theme-primary);
    font-size: 0.96rem;
}

.sales-cost-breakdown {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.65rem;
}

.sales-cost-metric {
    min-width: 0;
    padding: 0.78rem 0.85rem;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
}

.sales-cost-metric span { display: block; color: #64748b; font-size: 0.64rem; font-weight: 700; letter-spacing: 0.035em; text-transform: uppercase; }
.sales-cost-metric strong { display: block; margin-top: 0.2rem; overflow-wrap: anywhere; color: #334155; font-size: 0.9rem; }
.sales-cost-metric.is-primary { border-color: #bfdbfe; background: #eff6ff; }
.sales-cost-metric.is-primary strong { color: #1d4ed8; }
.sales-cost-metric.is-negative { border-color: #fecaca; background: #fef2f2; }
.sales-cost-metric.is-negative strong { color: #b91c1c; }

.sales-sul-note {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    margin-top: 0.75rem;
    padding: 0.8rem 0.9rem;
    border: 1px solid #dbe7f0;
    border-radius: 12px;
    background: #f5f9fc;
    color: #475569;
    font-size: 0.78rem;
    line-height: 1.5;
}

.sales-sul-note i { margin-top: 0.15rem; color: var(--theme-primary); }

.sales-cost-decisions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin-top: 0.85rem;
}

.sales-decision-card {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    min-width: 0;
    margin: 0;
    padding: 0.9rem;
    border: 1px solid #e2e8f0;
    border-radius: 13px;
    background: #fff;
}

.sales-decision-head { display: flex; align-items: flex-start; gap: 0.55rem; }
.sales-decision-head i { margin-top: 0.12rem; }
.sales-decision-head strong, .sales-decision-head span { display: block; }
.sales-decision-head strong { color: #334155; font-size: 0.8rem; }
.sales-decision-head span { margin-top: 0.1rem; color: #64748b; font-size: 0.68rem; line-height: 1.35; }
.sales-decision-card.is-approve .sales-decision-head i { color: #059669; }
.sales-decision-card.is-revision .sales-decision-head i { color: #d97706; }
.sales-decision-card label { margin-bottom: -0.35rem; color: #475569; font-size: 0.7rem; font-weight: 700; }
.sales-decision-card label span { color: #94a3b8; font-weight: 500; }
.sales-decision-card textarea { min-height: 76px; resize: vertical; }
.sales-decision-card button { justify-content: center; min-height: 40px; margin-top: auto; }

.project-gantt-panel {
    padding: 1rem 1.1rem;
    border-radius: 20px;
    background: linear-gradient(180deg, #fbfdff 0%, #f8fafc 100%);
    border: 1px solid #e2e8f0;
}

.project-gantt-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
    flex-wrap: wrap;
    margin-bottom: 0.9rem;
}

.project-gantt-head h6 {
    margin: 0;
    font-size: 0.96rem;
    font-weight: 700;
    color: #1f2937;
}

.project-gantt-head p {
    margin: 0.25rem 0 0;
    font-size: 0.8rem;
    color: #64748b;
}

.project-feature-stack {
    display: grid;
    gap: 1rem;
}

.task-card {
    background: white;
    border-radius: 20px;
    padding: 1.25rem;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
    margin-bottom: 1rem;
    transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.task-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.task-meta {
    font-size: 0.75rem;
    color: var(--gray-600);
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 0.5rem;
}

.approval-task-card {
    padding: 1.5rem;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    margin-bottom: 0;
}

.approval-card-main {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.1rem;
    min-height: 100%;
}

.approval-card-content {
    min-width: 0;
}

.approval-feature-header {
    display: grid;
    gap: 0.9rem;
    margin-bottom: 1.1rem;
}

.approval-feature-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    width: fit-content;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    background: rgba(31, 99, 146, 0.08);
    color: var(--theme-primary);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.approval-feature-title {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 700;
    color: #1f2937;
    line-height: 1.25;
}

.approval-meta-list {
    gap: 0.65rem;
    margin-top: 0;
}

.approval-meta-list span {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.7rem;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #e5edf5;
    color: #52606d;
    font-size: 0.74rem;
    line-height: 1;
}

.expectation-note {
    margin: 0;
    padding: 0.9rem 1rem;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.06) 0%, rgba(31, 99, 146, 0.02) 100%);
    border: 1px solid rgba(31, 99, 146, 0.1);
    color: #4b6477;
    font-size: 0.86rem;
    line-height: 1.6;
}

.timeline-panel {
    display: grid;
    gap: 1rem;
    padding: 1.15rem;
    border-radius: 20px;
    background: #ffffff;
    border: 1px solid #e6eef5;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.timeline-panel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.timeline-panel-head h6 {
    margin: 0.15rem 0 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #1f2937;
}

.timeline-kicker {
    display: inline-block;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--theme-primary);
}

.timeline-helper {
    margin: 0.35rem 0 0;
    font-size: 0.82rem;
    color: #64748b;
}

.timeline-pert-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 0.8rem;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #334155;
    font-size: 0.76rem;
    font-weight: 700;
    white-space: nowrap;
}

.btn-action {
    border: none;
    padding: 0.72rem 1rem;
    border-radius: 12px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
    min-width: 124px;
}

.btn-approve {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.btn-approve:disabled {
    background: #adb5bd;
    cursor: not-allowed;
    opacity: 0.7;
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
    box-shadow: var(--shadow-md);
}

.gantt-preview {
    display: grid;
    gap: 0.8rem;
    margin-top: 0;
}

.gantt-row-preview {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.gantt-label {
    width: 68px;
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
    transition: width 0.25s ease;
}

.min-bar { background: #60a5fa; }
.likely-bar { background: #34d399; }
.max-bar { background: #f97316; }

.timeline-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
}

.timeline-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.65rem;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #52606d;
    font-size: 0.72rem;
    font-weight: 600;
}

.timeline-legend-dot {
    width: 0.7rem;
    height: 0.7rem;
    border-radius: 999px;
    flex: 0 0 0.7rem;
}

.timeline-legend-dot.min { background: #60a5fa; }
.timeline-legend-dot.likely { background: #34d399; }
.timeline-legend-dot.max { background: #f97316; }

.choice-group {
    display: grid;
    gap: 0.9rem;
    padding-top: 0.15rem;
}

.choice-group-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.choice-group-head .form-label {
    margin: 0;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--theme-primary);
}

.choice-group-head p {
    margin: 0.3rem 0 0;
    font-size: 0.82rem;
    color: #64748b;
    line-height: 1.55;
}

.estimate-choice-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 0.75rem;
    width: 100%;
}

.estimate-choice-card {
    position: relative;
    display: block;
    margin: 0;
    cursor: pointer;
}

.estimate-choice-card input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.estimate-choice-body {
    display: grid;
    gap: 0.35rem;
    height: 100%;
    padding: 1rem 1rem 0.95rem;
    border: 1px solid #dfe8ef;
    border-radius: 18px;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    box-shadow: var(--shadow-sm);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}

.estimate-choice-card:hover .estimate-choice-body {
    border-color: #c8d9e6;
    transform: translateY(-1px);
}

.estimate-choice-card input:checked + .estimate-choice-body {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12), 0 12px 24px rgba(31, 99, 146, 0.08);
    transform: translateY(-1px);
}

.estimate-choice-tone {
    display: inline-flex;
    width: fit-content;
    padding: 0.32rem 0.58rem;
    border-radius: 999px;
    background: #eff6ff;
    color: var(--theme-primary);
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.estimate-choice-title {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--gray-800);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.estimate-choice-days {
    font-size: 1.18rem;
    font-weight: 700;
    color: var(--theme-primary);
}

.estimate-choice-caption {
    font-size: 0.76rem;
    color: #64748b;
    line-height: 1.45;
}

.choice-footer {
    display: grid;
    gap: 0.55rem;
}

.choice-range-note {
    font-size: 0.8rem;
    color: #64748b;
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
    background: linear-gradient(180deg, #fbfdff 0%, #f8fafc 100%);
    border: 1px solid var(--gray-200);
    border-radius: 18px;
}

.gantt-phase .bar {
    fill: #1f6392;
}

.sales-choice-status {
    display: block;
    padding: 0.8rem 0.95rem;
    border-radius: 14px;
    background: rgba(31, 99, 146, 0.06);
    border: 1px solid rgba(31, 99, 146, 0.1);
    font-size: 0.8rem;
    color: var(--theme-primary);
    font-weight: 700;
    line-height: 1.5;
}

.approval-actions {
    display: flex;
    gap: 0.75rem;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    min-width: 0;
    padding-top: 1rem;
    border-top: 1px solid #e6eef5;
}

.approval-actions-label {
    display: none;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-group,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-task-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    border: 1px solid rgba(31, 99, 146, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-toggle:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-gantt-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-meta-list span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expectation-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-pert-badge,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-legend-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .estimate-choice-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .estimate-choice-tone,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-choice-status,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-pill,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-chevron {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-gantt-head h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-feature-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-panel-head h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .estimate-choice-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-title {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-head p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-pill,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-gantt-head p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-meta-list span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expectation-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-helper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-pert-badge,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-legend-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .choice-group-head p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .estimate-choice-caption,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .choice-range-note {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-kicker,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-feature-eyebrow {
    background: rgba(31, 99, 146, 0.14);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-actions,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .estimate-choice-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-bar-track,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-view {
    background: rgba(157, 182, 207, 0.18);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern textarea {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern textarea:focus {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.45);
    color: #d7e4f2;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-project-cost-review,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-metric,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-decision-card {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-toggle:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-details,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-sul-note {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-project-queue-head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-project-cost-head h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-metric strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-decision-head strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-amount small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-project-cost-head p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-project-cost-total span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-metric span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-sul-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-decision-head span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-decision-card label {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-decision-card .form-control {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-decision-card .form-control:focus {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.45);
    color: #d7e4f2;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-metric.is-primary {
    background: rgba(29, 78, 216, 0.14);
    border-color: rgba(96, 165, 250, 0.34);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-metric.is-primary strong { color: #93c5fd; }

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-metric.is-negative {
    background: rgba(185, 28, 28, 0.14);
    border-color: rgba(248, 113, 113, 0.32);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-metric.is-negative strong { color: #fca5a5; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-status { background: rgba(29, 78, 216, 0.18); color: #93c5fd; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-cost-chevron { background: #10263a; color: #9db6cf; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-project-queue-count { background: #071321; border-color: rgba(31, 99, 146, 0.28); color: #9db6cf; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-feedback.is-success { border-color: rgba(34, 197, 94, 0.34); background: rgba(22, 101, 52, 0.2); color: #bbf7d0; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-feedback.is-error { border-color: rgba(248, 113, 113, 0.32); background: rgba(153, 27, 27, 0.2); color: #fecaca; }

@media (max-width: 991.98px) {
    .approval-actions {
        justify-content: flex-end;
    }
}

@media (max-width: 767.98px) {
    .project-approval-group {
        border-radius: 20px;
    }

    .project-approval-toggle {
        padding: 1rem;
    }

    .project-approval-body {
        padding: 0 1rem 1rem;
    }

    .approval-task-card {
        padding: 1rem;
    }

    .approval-feature-title {
        font-size: 1.15rem;
    }

    .timeline-panel {
        padding: 1rem;
    }

    .gantt-row-preview {
        align-items: flex-start;
        flex-direction: column;
    }

    .gantt-label {
        width: auto;
    }

    .approval-actions {
        justify-content: stretch;
    }

    .approval-actions .btn-action {
        flex: 1 1 100%;
    }

    .sales-cost-toggle {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.55rem 0.75rem;
    }

    .sales-cost-identity { grid-column: 1; grid-row: 1; }
    .sales-cost-chevron { grid-column: 2; grid-row: 1; }
    .sales-cost-amount { grid-column: 1; grid-row: 2; padding-left: 3.4rem; text-align: left; }
    .sales-cost-status { display: none; }
    .sales-cost-breakdown, .sales-cost-decisions { grid-template-columns: 1fr; }
}
</style>

<div class="container-fluid px-4 py-4">
    <div class="page-header-modern">
        <h2><i class="fas fa-clipboard-check me-2"></i> Estimation Approval</h2>
        <p>Review project costs and feature timelines, then make the final Sales decision.</p>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="approval-feedback is-success" role="status">
            <span class="approval-feedback__icon" aria-hidden="true"><i class="fas fa-check"></i></span>
            <span class="approval-feedback__content"><strong>Action completed successfully</strong><span><?php echo htmlspecialchars($flashSuccess); ?></span></span>
        </div>
    <?php elseif ($flashError !== ''): ?>
        <div class="approval-feedback is-error" role="alert">
            <span class="approval-feedback__icon" aria-hidden="true"><i class="fas fa-exclamation"></i></span>
            <span class="approval-feedback__content"><strong>Action could not be completed</strong><span><?php echo htmlspecialchars($flashError); ?></span></span>
        </div>
    <?php endif; ?>

    <?php if ($approvalProjectIds !== []): ?>
        <div class="sales-project-queue-head">
            <h5><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>Projects awaiting approval</h5>
            <span class="sales-project-queue-count"><?php echo count($approvalProjectIds); ?> project<?php echo count($approvalProjectIds) === 1 ? '' : 's'; ?></span>
        </div>
        <?php foreach ($approvalProjectIds as $pid): ?>
            <?php
                $estimate = $projectEstimationsByProject[$pid] ?? null;
                $projectTasks = $tasksByProject[$pid] ?? [];
                $projectTitle = (string) ($estimate['project_name'] ?? ($projectTasks[0]['project_name'] ?? 'Project'));
                $featureCount = count($projectTasks);
            ?>
            <section class="project-approval-group" data-project-group data-project-id="<?php echo (int) $pid; ?>">
                <button
                    type="button"
                    class="project-approval-toggle"
                    data-project-toggle
                    aria-expanded="false"
                    aria-controls="projectApprovalBody_<?php echo (int) $pid; ?>"
                >
                    <div class="project-approval-toggle-main project-approval-head">
                        <span class="project-approval-kicker"><i class="fas fa-diagram-project"></i> Project Approval Review</span>
                        <h5><?php echo htmlspecialchars($projectTitle); ?></h5>
                        <p>Review all pending cost and feature decisions for this project in one place.</p>
                    </div>
                    <div class="project-approval-toggle-side">
                        <div class="project-approval-summary">
                            <?php if ($estimate): ?>
                                <span class="project-approval-pill"><i class="fas fa-file-invoice-dollar"></i> Final cost review</span>
                            <?php endif; ?>
                            <?php if ($featureCount > 0): ?>
                                <span class="project-approval-pill"><i class="fas fa-layer-group"></i> <?php echo $featureCount; ?> pending feature<?php echo $featureCount === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="project-approval-chevron" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                    </div>
                </button>

                <div
                    class="project-approval-body"
                    id="projectApprovalBody_<?php echo (int) $pid; ?>"
                    data-project-body
                    hidden
                >
                    <?php if ($estimate): ?>
                        <section class="sales-project-cost-review" aria-labelledby="projectCostReviewTitle_<?php echo (int) $pid; ?>">
                            <div class="sales-project-cost-head">
                                <div>
                                    <h6 id="projectCostReviewTitle_<?php echo (int) $pid; ?>"><i class="fas fa-file-invoice-dollar me-1" aria-hidden="true"></i> Final Project Cost Review</h6>
                                    <p>Approve the overall project budget or return it for revision.</p>
                                </div>
                                <span class="sales-project-cost-total"><span>Overall estimate</span><strong><?php echo projectEstimationPeso((float) $estimate['overall_estimated_project_cost']); ?></strong></span>
                            </div>

                            <div class="sales-cost-breakdown" aria-label="Project cost breakdown">
                                <div class="sales-cost-metric"><span>Developer cost</span><strong><?php echo projectEstimationPeso((float) $estimate['total_developer_cost']); ?></strong></div>
                                <div class="sales-cost-metric"><span>Technology stack</span><strong><?php echo projectEstimationPeso((float) $estimate['total_technology_stack_cost']); ?></strong></div>
                                <div class="sales-cost-metric is-primary"><span>Overall estimate</span><strong><?php echo projectEstimationPeso((float) $estimate['overall_estimated_project_cost']); ?></strong></div>
                                <div class="sales-cost-metric"><span>Client budget</span><strong><?php echo projectEstimationPeso((float) $estimate['sales_budget']); ?></strong></div>
                                <div class="sales-cost-metric <?php echo (float) $estimate['remaining_budget'] < 0 ? 'is-negative' : ''; ?>"><span>Remaining budget</span><strong><?php echo projectEstimationPeso((float) $estimate['remaining_budget']); ?></strong></div>
                                <div class="sales-cost-metric"><span>Additional costs</span><strong><?php echo projectEstimationPeso((float) $estimate['additional_costs']); ?></strong></div>
                            </div>

                            <div class="sales-sul-note">
                                <i class="fas fa-message" aria-hidden="true"></i>
                                <span><strong>SUL review note:</strong> <?php echo !empty($estimate['sul_comment']) ? htmlspecialchars((string) $estimate['sul_comment']) : 'No additional comment was provided.'; ?></span>
                            </div>

                            <div class="sales-cost-decisions">
                                <form method="POST" class="sales-decision-card is-approve" data-generic-draft-disabled="1" data-app-loading data-loading-title="Approving project estimation" data-loading-text="Completing Planning and opening the next phase.">
                                    <input type="hidden" name="estimation_id" value="<?php echo (int) $estimate['id']; ?>">
                                    <div class="sales-decision-head">
                                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                                        <span><strong>Approve project estimate</strong><span>Finalize the cost, complete Planning, and activate the next roadmap phase.</span></span>
                                    </div>
                                    <label for="salesApprovalNote<?php echo (int) $estimate['id']; ?>">Sales note <span>(optional)</span></label>
                                    <textarea id="salesApprovalNote<?php echo (int) $estimate['id']; ?>" name="approval_comment" class="form-control" rows="2" placeholder="Add a short final approval note"></textarea>
                                    <button class="btn-action btn-approve" type="submit" name="approve_project_estimation"><i class="fas fa-check me-1"></i> Final Approve</button>
                                </form>

                                <form method="POST" class="sales-decision-card is-revision" data-generic-draft-disabled="1" data-app-loading data-loading-title="Rejecting project estimation" data-loading-text="Returning the estimation to the expert.">
                                    <input type="hidden" name="estimation_id" value="<?php echo (int) $estimate['id']; ?>">
                                    <div class="sales-decision-head">
                                        <i class="fas fa-rotate-left" aria-hidden="true"></i>
                                        <span><strong>Request revision</strong><span>Return the package to the Expert with specific changes.</span></span>
                                    </div>
                                    <label for="salesRevisionReason<?php echo (int) $estimate['id']; ?>">Revision reason <span>(required)</span></label>
                                    <textarea id="salesRevisionReason<?php echo (int) $estimate['id']; ?>" name="rejection_reason" class="form-control" rows="2" required placeholder="Explain what needs to be revised"></textarea>
                                    <button class="btn-action btn-reject" type="submit" name="reject_project_estimation"><i class="fas fa-rotate-left me-1"></i> Return for Revision</button>
                                </form>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if ($featureCount > 0): ?>
                    <div class="project-gantt-panel">
                        <div class="project-gantt-head">
                            <div>
                                <h6>Project Gantt Overview</h6>
                                <p>The gantt stays attached to this project so the feature approvals below are easier to review in context.</p>
                            </div>
                            <a href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php?project_id=<?php echo (int) $pid; ?>" class="btn-action text-decoration-none">
                                <i class="fas fa-route"></i> Roadmap
                            </a>
                        </div>
                        <div id="salesGantt_<?php echo (int) $pid; ?>"></div>
                    </div>
                    <div class="project-feature-stack">
                        <?php foreach ($projectTasks as $task): ?>
                            <?php 
                                $minDays = (float) ($task['min_days'] ?? 0);
                                $likelyDays = (float) ($task['likely_days'] ?? 0);
                                $maxDays = (float) ($task['max_days'] ?? 0);
                                $pert = round(($minDays + 4*$likelyDays + $maxDays)/6, 1);
                                $deadlineLabel = !empty($task['deadline']) ? date('M d, Y', strtotime($task['deadline'])) : 'No deadline yet';
                                $expectationDescription = trim((string) ($task['expectation_description'] ?? ''));
                            ?>
                            <div class="task-card approval-task-card">
                                <div class="approval-card-main">
                                    <div class="approval-card-content">
                                        <div class="approval-feature-header">
                                            <div class="approval-feature-eyebrow"><i class="fas fa-layer-group"></i> Feature Pending Approval</div>
                                            <div>
                                                <h6 class="approval-feature-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                                                <div class="task-meta approval-meta-list">
                                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($task['expert_name']); ?></span>
                                                    <span><i class="far fa-calendar-alt"></i> Due: <?php echo htmlspecialchars($deadlineLabel); ?></span>
                                                    <span><i class="fas fa-chart-line"></i> PERT: <?php echo $pert; ?> days</span>
                                                </div>
                                            </div>
                                            <?php if ($expectationDescription !== ''): ?>
                                                <p class="expectation-note"><?php echo htmlspecialchars($expectationDescription); ?></p>
                                            <?php endif; ?>
                                        </div>

                                        <div class="timeline-panel">
                                            <div class="timeline-panel-head">
                                                <div>
                                                    <span class="timeline-kicker">Timeline Preview</span>
                                                    <h6>Finalize Timeline Scenario</h6>
                                                    <p class="timeline-helper">Compare the expert range, then select the delivery commitment Sales will approve for this feature.</p>
                                                </div>
                                                <span class="timeline-pert-badge"><i class="fas fa-wave-square"></i> PERT <?php echo $pert; ?> days</span>
                                            </div>

                                            <div class="gantt-preview" data-task-id="<?php echo (int) $task['id']; ?>">
                                                <div class="gantt-row-preview">
                                                    <span class="gantt-label">Days</span>
                                                    <div class="gantt-bar-track">
                                                        <div class="gantt-bar min-bar" style="width: <?php echo $maxDays > 0 ? ($minDays / $maxDays * 100) : 0; ?>%;"></div>
                                                        <div class="gantt-bar likely-bar" style="width: <?php echo $maxDays > 0 ? (max($likelyDays - $minDays, 0) / $maxDays * 100) : 0; ?>%;"></div>
                                                        <div class="gantt-bar max-bar" style="width: <?php echo $maxDays > 0 ? (max($maxDays - max($likelyDays, $minDays), 0) / $maxDays * 100) : 0; ?>%;"></div>
                                                    </div>
                                                </div>
                                                <div class="timeline-legend">
                                                    <span class="timeline-legend-item"><span class="timeline-legend-dot min"></span> Minimum: <?php echo (int) $minDays; ?> day<?php echo (int) $minDays === 1 ? '' : 's'; ?></span>
                                                    <span class="timeline-legend-item"><span class="timeline-legend-dot likely"></span> Most Likely: <?php echo (int) $likelyDays; ?> day<?php echo (int) $likelyDays === 1 ? '' : 's'; ?></span>
                                                    <span class="timeline-legend-item"><span class="timeline-legend-dot max"></span> Maximum: <?php echo (int) $maxDays; ?> day<?php echo (int) $maxDays === 1 ? '' : 's'; ?></span>
                                                </div>
                                            </div>

                                            <div class="choice-group">
                                                <div class="choice-group-head">
                                                    <div>
                                                        <label class="form-label mb-0">Sales Commitment</label>
                                                        <p>Pick the timeline scenario that should become the official committed duration for this feature.</p>
                                                    </div>
                                                </div>

                                                <div class="estimate-choice-list">
                                                    <label class="estimate-choice-card">
                                                        <input type="radio" name="estimate_choice_<?php echo (int) $task['id']; ?>" value="min" data-days="<?php echo (int) $minDays; ?>">
                                                        <span class="estimate-choice-body">
                                                            <span class="estimate-choice-tone">Fastest</span>
                                                            <span class="estimate-choice-title">Minimum</span>
                                                            <span class="estimate-choice-days"><?php echo (int) $minDays; ?> day<?php echo (int) $minDays === 1 ? '' : 's'; ?></span>
                                                            <span class="estimate-choice-caption">Use the shortest delivery promise when the scope is tightly controlled.</span>
                                                        </span>
                                                    </label>
                                                    <label class="estimate-choice-card">
                                                        <input type="radio" name="estimate_choice_<?php echo (int) $task['id']; ?>" value="likely" data-days="<?php echo (int) $likelyDays; ?>">
                                                        <span class="estimate-choice-body">
                                                            <span class="estimate-choice-tone">Balanced</span>
                                                            <span class="estimate-choice-title">Most Likely</span>
                                                            <span class="estimate-choice-days"><?php echo (int) $likelyDays; ?> day<?php echo (int) $likelyDays === 1 ? '' : 's'; ?></span>
                                                            <span class="estimate-choice-caption">Use the most realistic scenario for standard execution and normal risk.</span>
                                                        </span>
                                                    </label>
                                                    <label class="estimate-choice-card">
                                                        <input type="radio" name="estimate_choice_<?php echo (int) $task['id']; ?>" value="max" data-days="<?php echo (int) $maxDays; ?>">
                                                        <span class="estimate-choice-body">
                                                            <span class="estimate-choice-tone">Safest Buffer</span>
                                                            <span class="estimate-choice-title">Maximum</span>
                                                            <span class="estimate-choice-days"><?php echo (int) $maxDays; ?> day<?php echo (int) $maxDays === 1 ? '' : 's'; ?></span>
                                                            <span class="estimate-choice-caption">Use the widest buffer when there is stronger delivery uncertainty or coordination risk.</span>
                                                        </span>
                                                    </label>
                                                </div>

                                                <div class="choice-footer">
                                                    <small class="choice-range-note">Expert range: Min <?php echo (int) $minDays; ?>, Likely <?php echo (int) $likelyDays; ?>, Max <?php echo (int) $maxDays; ?> days.</small>
                                                    <span class="sales-choice-status" id="salesChoiceStatus_<?php echo (int) $task['id']; ?>">Showing all expert scenarios. Pick one to finalize the timeline.</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="approval-actions">
                                        <span class="approval-actions-label">Actions</span>
                                        <button class="btn-action btn-approve" id="approveBtn_<?php echo (int) $task['id']; ?>" onclick="approveEstimation(<?php echo (int) $task['id']; ?>)" disabled>
                                            <i class="fas fa-check me-1"></i> Approve
                                        </button>
                                        <button class="btn-action btn-reject" onclick="openRejectModal(<?php echo (int) $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">
                                            <i class="fas fa-times me-1"></i> Reject
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-check-circle"></i>
            <p class="mt-2 mb-0">No estimations awaiting sales approval.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Reject Modal -->
<div class="modal fade modal-modern" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Estimation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Rejecting estimation" data-loading-text="Please wait while the estimation is returned to the expert.">
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
const salesSelections = {};

function diffDays(startDate, endDate) {
    return Math.max(1, Math.round((endDate - startDate) / 86400000));
}

function getTaskNumericId(task) {
    return String(task?.id || '').replace(/^task-/, '');
}

function getSelectedChoiceForTask(task) {
    const taskId = getTaskNumericId(task);
    return salesSelections[taskId] || task.sales_estimate_choice || '';
}

function getSelectedDaysForTask(task, selectedChoice) {
    if (selectedChoice === 'min') return Math.max(0, parseInt(task.min_days || '0', 10));
    if (selectedChoice === 'likely') return Math.max(0, parseInt(task.likely_days || '0', 10));
    if (selectedChoice === 'max') return Math.max(0, parseInt(task.max_days || '0', 10));
    return Math.max(0, parseInt(task.sales_estimate_days || '0', 10));
}

function normalizeGanttRows(tasks) {
    const rows = (tasks || []).map(task => {
        const startDate = new Date(`${task.start}T00:00:00`);
        const endDate = new Date(`${task.end}T00:00:00`);
        if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
            return null;
        }

        const dateDuration = diffDays(startDate, endDate);
        const isPhase = Boolean(task.is_phase);
        const minDays = Math.max(0, parseInt(task.min_days || '0', 10));
        const likelyDays = Math.max(minDays, parseInt(task.likely_days || '0', 10));
        const maxDays = Math.max(likelyDays, parseInt(task.max_days || '0', 10));
        const selectedChoice = getSelectedChoiceForTask(task);
        const committedDays = getSelectedDaysForTask(task, selectedChoice);
        const totalDays = isPhase
            ? dateDuration
            : Math.max(selectedChoice ? committedDays : maxDays, 1);

        let minSegment = isPhase ? 0 : Math.min(minDays, totalDays);
        let likelySegment = isPhase ? 0 : Math.max(Math.min(likelyDays, totalDays) - Math.min(minDays, totalDays), 0);
        let maxSegment = isPhase ? 0 : Math.max(totalDays - Math.max(Math.min(likelyDays, totalDays), Math.min(minDays, totalDays)), 0);

        if (!isPhase && selectedChoice) {
            minSegment = selectedChoice === 'min' ? totalDays : 0;
            likelySegment = selectedChoice === 'likely' ? totalDays : 0;
            maxSegment = selectedChoice === 'max' ? totalDays : 0;
        }

        return {
            taskId: getTaskNumericId(task),
            name: task.name,
            startDate,
            isPhase,
            phaseDuration: isPhase ? totalDays : 0,
            minSegment,
            likelySegment,
            maxSegment,
            tooltip: {
                minDays,
                likelyDays,
                maxDays,
                committedDays,
                committedChoice: selectedChoice || task.sales_estimate_choice || ''
            }
        };
    }).filter(Boolean);

    if (!rows.length) {
        return rows;
    }

    const earliestStart = new Date(Math.min(...rows.map(task => task.startDate.getTime())));
    rows.forEach(task => {
        task.offset = Math.max(0, diffDays(earliestStart, task.startDate) - 1);
    });

    return rows;
}

function renderThreePointGantt(targetSelector, tasks, chartKey) {
    const target = typeof targetSelector === 'string' ? document.querySelector(targetSelector) : targetSelector;
    if (!target) return;
    if (typeof Chart === 'undefined') {
        target.innerHTML = '<div class="text-danger small">Chart.js failed to load. Please check your internet connection.</div>';
        return;
    }

    const rows = normalizeGanttRows(tasks);
    if (!rows.length) {
        target.innerHTML = '<p class="text-muted mb-0">No Gantt data available for this project.</p>';
        return;
    }

    target.innerHTML = '';
    const canvas = document.createElement('canvas');
    canvas.height = Math.max(240, rows.length * 44);
    target.appendChild(canvas);
    const ctx = canvas.getContext('2d');
    const labels = rows.map(task => task.name);

    if (window[chartKey]) {
        window[chartKey].destroy();
    }

    window[chartKey] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Offset',
                data: rows.map(task => task.offset),
                backgroundColor: 'rgba(0,0,0,0)',
                borderWidth: 0,
                stack: 'gantt'
            }, {
                label: 'Project Phase',
                data: rows.map(task => task.phaseDuration),
                backgroundColor: '#e2e8f0',
                borderRadius: 999,
                borderSkipped: false,
                barThickness: 12,
                stack: 'gantt'
            }, {
                label: 'Minimum',
                data: rows.map(task => task.minSegment),
                backgroundColor: '#bfdbfe',
                borderRadius: 999,
                borderSkipped: false,
                barThickness: 12,
                stack: 'gantt'
            }, {
                label: 'Most Likely',
                data: rows.map(task => task.likelySegment),
                backgroundColor: '#86efac',
                borderRadius: 999,
                borderSkipped: false,
                barThickness: 12,
                stack: 'gantt'
            }, {
                label: 'Maximum',
                data: rows.map(task => task.maxSegment),
                backgroundColor: '#fdba74',
                borderRadius: 999,
                borderSkipped: false,
                barThickness: 12,
                stack: 'gantt'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 8,
                    right: 16,
                    bottom: 4,
                    left: 4
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleColor: '#f8fafc',
                    bodyColor: '#e2e8f0',
                    padding: 10,
                    displayColors: false,
                    callbacks: {
                        label: (context) => {
                            if (context.dataset.label === 'Offset') {
                                return null;
                            }
                            const value = Number(context.raw || 0);
                            if (value <= 0) {
                                return null;
                            }
                            return `${context.dataset.label}: ${value} day${value === 1 ? '' : 's'}`;
                        },
                        footer: (items) => {
                            if (!items.length) {
                                return '';
                            }
                            const row = rows[items[0].dataIndex];
                            if (!row || row.isPhase) {
                                return '';
                            }
                            const details = [
                                `Min: ${row.tooltip.minDays} day${row.tooltip.minDays === 1 ? '' : 's'}`,
                                `Likely: ${row.tooltip.likelyDays} day${row.tooltip.likelyDays === 1 ? '' : 's'}`,
                                `Max: ${row.tooltip.maxDays} day${row.tooltip.maxDays === 1 ? '' : 's'}`
                            ];
                            if (row.tooltip.committedChoice && row.tooltip.committedDays > 0) {
                                details.push(`Committed: ${row.tooltip.committedChoice} (${row.tooltip.committedDays} days)`);
                            }
                            return details;
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    stacked: true,
                    title: { display: true, text: 'Days' },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.18)',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#64748b',
                        padding: 8
                    },
                    border: { display: false }
                },
                y: {
                    stacked: true,
                    grid: { display: false, drawBorder: false },
                    ticks: {
                        color: '#334155',
                        padding: 10
                    },
                    border: { display: false }
                }
            }
        }
    });
}

function updateTaskChoiceStatus(taskId) {
    const choiceInput = document.querySelector(`input[name="estimate_choice_${taskId}"]:checked`);
    const status = document.getElementById(`salesChoiceStatus_${taskId}`);
    const approveButton = document.getElementById(`approveBtn_${taskId}`);
    const preview = document.querySelector(`.gantt-preview[data-task-id="${taskId}"]`);
    if (!status || !preview) {
        return;
    }

    const minBar = preview.querySelector('.min-bar');
    const likelyBar = preview.querySelector('.likely-bar');
    const maxBar = preview.querySelector('.max-bar');

    if (!choiceInput) {
        status.textContent = 'Showing all expert scenarios. Pick one to finalize the timeline.';
        if (approveButton) approveButton.disabled = true;
        if (minBar) minBar.style.width = minBar.dataset.originalWidth || minBar.style.width;
        if (likelyBar) likelyBar.style.width = likelyBar.dataset.originalWidth || likelyBar.style.width;
        if (maxBar) maxBar.style.width = maxBar.dataset.originalWidth || maxBar.style.width;
        return;
    }

    const choice = choiceInput.value;
    const days = parseInt(choiceInput.dataset.days || '0', 10) || 0;
    status.textContent = `Selected timeline: ${choice.charAt(0).toUpperCase() + choice.slice(1)} - ${days} day${days === 1 ? '' : 's'}`;
    if (approveButton) approveButton.disabled = !(days > 0);

    if (minBar) minBar.style.width = choice === 'min' ? '100%' : '0%';
    if (likelyBar) likelyBar.style.width = choice === 'likely' ? '100%' : '0%';
    if (maxBar) maxBar.style.width = choice === 'max' ? '100%' : '0%';
}

function syncSalesSelection(taskId) {
    const choiceInput = document.querySelector(`input[name="estimate_choice_${taskId}"]:checked`);
    if (choiceInput) {
        salesSelections[String(taskId)] = choiceInput.value;
    } else {
        delete salesSelections[String(taskId)];
    }
    updateTaskChoiceStatus(taskId);
    Object.keys(ganttProjects).forEach((projectId) => {
        const data = ganttProjects[projectId];
        if (!data || !data.tasks || !data.tasks.some(task => getTaskNumericId(task) === String(taskId))) {
            return;
        }
        refreshProjectGantt(projectId);
    });
}

async function approveEstimation(taskId) {
    const choiceInput = document.querySelector(`input[name="estimate_choice_${taskId}"]:checked`);
    const estimateChoice = choiceInput?.value || '';
    const daysValue = parseInt(choiceInput?.dataset?.days || '0', 10);
    if (!estimateChoice || !daysValue || daysValue <= 0) {
        window.appToast?.('Select an estimate', 'Choose Minimum, Most Likely, or Maximum before approving.', 'warning');
        return;
    }
    const confirmed = await appConfirm({
        title: 'Approve Estimation',
        subject: 'Send this estimation to SUL?',
        message: 'The selected timeline will be saved and the task will move to SUL review.',
        icon: 'fas fa-circle-check fa-lg',
        confirmIcon: 'fas fa-check',
        confirmText: 'Approve'
    });
    if (!confirmed) return;
    if (window.appShowLoading) {
        window.appShowLoading('Approving estimation', 'Please wait while the task is sent to SUL.');
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `<input type="hidden" name="approve_estimation" value="1"><input type="hidden" name="task_id" value="${taskId}"><input type="hidden" name="estimate_choice" value="${estimateChoice}"><input type="hidden" name="estimate_days" value="${daysValue}">`;
    document.body.appendChild(form);
    form.submit();
}

function openRejectModal(taskId, taskTitle) {
    document.getElementById('rejectTaskId').value = taskId;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function refreshProjectGantt(projectId) {
    const data = ganttProjects[projectId];
    if (!data || !data.tasks || data.tasks.length === 0) return;
    const target = document.getElementById(`salesGantt_${projectId}`);
    if (target) {
        renderThreePointGantt(target, data.tasks, `salesGanttInline_${projectId}`);
    }
}

function setProjectGroupOpen(group, shouldOpen) {
    if (!group) {
        return;
    }

    const toggle = group.querySelector('[data-project-toggle]');
    const body = group.querySelector('[data-project-body]');
    if (!toggle || !body) {
        return;
    }

    group.classList.toggle('is-open', shouldOpen);
    toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

    if (shouldOpen) {
        body.hidden = false;
        const projectId = group.dataset.projectId;
        if (projectId) {
            refreshProjectGantt(projectId);
        }
    } else {
        body.hidden = true;
    }
}

const projectApprovalGroups = document.querySelectorAll('[data-project-group]');

projectApprovalGroups.forEach((group) => {
    const toggle = group.querySelector('[data-project-toggle]');
    if (!toggle) {
        return;
    }

    toggle.addEventListener('click', () => {
        const shouldOpen = !group.classList.contains('is-open');
        projectApprovalGroups.forEach(otherGroup => setProjectGroupOpen(otherGroup, false));
        setProjectGroupOpen(group, shouldOpen);
    });

    if (group.classList.contains('is-open')) {
        setProjectGroupOpen(group, true);
    } else {
        setProjectGroupOpen(group, false);
    }
});

document.querySelectorAll('.gantt-preview .gantt-bar').forEach((bar) => {
    bar.dataset.originalWidth = bar.style.width;
});

document.querySelectorAll('input[type="radio"][name^="estimate_choice_"]').forEach((input) => {
    input.addEventListener('change', () => {
        const match = input.name.match(/^estimate_choice_(\d+)$/);
        if (match) {
            syncSalesSelection(match[1]);
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
