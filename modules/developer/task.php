<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/department-artifacts.php';
requireRole('developer');

$conn = getConnection();
$user = getCurrentUser();
workflowEnsureSchema($conn);
departmentArtifactEnsureSchema($conn);

$userDepartment = trim((string) ($user['department'] ?? ''));
$departmentProfile = departmentArtifactProfile($userDepartment);

$taskBaseQuery = "
    SELECT t.*,
           p.name AS project_name,
           latest_git.branch_name AS git_branch_name,
           latest_git.commit_hash AS git_commit_hash,
           latest_git.status AS git_status,
           latest_git.git_action AS git_action,
           latest_git.pull_request_url AS git_pull_request_url
    FROM tasks t
    LEFT JOIN projects p ON p.id = t.project_id
    LEFT JOIN task_git_updates latest_git ON latest_git.id = (
        SELECT tgu.id
        FROM task_git_updates tgu
        WHERE tgu.task_id = t.id
        ORDER BY tgu.created_at DESC, tgu.id DESC
        LIMIT 1
    )
";

// Check if project_phases column exists
$hasProjectPhases = dbColumnExists($conn, 'projects', 'project_phases');

// Get tasks assigned to this developer
$todoTasks = $conn->query($taskBaseQuery . " WHERE t.assigned_to = {$user['id']} AND t.status = 'todo' ORDER BY t.deadline ASC");
$inProgressTasks = $conn->query($taskBaseQuery . " WHERE t.assigned_to = {$user['id']} AND t.status IN ('in_progress', 'sul_revision', 'qa_failed') ORDER BY t.deadline ASC");
$reviewTasks = $conn->query($taskBaseQuery . " WHERE t.assigned_to = {$user['id']} AND t.status IN ('for_review', 'qa_testing', 'qa_passed', 'architect_review', 'merged', 'ready_for_release') ORDER BY t.deadline ASC");
$completedTasks = $conn->query($taskBaseQuery . " WHERE t.assigned_to = {$user['id']} AND t.status = 'completed' ORDER BY t.completed_at DESC LIMIT 10");

$departmentProjects = [];
$departmentProjectsResult = $conn->query("
    SELECT
        p.id,
        p.name,
        p.description,
        p.requested_features,
        p.timeline_start,
        p.timeline_end,
        c.name AS client_name,
        COUNT(t.id) AS assigned_task_count,
        SUM(CASE WHEN t.status IN ('for_review', 'qa_testing', 'qa_passed', 'architect_review', 'merged', 'ready_for_release', 'completed') THEN 1 ELSE 0 END) AS handoff_count
    FROM projects p
    JOIN tasks t ON t.project_id = p.id
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE t.assigned_to = {$user['id']}
    GROUP BY p.id, p.name, p.description, p.requested_features, p.timeline_start, p.timeline_end, c.name
    ORDER BY p.name ASC
");
if ($departmentProjectsResult && $departmentProjectsResult->num_rows > 0) {
    while ($row = $departmentProjectsResult->fetch_assoc()) {
        $row['requirements'] = departmentArtifactParseRequirements($row['requested_features'] ?? '');
        $row['additional_requirements'] = departmentArtifactAdditionalRequirements($row['requested_features'] ?? '');
        $departmentProjects[] = $row;
    }
}

// Get projects assigned to this developer (for Gantt modal) - ONLY projects with assigned tasks
$developerProjects = $conn->query("
    SELECT DISTINCT p.id, p.name, p.timeline_start, p.timeline_end" . ($hasProjectPhases ? ", p.project_phases" : "") . "
    FROM projects p
    JOIN tasks t ON p.id = t.project_id
    WHERE t.assigned_to = {$user['id']}
    ORDER BY p.name ASC
");

// Gantt data per project (for modal)
$ganttProjects = [];
if ($developerProjects && $developerProjects->num_rows > 0) {
    while ($project = $developerProjects->fetch_assoc()) {
        $pid = $project['id'];
        // Get tasks for this project assigned to this developer
        $tasks = $conn->query("
            SELECT t.id, t.title, t.started_at, t.created_at, t.deadline,
                   t.status, t.priority, t.estimated_days_likely,
                   (
                       SELECT fe.likely_days
                       FROM feature_estimations fe
                       WHERE fe.task_id = t.id AND fe.status = 'completed'
                       ORDER BY fe.submitted_at DESC
                       LIMIT 1
                   ) as fe_likely_days
            FROM tasks t
            WHERE t.project_id = $pid AND t.assigned_to = {$user['id']}
            ORDER BY t.deadline ASC
        ");
        
        $taskList = [];
        if ($tasks && $tasks->num_rows > 0) {
            while ($task = $tasks->fetch_assoc()) {
                $taskList[] = $task;
            }
        }
        
        // Decode project phases from JSON (configured by EXPERT)
        $projectPhases = [];
        if ($hasProjectPhases && !empty($project['project_phases'])) {
            $projectPhases = json_decode($project['project_phases'], true);
        }
        
        $ganttProjects[$pid] = [
            'project_name' => $project['name'],
            'timeline_start' => $project['timeline_start'],
            'timeline_end' => $project['timeline_end'],
            'project_phases' => $projectPhases,
            'tasks' => $taskList
        ];
    }
}

// Update task status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $newStatus = (string) ($_POST['new_status'] ?? '');
    $allowedStatuses = ['todo', 'in_progress'];

    if ($taskId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        $_SESSION['error'] = "Invalid task status update.";
        header('Location: task.php');
        exit();
    }

    $taskCheck = $conn->prepare("
        SELECT t.id, t.title, t.status, t.assigned_by, p.name AS project_name, p.sul_id
        FROM tasks t
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE t.id = ? AND t.assigned_to = ?
        LIMIT 1
    ");
    $taskCheck->bind_param("ii", $taskId, $user['id']);
    $taskCheck->execute();
    $task = $taskCheck->get_result()->fetch_assoc();
    $validTask = (bool) $task;
    $taskCheck->close();

    if (!$validTask) {
        $_SESSION['error'] = "You can only update tasks assigned to you.";
        header('Location: task.php');
        exit();
    }

    if (!in_array((string) ($task['status'] ?? ''), ['todo', 'in_progress', 'sul_revision', 'qa_failed', 'blocked'], true)) {
        $_SESSION['error'] = "This task is already in the controlled review workflow and cannot be moved by Developer.";
        header('Location: task.php');
        exit();
    }
    
    $stmt = $conn->prepare("UPDATE tasks SET status = ?, completed_at = NULL WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $taskId);
    $stmt->execute();
    
    workflowLogStatusChange((int) $user['id'], $taskId, (string) ($task['status'] ?? ''), $newStatus, 'developer_update_task_status');
    $_SESSION['success'] = "Task status updated";
    
    header('Location: task.php');
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-800: #343a40;
    --gray-900: #212529;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: white;
}

.page-header-modern h2 i {
    color: white;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9);
}

/* My Projects Section */
.my-projects-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.my-projects-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    background: white;
}

.my-projects-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
}

.my-projects-header h5 i {
    color: var(--theme-primary);
    margin-right: 0.5rem;
}

.project-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
}

.project-badge {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 40px;
    padding: 0.5rem 1rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
    cursor: pointer;
}

.project-badge:hover {
    background: white;
    border-color: var(--theme-primary);
    box-shadow: var(--shadow-sm);
    transform: translateY(-1px);
}

.project-badge i {
    color: var(--theme-primary);
    font-size: 0.85rem;
}

.project-badge span {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--gray-700);
}

.project-badge small {
    font-size: 0.7rem;
    color: var(--gray-400);
}

.department-workbench {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 20px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.department-workbench__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.2rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.08), #fff 70%);
}

.department-workbench__title {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    margin-bottom: 0.35rem;
    color: var(--gray-900);
    font-weight: 700;
}

.department-workbench__title i {
    color: var(--theme-primary);
}

.department-workbench__summary {
    color: var(--gray-600);
    font-size: 0.88rem;
    margin: 0;
}

.process-toggle {
    border: 1px solid rgba(31, 99, 146, 0.22);
    background: #fff;
    color: var(--theme-primary);
    border-radius: 999px;
    padding: 0.45rem 0.8rem;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}

.process-guide {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    background: var(--gray-50);
}

.process-guide__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

.process-step {
    display: flex;
    gap: 0.65rem;
    align-items: flex-start;
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 10px;
    padding: 0.8rem;
    color: var(--gray-700);
    font-size: 0.82rem;
}

.process-step__number {
    flex: 0 0 auto;
    width: 1.4rem;
    height: 1.4rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(31, 99, 146, 0.12);
    color: var(--theme-primary);
    font-size: 0.75rem;
    font-weight: 800;
}

.department-projects {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 1rem;
    padding: 1rem 1.5rem 1.5rem;
}

.department-project-card {
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 1rem;
    background: #fff;
}

.department-project-card h6 {
    color: var(--gray-900);
    font-weight: 700;
    margin-bottom: 0.35rem;
}

.department-project-card__meta {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
}

.department-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border-radius: 999px;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    color: var(--gray-600);
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.3rem 0.55rem;
}

.requirement-list {
    display: grid;
    gap: 0.45rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.requirement-list li {
    display: flex;
    gap: 0.45rem;
    align-items: flex-start;
    color: var(--gray-700);
    font-size: 0.8rem;
}

.requirement-list i {
    color: var(--theme-primary);
    margin-top: 0.16rem;
}

.tooltip-helper {
    color: var(--theme-primary);
    cursor: help;
}

/* Kanban Columns */
.kanban-column {
    background: var(--gray-50);
    border-radius: 20px;
    min-height: 500px;
    border: 1px solid var(--gray-200);
}

.kanban-header {
    padding: 1rem 1.25rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    border-radius: 20px 20px 0 0;
}

.kanban-header h5 {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.kanban-body {
    padding: 1rem;
    min-height: 450px;
}

/* Kanban Cards */
.kanban-card {
    background: white;
    border-radius: 12px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    cursor: grab;
    transition: all 0.2s;
    border: 1px solid var(--gray-200);
    position: relative;
}

.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--theme-primary);
}

.kanban-card:active {
    cursor: grabbing;
}

.task-priority-dot {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.priority-high { background-color: #dc2626; }
.priority-medium { background-color: #f59e0b; }
.priority-low { background-color: #10b981; }

/* Buttons */
.btn-action {
    background: white;
    border: 1px solid var(--gray-200);
    padding: 0.2rem 0.5rem;
    border-radius: 6px;
    font-size: 0.65rem;
    transition: all 0.2s;
    cursor: pointer;
}

.btn-action:hover {
    background: var(--theme-primary);
    border-color: var(--theme-primary);
    color: white;
}

.btn-action-view {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.2rem 0.5rem;
    border-radius: 6px;
    font-size: 0.65rem;
    transition: all 0.2s;
    cursor: pointer;
}

.btn-action-view:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Empty State */
.empty-state-mini {
    text-align: center;
    padding: 2rem 1rem;
}

.empty-state-mini i {
    font-size: 1.5rem;
    color: var(--gray-300);
    margin-bottom: 0.5rem;
}

.empty-state-mini p {
    color: var(--gray-500);
    font-size: 0.75rem;
    margin: 0;
}

/* Completed Card */
.completed-card {
    background: var(--gray-50);
    border-radius: 10px;
    padding: 0.5rem 0.75rem;
    margin-bottom: 0.5rem;
}

.completed-card small {
    font-size: 0.7rem;
}

.git-snippet {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.72rem;
    color: var(--theme-primary);
    margin-bottom: 0.65rem;
}

.git-update-item {
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    background: white;
}

.git-update-item:last-child {
    margin-bottom: 0;
}

.git-update-item code {
    font-size: 0.75rem;
}

.task-detail-hero {
    border: 1px solid rgba(31, 99, 146, 0.18);
    border-radius: 8px;
    padding: 1.15rem;
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.10), #fff 58%);
    margin-bottom: 1rem;
}

.task-detail-modal .modal-dialog {
    max-width: min(1180px, calc(100vw - 2rem));
}

.task-detail-modal .modal-content {
    border: 0;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22);
}

.task-detail-modal .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-bottom: 0;
    padding: 1rem 1.25rem;
}

.task-detail-modal .modal-title {
    color: #fff;
    font-weight: 700;
}

.task-detail-modal .btn-close {
    filter: invert(1) grayscale(100%) brightness(200%);
    opacity: 0.9;
}

.task-detail-modal .modal-body {
    background: #f8fafc;
    padding: 1.25rem;
}

.task-detail-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--theme-dark);
    margin: 0 0 0.45rem;
}

.task-detail-desc {
    color: var(--gray-600);
    font-size: 0.88rem;
    line-height: 1.55;
    margin: 0;
}

.task-facts-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.task-fact,
.task-detail-panel,
.task-side-panel {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
}

.task-fact {
    padding: 0.8rem;
    background: #fff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
}

.task-fact-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    font-weight: 700;
    color: var(--gray-500);
    margin-bottom: 0.3rem;
}

.task-fact-value {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--theme-dark);
}

.task-detail-panel {
    background: #fff;
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
}

.task-detail-panel h6,
.task-side-panel h6,
.task-side-panel label {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--theme-dark);
}

.task-side-panel {
    background: #fff;
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
}

.task-side-panel .form-control,
.task-side-panel .form-select {
    border-color: var(--gray-300);
    border-radius: 8px;
}

.task-side-panel .form-control:focus,
.task-side-panel .form-select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 0.18rem rgba(31, 99, 146, 0.16);
}

.task-side-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.task-side-heading label {
    margin: 0;
    line-height: 1.35;
}

.task-side-heading small {
    max-width: 8rem;
    text-align: right;
    line-height: 1.35;
}

.task-detail-action {
    width: 100%;
    justify-content: center;
    border: 0;
    border-radius: 8px;
    padding: 0.65rem 0.9rem;
    font-size: 0.82rem;
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: #fff;
}

.git-latest-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.8rem;
}

.git-latest-label {
    font-size: 0.7rem;
    color: var(--gray-500);
    margin-bottom: 0.2rem;
}

.git-latest-value {
    font-size: 0.85rem;
    color: var(--gray-900);
    font-weight: 600;
    word-break: break-word;
}

.department-modal-panel {
    border-left: 3px solid var(--theme-primary);
}

.department-modal-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.85rem;
}

.department-modal-heading h6 {
    margin: 0;
}

.department-modal-summary {
    color: var(--gray-600);
    font-size: 0.82rem;
    margin-bottom: 0.85rem;
}

.department-requirements {
    display: grid;
    gap: 0.5rem;
    margin: 0 0 0.85rem;
    padding: 0;
    list-style: none;
}

.department-requirements li {
    display: flex;
    gap: 0.5rem;
    align-items: flex-start;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 0.65rem 0.75rem;
    color: var(--gray-700);
    font-size: 0.82rem;
    background: var(--gray-50);
}

.department-requirements i {
    color: var(--theme-primary);
    margin-top: 0.18rem;
}

.artifact-list {
    display: grid;
    gap: 0.65rem;
}

.artifact-item {
    border: 1px solid var(--gray-200);
    border-radius: 10px;
    padding: 0.75rem;
    background: #fff;
}

.artifact-item__title {
    color: var(--gray-900);
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.artifact-item__meta {
    color: var(--gray-500);
    font-size: 0.72rem;
    margin-bottom: 0.45rem;
}

.artifact-item__links {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.artifact-note {
    color: var(--gray-600);
    font-size: 0.8rem;
    margin-top: 0.45rem;
}

@media (max-width: 767.98px) {
    .task-facts-grid,
    .git-latest-grid,
    .process-guide__grid {
        grid-template-columns: 1fr;
    }

    .task-side-heading {
        display: block;
    }

    .task-side-heading small {
        display: block;
        max-width: none;
        text-align: left;
        margin-top: 0.25rem;
    }
}

/* Modal Gantt Styles */
.gantt-modal-body {
    max-height: 70vh;
    overflow-y: auto;
}

.gantt-project-detail {
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--gray-200);
}

.gantt-project-detail:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.gantt-project-title {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--gray-800);
    margin-bottom: 0.25rem;
}

.gantt-project-dates {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-bottom: 1rem;
}

.gantt-grid {
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    overflow-x: auto;
}

.gantt-row {
    display: grid;
    grid-template-columns: 220px 1fr;
    align-items: stretch;
    border-top: 1px solid var(--gray-200);
    background: white;
    min-width: 500px;
}

.gantt-row:first-child {
    border-top: none;
}

.gantt-task-col {
    padding: 0.6rem 0.8rem;
    font-weight: 600;
    color: var(--gray-800);
    background: #f8fafc;
    border-right: 1px solid var(--gray-200);
}

.gantt-time-col {
    position: relative;
    padding: 0.35rem 0.5rem;
}

.gantt-quarters {
    display: grid;
    gap: 0;
    border-bottom: 1px solid var(--gray-200);
}

.gantt-quarter {
    text-align: center;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--gray-700);
    padding: 0.35rem 0;
    background: #eef5fb;
    border-left: 1px solid var(--gray-200);
}

.gantt-quarter:first-child {
    border-left: none;
}

.gantt-months {
    display: grid;
    gap: 0;
}

.gantt-month {
    text-align: center;
    font-size: 0.7rem;
    color: var(--gray-600);
    padding: 0.3rem 0;
    border-left: 1px solid var(--gray-200);
    background: #f6f9fc;
}

.gantt-month:first-child {
    border-left: none;
}

.gantt-time-track {
    position: relative;
    height: 28px;
    background: repeating-linear-gradient(
        to right,
        transparent,
        transparent calc(100% / var(--month-count) - 1px),
        rgba(222, 226, 230, 0.9) calc(100% / var(--month-count) - 1px),
        rgba(222, 226, 230, 0.9) calc(100% / var(--month-count))
    );
}

.gantt-bar {
    position: absolute;
    top: 6px;
    height: 16px;
    background: #2f80c5;
    border-radius: 6px;
}

.gantt-bar.completed {
    background: #10b981;
}

.gantt-bar.in-progress {
    background: #f59e0b;
}

.gantt-task-title {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.2rem;
}

.gantt-task-meta {
    font-size: 0.7rem;
    color: var(--gray-500);
}

/* Phase Colors */
.phase-planning { background: #8b5cf6; }
.phase-research { background: #3b82f6; }
.phase-design { background: #06b6d4; }
.phase-implementation { background: #10b981; }
.phase-followup { background: #f59e0b; }

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .my-projects-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-workbench,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-project-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-column,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-update-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-fact {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .my-projects-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-workbench__header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-guide,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-chip,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-requirements li,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-badge,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-modal .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-hero,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-col,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-month {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-badge:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-action {
    background: rgba(31, 99, 146, 0.14) !important;
    border-color: rgba(31, 99, 146, 0.28) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .my-projects-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-workbench__title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-project-card h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-badge span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-panel h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-fact-value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-latest-value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-project-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-col {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-badge small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-workbench__summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-chip,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requirement-list li,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-modal-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-requirements li,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-mini p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-desc,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-fact-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-latest-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-project-dates,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-month,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .my-projects-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-project-detail,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-grid,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-col,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-quarters,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-quarter,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-month {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel .form-select {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel .form-control::placeholder {
    color: #7f9bb6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-quarter {
    background: rgba(31, 99, 146, 0.18) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-time-track {
    background: repeating-linear-gradient(
        to right,
        rgba(157, 182, 207, 0.04),
        rgba(157, 182, 207, 0.04) calc(100% / var(--month-count) - 1px),
        rgba(31, 99, 146, 0.28) calc(100% / var(--month-count) - 1px),
        rgba(31, 99, 146, 0.28) calc(100% / var(--month-count))
    );
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .page-header-modern h2 {
        font-size: 1.5rem;
    }
    
    .kanban-column {
        margin-bottom: 1rem;
    }

    .gantt-row {
        grid-template-columns: 1fr;
    }

    .gantt-task-col {
        border-right: none;
        border-bottom: 1px solid var(--gray-200);
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-tasks me-2"></i> 
                My Tasks
            </h2>
            <p>Drag and drop tasks to update status, or use the buttons below</p>
        </div>
    </div>

    <!-- My Projects Section -->
    <div class="my-projects-card">
        <div class="my-projects-header">
            <h5>
                <i class="fas fa-project-diagram"></i>
                My Projects
                <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Open a project timeline without changing task status."></i>
            </h5>
        </div>
        <div class="project-list">
            <?php if(count($ganttProjects) > 0): ?>
                <?php foreach($ganttProjects as $project): ?>
                    <div class="project-badge" onclick="showGanttModal(<?php echo htmlspecialchars(json_encode($project)); ?>)">
                        <i class="fas fa-folder"></i>
                        <span><?php echo htmlspecialchars($project['project_name']); ?></span>
                        <small>(<?php echo count($project['tasks']); ?> tasks)</small>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state-mini" style="flex: 1;">
                    <i class="fas fa-folder-open"></i>
                    <p>No projects assigned yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="department-workbench">
        <div class="department-workbench__header">
            <div>
                <div class="department-workbench__title">
                    <i class="fas <?php echo htmlspecialchars((string) $departmentProfile['icon']); ?>"></i>
                    <?php echo htmlspecialchars((string) $departmentProfile['title']); ?>
                    <i class="fas fa-circle-info tooltip-helper" data-bs-toggle="tooltip" title="This department panel adds context and handoff tools only. The current task workflow below stays the source of truth."></i>
                </div>
                <p class="department-workbench__summary"><?php echo htmlspecialchars((string) $departmentProfile['summary']); ?></p>
            </div>
            <button class="process-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#departmentProcessGuide" aria-expanded="false" aria-controls="departmentProcessGuide">
                <i class="fas fa-list-check me-1"></i> Process Guide
            </button>
        </div>
        <div class="collapse" id="departmentProcessGuide">
            <div class="process-guide">
                <div class="process-guide__grid">
                    <?php foreach (($departmentProfile['steps'] ?? []) as $stepIndex => $step): ?>
                        <div class="process-step">
                            <span class="process-step__number"><?php echo $stepIndex + 1; ?></span>
                            <span><?php echo htmlspecialchars((string) $step); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="department-projects">
            <?php if (count($departmentProjects) > 0): ?>
                <?php foreach ($departmentProjects as $project): ?>
                    <?php
                    $requirements = array_slice($project['requirements'] ?? [], 0, 4);
                    $taskCount = (int) ($project['assigned_task_count'] ?? 0);
                    $handoffCount = (int) ($project['handoff_count'] ?? 0);
                    ?>
                    <div class="department-project-card">
                        <h6><?php echo htmlspecialchars((string) ($project['name'] ?? 'Project')); ?></h6>
                        <div class="department-project-card__meta">
                            <span class="department-chip"><i class="fas fa-building"></i><?php echo htmlspecialchars((string) ($project['client_name'] ?? 'Client')); ?></span>
                            <span class="department-chip"><i class="fas fa-list-check"></i><?php echo $taskCount; ?> task<?php echo $taskCount === 1 ? '' : 's'; ?></span>
                            <span class="department-chip"><i class="fas fa-share-from-square"></i><?php echo $handoffCount; ?> in review+</span>
                        </div>
                        <?php if ($userDepartment === 'UI/UX Designer'): ?>
                            <div class="small fw-semibold mb-2">
                                Requirements
                                <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="These are the Sales-captured project requirements UI/UX should read before uploading design handoffs."></i>
                            </div>
                        <?php else: ?>
                            <div class="small fw-semibold mb-2">
                                Department Inputs
                                <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Use these project inputs as context while keeping implementation and review in the Kanban flow."></i>
                            </div>
                        <?php endif; ?>
                        <?php if (count($requirements) > 0): ?>
                            <ul class="requirement-list">
                                <?php foreach ($requirements as $requirement): ?>
                                    <li><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars((string) $requirement); ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted small mb-0">No captured feature requirements yet.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state-mini" style="grid-column: 1 / -1;">
                    <i class="fas fa-inbox"></i>
                    <p>No department work items yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- To Do Column -->
        <div class="col-md-3">
            <div class="kanban-column">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-clock text-secondary me-2"></i>To Do <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Tasks waiting for you to start. Moving from here only sets the task to In Progress."></i></span>
                        <span class="badge bg-secondary"><?php echo $todoTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="todo-column">
                    <?php while($task = $todoTasks->fetch_assoc()): ?>
                    <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>" draggable="true">
                        <div class="task-priority-dot priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr((string) ($task['description'] ?? ''), 0, 80)); ?></p>
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr((string) ($task['git_commit_hash'] ?? ''), 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo !empty($task['deadline']) ? date('M d', strtotime((string) $task['deadline'])) : 'No deadline'; ?></small>
                            <div>
                                <button class="btn-action me-1" onclick="startTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="Start this task without changing the controlled review workflow.">
                                    <i class="fas fa-play"></i> Start
                                </button>
                                <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="Open task details, department handoff tools, comments, and Git updates.">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($todoTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-check-circle"></i>
                        <p>No tasks in To Do</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- In Progress Column -->
        <div class="col-md-3">
            <div class="kanban-column">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-spinner text-primary me-2"></i>In Progress <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Work is active here. Submit a PR from task details when it is ready for review."></i></span>
                        <span class="badge bg-primary"><?php echo $inProgressTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="progress-column">
                    <?php while($task = $inProgressTasks->fetch_assoc()): ?>
                    <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>" draggable="true">
                        <div class="task-priority-dot priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr((string) ($task['description'] ?? ''), 0, 80)); ?></p>
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr((string) ($task['git_commit_hash'] ?? ''), 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo !empty($task['deadline']) ? date('M d', strtotime((string) $task['deadline'])) : 'No deadline'; ?></small>
                            <div>
                                <button class="btn-action me-1" onclick="viewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="Open task details and submit the PR through the Git update form.">
                                    <i class="fas fa-code-pull-request"></i> Submit PR
                                </button>
                                <button class="btn-action me-1" onclick="reviewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="Move this task back to To Do if work has not started cleanly.">
                                    <i class="fas fa-undo"></i> Back
                                </button>
                                <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="Open task details, department handoff tools, comments, and Git updates.">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($inProgressTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-play-circle"></i>
                        <p>No tasks in progress</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Review Column -->
        <div class="col-md-3">
            <div class="kanban-column">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-check-circle text-warning me-2"></i>Workflow Review <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Tasks arrive here only through PR submission and continue through SUL, QA, and Architect review."></i></span>
                        <span class="badge bg-warning"><?php echo $reviewTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="review-column">
                    <?php while($task = $reviewTasks->fetch_assoc()): ?>
                    <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>" draggable="true">
                        <div class="task-priority-dot priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr((string) ($task['description'] ?? ''), 0, 80)); ?></p>
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr((string) ($task['git_commit_hash'] ?? ''), 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo !empty($task['deadline']) ? date('M d', strtotime((string) $task['deadline'])) : 'No deadline'; ?></small>
                            <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="Review the task details and handoff history.">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($reviewTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-check-double"></i>
                        <p>No tasks in review</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Completed Column -->
        <div class="col-md-3">
            <div class="kanban-column">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-check-double text-success me-2"></i>Completed <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Completion remains controlled by the existing SUL, QA, Architect, and release flow."></i></span>
                        <span class="badge bg-success"><?php echo $completedTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body">
                    <?php while($task = $completedTasks->fetch_assoc()): ?>
                    <div class="completed-card">
                        <div class="d-flex justify-content-between">
                            <small><s><?php echo htmlspecialchars($task['title']); ?></s></small>
                            <small class="text-muted"><?php echo !empty($task['completed_at']) ? date('M d', strtotime((string) $task['completed_at'])) : 'No date'; ?></small>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($completedTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-trophy"></i>
                        <p>No completed tasks yet</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Gantt Chart Modal -->
<div class="modal fade modal-modern" id="ganttModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-stream me-2"></i>Project Timeline (Gantt Chart)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body gantt-modal-body" id="ganttModalContent">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<!-- Task Detail Modal -->
<div class="modal fade modal-modern task-detail-modal" id="taskModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Task Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetails">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const currentDepartment = <?php echo json_encode($userDepartment); ?>;
const departmentProfile = <?php echo json_encode($departmentProfile); ?>;

function initTooltips(root = document) {
    if (!window.bootstrap || !bootstrap.Tooltip) {
        return;
    }

    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        bootstrap.Tooltip.getOrCreateInstance(element);
    });
}

function updateKanbanColumnCount(columnBody) {
    if (!columnBody) {
        return;
    }

    const column = columnBody.closest('.kanban-column');
    const badge = column?.querySelector('.kanban-header .badge');
    const count = columnBody.querySelectorAll('.kanban-card').length;
    if (badge) {
        badge.textContent = String(count);
    }

    ensureKanbanColumnEmptyState(columnBody);
}

function getKanbanEmptyState(columnBody) {
    if (columnBody.id === 'todo-column') {
        return { icon: 'fa-check-circle', text: 'No tasks in To Do' };
    }
    if (columnBody.id === 'progress-column') {
        return { icon: 'fa-play-circle', text: 'No tasks in progress' };
    }
    if (columnBody.id === 'review-column') {
        return { icon: 'fa-check-double', text: 'No tasks in review' };
    }

    return { icon: 'fa-inbox', text: 'No tasks' };
}

function ensureKanbanColumnEmptyState(columnBody) {
    const count = columnBody.querySelectorAll('.kanban-card').length;
    const existing = columnBody.querySelector('.empty-state-mini');

    if (count > 0) {
        existing?.remove();
        return;
    }

    if (existing) {
        return;
    }

    const config = getKanbanEmptyState(columnBody);
    const empty = document.createElement('div');
    empty.className = 'empty-state-mini';
    empty.innerHTML = `<i class="fas ${config.icon}"></i><p>${config.text}</p>`;
    columnBody.appendChild(empty);
}

function transformKanbanCardForReviewColumn(card, taskId) {
    const footer = card.querySelector('.d-flex.justify-content-between.align-items-center');
    if (!footer) {
        return;
    }

    const dueLabel = footer.querySelector('small');
    const dueHtml = dueLabel ? dueLabel.outerHTML : '';
    footer.innerHTML = `
        ${dueHtml}
        <button class="btn-action-view" onclick="viewTask(${taskId})">
            <i class="fas fa-eye"></i> View
        </button>
    `;
}

function moveKanbanTaskToReview(taskId) {
    const card = document.querySelector(`.kanban-card[data-task-id="${String(taskId)}"]`);
    const reviewColumn = document.getElementById('review-column');

    if (!card || !reviewColumn || card.closest('.kanban-body') === reviewColumn) {
        return;
    }

    const sourceColumn = card.closest('.kanban-body');
    transformKanbanCardForReviewColumn(card, taskId);
    reviewColumn.querySelector('.empty-state-mini')?.remove();
    reviewColumn.appendChild(card);

    updateKanbanColumnCount(sourceColumn);
    updateKanbanColumnCount(reviewColumn);
}

function startTask(taskId) {
    updateTaskStatus(taskId, 'in_progress');
}

function reviewTask(taskId) {
    updateTaskStatus(taskId, 'todo');
}

function updateTaskStatus(taskId, status) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="task_id" value="${taskId}">
        <input type="hidden" name="new_status" value="${status}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function showGanttModal(project) {
    // Check if project has timeline dates
    if (!project.timeline_start || !project.timeline_end) {
        const html = `
            <div class="gantt-project-detail">
                <div class="gantt-project-title">${escapeHtml(project.project_name)}</div>
                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Project Timeline Not Configured</strong><br>
                    The Expert needs to configure the project timeline and phases in the Feature Estimation page.
                    <br><small>Please contact the Expert assigned to this project.</small>
                </div>
            </div>
        `;
        document.getElementById('ganttModalContent').innerHTML = html;
        new bootstrap.Modal(document.getElementById('ganttModal')).show();
        return;
    }
    
    // Check if there are tasks
    if (!project.tasks || project.tasks.length === 0) {
        const html = `
            <div class="gantt-project-detail">
                <div class="gantt-project-title">${escapeHtml(project.project_name)}</div>
                <div class="gantt-project-dates">
                    ${new Date(project.timeline_start).toLocaleDateString()} - ${new Date(project.timeline_end).toLocaleDateString()}
                </div>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    <strong>No Tasks Assigned</strong><br>
                    You don't have any tasks assigned for this project yet.
                </div>
            </div>
        `;
        document.getElementById('ganttModalContent').innerHTML = html;
        new bootstrap.Modal(document.getElementById('ganttModal')).show();
        return;
    }

    const projectStart = new Date(project.timeline_start).getTime();
    const projectEnd = new Date(project.timeline_end).getTime();
    const range = Math.max(1, projectEnd - projectStart);
    
    // Calculate months and quarters
    let months = [];
    let quarters = {};
    let cursor = new Date(project.timeline_start);
    cursor.setDate(1);
    
    while (cursor <= new Date(project.timeline_end)) {
        const monthKey = new Date(cursor).getTime();
        months.push(monthKey);
        const q = 'Q' + Math.ceil((cursor.getMonth() + 1) / 3) + ' ' + cursor.getFullYear();
        if (!quarters[q]) quarters[q] = 0;
        quarters[q]++;
        cursor.setMonth(cursor.getMonth() + 1);
    }
    const monthCount = Math.max(1, months.length);
    
    // Get project phases from the expert's configuration
    const phases = project.project_phases || [];
    const phaseColors = {
        'Planning': '#8b5cf6',
        'Research': '#3b82f6',
        'Design': '#06b6d4',
        'Implementation': '#10b981',
        'Follow Up': '#f59e0b'
    };
    
    let phasesHtml = '';
    if (phases.length > 0) {
        phases.forEach(phase => {
            const phaseColor = phaseColors[phase.name] || '#6c757d';
            let phaseLeft = 0;
            let phaseWidth = 100;
            
            if (phase.start_date && phase.end_date) {
                const phaseStart = new Date(phase.start_date).getTime();
                const phaseEnd = new Date(phase.end_date).getTime();
                phaseLeft = ((phaseStart - projectStart) / range) * 100;
                phaseWidth = ((phaseEnd - phaseStart) / range) * 100;
                if (phaseLeft < 0) { phaseWidth += phaseLeft; phaseLeft = 0; }
                if (phaseLeft + phaseWidth > 100) { phaseWidth = 100 - phaseLeft; }
                if (phaseWidth < 2) phaseWidth = 2;
            }
            
            phasesHtml += `
                <div class="gantt-row">
                    <div class="gantt-task-col" style="background: ${phaseColor}10;">
                        <div class="gantt-task-title" style="color: ${phaseColor};">${escapeHtml(phase.name)}</div>
                        ${phase.start_date && phase.end_date ? `<div class="gantt-task-meta">${new Date(phase.start_date).toLocaleDateString()} - ${new Date(phase.end_date).toLocaleDateString()}</div>` : ''}
                    </div>
                    <div class="gantt-time-col">
                        <div class="gantt-time-track" style="--month-count: ${monthCount};">
                            <div class="gantt-bar" style="left: ${phaseLeft}%; width: ${phaseWidth}%; background: ${phaseColor}; opacity: 0.7;"></div>
                        </div>
                    </div>
                </div>
            `;
        });
    } else {
        // Default phases if none configured by expert
        const defaultPhases = [
            { name: "Planning", color: "#8b5cf6" },
            { name: "Research", color: "#3b82f6" },
            { name: "Design", color: "#06b6d4" },
            { name: "Implementation", color: "#10b981" },
            { name: "Follow Up", color: "#f59e0b" }
        ];
        defaultPhases.forEach(phase => {
            phasesHtml += `
                <div class="gantt-row">
                    <div class="gantt-task-col" style="background: ${phase.color}10;">
                        <div class="gantt-task-title" style="color: ${phase.color};">${phase.name}</div>
                        <div class="gantt-task-meta text-muted">Awaiting expert configuration</div>
                    </div>
                    <div class="gantt-time-col">
                        <div class="gantt-time-track" style="--month-count: ${monthCount};">
                            <div class="gantt-bar" style="width: 100%; background: ${phase.color}; opacity: 0.5;"></div>
                        </div>
                    </div>
                </div>
            `;
        });
    }
    
    let tasksHtml = '';
    let cursorTs = projectStart;
    project.tasks.forEach(task => {
        let startTs = task.started_at ? new Date(task.started_at).getTime() : null;
        let endTs = task.deadline ? new Date(task.deadline).getTime() : null;

        if (!startTs || !endTs) {
            const likelyDays = parseFloat(task.fe_likely_days || task.estimated_days_likely || 1);
            const durationMs = Math.max(1, likelyDays) * 24 * 60 * 60 * 1000;
            startTs = cursorTs;
            endTs = startTs + durationMs;
        }

        cursorTs = endTs;
        
        let left = ((startTs - projectStart) / range) * 100;
        let width = ((endTs - startTs) / range) * 100;
        
        if (left < 0) { width += left; left = 0; }
        if (left + width > 100) { width = 100 - left; }
        if (width < 2) width = 2;
        
        let barClass = '';
        if (task.status === 'completed') barClass = 'completed';
        else if (task.status === 'in_progress') barClass = 'in-progress';
        
        tasksHtml += `
            <div class="gantt-row">
                <div class="gantt-task-col">
                    <div class="gantt-task-title" title="${escapeHtml(task.title)}">
                        ${escapeHtml(task.title)}
                    </div>
                    <div class="gantt-task-meta">
                        <span class="badge-priority-${task.priority}" style="font-size: 0.6rem;">${task.priority}</span>
                        ${new Date(startTs).toLocaleDateString()} - ${new Date(endTs).toLocaleDateString()}
                    </div>
                </div>
                <div class="gantt-time-col">
                    <div class="gantt-time-track" style="--month-count: ${monthCount};">
                        <div class="gantt-bar ${barClass}" style="left: ${left}%; width: ${width}%;"></div>
                    </div>
                </div>
            </div>
        `;
    });
    
    let quartersHtml = '';
    let monthHtml = '';
    let quarterWidths = Object.values(quarters).map(c => c + 'fr').join(' ');
    for (let [qLabel, qCount] of Object.entries(quarters)) {
        quartersHtml += `<div class="gantt-quarter">${qLabel}</div>`;
    }
    for (let mTs of months) {
        let monthDate = new Date(mTs);
        monthHtml += `<div class="gantt-month">${monthDate.toLocaleDateString('en-US', { month: 'short', year: '2-digit' })}</div>`;
    }
    
    const html = `
        <div class="gantt-project-detail">
            <div class="gantt-project-title">${escapeHtml(project.project_name)}</div>
            <div class="gantt-project-dates">
                ${new Date(project.timeline_start).toLocaleDateString()} - ${new Date(project.timeline_end).toLocaleDateString()}
            </div>
            
            <!-- Project Phases Section (Configured by Expert) -->
            <div class="gantt-phases">
                <div class="gantt-grid">
                    <div class="gantt-row">
                        <div class="gantt-task-col">Project Phases</div>
                        <div class="gantt-time-col p-0">
                            <div class="gantt-quarters" style="grid-template-columns: ${quarterWidths};">
                                ${quartersHtml}
                            </div>
                            <div class="gantt-months" style="grid-template-columns: repeat(${monthCount}, 1fr);">
                                ${monthHtml}
                            </div>
                        </div>
                    </div>
                    ${phasesHtml}
                </div>
            </div>
            
            <!-- Tasks Section -->
            <div class="gantt-tasks mt-4">
                <div class="gantt-grid">
                    <div class="gantt-row">
                        <div class="gantt-task-col">Your Tasks</div>
                        <div class="gantt-time-col p-0">
                            <div class="gantt-quarters" style="grid-template-columns: ${quarterWidths};">
                                ${quartersHtml}
                            </div>
                            <div class="gantt-months" style="grid-template-columns: repeat(${monthCount}, 1fr);">
                                ${monthHtml}
                            </div>
                        </div>
                    </div>
                    ${tasksHtml}
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('ganttModalContent').innerHTML = html;
    new bootstrap.Modal(document.getElementById('ganttModal')).show();
}

function viewTask(taskId) {
    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        data: { id: taskId },
        success: function(response) {
            const task = typeof response === 'string' ? JSON.parse(response) : response;
            const latestGit = task.latest_git_update;
            const deadlineText = task.deadline ? new Date(task.deadline).toLocaleDateString() : 'No deadline set';
            const estimateText = [task.estimated_days_min, task.estimated_days_likely, task.estimated_days_max].every(value => value)
                ? `Min ${task.estimated_days_min}d | Likely ${task.estimated_days_likely}d | Max ${task.estimated_days_max}d`
                : 'No estimate available';
            const departmentPanel = buildDepartmentPanel(task);
            let html = `
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="task-detail-hero">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <div>
                                    <h5 class="task-detail-title">${escapeHtml(task.title)}</h5>
                                    <p class="task-detail-desc">${task.description ? escapeHtml(task.description) : 'No description provided.'}</p>
                                </div>
                                <span class="badge-priority-${task.priority}">${escapeHtml(task.priority || 'medium')}</span>
                            </div>
                        </div>

                        <div class="task-facts-grid">
                            <div class="task-fact">
                                <div class="task-fact-label">Deadline</div>
                                <div class="task-fact-value">${escapeHtml(deadlineText)}</div>
                            </div>
                            <div class="task-fact">
                                <div class="task-fact-label">Status</div>
                                <div class="task-fact-value">${escapeHtml((task.status || 'todo').replaceAll('_', ' '))}</div>
                            </div>
                            <div class="task-fact">
                                <div class="task-fact-label">Estimate</div>
                                <div class="task-fact-value">${escapeHtml(estimateText)}</div>
                            </div>
                        </div>

                        ${departmentPanel}

                        <div class="task-detail-panel">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <h6><i class="fas fa-code-branch me-2 text-primary"></i>Latest Git Activity</h6>
                                    <div class="small text-muted mt-1">${task.git_updates_count} update(s) logged for this task</div>
                                </div>
                                ${latestGit ? `<span class="badge bg-light text-primary border">${formatGitAction(latestGit.git_action)}</span>` : ''}
                            </div>
                            ${latestGit ? `
                                <div class="git-latest-grid mt-3">
                                    <div class="git-latest-item">
                                        <div class="git-latest-label">Branch</div>
                                        <div class="git-latest-value">${escapeHtml(latestGit.branch_name)}</div>
                                    </div>
                                    <div class="git-latest-item">
                                        <div class="git-latest-label">Status</div>
                                        <div class="git-latest-value">${formatGitStatusBadge(latestGit.status)}</div>
                                    </div>
                                    <div class="git-latest-item">
                                        <div class="git-latest-label">Commit</div>
                                        <div class="git-latest-value">${latestGit.commit_hash ? `<code>${escapeHtml(shortCommit(latestGit.commit_hash))}</code>` : '<span class="text-muted">No commit hash</span>'}</div>
                                    </div>
                                    <div class="git-latest-item">
                                        <div class="git-latest-label">Updated By</div>
                                        <div class="git-latest-value">${escapeHtml(latestGit.user_name || 'Unknown')}</div>
                                    </div>
                                    <div class="git-latest-item">
                                        <div class="git-latest-label">Commit Message</div>
                                        <div class="git-latest-value">${latestGit.commit_message ? escapeHtml(latestGit.commit_message) : '<span class="text-muted">No commit message</span>'}</div>
                                    </div>
                                    <div class="git-latest-item">
                                        <div class="git-latest-label">Links</div>
                                        <div class="git-latest-value d-flex gap-2 flex-wrap">
                                            ${latestGit.repository_url ? `<a href="${escapeAttribute(latestGit.repository_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="fas fa-link me-1"></i>Repository</a>` : ''}
                                            ${latestGit.pull_request_url ? `<a href="${escapeAttribute(latestGit.pull_request_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="fas fa-code-pull-request me-1"></i>Pull Request</a>` : ''}
                                            ${!latestGit.repository_url && !latestGit.pull_request_url ? '<span class="text-muted">No links attached</span>' : ''}
                                        </div>
                                    </div>
                                    <div class="git-latest-item">
                                        <div class="git-latest-label">Notes</div>
                                        <div class="git-latest-value">${latestGit.notes ? escapeHtml(latestGit.notes) : '<span class="text-muted">No notes provided</span>'}</div>
                                    </div>
                                </div>
                            ` : `<p class="text-muted mb-0 mt-3">No Git activity has been recorded for this task yet.</p>`}
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="task-side-panel">
                            <div class="task-side-heading">
                                <label class="form-label mb-0 fw-semibold">Log Git Update</label>
                                <small class="text-muted">Branch, commit, PR</small>
                            </div>
                            <div class="mb-2">
                                <select id="gitAction" class="form-select form-select-sm">
                                    <option value="commit_pushed">Commit Pushed</option>
                                    <option value="branch_created">Branch Created</option>
                                    <option value="pull_request_opened">Pull Request Opened</option>
                                    <option value="note">General Note</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <input id="gitBranchName" class="form-control form-control-sm" placeholder="Branch name (required)">
                            </div>
                            <div class="mb-2">
                                <input id="gitCommitHash" class="form-control form-control-sm" placeholder="Commit hash">
                            </div>
                            <div class="mb-2">
                                <input id="gitCommitMessage" class="form-control form-control-sm" placeholder="Commit message">
                            </div>
                            <div class="mb-2">
                                <input id="gitPullRequestUrl" class="form-control form-control-sm" placeholder="Pull request URL">
                            </div>
                            <div class="mb-2">
                                <input id="gitRepositoryUrl" class="form-control form-control-sm" placeholder="Repository URL">
                            </div>
                            <div class="mb-2">
                                <select id="gitStatus" class="form-select form-select-sm">
                                    <option value="active">Active</option>
                                    <option value="in_review">In Review</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <textarea id="gitNotes" class="form-control form-control-sm" rows="3" placeholder="Notes for this Git update"></textarea>
                            </div>
                            <button class="btn-primary-modern task-detail-action" onclick="addGitUpdate(${taskId})">
                                <i class="fas fa-code-branch"></i> Save Git Update
                            </button>
                        </div>
                        <div class="task-detail-panel">
                            <h6 class="mb-2">Git History</h6>
                            <div id="gitUpdatesList" class="small"></div>
                        </div>
                        <div class="task-side-panel">
                            <div class="mb-3">
                                <label class="form-label">Add Comment</label>
                                <textarea id="taskComment" class="form-control" rows="3"></textarea>
                            </div>
                            <button class="btn-primary-modern task-detail-action" onclick="addComment(${taskId})">
                                <i class="fas fa-paper-plane"></i> Add Comment
                            </button>
                        </div>
                        <div class="task-detail-panel">
                            <h6 class="mb-2">Comments</h6>
                            <div id="commentsList" class="small"></div>
                        </div>
                    </div>
                </div>
            `;
            $('#taskDetails').html(html);
            loadComments(taskId);
            loadGitUpdates(taskId);
            initTooltips(document.getElementById('taskDetails'));
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        },
        error: function(xhr) {
            const message = xhr.responseJSON?.error || 'Unable to load task details';
            $('#taskDetails').html(`<div class="alert alert-danger mb-0">${escapeHtml(message)}</div>`);
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }
    });
}

function buildDepartmentPanel(task) {
    const isUiUx = currentDepartment === 'UI/UX Designer';
    const requirements = Array.isArray(task.project_requirements) ? task.project_requirements : [];
    const requirementItems = requirements.length > 0
        ? requirements.map((item) => `<li><i class="fas fa-check-circle"></i><span>${escapeHtml(item)}</span></li>`).join('')
        : '<li><i class="fas fa-circle-info"></i><span>No captured feature requirements yet.</span></li>';
    const additionalRequirements = task.additional_requirements
        ? `<div class="mt-3">
                <div class="small fw-semibold mb-2">Additional Requirements</div>
                <div class="small text-muted">${escapeHtml(task.additional_requirements)}</div>
           </div>`
        : '';
    const uploadInput = isUiUx
        ? `<div class="mb-2">
                <label class="form-label">
                    ${escapeHtml(departmentProfile.file_label || 'Design picture')}
                    <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Upload a JPG, PNG, GIF, or WEBP design image for the team to inspect."></i>
                </label>
                <input id="departmentArtifactFile" class="form-control form-control-sm" type="file" accept="image/*">
           </div>`
        : '';
    const panelLabel = isUiUx ? 'Client Requirements' : 'Project Inputs';

    return `
        <div class="task-detail-panel department-modal-panel">
            <div class="department-modal-heading">
                <div>
                    <h6><i class="fas ${escapeAttribute(departmentProfile.icon || 'fa-briefcase')} me-2 text-primary"></i>${escapeHtml(departmentProfile.title || 'Department Workbench')}</h6>
                    <div class="department-modal-summary">${escapeHtml(departmentProfile.summary || '')}</div>
                </div>
                <span class="badge bg-light text-primary border">${escapeHtml(currentDepartment || 'Department')}</span>
            </div>

            <div class="small fw-semibold mb-2">
                ${panelLabel}
                <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="${isUiUx ? 'UI/UX should review these requirements before preparing designs and wireframes.' : 'Use these requirements as context for this department without changing the task workflow.'}"></i>
            </div>
            <ul class="department-requirements">${requirementItems}</ul>
            ${additionalRequirements}

            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <div class="task-side-panel mb-0">
                        <div class="task-side-heading">
                            <label class="form-label mb-0 fw-semibold">${escapeHtml(departmentProfile.artifact_cta || 'Save Handoff')}</label>
                            <small class="text-muted">${isUiUx ? 'Design, wireframe, notes' : 'Link, notes'}</small>
                        </div>
                        <div class="mb-2">
                            <input id="departmentArtifactTitle" class="form-control form-control-sm" placeholder="Handoff title">
                        </div>
                        ${uploadInput}
                        <div class="mb-2">
                            <label class="form-label">
                                ${escapeHtml(departmentProfile.link_label || 'Reference link')}
                                <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="${isUiUx ? 'Paste a Figma, FigJam, wireframe, or prototype link.' : 'Paste the reference link this department needs to hand off.'}"></i>
                            </label>
                            <input id="departmentArtifactUrl" class="form-control form-control-sm" placeholder="https://...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">${escapeHtml(departmentProfile.notes_label || 'Notes')}</label>
                            <textarea id="departmentArtifactNotes" class="form-control form-control-sm" rows="3" placeholder="Short handoff notes"></textarea>
                        </div>
                        <button class="btn-primary-modern task-detail-action" onclick="saveDepartmentArtifact(${Number(task.id)})" data-bs-toggle="tooltip" title="Saves department-specific handoff details without moving the task status.">
                            <i class="fas fa-floppy-disk"></i> ${escapeHtml(departmentProfile.artifact_cta || 'Save Handoff')}
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="task-side-panel mb-0">
                        <h6 class="mb-2">Department Handoffs</h6>
                        <div id="departmentArtifactList" class="artifact-list">
                            ${renderDepartmentArtifacts(task.department_artifacts || [])}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function renderDepartmentArtifacts(artifacts) {
    if (!Array.isArray(artifacts) || artifacts.length === 0) {
        return '<p class="text-muted mb-0 small">No department handoffs saved yet.</p>';
    }

    return artifacts.map((artifact) => {
        const fileLink = artifact.file_url
            ? `<a class="btn btn-sm btn-outline-primary" href="${escapeAttribute(artifact.file_url)}" target="_blank" rel="noopener"><i class="fas fa-image me-1"></i>Design File</a>`
            : '';
        const externalLink = artifact.external_url
            ? `<a class="btn btn-sm btn-outline-primary" href="${escapeAttribute(artifact.external_url)}" target="_blank" rel="noopener"><i class="fas fa-link me-1"></i>Reference</a>`
            : '';
        const note = artifact.notes ? `<div class="artifact-note">${escapeHtml(artifact.notes)}</div>` : '';

        return `
            <div class="artifact-item">
                <div class="artifact-item__title">${escapeHtml(artifact.title || 'Department handoff')}</div>
                <div class="artifact-item__meta">${escapeHtml(artifact.department || 'Department')} | ${escapeHtml(artifact.user_name || 'User')} | ${formatDateTime(artifact.created_at)}</div>
                <div class="artifact-item__links">${fileLink}${externalLink}${!fileLink && !externalLink ? '<span class="text-muted small">No links attached</span>' : ''}</div>
                ${note}
            </div>
        `;
    }).join('');
}

function saveDepartmentArtifact(taskId) {
    const formData = new FormData();
    formData.append('task_id', String(taskId));
    formData.append('artifact_type', departmentProfile.artifact_type || 'department_handoff');
    formData.append('title', $('#departmentArtifactTitle').val() || '');
    formData.append('external_url', $('#departmentArtifactUrl').val() || '');
    formData.append('notes', $('#departmentArtifactNotes').val() || '');

    const fileInput = document.getElementById('departmentArtifactFile');
    if (fileInput?.files?.[0]) {
        formData.append('artifact_file', fileInput.files[0]);
    }

    $.ajax({
        url: '../../api/add-department-artifact.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            alert(response.message || 'Department handoff saved.');
            viewTask(taskId);
        },
        error: function(xhr) {
            alert(xhr.responseJSON?.error || 'Unable to save department handoff.');
        }
    });
}

function loadComments(taskId) {
    $.ajax({
        url: '../../api/get-task-comments.php',
        method: 'GET',
        data: { task_id: taskId },
        success: function(response) {
            const comments = typeof response === 'string' ? JSON.parse(response) : response;
            let html = '';
            comments.forEach(comment => {
                html += `
                    <div class="border-bottom mb-2 pb-2">
                        <strong>${escapeHtml(comment.user_name)}</strong> 
                        <small class="text-muted">${new Date(comment.created_at).toLocaleString()}</small>
                        <p class="mb-0 small">${escapeHtml(comment.comment)}</p>
                    </div>
                `;
            });
            $('#commentsList').html(html || '<p class="text-muted">No comments yet</p>');
        }
    });
}

function loadGitUpdates(taskId) {
    $.ajax({
        url: '../../api/get-task-git-updates.php',
        method: 'GET',
        data: { task_id: taskId },
        success: function(response) {
            const updates = typeof response === 'string' ? JSON.parse(response) : response;
            let html = '';
            updates.forEach(update => {
                html += `
                    <div class="git-update-item">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <strong>${escapeHtml(update.branch_name)}</strong>
                                <div class="small text-muted">${escapeHtml(update.user_name || 'Unknown')} | ${formatDateTime(update.created_at)}</div>
                            </div>
                            ${formatGitStatusBadge(update.status)}
                        </div>
                        <div class="small mb-2"><span class="badge bg-light text-dark border">${formatGitAction(update.git_action)}</span></div>
                        <div class="small mb-2">${update.commit_hash ? `<code>${escapeHtml(shortCommit(update.commit_hash))}</code>` : '<span class="text-muted">No commit hash</span>'}</div>
                        ${update.commit_message ? `<div class="small mb-2">${escapeHtml(update.commit_message)}</div>` : ''}
                        ${update.notes ? `<div class="small mb-2 text-muted">${escapeHtml(update.notes)}</div>` : ''}
                        <div class="d-flex gap-2 flex-wrap">
                            ${update.repository_url ? `<a href="${escapeAttribute(update.repository_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Repository</a>` : ''}
                            ${update.pull_request_url ? `<a href="${escapeAttribute(update.pull_request_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Pull Request</a>` : ''}
                        </div>
                    </div>
                `;
            });
            $('#gitUpdatesList').html(html || '<p class="text-muted">No Git updates yet</p>');
        },
        error: function(xhr) {
            const message = xhr.responseJSON?.error || 'Unable to load Git history';
            $('#gitUpdatesList').html(`<p class="text-danger mb-0">${escapeHtml(message)}</p>`);
        }
    });
}

function addComment(taskId) {
    const comment = $('#taskComment').val();
    if(!comment.trim()) return;
    
    $.ajax({
        url: '../../api/add-task-comment.php',
        method: 'POST',
        data: { task_id: taskId, comment: comment },
        success: function() {
            $('#taskComment').val('');
            loadComments(taskId);
            alert('Comment added successfully');
        }
    });
}

function addGitUpdate(taskId) {
    const branchName = $('#gitBranchName').val().trim();

    if(!branchName) {
        alert('Branch name is required for Git updates.');
        return;
    }

    $.ajax({
        url: '../../api/add-task-git-update.php',
        method: 'POST',
        dataType: 'json',
        data: {
            task_id: taskId,
            git_action: $('#gitAction').val(),
            branch_name: branchName,
            commit_hash: $('#gitCommitHash').val().trim(),
            commit_message: $('#gitCommitMessage').val().trim(),
            pull_request_url: $('#gitPullRequestUrl').val().trim(),
            repository_url: $('#gitRepositoryUrl').val().trim(),
            status: $('#gitStatus').val(),
            notes: $('#gitNotes').val().trim()
        },
        success: function(response) {
            const data = typeof response === 'string' ? JSON.parse(response) : response;
            $('#gitCommitHash, #gitCommitMessage, #gitPullRequestUrl, #gitRepositoryUrl, #gitNotes').val('');
            loadGitUpdates(taskId);
            viewTask(taskId);

            if (data.pull_request_submitted || data.task_status === 'for_review') {
                moveKanbanTaskToReview(taskId);
                alert(data.message || 'Pull Request submitted. Task moved to Workflow Review.');
            }
        },
        error: function(xhr) {
            alert(xhr.responseJSON?.error || 'Failed to save Git update');
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(text).replace(/"/g, '&quot;');
}

function shortCommit(hash) {
    return (hash || '').slice(0, 7);
}

function formatDateTime(value) {
    return value ? new Date(value).toLocaleString() : 'Unknown date';
}

function formatGitAction(action) {
    const labels = {
        branch_created: 'Branch Created',
        commit_pushed: 'Commit Pushed',
        pull_request_opened: 'Pull Request Opened',
        merged: 'Merged',
        deployed: 'Deployed',
        note: 'General Note'
    };
    return labels[action] || 'Git Update';
}

function formatGitStatusBadge(status) {
    const labels = {
        active: 'Active',
        in_review: 'In Review',
        merged: 'Merged',
        closed: 'Closed'
    };
    const colors = {
        active: 'success',
        in_review: 'warning text-dark',
        merged: 'primary',
        closed: 'secondary'
    };
    const label = labels[status] || 'Active';
    const color = colors[status] || 'secondary';
    return `<span class="badge bg-${color}">${label}</span>`;
}

// Drag and drop functionality
let draggedItem = null;

document.querySelectorAll('.kanban-card').forEach(card => {
    card.addEventListener('dragstart', handleDragStart);
    card.addEventListener('dragend', handleDragEnd);
});

document.querySelectorAll('.kanban-body').forEach(column => {
    column.addEventListener('dragover', handleDragOver);
    column.addEventListener('drop', handleDrop);
});

function handleDragStart(e) {
    draggedItem = this;
    e.dataTransfer.setData('text/plain', this.dataset.taskId);
}

function handleDragEnd(e) {
    draggedItem = null;
}

function handleDragOver(e) {
    e.preventDefault();
}

function handleDrop(e) {
    e.preventDefault();
    const taskId = e.dataTransfer.getData('text/plain');
    const targetColumn = this.closest('.kanban-column');
    const columnTitle = targetColumn.querySelector('.kanban-header h5 span:first-child').textContent.trim();
    
    let newStatus = '';
    if(columnTitle === 'To Do') newStatus = 'todo';
    else if(columnTitle === 'In Progress') newStatus = 'in_progress';
    else if(columnTitle === 'Workflow Review') {
        alert('Submit a Pull Request from the task Git update form to move this task For Review.');
        return;
    }
    else if(columnTitle === 'Completed') {
        alert('Tasks are completed only after SUL review, QA testing, and Architect merge.');
        return;
    }
    
    if(newStatus) {
        updateTaskStatus(taskId, newStatus);
    }
}

const selectedTaskId = new URLSearchParams(window.location.search).get('id');
initTooltips(document);
if(selectedTaskId) {
    viewTask(selectedTaskId);
}
</script>

<?php include '../../includes/footer.php'; ?>
