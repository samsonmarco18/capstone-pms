<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/department-artifacts.php';
require_once '../../includes/methodology-roadmap.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/task-reopen.php';
requireRole('developer');

$conn = getConnection();
$user = getCurrentUser();
workflowEnsureSchema($conn);
departmentArtifactEnsureSchema($conn);
methodologyRoadmapEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);
taskReopenEnsureSchema($conn);

$userDepartment = trim((string) ($user['department'] ?? ''));
$userDepartmentCanonical = projectWorkflowDepartmentLabel($userDepartment);
$departmentProfile = departmentArtifactProfile($userDepartment);
$isQaDepartmentDeveloper = $userDepartmentCanonical === 'QA';

if (!class_exists('DeveloperTaskQueryResult')) {
    class DeveloperTaskQueryResult
    {
        public int $num_rows = 0;
        private array $rows;
        private int $index = 0;

        public function __construct(array $rows)
        {
            $this->rows = $rows;
            $this->num_rows = count($rows);
        }

        public function fetch_assoc(): ?array
        {
            if ($this->index >= $this->num_rows) {
                return null;
            }

            return $this->rows[$this->index++];
        }
    }
}

function developerTaskQuery(mysqli|PgsqlMysqliCompatConnection $conn, string $sql, string $label): DeveloperTaskQueryResult
{
    $rows = [];
    $result = $conn->query($sql);
    if (!$result) {
        error_log('Developer task board query failed [' . $label . ']: ' . ($conn->error ?? 'unknown database error'));
        return new DeveloperTaskQueryResult([]);
    }

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return new DeveloperTaskQueryResult($rows);
}

$developerTaskAccessSql = "
    (
        t.assigned_to = {$user['id']}
        OR EXISTS (
            SELECT 1
            FROM methodology_tasks mt_access
            WHERE mt_access.task_id = t.id
              AND mt_access.assigned_developer_id = {$user['id']}
        )
        OR EXISTS (
            SELECT 1
            FROM methodology_task_departments mtd_access
            LEFT JOIN methodology_tasks mt_access_department ON mt_access_department.id = mtd_access.methodology_task_id
            WHERE mtd_access.assigned_developer_id = {$user['id']}
              AND (mtd_access.workflow_task_id = t.id OR mt_access_department.task_id = t.id)
        )
    )
";

$taskBaseQuery = "
    SELECT t.*,
           p.name AS project_name,
           latest_git.branch_name AS git_branch_name,
           latest_git.commit_hash AS git_commit_hash,
           latest_git.status AS git_status,
           latest_git.git_action AS git_action,
           latest_git.pull_request_url AS git_pull_request_url,
           (
               SELECT MAX(mt_done.updated_at)
               FROM methodology_tasks mt_done
               WHERE mt_done.task_id = t.id
                 AND mt_done.assigned_developer_id = {$user['id']}
                 AND mt_done.status = 'completed'
           ) AS developer_completed_at
           ,
           (
               SELECT COUNT(*)
               FROM task_reopen_requests rr
               WHERE rr.task_id = t.id
                 AND rr.status IN ('pending_sul', 'pending_architect')
           ) AS active_reopen_request_count,
           (
               SELECT MAX(rr_approved.architect_reviewed_at)
               FROM task_reopen_requests rr_approved
               WHERE rr_approved.task_id = t.id
                 AND rr_approved.status = 'architect_approved'
                 AND rr_approved.requested_by = {$user['id']}
           ) AS last_reopened_at
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

$developerCompletedRoadmapAssignmentSql = "
    (
        EXISTS (
            SELECT 1
            FROM methodology_tasks mt_done_assignment
            WHERE mt_done_assignment.task_id = t.id
              AND mt_done_assignment.assigned_developer_id = {$user['id']}
              AND mt_done_assignment.status = 'completed'
        )
        OR EXISTS (
            SELECT 1
            FROM methodology_task_departments mtd_done_assignment
            LEFT JOIN methodology_tasks mt_done_assignment_parent
              ON mt_done_assignment_parent.id = mtd_done_assignment.methodology_task_id
            WHERE mtd_done_assignment.assigned_developer_id = {$user['id']}
              AND (mtd_done_assignment.workflow_task_id = t.id OR mt_done_assignment_parent.task_id = t.id)
              AND mtd_done_assignment.status = 'completed'
        )
    )
";

$developerActiveRoadmapAssignmentSql = "
    (
        EXISTS (
            SELECT 1
            FROM methodology_tasks mt_active_assignment
            WHERE mt_active_assignment.task_id = t.id
              AND mt_active_assignment.assigned_developer_id = {$user['id']}
              AND mt_active_assignment.status <> 'completed'
        )
        OR EXISTS (
            SELECT 1
            FROM methodology_task_departments mtd_active_assignment
            LEFT JOIN methodology_tasks mt_active_assignment_parent
              ON mt_active_assignment_parent.id = mtd_active_assignment.methodology_task_id
            WHERE mtd_active_assignment.assigned_developer_id = {$user['id']}
              AND (
                  mtd_active_assignment.workflow_task_id = t.id
                  OR mt_active_assignment_parent.task_id = t.id
              )
              AND mtd_active_assignment.status <> 'completed'
        )
    )
";

$developerCurrentTaskOwnershipSql = "
    (
        t.assigned_to = {$user['id']}
        OR {$developerActiveRoadmapAssignmentSql}
    )
";

// Check if project_phases column exists
$hasProjectPhases = dbColumnExists($conn, 'projects', 'project_phases');

// Get tasks assigned to this developer
$todoTasks = developerTaskQuery(
    $conn,
    $taskBaseQuery . "
        WHERE {$developerTaskAccessSql}
          AND t.status = 'todo'
          AND {$developerCurrentTaskOwnershipSql}
        ORDER BY t.deadline ASC
    ",
    'todo'
);
$inProgressTasks = developerTaskQuery($conn, $taskBaseQuery . "
    WHERE {$developerTaskAccessSql}
      AND {$developerCurrentTaskOwnershipSql}
      AND (
          t.status IN ('in_progress', 'qa_failed')
          OR (
              t.status = 'sul_revision'
              AND (
                  NOT EXISTS (
                      SELECT 1
                      FROM task_reopen_requests rr_any_approved
                      WHERE rr_any_approved.task_id = t.id
                        AND rr_any_approved.status = 'architect_approved'
                  )
                  OR EXISTS (
                      SELECT 1
                      FROM task_reopen_requests rr_mine_approved
                      WHERE rr_mine_approved.task_id = t.id
                        AND rr_mine_approved.status = 'architect_approved'
                        AND rr_mine_approved.requested_by = {$user['id']}
                  )
              )
          )
      )
    ORDER BY last_reopened_at DESC, t.updated_at DESC, t.deadline ASC
", 'in_progress');
$reviewTasks = developerTaskQuery($conn, $taskBaseQuery . "
    WHERE {$developerTaskAccessSql}
      AND {$developerCurrentTaskOwnershipSql}
      AND t.status IN ('for_review', 'qa_testing', 'qa_passed', 'architect_review', 'merged', 'ready_for_release')
      AND NOT {$developerCompletedRoadmapAssignmentSql}
    ORDER BY t.deadline ASC
", 'review');
$completedTasks = developerTaskQuery($conn, $taskBaseQuery . "
    WHERE (
          (t.assigned_to = {$user['id']} AND t.status = 'completed')
          OR EXISTS (
              SELECT 1
              FROM methodology_tasks mt_done
              WHERE mt_done.task_id = t.id
                AND mt_done.assigned_developer_id = {$user['id']}
                AND mt_done.status = 'completed'
                AND NOT {$developerActiveRoadmapAssignmentSql}
                AND NOT (
                    t.status IN ('todo', 'in_progress', 'sul_revision', 'qa_failed', 'blocked')
                    AND COALESCE(t.assigned_to, 0) = {$user['id']}
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM task_reopen_requests rr_mine_done
                    WHERE rr_mine_done.task_id = t.id
                      AND rr_mine_done.status = 'architect_approved'
                      AND rr_mine_done.requested_by = {$user['id']}
                )
          )
          OR EXISTS (
              SELECT 1
              FROM methodology_task_departments mtd_done
              LEFT JOIN methodology_tasks mt_done_department ON mt_done_department.id = mtd_done.methodology_task_id
              WHERE mtd_done.assigned_developer_id = {$user['id']}
                AND (mtd_done.workflow_task_id = t.id OR mt_done_department.task_id = t.id)
                AND mtd_done.status = 'completed'
                AND NOT {$developerActiveRoadmapAssignmentSql}
                AND NOT (
                    t.status IN ('todo', 'in_progress', 'sul_revision', 'qa_failed', 'blocked')
                    AND COALESCE(t.assigned_to, 0) = {$user['id']}
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM task_reopen_requests rr_mine_department_done
                    WHERE rr_mine_department_done.task_id = t.id
                      AND rr_mine_department_done.status = 'architect_approved'
                      AND rr_mine_department_done.requested_by = {$user['id']}
                )
          )
      )
    ORDER BY COALESCE(t.completed_at, t.updated_at) DESC
    LIMIT 10
", 'completed');

$taskBoardProjects = [];
$taskBoardProjectsResult = $conn->query("
    SELECT DISTINCT p.id, p.name
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
    WHERE {$developerTaskAccessSql}
    ORDER BY p.name ASC
");
if ($taskBoardProjectsResult && $taskBoardProjectsResult->num_rows > 0) {
    while ($taskBoardProject = $taskBoardProjectsResult->fetch_assoc()) {
        $taskBoardProjects[] = $taskBoardProject;
    }
}

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
            'project_id' => (int) $pid,
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
    if (in_array($userDepartmentCanonical, ['UI/UX', 'QA'], true)) {
        $allowedStatuses[] = 'for_review';
    }

    if ($taskId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        $_SESSION['error'] = "Invalid task status update.";
        header('Location: task.php');
        exit();
    }

    $taskCheck = $conn->prepare("
        SELECT t.id, t.title, t.status, t.assigned_by, t.project_id, p.name AS project_name, p.sul_id
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

    if ($newStatus === 'for_review' && !in_array($userDepartmentCanonical, ['UI/UX', 'QA'], true)) {
        $_SESSION['error'] = "Submit a Pull Request from the Git update form to request SUL review.";
        header('Location: task.php');
        exit();
    }
    if (
        $newStatus === 'for_review'
        && $userDepartmentCanonical === 'QA'
        && !in_array((string) ($task['status'] ?? ''), ['in_progress', 'sul_revision', 'qa_failed'], true)
    ) {
        $_SESSION['error'] = "Start testing before submitting QA test results to the QA SUL.";
        header('Location: task.php');
        exit();
    }

    if ($newStatus === 'in_progress') {
        $roadmapBlockReason = methodologyRoadmapStartBlockReason($conn, $taskId, (int) $user['id']);
        if ($roadmapBlockReason !== null) {
            $_SESSION['error'] = $roadmapBlockReason;
            header('Location: task.php');
            exit();
        }
    }
    
    $stmt = $conn->prepare("UPDATE tasks SET status = ?, completed_at = NULL WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $taskId);
    if (!$stmt->execute()) {
        $stmt->close();
        $_SESSION['error'] = "Task status could not be updated. Please try again.";
        header('Location: task.php');
        exit();
    }
    $stmt->close();

    projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));
    
    workflowLogStatusChange(
        (int) $user['id'],
        $taskId,
        (string) ($task['status'] ?? ''),
        $newStatus,
        $newStatus === 'for_review'
            ? ($userDepartmentCanonical === 'QA' ? 'qa_submit_test_results' : 'uiux_submit_design')
            : 'developer_update_task_status'
    );
    if ($newStatus === 'for_review') {
        $isQaSubmission = $userDepartmentCanonical === 'QA';
        $submissionLabel = $userDepartmentCanonical === 'QA'
            ? 'QA Developer submitted test results for QA SUL review.'
            : 'UI/UX submitted design work for SUL review.';
        workflowAddTaskComment($conn, $taskId, (int) $user['id'], $submissionLabel);
        $reviewActionUrl = BASE_URL . 'modules/sul/task-allocation.php?project_id=' . (int) ($task['project_id'] ?? 0) . '&task_id=' . $taskId . '#developer-review-panel';
        $reviewerIds = array_values(array_filter([(int) ($task['assigned_by'] ?? 0)]));
        if ($reviewerIds === [] && (int) ($task['sul_id'] ?? 0) > 0) {
            $reviewerIds[] = (int) $task['sul_id'];
        }
        if ($userDepartmentCanonical !== '') {
            $departmentSulWhere = projectWorkflowUserDepartmentClause($conn, 'u', $userDepartmentCanonical);
            $departmentSulRows = $conn->query("SELECT u.id FROM users u WHERE u.role = 'sul' AND u.status = 'active' AND {$departmentSulWhere}");
            if ($departmentSulRows) {
                while ($departmentSul = $departmentSulRows->fetch_assoc()) {
                    $reviewerIds[] = (int) ($departmentSul['id'] ?? 0);
                }
            }
        }
        foreach (array_unique(array_filter($reviewerIds)) as $reviewerId) {
            workflowNotifyUser(
                (int) $reviewerId,
                ($isQaSubmission ? 'QA Test Results Submitted: ' : 'Design Submitted for Review: ') . $task['title'],
                $isQaSubmission
                    ? 'A QA Developer submitted testing notes and evidence for QA SUL review.'
                    : 'UI/UX submitted design files or references for review.',
                $reviewActionUrl,
                $taskId
            );
        }
    }
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

.task-workspace-panel {
    background: #fff;
    border: 1px solid #dbe5ec;
    border-radius: 16px;
    overflow: hidden;
}

.task-board-toolbar {
    align-items: center;
    background: #fff;
    border: 0;
    border-bottom: 1px solid #e0e8ee;
    border-radius: 0;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    margin: 0;
    padding: .82rem 1rem;
}

.task-board-toolbar__title {
    align-items: center;
    display: flex;
    gap: .65rem;
    min-width: 0;
}

.task-board-toolbar__icon {
    align-items: center;
    background: #eaf4fa;
    border-radius: 10px;
    color: #1f6392;
    display: inline-flex;
    flex: 0 0 38px;
    height: 38px;
    justify-content: center;
}

.task-board-toolbar__copy {
    display: grid;
    gap: .08rem;
}

.task-board-toolbar__copy strong {
    color: #19324a;
    font-size: .88rem;
    font-weight: 650;
}

.task-board-toolbar__copy small {
    color: #718296;
    font-size: .68rem;
}

.task-board-toolbar__controls {
    align-items: center;
    display: flex;
    gap: .7rem;
}

.task-project-filter {
    align-items: center;
    border-bottom: 1px solid #b9ccda;
    display: flex;
    gap: .45rem;
    min-width: 270px;
    padding: 0 .1rem;
}

.task-project-filter i {
    color: #648198;
    font-size: .76rem;
}

.task-project-filter select {
    appearance: none;
    background: transparent;
    border: 0;
    color: #23384b;
    cursor: pointer;
    font-size: .76rem;
    min-height: 38px;
    outline: 0;
    padding: .25rem 1.6rem .25rem .15rem;
    width: 100%;
}

.task-context-toggle {
    align-items: center;
    background: transparent;
    border: 0;
    color: #1f6392;
    display: inline-flex;
    font-size: .7rem;
    font-weight: 650;
    gap: .35rem;
    padding: .42rem .2rem;
    white-space: nowrap;
}

.task-context-toggle:hover {
    color: #124b70;
}

.task-context-toggle__chevron {
    font-size: .62rem;
    margin-left: .08rem;
    transition: transform .2s ease;
}

.task-context-toggle[aria-expanded="true"] .task-context-toggle__chevron {
    transform: rotate(180deg);
}

.task-project-context {
    border-bottom: 1px solid #e0e8ee;
}

.task-project-context__inner {
    background: #fbfdff;
    padding: 1.05rem 1.15rem;
}

.task-project-context__overview {
    align-items: center;
    display: flex;
    gap: .8rem;
    min-height: 54px;
}

.task-project-context__overview[hidden] {
    display: none;
}

.task-project-context__overview-icon {
    align-items: center;
    background: #eaf4fa;
    border-radius: 10px;
    color: #1f6392;
    display: inline-flex;
    flex: 0 0 40px;
    height: 40px;
    justify-content: center;
}

.task-project-context__overview-copy {
    display: grid;
    gap: .12rem;
    min-width: 0;
}

.task-project-context__overview-copy strong {
    color: #19324a;
    font-size: .84rem;
    font-weight: 650;
}

.task-project-context__overview-copy span {
    color: #718296;
    font-size: .72rem;
}

.task-project-context__overview-stats {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
    margin-left: auto;
}

.task-project-context__overview-stat {
    align-items: center;
    color: #526b80;
    display: inline-flex;
    font-size: .68rem;
    gap: .32rem;
    white-space: nowrap;
}

.task-project-context__overview-stat i {
    color: #1f6392;
}

.task-project-context__project {
    align-items: start;
    display: grid;
    gap: 1.5rem;
    grid-template-columns: minmax(230px, .72fr) minmax(0, 1.6fr) auto;
}

.task-project-context__project[hidden] {
    display: none;
}

.task-project-context__identity,
.task-project-context__requirements {
    display: grid;
    gap: .35rem;
    min-width: 0;
}

.task-project-context__eyebrow,
.task-project-context__label {
    color: #60758a;
    font-size: .64rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.task-project-context__identity strong {
    color: #19324a;
    font-size: .94rem;
    font-weight: 650;
    line-height: 1.35;
}

.task-project-context__meta,
.task-project-context__empty {
    color: #718296;
    font-size: .75rem;
    line-height: 1.45;
    margin: 0;
}

.task-project-context__meta {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
}

.task-project-context__meta i {
    color: #1f6392;
}

.task-project-context__requirement-list {
    display: grid;
    gap: .42rem 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    list-style: none;
    margin: 0;
    padding: 0;
}

.task-project-context__requirement-list li {
    align-items: flex-start;
    color: #425d73;
    display: flex;
    font-size: .75rem;
    gap: .42rem;
    line-height: 1.4;
    min-width: 0;
}

.task-project-context__requirement-list i {
    color: #238b63;
    flex: 0 0 auto;
    font-size: .68rem;
    margin-top: .18rem;
}

.task-project-context__requirements-empty {
    color: #718296;
    font-size: .75rem;
    margin: 0;
}

.task-project-context__actions {
    align-items: center;
    display: flex;
    gap: .4rem;
}

.task-project-context__actions a,
.task-project-context__actions button {
    align-items: center;
    background: transparent;
    border: 1px solid #cfe0eb;
    border-radius: 8px;
    color: #1f6392;
    display: inline-flex;
    font-size: .72rem;
    font-weight: 600;
    gap: .3rem;
    padding: .48rem .62rem;
    text-decoration: none;
}

.task-project-context__actions a:hover,
.task-project-context__actions button:hover {
    background: #edf6fb;
    color: #124b70;
}

.task-board-grid {
    align-items: start !important;
    display: grid !important;
    gap: .9rem;
    grid-auto-rows: max-content;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin: 0;
    padding: 1rem;
}

.task-board-lane {
    align-self: start;
    height: max-content;
    min-width: 0;
    width: 100%;
}

/* Kanban Columns */
.kanban-column {
    background: #f7f9fb;
    border-radius: 14px;
    height: max-content !important;
    min-height: 0 !important;
    min-width: 0 !important;
    padding: 0 !important;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    width: 100%;
}

.kanban-column[data-board-status="todo"] {
    background: #f5f8fb;
    border-color: #dce6ee;
}

.kanban-column[data-board-status="in_progress"] {
    background: #f1f7fc;
    border-color: #d5e6f2;
}

.kanban-column[data-board-status="review"] {
    background: #fff9ed;
    border-color: #f1e2bf;
}

.kanban-column[data-board-status="completed"] {
    background: #f2faf6;
    border-color: #d4e9dc;
}

.kanban-header {
    padding: .9rem 1rem .75rem;
    background: transparent;
    border: 0;
    border-bottom: 1px solid rgba(112, 137, 158, .14);
}

.kanban-header h5 {
    font-size: 0.82rem;
    font-weight: 650;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.kanban-header .badge {
    background: #e7edf2 !important;
    color: #52677a !important;
    font-size: .65rem;
    font-weight: 650;
    min-width: 25px;
    padding: .34rem .46rem;
}

.kanban-body {
    height: auto;
    min-height: 124px !important;
    overflow: visible;
    padding: .7rem .85rem .85rem;
}

/* Kanban Cards */
.kanban-card {
    background: white;
    border-radius: 12px;
    padding: .82rem .85rem 0;
    margin: .58rem 0 .78rem;
    cursor: grab;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    border: 1px solid var(--gray-200);
    position: relative;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.035);
}

.kanban-card::before,
.completed-card::before {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-bottom: 0;
    border-radius: 7px 7px 0 0;
    content: "";
    height: 10px;
    left: 10px;
    position: absolute;
    top: -9px;
    transition: border-color .18s ease;
    width: 58px;
    z-index: 1;
}

.kanban-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(15, 43, 64, .09);
    border-color: #9bc4dd;
}

.kanban-card:hover::before,
.completed-card:hover::before {
    border-color: #9bc4dd;
}

.kanban-card:active {
    cursor: grabbing;
}

.kanban-card.reopened-card {
    border-color: #f59e0b;
    background: #fff7ed;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
}

.kanban-card.reopened-card::before {
    background: #fff7ed;
    border-color: #f59e0b;
}

.reopen-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    width: fit-content;
    padding: 0.22rem 0.5rem;
    border-radius: 999px;
    background: #fef3c7;
    color: #92400e;
    font-size: 0.65rem;
    font-weight: 700;
    margin-bottom: 0.6rem;
}

.revision-indicator {
    align-items: center;
    background: #fee2e2;
    border-radius: 999px;
    color: #b42318;
    display: inline-flex;
    font-size: .65rem;
    font-weight: 700;
    gap: .25rem;
    margin-bottom: .6rem;
    padding: .22rem .5rem;
    width: fit-content;
}

.kanban-card-title {
    color: var(--gray-900);
    font-size: .86rem;
    font-weight: 650;
    line-height: 1.35;
    margin: 0 0 .35rem;
    overflow-wrap: anywhere;
}

.kanban-card-description {
    color: #58708a;
    display: -webkit-box;
    font-size: .72rem;
    line-height: 1.45;
    margin-bottom: .6rem;
    overflow: hidden;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.kanban-card-meta {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .42rem .7rem;
    margin: .15rem 0 .7rem;
}

.kanban-card-project {
    align-items: center;
    color: #60758a;
    display: flex;
    font-size: .68rem;
    gap: .32rem;
    margin: 0;
    min-width: 0;
}

.kanban-card-project span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.kanban-card-footer {
    border-top: 1px solid #e5ebf0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    margin: 0 -.85rem;
    min-height: 43px;
    padding: .45rem .85rem;
}

.kanban-card-due {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    color: #5f7690;
    font-size: .68rem;
    line-height: 1.35;
}

.kanban-card-actions {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: .3rem;
    justify-content: flex-end;
    min-width: 0;
    width: 100%;
}

.kanban-card-actions .btn-action,
.kanban-card-actions .btn-action-view {
    flex: 0 0 auto;
    font-size: .68rem;
    padding: .34rem .46rem;
    white-space: nowrap;
}

/* In-progress cards have three actions. Give the primary submission action
   its own row so labels never clip inside the narrow kanban lane. */
.kanban-card-actions:has(> :nth-child(3)) {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: .38rem;
}

.kanban-card-actions:has(> :nth-child(3)) > :first-child {
    grid-column: 1 / -1;
    justify-content: center;
    width: 100%;
}

.kanban-card-actions:has(> :nth-child(3)) > :nth-child(2) {
    justify-content: center;
    min-width: 0;
    width: 100%;
}

.task-board-item[hidden] {
    display: none !important;
}

.task-board-item.is-task-collapsed {
    display: none !important;
}

.task-column-more {
    align-items: center;
    background: transparent;
    border: 0;
    border-top: 1px solid #dbe5ec;
    color: #1f6392;
    display: flex;
    font-size: .68rem;
    font-weight: 650;
    gap: .35rem;
    justify-content: center;
    margin-top: .2rem;
    padding: .7rem .25rem .15rem;
    width: 100%;
}

.task-column-more:hover {
    color: #124b70;
}

.task-filter-empty {
    border: 0;
    border-radius: 0;
    margin-top: .15rem;
}

.kanban-body > .empty-state-mini:only-child {
    align-items: center;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 102px;
    padding: 1rem .65rem;
}

/* Buttons */
.btn-action {
    background: white;
    border: 1px solid var(--gray-200);
    padding: 0.34rem 0.58rem;
    border-radius: 6px;
    font-size: 0.72rem;
    line-height: 1;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.28rem;
    min-height: 28px;
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
    padding: 0.34rem 0.58rem;
    border-radius: 6px;
    font-size: 0.72rem;
    line-height: 1;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.28rem;
    min-width: 32px;
    min-height: 28px;
}

.btn-action-view:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-action-reopen {
    background: rgba(31, 99, 146, 0.08);
    border-color: rgba(31, 99, 146, 0.2);
    color: var(--theme-primary);
    font-weight: 700;
}

.btn-action-reopen:hover {
    background: var(--theme-primary);
    border-color: var(--theme-primary);
    color: #fff;
}

.btn-primary-modern,
.btn-outline-modern,
.btn-reopen-modern {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    border-radius: 8px;
    border: 1px solid transparent;
    padding: 0.58rem 0.9rem;
    font-size: 0.82rem;
    font-weight: 700;
    line-height: 1.2;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease, color 0.2s ease;
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: #fff;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.24);
    color: #fff;
}

.btn-primary-modern:disabled {
    cursor: wait;
    opacity: 0.72;
    transform: none;
    box-shadow: none;
}

.btn-outline-modern {
    background: #fff;
    border-color: var(--gray-300);
    color: var(--gray-700);
}

.btn-outline-modern:hover {
    background: var(--gray-50);
    border-color: var(--theme-primary);
    color: var(--theme-primary);
}

.btn-reopen-modern {
    width: 100%;
    background: #fff;
    border-color: rgba(31, 99, 146, 0.22);
    color: var(--theme-primary);
}

.btn-reopen-modern:hover {
    background: var(--theme-primary);
    border-color: var(--theme-primary);
    color: #fff;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.18);
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
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: .82rem .85rem 0;
    margin: .55rem 0 .75rem;
    box-shadow: 0 4px 12px rgba(15, 23, 42, .035);
    position: relative;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.completed-card:hover {
    border-color: #9bc4dd;
    box-shadow: 0 8px 20px rgba(15, 43, 64, .09);
    transform: translateY(-1px);
}

.completed-card-title {
    display: block;
    color: var(--gray-800);
    font-size: 0.82rem;
    font-weight: 650;
    line-height: 1.35;
    overflow-wrap: anywhere;
    margin-bottom: .45rem;
}

.completed-card-date {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    color: #6b7f95;
    font-size: 0.72rem;
}

.completed-card-actions {
    border-top: 1px solid #e5ebf0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin: .7rem -.85rem 0;
    min-height: 43px;
    padding: .45rem .85rem;
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
    background: #fff;
    border: 1px solid #dbe5ec;
    border-radius: 12px;
    margin-bottom: 1rem;
    overflow: hidden;
    padding: 1.1rem 1.2rem 0;
}

.task-detail-sheet {
    background: #fff;
    border: 1px solid #dbe5ec;
    border-radius: 12px;
    margin-bottom: 1rem;
    overflow: hidden;
}

.task-detail-sheet > .task-detail-hero {
    border: 0;
    border-radius: 0;
    margin-bottom: 0;
}

.task-detail-section {
    border-top: 1px solid #e1e8ee;
    margin: 0;
    padding: 1.1rem 1.2rem 1.2rem;
}

.task-detail-modal .modal-dialog {
    max-width: min(1180px, calc(100vw - 2rem));
}

.task-detail-modal .modal-content {
    border: 0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22);
}

.task-detail-modal .modal-header {
    align-items: center;
    background: #155f85 !important;
    border-bottom: 0;
    padding: 1rem 1.2rem;
}

.task-detail-modal .modal-title,
.task-detail-modal .modal-title i {
    color: #fff !important;
    font-size: 1rem;
    font-weight: 650;
}

.task-detail-modal .btn-close {
    filter: invert(1) grayscale(100%) brightness(200%);
    opacity: 1;
}

.task-detail-modal .modal-body {
    background: #f8fafc;
    padding: .9rem 1rem 1rem;
}

.pull-request-modal .modal-dialog {
    max-width: min(760px, calc(100vw - 2rem));
}

.pull-request-modal .modal-content {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24);
    overflow: hidden;
}

.pull-request-modal .modal-header {
    align-items: center;
    background: #155f85 !important;
    border: 0;
    padding: 1rem 1.25rem;
}

.pull-request-modal .modal-title,
.pull-request-modal .modal-title i {
    color: #fff !important;
    font-size: 1.02rem;
    font-weight: 650;
}

.pull-request-modal__eyebrow {
    color: rgba(255, 255, 255, .72);
    display: block;
    font-size: .64rem;
    font-weight: 650;
    letter-spacing: .08em;
    margin-bottom: .18rem;
    text-transform: uppercase;
}

.pull-request-modal .btn-close {
    filter: invert(1) grayscale(100%) brightness(200%);
    opacity: .9;
}

.pull-request-modal .modal-body {
    background: #f8fafc;
    padding: 1.25rem;
}

.pr-task-context {
    align-items: center;
    display: flex;
    gap: .85rem;
    margin-bottom: 1rem;
}

.pr-task-context__icon {
    align-items: center;
    background: #e9f4fa;
    border-radius: 10px;
    color: #126b98;
    display: inline-flex;
    flex: 0 0 44px;
    font-size: 1rem;
    height: 44px;
    justify-content: center;
}

.pr-task-context__copy {
    min-width: 0;
}

.pr-task-context__copy h6 {
    color: var(--theme-dark);
    font-size: .98rem;
    font-weight: 650;
    margin: 0 0 .16rem;
}

.pr-task-context__copy p {
    color: #718296;
    font-size: .76rem;
    margin: 0;
}

.pr-form-sheet {
    background: #fff;
    border: 1px solid #dbe5ec;
    border-radius: 12px;
    overflow: hidden;
}

.pr-form-body {
    padding: 1.1rem 1.15rem;
}

.pr-form-intro {
    border-bottom: 1px solid #e1e8ee;
    margin-bottom: 1rem;
    padding-bottom: .8rem;
}

.pr-form-intro strong {
    color: var(--theme-dark);
    display: block;
    font-size: .88rem;
    font-weight: 650;
    margin-bottom: .18rem;
}

.pr-form-intro span {
    color: #718296;
    font-size: .74rem;
}

.pr-handoff-section {
    border-bottom: 1px solid #e1e8ee;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
}

.pr-handoff-section__heading {
    align-items: flex-start;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    margin-bottom: .85rem;
}

.pr-handoff-section__heading strong,
.pr-section-divider strong {
    color: var(--theme-dark);
    display: block;
    font-size: .82rem;
    font-weight: 650;
}

.pr-handoff-section__heading > div > span,
.pr-section-divider span {
    color: #718296;
    display: block;
    font-size: .7rem;
    margin-top: .12rem;
}

.pr-handoff-section__heading > span {
    color: #718296;
    flex: 0 0 auto;
    font-size: .68rem;
}

.pr-section-divider {
    border-top: 1px solid #e1e8ee;
    margin: 1rem 0 .85rem;
    padding-top: 1rem;
}

.pr-save-handoff {
    align-items: center;
    border-radius: 8px;
    display: inline-flex;
    font-size: .76rem;
    font-weight: 600;
    gap: .4rem;
    padding: .52rem .78rem;
}

.pr-form-sheet .form-label {
    color: #52687c;
    font-size: .69rem;
    font-weight: 650;
    letter-spacing: .035em;
    margin-bottom: .35rem;
    text-transform: uppercase;
}

.pr-form-sheet .form-control {
    border-color: #d5e0e8;
    border-radius: 8px;
    color: var(--theme-dark);
    font-size: .82rem;
    min-height: 42px;
}

.pr-form-sheet textarea.form-control {
    min-height: 76px;
    resize: vertical;
}

.pr-form-sheet .form-control:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 .18rem rgba(31, 99, 146, .13);
}

.pr-field-help {
    color: #7b8da0;
    display: block;
    font-size: .68rem;
    margin-top: .3rem;
}

.pr-form-footer {
    align-items: center;
    background: #f9fbfc;
    border-top: 1px solid #e1e8ee;
    display: flex;
    gap: .65rem;
    justify-content: space-between;
    padding: .85rem 1.15rem;
}

.pr-form-footer__notice {
    align-items: center;
    color: #63788c;
    display: flex;
    font-size: .7rem;
    gap: .4rem;
}

.pr-form-footer__actions {
    display: flex;
    gap: .55rem;
}

.pr-form-footer .btn {
    align-items: center;
    border-radius: 8px;
    display: inline-flex;
    font-size: .78rem;
    font-weight: 600;
    gap: .4rem;
    justify-content: center;
    min-height: 38px;
    padding: .55rem .85rem;
    width: auto;
}

.pr-form-footer .btn-primary {
    background: #155f85;
    border-color: #155f85;
}

.pr-form-footer .btn-primary:hover {
    background: #0f4e70;
    border-color: #0f4e70;
}

.task-detail-title {
    font-size: 1.12rem;
    font-weight: 650;
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
    border-top: 1px solid #e1e8ee;
    margin: 1rem -1.2rem 0;
}

.task-detail-panel,
.task-side-panel {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
}

.task-fact {
    border-right: 1px solid #e1e8ee;
    padding: .72rem 1.2rem .82rem;
}

.task-fact:last-child {
    border-right: 0;
}

.task-fact-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    font-weight: 700;
    color: var(--gray-500);
    margin-bottom: 0.3rem;
}

.task-fact-value {
    font-size: .82rem;
    font-weight: 650;
    color: var(--theme-dark);
}

.task-detail-panel {
    background: #fff;
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
}

.task-comments-panel {
    padding: 0;
}

.task-comments-panel--bottom {
    margin-top: 1rem;
}

.task-comments-heading {
    align-items: center;
    border-bottom: 1px solid #e1e8ee;
    display: flex;
    justify-content: space-between;
    padding: .85rem 1rem;
}

.task-comments-heading h6 {
    margin: 0;
}

.task-comments-heading span {
    color: #718296;
    font-size: .68rem;
}

.task-comment-compose {
    padding: .85rem 1rem;
}

.task-comment-compose .form-control {
    min-height: 72px;
    resize: vertical;
}

.task-comment-compose__footer {
    align-items: center;
    display: flex;
    gap: .75rem;
    justify-content: space-between;
    margin-top: .65rem;
}

.task-comment-compose__footer small {
    color: #718296;
    font-size: .67rem;
}

.task-comment-compose__footer .task-detail-action {
    flex: 0 0 auto;
    padding: .55rem .8rem;
    width: auto;
}

.task-comments-list {
    border-bottom: 1px solid #e1e8ee;
    min-height: 58px;
    padding: .85rem 1rem 1rem;
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

.department-handoff-actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .65rem;
    justify-content: flex-end;
}

.department-handoff-actions .task-detail-action {
    flex: 0 0 auto;
    min-width: 10.5rem;
    width: auto;
}

.department-modal-panel .task-side-panel--flat {
    border: 0;
    border-radius: 0;
    box-shadow: none;
    padding: 0;
}

.department-handoff-grid > .col-md-6 + .col-md-6 {
    border-left: 1px solid #e1e8ee;
    padding-left: 1.25rem;
}

.task-reopen-callout {
    align-items: center;
    background: #fff;
    border: 1px solid #dbe5ec;
    border-radius: 12px;
    display: flex;
    gap: .85rem;
    margin-bottom: 1rem;
    padding: .78rem .9rem;
}

.task-reopen-callout__icon {
    align-items: center;
    background: rgba(31, 99, 146, .09);
    border-radius: 10px;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 2.35rem;
    height: 2.35rem;
    justify-content: center;
}

.task-reopen-callout__copy {
    flex: 1 1 auto;
    min-width: 0;
}

.task-reopen-callout__title {
    color: var(--theme-dark);
    font-size: .8rem;
    font-weight: 650;
    margin-bottom: .12rem;
}

.task-reopen-callout__copy p {
    color: #718296;
    font-size: .72rem;
    line-height: 1.45;
    margin: 0;
}

.task-reopen-callout .btn-reopen-modern {
    flex: 0 0 auto;
    white-space: nowrap;
    width: auto;
}

.task-reopen-callout--pending .task-reopen-callout__icon {
    background: rgba(245, 158, 11, .12);
    color: #b45309;
}

.task-reopen-callout__status {
    background: rgba(245, 158, 11, .12);
    border-radius: 999px;
    color: #9a5a00;
    flex: 0 0 auto;
    font-size: .67rem;
    font-weight: 650;
    padding: .35rem .62rem;
    white-space: nowrap;
}

.git-activity-list {
    display: grid;
}

.git-activity-row {
    align-items: center;
    border-bottom: 1px solid #dbe5ec;
    display: grid;
    gap: .8rem;
    grid-template-columns: 38px minmax(0, 1fr) auto;
    min-height: 72px;
    padding: .78rem 1.1rem;
}

.git-activity-row:last-child {
    border-bottom: 0;
}

.git-activity-row--latest {
    background: #f5faff;
}

.git-activity-icon {
    align-items: center;
    background: #eaf4fb;
    border-radius: 9px;
    color: #176c99;
    display: inline-flex;
    font-size: .82rem;
    height: 34px;
    justify-content: center;
    width: 34px;
}

.git-activity-content {
    min-width: 0;
}

.git-activity-title {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .42rem;
}

.git-activity-branch {
    color: #17324d;
    font-size: .82rem;
    font-weight: 650;
}

.git-activity-latest {
    background: #e4f2fb;
    border-radius: 999px;
    color: #176c99;
    font-size: .61rem;
    font-weight: 650;
    padding: .2rem .42rem;
}

.git-activity-meta,
.git-activity-detail {
    color: #72869a;
    font-size: .7rem;
    line-height: 1.45;
}

.git-activity-meta {
    margin-top: .18rem;
}

.git-activity-detail {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .35rem .62rem;
    margin-top: .22rem;
}

.git-activity-detail code {
    background: #eef3f7;
    border-radius: 4px;
    color: #35546f;
    font-size: .66rem;
    padding: .12rem .3rem;
}

.git-activity-note {
    color: #526b82;
    overflow-wrap: anywhere;
}

.git-activity-actions {
    align-items: center;
    display: flex;
    gap: .35rem;
    justify-content: flex-end;
}

.git-activity-action {
    align-items: center;
    border: 1px solid #cddde8;
    border-radius: 7px;
    color: #176c99;
    display: inline-flex;
    font-size: .68rem;
    font-weight: 600;
    gap: .35rem;
    padding: .4rem .55rem;
    text-decoration: none;
    white-space: nowrap;
}

.git-activity-action:hover {
    background: #edf7fd;
    border-color: #9ec8e0;
    color: #0f5b84;
}

.git-activity-empty {
    color: #71869a;
    font-size: .78rem;
    margin: 0;
    padding: 1rem 1.1rem;
}

.department-modal-panel {
    background: transparent;
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

#reopenRequestModal .modal-dialog {
    max-width: min(560px, calc(100vw - 2rem));
}

#reopenRequestModal .modal-content {
    border: 0;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22);
}

#reopenRequestModal .modal-header {
    align-items: center;
    border-bottom: 0;
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    padding: 1rem 1.25rem;
}

#reopenRequestModal .modal-title,
#reopenRequestModal .modal-title i {
    color: #fff;
    font-weight: 750;
}

#reopenRequestModal .btn-close {
    filter: invert(1) grayscale(100%) brightness(200%);
    opacity: 0.9;
}

#reopenRequestModal .modal-body {
    background: #fff;
    padding: 1.25rem;
}

#reopenRequestModal .modal-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
    border-top: 1px solid var(--gray-200);
    background: #f8fafc;
    padding: 1rem 1.25rem;
}

#reopenRequestModal .modal-footer > * {
    margin: 0;
}

#reopenRequestModal .form-label {
    color: var(--theme-dark);
    font-size: 0.82rem;
    font-weight: 700;
}

#reopenRequestModal .form-control,
#reopenRequestModal .form-select {
    border-color: var(--gray-300);
    border-radius: 8px;
    font-size: 0.9rem;
}

#reopenRequestModal .form-control:focus,
#reopenRequestModal .form-select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 0.18rem rgba(31, 99, 146, 0.16);
}

#reopenReason {
    min-height: 120px;
}

.reopen-task-summary {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: #f8fbfd;
    padding: 0.75rem 0.85rem;
    margin-bottom: 1rem;
}

.reopen-task-summary i {
    color: var(--theme-primary);
    margin-top: 0.15rem;
}

.reopen-task-summary span {
    display: block;
    color: var(--gray-500);
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
}

.reopen-task-summary strong {
    display: block;
    color: var(--gray-900);
    font-size: 0.92rem;
    overflow-wrap: anywhere;
}

.reopen-modal-help {
    display: flex;
    align-items: flex-start;
    gap: 0.45rem;
    margin-top: 0.45rem;
    color: #607893;
    font-size: 0.78rem;
    line-height: 1.45;
}

.reopen-modal-help i {
    color: var(--theme-primary);
    margin-top: 0.15rem;
}

.department-requirements {
    display: grid;
    gap: 0 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    list-style: none;
    margin: 0 0 0.85rem;
    padding: 0;
}

.department-requirements li {
    align-items: flex-start;
    background: transparent;
    border: 0;
    border-bottom: 1px solid #e3eaf0;
    border-radius: 0;
    color: var(--gray-700);
    display: flex;
    font-size: .76rem;
    gap: .5rem;
    padding: .52rem .15rem;
}

.department-requirements i {
    color: var(--theme-primary);
    margin-top: 0.18rem;
}

.artifact-list {
    --artifact-table-columns: minmax(260px, 1.55fr) minmax(145px, .85fr) 108px 150px 112px;
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 10px;
    display: block;
    flex: 1 1 auto;
    max-height: 100%;
    min-height: 0;
    overflow: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.artifact-table-head {
    align-items: center;
    background: #f7f9fb;
    border-bottom: 1px solid var(--gray-200);
    color: #64778a;
    display: grid;
    font-size: .6rem;
    font-weight: 700;
    gap: .8rem;
    grid-template-columns: var(--artifact-table-columns);
    letter-spacing: .045em;
    padding: .58rem .78rem;
    position: sticky;
    text-transform: uppercase;
    top: 0;
    z-index: 2;
}

.artifact-table-head span:first-child {
    padding-left: calc(34px + .62rem);
}

.artifact-table-head span:last-child {
    text-align: right;
}

.task-summary-section__body--artifacts .artifact-table-head span:last-child {
    text-align: center;
}

.task-summary-section__body--artifacts .artifact-item__actions {
    justify-content: center;
}

.artifact-history-panel {
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.artifact-history-panel .artifact-table-head {
    grid-template-columns: minmax(0, 1fr) auto;
}

.artifact-history-panel .artifact-table-head span:first-child {
    padding-left: calc(34px + .62rem);
}

.artifact-history-panel .artifact-table-head span:nth-child(2),
.artifact-history-panel .artifact-table-head span:nth-child(3),
.artifact-history-panel .artifact-table-head span:nth-child(4) {
    display: none;
}

.artifact-history-panel .artifact-item {
    grid-template-columns: minmax(0, 1fr) auto;
}

.artifact-history-panel .artifact-item__task,
.artifact-history-panel .artifact-item__kind,
.artifact-history-panel .artifact-item__updated {
    display: none;
}

.artifact-item {
    align-items: center;
    background: #fff;
    display: grid;
    flex: 0 0 auto;
    gap: .8rem;
    grid-template-columns: var(--artifact-table-columns);
    min-width: 0;
    min-height: 58px;
    padding: .55rem .78rem;
    transition: background-color .18s ease;
}

.artifact-item:not(:last-of-type) {
    border-bottom: 1px solid var(--gray-200);
}

.artifact-item:hover {
    background: #f7fbfe;
}

.artifact-item__identity {
    align-items: center;
    display: grid;
    gap: .62rem;
    grid-template-columns: 34px minmax(0, 1fr);
    min-width: 0;
}

.artifact-item__icon {
    align-items: center;
    background: rgba(31, 99, 146, .1);
    border-radius: 8px;
    color: var(--theme-primary);
    display: inline-flex;
    font-size: .9rem;
    height: 34px;
    justify-content: center;
    width: 34px;
}

.artifact-item__content {
    min-width: 0;
    overflow: hidden;
}

.artifact-item__title {
    color: var(--gray-900);
    font-size: .78rem;
    font-weight: 650;
    line-height: 1.35;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.artifact-item__filename,
.artifact-item__task,
.artifact-item__updated {
    color: #718296;
    font-size: .64rem;
    line-height: 1.35;
    min-width: 0;
}

.artifact-item__filename,
.artifact-item__task {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.artifact-item__filename {
    margin-top: .13rem;
}

.artifact-item__task {
    color: #35536d;
}

.artifact-item__kind {
    background: #edf5fa;
    border-radius: 999px;
    color: #176c99;
    display: inline-flex;
    font-size: .61rem;
    font-weight: 650;
    justify-self: start;
    padding: .28rem .48rem;
    white-space: nowrap;
}

.artifact-item__updated strong {
    color: #35536d;
    display: block;
    font-size: .66rem;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.artifact-item__updated span {
    display: block;
    margin-top: 0;
}

.artifact-item__actions {
    align-items: center;
    display: flex;
    flex: 0 0 auto;
    gap: .28rem;
}

.artifact-item__view {
    align-items: center;
    background: #edf5fa;
    border: 1px solid #d3e3ed;
    border-radius: 7px;
    color: #176c99;
    display: inline-flex;
    font-size: .66rem;
    font-weight: 650;
    gap: .32rem;
    height: 31px;
    justify-content: center;
    padding: 0 .58rem;
    text-decoration: none;
    white-space: nowrap;
}

.artifact-item__view:hover {
    background: #176c99;
    border-color: #176c99;
    color: #fff;
}

.artifact-item__empty {
    color: var(--gray-400);
    font-size: .64rem;
}

.artifact-note {
    min-width: 0;
    margin-top: .32rem;
}

.artifact-note__label {
    align-items: center;
    color: #60788e;
    display: flex;
    font-size: .61rem;
    font-weight: 650;
    gap: .3rem;
    margin-bottom: .18rem;
    text-transform: uppercase;
}

.artifact-note p {
    color: var(--gray-600);
    display: -webkit-box;
    font-size: .64rem;
    line-height: 1.45;
    margin: 0;
    overflow: hidden;
    overflow-wrap: anywhere;
    text-overflow: ellipsis;
    white-space: normal;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
    word-break: break-word;
}

.artifact-drawer-backdrop {
    background: rgba(7, 19, 33, .42);
    inset: 0;
    opacity: 0;
    position: fixed;
    transition: opacity .2s ease;
    visibility: hidden;
    z-index: 1095;
}

.artifact-drawer-backdrop.is-open {
    opacity: 1;
    visibility: visible;
}

.artifact-detail-drawer {
    background: #fff;
    box-shadow: -20px 0 48px rgba(15, 42, 64, .18);
    display: flex;
    flex-direction: column;
    height: 100vh;
    max-width: 100%;
    position: fixed;
    right: 0;
    top: 0;
    transform: translateX(104%);
    transition: transform .24s ease;
    width: 420px;
    z-index: 1096;
}

.artifact-detail-drawer.is-open {
    transform: translateX(0);
}

.artifact-detail-drawer__header {
    align-items: center;
    border-bottom: 1px solid #dbe5ec;
    display: flex;
    justify-content: space-between;
    min-height: 68px;
    padding: 1rem 1.15rem;
}

.artifact-detail-drawer__header h5 {
    color: #17324d;
    font-size: .98rem;
    font-weight: 650;
    margin: 0;
}

.artifact-detail-drawer__close {
    align-items: center;
    background: #edf5fa;
    border: 0;
    border-radius: 9px;
    color: #176c99;
    display: inline-flex;
    height: 36px;
    justify-content: center;
    width: 36px;
}

.artifact-detail-drawer__body {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 1.15rem;
}

.artifact-drawer-file {
    align-items: flex-start;
    display: grid;
    gap: .75rem;
    grid-template-columns: 42px minmax(0, 1fr);
    margin-bottom: 1.25rem;
}

.artifact-drawer-file__icon {
    align-items: center;
    background: #edf5fa;
    border-radius: 10px;
    color: #176c99;
    display: inline-flex;
    font-size: 1rem;
    height: 42px;
    justify-content: center;
    width: 42px;
}

.artifact-drawer-file h6 {
    color: #17324d;
    font-size: .9rem;
    font-weight: 650;
    line-height: 1.4;
    margin: 0;
    overflow-wrap: anywhere;
}

.artifact-drawer-file p {
    color: #718296;
    font-size: .68rem;
    margin: .2rem 0 0;
    overflow-wrap: anywhere;
}

.artifact-drawer-section {
    border-top: 1px solid #e1e8ee;
    padding: .9rem 0;
}

.artifact-drawer-section__label {
    color: #6a7f93;
    display: block;
    font-size: .61rem;
    font-weight: 700;
    letter-spacing: .045em;
    margin-bottom: .32rem;
    text-transform: uppercase;
}

.artifact-drawer-section strong,
.artifact-drawer-section p {
    color: #29465f;
    font-size: .75rem;
    line-height: 1.55;
    margin: 0;
    overflow-wrap: anywhere;
}

.artifact-detail-drawer__actions {
    border-top: 1px solid #dbe5ec;
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
    padding: 1rem 1.15rem;
}

.artifact-detail-drawer__actions a {
    align-items: center;
    border: 1px solid #c9dce8;
    border-radius: 8px;
    color: #176c99;
    display: inline-flex;
    font-size: .7rem;
    font-weight: 650;
    gap: .4rem;
    padding: .55rem .7rem;
    text-decoration: none;
}

.artifact-detail-drawer__actions a:hover {
    background: #176c99;
    border-color: #176c99;
    color: #fff;
}

body.artifact-drawer-open {
    overflow: hidden;
}

.task-output-summary {
    border-top: 1px solid #e1e8ee;
    margin-top: 1rem;
    padding-top: 1rem;
}

.task-output-summary .artifact-list {
    background: transparent;
    border: 0;
    border-radius: 0;
    display: block;
    max-height: none;
    overflow: visible;
}

.task-output-summary .artifact-item {
    background: transparent;
    border-bottom: 1px solid var(--gray-200);
    min-height: 58px;
    padding: .55rem .78rem;
}

.task-output-summary .artifact-item:hover {
    background: #f7fbfe;
}

.task-output-summary .artifact-item__icon {
    height: 32px;
    width: 32px;
}

.task-summary-sheet {
    border: 0;
    border-radius: 0;
    margin-bottom: 0;
}

.task-summary-section + .task-summary-section {
    border-top: 1px solid #dbe5ec;
}

.task-summary-section__header {
    align-items: center;
    background: #fff;
    border-bottom: 1px solid #dbe5ec;
    display: flex;
    gap: .75rem;
    justify-content: space-between;
    min-height: 44px;
    padding: .68rem 1.1rem;
}

.task-summary-section__header h6 {
    align-items: center;
    color: #17324d;
    display: flex;
    font-size: .8rem;
    font-weight: 650;
    gap: .48rem;
    letter-spacing: 0;
    margin: 0;
}

.task-summary-section__header h6 i {
    color: #176c99;
}

.task-summary-section__header > span {
    color: #6b7f93;
    font-size: .68rem;
    white-space: nowrap;
}

.task-summary-section__body {
    background: #fff;
    padding: 1rem 1.1rem;
}

.task-summary-section__body--flush {
    padding: 0;
}

.task-summary-section__body--artifacts {
    padding-bottom: .35rem;
    padding-top: .35rem;
}

.task-summary-section .task-detail-hero {
    border: 0;
    border-radius: 0;
    margin: 0;
}

.task-summary-overview-extras {
    padding: 0 1.1rem 1rem;
}

.task-summary-overview-extras > :last-child {
    margin-bottom: 0;
}

.task-summary-section .department-modal-heading {
    margin-bottom: 1rem;
}

.task-summary-section .artifact-list {
    background: transparent;
    border: 0;
    border-radius: 0;
    display: block;
    max-height: none;
    overflow: visible;
}

.task-summary-section .artifact-item {
    background: transparent;
    border-bottom: 1px solid var(--gray-200);
    min-height: 58px;
    padding: .55rem .78rem;
}

.task-summary-section .artifact-item:hover {
    background: #f7fbfe;
}

.task-summary-section .artifact-item__icon {
    height: 32px;
    width: 32px;
}

.task-summary-section .task-comments-list {
    border: 0;
    min-height: 54px;
    padding: 0;
}

.task-summary-section .git-update-item {
    border: 0;
    border-bottom: 1px solid var(--gray-200);
    border-radius: 0;
    box-shadow: none;
    margin: 0;
    padding: .8rem .15rem;
}

.roadmap-ready-banner {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    border: 1px solid rgba(31, 99, 146, 0.18);
    border-radius: 8px;
    background: rgba(31, 99, 146, 0.08);
    color: var(--gray-700);
    padding: 0.75rem 0.85rem;
    margin-bottom: 1rem;
    font-size: 0.82rem;
}

.roadmap-ready-banner i {
    color: var(--theme-primary);
    margin-top: 0.15rem;
}

.roadmap-ready-banner.is-locked {
    border-color: rgba(217, 119, 6, 0.22);
    background: rgba(245, 158, 11, 0.1);
}

.roadmap-ready-banner.is-locked i {
    color: #d97706;
}

.design-reference-strip {
    border: 1px solid rgba(31, 99, 146, 0.18);
    border-radius: 8px;
    background: #fff;
    margin-bottom: 1rem;
    padding: 0.9rem;
}

.design-reference-strip__header {
    align-items: center;
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.design-reference-strip__header h6 {
    margin: 0;
}

.design-reference-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.75rem;
}

.design-reference-card {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    overflow: hidden;
    background: var(--gray-50);
}

.design-reference-card img {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 10;
    object-fit: contain;
    background: #fff;
}

.design-reference-card__body {
    padding: 0.6rem;
}

.design-reference-card__title {
    color: var(--gray-900);
    font-size: 0.78rem;
    font-weight: 700;
    margin-bottom: 0.45rem;
}

.developer-upload-field {
    align-items: stretch;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.055), rgba(255, 255, 255, 0.94)),
        #fff;
    border: 1px dashed rgba(31, 99, 146, 0.22);
    border-radius: 12px;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    min-height: 44px;
    overflow: hidden;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.developer-upload-field:hover,
.developer-upload-field:focus-within {
    border-color: rgba(13, 103, 134, 0.46);
    box-shadow: 0 0 0 3px rgba(13, 103, 134, 0.08);
}

.developer-upload-button {
    align-items: center;
    background: rgba(226, 244, 250, 0.92);
    border-right: 1px solid rgba(31, 99, 146, 0.16);
    color: #0d6786;
    cursor: pointer;
    display: inline-flex;
    font-size: 0.82rem;
    font-weight: 700;
    gap: 0.45rem;
    justify-content: center;
    margin: 0;
    min-height: 42px;
    padding: 0 0.85rem;
    transition: background-color 0.2s ease, color 0.2s ease;
    white-space: nowrap;
}

.developer-upload-button:hover {
    background: #0d6786;
    color: #fff;
}

.developer-upload-summary {
    align-self: center;
    color: #28445f;
    font-size: 0.84rem;
    min-width: 0;
    overflow: hidden;
    padding: 0 0.85rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media (max-width: 767.98px) {
    .task-facts-grid,
    .process-guide__grid {
        grid-template-columns: 1fr;
    }

    .git-activity-row {
        align-items: start;
        grid-template-columns: 34px minmax(0, 1fr);
        padding: .75rem .85rem;
    }

    .git-activity-actions {
        grid-column: 2;
        justify-content: flex-start;
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

@media (max-width: 420px) {
    .developer-upload-button,
    .developer-upload-summary {
        padding-inline: 0.7rem;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-workspace-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-board-toolbar,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-column,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-update-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-list,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .roadmap-ready-banner,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .design-reference-strip,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .design-reference-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-sheet,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-reopen-callout,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-fact,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #reopenRequestModal .modal-content {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-summary-section + .task-summary-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-handoff-grid > .col-md-6 + .col-md-6 {
    border-color: rgba(31, 99, 146, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-board-toolbar__icon {
    background: rgba(31, 99, 146, .22);
    color: #7dd3fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-board-toolbar,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context {
    border-color: rgba(31, 99, 146, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-upload-field {
    background: rgba(10, 27, 44, 0.72);
    border-color: rgba(138, 210, 255, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-upload-field:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-upload-field:focus-within {
    border-color: rgba(125, 211, 252, 0.5);
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.1);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-upload-button {
    background: rgba(13, 103, 134, 0.28);
    border-color: rgba(138, 210, 255, 0.16);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-upload-button:hover {
    background: #0d6786;
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-upload-summary {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__inner {
    background: #081827;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-board-toolbar__copy strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-filter select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__overview-copy strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__identity strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-board-toolbar__copy small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__overview-copy span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__overview-stat,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__requirements-empty {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__eyebrow,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__label {
    color: #88a7c2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__requirement-list li {
    color: #c0d2e3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-filter {
    border-bottom-color: rgba(125, 211, 252, .32);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-filter i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card-project,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-context-toggle,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__actions a,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__actions button,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-column-more {
    color: #7dd3fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__actions a,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__actions button {
    border-color: rgba(125, 211, 252, .26);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item:hover {
    background: #0a1b2b;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-table-head {
    background: #0a1b2b;
    border-bottom-color: rgba(31, 99, 146, .24);
    color: #88a7c2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__details {
    background: #0a1b2b;
    border-color: rgba(31, 99, 146, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__icon {
    background: rgba(31, 99, 146, .2);
    color: #7dd3fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__actions a:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-project-context__actions button:hover {
    background: rgba(31, 99, 146, .22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__task,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__updated,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__filename {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__updated strong {
    color: #c0d2e3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__kind,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__view {
    background: rgba(31, 99, 146, .18);
    border-color: rgba(125, 211, 252, .2);
    color: #7dd3fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-detail-drawer {
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-detail-drawer__header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-detail-drawer__actions,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-drawer-section {
    border-color: rgba(31, 99, 146, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-detail-drawer__header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-drawer-file h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-drawer-section strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-drawer-file p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-drawer-section p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-drawer-section__label {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card-footer,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card-actions {
    border-color: rgba(31, 99, 146, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-facts-grid,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-fact,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-comments-heading,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-comments-list,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-requirements li {
    border-color: rgba(31, 99, 146, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-summary-section__body {
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-summary-section__header {
    background: #081827;
    border-bottom-color: rgba(31, 99, 146, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-summary-section__header h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-summary-section__header h6 i {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-summary-section__header > span {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-row {
    border-bottom-color: rgba(31, 99, 146, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-row--latest {
    background: rgba(31, 99, 146, .1);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-latest {
    background: rgba(31, 99, 146, .2);
    color: #8fd3f4;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-branch {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-detail,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-empty {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-detail code {
    background: rgba(255, 255, 255, .07);
    color: #c1d8eb;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-note {
    color: #b2c8dc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-action {
    background: rgba(31, 99, 146, .1);
    border-color: rgba(125, 211, 252, .25);
    color: #9ed9f5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-action:hover {
    background: rgba(31, 99, 146, .24);
    color: #d7effb;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-comments-heading span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-comment-compose__footer small {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card::before,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card::before {
    background: #071321;
    border-color: rgba(31, 99, 146, .24);
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #reopenRequestModal .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #reopenRequestModal .modal-footer,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-task-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-hero,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-col,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-month {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-header {
    background: transparent !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-column[data-board-status="todo"] {
    background: #0a1826 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-column[data-board-status="in_progress"] {
    background: #091b2b !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-column[data-board-status="review"] {
    background: #1b180f !important;
    border-color: rgba(245, 158, 11, .25) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-column[data-board-status="completed"] {
    background: #0b1c18 !important;
    border-color: rgba(16, 185, 129, .22) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-header .badge {
    background: rgba(125, 211, 252, .12) !important;
    color: #b9ddf4 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-badge:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-action {
    background: rgba(31, 99, 146, 0.14) !important;
    border-color: rgba(31, 99, 146, 0.28) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-action:hover {
    background: var(--theme-primary) !important;
    border-color: var(--theme-primary) !important;
    color: #ffffff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-outline-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-reopen-modern {
    background: rgba(31, 99, 146, 0.12);
    border-color: rgba(31, 99, 146, 0.3);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-outline-modern:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-reopen-modern:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-action-reopen:hover {
    background: var(--theme-primary) !important;
    border-color: var(--theme-primary) !important;
    color: #fff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card.reopened-card {
    background: #1f1608 !important;
    border-color: #f59e0b !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card.reopened-card::before {
    background: #1f1608;
    border-color: #f59e0b;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-indicator {
    background: rgba(245, 158, 11, 0.2);
    color: #fbbf24;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .revision-indicator {
    background: rgba(248, 113, 113, .18);
    color: #fca5a5;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-reopen-callout__title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-panel h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #reopenRequestModal .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-task-summary strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .design-reference-card__title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-fact-value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-project-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-col {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card-description,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .kanban-card-due,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card-date {
    color: #90aeca !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card-title {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-badge small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-workbench__summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-chip,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requirement-list li,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-modal-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-modal-help,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .reopen-task-summary span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-requirements li,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-item__meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-note__label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .artifact-note p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .roadmap-ready-banner,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-mini p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .completed-card small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-detail-desc,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-fact-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-project-dates,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-task-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-month,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-reopen-callout__copy p {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-reopen-callout__status {
    background: rgba(245, 158, 11, .18);
    color: #fbbf24;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #reopenRequestModal .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #reopenRequestModal .form-select {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-side-panel .form-control::placeholder,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #reopenRequestModal .form-control::placeholder {
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pull-request-modal .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-footer {
    background: #081827;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-sheet {
    background: #071321;
    border-color: rgba(31, 99, 146, .3);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-intro,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-handoff-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-section-divider,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-footer {
    border-color: rgba(31, 99, 146, .25);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-task-context__icon {
    background: rgba(31, 99, 146, .2);
    color: #7dd3fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-task-context__copy h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-intro strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-handoff-section__heading strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-section-divider strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-task-context__copy p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-intro span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-handoff-section__heading span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-section-divider span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-field-help,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-footer__notice {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-sheet .form-label {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-sheet .form-control {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, .3);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pr-form-sheet .form-control::placeholder {
    color: #718aa2;
}

/* Responsive */
@media (max-width: 1199.98px) {
    .task-board-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 575.98px) {
    .task-detail-modal .modal-body {
        padding: .65rem;
    }

    .pull-request-modal .modal-dialog {
        margin: .65rem;
        max-width: calc(100vw - 1.3rem);
    }

    .pull-request-modal .modal-body {
        padding: .85rem;
    }

    .pr-form-footer {
        align-items: stretch;
        flex-direction: column;
    }

    .pr-form-footer__actions {
        justify-content: flex-end;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }

    .page-header-modern h2 {
        font-size: 1.5rem;
    }

    .task-board-grid {
        grid-template-columns: 1fr;
        padding: .8rem;
    }

    .kanban-body {
        min-height: 128px;
    }

    .task-board-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .task-board-toolbar__controls {
        align-items: stretch;
        flex-direction: column;
    }

    .task-project-filter {
        min-width: 0;
        width: 100%;
    }

    .task-project-context__project {
        align-items: flex-start;
        grid-template-columns: 1fr;
    }

    .task-project-context__overview {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .task-project-context__overview-stats {
        flex-basis: 100%;
        margin-left: 3rem;
    }

    .task-project-context__requirement-list {
        grid-template-columns: 1fr;
    }

    .artifact-item {
        align-items: center;
        grid-template-columns: minmax(0, 1fr) auto;
        padding: .6rem .65rem;
    }

    .artifact-table-head {
        display: none;
    }

    .artifact-item__task,
    .artifact-item__kind,
    .artifact-item__updated {
        display: none;
    }

    .artifact-item__actions {
        grid-column: 2;
        justify-self: end;
    }

    .task-summary-section__header {
        align-items: flex-start;
        flex-direction: column;
        gap: .25rem;
    }

    .department-handoff-grid > .col-md-6 + .col-md-6 {
        border-left: 0;
        border-top: 1px solid #e1e8ee;
        margin-top: .9rem;
        padding-left: calc(var(--bs-gutter-x) * .5);
        padding-top: 1rem;
    }

    .task-facts-grid,
    .department-requirements {
        grid-template-columns: 1fr;
    }

    .task-fact {
        border-bottom: 1px solid #e1e8ee;
        border-right: 0;
    }

    .task-fact:last-child {
        border-bottom: 0;
    }

    .task-comment-compose__footer {
        align-items: stretch;
        flex-direction: column;
    }

    .task-comment-compose__footer .task-detail-action {
        justify-content: center;
        width: 100%;
    }

    .task-reopen-callout {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .task-reopen-callout__copy {
        flex-basis: calc(100% - 3.2rem);
    }

    .task-reopen-callout .btn-reopen-modern,
    .task-reopen-callout__status {
        margin-left: 3.2rem;
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

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-check me-2"></i><?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-exclamation me-2"></i><?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <section class="task-workspace-panel">
    <div class="task-board-toolbar" aria-label="Task board project filter">
        <div class="task-board-toolbar__title">
            <span class="task-board-toolbar__icon" aria-hidden="true"><i class="fas fa-table-columns"></i></span>
            <span class="task-board-toolbar__copy">
                <strong><?php echo htmlspecialchars((string) $departmentProfile['title']); ?></strong>
                <small>Choose a project, then manage its tasks by workflow status.</small>
            </span>
        </div>
        <div class="task-board-toolbar__controls">
            <label class="task-project-filter" for="taskProjectFilter">
                <i class="fas fa-folder-tree" aria-hidden="true"></i>
                <select id="taskProjectFilter" aria-label="Filter task board by project">
                    <option value="all">All assigned projects</option>
                    <?php foreach ($taskBoardProjects as $taskBoardProject): ?>
                        <option value="<?php echo (int) $taskBoardProject['id']; ?>">
                            <?php echo htmlspecialchars((string) $taskBoardProject['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button
                class="task-context-toggle"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#taskProjectContext"
                aria-expanded="false"
                aria-controls="taskProjectContext"
            >
                <i class="fas fa-circle-info" aria-hidden="true"></i>
                <span>Project context</span>
                <i class="fas fa-chevron-down task-context-toggle__chevron" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div class="collapse task-project-context" id="taskProjectContext">
        <div class="task-project-context__inner">
            <?php if (count($departmentProjects) > 0): ?>
                <?php
                    $contextRequirementCount = 0;
                    $contextReviewCount = 0;
                    foreach ($departmentProjects as $contextProject) {
                        $contextRequirementCount += count($contextProject['requirements'] ?? []);
                        $contextReviewCount += (int) ($contextProject['handoff_count'] ?? 0);
                    }
                ?>
                <div class="task-project-context__overview" data-task-project-context-overview>
                    <span class="task-project-context__overview-icon" aria-hidden="true">
                        <i class="fas fa-folder-tree"></i>
                    </span>
                    <span class="task-project-context__overview-copy">
                        <strong><?php echo count($departmentProjects); ?> assigned project<?php echo count($departmentProjects) === 1 ? '' : 's'; ?></strong>
                        <span>Select a project above to view its requirements, timeline, and roadmap.</span>
                    </span>
                    <span class="task-project-context__overview-stats" aria-label="Assigned project summary">
                        <span class="task-project-context__overview-stat">
                            <i class="fas fa-list-check" aria-hidden="true"></i>
                            <?php echo $contextRequirementCount; ?> requirement<?php echo $contextRequirementCount === 1 ? '' : 's'; ?>
                        </span>
                        <?php if ($contextReviewCount > 0): ?>
                            <span class="task-project-context__overview-stat">
                                <i class="fas fa-rotate" aria-hidden="true"></i>
                                <?php echo $contextReviewCount; ?> in review
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php foreach ($departmentProjects as $project): ?>
                    <?php
                        $requirements = array_slice($project['requirements'] ?? [], 0, 6);
                        $handoffCount = (int) ($project['handoff_count'] ?? 0);
                        $projectId = (int) ($project['id'] ?? 0);
                    ?>
                    <div class="task-project-context__project" data-task-project-context="<?php echo $projectId; ?>" hidden>
                        <div class="task-project-context__identity">
                            <span class="task-project-context__eyebrow">Project</span>
                            <strong><?php echo htmlspecialchars((string) ($project['name'] ?? 'Project')); ?></strong>
                            <span class="task-project-context__meta">
                                <span><i class="fas fa-building" aria-hidden="true"></i> <?php echo htmlspecialchars((string) ($project['client_name'] ?? 'Client')); ?></span>
                                <?php if ($handoffCount > 0): ?>
                                    <span>&middot; <?php echo $handoffCount; ?> in review</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="task-project-context__requirements">
                            <span class="task-project-context__label">Project requirements</span>
                            <?php if (count($requirements) > 0): ?>
                                <ul class="task-project-context__requirement-list">
                                    <?php foreach ($requirements as $requirement): ?>
                                        <li>
                                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                                            <span><?php echo htmlspecialchars((string) $requirement); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="task-project-context__requirements-empty">No captured requirements yet.</p>
                            <?php endif; ?>
                        </div>
                        <div class="task-project-context__actions">
                            <?php if (isset($ganttProjects[$projectId])): ?>
                                <button type="button" onclick="showGanttModal(<?php echo htmlspecialchars(json_encode($ganttProjects[$projectId]), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fas fa-chart-gantt" aria-hidden="true"></i> Timeline
                                </button>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php?project_id=<?php echo $projectId; ?>">
                                <i class="fas fa-route" aria-hidden="true"></i> Roadmap
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="task-project-context__empty">No project context is available yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="task-board-grid">
        <!-- To Do Column -->
        <div class="task-board-lane">
            <div class="kanban-column" data-board-status="todo">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-clock text-secondary me-2"></i><?php echo $isQaDepartmentDeveloper ? 'Ready for Testing' : 'To Do'; ?> <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="<?php echo $isQaDepartmentDeveloper ? 'QA tasks assigned by the QA SUL and ready for testing.' : 'Tasks waiting for you to start. Moving from here only sets the task to In Progress.'; ?>"></i></span>
                        <span class="badge bg-secondary"><?php echo $todoTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="todo-column">
                    <?php while($task = $todoTasks->fetch_assoc()): ?>
                    <?php
                        $taskProjectName = trim((string) ($task['project_name'] ?? '')) ?: 'Project not specified';
                        $taskDescription = trim((string) ($task['description'] ?? ''));
                        $showTaskDescription = $taskDescription !== ''
                            && strcasecmp(rtrim($taskDescription, " \t\n\r\0\x0B."), 'Requested by Sales during client intake') !== 0;
                    ?>
                    <div
                        class="kanban-card task-board-item"
                        data-task-id="<?php echo $task['id']; ?>"
                        data-project-id="<?php echo (int) ($task['project_id'] ?? 0); ?>"
                        data-project-name="<?php echo htmlspecialchars($taskProjectName, ENT_QUOTES, 'UTF-8'); ?>"
                        draggable="true"
                    >
                        <h6 class="kanban-card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <?php if ($showTaskDescription): ?>
                            <p class="kanban-card-description"><?php echo htmlspecialchars(substr($taskDescription, 0, 100)); ?></p>
                        <?php endif; ?>
                        <div class="kanban-card-meta">
                            <span class="kanban-card-project"><i class="fas fa-diagram-project" aria-hidden="true"></i><span><?php echo htmlspecialchars($taskProjectName); ?></span></span>
                            <span class="kanban-card-due"><i class="far fa-calendar-alt"></i><?php echo !empty($task['deadline']) ? date('M d, Y', strtotime((string) $task['deadline'])) : 'No deadline'; ?></span>
                        </div>
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr((string) ($task['git_commit_hash'] ?? ''), 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="kanban-card-footer">
                            <div class="kanban-card-actions">
                                <button type="button" class="btn-action" onclick="startTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="<?php echo $isQaDepartmentDeveloper ? 'Start testing this task.' : 'Start this task without changing the controlled review workflow.'; ?>">
                                    <i class="fas fa-play"></i> <?php echo $isQaDepartmentDeveloper ? 'Start Testing' : 'Start'; ?>
                                </button>
                                <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="View the task summary, saved handoffs, comments, and Git activity.">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($todoTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-check-circle"></i>
                        <p><?php echo $isQaDepartmentDeveloper ? 'No tasks ready for testing' : 'No tasks in To Do'; ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- In Progress Column -->
        <div class="task-board-lane">
            <div class="kanban-column" data-board-status="in_progress">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-spinner text-primary me-2"></i><?php echo $isQaDepartmentDeveloper ? 'Testing' : 'In Progress'; ?> <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="<?php echo $isQaDepartmentDeveloper ? 'Testing is active. Record QA notes and evidence, then submit the results to the QA SUL.' : 'Work is active here. Submit a PR from task details when it is ready for review.'; ?>"></i></span>
                        <span class="badge bg-primary"><?php echo $inProgressTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="progress-column">
                    <?php while($task = $inProgressTasks->fetch_assoc()): ?>
                    <?php
                        $isReopenedTask = !empty($task['last_reopened_at']) && (string) ($task['status'] ?? '') === 'sul_revision';
                        $isRevisionTask = in_array((string) ($task['status'] ?? ''), ['qa_failed', 'sul_revision'], true)
                            && !$isReopenedTask;
                        $taskProjectName = trim((string) ($task['project_name'] ?? '')) ?: 'Project not specified';
                        $taskDescription = trim((string) ($task['description'] ?? ''));
                        $showTaskDescription = $taskDescription !== ''
                            && strcasecmp(rtrim($taskDescription, " \t\n\r\0\x0B."), 'Requested by Sales during client intake') !== 0;
                    ?>
                    <div
                        class="kanban-card task-board-item <?php echo $isReopenedTask ? 'reopened-card' : ''; ?>"
                        data-task-id="<?php echo $task['id']; ?>"
                        data-project-id="<?php echo (int) ($task['project_id'] ?? 0); ?>"
                        data-project-name="<?php echo htmlspecialchars($taskProjectName, ENT_QUOTES, 'UTF-8'); ?>"
                        draggable="true"
                    >
                        <?php if ($isReopenedTask): ?>
                            <span class="reopen-indicator" data-bs-toggle="tooltip" title="Architect approved a reopen request for this task.">
                                <i class="fas fa-rotate-left"></i> Reopened
                            </span>
                        <?php elseif ($isRevisionTask): ?>
                            <span class="revision-indicator" data-bs-toggle="tooltip" title="QA returned this task for correction using the saved revision notes.">
                                <i class="fas fa-screwdriver-wrench"></i> Revision
                            </span>
                        <?php endif; ?>
                        <h6 class="kanban-card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <?php if ($showTaskDescription): ?>
                            <p class="kanban-card-description"><?php echo htmlspecialchars(substr($taskDescription, 0, 100)); ?></p>
                        <?php endif; ?>
                        <div class="kanban-card-meta">
                            <span class="kanban-card-project"><i class="fas fa-diagram-project" aria-hidden="true"></i><span><?php echo htmlspecialchars($taskProjectName); ?></span></span>
                            <span class="kanban-card-due"><i class="far fa-calendar-alt"></i><?php echo !empty($task['deadline']) ? date('M d, Y', strtotime((string) $task['deadline'])) : 'No deadline'; ?></span>
                        </div>
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr((string) ($task['git_commit_hash'] ?? ''), 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="kanban-card-footer">
                            <div class="kanban-card-actions">
                                <button class="btn-action" onclick="openPullRequestModal(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="<?php echo $userDepartmentCanonical === 'UI/UX' ? 'Submit the saved design handoff for workflow review.' : 'Submit this task\'s pull request for workflow review.'; ?>">
                                    <i class="fas <?php echo $userDepartmentCanonical === 'UI/UX' ? 'fa-paper-plane' : 'fa-code-pull-request'; ?>"></i>
                                    <?php echo $userDepartmentCanonical === 'UI/UX' ? 'Submit Design' : 'Submit PR'; ?>
                                </button>
                                <button class="btn-action" onclick="reviewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="<?php echo $isQaDepartmentDeveloper ? 'Move this task back to Ready for Testing.' : 'Move this task back to To Do if work has not started cleanly.'; ?>">
                                    <i class="fas fa-undo"></i> Back
                                </button>
                                <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="View the task summary, saved handoffs, comments, and Git activity.">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($inProgressTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-play-circle"></i>
                        <p><?php echo $isQaDepartmentDeveloper ? 'No tasks currently being tested' : 'No tasks in progress'; ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Review Column -->
        <div class="task-board-lane">
            <div class="kanban-column" data-board-status="review">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-check-circle text-warning me-2"></i><?php echo $isQaDepartmentDeveloper ? 'QA SUL Review' : 'Workflow Review'; ?> <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="<?php echo $isQaDepartmentDeveloper ? 'Submitted test results are waiting for QA SUL approval or revision.' : 'Tasks arrive here only through PR submission and continue through SUL, QA, and Architect review.'; ?>"></i></span>
                        <span class="badge bg-warning"><?php echo $reviewTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="review-column">
                    <?php while($task = $reviewTasks->fetch_assoc()): ?>
                    <?php
                        $taskProjectName = trim((string) ($task['project_name'] ?? '')) ?: 'Project not specified';
                        $taskDescription = trim((string) ($task['description'] ?? ''));
                        $showTaskDescription = $taskDescription !== ''
                            && strcasecmp(rtrim($taskDescription, " \t\n\r\0\x0B."), 'Requested by Sales during client intake') !== 0;
                    ?>
                    <div
                        class="kanban-card task-board-item"
                        data-task-id="<?php echo $task['id']; ?>"
                        data-project-id="<?php echo (int) ($task['project_id'] ?? 0); ?>"
                        data-project-name="<?php echo htmlspecialchars($taskProjectName, ENT_QUOTES, 'UTF-8'); ?>"
                        draggable="true"
                    >
                        <h6 class="kanban-card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <?php if ($showTaskDescription): ?>
                            <p class="kanban-card-description"><?php echo htmlspecialchars(substr($taskDescription, 0, 100)); ?></p>
                        <?php endif; ?>
                        <div class="kanban-card-meta">
                            <span class="kanban-card-project"><i class="fas fa-diagram-project" aria-hidden="true"></i><span><?php echo htmlspecialchars($taskProjectName); ?></span></span>
                            <span class="kanban-card-due"><i class="far fa-calendar-alt"></i><?php echo !empty($task['deadline']) ? date('M d, Y', strtotime((string) $task['deadline'])) : 'No deadline'; ?></span>
                        </div>
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr((string) ($task['git_commit_hash'] ?? ''), 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="kanban-card-footer">
                            <div class="kanban-card-actions">
                                <?php if ((int) ($task['active_reopen_request_count'] ?? 0) > 0): ?>
                                    <span class="badge bg-info text-dark" data-bs-toggle="tooltip" title="This task already has a reopen request waiting for SUL or Architect decision.">Pending Reopen</span>
                                <?php else: ?>
                                    <button class="btn-action btn-action-reopen" onclick="openReopenRequestModal(<?php echo (int) $task['id']; ?>, decodeURIComponent('<?php echo rawurlencode((string) $task['title']); ?>'))" data-bs-toggle="tooltip" title="Ask SUL to review a reopen request for this task.">
                                        <i class="fas fa-rotate-left"></i> Reopen
                                    </button>
                                <?php endif; ?>
                                <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)" data-bs-toggle="tooltip" title="Review the task details and handoff history.">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($reviewTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-check-double"></i>
                        <p><?php echo $isQaDepartmentDeveloper ? 'No test results awaiting QA SUL review' : 'No tasks in review'; ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Completed Column -->
        <div class="task-board-lane">
            <div class="kanban-column" data-board-status="completed">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-check-double text-success me-2"></i><?php echo $isQaDepartmentDeveloper ? 'Testing Completed' : 'Completed'; ?> <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="<?php echo $isQaDepartmentDeveloper ? 'QA department testing approved by the QA SUL.' : 'Completion remains controlled by the existing SUL, QA, Architect, and release flow.'; ?>"></i></span>
                        <span class="badge bg-success"><?php echo $completedTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="completed-column">
                    <?php while($task = $completedTasks->fetch_assoc()): ?>
                    <?php
                        $canRequestReopen = in_array((string) ($task['status'] ?? ''), ['for_review', 'qa_testing', 'qa_failed', 'qa_passed', 'architect_review', 'merged', 'ready_for_release', 'completed'], true);
                        $taskProjectName = trim((string) ($task['project_name'] ?? '')) ?: 'Project not specified';
                    ?>
                    <div
                        class="completed-card task-board-item"
                        data-task-id="<?php echo (int) $task['id']; ?>"
                        data-project-id="<?php echo (int) ($task['project_id'] ?? 0); ?>"
                        data-project-name="<?php echo htmlspecialchars($taskProjectName, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                        <?php $doneAt = $task['completed_at'] ?: ($task['developer_completed_at'] ?? null); ?>
                        <span class="completed-card-title"><?php echo htmlspecialchars($task['title']); ?></span>
                        <div class="kanban-card-meta">
                            <span class="kanban-card-project"><i class="fas fa-diagram-project" aria-hidden="true"></i><span><?php echo htmlspecialchars($taskProjectName); ?></span></span>
                            <span class="completed-card-date"><i class="far fa-calendar-check"></i><?php echo !empty($doneAt) ? date('M d, Y', strtotime((string) $doneAt)) : 'No date'; ?></span>
                        </div>
                        <div class="completed-card-actions">
                            <?php if ((int) ($task['active_reopen_request_count'] ?? 0) > 0): ?>
                                <span class="badge bg-info text-dark">Pending Reopen</span>
                            <?php elseif ($canRequestReopen): ?>
                                <button class="btn-action btn-action-reopen" onclick="openReopenRequestModal(<?php echo (int) $task['id']; ?>, decodeURIComponent('<?php echo rawurlencode((string) $task['title']); ?>'))" data-bs-toggle="tooltip" title="Ask SUL to review a reopen request for this completed task.">
                                    <i class="fas fa-rotate-left"></i> Request Reopen
                                </button>
                            <?php endif; ?>
                            <button class="btn-action-view" onclick="viewTask(<?php echo (int) $task['id']; ?>)" data-bs-toggle="tooltip" title="Open task details.">
                                <i class="fas fa-eye"></i> View
                            </button>
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
    </section>
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
                <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Task Summary</h5>
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

<!-- Pull Request Submission Modal -->
<div class="modal fade modal-modern pull-request-modal" id="pullRequestModal" tabindex="-1" aria-labelledby="pullRequestModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="pull-request-modal__eyebrow">Workflow submission</span>
                    <h5 class="modal-title" id="pullRequestModalTitle">
                        <i class="fas fa-code-pull-request me-2"></i>Submit Pull Request
                    </h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="pullRequestModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reopen Request Modal -->
<div class="modal fade modal-modern reopen-request-modal" id="reopenRequestModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-rotate-left me-2"></i>Request Task Reopen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="reopenRequestForm">
                <div class="modal-body">
                    <input type="hidden" id="reopenTaskId" name="task_id">
                    <div class="reopen-task-summary">
                        <i class="fas fa-clipboard-check"></i>
                        <div>
                            <span>Task</span>
                            <strong id="reopenTaskTitle"></strong>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Severity</label>
                        <select class="form-select" id="reopenSeverity" name="severity" required>
                            <option value="minor">Minor - change affects only this task</option>
                            <option value="major">Major - change may affect later work or phases</option>
                        </select>
                        <div class="reopen-modal-help">
                            <i class="fas fa-circle-info"></i>
                            <span>Major requests go to Architect so downstream phases can be reopened when needed.</span>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Reason</label>
                        <textarea class="form-control" id="reopenReason" name="reason" rows="4" placeholder="Explain what went wrong and what needs to change." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">
                        <i class="fas fa-xmark"></i> Cancel
                    </button>
                    <button type="submit" class="btn-primary-modern">
                        <i class="fas fa-paper-plane"></i> Send to SUL
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const currentDepartment = <?php echo json_encode($userDepartment); ?>;
const currentDepartmentCanonical = <?php echo json_encode($userDepartmentCanonical); ?>;
const isQaDepartment = currentDepartmentCanonical === 'QA';
const departmentProfile = <?php echo json_encode($departmentProfile); ?>;
const taskProjectFilter = document.getElementById('taskProjectFilter');
const taskColumnInitialLimit = 6;

function getVisibleBoardItems(columnBody) {
    return Array.from(columnBody?.querySelectorAll('.task-board-item') || [])
        .filter(item => !item.hidden);
}

function applyTaskProjectFilter({ resetScroll = false } = {}) {
    const selectedProjectId = taskProjectFilter?.value || 'all';

    document.querySelectorAll('.kanban-body').forEach(columnBody => {
        const columnItems = Array.from(columnBody.querySelectorAll('.task-board-item'));
        columnItems.forEach(item => {
            item.hidden = selectedProjectId !== 'all'
                && item.dataset.projectId !== selectedProjectId;
        });

        if (resetScroll) {
            columnBody.dataset.expanded = 'false';
        }

        const projectItems = getVisibleBoardItems(columnBody);
        const isExpanded = columnBody.dataset.expanded === 'true';
        projectItems.forEach((item, index) => {
            item.classList.toggle('is-task-collapsed', !isExpanded && index >= taskColumnInitialLimit);
        });

        let moreButton = columnBody.querySelector('.task-column-more');
        const hiddenCount = Math.max(0, projectItems.length - taskColumnInitialLimit);
        if (!isExpanded && hiddenCount > 0) {
            if (!moreButton) {
                moreButton = document.createElement('button');
                moreButton.type = 'button';
                moreButton.className = 'task-column-more';
                moreButton.addEventListener('click', () => {
                    columnBody.dataset.expanded = 'true';
                    applyTaskProjectFilter();
                });
                columnBody.appendChild(moreButton);
            }
            moreButton.innerHTML = `<i class="fas fa-chevron-down" aria-hidden="true"></i> Show ${hiddenCount} more`;
        } else {
            moreButton?.remove();
        }

        updateKanbanColumnCount(columnBody);
    });

    const showingAllProjects = selectedProjectId === 'all';
    const contextOverview = document.querySelector('[data-task-project-context-overview]');
    if (contextOverview) {
        contextOverview.hidden = !showingAllProjects;
    }

    document.querySelectorAll('[data-task-project-context]').forEach(context => {
        context.hidden = showingAllProjects
            || context.dataset.taskProjectContext !== selectedProjectId;
    });

}

function initTooltips(root = document) {
    if (!window.bootstrap || !bootstrap.Tooltip) {
        return;
    }

    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        const existingTooltip = bootstrap.Tooltip.getInstance(element);
        if (existingTooltip) {
            existingTooltip.dispose();
        }
        bootstrap.Tooltip.getOrCreateInstance(element, { trigger: 'hover' });
    });
}

document.addEventListener('click', (event) => {
    if (!window.bootstrap || !bootstrap.Tooltip) {
        return;
    }

    const tooltipTrigger = event.target.closest('[data-bs-toggle="tooltip"]');
    if (tooltipTrigger) {
        bootstrap.Tooltip.getInstance(tooltipTrigger)?.hide();
    }
});

document.addEventListener('change', (event) => {
    const fileInput = event.target.closest?.('[data-developer-file-input]');
    if (!fileInput) {
        return;
    }

    const summary = fileInput
        .closest('.developer-upload-field')
        ?.querySelector('[data-developer-file-summary]');
    if (!summary) {
        return;
    }

    const fileName = fileInput.files?.[0]?.name || 'No file chosen';
    summary.textContent = fileName;
    summary.title = fileInput.files?.[0]?.name || '';
});

function updateKanbanColumnCount(columnBody) {
    if (!columnBody) {
        return;
    }

    const column = columnBody.closest('.kanban-column');
    const badge = column?.querySelector('.kanban-header .badge');
    const count = getVisibleBoardItems(columnBody).length;
    if (badge) {
        badge.textContent = String(count);
    }

    ensureKanbanColumnEmptyState(columnBody);
}

function getKanbanEmptyState(columnBody) {
    if (columnBody.id === 'todo-column') {
        return { icon: 'fa-check-circle', text: isQaDepartment ? 'No tasks ready for testing' : 'No tasks in To Do' };
    }
    if (columnBody.id === 'progress-column') {
        return { icon: 'fa-play-circle', text: isQaDepartment ? 'No tasks currently being tested' : 'No tasks in progress' };
    }
    if (columnBody.id === 'review-column') {
        return { icon: 'fa-check-double', text: isQaDepartment ? 'No test results awaiting QA SUL review' : 'No tasks in review' };
    }

    return { icon: 'fa-inbox', text: 'No tasks' };
}

function ensureKanbanColumnEmptyState(columnBody) {
    const allItems = Array.from(columnBody.querySelectorAll('.task-board-item'));
    const visibleItems = allItems.filter(item => !item.hidden);
    const defaultEmpty = columnBody.querySelector('.empty-state-mini:not(.task-filter-empty)');
    const filterEmpty = columnBody.querySelector('.task-filter-empty');

    if (visibleItems.length > 0) {
        defaultEmpty?.remove();
        filterEmpty?.remove();
        return;
    }

    if (allItems.length > 0) {
        defaultEmpty?.remove();
        if (!filterEmpty) {
            const empty = document.createElement('div');
            empty.className = 'empty-state-mini task-filter-empty';
            empty.innerHTML = '<i class="fas fa-filter"></i><p>No tasks from this project in this status.</p>';
            columnBody.appendChild(empty);
        }
        return;
    }

    filterEmpty?.remove();
    if (defaultEmpty) {
        return;
    }

    const config = getKanbanEmptyState(columnBody);
    const empty = document.createElement('div');
    empty.className = 'empty-state-mini';
    empty.innerHTML = `<i class="fas ${config.icon}"></i><p>${config.text}</p>`;
    columnBody.appendChild(empty);
}

function transformKanbanCardForReviewColumn(card, taskId) {
    const footer = card.querySelector('.kanban-card-footer, .d-flex.justify-content-between.align-items-center');
    if (!footer) {
        return;
    }

    const dueLabel = footer.querySelector('.kanban-card-due, small');
    const dueHtml = dueLabel ? dueLabel.outerHTML : '';
    footer.className = 'kanban-card-footer';
    footer.innerHTML = `
        ${dueHtml}
        <div class="kanban-card-actions">
            <button class="btn-action-view" onclick="viewTask(${taskId})">
                <i class="fas fa-eye"></i> View
            </button>
        </div>
    `;
}

function transformKanbanCardForProgressColumn(card, taskId) {
    const footer = card.querySelector('.kanban-card-footer, .d-flex.justify-content-between.align-items-center');
    if (!footer) {
        return;
    }

    const dueLabel = footer.querySelector('.kanban-card-due, small');
    const dueHtml = dueLabel ? dueLabel.outerHTML : '';
    footer.className = 'kanban-card-footer';
    footer.innerHTML = `
        ${dueHtml}
        <div class="kanban-card-actions">
            <button class="btn-action" onclick="openPullRequestModal(${taskId})" data-bs-toggle="tooltip" title="${currentDepartmentCanonical === 'UI/UX' ? "Submit the saved design handoff for workflow review." : "Submit this task's pull request for workflow review."}">
                <i class="fas ${currentDepartmentCanonical === 'UI/UX' ? 'fa-paper-plane' : 'fa-code-pull-request'}"></i>
                ${currentDepartmentCanonical === 'UI/UX' ? 'Submit Design' : 'Submit PR'}
            </button>
            <button class="btn-action" onclick="reviewTask(${taskId})" data-bs-toggle="tooltip" title="${isQaDepartment ? 'Move this task back to Ready for Testing.' : 'Move this task back to To Do if work has not started cleanly.'}">
                <i class="fas fa-undo"></i> Back
            </button>
            <button class="btn-action-view" onclick="viewTask(${taskId})" data-bs-toggle="tooltip" title="View the task summary, saved handoffs, comments, and Git activity.">
                <i class="fas fa-eye"></i> View
            </button>
        </div>
    `;
}

function transformKanbanCardForTodoColumn(card, taskId) {
    const footer = card.querySelector('.kanban-card-footer, .d-flex.justify-content-between.align-items-center');
    if (!footer) {
        return;
    }

    const dueLabel = footer.querySelector('.kanban-card-due, small');
    const dueHtml = dueLabel ? dueLabel.outerHTML : '';
    footer.className = 'kanban-card-footer';
    footer.innerHTML = `
        ${dueHtml}
        <div class="kanban-card-actions">
            <button type="button" class="btn-action" onclick="startTask(${taskId})" data-bs-toggle="tooltip" title="${isQaDepartment ? 'Start testing this task.' : 'Start this task and open the UI/UX design references.'}">
                <i class="fas fa-play"></i> ${isQaDepartment ? 'Start Testing' : 'Start'}
            </button>
            <button class="btn-action-view" onclick="viewTask(${taskId})" data-bs-toggle="tooltip" title="View the task summary, saved handoffs, comments, and Git activity.">
                <i class="fas fa-eye"></i> View
            </button>
        </div>
    `;
}

function moveKanbanTaskToColumn(taskId, status) {
    if (status !== 'completed') {
        document.querySelectorAll(`.completed-card[data-task-id="${String(taskId)}"]`).forEach((completedCard) => {
            const completedColumn = completedCard.closest('.kanban-body');
            completedCard.remove();
            updateKanbanColumnCount(completedColumn);
        });
    }

    const card = document.querySelector(`.kanban-card[data-task-id="${String(taskId)}"]`);
    const targetColumn = status === 'todo'
        ? document.getElementById('todo-column')
        : status === 'in_progress'
            ? document.getElementById('progress-column')
            : status === 'for_review'
                ? document.getElementById('review-column')
                : null;

    if (!card || !targetColumn || card.closest('.kanban-body') === targetColumn) {
        return;
    }

    const sourceColumn = card.closest('.kanban-body');
    if (status === 'todo') {
        transformKanbanCardForTodoColumn(card, taskId);
    } else if (status === 'in_progress') {
        transformKanbanCardForProgressColumn(card, taskId);
    } else if (status === 'for_review') {
        transformKanbanCardForReviewColumn(card, taskId);
    }

    targetColumn.querySelector('.empty-state-mini')?.remove();
    targetColumn.appendChild(card);
    updateKanbanColumnCount(sourceColumn);
    updateKanbanColumnCount(targetColumn);
    applyTaskProjectFilter();
    initTooltips(card);
}

function moveKanbanTaskToReview(taskId) {
    moveKanbanTaskToColumn(taskId, 'for_review');
}

function startTask(taskId) {
    updateTaskStatus(taskId, 'in_progress', {
        openDetails: true,
        skipConfirm: true
    });
}

function reviewTask(taskId) {
    updateTaskStatus(taskId, 'todo');
}

function showTaskNotice(title, message, type = 'success') {
    if (typeof window.appToast === 'function') {
        window.appToast(title, message, type);
        return;
    }

    if (typeof window.showToast === 'function') {
        window.showToast(title, message, type);
        return;
    }

    console[type === 'error' ? 'error' : 'log'](`${title}: ${message}`);
}

async function updateTaskStatus(taskId, status, options = {}) {
    if (!options.skipConfirm) {
        const confirmOptions = {
                title: 'Confirm Task Update',
                subject: status === 'for_review'
                    ? (isQaDepartment ? 'Submit QA test results?' : 'Submit design for review?')
                    : (status === 'in_progress' ? (isQaDepartment ? 'Start testing?' : 'Start this task?') : 'Update this task status?'),
                message: status === 'for_review'
                    ? (isQaDepartment
                        ? 'Your QA notes and evidence will be sent to the QA SUL for approval.'
                        : 'Your UI/UX design handoff will be sent to the SUL for approval.')
                    : status === 'in_progress'
                        ? (isQaDepartment
                            ? 'The task will move to Testing and its requirements, suggested tests, and handoffs will open.'
                            : 'The task will move to In Progress and its UI/UX designs/uploads will open for reference.')
                        : 'The task status will be saved and activity logged.',
                icon: 'fas fa-circle-check fa-lg',
                confirmIcon: 'fas fa-check',
                confirmText: status === 'for_review'
                    ? (isQaDepartment ? 'Submit Test Results' : 'Submit Design')
                    : (status === 'in_progress' ? (isQaDepartment ? 'Start Testing' : 'Start Task') : 'Update')
            };
        const confirmed = typeof window.appConfirm === 'function'
            ? await window.appConfirm(confirmOptions)
            : window.confirm(`${confirmOptions.subject}\n\n${confirmOptions.message}`);
        if (!confirmed) {
            return;
        }
    }

    try {
        const requestBody = new URLSearchParams({
            task_id: String(taskId),
            new_status: status
        });
        const endpoint = `${String(window.BASE_URL || '../../').replace(/\/?$/, '/')}api/update-task-status.php`;
        const request = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: requestBody.toString()
        });
        const response = await request.json().catch(() => ({}));
        if (!request.ok) {
            const requestError = new Error(response.error || 'Unable to update task status.');
            requestError.responseJSON = response;
            throw requestError;
        }

        moveKanbanTaskToColumn(taskId, response.new_status || status);
        if (!options.suppressDetails && (options.openDetails || status === 'in_progress' || status === 'for_review')) {
            viewTask(taskId);
        }
        return response;
    } catch (error) {
        showTaskNotice('Task Update', error.responseJSON?.error || error.message || 'Unable to update task status.', 'error');
        throw error;
    }
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

function openPullRequestModal(taskId) {
    const modalElement = document.getElementById('pullRequestModal');
    const modalBody = document.getElementById('pullRequestModalBody');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;
    modal.show();

    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        data: { id: taskId },
        success: function(response) {
            const task = typeof response === 'string' ? JSON.parse(response) : response;
            const latestGit = task.latest_git_update || {};
            const branchValue = latestGit.branch_name || '';
            const repositoryValue = latestGit.repository_url || '';
            const repositoryBranches = Array.isArray(task.repository_branches) ? task.repository_branches : [];
            const branchOptions = repositoryBranches.map(branch => `
                <option
                    value="${escapeAttribute(branch.name || '')}"
                    data-repository-url="${escapeAttribute(branch.repository_url || '')}"
                    ${String(branch.name || '') === String(branchValue) ? 'selected' : ''}
                >${escapeHtml(branch.name || 'Unnamed branch')}${repositoryBranches.some(other => other.repository_id !== branch.repository_id) ? ` — ${escapeHtml(branch.repository_name || 'Repository')}` : ''}</option>
            `).join('');
            const commitHashValue = latestGit.commit_hash || '';
            const commitMessageValue = latestGit.commit_message || '';
            const pullRequestValue = latestGit.pull_request_url || '';
            const statusLabel = String(task.status || 'in progress').replaceAll('_', ' ');
            const isUiUxSubmission = currentDepartmentCanonical === 'UI/UX';
            const needsSubmissionHandoff = isUiUxSubmission;
            const submissionHandoff = needsSubmissionHandoff ? buildDepartmentPanel(task, { submission: true }) : '';
            const modalTitle = document.getElementById('pullRequestModalTitle');
            modalTitle.innerHTML = isUiUxSubmission
                ? '<i class="fas fa-pen-ruler me-2"></i>Submit Design Handoff'
                : '<i class="fas fa-code-pull-request me-2"></i>Submit Pull Request';
            const versionControlFields = isUiUxSubmission ? '' : `
                <div class="pr-section-divider">
                    <strong>Pull request</strong>
                    <span>Connect the implementation that the reviewer should inspect.</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="prBranchName">Branch name</label>
                        <select id="prBranchName" class="form-select" required ${repositoryBranches.length ? '' : 'disabled'}>
                            <option value="">${repositoryBranches.length ? 'Select an available feature branch' : 'No feature branches available'}</option>
                            ${branchOptions}
                        </select>
                        <small class="pr-field-help">${repositoryBranches.length ? 'Choose a branch prepared for this project.' : 'Ask the Architect to prepare the project repository and feature branches.'}</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="prPullRequestUrl">Pull request URL</label>
                        <input id="prPullRequestUrl" class="form-control" type="url" value="${escapeAttribute(pullRequestValue)}" placeholder="https://github.com/.../pull/..." autocomplete="url" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="prRepositoryUrl">Repository URL</label>
                        <input id="prRepositoryUrl" class="form-control" type="url" value="${escapeAttribute(repositoryValue)}" placeholder="https://github.com/organization/repository" autocomplete="url">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="prCommitHash">Latest commit</label>
                        <input id="prCommitHash" class="form-control" value="${escapeAttribute(commitHashValue)}" placeholder="Commit hash" autocomplete="off">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label" for="prCommitMessage">Change summary</label>
                        <input id="prCommitMessage" class="form-control" value="${escapeAttribute(commitMessageValue)}" placeholder="Short summary of the completed work">
                    </div>
                </div>
            `;

            modalBody.innerHTML = `
                <div class="pr-task-context">
                    <span class="pr-task-context__icon"><i class="fas fa-code-branch"></i></span>
                    <div class="pr-task-context__copy">
                        <h6>${escapeHtml(task.title || 'Development task')}</h6>
                        <p>${escapeHtml(task.project_name || 'Project')} &middot; ${escapeHtml(statusLabel)}</p>
                    </div>
                </div>
                <form class="pr-form-sheet" id="pullRequestForm" onsubmit="${isUiUxSubmission ? 'submitDesignSubmission' : 'submitPullRequest'}(event, ${Number(taskId)})">
                    <div class="pr-form-body">
                        <div class="pr-form-intro">
                            <strong>${isUiUxSubmission ? 'Prepare the design package for review' : 'Send your completed work for review'}</strong>
                            <span>${isUiUxSubmission ? 'The eye view remains a clean summary. Save the handoff and add the reviewer context here.' : 'Choose the implementation branch and paste the pull request for SUL review.'}</span>
                        </div>
                        ${submissionHandoff}
                        ${versionControlFields}
                        <div class="pr-section-divider">
                            <strong>Comment for reviewer</strong>
                            <span>Leave the final context with the task activity.</span>
                        </div>
                        <div>
                            <label class="form-label" for="prReviewNotes">Comment</label>
                            <textarea id="prReviewNotes" class="form-control" rows="3" placeholder="Mention testing completed, known limitations, or what the reviewer should verify."></textarea>
                            <small class="pr-field-help">Submitting moves this task to Workflow Review and notifies the Service Unit Leader.</small>
                        </div>
                    </div>
                    <div class="pr-form-footer">
                        <span class="pr-form-footer__notice"><i class="fas fa-circle-info"></i> Your task data and estimates are not changed.</span>
                        <div class="pr-form-footer__actions">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="submitPullRequestButton" ${!isUiUxSubmission && !repositoryBranches.length ? 'disabled' : ''}>
                                <i class="fas fa-paper-plane"></i> ${isUiUxSubmission ? 'Submit Design' : 'Submit for Review'}
                            </button>
                        </div>
                    </div>
                </form>
            `;
            const branchSelect = document.getElementById('prBranchName');
            const syncSelectedRepository = () => {
                const selectedOption = branchSelect?.selectedOptions?.[0];
                const selectedRepositoryUrl = selectedOption?.dataset?.repositoryUrl || repositoryValue;
                const repositoryInput = document.getElementById('prRepositoryUrl');
                if (repositoryInput) repositoryInput.value = selectedRepositoryUrl;
            };
            branchSelect?.addEventListener('change', syncSelectedRepository);
            syncSelectedRepository();
        },
        error: function(xhr) {
            const message = xhr.responseJSON?.error || 'Unable to prepare the pull request form';
            modalBody.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(message)}</div>`;
        }
    });
}

async function submitPullRequest(event, taskId) {
    event.preventDefault();

    const form = event.currentTarget;
    if (!form.reportValidity()) {
        return;
    }

    const submitButton = document.getElementById('submitPullRequestButton');
    const originalButtonHtml = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Submitting...';

    try {
        const reviewComment = $('#prReviewNotes').val().trim();
        if (reviewComment) {
            await $.ajax({
                url: '../../api/add-task-comment.php',
                method: 'POST',
                data: { task_id: taskId, comment: reviewComment }
            });
        }

        const response = await $.ajax({
            url: '../../api/add-task-git-update.php',
            method: 'POST',
            dataType: 'json',
            data: {
                task_id: taskId,
                git_action: 'pull_request_opened',
                branch_name: String($('#prBranchName').val() || '').trim(),
                commit_hash: $('#prCommitHash').val().trim(),
                commit_message: $('#prCommitMessage').val().trim(),
                pull_request_url: $('#prPullRequestUrl').val().trim(),
                repository_url: $('#prRepositoryUrl').val().trim(),
                status: 'in_review',
                notes: reviewComment
            }
        });

        const data = typeof response === 'string' ? JSON.parse(response) : response;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('pullRequestModal')).hide();
        moveKanbanTaskToReview(taskId);
        showTaskNotice('Pull Request Submitted', data.message || 'Task moved to Workflow Review.');
    } catch (xhr) {
        showTaskNotice('Pull Request', xhr.responseJSON?.error || 'Failed to submit the pull request', 'error');
        submitButton.disabled = false;
        submitButton.innerHTML = originalButtonHtml;
    }
}

async function saveSubmissionHandoff(taskId, button) {
    const originalButtonHtml = button?.innerHTML || '';
    if (button) {
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving...';
    }

    try {
        await saveDepartmentArtifact(taskId, { reloadTask: false });
    } finally {
        if (button) {
            button.disabled = false;
            button.innerHTML = originalButtonHtml;
        }
    }
}

async function submitDesignSubmission(event, taskId) {
    event.preventDefault();

    const form = event.currentTarget;
    if (!form.reportValidity()) {
        return;
    }

    const submitButton = document.getElementById('submitPullRequestButton');
    const originalButtonHtml = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Submitting...';

    try {
        await saveDepartmentArtifact(taskId, { silent: true, reloadTask: false });

        const reviewComment = $('#prReviewNotes').val().trim();
        if (reviewComment) {
            await $.ajax({
                url: '../../api/add-task-comment.php',
                method: 'POST',
                data: { task_id: taskId, comment: reviewComment }
            });
        }

        await updateTaskStatus(taskId, 'for_review', { skipConfirm: true, suppressDetails: true });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('pullRequestModal')).hide();
        showTaskNotice('Design Submitted', 'Design handoff moved to Workflow Review.');
    } catch (xhr) {
        showTaskNotice('Design Submission', xhr.responseJSON?.error || 'Unable to submit the design handoff.', 'error');
        submitButton.disabled = false;
        submitButton.innerHTML = originalButtonHtml;
    }
}

function viewTask(taskId) {
    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        data: { id: taskId },
        success: function(response) {
            const task = typeof response === 'string' ? JSON.parse(response) : response;
            const deadlineText = task.deadline ? new Date(task.deadline).toLocaleDateString() : 'No deadline set';
            const committedDays = Math.max(0, Number(task.sales_estimate_days || 0));
            const committedChoice = String(task.sales_estimate_choice || '').toLowerCase();
            const commitmentLabels = {
                min: 'Fastest',
                likely: 'Balanced',
                max: 'Safest buffer'
            };
            const estimateText = committedDays > 0
                ? `${committedDays} ${committedDays === 1 ? 'day' : 'days'}${commitmentLabels[committedChoice] ? ` · ${commitmentLabels[committedChoice]}` : ''}`
                : 'Not finalized by Sales';
            const designReferenceStrip = buildDesignReferenceStrip(task);
            const departmentPanel = buildDepartmentPanel(task, { readOnly: true });
            const roadmapBanner = buildRoadmapReadyBanner(task);
            const departmentRevisionBanner = buildDepartmentRevisionBanner(task);
            const reopenPanel = buildReopenRequestPanel(task);
            const isReopenedTask = Boolean(task.last_reopened_at) && String(task.status || '') === 'sul_revision';
            const isRevisionTask = ['qa_failed', 'sul_revision'].includes(String(task.status || '')) && !isReopenedTask;
            announceDeveloperTaskGuidance(task);
            const reopenedBadge = isReopenedTask
                ? '<span class="reopen-indicator mb-0"><i class="fas fa-rotate-left"></i> Reopened</span>'
                : '';
            const revisionBadge = isRevisionTask
                ? '<span class="revision-indicator mb-0"><i class="fas fa-screwdriver-wrench"></i> Revision</span>'
                : '';
            const overviewSupport = [reopenPanel, roadmapBanner, designReferenceStrip]
                .filter((section) => String(section || '').trim())
                .join('');
            const gitUpdates = Array.isArray(task.git_updates) ? task.git_updates : [];
            const gitActivityRows = gitUpdates.map((update, index) => {
                const detailParts = [];
                if (update.commit_hash) {
                    detailParts.push(`<code title="${escapeAttribute(update.commit_hash)}">${escapeHtml(shortCommit(update.commit_hash))}</code>`);
                }
                if (update.commit_message) {
                    detailParts.push(`<span>${escapeHtml(update.commit_message)}</span>`);
                }
                if (update.notes) {
                    detailParts.push(`<span class="git-activity-note"><i class="far fa-note-sticky me-1"></i>${escapeHtml(update.notes)}</span>`);
                }

                const actions = [
                    update.repository_url
                        ? `<a href="${escapeAttribute(update.repository_url)}" target="_blank" rel="noopener" class="git-activity-action"><i class="fas fa-arrow-up-right-from-square"></i>Repository</a>`
                        : '',
                    update.pull_request_url
                        ? `<a href="${escapeAttribute(update.pull_request_url)}" target="_blank" rel="noopener" class="git-activity-action"><i class="fas fa-code-pull-request"></i>Pull Request</a>`
                        : ''
                ].filter(Boolean).join('');

                return `
                    <article class="git-activity-row${index === 0 ? ' git-activity-row--latest' : ''}">
                        <span class="git-activity-icon" aria-hidden="true">
                            <i class="fas ${update.git_action === 'merged' ? 'fa-code-merge' : 'fa-code-branch'}"></i>
                        </span>
                        <div class="git-activity-content">
                            <div class="git-activity-title">
                                <span class="git-activity-branch">${escapeHtml(update.branch_name || 'Unnamed branch')}</span>
                                ${index === 0 ? '<span class="git-activity-latest">Latest</span>' : ''}
                                <span class="badge bg-light text-primary border">${escapeHtml(formatGitAction(update.git_action))}</span>
                                ${formatGitStatusBadge(update.status)}
                            </div>
                            <div class="git-activity-meta">
                                ${escapeHtml(update.user_name || 'Unknown')} &middot; ${escapeHtml(formatDateTime(update.created_at))}
                            </div>
                            ${detailParts.length ? `<div class="git-activity-detail">${detailParts.join('')}</div>` : ''}
                        </div>
                        ${actions ? `<div class="git-activity-actions">${actions}</div>` : ''}
                    </article>`;
            }).join('');
            const gitActivitySection = `
                <section class="task-summary-section">
                    <header class="task-summary-section__header">
                        <h6><i class="fas fa-code-branch"></i>Git Activity</h6>
                        <span>${gitUpdates.length} ${gitUpdates.length === 1 ? 'update' : 'updates'}</span>
                    </header>
                    <div class="task-summary-section__body task-summary-section__body--flush">
                        ${gitActivityRows
                            ? `<div class="git-activity-list">${gitActivityRows}</div>`
                            : '<p class="git-activity-empty">No Git activity has been recorded for this task yet.</p>'}
                    </div>
                </section>`;
            const commentsSection = `
                <section class="task-summary-section">
                    <header class="task-summary-section__header">
                        <h6><i class="fas fa-comments"></i>Comments</h6>
                        <span>Read-only activity</span>
                    </header>
                    <div class="task-summary-section__body">
                        <div class="task-comments-list">
                            <div id="commentsList" class="small"></div>
                        </div>
                    </div>
                </section>`;
            let html = `
                <div class="task-detail-sheet task-summary-sheet">
                    <section class="task-summary-section">
                        <header class="task-summary-section__header">
                            <h6><i class="fas fa-circle-info"></i>Task Overview</h6>
                            <span>Read-only summary</span>
                        </header>
                        <div class="task-summary-section__body task-summary-section__body--flush">
                            <div class="task-detail-hero">
                                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                    <div>
                                        <h5 class="task-detail-title">${escapeHtml(task.title)}</h5>
                                        <p class="task-detail-desc">${task.description ? escapeHtml(task.description) : 'No description provided.'}</p>
                                    </div>
                                    ${reopenedBadge || revisionBadge}
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
                                        <div class="task-fact-label">Committed duration</div>
                                        <div class="task-fact-value">${escapeHtml(estimateText)}</div>
                                    </div>
                                </div>
                            </div>
                            ${overviewSupport ? `<div class="task-summary-overview-extras">${overviewSupport}</div>` : ''}
                        </div>
                    </section>
                    ${departmentPanel}
                    ${gitActivitySection}
                    ${commentsSection}
                </div>
            `;
            $('#taskDetails').html(html);
            loadComments(taskId);
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

function announceDeveloperTaskGuidance(task) {
    if (!task || !task.id) return;
    const historyKey = `${isQaDepartment ? 'evortix.qa-developer.testing.v1' : 'evortix.developer.task.v2'}.${task.id}.${task.evortix_suggestion_version || task.status || 'current'}`;
    if (localStorage.getItem(historyKey)) return;

    const guidance = [];
    const daysLeft = Number(task.days_left);
    if (task.deadline && Number.isFinite(daysLeft) && daysLeft <= 3) {
        guidance.push(daysLeft < 0
            ? `Deadline alert: this task is ${Math.abs(daysLeft)} day(s) overdue.`
            : `Deadline alert: only ${daysLeft} day(s) remain.`);
    }

    if (task.evortix_revision) {
        const source = String(task.evortix_revision.role || 'reviewer').toUpperCase();
        guidance.push(`${source} requested revision: ${task.evortix_revision.comment || 'Review the latest feedback and correct the requested items.'}`);
    }

    const tests = Array.isArray(task.evortix_recommended_tests) ? task.evortix_recommended_tests : [];
    if (!['for_review', 'qa_testing', 'qa_passed', 'architect_review', 'completed'].includes(String(task.status || ''))) {
        guidance.push(`Before submission: ${tests.join(' ')}`);
    }

    if (task.evortix_next_task) {
        guidance.push(`Recommended next task: ${task.evortix_next_task.title}. ${task.evortix_next_task.reason}`);
    } else {
        guidance.push('No other dependency-ready task is currently ahead of this one.');
    }

    let announcementAttempts = 0;
    const deliverGuidance = () => {
        if (typeof window.evortixAnnounceNotification !== 'function' && announcementAttempts++ < 10) {
            window.setTimeout(deliverGuidance, 250);
            return;
        }
        if (typeof window.evortixAnnounceNotification !== 'function') return;
        window.evortixAnnounceNotification(
            `${isQaDepartment ? 'QA testing guidance' : 'Developer guidance'}: ${task.title || 'Task'}`,
            guidance.join(' '),
            ''
        );
        localStorage.setItem(historyKey, JSON.stringify({ reviewedAt: new Date().toISOString(), taskId: task.id }));
    };
    window.setTimeout(deliverGuidance, 350);
}

function announceDeveloperTaskPageGuidance() {
    const recommendedCard = document.querySelector('#progress-column .reopened-card')
        || document.querySelector('#progress-column .kanban-card')
        || document.querySelector('#todo-column .kanban-card');
    const taskId = Number(recommendedCard?.dataset.taskId || 0);

    if (taskId <= 0) {
        window.setTimeout(() => window.evortixAnnounceNotification?.(
            'Developer task guidance',
            'You have no active or ready tasks right now. Evortix will guide you when a new task or revision is assigned.',
            ''
        ), 700);
        return;
    }

    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        data: { id: taskId },
        success: response => {
            const task = typeof response === 'string' ? JSON.parse(response) : response;
            announceDeveloperTaskGuidance(task);
        }
    });
}

document.addEventListener('DOMContentLoaded', announceDeveloperTaskPageGuidance);

function buildReopenRequestPanel(task) {
    const eligibleStatuses = ['for_review', 'qa_testing', 'qa_failed', 'qa_passed', 'architect_review', 'merged', 'ready_for_release', 'completed'];
    const status = String(task.status || '');
    if (!eligibleStatuses.includes(status)) {
        return '';
    }

    if (Number(task.active_reopen_request_count || 0) > 0) {
        return `
            <div class="task-reopen-callout task-reopen-callout--pending">
                <span class="task-reopen-callout__icon"><i class="fas fa-clock"></i></span>
                <div class="task-reopen-callout__copy">
                    <div class="task-reopen-callout__title">Reopen request pending</div>
                    <p>SUL or Architect is reviewing the requested task changes.</p>
                </div>
                <span class="task-reopen-callout__status">Pending review</span>
            </div>
        `;
    }

    return `
        <div class="task-reopen-callout">
            <span class="task-reopen-callout__icon"><i class="fas fa-rotate-left"></i></span>
            <div class="task-reopen-callout__copy">
                <div class="task-reopen-callout__title">Need changes after review?</div>
                <p>Request a Minor task fix or a Major change that may affect later work.</p>
            </div>
            <button class="btn-reopen-modern" onclick="openReopenRequestModal(${Number(task.id)}, ${jsStringLiteral(task.title || 'Task')})">
                <i class="fas fa-rotate-left"></i> Request Reopen
            </button>
        </div>
    `;
}

function openReopenRequestModal(taskId, taskTitle) {
    $('#reopenTaskId').val(taskId);
    $('#reopenTaskTitle').text(taskTitle || 'Selected task');
    $('#reopenSeverity').val('minor');
    $('#reopenReason').val('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('reopenRequestModal')).show();
}

$('#reopenRequestForm').on('submit', function(event) {
    event.preventDefault();

    const submitButton = this.querySelector('button[type="submit"]');
    const originalText = submitButton ? submitButton.innerHTML : '';
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending';
    }

    $.ajax({
        url: '../../api/request-task-reopen.php',
        method: 'POST',
        dataType: 'json',
        data: $(this).serialize(),
        success: function(response) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('reopenRequestModal')).hide();
            showTaskNotice('Reopen Request', response.message || 'Reopen request sent to SUL.');
            window.location.reload();
        },
        error: function(xhr) {
            showTaskNotice('Reopen Request', xhr.responseJSON?.message || 'Unable to send reopen request.', 'error');
        },
        complete: function() {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            }
        }
    });
});

function buildDesignReferenceStrip(task) {
    if (currentDepartmentCanonical === 'UI/UX') {
        return '';
    }

    const artifacts = Array.isArray(task.department_artifacts) ? task.department_artifacts : [];
    const designArtifacts = artifacts.filter((artifact) => artifact.artifact_type === 'design_handoff');
    if (designArtifacts.length === 0) {
        return `
            <div class="design-reference-strip">
                <div class="design-reference-strip__header">
                    <h6><i class="fas fa-pen-ruler me-2 text-primary"></i>UI/UX Design References</h6>
                    <span class="badge bg-light text-muted border">No upload yet</span>
                </div>
                <p class="text-muted small mb-0">No UI/UX design upload or prototype link has been saved for this feature yet.</p>
            </div>
        `;
    }

    const cards = designArtifacts.slice(0, 4).map((artifact) => {
        const preview = artifact.file_url
            ? `<a href="${escapeAttribute(artifact.file_url)}" target="_blank" rel="noopener">
                    <img src="${escapeAttribute(artifact.file_url)}" alt="${escapeAttribute(artifact.original_file_name || artifact.title || 'Design preview')}" loading="lazy">
               </a>`
            : `<div class="empty-state-mini py-3"><i class="fas fa-link"></i><p>Prototype link</p></div>`;
        const sourceTask = artifact.source_task_title ? `<div class="small text-muted mb-2">${escapeHtml(artifact.source_task_title)}</div>` : '';
        const fileLink = artifact.file_url
            ? `<a class="btn btn-sm btn-outline-primary" href="${escapeAttribute(artifact.file_url)}" target="_blank" rel="noopener"><i class="fas fa-image me-1"></i>Open</a>`
            : '';
        const externalLink = artifact.external_url
            ? `<a class="btn btn-sm btn-outline-primary" href="${escapeAttribute(artifact.external_url)}" target="_blank" rel="noopener"><i class="fas fa-link me-1"></i>Prototype</a>`
            : '';

        return `
            <div class="design-reference-card">
                ${preview}
                <div class="design-reference-card__body">
                    <div class="design-reference-card__title">${escapeHtml(artifact.title || 'UI/UX design')}</div>
                    ${sourceTask}
                    <div class="d-flex gap-2 flex-wrap">${fileLink}${externalLink}</div>
                </div>
            </div>
        `;
    }).join('');

    return `
        <div class="design-reference-strip">
            <div class="design-reference-strip__header">
                <h6><i class="fas fa-pen-ruler me-2 text-primary"></i>UI/UX Design References</h6>
                <span class="badge bg-light text-primary border">${designArtifacts.length} reference${designArtifacts.length === 1 ? '' : 's'}</span>
            </div>
            <div class="design-reference-grid">${cards}</div>
        </div>
    `;
}

function buildDepartmentRevisionBanner(task) {
    const revision = task.department_revision;
    if (!revision || !revision.revision_notes) {
        return '';
    }

    const department = revision.department || currentDepartmentCanonical || 'Your department';
    const developer = revision.developer_name || task.assigned_to_name || 'Assigned developer';
    const responsibleSul = revision.sul_name || task.assigned_by_name || 'Department SUL';

    return `
        <div class="alert alert-warning border-warning-subtle mb-3">
            <div class="d-flex align-items-start gap-2">
                <i class="fas fa-user-check mt-1"></i>
                <div>
                    <strong>${escapeHtml(department)} owns this QA revision.</strong>
                    <div class="small mt-1">
                        Responsible developer: <strong>${escapeHtml(developer)}</strong>
                        · Review handler: <strong>${escapeHtml(responsibleSul)}</strong>
                    </div>
                    <div class="small mt-2">${escapeHtml(revision.revision_notes)}</div>
                    <div class="small text-muted mt-2">
                        Complete this correction and resubmit it to your department SUL. The workflow unlocks the next department only after approval.
                    </div>
                </div>
            </div>
        </div>
    `;
}

function buildRoadmapReadyBanner(task) {
    if (!['Frontend', 'Backend', 'QA'].includes(currentDepartmentCanonical)) {
        return '';
    }

    const blockReason = task.roadmap_start_block_reason || '';
    if (blockReason) {
        return `
            <div class="roadmap-ready-banner is-locked">
                <i class="fas fa-lock"></i>
                <div><strong>Waiting to start.</strong> ${escapeHtml(blockReason)}</div>
            </div>
        `;
    }

    return `
        <div class="roadmap-ready-banner">
            <i class="fas fa-lock-open"></i>
            <div><strong>${isQaDepartment ? 'Ready for testing.' : 'Ready to start.'}</strong> Earlier roadmap phase gates are clear, so ${isQaDepartment ? 'QA testing' : escapeHtml(currentDepartmentCanonical.toLowerCase() + ' work')} can begin.</div>
        </div>
    `;
}

function buildDepartmentPanel(task, options = {}) {
    const readOnly = Boolean(options.readOnly);
    const submission = Boolean(options.submission);
    const isUiUx = currentDepartmentCanonical === 'UI/UX';
    const isQa = currentDepartmentCanonical === 'QA';
    const canSubmitQaResults = isQa && ['in_progress', 'sul_revision', 'qa_failed'].includes(String(task.status || ''));
    const qaSubmissionHint = String(task.status || '') === 'for_review'
        ? '<div class="small text-success mt-2"><i class="fas fa-circle-check me-1"></i>Test results were submitted and are awaiting QA SUL review.</div>'
        : '<div class="small text-muted mt-2"><i class="fas fa-circle-info me-1"></i>Start testing before submitting QA results.</div>';
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
                <div class="developer-upload-field">
                    <label class="developer-upload-button" for="departmentArtifactFile">
                        <i class="fas fa-paperclip" aria-hidden="true"></i>
                        <span>Choose File</span>
                    </label>
                    <span class="developer-upload-summary" data-developer-file-summary aria-live="polite">No file chosen</span>
                    <input id="departmentArtifactFile" class="visually-hidden" type="file" accept="image/*" data-developer-file-input>
                </div>
           </div>`
        : '';
    const panelLabel = isUiUx ? 'Client Requirements' : 'Project Inputs';
    const handoffHeading = isUiUx ? 'Department Handoffs' : 'Handoffs and UI/UX Designs';
    const artifactCount = Array.isArray(task.department_artifacts) ? task.department_artifacts.length : 0;

    if (readOnly) {
        return `
            <section class="task-summary-section">
                <header class="task-summary-section__header">
                    <h6><i class="fas ${escapeAttribute(departmentProfile.icon || 'fa-briefcase')}"></i>${escapeHtml(panelLabel)}</h6>
                    <span>${escapeHtml(currentDepartment || 'Department')}</span>
                </header>
                <div class="task-summary-section__body">
                    <div class="department-modal-heading">
                        <div>
                            <h6>${escapeHtml(departmentProfile.title || 'Department Workbench')}</h6>
                            <div class="department-modal-summary">${escapeHtml(departmentProfile.summary || '')}</div>
                        </div>
                    </div>
                    <ul class="department-requirements">${requirementItems}</ul>
                    ${additionalRequirements}
                </div>
            </section>
            <section class="task-summary-section">
                <header class="task-summary-section__header">
                    <h6><i class="fas fa-folder-open"></i>${escapeHtml(handoffHeading)}</h6>
                    <span>${artifactCount} saved</span>
                </header>
                <div class="task-summary-section__body task-summary-section__body--artifacts">
                    <div id="departmentArtifactList" class="artifact-list">
                        ${renderDepartmentArtifacts(task.department_artifacts || [])}
                    </div>
                </div>
            </section>
        `;
    }

    if (submission) {
        return `
            <div class="pr-handoff-section">
                <div class="pr-handoff-section__heading">
                    <div>
                        <strong>${escapeHtml(departmentProfile.artifact_cta || 'Save Department Handoff')}</strong>
                        <span>${isUiUx ? 'Attach the final design, wireframe, and implementation notes.' : 'Add the implementation reference and notes for the next reviewer.'}</span>
                    </div>
                    <span>${artifactCount} saved</span>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="departmentArtifactTitle">Handoff title</label>
                        <input id="departmentArtifactTitle" class="form-control" placeholder="Clear name for this handoff">
                    </div>
                    ${isUiUx ? `
                        <div class="col-md-6">
                            <label class="form-label" for="departmentArtifactFile">${escapeHtml(departmentProfile.file_label || 'Design picture')}</label>
                            <div class="developer-upload-field">
                                <label class="developer-upload-button" for="departmentArtifactFile">
                                    <i class="fas fa-paperclip" aria-hidden="true"></i>
                                    <span>Choose File</span>
                                </label>
                                <span class="developer-upload-summary" data-developer-file-summary aria-live="polite">No file chosen</span>
                                <input id="departmentArtifactFile" class="visually-hidden" type="file" accept="image/*" data-developer-file-input>
                            </div>
                        </div>
                    ` : ''}
                    <div class="${isUiUx ? 'col-md-6' : 'col-12'}">
                        <label class="form-label" for="departmentArtifactUrl">
                            ${escapeHtml(departmentProfile.link_label || 'Reference link')}
                            ${isUiUx ? '<span class="text-danger" aria-hidden="true">*</span>' : ''}
                        </label>
                        <input id="departmentArtifactUrl" class="form-control" type="url" placeholder="https://..." ${isUiUx ? 'required aria-required="true"' : ''}>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="departmentArtifactNotes">${escapeHtml(departmentProfile.notes_label || 'Handoff notes')}</label>
                        <textarea id="departmentArtifactNotes" class="form-control" rows="2" placeholder="What the next reviewer or department needs to know"></textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-primary pr-save-handoff" onclick="saveSubmissionHandoff(${Number(task.id)}, this)">
                            <i class="fas fa-floppy-disk"></i> ${escapeHtml(departmentProfile.artifact_cta || 'Save Handoff')}
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    return `
        <div class="task-detail-section department-modal-panel">
            <div class="department-modal-heading">
                <div>
                    <h6><i class="fas ${escapeAttribute(departmentProfile.icon || 'fa-briefcase')} me-2 text-primary"></i>${escapeHtml(departmentProfile.title || 'Department Workbench')}</h6>
                    <div class="department-modal-summary">${escapeHtml(departmentProfile.summary || '')}</div>
                </div>
                <span class="badge bg-light text-primary border">${escapeHtml(currentDepartment || 'Department')}</span>
            </div>

            <div class="small fw-semibold mb-2">
                ${panelLabel}
                <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="${isUiUx
                    ? 'UI/UX should review these requirements before preparing designs and wireframes.'
                    : (isQa
                        ? 'Use these requirements to prepare acceptance, negative, boundary, and regression tests.'
                        : 'Use these requirements as context for this department without changing the task workflow.')}"></i>
            </div>
            <ul class="department-requirements">${requirementItems}</ul>
            ${additionalRequirements}

            <div class="row g-3 department-handoff-grid mt-1">
                <div class="col-md-6">
                    <div class="task-side-panel task-side-panel--flat mb-0">
                        <div class="task-side-heading">
                            <label class="form-label mb-0 fw-semibold">${escapeHtml(departmentProfile.artifact_cta || 'Save Handoff')}</label>
                            <small class="text-muted">${isUiUx ? 'Design, wireframe, notes' : (isQa ? 'Test evidence, defects, notes' : 'Link, notes')}</small>
                        </div>
                        <div class="mb-2">
                            <input id="departmentArtifactTitle" class="form-control form-control-sm" placeholder="${isQa ? 'QA test result title' : 'Handoff title'}">
                        </div>
                        ${uploadInput}
                        <div class="mb-2">
                            <label class="form-label">
                                ${escapeHtml(departmentProfile.link_label || 'Reference link')}
                                ${isUiUx ? '<span class="text-danger" aria-hidden="true">*</span>' : ''}
                                <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="${isUiUx
                                    ? 'Paste a Figma, FigJam, wireframe, or prototype link.'
                                    : (isQa
                                        ? 'Paste a test case, screenshot, recording, or defect reference link.'
                                        : 'Paste the reference link this department needs to hand off.')}"></i>
                            </label>
                            <input id="departmentArtifactUrl" class="form-control form-control-sm" type="url" placeholder="https://..." ${isUiUx ? 'required aria-required="true"' : ''}>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">${escapeHtml(departmentProfile.notes_label || 'Notes')}</label>
                            <textarea id="departmentArtifactNotes" class="form-control form-control-sm" rows="3" placeholder="Short handoff notes"></textarea>
                        </div>
                        <div class="department-handoff-actions">
                            <button type="button" class="btn-primary-modern task-detail-action" onclick="saveDepartmentArtifact(${Number(task.id)})" data-bs-toggle="tooltip" title="Saves department-specific handoff details without moving the task status.">
                                <i class="fas fa-floppy-disk"></i> ${escapeHtml(departmentProfile.artifact_cta || 'Save Handoff')}
                            </button>
                            ${isUiUx ? `
                                <button type="button" class="btn-primary-modern task-detail-action" onclick="submitDesignForReview(${Number(task.id)})" data-bs-toggle="tooltip" title="Auto-saves the design handoff, then submits the task to the UI/UX SUL for approval.">
                                    <i class="fas fa-paper-plane"></i> Submit Design
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
                <div class="col-md-6 d-flex">
                    <div class="task-side-panel task-side-panel--flat artifact-history-panel mb-0 w-100">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <h6 class="mb-0">${handoffHeading}</h6>
                            <span class="small text-muted">${Array.isArray(task.department_artifacts) ? task.department_artifacts.length : 0} saved</span>
                        </div>
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
        return '<p class="text-muted mb-0 small">No department handoffs or UI/UX design references saved yet.</p>';
    }

    const tableHead = `
        <div class="artifact-table-head" aria-hidden="true">
            <span>Handoff</span>
            <span>Related task</span>
            <span>Type</span>
            <span>Updated</span>
            <span>Actions</span>
        </div>
    `;

    const rows = artifacts.map((artifact) => {
        const isDesign = artifact.artifact_type === 'design_handoff';
        const isProjectDesignReference = Boolean(artifact.is_project_design_reference);
        const fileName = String(artifact.original_file_name || '');
        const artifactTitle = String(artifact.title || '').trim();
        const genericTitle = /^(design|department)\s+handoff$/i.test(artifactTitle);
        const sourceTaskTitle = String(artifact.source_task_title || '').trim();
        const displayName = (artifactTitle && !genericTitle ? artifactTitle : '')
            || (sourceTaskTitle ? `${sourceTaskTitle} design` : '')
            || fileName
            || `${artifact.source_task_title || 'Task'} handoff`;
        const lowerFileName = fileName.toLowerCase();
        const isImage = /\.(png|jpe?g|gif|webp|svg)$/.test(lowerFileName);
        const isPdf = lowerFileName.endsWith('.pdf');
        const fileIcon = isImage
            ? 'fa-file-image'
            : isPdf
                ? 'fa-file-pdf'
                : artifact.file_url
                    ? 'fa-file-lines'
                    : artifact.external_url
                        ? 'fa-link'
                        : isDesign
                            ? 'fa-pen-ruler'
                            : 'fa-clipboard-check';
        const artifactKind = isImage
            ? (artifact.external_url ? 'Image + link' : 'Image')
            : isPdf
                ? (artifact.external_url ? 'PDF + link' : 'PDF')
                : artifact.file_url
                    ? (artifact.external_url ? 'File + link' : 'File')
                    : artifact.external_url
                        ? 'Reference'
                        : 'Handoff';
        const filenameLine = fileName && fileName !== displayName
            ? `<div class="artifact-item__filename" title="${escapeAttribute(fileName)}">${escapeHtml(fileName)}</div>`
            : '';
        const relatedTask = sourceTaskTitle || 'Project-level handoff';
        const updatedAt = formatDateTime(artifact.created_at);
        const referenceType = isProjectDesignReference ? 'Project reference' : 'Task handoff';

        return `
            <div class="artifact-item">
                <div class="artifact-item__identity">
                    <span class="artifact-item__icon" aria-hidden="true"><i class="fas ${fileIcon}"></i></span>
                    <div class="artifact-item__content">
                        <div class="artifact-item__title" title="${escapeAttribute(displayName)}">${escapeHtml(displayName)}</div>
                        ${filenameLine}
                    </div>
                </div>
                <div class="artifact-item__task" title="${escapeAttribute(relatedTask)}">${escapeHtml(relatedTask)}</div>
                <span class="artifact-item__kind">${escapeHtml(artifactKind)}</span>
                <div class="artifact-item__updated">
                    <span>${escapeHtml(updatedAt)}</span>
                </div>
                <div class="artifact-item__actions">
                    <button
                        class="artifact-item__view"
                        type="button"
                        title="View handoff details"
                        aria-label="View details for ${escapeAttribute(displayName)}"
                        data-title="${escapeAttribute(displayName)}"
                        data-filename="${escapeAttribute(fileName)}"
                        data-task="${escapeAttribute(relatedTask)}"
                        data-kind="${escapeAttribute(artifactKind)}"
                        data-reference-type="${escapeAttribute(referenceType)}"
                        data-user="${escapeAttribute(artifact.user_name || 'User')}"
                        data-date="${escapeAttribute(updatedAt)}"
                        data-note="${escapeAttribute(artifact.notes || '')}"
                        data-file-url="${escapeAttribute(artifact.file_url || '')}"
                        data-external-url="${escapeAttribute(artifact.external_url || '')}"
                        data-icon="${escapeAttribute(fileIcon)}"
                        onclick="openArtifactDetails(this)"
                    >
                        <i class="fas fa-eye" aria-hidden="true"></i><span>View</span>
                    </button>
                </div>
            </div>
        `;
    }).join('');

    return tableHead + rows;
}

function ensureArtifactDetailsDrawer() {
    let drawer = document.getElementById('artifactDetailDrawer');
    if (drawer) {
        return drawer;
    }

    const backdrop = document.createElement('div');
    backdrop.id = 'artifactDetailBackdrop';
    backdrop.className = 'artifact-drawer-backdrop';
    backdrop.addEventListener('click', closeArtifactDetails);

    drawer = document.createElement('aside');
    drawer.id = 'artifactDetailDrawer';
    drawer.className = 'artifact-detail-drawer';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    drawer.setAttribute('aria-labelledby', 'artifactDetailDrawerTitle');

    document.body.append(backdrop, drawer);

    if (!window.artifactDetailsEscapeBound) {
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeArtifactDetails();
            }
        });
        window.artifactDetailsEscapeBound = true;
    }

    return drawer;
}

function openArtifactDetails(button) {
    if (!button) {
        return;
    }

    window.artifactDetailsTrigger = button;
    const drawer = ensureArtifactDetailsDrawer();
    const data = button.dataset;
    const fileAction = data.fileUrl
        ? `<a href="${escapeAttribute(data.fileUrl)}" target="_blank" rel="noopener">
                <i class="fas fa-file-arrow-up" aria-hidden="true"></i>Open uploaded file
           </a>`
        : '';
    const referenceAction = data.externalUrl
        ? `<a href="${escapeAttribute(data.externalUrl)}" target="_blank" rel="noopener">
                <i class="fas fa-link" aria-hidden="true"></i>Open reference link
           </a>`
        : '';
    const filename = data.filename
        ? `<p>${escapeHtml(data.filename)}</p>`
        : '';
    const note = data.note
        ? `<p>${escapeHtml(data.note)}</p>`
        : '<p class="text-muted">No comment was added to this handoff.</p>';

    drawer.innerHTML = `
        <header class="artifact-detail-drawer__header">
            <h5 id="artifactDetailDrawerTitle">Handoff details</h5>
            <button class="artifact-detail-drawer__close" type="button" onclick="closeArtifactDetails()" aria-label="Close handoff details">
                <i class="fas fa-xmark" aria-hidden="true"></i>
            </button>
        </header>
        <div class="artifact-detail-drawer__body">
            <div class="artifact-drawer-file">
                <span class="artifact-drawer-file__icon" aria-hidden="true"><i class="fas ${escapeAttribute(data.icon || 'fa-file-lines')}"></i></span>
                <div>
                    <h6>${escapeHtml(data.title || 'Department handoff')}</h6>
                    ${filename}
                </div>
            </div>
            <section class="artifact-drawer-section">
                <span class="artifact-drawer-section__label">Related task</span>
                <strong>${escapeHtml(data.task || 'Project-level handoff')}</strong>
            </section>
            <section class="artifact-drawer-section">
                <span class="artifact-drawer-section__label">Handoff type</span>
                <strong>${escapeHtml(data.kind || 'Handoff')} · ${escapeHtml(data.referenceType || 'Task handoff')}</strong>
            </section>
            <section class="artifact-drawer-section">
                <span class="artifact-drawer-section__label">Updated</span>
                <strong>${escapeHtml(data.user || 'User')}</strong>
                <p>${escapeHtml(data.date || '')}</p>
            </section>
            <section class="artifact-drawer-section">
                <span class="artifact-drawer-section__label">Comment</span>
                ${note}
            </section>
        </div>
        ${(fileAction || referenceAction) ? `
            <footer class="artifact-detail-drawer__actions">
                ${fileAction}${referenceAction}
            </footer>
        ` : ''}
    `;

    const backdrop = document.getElementById('artifactDetailBackdrop');
    document.body.classList.add('artifact-drawer-open');
    window.requestAnimationFrame(() => {
        backdrop?.classList.add('is-open');
        drawer.classList.add('is-open');
        drawer.querySelector('.artifact-detail-drawer__close')?.focus();
    });
}

function closeArtifactDetails() {
    const drawer = document.getElementById('artifactDetailDrawer');
    const backdrop = document.getElementById('artifactDetailBackdrop');
    drawer?.classList.remove('is-open');
    backdrop?.classList.remove('is-open');
    document.body.classList.remove('artifact-drawer-open');
    window.setTimeout(() => {
        window.artifactDetailsTrigger?.focus?.();
        window.artifactDetailsTrigger = null;
    }, 220);
}

function buildDepartmentArtifactFormData(taskId) {
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

    return formData;
}

function saveDepartmentArtifact(taskId, options = {}) {
    const reloadTask = options.reloadTask !== false;
    const silent = Boolean(options.silent);
    const wireframeInput = document.getElementById('departmentArtifactUrl');
    const wireframeUrl = String(wireframeInput?.value || '').trim();
    if ((departmentProfile.artifact_type || '') === 'design_handoff' && wireframeUrl === '') {
        const error = 'Wireframe link is required for a design handoff.';
        wireframeInput?.focus();
        if (!silent) {
            showTaskNotice('Design Handoff', error, 'error');
        }
        return $.Deferred().reject({ responseJSON: { error } }).promise();
    }

    const formData = buildDepartmentArtifactFormData(taskId);

    return $.ajax({
        url: '../../api/add-department-artifact.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (!silent) {
                showTaskNotice('Department Handoff', response.message || 'Department handoff saved.');
            }
            if (reloadTask) {
                viewTask(taskId);
            }
        },
        error: function(xhr) {
            if (!silent) {
                showTaskNotice('Department Handoff', xhr.responseJSON?.error || 'Unable to save department handoff.', 'error');
            }
        }
    });
}

async function submitDesignForReview(taskId) {
    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm({
            title: 'Confirm Design Submission',
            subject: 'Save handoff and submit design?',
            message: 'Your design handoff will be auto-saved first, then the task will move to SUL review.',
            icon: 'fas fa-circle-check fa-lg',
            confirmIcon: 'fas fa-paper-plane',
            confirmText: 'Submit Design'
        })
        : false;
    if (!confirmed) {
        return;
    }

    try {
        await saveDepartmentArtifact(taskId, { silent: true, reloadTask: false });
    } catch (xhr) {
        showTaskNotice('Design Handoff', xhr.responseJSON?.error || 'Unable to save the design handoff before submitting.', 'error');
        return;
    }

    await updateTaskStatus(taskId, 'for_review', { skipConfirm: true, openDetails: true });
}

async function submitQaResultsForReview(taskId) {
    const notesInput = document.getElementById('departmentArtifactNotes');
    const titleInput = document.getElementById('departmentArtifactTitle');
    const notes = String(notesInput?.value || '').trim();
    if (!notes) {
        showTaskNotice('QA Test Results', 'Record the executed tests, result summary, and any defects before submitting.', 'error');
        notesInput?.focus();
        return;
    }
    if (titleInput && String(titleInput.value || '').trim() === '') {
        titleInput.value = 'QA Test Results';
    }

    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm({
            title: 'Submit QA Test Results',
            subject: 'Send testing evidence to the QA SUL?',
            message: 'Your QA notes and evidence will be saved first. The task will then move to QA SUL Review.',
            icon: 'fas fa-vial-circle-check fa-lg',
            confirmIcon: 'fas fa-paper-plane',
            confirmText: 'Submit Test Results'
        })
        : false;
    if (!confirmed) return;

    try {
        await saveDepartmentArtifact(taskId, { silent: true, reloadTask: false });
    } catch (xhr) {
        showTaskNotice('QA Test Results', xhr.responseJSON?.error || 'Unable to save QA notes before submitting.', 'error');
        return;
    }

    await updateTaskStatus(taskId, 'for_review', { skipConfirm: true, skipDetails: true });
    showTaskNotice('QA Test Results', 'Testing results submitted to the QA SUL for review.');
    window.setTimeout(() => window.location.reload(), 350);
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
            showTaskNotice('Comments', 'Comment added successfully');
        }
    });
}

function addGitUpdate(taskId) {
    const branchName = $('#gitBranchName').val().trim();

    if(!branchName) {
        showTaskNotice('Git Update', 'Branch name is required for Git updates.', 'error');
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
            viewTask(taskId);

            if (data.pull_request_submitted || data.task_status === 'for_review') {
                moveKanbanTaskToReview(taskId);
                showTaskNotice('Git Update', data.message || 'Pull Request submitted. Task moved to Workflow Review.');
            }
        },
        error: function(xhr) {
            showTaskNotice('Git Update', xhr.responseJSON?.error || 'Failed to save Git update', 'error');
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

function jsStringLiteral(value) {
    return JSON.stringify(String(value ?? '')).replace(/</g, '\\u003c');
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
    card.addEventListener('click', event => {
        if (event.target.closest('button, a, input, select, textarea')) return;
        const taskId = Number(card.dataset.taskId || 0);
        if (taskId > 0) viewTask(taskId);
    });
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
    if(['To Do', 'Ready for Testing'].includes(columnTitle)) newStatus = 'todo';
    else if(['In Progress', 'Testing'].includes(columnTitle)) newStatus = 'in_progress';
    else if(['Workflow Review', 'QA SUL Review'].includes(columnTitle)) {
        showTaskNotice(
            isQaDepartment ? 'QA SUL Review' : 'Workflow Review',
            isQaDepartment
                ? 'Open the task, record QA notes and evidence, then submit the test results to the QA SUL.'
                : 'Submit a Pull Request from the task Git update form to move this task For Review.',
            'error'
        );
        return;
    }
    else if(['Completed', 'Testing Completed'].includes(columnTitle)) {
        showTaskNotice(
            isQaDepartment ? 'Testing Completed' : 'Completed Tasks',
            isQaDepartment
                ? 'Testing is completed only after the QA SUL approves the submitted results.'
                : 'Tasks are completed only after SUL review, QA testing, and Architect merge.',
            'error'
        );
        return;
    }
    
    if(newStatus) {
        updateTaskStatus(taskId, newStatus);
    }
}

const selectedTaskId = new URLSearchParams(window.location.search).get('id');
taskProjectFilter?.addEventListener('change', () => applyTaskProjectFilter({ resetScroll: true }));
applyTaskProjectFilter();
initTooltips(document);
if(selectedTaskId) {
    viewTask(selectedTaskId);
}
</script>

<?php include '../../includes/footer.php'; ?>
