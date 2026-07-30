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
            WHERE e.id = {$estimationId} AND e.status = 'submitted'
            LIMIT 1
        ")->fetch_assoc() : null;

        if (!$estimation) {
            $_SESSION['error'] = "Please select an Expert-submitted project estimation.";
            header('Location: estimation-approval.php');
            exit();
        }

        $stmt = $conn->prepare("
            UPDATE expert_project_estimations
            SET status = 'sales_approved', sales_reviewed_by = ?, sales_reviewed_at = NOW(), sales_comment = ?
            WHERE id = ?
        ");
        $stmt->bind_param('isi', $user['id'], $comment, $estimationId);
        if ($stmt->execute()) {
            workflowNotifyRoles(
                $conn,
                ['sul'],
                'Project Estimation Ready for Final Review',
                'Sales approved the project cost estimation. SUL can now complete the estimation flow.',
                BASE_URL . 'modules/sul/task-allocation.php?project_id=' . (int) $estimation['project_id'],
                (int) $estimation['project_id'],
                'approval',
                'projects'
            );
            logActivity((int) $user['id'], 'sales_approve_project_estimation', 'expert_project_estimations', $estimationId, null, $_POST);
            $_SESSION['success'] = "Project estimation approved and sent to SUL for final review.";
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
            WHERE e.id = {$estimationId} AND e.status = 'submitted'
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
                projectWorkflowNotifyDepartmentSuls(
                    $conn,
                    'Expert Department',
                    "Sales Approved Estimation: " . ($taskDetails['title'] ?? 'Task'),
                    "Sales approved the estimation. Task is ready for Expert SUL review.",
                    BASE_URL . 'modules/sul/task-allocation.php?project_id=' . (int) ($taskDetails['project_id'] ?? 0) . '#expert-review-panel',
                    (int) ($taskDetails['project_id'] ?? 0)
                );
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
    SELECT e.*, p.name AS project_name, p.timeline_start, p.timeline_end, p.sales_budget, p.priority, c.name AS client_name, u.full_name AS expert_name
    FROM expert_project_estimations e
    JOIN projects p ON p.id = e.project_id
    JOIN clients c ON c.id = p.client_id
    JOIN users u ON u.id = e.expert_id
    WHERE e.status = 'submitted'
    ORDER BY e.submitted_at ASC, e.id ASC
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
    margin-bottom: 0.8rem;
    border-radius: 18px;
    background: #ffffff;
    border: 1px solid #dfe9f1;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}

.project-approval-group:hover {
    border-color: #c9dce9;
    box-shadow: 0 10px 24px rgba(15, 42, 64, 0.07);
    transform: translateY(-1px);
}

.project-approval-group.is-open {
    border-color: rgba(31, 99, 146, 0.34);
    box-shadow: 0 14px 32px rgba(15, 42, 64, 0.08);
    transform: none;
}

.project-approval-toggle {
    flex: 1 1 auto;
    width: auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.15rem;
    border: none;
    background: transparent;
    text-align: left;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.project-approval-header {
    align-items: center;
    display: flex;
    min-width: 0;
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
    display: flex;
    align-items: center;
    gap: 0.85rem;
    flex: 1 1 auto;
}

.project-approval-toggle-side {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
    gap: 0.55rem;
    justify-content: flex-end;
    padding-right: 1rem;
}

.project-approval-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.65rem;
    height: 2.65rem;
    flex: 0 0 2.65rem;
    border-radius: 12px;
    background: #edf6fb;
    color: var(--theme-primary);
    font-size: 1rem;
}

.project-approval-title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 600;
    color: #1f2937;
    overflow-wrap: anywhere;
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
    padding: 0.46rem 0.72rem;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #e3ebf2;
    color: #475569;
    font-size: 0.74rem;
    font-weight: 600;
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
    padding: 1.15rem;
    border-top: 1px solid #e6eef5;
    background: #fbfdff;
}

.project-approval-group.is-open .project-approval-body {
    display: grid;
    gap: 1.1rem;
}

.project-approval-body.is-feature-only {
    padding: 0;
    gap: 0;
    background: #ffffff;
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
    border-radius: 14px;
    background: linear-gradient(180deg, #fbfdff 0%, #f8fafc 100%);
    border: 1px solid #e2e8f0;
}

.project-gantt-panel[hidden] {
    display: none;
}

.project-context-actions {
    display: none;
    align-items: center;
    justify-content: flex-end;
    gap: .45rem;
}

.project-approval-group.is-open .project-context-actions {
    display: flex;
}

.project-context-action {
    align-items: center;
    background: #fff;
    border: 1px solid #d9e5ed;
    border-radius: 10px;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 38px;
    font-size: .86rem;
    height: 38px;
    justify-content: center;
    text-decoration: none;
    transition: background-color .18s ease, border-color .18s ease, color .18s ease, transform .18s ease;
    width: 38px;
}

.project-context-action:hover,
.project-context-action.is-active {
    background: #eaf4fb;
    border-color: #b9d7e9;
    color: #15577f;
    transform: translateY(-1px);
}

.project-context-action:focus-visible {
    box-shadow: 0 0 0 3px rgba(31, 99, 146, .16);
    outline: 0;
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

.feature-approval-workspace {
    overflow: hidden;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
}

.feature-approval-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.15rem;
    border-bottom: 1px solid #dfe8ef;
    background: transparent;
}

.feature-approval-toolbar h6 {
    margin: 0.12rem 0 0;
    color: #1f2937;
    font-size: 1rem;
    font-weight: 650;
}

.feature-approval-kicker,
.feature-detail-position {
    color: #64748b;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.feature-selection-summary {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.feature-selection-summary span {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-height: 30px;
    padding: 0.35rem 0.58rem;
    border: 1px solid #dce6ee;
    border-radius: 999px;
    background: #ffffff;
    color: #64748b;
    font-size: 0.7rem;
    white-space: nowrap;
}

.feature-selection-summary strong {
    color: #163c59;
    font-size: 0.76rem;
}

.feature-approval-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
}

.feature-approval-workspace.has-active-review .feature-approval-layout {
    grid-template-columns: minmax(0, 1.35fr) minmax(360px, 0.85fr);
}

.feature-approval-queue {
    min-width: 0;
    padding: 0;
}

.feature-approval-table-wrap {
    overflow-x: auto;
    border: 0;
    border-radius: 0;
}

.feature-approval-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    table-layout: fixed;
}

.feature-approval-table th {
    padding: 0.72rem 0.65rem;
    border-bottom: 1px solid #dfe8ef;
    background: #f8fafc;
    color: #64748b;
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-align: left;
    text-transform: uppercase;
}

.feature-approval-table th:first-child { width: 43%; }
.feature-approval-table th:nth-child(2) { width: 27%; }
.feature-approval-table th:nth-child(3) { width: 30%; }

.feature-approval-row {
    cursor: pointer;
    outline: none;
}

.feature-approval-row td {
    height: 54px;
    padding: 0.62rem 0.65rem;
    border-bottom: 1px solid #e6edf2;
    background: #ffffff;
    color: #536578;
    font-size: 0.76rem;
    vertical-align: middle;
    transition: background-color 0.18s ease, color 0.18s ease;
}

.feature-approval-row:last-child td {
    border-bottom: 0;
}

.feature-approval-row:hover td {
    background: #f6fafc;
}

.feature-approval-row.is-selected td {
    background: #eaf4fb;
    color: #173a55;
}

.feature-approval-row:focus-visible td {
    box-shadow: inset 0 0 0 2px rgba(31, 99, 146, 0.32);
}

.feature-row-identity {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    min-width: 0;
}

.feature-row-index {
    display: inline-grid;
    flex: 0 0 26px;
    width: 26px;
    height: 26px;
    place-items: center;
    border-radius: 8px;
    background: #edf4f8;
    color: #547087;
    font-size: 0.68rem;
    font-weight: 700;
}

.feature-approval-row.is-selected .feature-row-index {
    background: var(--theme-primary);
    color: #ffffff;
}

.feature-row-name {
    min-width: 0;
    overflow: hidden;
    color: #1f2937;
    font-size: 0.78rem;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.feature-row-choice {
    display: inline-flex;
    max-width: 100%;
    align-items: center;
    overflow: hidden;
    padding: 0.3rem 0.5rem;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 650;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.feature-row-choice.is-pending {
    background: #fff4d6;
    color: #9a5b00;
}

.feature-row-choice.is-min {
    background: #e7f1ff;
    color: #2563a6;
}

.feature-row-choice.is-likely {
    background: #dcfce7;
    color: #137a43;
}

.feature-row-choice.is-max {
    background: #fff0df;
    color: #b45309;
}

.feature-commitment-select {
    width: min(100%, 210px);
    min-height: 36px;
    padding: 0.38rem 1.8rem 0.38rem 0.55rem;
    border: 1px solid #d7e2ea;
    border-radius: 9px;
    background-color: #ffffff;
    color: #30465a;
    font-size: 0.72rem;
    outline: none;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.feature-commitment-select:focus {
    border-color: #68a4cc;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.feature-row-actions {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.feature-row-action {
    display: inline-flex;
    min-height: 34px;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
    padding: 0.38rem 0.55rem;
    border: 1px solid transparent;
    border-radius: 9px;
    font-size: 0.68rem;
    font-weight: 650;
    line-height: 1;
    cursor: pointer;
    transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.feature-row-action:hover:not(:disabled) {
    transform: translateY(-1px);
}

.feature-row-action.is-approve {
    background: #e5f7ed;
    border-color: #bce8ce;
    color: #137a43;
}

.feature-row-action.is-approve:hover:not(:disabled) {
    background: #d3f1df;
}

.feature-row-action.is-approve:disabled {
    background: #eef2f5;
    border-color: #e2e8ee;
    color: #9aa7b3;
    cursor: not-allowed;
}

.feature-row-action.is-reject {
    background: #fff7ed;
    border-color: #fed7aa;
    color: #b45309;
}

.feature-row-action.is-reject:hover {
    background: #ffedd5;
}

.feature-queue-hint {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
    padding: 0.7rem 1rem;
    border-top: 1px solid #e2e8f0;
    color: #7a8a99;
    font-size: 0.72rem;
}

.feature-review-pane {
    display: none;
    min-width: 0;
    border-left: 1px solid #dfe8ef;
    background: #fbfdff;
}

.feature-approval-workspace.has-active-review .feature-review-pane {
    display: block;
}

.feature-review-pane[hidden] {
    display: none;
}

.feature-review-pane-head {
    align-items: center;
    border-bottom: 1px solid #dfe8ef;
    display: flex;
    justify-content: space-between;
    padding: .72rem 1.15rem;
}

.feature-review-pane-head > span {
    color: #64748b;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.feature-review-close {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 8px;
    color: #64748b;
    display: inline-flex;
    font-size: .74rem;
    gap: .35rem;
    min-height: 32px;
    padding: .35rem .5rem;
    transition: background-color .18s ease, color .18s ease;
}

.feature-review-close:hover {
    background: #eaf4fb;
    color: var(--theme-primary);
}

.feature-review-close:focus-visible {
    box-shadow: 0 0 0 3px rgba(31, 99, 146, .16);
    outline: 0;
}

.feature-review-detail {
    padding: 1.1rem 1.25rem 1.25rem;
}

.feature-review-detail[hidden] {
    display: none;
}

.feature-review-detail .approval-card-main,
.feature-review-detail .approval-card-content {
    min-height: 0;
}

.feature-review-detail .approval-feature-header {
    display: grid;
    gap: 0.7rem;
    margin: 0;
}

.feature-detail-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.feature-detail-heading {
    min-width: 0;
}

.feature-detail-status {
    display: inline-flex;
    align-items: center;
    align-self: center;
    gap: 0.35rem;
    flex: 0 0 auto;
    width: auto;
    height: auto;
    min-height: 0;
    max-width: 100%;
    padding: 0.32rem 0.55rem;
    border-radius: 999px;
    background: #fff4d6;
    border: 1px solid #f7df9b;
    color: #9a5b00;
    font-size: 0.68rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
}

.feature-review-detail .approval-feature-title {
    margin-top: 0.15rem;
    font-size: 1.25rem;
    font-weight: 650;
}

.feature-review-detail .approval-meta-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.85rem;
    margin-top: 0.45rem;
}

.feature-review-detail .approval-meta-list span {
    gap: 0.35rem;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    color: #64748b;
    font-size: 0.72rem;
}

.feature-detail-expectation {
    display: grid;
    grid-template-columns: 78px minmax(0, 1fr);
    align-items: baseline;
    gap: 0.75rem;
    padding: 0.8rem 0;
    border-top: 1px solid #e2e8f0;
}

.feature-detail-expectation > span {
    color: #64748b;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.055em;
    text-transform: uppercase;
}

.feature-detail-expectation p {
    margin: 0;
    color: #334155;
    font-size: 0.8rem;
    line-height: 1.5;
}

.feature-estimate-summary {
    display: grid;
    gap: 0.85rem;
    padding-top: 0.9rem;
    border-top: 1px solid #e2e8f0;
}

.feature-estimate-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.feature-estimate-head h6 {
    margin: 0;
    color: #1f2937;
    font-size: 0.94rem;
    font-weight: 650;
}

.feature-pert-value {
    display: inline-flex;
    align-items: baseline;
    gap: 0.4rem;
    color: #64748b;
    white-space: nowrap;
}

.feature-pert-value span {
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.055em;
    text-transform: uppercase;
}

.feature-pert-value strong {
    color: #163c59;
    font-size: 0.82rem;
    font-weight: 650;
}

.feature-estimate-track {
    display: flex;
    height: 6px;
    overflow: hidden;
    border-radius: 999px;
    background: #e8eef3;
}

.feature-estimate-segment {
    height: 100%;
    min-width: 0;
}

.feature-estimate-segment.is-min { background: #60a5fa; }
.feature-estimate-segment.is-likely { background: #34d399; }
.feature-estimate-segment.is-max { background: #f97316; }

.feature-estimate-values {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    border-top: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
}

.feature-estimate-value {
    display: grid;
    gap: 0.18rem;
    padding: 0.65rem 0.7rem;
}

.feature-estimate-value + .feature-estimate-value {
    border-left: 1px solid #e2e8f0;
}

.feature-estimate-value span {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: #64748b;
    font-size: 0.64rem;
    font-weight: 600;
}

.feature-estimate-value span::before {
    width: 0.48rem;
    height: 0.48rem;
    flex: 0 0 0.48rem;
    border-radius: 50%;
    background: #60a5fa;
    content: "";
}

.feature-estimate-value.is-likely span::before { background: #34d399; }
.feature-estimate-value.is-max span::before { background: #f97316; }

.feature-estimate-value strong {
    color: #1f2937;
    font-size: 0.8rem;
    font-weight: 650;
}

.feature-review-detail .approval-actions {
    padding-top: 0.9rem;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-icon {
    background: rgba(31, 99, 146, 0.2);
    color: #7dd3fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .approval-feature-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-panel-head h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .estimate-choice-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-title {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-head p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-approval-pill,
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-approval-workspace,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-selection-summary span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-approval-row td {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-approval-toolbar,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-approval-table th {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-review-pane {
    background: #081725;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-review-pane-head {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-review-close {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-action {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, .28);
    color: #7dd3fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-action:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-action.is-active {
    background: #102b40;
    border-color: rgba(125, 211, 252, .35);
    color: #bae6fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-review-close:hover {
    background: #102b40;
    color: #bae6fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-review-detail .approval-meta-list span {
    background: transparent;
    border-color: transparent;
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-detail-status {
    background: rgba(217, 119, 6, 0.15);
    border-color: rgba(251, 191, 36, 0.24);
    color: #fcd34d;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-detail-expectation,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-estimate-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-estimate-values,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-estimate-value + .feature-estimate-value {
    border-color: rgba(31, 99, 146, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-detail-expectation > span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-estimate-value span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-pert-value {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-detail-expectation p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-estimate-head h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-estimate-value strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-pert-value strong {
    color: #bae6fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-estimate-track {
    background: rgba(157, 182, 207, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-approval-table-wrap {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-approval-row:hover td {
    background: #0b2032;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-approval-row.is-selected td {
    background: #102b40;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-row-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-approval-toolbar h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-review-empty strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-selection-summary strong {
    color: #bae6fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-commitment-select {
    background-color: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.3);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-row-action.is-approve {
    background: rgba(22, 163, 74, 0.16);
    border-color: rgba(74, 222, 128, 0.24);
    color: #86efac;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-row-action.is-reject {
    background: rgba(217, 119, 6, 0.15);
    border-color: rgba(251, 191, 36, 0.22);
    color: #fcd34d;
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

@media (max-width: 1199.98px) {
    .feature-approval-workspace.has-active-review .feature-approval-layout {
        grid-template-columns: 1fr;
    }

    .feature-review-pane {
        border-top: 1px solid #dfe8ef;
        border-left: 0;
    }

}

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
        align-items: flex-start;
    }

    .project-approval-header {
        align-items: stretch;
        flex-wrap: wrap;
    }

    .project-approval-body {
        padding: 1rem;
    }

    .project-approval-toggle-side {
        width: 100%;
        padding: 0 1rem 1rem 4.5rem;
        justify-content: flex-end;
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

    .feature-approval-toolbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .feature-selection-summary {
        justify-content: flex-start;
    }

    .feature-approval-queue,
    .feature-review-detail {
        padding: 0.8rem;
    }

    .feature-approval-table {
        min-width: 620px;
    }

    .feature-detail-topline {
        align-items: flex-start;
    }

    .feature-detail-expectation {
        grid-template-columns: 1fr;
        gap: 0.3rem;
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
                $projectBaseCost = $estimate
                    ? (float) $estimate['total_developer_cost'] + (float) $estimate['total_technology_stack_cost'] + (float) $estimate['additional_costs']
                    : 0.0;
                $projectPriorityMarkup = projectEstimationPriorityMarkup((string) ($estimate['priority'] ?? 'medium'));
                $projectPriorityMarkupAmount = $projectBaseCost * (float) $projectPriorityMarkup['rate'];
            ?>
            <section class="project-approval-group" data-project-group data-project-id="<?php echo (int) $pid; ?>">
                <div class="project-approval-header">
                    <button
                        type="button"
                        class="project-approval-toggle"
                        data-project-toggle
                        aria-expanded="false"
                        aria-controls="projectApprovalBody_<?php echo (int) $pid; ?>"
                    >
                        <span class="project-approval-toggle-main project-approval-head">
                            <span class="project-approval-icon" aria-hidden="true"><i class="fas fa-diagram-project"></i></span>
                            <span class="project-approval-title"><?php echo htmlspecialchars($projectTitle); ?></span>
                        </span>
                    </button>
                    <div class="project-approval-toggle-side">
                        <div class="project-approval-summary">
                            <?php if ($featureCount > 0): ?>
                                <span class="project-approval-pill"><i class="fas fa-layer-group"></i> <?php echo $featureCount; ?> pending feature<?php echo $featureCount === 1 ? '' : 's'; ?></span>
                            <?php elseif ($estimate): ?>
                                <span class="project-approval-pill"><i class="fas fa-file-invoice-dollar"></i> Cost approval</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($featureCount > 0): ?>
                            <div class="project-context-actions" aria-label="Project planning views">
                                <button
                                    type="button"
                                    class="project-context-action"
                                    data-project-gantt-toggle
                                    aria-label="Show project Gantt"
                                    aria-controls="projectGanttPanel_<?php echo (int) $pid; ?>"
                                    aria-expanded="false"
                                    title="Project Gantt"
                                >
                                    <i class="fas fa-chart-gantt" aria-hidden="true"></i>
                                </button>
                                <a
                                    href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php?project_id=<?php echo (int) $pid; ?>"
                                    class="project-context-action"
                                    aria-label="Open project roadmap"
                                    title="Open Roadmap"
                                >
                                    <i class="fas fa-route" aria-hidden="true"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                        <button
                            type="button"
                            class="project-approval-chevron"
                            data-project-toggle
                            aria-expanded="false"
                            aria-controls="projectApprovalBody_<?php echo (int) $pid; ?>"
                            aria-label="Toggle project approval details"
                        >
                            <i class="fas fa-chevron-down" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div
                    class="project-approval-body<?php echo !$estimate && $featureCount > 0 ? ' is-feature-only' : ''; ?>"
                    id="projectApprovalBody_<?php echo (int) $pid; ?>"
                    data-project-body
                    hidden
                >
                    <?php if ($estimate): ?>
                        <section class="sales-project-cost-review" aria-labelledby="projectCostReviewTitle_<?php echo (int) $pid; ?>">
                            <div class="sales-project-cost-head">
                                <div>
                                    <h6 id="projectCostReviewTitle_<?php echo (int) $pid; ?>"><i class="fas fa-file-invoice-dollar me-1" aria-hidden="true"></i> Project Cost Review</h6>
                                    <p>Approve the overall project budget for SUL completion or return it for revision.</p>
                                </div>
                                <span class="sales-project-cost-total"><span>Overall estimate</span><strong><?php echo projectEstimationPeso((float) $estimate['overall_estimated_project_cost']); ?></strong></span>
                            </div>

                            <div class="sales-cost-breakdown" aria-label="Project cost breakdown">
                                <div class="sales-cost-metric"><span>Developer cost</span><strong><?php echo projectEstimationPeso((float) $estimate['total_developer_cost']); ?></strong></div>
                                <div class="sales-cost-metric"><span>Technology stack</span><strong><?php echo projectEstimationPeso((float) $estimate['total_technology_stack_cost']); ?></strong></div>
                                <div class="sales-cost-metric"><span>Base project cost</span><strong><?php echo projectEstimationPeso($projectBaseCost); ?></strong></div>
                                <div class="sales-cost-metric"><span>Priority markup (<?php echo htmlspecialchars((string) $projectPriorityMarkup['percent']); ?>%)</span><strong><?php echo projectEstimationPeso($projectPriorityMarkupAmount); ?></strong><small class="d-block mt-1"><?php echo htmlspecialchars((string) $projectPriorityMarkup['reason']); ?></small></div>
                                <div class="sales-cost-metric is-primary"><span>Overall estimate</span><strong><?php echo projectEstimationPeso((float) $estimate['overall_estimated_project_cost']); ?></strong></div>
                                <div class="sales-cost-metric"><span>Client budget</span><strong><?php echo projectEstimationPeso((float) $estimate['sales_budget']); ?></strong></div>
                                <div class="sales-cost-metric <?php echo (float) $estimate['remaining_budget'] < 0 ? 'is-negative' : ''; ?>"><span>Remaining budget</span><strong><?php echo projectEstimationPeso((float) $estimate['remaining_budget']); ?></strong></div>
                                <div class="sales-cost-metric"><span>Additional costs</span><strong><?php echo projectEstimationPeso((float) $estimate['additional_costs']); ?></strong></div>
                            </div>

                            <div class="sales-cost-decisions">
                                <form method="POST" class="sales-decision-card is-approve" data-generic-draft-disabled="1" data-app-loading data-loading-title="Approving project estimation" data-loading-text="Sending the approved estimate to SUL.">
                                    <input type="hidden" name="estimation_id" value="<?php echo (int) $estimate['id']; ?>">
                                    <div class="sales-decision-head">
                                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                                        <span><strong>Approve project estimate</strong><span>Confirm the cost and send it to SUL for final completion.</span></span>
                                    </div>
                                    <label for="salesApprovalNote<?php echo (int) $estimate['id']; ?>">Sales note <span>(optional)</span></label>
                                    <textarea id="salesApprovalNote<?php echo (int) $estimate['id']; ?>" name="approval_comment" class="form-control" rows="2" placeholder="Add a short note for SUL"></textarea>
                                    <button class="btn-action btn-approve" type="submit" name="approve_project_estimation"><i class="fas fa-check me-1"></i> Approve &amp; Send to SUL</button>
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
                    <div
                        class="project-gantt-panel"
                        id="projectGanttPanel_<?php echo (int) $pid; ?>"
                        data-project-gantt-panel
                        hidden
                    >
                        <div id="salesGantt_<?php echo (int) $pid; ?>"></div>
                    </div>
                    <section class="feature-approval-workspace" data-feature-approval-workspace>
                        <div class="feature-approval-toolbar">
                            <div>
                                <span class="feature-approval-kicker">Feature decisions</span>
                                <h6>Approval queue</h6>
                            </div>
                            <div class="feature-selection-summary" data-selection-summary>
                                <span><strong data-pending-count><?php echo (int) $featureCount; ?></strong> pending</span>
                                <span><strong data-choice-count="likely">0</strong> balanced</span>
                                <span><strong data-choice-count="min">0</strong> fastest</span>
                                <span><strong data-choice-count="max">0</strong> high buffer</span>
                            </div>
                        </div>

                        <div class="feature-approval-layout">
                            <div class="feature-approval-queue">
                                <div class="feature-approval-table-wrap">
                                    <table class="feature-approval-table">
                                        <thead>
                                             <tr>
                                                 <th>Feature</th>
                                                 <th>Sales Commitment</th>
                                                 <th>Actions</th>
                                             </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($projectTasks as $taskIndex => $task): ?>
                                            <?php
                                                 $rowMinDays = (float) ($task['min_days'] ?? 0);
                                                 $rowLikelyDays = (float) ($task['likely_days'] ?? 0);
                                                 $rowMaxDays = (float) ($task['max_days'] ?? 0);
                                             ?>
                                            <tr class="feature-approval-row"
                                                tabindex="0"
                                                role="button"
                                                aria-selected="false"
                                                data-feature-row="<?php echo (int) $task['id']; ?>">
                                                <td>
                                                    <div class="feature-row-identity">
                                                        <span class="feature-row-index"><?php echo (int) $taskIndex + 1; ?></span>
                                                        <span class="feature-row-name"><?php echo htmlspecialchars($task['title']); ?></span>
                                                    </div>
                                                </td>
                                                 <td>
                                                    <select class="feature-commitment-select"
                                                        id="estimateChoice_<?php echo (int) $task['id']; ?>"
                                                        data-estimate-select="<?php echo (int) $task['id']; ?>"
                                                        aria-label="Commitment selection for <?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <option value="">Select commitment</option>
                                                        <option value="min" data-days="<?php echo (int) $rowMinDays; ?>">Fastest · <?php echo (int) $rowMinDays; ?>d</option>
                                                        <option value="likely" data-days="<?php echo (int) $rowLikelyDays; ?>">Balanced · <?php echo (int) $rowLikelyDays; ?>d</option>
                                                        <option value="max" data-days="<?php echo (int) $rowMaxDays; ?>">High buffer · <?php echo (int) $rowMaxDays; ?>d</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <div class="feature-row-actions">
                                                        <button class="feature-row-action is-approve"
                                                            type="button"
                                                            id="approveBtn_<?php echo (int) $task['id']; ?>"
                                                            onclick="approveEstimation(<?php echo (int) $task['id']; ?>)"
                                                            aria-label="Approve <?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            title="Approve"
                                                            disabled>
                                                            <i class="fas fa-check"></i><span>Approve</span>
                                                        </button>
                                                        <button class="feature-row-action is-reject"
                                                            type="button"
                                                            data-reject-task-id="<?php echo (int) $task['id']; ?>"
                                                            data-reject-task-title="<?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            aria-label="Reject <?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            title="Reject">
                                                            <i class="fas fa-times"></i><span>Reject</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="feature-queue-hint"><i class="fas fa-arrow-pointer"></i> Select a feature to review its estimate.</p>
                            </div>

                            <div class="feature-review-pane" data-feature-review-pane hidden>
                                <div class="feature-review-pane-head">
                                    <span>Feature review</span>
                                    <button type="button" class="feature-review-close" data-close-feature-review>
                                        <i class="fas fa-xmark" aria-hidden="true"></i>
                                        <span>Close</span>
                                    </button>
                                </div>
                        <?php foreach ($projectTasks as $taskIndex => $task): ?>
                            <?php 
                                $minDays = (float) ($task['min_days'] ?? 0);
                                $likelyDays = (float) ($task['likely_days'] ?? 0);
                                $maxDays = (float) ($task['max_days'] ?? 0);
                                $pert = round(($minDays + 4*$likelyDays + $maxDays)/6, 1);
                                $deadlineLabel = !empty($task['deadline']) ? date('M d, Y', strtotime($task['deadline'])) : 'No deadline yet';
                                $expectationDescription = trim((string) ($task['expectation_description'] ?? ''));
                            ?>
                            <section class="feature-review-detail"
                                data-feature-detail="<?php echo (int) $task['id']; ?>"
                                hidden>
                                <div class="approval-card-main">
                                    <div class="approval-card-content">
                                        <div class="approval-feature-header">
                                            <div class="feature-detail-topline">
                                                <span class="feature-detail-position">Feature <?php echo (int) $taskIndex + 1; ?> of <?php echo (int) $featureCount; ?></span>
                                                <span class="feature-detail-status"><i class="far fa-clock"></i> Pending</span>
                                            </div>
                                            <div class="feature-detail-heading">
                                                <h6 class="approval-feature-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                                                <div class="task-meta approval-meta-list">
                                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($task['expert_name']); ?></span>
                                                    <span><i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($deadlineLabel); ?></span>
                                                </div>
                                            </div>
                                            <?php if ($expectationDescription !== ''): ?>
                                                <div class="feature-detail-expectation">
                                                    <span>Expectation</span>
                                                    <p><?php echo htmlspecialchars($expectationDescription); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="feature-estimate-summary" data-task-id="<?php echo (int) $task['id']; ?>">
                                            <div class="feature-estimate-head">
                                                <h6>Three-point estimate</h6>
                                                <span class="feature-pert-value"><span>PERT</span><strong><?php echo $pert; ?> days</strong></span>
                                            </div>
                                            <div class="feature-estimate-track" aria-hidden="true">
                                                <span class="feature-estimate-segment is-min" style="width: <?php echo $maxDays > 0 ? ($minDays / $maxDays * 100) : 0; ?>%;"></span>
                                                <span class="feature-estimate-segment is-likely" style="width: <?php echo $maxDays > 0 ? (max($likelyDays - $minDays, 0) / $maxDays * 100) : 0; ?>%;"></span>
                                                <span class="feature-estimate-segment is-max" style="width: <?php echo $maxDays > 0 ? (max($maxDays - max($likelyDays, $minDays), 0) / $maxDays * 100) : 0; ?>%;"></span>
                                            </div>
                                            <div class="feature-estimate-values">
                                                <div class="feature-estimate-value is-min">
                                                    <span>Minimum</span>
                                                    <strong><?php echo (int) $minDays; ?> day<?php echo (int) $minDays === 1 ? '' : 's'; ?></strong>
                                                </div>
                                                <div class="feature-estimate-value is-likely">
                                                    <span>Most likely</span>
                                                    <strong><?php echo (int) $likelyDays; ?> day<?php echo (int) $likelyDays === 1 ? '' : 's'; ?></strong>
                                                </div>
                                                <div class="feature-estimate-value is-max">
                                                    <span>Maximum</span>
                                                    <strong><?php echo (int) $maxDays; ?> day<?php echo (int) $maxDays === 1 ? '' : 's'; ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
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
const salesSelectionStorageKey = 'erniPms:salesEstimationApproval:selections:<?php echo (int) $user['id']; ?>';
const salesOpenProjectStorageKey = 'erniPms:salesEstimationApproval:openProject:<?php echo (int) $user['id']; ?>';

function readStoredSalesSelections() {
    try {
        const stored = JSON.parse(sessionStorage.getItem(salesSelectionStorageKey) || '{}');
        return stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {};
    } catch (error) {
        return {};
    }
}

function persistSalesSelections() {
    const selections = {};
    document.querySelectorAll('[data-estimate-select]').forEach((control) => {
        const taskId = String(control.dataset.estimateSelect || '');
        if (taskId && ['min', 'likely', 'max'].includes(control.value)) {
            selections[taskId] = control.value;
        }
    });

    try {
        if (Object.keys(selections).length > 0) {
            sessionStorage.setItem(salesSelectionStorageKey, JSON.stringify(selections));
        } else {
            sessionStorage.removeItem(salesSelectionStorageKey);
        }
    } catch (error) {
        // The approval flow still works when browser storage is unavailable.
    }
}

function rememberOpenApprovalProject(projectId) {
    try {
        if (projectId) {
            sessionStorage.setItem(salesOpenProjectStorageKey, String(projectId));
        } else {
            sessionStorage.removeItem(salesOpenProjectStorageKey);
        }
    } catch (error) {
        // Ignore storage restrictions without interrupting approval.
    }
}

function readOpenApprovalProject() {
    try {
        return sessionStorage.getItem(salesOpenProjectStorageKey) || '';
    } catch (error) {
        return '';
    }
}

function restoreSalesSelections() {
    const storedSelections = readStoredSalesSelections();
    const restoredSelections = {};

    document.querySelectorAll('[data-estimate-select]').forEach((control) => {
        const taskId = String(control.dataset.estimateSelect || '');
        const storedChoice = storedSelections[taskId] || '';
        const hasStoredChoice = Array.from(control.options).some((option) => option.value === storedChoice);

        if (!taskId || !hasStoredChoice || !storedChoice) {
            return;
        }

        control.value = storedChoice;
        salesSelections[taskId] = storedChoice;
        restoredSelections[taskId] = storedChoice;
        updateTaskChoiceStatus(taskId);
    });

    document.querySelectorAll('[data-feature-approval-workspace]').forEach((workspace) => {
        updateFeatureSelectionSummary(workspace);
    });

    try {
        if (Object.keys(restoredSelections).length > 0) {
            sessionStorage.setItem(salesSelectionStorageKey, JSON.stringify(restoredSelections));
        } else {
            sessionStorage.removeItem(salesSelectionStorageKey);
        }
    } catch (error) {
        // Ignore storage restrictions without interrupting approval.
    }
}

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

const salesChoiceLabels = {
    min: 'Fastest',
    likely: 'Balanced',
    max: 'High buffer'
};

function selectFeatureForReview(workspace, taskId, shouldFocus = false) {
    if (!workspace) return;

    const reviewPane = workspace.querySelector('[data-feature-review-pane]');
    workspace.classList.add('has-active-review');
    if (reviewPane) reviewPane.hidden = false;

    workspace.querySelectorAll('[data-feature-row]').forEach((row) => {
        const isSelected = row.dataset.featureRow === String(taskId);
        row.classList.toggle('is-selected', isSelected);
        row.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        if (isSelected && shouldFocus) row.focus({ preventScroll: true });
    });

    workspace.querySelectorAll('[data-feature-detail]').forEach((detail) => {
        detail.hidden = detail.dataset.featureDetail !== String(taskId);
    });

    const activeDetail = workspace.querySelector(`[data-feature-detail="${taskId}"]`);
    if (activeDetail && window.innerWidth < 992) {
        activeDetail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function closeFeatureReview(workspace, restoreFocus = true) {
    if (!workspace) return;

    const selectedRow = workspace.querySelector('[data-feature-row].is-selected');
    workspace.classList.remove('has-active-review');
    workspace.querySelector('[data-feature-review-pane]')?.setAttribute('hidden', '');

    workspace.querySelectorAll('[data-feature-row]').forEach((row) => {
        row.classList.remove('is-selected');
        row.setAttribute('aria-selected', 'false');
    });
    workspace.querySelectorAll('[data-feature-detail]').forEach((detail) => {
        detail.hidden = true;
    });

    if (restoreFocus && selectedRow) {
        selectedRow.focus({ preventScroll: true });
    }
}

function updateFeatureSelectionSummary(workspace) {
    if (!workspace) return;

    const totals = { min: 0, likely: 0, max: 0 };
    const selectedControls = Array.from(workspace.querySelectorAll('[data-estimate-select]'))
        .filter((control) => control.value);
    selectedControls.forEach((control) => {
        if (Object.prototype.hasOwnProperty.call(totals, control.value)) {
            totals[control.value] += 1;
        }
    });

    Object.entries(totals).forEach(([choice, count]) => {
        const counter = workspace.querySelector(`[data-choice-count="${choice}"]`);
        if (counter) counter.textContent = String(count);
    });

    const pendingCounter = workspace.querySelector('[data-pending-count]');
    const totalRows = workspace.querySelectorAll('[data-feature-row]').length;
    if (pendingCounter) pendingCounter.textContent = String(Math.max(totalRows - selectedControls.length, 0));
}

function getEstimateSelection(taskId) {
    const control = document.querySelector(`[data-estimate-select="${taskId}"]`);
    const selectedOption = control?.selectedOptions?.[0] || null;
    return {
        control,
        choice: control?.value || '',
        days: parseInt(selectedOption?.dataset?.days || '0', 10) || 0
    };
}

function updateTaskChoiceStatus(taskId) {
    const selection = getEstimateSelection(taskId);
    const approveButton = document.getElementById(`approveBtn_${taskId}`);

    if (!selection.choice) {
        if (approveButton) approveButton.disabled = true;
        return;
    }

    if (approveButton) approveButton.disabled = !(selection.days > 0);
}

function syncSalesSelection(taskId) {
    const selection = getEstimateSelection(taskId);
    if (selection.choice) {
        salesSelections[String(taskId)] = selection.choice;
    } else {
        delete salesSelections[String(taskId)];
    }
    updateTaskChoiceStatus(taskId);
    const workspace = selection.control?.closest('[data-feature-approval-workspace]')
        || document.querySelector(`[data-feature-detail="${taskId}"]`)?.closest('[data-feature-approval-workspace]');
    updateFeatureSelectionSummary(workspace);
    Object.keys(ganttProjects).forEach((projectId) => {
        const data = ganttProjects[projectId];
        if (!data || !data.tasks || !data.tasks.some(task => getTaskNumericId(task) === String(taskId))) {
            return;
        }
        refreshProjectGantt(projectId);
    });
    persistSalesSelections();
}

async function approveEstimation(taskId) {
    const selection = getEstimateSelection(taskId);
    const estimateChoice = selection.choice;
    const daysValue = selection.days;
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
    persistSalesSelections();
    rememberOpenApprovalProject(selection.control?.closest('[data-project-group]')?.dataset?.projectId || '');
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
    const panel = target?.closest('[data-project-gantt-panel]');
    if (panel?.hidden) return;
    if (target) {
        renderThreePointGantt(target, data.tasks, `salesGanttInline_${projectId}`);
    }
}

function setProjectGanttOpen(group, shouldOpen) {
    if (!group) return;

    const panel = group.querySelector('[data-project-gantt-panel]');
    const toggle = group.querySelector('[data-project-gantt-toggle]');
    if (!panel || !toggle) return;

    panel.hidden = !shouldOpen;
    toggle.classList.toggle('is-active', shouldOpen);
    toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    toggle.setAttribute('aria-label', shouldOpen ? 'Hide project Gantt' : 'Show project Gantt');

    if (shouldOpen) {
        const projectId = group.dataset.projectId;
        if (projectId) {
            refreshProjectGantt(projectId);
        }
    }
}

function setProjectGroupOpen(group, shouldOpen) {
    if (!group) {
        return;
    }

    const toggles = group.querySelectorAll('[data-project-toggle]');
    const body = group.querySelector('[data-project-body]');
    if (toggles.length === 0 || !body) {
        return;
    }

    group.classList.toggle('is-open', shouldOpen);
    toggles.forEach((toggle) => {
        toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    });

    if (shouldOpen) {
        body.hidden = false;
    } else {
        setProjectGanttOpen(group, false);
        body.hidden = true;
    }
}

const projectApprovalGroups = document.querySelectorAll('[data-project-group]');
const restoredOpenProjectId = readOpenApprovalProject();

projectApprovalGroups.forEach((group) => {
    const toggles = group.querySelectorAll('[data-project-toggle]');
    if (toggles.length === 0) {
        return;
    }

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const shouldOpen = !group.classList.contains('is-open');
            projectApprovalGroups.forEach(otherGroup => setProjectGroupOpen(otherGroup, false));
            setProjectGroupOpen(group, shouldOpen);
            rememberOpenApprovalProject(shouldOpen ? group.dataset.projectId : '');
        });
    });

    if (String(group.dataset.projectId || '') === restoredOpenProjectId || group.classList.contains('is-open')) {
        setProjectGroupOpen(group, true);
    } else {
        setProjectGroupOpen(group, false);
    }
});

document.querySelectorAll('[data-feature-approval-workspace]').forEach((workspace) => {
    const rows = workspace.querySelectorAll('[data-feature-row]');
    rows.forEach((row) => {
        const activateRow = () => selectFeatureForReview(workspace, row.dataset.featureRow);
        row.addEventListener('click', activateRow);
        row.addEventListener('keydown', (event) => {
            if (event.target !== row) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                activateRow();
            }
        });
    });

    workspace.querySelector('[data-close-feature-review]')?.addEventListener('click', () => {
        closeFeatureReview(workspace);
    });

    updateFeatureSelectionSummary(workspace);
});

restoreSalesSelections();

document.querySelectorAll('[data-project-gantt-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
        const group = toggle.closest('[data-project-group]');
        if (group && !group.classList.contains('is-open')) {
            projectApprovalGroups.forEach(otherGroup => setProjectGroupOpen(otherGroup, false));
            setProjectGroupOpen(group, true);
        }
        setProjectGanttOpen(group, toggle.getAttribute('aria-expanded') !== 'true');
    });
});

document.querySelectorAll('[data-reject-task-id]').forEach((button) => {
    button.addEventListener('click', () => {
        openRejectModal(button.dataset.rejectTaskId, button.dataset.rejectTaskTitle || '');
    });
});

document.querySelectorAll('.gantt-preview .gantt-bar').forEach((bar) => {
    bar.dataset.originalWidth = bar.style.width;
});

document.querySelectorAll('[data-estimate-select]').forEach((control) => {
    control.addEventListener('change', () => {
        const taskId = control.dataset.estimateSelect;
        if (!taskId) return;

        const workspace = control.closest('[data-feature-approval-workspace]');
        selectFeatureForReview(workspace, taskId);
        syncSalesSelection(taskId);
    });
});

document.getElementById('rejectModal')?.querySelector('form')?.addEventListener('submit', () => {
    persistSalesSelections();
    const selectedRow = document.querySelector('[data-feature-row].is-selected');
    rememberOpenApprovalProject(selectedRow?.closest('[data-project-group]')?.dataset?.projectId || '');
});

window.addEventListener('pagehide', persistSalesSelections);
</script>

<?php include '../../includes/footer.php'; ?>
