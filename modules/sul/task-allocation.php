<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/methodology-roadmap.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/project-estimation.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();
$userId = (int) ($user['id'] ?? 0);
workflowEnsureSchema($conn);
methodologyRoadmapEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);
projectEstimationEnsureSchema($conn);

$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : (isset($_POST['project_id']) ? intval($_POST['project_id']) : 0);
$taskId = isset($_GET['task_id']) ? intval($_GET['task_id']) : 0;

function sulTaskAllocationProjectFilter($conn, string $projectAlias, string $department): string
{
    return projectWorkflowProjectDepartmentClause($conn, $projectAlias, $department);
}

function sulTaskAllocationFormatDeadline(?string $deadline): string
{
    $timestamp = $deadline ? strtotime($deadline) : false;
    return $timestamp ? date('M d, Y', $timestamp) : 'N/A';
}

function sulTaskAllocationDateInputValue(?string $deadline): string
{
    $timestamp = $deadline ? strtotime($deadline) : false;
    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function sulTaskAllocationAssignmentDeadlineValue(?string $deadline, ?string $roadmapStart, ?string $roadmapEnd): string
{
    $deadlineValue = sulTaskAllocationDateInputValue($deadline);
    $startValue = sulTaskAllocationDateInputValue($roadmapStart);
    $endValue = sulTaskAllocationDateInputValue($roadmapEnd);

    if ($deadlineValue === '') {
        return $endValue !== '' ? $endValue : $startValue;
    }

    if ($startValue !== '' && $deadlineValue < $startValue) {
        return $endValue !== '' ? $endValue : $startValue;
    }

    if ($endValue !== '' && $deadlineValue > $endValue) {
        return $endValue;
    }

    return $deadlineValue;
}

function sulTaskAllocationDaysLeft(?string $deadline): ?int
{
    $timestamp = $deadline ? strtotime($deadline) : false;
    if ($timestamp === false) {
        return null;
    }

    return (int) ceil(($timestamp - time()) / 86400);
}

function sulTaskAllocationExcerpt(?string $text, int $limit = 120): string
{
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
    if ($clean === '') {
        return 'No description provided.';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean) <= $limit) {
            return $clean;
        }
        return mb_substr($clean, 0, $limit - 1) . '…';
    }

    if (strlen($clean) <= $limit) {
        return $clean;
    }

    return substr($clean, 0, $limit - 1) . '…';
}

function sulTaskAllocationBulkEntityCountSelect(string $taskAlias = 't'): string
{
    $taskAlias = preg_replace('/[^A-Za-z0-9_]/', '', $taskAlias) ?: 't';

    return "(
        SELECT COUNT(DISTINCT mt_bulk.task_id)
        FROM methodology_tasks mt_current
        INNER JOIN methodology_tasks mt_bulk
            ON mt_bulk.project_id = mt_current.project_id
           AND mt_bulk.developer_entity_key = mt_current.developer_entity_key
           AND mt_bulk.task_id IS NOT NULL
           AND mt_bulk.task_id > 0
        WHERE mt_current.project_id = {$taskAlias}.project_id
          AND mt_current.task_id = {$taskAlias}.id
          AND COALESCE(mt_current.developer_entity_key, '') <> ''
    ) AS bulk_entity_task_count";
}

function sulTaskAllocationBulkEntityBadge(array $task): string
{
    $bulkCount = (int) ($task['bulk_entity_task_count'] ?? 0);
    if ($bulkCount <= 1) {
        return '';
    }

    $label = 'Bulk assignment: ' . $bulkCount . ' features';
    $title = 'Architect bulk placement: these features share one roadmap developer/entity and should move together.';

    return '<span class="bulk-entity-badge" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="fas fa-layer-group" aria-hidden="true"></i> '
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</span>';
}

function sulTaskAllocationRoadmapEntityRows(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $projectId,
    int $taskId,
    array $currentRoadmapTask,
    string $department,
    ?string &$error
): array {
    $error = null;
    $entityKey = trim((string) ($currentRoadmapTask['developer_entity_key'] ?? ''));
    if ($entityKey === '') {
        return [$taskId => $currentRoadmapTask];
    }

    $safeEntityKey = $conn->real_escape_string($entityKey);
    $entityTaskRows = $conn->query("
        SELECT DISTINCT task_id
        FROM methodology_tasks
        WHERE project_id = {$projectId}
          AND developer_entity_key = '{$safeEntityKey}'
          AND task_id IS NOT NULL
          AND task_id > 0
        ORDER BY task_id ASC
    ");

    if (!$entityTaskRows) {
        $error = 'Unable to load the roadmap developer entity.';
        return [];
    }

    $rows = [];
    while ($entityTask = $entityTaskRows->fetch_assoc()) {
        $entityTaskId = (int) ($entityTask['task_id'] ?? 0);
        if ($entityTaskId <= 0) {
            continue;
        }

        $entityCurrentRoadmapTask = projectWorkflowCurrentRoadmapTask($conn, $projectId, $entityTaskId, $department);
        if (!$entityCurrentRoadmapTask || trim((string) ($entityCurrentRoadmapTask['developer_entity_key'] ?? '')) !== $entityKey) {
            $error = 'Every task in this roadmap developer entity must be current and unlocked before assignment.';
            return [];
        }

        if ((int) ($entityCurrentRoadmapTask['assigned_developer_id'] ?? 0) > 0) {
            $error = 'This roadmap developer entity already has a developer assigned.';
            return [];
        }

        $rows[$entityTaskId] = $entityCurrentRoadmapTask;
    }

    return $rows !== [] ? $rows : [$taskId => $currentRoadmapTask];
}

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
$sulDepartment = trim((string) ($user['department'] ?? ''));
$sulDepartmentLabel = projectWorkflowDepartmentLabel($sulDepartment);
$sulDepartmentEscaped = $conn->real_escape_string($sulDepartment);
$sulMissingDepartment = $sulDepartment === '';
$isExpertDepartmentSul = $sulDepartmentLabel === 'Expert Department';
$canManageExpertWorkflow = !$sulMissingDepartment && $isExpertDepartmentSul;
$canManageDeveloperWorkflow = !$sulMissingDepartment && !$isExpertDepartmentSul;
$sulUserDepartmentFilter = $sulDepartment !== '' ? ' AND ' . projectWorkflowUserDepartmentClause($conn, 'u', $sulDepartment) : ' AND 1 = 0';
$sulProjectFilter = sulTaskAllocationProjectFilter($conn, 'p', $sulDepartment);
$sulTaskDepartmentFilter = $sulDepartment !== '' ? ' AND ' . projectWorkflowTaskDepartmentClause($conn, 't', $sulDepartment, true) : ' AND 1 = 0';
$sulCurrentRoadmapTaskFilter = $sulDepartment !== '' ? ' AND ' . projectWorkflowCurrentRoadmapTaskClause($conn, 't', $sulDepartment, true) : ' AND 1 = 0';
$sulRoadmapTaskDepartmentFilter = $sulDepartment !== '' ? projectWorkflowMethodologyTaskDepartmentClause($conn, 'mt_department_scope', $sulDepartment) : '1 = 0';

if ($sulMissingDepartment) {
    $_SESSION['error'] = 'Your SUL account must be assigned to a department before managing task allocation.';
}

// If a dashboard link points to a specific task, open that task's project context.
if (!$projectId && $taskId > 0) {
    $taskProject = $conn->prepare("SELECT project_id FROM tasks WHERE id = ? LIMIT 1");
    $taskProject->bind_param("i", $taskId);
    $taskProject->execute();
    $taskProjectRow = $taskProject->get_result()->fetch_assoc();
    $taskProject->close();
    $projectId = (int) ($taskProjectRow['project_id'] ?? 0);
}

$ganttProjectOptions = $conn->query("
    SELECT p.id, p.name,
           COUNT(CASE
               WHEN " . ($hasSalesApproval ? "t.sales_approved = 1" : "1 = 1") . "
                " . ($hasSalesChoice ? "AND t.sales_estimate_days IS NOT NULL" : "") . "
               THEN t.id
               ELSE NULL
           END) AS approved_task_count
    FROM projects p
    LEFT JOIN tasks t ON t.project_id = p.id
    WHERE p.status != 'completed'
      AND {$sulProjectFilter}
    GROUP BY p.id, p.name
    ORDER BY p.created_at DESC
");

// Get project details
$project = null;
if($projectId) {
    $project = $conn->query("
        SELECT p.*, c.name as client_name, c.email as client_email 
        FROM projects p
        JOIN clients c ON p.client_id = c.id
        WHERE p.id = $projectId
          AND {$sulProjectFilter}
    ")->fetch_assoc();
}

if ($projectId && $project) {
    projectWorkflowSyncRoadmap($conn, $projectId);
}

$ganttProjectData = null;
$canShowGantt = false;
if ($projectId && $project) {
    if ($hasSalesApproval) {
        $choiceFilter = $hasSalesChoice ? " AND sales_estimate_days IS NOT NULL" : "";
        $approvedCount = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE project_id = $projectId AND sales_approved = 1{$choiceFilter}")->fetch_assoc()['count'] ?? 0;
        $canShowGantt = ((int) $approvedCount) > 0;
    } else {
        $canShowGantt = true;
    }

    if ($canShowGantt) {
        $ganttTasks = [];
        if ($hasProjectPhases && !empty($project['project_phases'])) {
            $phases = json_decode($project['project_phases'], true);
            if (is_array($phases)) {
                foreach ($phases as $idx => $phase) {
                    if (empty($phase['start_date']) || empty($phase['end_date'])) {
                        continue;
                    }
                    $ganttTasks[] = [
                        'id' => 'phase-' . $projectId . '-' . $idx,
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

        $taskApprovalFilter = $hasSalesApproval ? " AND sales_approved = 1" : "";
        $taskChoiceFilter = $hasSalesChoice ? " AND sales_estimate_days IS NOT NULL" : "";
        $taskRows = $conn->query("SELECT id, title, started_at, deadline, estimated_days_min, estimated_days_likely, estimated_days_max, sales_estimate_choice, sales_estimate_days FROM tasks WHERE project_id = $projectId{$taskApprovalFilter}{$taskChoiceFilter}");
        while ($task = $taskRows->fetch_assoc()) {
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
                $duration = (int) ($task['sales_estimate_days'] ?? 0);
                if ($duration <= 0) {
                    $duration = (int) ($task['estimated_days_likely'] ?? 0);
                }
                if ($duration <= 0) {
                    $duration = (int) ($task['estimated_days_min'] ?? 0);
                }
                if ($duration <= 0) {
                    $duration = (int) ($task['estimated_days_max'] ?? 0);
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
                'min_days' => (int) ($task['estimated_days_min'] ?? 0),
                'likely_days' => (int) ($task['estimated_days_likely'] ?? 0),
                'max_days' => (int) ($task['estimated_days_max'] ?? 0),
                'sales_estimate_choice' => $task['sales_estimate_choice'] ?? null,
                'sales_estimate_days' => (int) ($task['sales_estimate_days'] ?? 0),
                'is_phase' => false
            ];
        }

        $ganttProjectData = [
            'project_name' => $project['name'],
            'tasks' => $ganttTasks
        ];
    }
}

$selectedExpertProjectFilter = $projectId > 0 ? " AND t.project_id = {$projectId}" : " AND 1 = 0";
$selectedExpertEstimationProjectFilter = $projectId > 0 ? " AND e.project_id = {$projectId}" : " AND 1 = 0";
$selectedProjectAllocationFilter = $projectId > 0 ? " AND p.id = {$projectId}" : " AND 1 = 0";
$bulkEntityCountSelect = sulTaskAllocationBulkEntityCountSelect('t');

// Get all unassigned tasks (need expert assignment)
$unassignedTasks = $conn->query("
    SELECT t.*, p.name as project_name, p.client_id,
           {$bulkEntityCountSelect},
           (SELECT COUNT(*) FROM feature_estimations fe WHERE fe.task_id = t.id AND fe.status = 'completed') as has_estimation
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE (t.assigned_to IS NULL OR t.assigned_to = 0) AND t.status = 'todo'
      AND {$sulProjectFilter}
      {$selectedExpertProjectFilter}
    ORDER BY 
        CASE t.priority 
            WHEN 'critical' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
        END,
        t.deadline ASC
");

// Get tasks pending expert estimation (assigned to expert but not yet estimated)
$pendingEstimationTasks = $conn->query("
    SELECT t.*, u.full_name as expert_name, p.name as project_name,
           {$bulkEntityCountSelect},
           (SELECT COUNT(*) FROM feature_estimations fe WHERE fe.task_id = t.id AND fe.status = 'completed') as has_estimation
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    WHERE t.status = 'todo' 
      AND t.assigned_to IS NOT NULL 
      AND t.assigned_to != 0
      AND u.role = 'expert'
      {$sulUserDepartmentFilter}
      AND {$sulProjectFilter}
      {$selectedExpertProjectFilter}
      AND (SELECT COUNT(*) FROM feature_estimations fe WHERE fe.task_id = t.id AND fe.status = 'completed') = 0
    ORDER BY t.deadline ASC
");

// Get tasks ready for review (expert has submitted estimation)
$salesApprovalFilter = $hasSalesApproval ? " AND t.sales_approved = 1" : "";
if ($hasSalesChoice) {
    $salesApprovalFilter .= " AND t.sales_estimate_days IS NOT NULL";
}
$developerReadyApprovalFilter = ($hasSalesApproval ? " AND t.sales_approved = 1" : "") . ($hasSalesChoice ? " AND t.sales_estimate_days IS NOT NULL" : "");
$selectedDeveloperProjectFilter = $projectId > 0 ? " AND t.project_id = {$projectId}" : " AND 1 = 0";
$reviewTasks = $conn->query("
    SELECT t.*, u.full_name as expert_name, p.name as project_name,
           {$bulkEntityCountSelect},
           fe.min_days, fe.likely_days, fe.max_days, fe.expectation_description,
           (SELECT COUNT(*) FROM feature_estimations fe2 WHERE fe2.task_id = t.id AND fe2.status = 'completed') as has_estimation
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    LEFT JOIN feature_estimations fe ON t.id = fe.task_id AND fe.status = 'completed'
    WHERE t.status = 'review'{$salesApprovalFilter}
      {$sulUserDepartmentFilter}
      AND {$sulProjectFilter}
      {$selectedExpertProjectFilter}
    ORDER BY t.deadline ASC
");

$projectEstimationReviews = $conn->query("
    SELECT e.*, p.name AS project_name, p.sales_budget, c.name AS client_name, u.full_name AS expert_name
    FROM expert_project_estimations e
    JOIN projects p ON p.id = e.project_id
    JOIN clients c ON c.id = p.client_id
    JOIN users u ON u.id = e.expert_id
    WHERE e.status = 'submitted'
      AND {$sulProjectFilter}
      {$selectedExpertEstimationProjectFilter}
    ORDER BY e.submitted_at ASC, e.id ASC
");

// Get tasks ready for developer assignment (approved, waiting for developer)
$readyForDevTasks = $conn->query("
    SELECT t.*, u.full_name as expert_name, p.name as project_name,
           {$bulkEntityCountSelect},
           fe.min_days, fe.likely_days, fe.max_days,
           (
               SELECT mt_department_scope.start_date
               FROM methodology_tasks mt_department_scope
               WHERE mt_department_scope.project_id = t.project_id
                 AND mt_department_scope.task_id = t.id
                 AND {$sulRoadmapTaskDepartmentFilter}
               ORDER BY mt_department_scope.start_date ASC, mt_department_scope.id ASC
               LIMIT 1
           ) AS department_roadmap_start,
           (
               SELECT mt_department_scope.end_date
               FROM methodology_tasks mt_department_scope
               WHERE mt_department_scope.project_id = t.project_id
                 AND mt_department_scope.task_id = t.id
                 AND {$sulRoadmapTaskDepartmentFilter}
               ORDER BY mt_department_scope.start_date ASC, mt_department_scope.id ASC
               LIMIT 1
           ) AS department_roadmap_end
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    JOIN feature_estimations fe ON t.id = fe.task_id AND fe.status = 'completed'
    WHERE (
          (t.status = 'todo' AND (t.assigned_to IS NULL OR u.role = 'expert'))
          OR (t.status = 'completed' AND t.architect_reviewed_at IS NULL AND t.merged_at IS NULL)
      )
      AND {$sulProjectFilter}
      {$selectedDeveloperProjectFilter}
      {$sulCurrentRoadmapTaskFilter}
      AND (SELECT COUNT(*) FROM feature_estimations fe2 WHERE fe2.task_id = t.id AND fe2.status = 'completed') > 0{$developerReadyApprovalFilter}
    ORDER BY t.deadline ASC
");

// Get tasks assigned to developers (in progress)
$inProgressTasks = $conn->query("
    SELECT t.*, u.full_name as developer_name, p.name as project_name,
           {$bulkEntityCountSelect}
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    WHERE t.status = 'in_progress'
      {$sulUserDepartmentFilter}
      AND {$sulProjectFilter}
      {$selectedDeveloperProjectFilter}
    ORDER BY t.deadline ASC
");

// Get developer Pull Requests waiting for SUL code review
$codeReviewTasks = $conn->query("
    SELECT t.*, u.full_name as developer_name, p.name as project_name,
           {$bulkEntityCountSelect},
           latest_git.branch_name, latest_git.commit_hash, latest_git.pull_request_url, latest_git.repository_url, latest_git.notes AS git_notes
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    LEFT JOIN task_git_updates latest_git ON latest_git.id = (
        SELECT tgu.id
        FROM task_git_updates tgu
        WHERE tgu.task_id = t.id
        ORDER BY tgu.created_at DESC, tgu.id DESC
        LIMIT 1
    )
    WHERE t.status = 'for_review'
      {$sulUserDepartmentFilter}
      AND {$sulProjectFilter}
      {$selectedDeveloperProjectFilter}
    ORDER BY t.pr_submitted_at ASC, t.updated_at ASC
");

// Get completed tasks
$completedTasks = $conn->query("
    SELECT t.*, u.full_name as assigned_to_name, p.name as project_name,
           {$bulkEntityCountSelect}
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    WHERE t.status = 'completed'
      AND (t.architect_reviewed_at IS NOT NULL OR t.merged_at IS NOT NULL)
      {$sulUserDepartmentFilter}
      AND {$sulProjectFilter}
      {$selectedDeveloperProjectFilter}
    ORDER BY t.completed_at DESC
    LIMIT 10
");

// Get available experts
$experts = $conn->query("
    SELECT u.id, u.full_name, 
           COUNT(t.id) as assigned_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
    WHERE u.role = 'expert' AND u.status = 'active'
      {$sulUserDepartmentFilter}
    GROUP BY u.id
    ORDER BY assigned_tasks ASC
");

// Projects with unassigned feature tasks
$projectsForAllocation = $conn->query("
    SELECT p.id, p.name, COUNT(t.id) as unassigned_count
    FROM projects p
    JOIN tasks t ON t.project_id = p.id
    WHERE t.task_type = 'feature'
      AND (t.assigned_to IS NULL OR t.assigned_to = 0)
      AND t.status = 'todo'
      AND {$sulProjectFilter}
      {$selectedProjectAllocationFilter}
    GROUP BY p.id, p.name
    ORDER BY p.created_at DESC
");

$expertProjectOptions = [];
if ($canManageExpertWorkflow) {
    $expertProjectOptionsResult = $conn->query("
        SELECT
            p.id,
            p.name,
            (
                SELECT COUNT(DISTINCT t.id)
                FROM tasks t
                WHERE t.project_id = p.id
                  AND (t.assigned_to IS NULL OR t.assigned_to = 0)
                  AND t.status = 'todo'
            ) AS unassigned_count,
            (
                SELECT COUNT(DISTINCT t.id)
                FROM tasks t
                JOIN users u ON t.assigned_to = u.id
                WHERE t.project_id = p.id
                  AND t.status = 'todo'
                  AND t.assigned_to IS NOT NULL
                  AND t.assigned_to != 0
                  AND u.role = 'expert'
                  {$sulUserDepartmentFilter}
                  AND (SELECT COUNT(*) FROM feature_estimations fe WHERE fe.task_id = t.id AND fe.status = 'completed') = 0
            ) AS pending_count,
            (
                SELECT COUNT(DISTINCT t.id)
                FROM tasks t
                JOIN users u ON t.assigned_to = u.id
                WHERE t.project_id = p.id
                  AND t.status = 'review'{$salesApprovalFilter}
                  {$sulUserDepartmentFilter}
            ) AS review_count,
            (
                SELECT COUNT(DISTINCT e.id)
                FROM expert_project_estimations e
                WHERE e.project_id = p.id
                  AND e.status = 'submitted'
            ) AS project_review_count
        FROM projects p
        WHERE p.status != 'completed'
          AND {$sulProjectFilter}
        ORDER BY p.name ASC
    ");

    if ($expertProjectOptionsResult) {
        while ($expertProjectOption = $expertProjectOptionsResult->fetch_assoc()) {
            $expertProjectOptions[] = $expertProjectOption;
        }
    }
}

$expertProjectOptionTaskCount = 0;
foreach ($expertProjectOptions as $expertProjectOption) {
    $expertProjectOptionTaskCount += (int) ($expertProjectOption['unassigned_count'] ?? 0)
        + (int) ($expertProjectOption['pending_count'] ?? 0)
        + (int) ($expertProjectOption['review_count'] ?? 0)
        + (int) ($expertProjectOption['project_review_count'] ?? 0);
}
$expertReviewCount = $reviewTasks->num_rows + $projectEstimationReviews->num_rows;
$selectedExpertTaskCount = $unassignedTasks->num_rows + $pendingEstimationTasks->num_rows + $expertReviewCount;
$expertAllocationBadgeCount = ($projectId && $project) ? $selectedExpertTaskCount : $expertProjectOptionTaskCount;

// Get available developers
$developers = $conn->query("
    SELECT u.id, u.full_name, 
           COUNT(t.id) as assigned_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
    WHERE u.role = 'developer' AND u.status = 'active'
      {$sulUserDepartmentFilter}
    GROUP BY u.id
    ORDER BY assigned_tasks ASC
");

$developerProjectOptions = [];
if ($canManageDeveloperWorkflow) {
    $developerProjectOptionsResult = $conn->query("
        SELECT
            p.id,
            p.name,
            (
                SELECT COUNT(DISTINCT t.id)
                FROM tasks t
                LEFT JOIN users current_assignee ON current_assignee.id = t.assigned_to
                WHERE t.project_id = p.id
                  AND (
                      (t.status = 'todo' AND (t.assigned_to IS NULL OR current_assignee.role = 'expert'))
                      OR (t.status = 'completed' AND t.architect_reviewed_at IS NULL AND t.merged_at IS NULL)
                  )
                  {$sulCurrentRoadmapTaskFilter}
                  {$developerReadyApprovalFilter}
                  AND EXISTS (
                      SELECT 1
                      FROM feature_estimations fe_ready
                      WHERE fe_ready.task_id = t.id
                        AND fe_ready.status = 'completed'
                  )
            ) AS ready_count,
            (
                SELECT COUNT(DISTINCT t.id)
                FROM tasks t
                JOIN users u ON t.assigned_to = u.id
                WHERE t.project_id = p.id
                  AND t.status = 'in_progress'
                  {$sulUserDepartmentFilter}
            ) AS in_progress_count,
            (
                SELECT COUNT(DISTINCT t.id)
                FROM tasks t
                JOIN users u ON t.assigned_to = u.id
                WHERE t.project_id = p.id
                  AND t.status = 'for_review'
                  {$sulUserDepartmentFilter}
            ) AS review_count
        FROM projects p
        WHERE p.status != 'completed'
          AND {$sulProjectFilter}
        ORDER BY p.name ASC
    ");

    if ($developerProjectOptionsResult) {
        while ($developerProjectOption = $developerProjectOptionsResult->fetch_assoc()) {
            $developerProjectOptions[] = $developerProjectOption;
        }
    }
}

$developerProjectOptionTaskCount = 0;
foreach ($developerProjectOptions as $developerProjectOption) {
    $developerProjectOptionTaskCount += (int) ($developerProjectOption['ready_count'] ?? 0)
        + (int) ($developerProjectOption['in_progress_count'] ?? 0)
        + (int) ($developerProjectOption['review_count'] ?? 0);
}
$selectedDeveloperTaskCount = $readyForDevTasks->num_rows + $inProgressTasks->num_rows + $codeReviewTasks->num_rows;
$developerAllocationBadgeCount = ($projectId && $project) ? $selectedDeveloperTaskCount : $developerProjectOptionTaskCount;

// Handle task operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Assign task to expert
    if (isset($_POST['assign_to_expert'])) {
        if (!$canManageExpertWorkflow) {
            $_SESSION['error'] = "Only the Expert Department SUL can assign tasks to experts.";
            header("Location: task-allocation.php");
            exit();
        }

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $expertId = (int) ($_POST['expert_id'] ?? 0);

        if ($taskId <= 0 || $expertId <= 0) {
            $_SESSION['error'] = "Please select a valid task and expert.";
            header("Location: task-allocation.php");
            exit();
        }

        $expertDepartmentSql = $sulDepartment !== '' ? ' AND ' . projectWorkflowUserDepartmentClause($conn, 'users', $sulDepartment) : ' AND 1 = 0';
        $expertCheck = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'expert' AND status = 'active'{$expertDepartmentSql}");
        $expertCheck->bind_param("i", $expertId);
        $expertCheck->execute();
        $validExpert = $expertCheck->get_result()->num_rows > 0;
        $expertCheck->close();

        $taskCheck = $conn->prepare("
            SELECT t.id, t.project_id
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
              AND t.status = 'todo'
              AND (t.assigned_to IS NULL OR t.assigned_to = 0)
              AND {$sulProjectFilter}
        ");
        $taskCheck->bind_param("i", $taskId);
        $taskCheck->execute();
        $validTaskRow = $taskCheck->get_result()->fetch_assoc();
        $validTask = (bool) $validTaskRow;
        $taskCheck->close();

        if (!$validExpert || !$validTask) {
            $_SESSION['error'] = "Assignment cannot proceed. Check the selected task and expert.";
            header("Location: task-allocation.php");
            exit();
        }

        $stmt = $conn->prepare("UPDATE tasks SET assigned_to = ?, status = 'todo' WHERE id = ?");
        $stmt->bind_param("ii", $expertId, $taskId);
        
        if ($stmt->execute()) {
            $assignmentProjectId = (int) ($validTaskRow['project_id'] ?? $projectId);
            $taskDetails = $conn->query("SELECT title FROM tasks WHERE id = $taskId")->fetch_assoc();
            
            createNotification(
                (int) $expertId,
                "New Task Assignment: " . $taskDetails['title'],
                "You have been assigned a new task for estimation.",
                'task',
                'tasks',
                (int) $taskId,
                BASE_URL . 'modules/expert/feature-estimation.php'
            );
            
            $_SESSION['success'] = "Task assigned to Expert successfully";
            header("Location: task-allocation.php?project_id=" . $assignmentProjectId);
            exit();
        } else {
            $_SESSION['error'] = "Failed to assign task";
        }
    }

    // Assign project to expert (bulk features)
    if (isset($_POST['assign_project_to_expert'])) {
        if (!$canManageExpertWorkflow) {
            $_SESSION['error'] = "Only the Expert Department SUL can assign projects to experts.";
            header("Location: task-allocation.php?project_id=" . $projectId);
            exit();
        }

        $projectId = (int) ($_POST['project_id'] ?? 0);
        $expertId = (int) ($_POST['expert_id'] ?? 0);
        if ($projectId <= 0 || $expertId <= 0) {
            $_SESSION['error'] = "Please select a valid project and expert.";
            header("Location: task-allocation.php");
            exit();
        }

        $expertDepartmentSql = $sulDepartment !== '' ? ' AND ' . projectWorkflowUserDepartmentClause($conn, 'users', $sulDepartment) : ' AND 1 = 0';
        $expertCheck = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'expert' AND status = 'active'{$expertDepartmentSql}");
        $expertCheck->bind_param("i", $expertId);
        $expertCheck->execute();
        $validExpert = $expertCheck->get_result()->num_rows > 0;
        $expertCheck->close();

        if (!$validExpert) {
            $_SESSION['error'] = "Please select an active expert.";
            header("Location: task-allocation.php");
            exit();
        }

        $projectCheck = $conn->prepare("SELECT p.id FROM projects p WHERE p.id = ? AND {$sulProjectFilter} LIMIT 1");
        $projectCheck->bind_param('i', $projectId);
        $projectCheck->execute();
        $validProjectForSul = $projectCheck->get_result()->num_rows > 0;
        $projectCheck->close();

        if (!$validProjectForSul) {
            $_SESSION['error'] = "This project is not assigned to your SUL department.";
            header("Location: task-allocation.php");
            exit();
        }

        if ($projectId > 0 && $expertId > 0) {
            $stmt = $conn->prepare("
                UPDATE tasks 
                SET assigned_to = ?, status = 'todo' 
                WHERE project_id = ? 
                  AND task_type = 'feature'
                  AND (assigned_to IS NULL OR assigned_to = 0)
                  AND status = 'todo'
            ");
            $stmt->bind_param("ii", $expertId, $projectId);

            if ($stmt->execute()) {
                $projectDetails = $conn->query("SELECT name FROM projects WHERE id = $projectId")->fetch_assoc();
                createNotification(
                    (int) $expertId,
                    "Project Assigned for Estimation: " . ($projectDetails['name'] ?? 'Project'),
                    "You have been assigned features to estimate for this project.",
                    'project',
                    'projects',
                    (int) $projectId,
                    BASE_URL . 'modules/expert/feature-estimation.php'
                );
                $_SESSION['success'] = "Project assigned to Expert successfully";
            } else {
                $_SESSION['error'] = "Failed to assign project to Expert";
            }
            header("Location: task-allocation.php");
            exit();
        }
    }
    
    // Assign task to developer (after estimation approved)
    if (isset($_POST['assign_to_developer'])) {
        if (!$canManageDeveloperWorkflow) {
            $_SESSION['error'] = "Developer assignment is available only for department SULs outside Expert Department.";
            header("Location: task-allocation.php");
            exit();
        }

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $developerId = (int) ($_POST['developer_id'] ?? 0);
        $deadline = trim((string) ($_POST['deadline'] ?? ''));

        if ($taskId <= 0 || $developerId <= 0 || $deadline === '') {
            $_SESSION['error'] = "Please select a valid task, developer, and deadline.";
            header("Location: task-allocation.php");
            exit();
        }

        $deadlineDate = DateTime::createFromFormat('Y-m-d', $deadline);
        $deadlineErrors = DateTime::getLastErrors();
        if (
            !$deadlineDate
            || ($deadlineErrors !== false && ($deadlineErrors['warning_count'] > 0 || $deadlineErrors['error_count'] > 0))
            || $deadlineDate->format('Y-m-d') !== $deadline
        ) {
            $_SESSION['error'] = "Please enter a valid assignment deadline.";
            header("Location: task-allocation.php");
            exit();
        }

        $developerDepartmentSql = $sulDepartment !== '' ? ' AND ' . projectWorkflowUserDepartmentClause($conn, 'users', $sulDepartment) : ' AND 1 = 0';
        $developerCheck = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'developer' AND status = 'active'{$developerDepartmentSql}");
        $developerCheck->bind_param("i", $developerId);
        $developerCheck->execute();
        $validDeveloper = $developerCheck->get_result()->num_rows > 0;
        $developerCheck->close();

        $approvalSql = $hasSalesApproval ? " AND sales_approved = 1" : "";
        $choiceSql = $hasSalesChoice ? " AND sales_estimate_days IS NOT NULL" : "";
        $taskCheck = $conn->prepare("
            SELECT t.id, t.project_id
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            LEFT JOIN users current_assignee ON current_assignee.id = t.assigned_to
            WHERE t.id = ?
              AND (
                  (t.status = 'todo' AND (t.assigned_to IS NULL OR current_assignee.role = 'expert'))
                  OR (t.status = 'completed' AND t.architect_reviewed_at IS NULL AND t.merged_at IS NULL)
              )
              AND {$sulProjectFilter}
              {$sulCurrentRoadmapTaskFilter}
              {$approvalSql}{$choiceSql}
              AND EXISTS (
                  SELECT 1
                  FROM feature_estimations fe_ready
                  WHERE fe_ready.task_id = t.id
                    AND fe_ready.status = 'completed'
              )
        ");
        $taskCheck->bind_param("i", $taskId);
        $taskCheck->execute();
        $validTaskRow = $taskCheck->get_result()->fetch_assoc();
        $validTask = (bool) $validTaskRow;
        $taskCheck->close();

        if (!$validDeveloper || !$validTask) {
            $_SESSION['error'] = "Developer assignment cannot proceed. Check approval status and selected developer.";
            header("Location: task-allocation.php");
            exit();
        }

        $assignmentProjectId = (int) ($validTaskRow['project_id'] ?? 0);
        $roadmapPhaseCount = (int) (($conn->query("SELECT COUNT(*) AS total FROM methodology_phases WHERE project_id = {$assignmentProjectId}")->fetch_assoc()['total'] ?? 0));
        $currentRoadmapTask = null;
        $assignmentTaskIds = [$taskId => $taskId];
        $assignmentRoadmapTaskIds = [];
        $assignmentDepartmentProgressByTaskId = [];
        if ($roadmapPhaseCount > 0) {
            $currentRoadmapTask = projectWorkflowCurrentRoadmapTask($conn, $assignmentProjectId, $taskId, $sulDepartment);
            if (!$currentRoadmapTask) {
                $_SESSION['error'] = "This task is waiting for another roadmap department before your SUL allocation can start.";
                header("Location: task-allocation.php");
                exit();
            }

            $entityError = null;
            $roadmapEntityRows = sulTaskAllocationRoadmapEntityRows($conn, $assignmentProjectId, $taskId, $currentRoadmapTask, $sulDepartment, $entityError);
            if ($entityError !== null) {
                $_SESSION['error'] = $entityError;
                header("Location: task-allocation.php");
                exit();
            }

            foreach ($roadmapEntityRows as $entityTaskId => $roadmapEntityRow) {
                $roadmapStart = DateTime::createFromFormat('Y-m-d', (string) ($roadmapEntityRow['start_date'] ?? ''));
                $roadmapEnd = DateTime::createFromFormat('Y-m-d', (string) ($roadmapEntityRow['end_date'] ?? ''));
                if (($roadmapStart && $deadlineDate < $roadmapStart) || ($roadmapEnd && $deadlineDate > $roadmapEnd)) {
                    $_SESSION['error'] = "The developer deadline must stay inside the methodology task window: "
                        . date('M d, Y', strtotime((string) $roadmapEntityRow['start_date']))
                        . " to "
                        . date('M d, Y', strtotime((string) $roadmapEntityRow['end_date']))
                        . ".";
                    header("Location: task-allocation.php");
                    exit();
                }

                $assignmentTaskIds[(int) $entityTaskId] = (int) $entityTaskId;
                $roadmapEntityRowId = (int) ($roadmapEntityRow['id'] ?? 0);
                if ($roadmapEntityRowId > 0) {
                    $assignmentRoadmapTaskIds[$roadmapEntityRowId] = $roadmapEntityRowId;
                }
                $departmentProgressId = (int) ($roadmapEntityRow['department_progress_id'] ?? 0);
                if ($departmentProgressId > 0) {
                    $assignmentDepartmentProgressByTaskId[$departmentProgressId] = (int) $entityTaskId;
                }
            }

            $assignmentTaskIdList = implode(',', array_map('intval', array_values($assignmentTaskIds)));
            $readyRows = $conn->query("
                SELECT t.id
                FROM tasks t
                JOIN projects p ON p.id = t.project_id
                LEFT JOIN users current_assignee ON current_assignee.id = t.assigned_to
                WHERE t.id IN ({$assignmentTaskIdList})
                  AND (
                      (t.status = 'todo' AND (t.assigned_to IS NULL OR current_assignee.role = 'expert'))
                      OR (t.status = 'completed' AND t.architect_reviewed_at IS NULL AND t.merged_at IS NULL)
                  )
                  AND {$sulProjectFilter}
                  {$sulCurrentRoadmapTaskFilter}
                  {$approvalSql}{$choiceSql}
                  AND EXISTS (
                      SELECT 1
                      FROM feature_estimations fe_ready
                      WHERE fe_ready.task_id = t.id
                        AND fe_ready.status = 'completed'
                  )
            ");
            $readyTaskIds = [];
            if (!$readyRows) {
                $_SESSION['error'] = "Unable to verify every task in this roadmap developer entity.";
                header("Location: task-allocation.php");
                exit();
            }
            while ($readyRow = $readyRows->fetch_assoc()) {
                $readyTaskIds[(int) $readyRow['id']] = true;
            }
            if (count($readyTaskIds) !== count($assignmentTaskIds)) {
                $_SESSION['error'] = "Every task in this roadmap developer entity must be approved and ready before assignment.";
                header("Location: task-allocation.php");
                exit();
            }
        }

        $assignmentTaskIdList = implode(',', array_map('intval', array_values($assignmentTaskIds)));
        
        $stmt = $conn->prepare("
            UPDATE tasks
            SET assigned_to = ?,
                assigned_by = ?,
                status = 'todo',
                deadline = ?,
                completed_at = NULL,
                pr_submitted_at = NULL,
                sul_reviewed_by = NULL,
                sul_reviewed_at = NULL
            WHERE id IN ({$assignmentTaskIdList})
        ");
        $stmt->bind_param("iis", $developerId, $userId, $deadline);
        
        if ($stmt->execute()) {
            if ($currentRoadmapTask) {
                if ($assignmentRoadmapTaskIds === []) {
                    $currentRoadmapTaskId = (int) ($currentRoadmapTask['id'] ?? 0);
                    if ($currentRoadmapTaskId > 0) {
                        $assignmentRoadmapTaskIds[$currentRoadmapTaskId] = $currentRoadmapTaskId;
                    }
                }
                $assignmentRoadmapTaskIdList = implode(',', array_map('intval', array_values($assignmentRoadmapTaskIds)));
                if ($assignmentRoadmapTaskIdList !== '') {
                    $syncRoadmapStmt = $conn->prepare("
                        UPDATE methodology_tasks
                        SET assigned_developer_id = ?, status = 'planned', updated_by = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id IN ({$assignmentRoadmapTaskIdList})
                          AND project_id = ?
                    ");
                    if ($syncRoadmapStmt) {
                        $syncRoadmapStmt->bind_param("iii", $developerId, $userId, $assignmentProjectId);
                        $syncRoadmapStmt->execute();
                        $syncRoadmapStmt->close();
                    }
                }

                if ($assignmentDepartmentProgressByTaskId !== []) {
                    $syncDepartmentStmt = $conn->prepare("
                        UPDATE methodology_task_departments
                        SET assigned_developer_id = ?,
                            workflow_task_id = ?,
                            deadline = ?,
                            status = 'planned',
                            updated_by = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND project_id = ?
                    ");
                    if ($syncDepartmentStmt) {
                        foreach ($assignmentDepartmentProgressByTaskId as $departmentProgressId => $workflowTaskId) {
                            $syncDepartmentStmt->bind_param("iisiii", $developerId, $workflowTaskId, $deadline, $userId, $departmentProgressId, $assignmentProjectId);
                            $syncDepartmentStmt->execute();
                        }
                        $syncDepartmentStmt->close();
                    }
                }
            }

            projectWorkflowSyncRoadmap($conn, $assignmentProjectId);
            $assignedTaskRows = $conn->query("SELECT id, title, deadline FROM tasks WHERE id IN ({$assignmentTaskIdList})");
            if ($assignedTaskRows) {
                while ($assignedTask = $assignedTaskRows->fetch_assoc()) {
                    createNotification(
                        (int) $developerId,
                        "New Task Assignment: " . $assignedTask['title'],
                        "You have been assigned a new task. Deadline: " . date('M d, Y', strtotime((string) $assignedTask['deadline'])),
                        'task',
                        'tasks',
                        (int) $assignedTask['id'],
                        BASE_URL . 'modules/developer/task.php?id=' . (int) $assignedTask['id']
                    );
                }
            }
            
            $assignedCount = count($assignmentTaskIds);
            $_SESSION['success'] = $assignedCount . " task" . ($assignedCount === 1 ? "" : "s") . " assigned to one Developer successfully";
            header("Location: task-allocation.php?project_id=" . $assignmentProjectId);
            exit();
        } else {
            $_SESSION['error'] = "Failed to assign task";
        }
    }

    // Review developer Pull Request and move to QA or back to Developer
    if (isset($_POST['sul_approve_pr']) || isset($_POST['sul_request_revision'])) {
        if (!$canManageDeveloperWorkflow) {
            $_SESSION['error'] = "Developer code review is available only for department SULs outside Expert Department.";
            header("Location: task-allocation.php");
            exit();
        }

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $reviewComment = trim((string) ($_POST['review_comment'] ?? ''));
        $isApproval = isset($_POST['sul_approve_pr']);

        if ($taskId <= 0 || (!$isApproval && $reviewComment === '')) {
            $_SESSION['error'] = $isApproval ? "Invalid task selected." : "Please provide revision comments.";
            header("Location: task-allocation.php");
            exit();
        }

        $taskStmt = $conn->prepare("
            SELECT t.id, t.title, t.status, t.assigned_to, t.project_id, p.name AS project_name
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            JOIN users u ON u.id = t.assigned_to
            WHERE t.id = ? AND t.status = 'for_review'
              {$sulUserDepartmentFilter}
              AND {$sulProjectFilter}
            LIMIT 1
        ");
        $taskStmt->bind_param("i", $taskId);
        $taskStmt->execute();
        $reviewTask = $taskStmt->get_result()->fetch_assoc();
        $taskStmt->close();

        if (!$reviewTask) {
            $_SESSION['error'] = "Only tasks marked For Review can be reviewed by SUL.";
            header("Location: task-allocation.php");
            exit();
        }

        if ($isApproval) {
            $projectForRouting = (int) ($reviewTask['project_id'] ?? 0);
            $roadmapPhaseCount = (int) (($conn->query("SELECT COUNT(*) AS total FROM methodology_phases WHERE project_id = {$projectForRouting}")->fetch_assoc()['total'] ?? 0));
            $currentRoadmapTask = $roadmapPhaseCount > 0
                ? projectWorkflowCurrentRoadmapTask($conn, $projectForRouting, $taskId, $sulDepartment)
                : null;

            if ($roadmapPhaseCount > 0 && !$currentRoadmapTask) {
                $_SESSION['error'] = "This work item is not the current unlocked roadmap task for your department.";
                header("Location: task-allocation.php");
                exit();
            }

            $allDepartmentsComplete = true;
            $nextRoadmapTask = null;
            $nextDepartment = '';

            if ($currentRoadmapTask) {
                $currentRoadmapTaskId = (int) ($currentRoadmapTask['id'] ?? 0);
                $currentDepartmentProgressId = (int) ($currentRoadmapTask['department_progress_id'] ?? 0);
                if ($currentDepartmentProgressId > 0) {
                    $completeRoadmapStmt = $conn->prepare("
                        UPDATE methodology_task_departments
                        SET status = 'completed',
                            submitted_at = COALESCE(submitted_at, NOW()),
                            approved_by = ?,
                            approved_at = NOW(),
                            revision_notes = NULL,
                            updated_by = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND project_id = ?
                    ");
                    if ($completeRoadmapStmt) {
                        $completeRoadmapStmt->bind_param("iiii", $userId, $userId, $currentDepartmentProgressId, $projectForRouting);
                        $completeRoadmapStmt->execute();
                        $completeRoadmapStmt->close();
                    }
                } else {
                    $completeRoadmapStmt = $conn->prepare("
                        UPDATE methodology_tasks
                        SET status = 'completed', updated_by = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    if ($completeRoadmapStmt) {
                        $completeRoadmapStmt->bind_param("ii", $userId, $currentRoadmapTaskId);
                        $completeRoadmapStmt->execute();
                        $completeRoadmapStmt->close();
                    }
                }

                if (dbTableExists($conn, 'methodology_task_departments')) {
                    $laneCounts = $conn->query("
                        SELECT
                            COUNT(*) AS total,
                            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
                        FROM methodology_task_departments
                        WHERE methodology_task_id = {$currentRoadmapTaskId}
                          AND project_id = {$projectForRouting}
                    ")->fetch_assoc();
                    $totalLanes = (int) ($laneCounts['total'] ?? 0);
                    $completedLanes = (int) ($laneCounts['completed_count'] ?? 0);
                    $allDepartmentsComplete = $totalLanes <= 0 || $completedLanes >= $totalLanes;
                }

                if ($allDepartmentsComplete) {
                    $nextRoadmapTask = projectWorkflowNextRoadmapTask($conn, $projectForRouting, $taskId, $currentRoadmapTaskId);
                    $nextDepartment = projectWorkflowDepartmentLabel($nextRoadmapTask['department_label'] ?? ($nextRoadmapTask['department'] ?? ''));
                }
            }

            $routeToQa = $allDepartmentsComplete && ($nextDepartment === 'QA' || !$nextRoadmapTask);
            $approvedStatus = $routeToQa ? 'qa_testing' : 'todo';

            if ($routeToQa) {
                $stmt = $conn->prepare("
                    UPDATE tasks
                    SET status = 'qa_testing',
                        completed_at = NULL,
                        sul_reviewed_by = ?,
                        sul_reviewed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param("ii", $userId, $taskId);
            } else {
                $stmt = $conn->prepare("
                    UPDATE tasks
                    SET status = 'todo',
                        assigned_to = NULL,
                        assigned_by = NULL,
                        completed_at = NULL,
                        pr_submitted_at = NULL,
                        sul_reviewed_by = ?,
                        sul_reviewed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param("ii", $userId, $taskId);
            }
            if ($stmt->execute()) {
                workflowLogStatusChange((int) $user['id'], $taskId, 'for_review', $approvedStatus, 'sul_approve_department_work', $reviewComment);
                $approvalComment = $reviewComment !== ''
                    ? "SUL approved work: {$reviewComment}"
                    : 'SUL approved this department work.';
                if (!$allDepartmentsComplete) {
                    $approvalComment .= ' Waiting for remaining department work before the parent task can move forward.';
                } elseif (!$routeToQa && $nextDepartment !== '') {
                    $approvalComment .= ' Handoff sent to ' . $nextDepartment . '.';
                }
                workflowAddTaskComment($conn, $taskId, (int) $user['id'], $approvalComment);
                projectWorkflowSyncRoadmap($conn, $projectForRouting);

                if ($routeToQa) {
                    workflowNotifyRoles(
                        $conn,
                        ['qa'],
                        'Ready for QA: ' . $reviewTask['title'],
                        'All prior work for this item is ready for QA testing.',
                        BASE_URL . 'modules/qa/dashboard.php?task_id=' . $taskId,
                        $taskId
                    );
                    $_SESSION['success'] = "Work approved and moved to QA Testing.";
                } elseif ($allDepartmentsComplete && $nextDepartment !== '') {
                    projectWorkflowNotifyDepartmentSuls(
                        $conn,
                        $nextDepartment,
                        'Next Phase Unlocked: ' . ($nextRoadmapTask['title'] ?? $reviewTask['project_name']),
                        'The previous department was approved. Your department may now assign and start work.',
                        BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectForRouting,
                        $projectForRouting
                    );
                    $_SESSION['success'] = "Work approved. The next department has been notified.";
                } else {
                    $_SESSION['success'] = "Department work approved. The parent task remains in progress until every department is approved.";
                }
                header("Location: task-allocation.php");
                exit();
            }
            $_SESSION['error'] = "Failed to approve work.";
        } else {
            $stmt = $conn->prepare("UPDATE tasks SET status = 'sul_revision', completed_at = NULL, sul_reviewed_by = ?, sul_reviewed_at = NOW() WHERE id = ?");
            $stmt->bind_param("ii", $user['id'], $taskId);
            if ($stmt->execute()) {
                workflowAddTaskComment($conn, $taskId, (int) $user['id'], 'SUL requested revision: ' . $reviewComment);
                workflowLogStatusChange((int) $user['id'], $taskId, 'for_review', 'sul_revision', 'sul_request_pull_request_revision', $reviewComment);
                projectWorkflowSyncRoadmap($conn, (int) ($reviewTask['project_id'] ?? 0));
                workflowNotifyUser(
                    (int) $reviewTask['assigned_to'],
                    'PR Revision Requested: ' . $reviewTask['title'],
                    'SUL requested revisions before QA. Comment: ' . $reviewComment,
                    BASE_URL . 'modules/developer/task.php?id=' . $taskId,
                    $taskId
                );
                $_SESSION['success'] = "Pull Request returned to Developer for revision.";
                header("Location: task-allocation.php");
                exit();
            }
            $_SESSION['error'] = "Failed to request revision.";
        }
    }

    // Send a completed task back to the developer for revision
    if (isset($_POST['revise_completed_task'])) {
        if (!$canManageDeveloperWorkflow) {
            $_SESSION['error'] = "Developer revision requests are available only for department SULs outside Expert Department.";
            header("Location: task-allocation.php");
            exit();
        }

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $revisionReason = trim((string) ($_POST['revision_reason'] ?? ''));

        if ($taskId <= 0 || $revisionReason === '') {
            $_SESSION['error'] = "Please provide a task and revision reason.";
            header("Location: task-allocation.php");
            exit();
        }

        $taskStmt = $conn->prepare("
            SELECT t.id, t.title, t.assigned_to, t.project_id, t.architect_reviewed_at
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            JOIN users u ON u.id = t.assigned_to
            WHERE t.id = ?
              AND t.status = 'completed'
              AND t.architect_reviewed_at IS NULL
              AND t.assigned_to IS NOT NULL
              AND t.assigned_to != 0
              {$sulUserDepartmentFilter}
              AND {$sulProjectFilter}
            LIMIT 1
        ");
        $taskStmt->bind_param("i", $taskId);
        $taskStmt->execute();
        $task = $taskStmt->get_result()->fetch_assoc();
        $taskStmt->close();

        if (!$task) {
            $_SESSION['error'] = "Only pre-merge completed tasks can be sent for revision by SUL.";
            header("Location: task-allocation.php");
            exit();
        }

        $stmt = $conn->prepare("UPDATE tasks SET status = 'in_progress', completed_at = NULL WHERE id = ?");
        $stmt->bind_param("i", $taskId);

        if ($stmt->execute()) {
            projectWorkflowSyncRoadmap($conn, (int) ($task['project_id'] ?? 0));
            createNotification(
                (int) $task['assigned_to'],
                "Task Revision Requested: " . $task['title'],
                "SUL requested revisions. Reason: " . $revisionReason,
                'task',
                'tasks',
                (int) $taskId,
                BASE_URL . 'modules/developer/task.php?id=' . $taskId
            );
            $_SESSION['success'] = "Task sent back to the developer for revision.";
            header("Location: task-allocation.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to send task for revision.";
        }
    }
    
    if (isset($_POST['approve_project_estimation'])) {
        $estimationId = (int) ($_POST['estimation_id'] ?? 0);
        $comment = trim((string) ($_POST['review_comment'] ?? ''));

        $estimation = $estimationId > 0 ? $conn->query("
            SELECT e.*, p.name AS project_name
            FROM expert_project_estimations e
            JOIN projects p ON p.id = e.project_id
            WHERE e.id = {$estimationId} AND e.status = 'submitted'
            LIMIT 1
        ")->fetch_assoc() : null;

        if (!$estimation) {
            $_SESSION['error'] = "Please select a submitted project estimation.";
            header("Location: task-allocation.php");
            exit();
        }

        $stmt = $conn->prepare("UPDATE expert_project_estimations SET status = 'sul_approved', sul_reviewed_by = ?, sul_reviewed_at = NOW(), sul_comment = ? WHERE id = ?");
        $stmt->bind_param('isi', $userId, $comment, $estimationId);
        $approvalSaved = $stmt->execute();
        if ($approvalSaved) {
            workflowNotifyRoles(
                $conn,
                ['sales'],
                'Project Estimation Ready for Final Approval',
                'Expert SUL approved a project cost estimation for Sales final approval.',
                BASE_URL . 'modules/sales/estimation-approval.php',
                (int) $estimation['project_id'],
                'approval',
                'projects'
            );
            logActivity($userId, 'sul_approve_project_estimation', 'expert_project_estimations', $estimationId, null, $_POST);
            $_SESSION['success'] = ($estimation['project_name'] ?? 'Project') . " estimation approved and forwarded to Sales successfully.";
        } else {
            $_SESSION['error'] = "Failed to approve project estimation.";
        }
        $stmt->close();
        $approvalResult = $approvalSaved ? 'approved' : 'error';
        header("Location: task-allocation.php?project_id=" . (int) $estimation['project_id'] . "&review_result={$approvalResult}#expert-review-panel");
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
            header("Location: task-allocation.php");
            exit();
        }

        $stmt = $conn->prepare("UPDATE expert_project_estimations SET status = 'sul_rejected', sul_reviewed_by = ?, sul_reviewed_at = NOW(), sul_comment = ? WHERE id = ?");
        $stmt->bind_param('isi', $userId, $reason, $estimationId);
        $revisionSaved = $stmt->execute();
        if ($revisionSaved) {
            createNotification(
                (int) $estimation['expert_id'],
                "Project Estimation Revision Requested: " . ($estimation['project_name'] ?? 'Project'),
                "Expert SUL requested revisions. Reason: " . $reason,
                'approval',
                'expert_project_estimations',
                $estimationId,
                BASE_URL . 'modules/expert/feature-estimation.php?project_estimation_id=' . (int) $estimation['project_id']
            );
            logActivity($userId, 'sul_reject_project_estimation', 'expert_project_estimations', $estimationId, null, $_POST);
            $_SESSION['success'] = "Project estimation returned to Expert Developer for revision.";
        } else {
            $_SESSION['error'] = "Failed to reject project estimation.";
        }
        $stmt->close();
        $revisionResult = $revisionSaved ? 'revision_requested' : 'error';
        header("Location: task-allocation.php?project_id=" . (int) $estimation['project_id'] . "&review_result={$revisionResult}#expert-review-panel");
        exit();
    }

    // Approve estimation and move to ready for dev
    if (isset($_POST['approve_estimation'])) {
        if (!$canManageExpertWorkflow) {
            $_SESSION['error'] = "Only the Expert Department SUL can approve expert estimations.";
            header("Location: task-allocation.php");
            exit();
        }

        $taskId = (int) ($_POST['task_id'] ?? 0);

        if ($taskId <= 0) {
            $_SESSION['error'] = "Invalid task selected.";
            header("Location: task-allocation.php");
            exit();
        }

        $approvalSql = $hasSalesApproval ? " AND t.sales_approved = 1" : "";
        $choiceSql = $hasSalesChoice ? " AND t.sales_estimate_days IS NOT NULL" : "";
        $taskCheck = $conn->prepare("
            SELECT t.id
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            INNER JOIN feature_estimations fe ON fe.task_id = t.id AND fe.status = 'completed'
            WHERE t.id = ?
              AND t.status = 'review'
              AND {$sulProjectFilter}
              {$approvalSql}{$choiceSql}
        ");
        $taskCheck->bind_param("i", $taskId);
        $taskCheck->execute();
        $validTask = $taskCheck->get_result()->num_rows > 0;
        $taskCheck->close();

        if (!$validTask) {
            $_SESSION['error'] = "Approval cannot proceed until Sales approval and selected days are complete.";
            header("Location: task-allocation.php");
            exit();
        }
        
        $stmt = $conn->prepare("UPDATE tasks SET status = 'todo' WHERE id = ?");
        $stmt->bind_param("i", $taskId);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Estimation approved! Task is now ready for Developer assignment.";
            header("Location: task-allocation.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to approve estimation";
        }
    }
    
    // Reject estimation
    if (isset($_POST['reject_estimation'])) {
        if (!$canManageExpertWorkflow) {
            $_SESSION['error'] = "Only the Expert Department SUL can reject expert estimations.";
            header("Location: task-allocation.php");
            exit();
        }

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));

        if ($taskId <= 0 || $rejectionReason === '') {
            $_SESSION['error'] = "Please provide a task and rejection reason.";
            header("Location: task-allocation.php");
            exit();
        }
        
        $taskCheck = $conn->prepare("
            SELECT t.assigned_to, t.title
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            INNER JOIN feature_estimations fe ON fe.task_id = t.id AND fe.status = 'completed'
            WHERE t.id = ?
              AND t.status = 'review'
              AND {$sulProjectFilter}
            LIMIT 1
        ");
        $taskCheck->bind_param("i", $taskId);
        $taskCheck->execute();
        $task = $taskCheck->get_result()->fetch_assoc();
        $taskCheck->close();

        if (!$task) {
            $_SESSION['error'] = "Only reviewed estimations for Expert Department projects can be rejected.";
            header("Location: task-allocation.php");
            exit();
        }
        
        $stmt = $conn->prepare("UPDATE tasks SET status = 'todo' WHERE id = ?");
        $stmt->bind_param("i", $taskId);
        
        if ($stmt->execute()) {
            if (!empty($rejectionReason)) {
                createNotification(
                    (int) $task['assigned_to'],
                    "Estimation Revision Requested: " . $task['title'],
                    "Please revise your estimation. Reason: " . $rejectionReason,
                    'task',
                    'tasks',
                    (int) $taskId,
                    BASE_URL . 'modules/expert/feature-estimation.php'
                );
            }
            $_SESSION['success'] = "Estimation rejected. Task sent back to Expert.";
            header("Location: task-allocation.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to reject estimation";
        }
    }
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
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
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

.workflow-feedback {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    margin-bottom: 1.25rem;
    padding: 0.9rem 1rem;
    border: 1px solid;
    border-radius: 14px;
    box-shadow: var(--shadow-sm);
    animation: workflowFeedbackArrival 0.35s ease-out;
}

.workflow-feedback__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    color: #fff;
}

.workflow-feedback__content {
    min-width: 0;
}

.workflow-feedback__content strong,
.workflow-feedback__content span {
    display: block;
}

.workflow-feedback__content strong {
    margin-bottom: 0.08rem;
    font-size: 0.82rem;
}

.workflow-feedback__content span {
    font-size: 0.76rem;
    line-height: 1.4;
}

.workflow-feedback.is-success {
    border-color: #bbf7d0;
    background: #f0fdf4;
    color: #166534;
}

.workflow-feedback.is-success .workflow-feedback__icon {
    background: #16a34a;
}

.workflow-feedback.is-error {
    border-color: #fecaca;
    background: #fef2f2;
    color: #991b1b;
}

.workflow-feedback.is-error .workflow-feedback__icon {
    background: #dc2626;
}

@keyframes workflowFeedbackArrival {
    from { opacity: 0; transform: translateY(-6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Section Cards */
.section-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}

.expert-utility-grid {
    display: grid;
    grid-template-columns: minmax(280px, 0.9fr) minmax(0, 1.4fr);
    gap: 1rem;
    align-items: stretch;
}

.expert-utility-grid .section-card,
.allocation-board .section-card {
    margin-bottom: 0;
}

.allocation-board {
    min-width: 0;
}

.workflow-layout {
    display: grid;
    grid-template-columns: 292px minmax(0, 1fr);
    gap: 1.5rem;
    align-items: start;
    margin-top: 1rem;
}

.workflow-tabs {
    border: 1px solid var(--gray-200);
    border-radius: 20px;
    background: #ffffff;
    gap: 0.5rem;
    margin: 0;
    padding: 0.75rem;
    flex-direction: column;
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 88px;
}

.workflow-tabs .nav-link {
    width: 100%;
    border: 0;
    border-radius: 14px;
    color: var(--gray-600);
    background: transparent;
    font-size: 0.9rem;
    font-weight: 600;
    padding: 0.85rem 1rem;
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    gap: 0.75rem;
    text-align: left;
}

.workflow-tabs .nav-link:hover {
    color: var(--theme-primary);
    background: var(--gray-50);
}

.workflow-tabs .nav-link.active {
    color: var(--theme-primary);
    background: linear-gradient(135deg, #e9f7ff 0%, #f3fbff 100%);
    box-shadow: inset 0 0 0 1px rgba(31, 99, 146, 0.12);
}

.workflow-tabs .nav-link i {
    width: 18px;
    color: var(--theme-primary);
    text-align: center;
}

.workflow-tabs .badge-count {
    margin-left: auto;
}

.allocation-column {
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.allocation-column-body {
    max-height: 68vh;
    overflow-y: auto;
    scrollbar-gutter: stable;
}

.allocation-column-body::-webkit-scrollbar {
    width: 8px;
}

.allocation-column-body::-webkit-scrollbar-track {
    background: transparent;
}

.allocation-column-body::-webkit-scrollbar-thumb {
    background: rgba(31, 99, 146, 0.18);
    border-radius: 999px;
}

.section-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.section-header h5 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.section-header h5 i {
    color: var(--theme-primary);
}

.badge-count {
    background: var(--gray-200);
    color: var(--gray-600);
    padding: 0.2rem 0.5rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

/* Task Cards */
.task-card {
    background: white;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    border: 1px solid var(--gray-200);
    transition: all 0.2s;
}

.task-card.is-clickable {
    cursor: pointer;
}

.task-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--theme-primary);
}

.task-priority {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
}

.priority-critical { background: #fee2e2; color: #dc2626; }
.priority-high { background: #fff3e0; color: #f59e0b; }
.priority-medium { background: #dbeafe; color: #2563eb; }
.priority-low { background: #d1fae5; color: #059669; }

.task-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.25rem;
}

.task-meta {
    font-size: 0.7rem;
    color: var(--gray-500);
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 0.5rem;
}

.task-roadmap-link {
    align-items: center;
    color: var(--theme-primary);
    display: inline-flex;
    font-weight: 700;
    gap: .3rem;
    text-decoration: none;
}

.task-roadmap-link:hover {
    color: var(--theme-dark);
    text-decoration: underline;
}

.task-card-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

/* Review queue */
.review-queue-hint {
    color: var(--gray-600);
    font-size: 0.75rem;
}

.review-queue-group + .review-queue-group {
    margin-top: 1.25rem;
}

.review-queue-group-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.6rem;
    color: var(--gray-700);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

.review-queue-group-head span {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.review-queue-group-head small {
    color: var(--gray-500);
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
}

.review-queue-list {
    display: grid;
    gap: 0.65rem;
}

.project-review-card {
    overflow: hidden;
    border: 1px solid var(--gray-200);
    border-radius: 14px;
    background: #fff;
    box-shadow: var(--shadow-sm);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.project-review-card.is-open {
    border-color: rgba(31, 99, 146, 0.45);
    box-shadow: 0 10px 28px rgba(15, 42, 64, 0.08);
}

.project-review-toggle {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto auto;
    align-items: center;
    gap: 1rem;
    width: 100%;
    padding: 0.9rem 1rem;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.project-review-toggle:hover {
    background: #f8fbfd;
}

.project-review-toggle:focus-visible {
    outline: 3px solid rgba(31, 99, 146, 0.2);
    outline-offset: -3px;
}

.project-review-identity {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
}

.project-review-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: #fff7ed;
    color: #c2410c;
}

.project-review-title {
    display: block;
    overflow: hidden;
    color: var(--gray-900);
    font-size: 0.9rem;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.project-review-copy {
    min-width: 0;
}

.project-review-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.35rem 0.8rem;
    margin-top: 0.2rem;
    color: var(--gray-500);
    font-size: 0.7rem;
}

.project-review-meta span {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}

.project-review-amount {
    display: grid;
    gap: 0.08rem;
    text-align: right;
    white-space: nowrap;
}

.project-review-amount small {
    color: var(--gray-500);
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.project-review-amount strong {
    color: var(--theme-primary);
    font-size: 0.92rem;
}

.project-review-status {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.34rem 0.62rem;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 0.68rem;
    font-weight: 700;
    white-space: nowrap;
}

.project-review-chevron {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: var(--gray-100);
    color: var(--gray-600);
    transition: transform 0.2s ease, background 0.2s ease;
}

.project-review-toggle[aria-expanded="true"] .project-review-chevron {
    transform: rotate(180deg);
    background: #e8f3fa;
    color: var(--theme-primary);
}

.project-review-details {
    padding: 1rem;
    border-top: 1px solid var(--gray-200);
    background: #fbfcfe;
}

.project-review-costs {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.65rem;
    margin-bottom: 0.9rem;
}

.project-review-cost {
    min-width: 0;
    padding: 0.75rem 0.8rem;
    border: 1px solid var(--gray-200);
    border-radius: 11px;
    background: #fff;
}

.project-review-cost span {
    display: block;
    color: var(--gray-500);
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

.project-review-cost strong {
    display: block;
    margin-top: 0.18rem;
    overflow-wrap: anywhere;
    color: var(--gray-800);
    font-size: 0.88rem;
}

.project-review-cost.is-primary {
    border-color: #bfdbfe;
    background: #eff6ff;
}

.project-review-cost.is-primary strong {
    color: #1d4ed8;
}

.project-review-cost.is-negative {
    border-color: #fecaca;
    background: #fef2f2;
}

.project-review-cost.is-negative strong {
    color: #b91c1c;
}

.project-review-decisions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}

.project-review-decision {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    min-width: 0;
    margin: 0;
    padding: 0.85rem;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    background: #fff;
}

.project-review-decision-head {
    display: flex;
    align-items: flex-start;
    gap: 0.55rem;
}

.project-review-decision-head i {
    margin-top: 0.12rem;
}

.project-review-decision-head strong,
.project-review-decision-head span {
    display: block;
}

.project-review-decision-head strong {
    color: var(--gray-800);
    font-size: 0.78rem;
}

.project-review-decision-head span {
    margin-top: 0.1rem;
    color: var(--gray-500);
    font-size: 0.68rem;
    line-height: 1.35;
}

.project-review-decision.is-approve .project-review-decision-head i {
    color: #059669;
}

.project-review-decision.is-revision .project-review-decision-head i {
    color: #d97706;
}

.project-review-decision label {
    margin-bottom: -0.35rem;
    color: var(--gray-700);
    font-size: 0.7rem;
    font-weight: 700;
}

.project-review-decision label span {
    color: var(--gray-500);
    font-weight: 500;
}

.project-review-decision textarea {
    min-height: 74px;
    resize: vertical;
}

.project-review-decision button {
    justify-content: center;
    min-height: 38px;
    margin-top: auto;
}

/* Estimation Badge */
.estimation-badge {
    background: #f0fdf4;
    color: #059669;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.bulk-entity-badge {
    align-items: center;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 20px;
    color: #9a3412;
    display: inline-flex;
    font-size: 0.65rem;
    font-weight: 700;
    gap: 0.25rem;
    padding: 0.22rem 0.6rem;
    white-space: nowrap;
}

.bulk-entity-badge i {
    color: #c2410c;
}

/* Buttons */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-success-modern {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-success-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-warning-modern {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-warning-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.btn-outline-modern {
    background: transparent;
    border: 1px solid var(--gray-300);
    color: var(--gray-700);
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-outline-modern:hover {
    background: var(--gray-50);
    border-color: var(--theme-primary);
}

.gantt-phase .bar {
    fill: #1f6392;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 2rem 1.5rem;
}

.empty-state i {
    font-size: 2rem;
    color: var(--gray-300);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--gray-500);
    font-size: 0.8rem;
    margin: 0;
}

/* Modal */
.modal-modern .modal-content {
    border-radius: 20px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    padding: 1rem 1.5rem;
    border: none;
}

.modal-modern .modal-header .modal-title,
.modal-modern .modal-header .modal-title i {
    color: #ffffff !important;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-modern .modal-body {
    padding: 1.5rem;
}

.modal-modern .modal-footer {
    padding: 1rem 1.5rem 1.5rem;
    border-top: 1px solid var(--gray-200);
}

.form-select-custom {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
}

.form-control-custom {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
}

/* Tab Navigation */
#allocationTabs {
    border-bottom: 2px solid var(--gray-200);
}

#allocationTabs .nav-link {
    border: none;
    padding: 0.75rem 1.5rem;
    color: var(--gray-600);
    font-weight: 500;
    position: relative;
    transition: all 0.2s;
}

#allocationTabs .nav-link:hover {
    color: var(--theme-primary);
    background: transparent;
}

#allocationTabs .nav-link.active {
    color: var(--theme-primary);
    background: transparent;
    border: none;
}

#allocationTabs .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--theme-primary);
    border-radius: 3px 3px 0 0;
}

#allocationTabs .nav-link i {
    margin-right: 0.25rem;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.16));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-tabs,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-tabs .nav-link:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-tabs .nav-link.active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-column-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-light {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern h2 i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body strong {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select-custom,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-custom {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-tabs {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-tabs .nav-link {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-tabs .nav-link.active {
    box-shadow: inset 0 0 0 1px rgba(31, 99, 146, 0.12);
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-custom::placeholder {
    color: #7f9bb6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #allocationTabs {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #allocationTabs .nav-link {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #allocationTabs .nav-link.active {
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-outline-modern {
    background: rgba(31, 99, 146, 0.12);
    border-color: rgba(31, 99, 146, 0.3);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-entity-badge {
    background: rgba(194, 65, 12, 0.16);
    border-color: rgba(251, 146, 60, 0.32);
    color: #fdba74;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-entity-badge i {
    color: #fb923c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-cost,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-decision {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-toggle:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-details {
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-details {
    border-color: rgba(31, 99, 146, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-cost strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-decision-head strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-cost.is-primary {
    background: rgba(29, 78, 216, 0.14);
    border-color: rgba(96, 165, 250, 0.34);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-cost.is-primary strong {
    color: #93c5fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-cost.is-negative {
    background: rgba(185, 28, 28, 0.14);
    border-color: rgba(248, 113, 113, 0.32);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-cost.is-negative strong {
    color: #fca5a5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-status {
    background: rgba(29, 78, 216, 0.18);
    color: #93c5fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-review-chevron {
    background: #10263a;
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-feedback.is-success {
    border-color: rgba(34, 197, 94, 0.34);
    background: rgba(22, 101, 52, 0.2);
    color: #bbf7d0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-feedback.is-error {
    border-color: rgba(248, 113, 113, 0.32);
    background: rgba(153, 27, 27, 0.2);
    color: #fecaca;
}

/* Responsive */
@media (max-width: 1200px) {
    .expert-utility-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 992px) {
    .workflow-layout {
        grid-template-columns: 1fr;
    }

    .workflow-tabs {
        position: static;
    }

    .allocation-column-body {
        max-height: none;
        overflow: visible;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .section-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .task-meta {
        gap: 0.5rem;
    }

    .project-review-toggle {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.55rem 0.75rem;
    }

    .project-review-identity {
        grid-column: 1;
        grid-row: 1;
    }

    .project-review-chevron {
        grid-column: 2;
        grid-row: 1;
    }

    .project-review-amount {
        grid-column: 1;
        grid-row: 2;
        padding-left: 3.1rem;
        text-align: left;
    }

    .project-review-status {
        display: none;
    }

    .project-review-costs,
    .project-review-decisions {
        grid-template-columns: 1fr;
    }
    
    #allocationTabs .nav-link {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-tasks me-2"></i> 
                Task Allocation
            </h2>
            <p>Manage tasks from creation to completion</p>
        </div>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="workflow-feedback is-success" role="status" tabindex="-1" data-workflow-feedback>
            <span class="workflow-feedback__icon" aria-hidden="true"><i class="fas fa-check"></i></span>
            <span class="workflow-feedback__content">
                <strong>Action completed successfully</strong>
                <span><?php echo htmlspecialchars($flashSuccess); ?></span>
            </span>
        </div>
    <?php elseif ($flashError !== ''): ?>
        <div class="workflow-feedback is-error" role="alert" tabindex="-1" data-workflow-feedback>
            <span class="workflow-feedback__icon" aria-hidden="true"><i class="fas fa-exclamation"></i></span>
            <span class="workflow-feedback__content">
                <strong>Action could not be completed</strong>
                <span><?php echo htmlspecialchars($flashError); ?></span>
            </span>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" id="allocationTabs" role="tablist">
        <?php if ($canManageExpertWorkflow): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="expert-tab" data-bs-toggle="tab" data-bs-target="#expert-panel" type="button" role="tab">
                <i class="fas fa-user-tie me-2"></i>Expert
                <span class="badge bg-warning ms-2"><?php echo $expertAllocationBadgeCount; ?></span>
            </button>
        </li>
        <?php endif; ?>
        <?php if ($canManageDeveloperWorkflow): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="developer-tab" data-bs-toggle="tab" data-bs-target="#developer-panel" type="button" role="tab">
                <i class="fas fa-code me-2"></i>Developer
                <span class="badge bg-primary ms-2"><?php echo $developerAllocationBadgeCount; ?></span>
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="allocationTabsContent">
        
        <!-- Expert Tab Panel -->
        <?php if ($canManageExpertWorkflow): ?>
        <div class="tab-pane fade show active" id="expert-panel" role="tabpanel">
            <div class="section-card mb-4">
                <div class="section-header">
                    <div>
                        <h5>
                            <i class="fas fa-folder-open"></i>
                            Project Allocation
                        </h5>
                        <?php if ($projectId && $project): ?>
                            <small class="text-muted"><?php echo htmlspecialchars((string) $project['name']); ?></small>
                        <?php else: ?>
                            <small class="text-muted"><?php echo htmlspecialchars($sulDepartmentLabel); ?></small>
                        <?php endif; ?>
                    </div>
                    <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                        <select name="project_id" class="form-select-custom" onchange="this.form.submit()" aria-label="Select project for expert allocation" required>
                            <option value="">Select project</option>
                            <?php foreach ($expertProjectOptions as $expertProjectOption): ?>
                                <?php
                                    $optionUnassigned = (int) ($expertProjectOption['unassigned_count'] ?? 0);
                                    $optionPending = (int) ($expertProjectOption['pending_count'] ?? 0);
                                    $optionReview = (int) ($expertProjectOption['review_count'] ?? 0) + (int) ($expertProjectOption['project_review_count'] ?? 0);
                                ?>
                                <option value="<?php echo (int) $expertProjectOption['id']; ?>" <?php echo (int) $expertProjectOption['id'] === (int) $projectId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $expertProjectOption['name']); ?>
                                    (<?php echo $optionUnassigned; ?> unassigned, <?php echo $optionPending; ?> pending, <?php echo $optionReview; ?> review)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($projectId && $project): ?>
                            <a href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php?project_id=<?php echo (int) $projectId; ?>" class="btn-outline-modern text-decoration-none">
                                <i class="fas fa-route"></i> Roadmap
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="workflow-layout">
            <ul class="nav workflow-tabs" id="expertWorkflowTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="expert-unassigned-tab" data-bs-toggle="tab" data-bs-target="#expert-unassigned-panel" type="button" role="tab" aria-controls="expert-unassigned-panel" aria-selected="true">
                        <i class="fas fa-user-plus"></i> Unassigned <span class="badge-count"><?php echo $unassignedTasks->num_rows; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="expert-pending-tab" data-bs-toggle="tab" data-bs-target="#expert-pending-panel" type="button" role="tab" aria-controls="expert-pending-panel" aria-selected="false">
                        <i class="fas fa-calculator"></i> Pending Estimation <span class="badge-count"><?php echo $pendingEstimationTasks->num_rows; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="expert-review-tab" data-bs-toggle="tab" data-bs-target="#expert-review-panel" type="button" role="tab" aria-controls="expert-review-panel" aria-selected="false">
                        <i class="fas fa-clipboard-list"></i> Ready for Review <span class="badge-count"><?php echo $expertReviewCount; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="expert-assign-tab" data-bs-toggle="tab" data-bs-target="#expert-assign-panel" type="button" role="tab" aria-controls="expert-assign-panel" aria-selected="false">
                        <i class="fas fa-user-check"></i> Assign to Expert <span class="badge-count"><?php echo $projectsForAllocation->num_rows; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="expert-gantt-tab" data-bs-toggle="tab" data-bs-target="#expert-gantt-panel" type="button" role="tab" aria-controls="expert-gantt-panel" aria-selected="false">
                        <i class="fas fa-stream"></i> Project Gantt
                    </button>
                </li>
            </ul>
            <div class="tab-content allocation-board" id="expertWorkflowContent">

            <!-- Section 1: Unassigned Tasks - Need Expert Assignment -->
            <div class="tab-pane fade show active section-card allocation-column" id="expert-unassigned-panel" role="tabpanel" aria-labelledby="expert-unassigned-tab">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-user-plus"></i>
                        Unassigned Tasks
                        <span class="badge-count"><?php echo $unassignedTasks->num_rows; ?> tasks</span>
                    </h5>
                    
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for expert assignment</p>
                        </div>
                    <?php elseif($unassignedTasks->num_rows > 0): ?>
                        <?php while($task = $unassignedTasks->fetch_assoc()): 
                            $daysLeft = sulTaskAllocationDaysLeft($task['deadline'] ?? null);
                        ?>
                        <div class="task-card is-clickable" role="button" tabindex="0" onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)" onkeydown="if(event.key === 'Enter' || event.key === ' '){ event.preventDefault(); viewTaskDetails(<?php echo (int) $task['id']; ?>); }">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                        <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                        <?php echo sulTaskAllocationBulkEntityBadge($task); ?>
                                    </div>
                                    <div class="task-meta">
                                        <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                        <span><i class="far fa-calendar-alt"></i> Due: <?php echo htmlspecialchars(sulTaskAllocationFormatDeadline($task['deadline'] ?? null)); ?></span>
                                        <span class="text-<?php echo ($daysLeft !== null && $daysLeft <= 2) ? 'danger' : 'muted'; ?>">
                                            <?php echo $daysLeft !== null ? $daysLeft . ' days left' : 'No deadline set'; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="task-card-actions" onclick="event.stopPropagation();">
                                    <button class="btn-outline-modern" type="button" onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)">
                                        <i class="fas fa-circle-info"></i> View Info
                                    </button>
                                    <button
                                        class="btn-primary-modern js-assign-expert"
                                        type="button"
                                        data-task-id="<?php echo (int) $task['id']; ?>"
                                        data-task-title="<?php echo htmlspecialchars((string) $task['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <i class="fas fa-user-plus"></i> Assign to Expert
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No unassigned tasks</p>
                        </div>
                    <?php endif; ?>
        </div>
    </div>

    <!-- Section 2: Pending Expert Estimation -->
    <div class="tab-pane fade section-card allocation-column" id="expert-pending-panel" role="tabpanel" aria-labelledby="expert-pending-tab">
        <div class="section-header">
            <h5>
                <i class="fas fa-calculator"></i>
                Pending Expert Estimation
                <span class="badge-count"><?php echo $pendingEstimationTasks->num_rows; ?> tasks</span>
            </h5>
        </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for pending estimations</p>
                        </div>
                    <?php elseif($pendingEstimationTasks->num_rows > 0): ?>
                        <?php while($task = $pendingEstimationTasks->fetch_assoc()): 
                            $daysLeft = sulTaskAllocationDaysLeft($task['deadline'] ?? null);
                        ?>
                        <div class="task-card">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                        <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                        <?php echo sulTaskAllocationBulkEntityBadge($task); ?>
                                    </div>
                                    <div class="task-meta">
                                        <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                        <span><i class="fas fa-user"></i> Expert: <?php echo htmlspecialchars($task['expert_name']); ?></span>
                                        <span><i class="far fa-calendar-alt"></i> Due: <?php echo htmlspecialchars(sulTaskAllocationFormatDeadline($task['deadline'] ?? null)); ?></span>
                                        <span class="estimation-badge"><i class="fas fa-hourglass-half"></i> Waiting for estimation</span>
                                    </div>
                                </div>
                                <div class="text-muted small">
                                    <i class="fas fa-clock"></i> Pending Expert
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No tasks pending expert estimation</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 3: Ready for Review -->
            <div class="tab-pane fade section-card allocation-column" id="expert-review-panel" role="tabpanel" aria-labelledby="expert-review-tab">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-clipboard-list"></i>
                        Ready for Review
                        <span class="badge-count"><?php echo $expertReviewCount; ?> <?php echo $expertReviewCount === 1 ? 'item' : 'items'; ?></span>
                    </h5>
                    <span class="review-queue-hint">Open an item to review its details</span>
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for reviews</p>
                        </div>
                    <?php else: ?>
                    <?php if($projectEstimationReviews->num_rows > 0): ?>
                        <div class="review-queue-group">
                            <div class="review-queue-group-head">
                                <span><i class="fas fa-file-invoice-dollar"></i> Project cost estimations</span>
                                <small><?php echo $projectEstimationReviews->num_rows; ?> awaiting decision</small>
                            </div>
                            <div class="review-queue-list">
                        <?php while($estimate = $projectEstimationReviews->fetch_assoc()): ?>
                        <?php $projectReviewPanelId = 'projectEstimationReview' . (int) $estimate['id']; ?>
                        <article class="project-review-card" data-project-review-card>
                            <button type="button" class="project-review-toggle" aria-expanded="false" aria-controls="<?php echo $projectReviewPanelId; ?>">
                                <span class="project-review-identity">
                                    <span class="project-review-icon" aria-hidden="true"><i class="fas fa-calculator"></i></span>
                                    <span class="project-review-copy">
                                        <span class="project-review-title"><?php echo htmlspecialchars((string) $estimate['project_name']); ?></span>
                                        <span class="project-review-meta">
                                            <span><i class="fas fa-building" aria-hidden="true"></i><?php echo htmlspecialchars((string) $estimate['client_name']); ?></span>
                                            <span><i class="fas fa-user" aria-hidden="true"></i><?php echo htmlspecialchars((string) $estimate['expert_name']); ?></span>
                                            <span><i class="fas fa-gauge-high" aria-hidden="true"></i><?php echo htmlspecialchars((string) $estimate['project_complexity']); ?></span>
                                            <span><i class="far fa-calendar" aria-hidden="true"></i><?php echo (int) $estimate['project_duration_months']; ?> month<?php echo (int) $estimate['project_duration_months'] === 1 ? '' : 's'; ?></span>
                                        </span>
                                    </span>
                                </span>
                                <span class="project-review-amount">
                                    <small>Overall estimate</small>
                                    <strong><?php echo projectEstimationPeso((float) $estimate['overall_estimated_project_cost']); ?></strong>
                                </span>
                                <span class="project-review-status"><i class="fas fa-clock" aria-hidden="true"></i>Awaiting review</span>
                                <span class="project-review-chevron" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                            </button>
                            <div class="project-review-details" id="<?php echo $projectReviewPanelId; ?>" hidden>
                                <div class="project-review-costs" aria-label="Project estimation cost breakdown">
                                    <div class="project-review-cost">
                                        <span>Developer cost</span>
                                        <strong><?php echo projectEstimationPeso((float) $estimate['total_developer_cost']); ?></strong>
                                    </div>
                                    <div class="project-review-cost">
                                        <span>Technology stack</span>
                                        <strong><?php echo projectEstimationPeso((float) $estimate['total_technology_stack_cost']); ?></strong>
                                    </div>
                                    <div class="project-review-cost is-primary">
                                        <span>Overall estimate</span>
                                        <strong><?php echo projectEstimationPeso((float) $estimate['overall_estimated_project_cost']); ?></strong>
                                    </div>
                                    <div class="project-review-cost <?php echo (float) $estimate['remaining_budget'] < 0 ? 'is-negative' : ''; ?>">
                                        <span>Remaining budget</span>
                                        <strong><?php echo projectEstimationPeso((float) $estimate['remaining_budget']); ?></strong>
                                    </div>
                                </div>

                                <div class="project-review-decisions">
                                    <form method="POST" class="project-review-decision is-approve" data-generic-draft-disabled="1" data-app-loading data-loading-title="Approving estimation" data-loading-text="Forwarding project estimation to Sales.">
                                        <input type="hidden" name="estimation_id" value="<?php echo (int) $estimate['id']; ?>">
                                        <div class="project-review-decision-head">
                                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                                            <span><strong>Approve estimation</strong><span>Confirm the costing and forward it to Sales.</span></span>
                                        </div>
                                        <label for="approvalNote<?php echo (int) $estimate['id']; ?>">Approval note <span>(optional)</span></label>
                                        <textarea id="approvalNote<?php echo (int) $estimate['id']; ?>" name="review_comment" class="form-control-custom" rows="2" placeholder="Add a short note for Sales"></textarea>
                                        <button type="submit" name="approve_project_estimation" class="btn-success-modern">
                                            <i class="fas fa-check"></i> Approve and Forward to Sales
                                        </button>
                                    </form>
                                    <form method="POST" class="project-review-decision is-revision" data-generic-draft-disabled="1" data-app-loading data-loading-title="Returning estimation" data-loading-text="Sending the estimation back for revision.">
                                        <input type="hidden" name="estimation_id" value="<?php echo (int) $estimate['id']; ?>">
                                        <div class="project-review-decision-head">
                                            <i class="fas fa-rotate-left" aria-hidden="true"></i>
                                            <span><strong>Request revision</strong><span>Return the estimate with clear changes for the Expert.</span></span>
                                        </div>
                                        <label for="revisionReason<?php echo (int) $estimate['id']; ?>">Revision reason <span>(required)</span></label>
                                        <textarea id="revisionReason<?php echo (int) $estimate['id']; ?>" name="rejection_reason" class="form-control-custom" rows="2" required placeholder="Explain what needs to be revised"></textarea>
                                        <button type="submit" name="reject_project_estimation" class="btn-warning-modern">
                                            <i class="fas fa-rotate-left"></i> Return for Revision
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                        <?php endwhile; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if($reviewTasks->num_rows > 0): ?>
                        <div class="review-queue-group">
                            <div class="review-queue-group-head">
                                <span><i class="fas fa-chart-line"></i> Task duration estimates</span>
                                <small><?php echo $reviewTasks->num_rows; ?> awaiting decision</small>
                            </div>
                        <?php while($task = $reviewTasks->fetch_assoc()): 
                            $daysLeft = sulTaskAllocationDaysLeft($task['deadline'] ?? null);
                            $pertEstimate = round(($task['min_days'] + 4*$task['likely_days'] + $task['max_days'])/6, 1);
                        ?>
                        <div class="task-card">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                        <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                        <?php echo sulTaskAllocationBulkEntityBadge($task); ?>
                                        </div>
                                <div class="task-meta">
                                    <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                    <span><i class="fas fa-user"></i> Expert: <?php echo htmlspecialchars($task['expert_name']); ?></span>
                                    <span><i class="far fa-calendar-alt"></i> Due: <?php echo htmlspecialchars(sulTaskAllocationFormatDeadline($task['deadline'] ?? null)); ?></span>
                                    <span class="estimation-badge"><i class="fas fa-chart-line"></i> PERT: <?php echo $pertEstimate; ?> days</span>
                                </div>
                                <div class="mt-2 p-2 bg-light rounded-2">
                                    <small class="text-muted"><i class="fas fa-info-circle"></i> Estimation: Min: <?php echo $task['min_days']; ?> | Likely: <?php echo $task['likely_days']; ?> | Max: <?php echo $task['max_days']; ?> days</small>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn-success-modern" onclick="approveEstimation(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-warning-modern" onclick="openRejectModal(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                        </div>
                <?php elseif($projectEstimationReviews->num_rows === 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No tasks ready for review</p>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
                </div>
            </div>
            <div class="tab-pane fade section-card allocation-column" id="expert-assign-panel" role="tabpanel" aria-labelledby="expert-assign-tab">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-user-check"></i>
                        Assign Project to Expert
                        <span class="badge-count"><?php echo $projectsForAllocation->num_rows; ?> projects</span>
                    </h5>
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for expert allocation</p>
                        </div>
                    <?php elseif($projectsForAllocation->num_rows > 0): ?>
                        <form method="POST" class="row g-3 align-items-end" data-app-loading data-loading-title="Assigning project" data-loading-text="Please wait while the project features are assigned to the expert.">
                            <div class="col-md-6">
                                <label class="form-label">Project</label>
                                <select name="project_id" class="form-select-custom" required>
                                    <option value="">Select project</option>
                                    <?php while($proj = $projectsForAllocation->fetch_assoc()): ?>
                                        <option value="<?php echo $proj['id']; ?>">
                                            <?php echo htmlspecialchars($proj['name']); ?> (<?php echo $proj['unassigned_count']; ?> features)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Expert</label>
                                <select name="expert_id" class="form-select-custom" required>
                                    <option value="">Select expert</option>
                                    <?php $experts->data_seek(0); while($expert = $experts->fetch_assoc()): ?>
                                        <option value="<?php echo $expert['id']; ?>">
                                            <?php echo htmlspecialchars($expert['full_name']); ?> (<?php echo $expert['assigned_tasks']; ?> tasks)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="assign_project_to_expert" class="btn-primary-modern w-100">
                                    <i class="fas fa-share"></i> Assign
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No projects awaiting expert allocation</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade section-card allocation-column" id="expert-gantt-panel" role="tabpanel" aria-labelledby="expert-gantt-tab">
                <?php if ($projectId && $project): ?>
                <div class="section-header">
                    <div>
                        <h5>
                            <i class="fas fa-stream"></i>
                            Project Gantt Chart
                        </h5>
                        <small class="text-muted"><?php echo htmlspecialchars($project['name']); ?></small>
                    </div>
                    <?php if ($ganttProjectOptions && $ganttProjectOptions->num_rows > 0): ?>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <select name="project_id" class="form-select-custom" onchange="this.form.submit()" aria-label="Select project for Gantt chart">
                            <?php while($ganttProjectOption = $ganttProjectOptions->fetch_assoc()): ?>
                                <option value="<?php echo (int) $ganttProjectOption['id']; ?>" <?php echo (int) $ganttProjectOption['id'] === (int) $projectId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ganttProjectOption['name']); ?>
                                    <?php if ((int) $ganttProjectOption['approved_task_count'] > 0): ?>
                                        (<?php echo (int) $ganttProjectOption['approved_task_count']; ?> approved)
                                    <?php endif; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <a href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php?project_id=<?php echo (int) $projectId; ?>" class="btn-primary-modern text-decoration-none">
                            <i class="fas fa-route"></i> Roadmap
                        </a>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if (!$canShowGantt): ?>
                        <div class="empty-state">
                            <i class="fas fa-lock"></i>
                            <p>Gantt chart is available after Sales approval.</p>
                        </div>
                    <?php elseif (!$ganttProjectData || empty($ganttProjectData['tasks'])): ?>
                        <div class="empty-state">
                            <i class="fas fa-stream"></i>
                            <p>No Gantt data available for this project.</p>
                        </div>
                    <?php else: ?>
                        <div id="sulGanttChart"></div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="p-3 allocation-column-body">
                    <div class="empty-state">
                        <i class="fas fa-stream"></i>
                        <p>No project selected for Gantt view.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            </div>
            </div>
            </div>
            <!-- End Expert Tab Panel -->
        <?php endif; ?>
            
            <!-- Developer Tab Panel -->
        <?php if ($canManageDeveloperWorkflow): ?>
            <div class="tab-pane fade show active" id="developer-panel" role="tabpanel">
            <div class="section-card mb-4">
                <div class="section-header">
                    <div>
                        <h5>
                            <i class="fas fa-folder-open"></i>
                            Project Allocation
                        </h5>
                        <?php if ($projectId && $project): ?>
                            <small class="text-muted"><?php echo htmlspecialchars((string) $project['name']); ?></small>
                        <?php else: ?>
                            <small class="text-muted"><?php echo htmlspecialchars($sulDepartmentLabel); ?></small>
                        <?php endif; ?>
                    </div>
                    <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                        <select name="project_id" class="form-select-custom" onchange="this.form.submit()" aria-label="Select project for developer allocation" required>
                            <option value="">Select project</option>
                            <?php foreach ($developerProjectOptions as $developerProjectOption): ?>
                                <?php
                                    $optionReady = (int) ($developerProjectOption['ready_count'] ?? 0);
                                    $optionProgress = (int) ($developerProjectOption['in_progress_count'] ?? 0);
                                    $optionReview = (int) ($developerProjectOption['review_count'] ?? 0);
                                ?>
                                <option value="<?php echo (int) $developerProjectOption['id']; ?>" <?php echo (int) $developerProjectOption['id'] === (int) $projectId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $developerProjectOption['name']); ?>
                                    (<?php echo $optionReady; ?> ready, <?php echo $optionProgress; ?> active, <?php echo $optionReview; ?> review)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($projectId && $project): ?>
                            <a href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php?project_id=<?php echo (int) $projectId; ?>" class="btn-outline-modern text-decoration-none">
                                <i class="fas fa-route"></i> Roadmap
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="workflow-layout">
            <ul class="nav workflow-tabs" id="developerWorkflowTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="developer-ready-tab" data-bs-toggle="tab" data-bs-target="#developer-ready-panel" type="button" role="tab" aria-controls="developer-ready-panel" aria-selected="true">
                        <i class="fas fa-code"></i> Ready for Assignment <span class="badge-count"><?php echo $readyForDevTasks->num_rows; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="developer-progress-tab" data-bs-toggle="tab" data-bs-target="#developer-progress-panel" type="button" role="tab" aria-controls="developer-progress-panel" aria-selected="false">
                        <i class="fas fa-spinner"></i> In Progress <span class="badge-count"><?php echo $inProgressTasks->num_rows; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="developer-review-tab" data-bs-toggle="tab" data-bs-target="#developer-review-panel" type="button" role="tab" aria-controls="developer-review-panel" aria-selected="false">
                        <i class="fas fa-code-pull-request"></i> SUL Code Review <span class="badge-count"><?php echo $codeReviewTasks->num_rows; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="developer-completed-tab" data-bs-toggle="tab" data-bs-target="#developer-completed-panel" type="button" role="tab" aria-controls="developer-completed-panel" aria-selected="false">
                        <i class="fas fa-check-double"></i> Recently Completed <span class="badge-count"><?php echo $completedTasks->num_rows; ?></span>
                    </button>
                </li>
            </ul>
            <div class="tab-content allocation-board" id="developerWorkflowContent">
             
            <!-- Section 4: Ready for Developer Assignment -->
            <div class="tab-pane fade show active section-card allocation-column" id="developer-ready-panel" role="tabpanel" aria-labelledby="developer-ready-tab">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-code"></i>
                        Ready for Developer Assignment
                        <span class="badge-count"><?php echo $readyForDevTasks->num_rows; ?> tasks</span>
                    </h5>
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for developer assignment</p>
                        </div>
                    <?php elseif($readyForDevTasks->num_rows > 0): ?>
                        <?php while($task = $readyForDevTasks->fetch_assoc()): 
                            $daysLeft = sulTaskAllocationDaysLeft($task['deadline'] ?? null);
                            $pertEstimate = round(($task['min_days'] + 4*$task['likely_days'] + $task['max_days'])/6, 1);
                        ?>
                    <div class="task-card is-clickable" role="button" tabindex="0" aria-label="View details for <?php echo htmlspecialchars((string) $task['title'], ENT_QUOTES, 'UTF-8'); ?>" onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)" onkeydown="if(event.key === 'Enter' || event.key === ' '){ event.preventDefault(); viewTaskDetails(<?php echo (int) $task['id']; ?>); }">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                    <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                    <?php echo sulTaskAllocationBulkEntityBadge($task); ?>
                                </div>
                                <div class="task-meta">
                                    <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                    <span><i class="fas fa-user-check"></i> Estimated by: <?php echo htmlspecialchars($task['expert_name']); ?></span>
                                    <span><i class="far fa-calendar-alt"></i> Due: <?php echo htmlspecialchars(sulTaskAllocationFormatDeadline($task['deadline'] ?? null)); ?></span>
                                    <span class="estimation-badge"><i class="fas fa-chart-line"></i> PERT: <?php echo $pertEstimate; ?> days</span>
                                    <?php if (!empty($task['sales_estimate_choice']) && !empty($task['sales_estimate_days'])): ?>
                                        <span class="estimation-badge"><i class="fas fa-flag-checkered"></i> Sales picked <?php echo ucfirst($task['sales_estimate_choice']); ?>: <?php echo (int) $task['sales_estimate_days']; ?> days</span>
                                    <?php endif; ?>
                                    <a class="task-roadmap-link" href="../roadmap/methodology.php?project_id=<?php echo (int) $task['project_id']; ?>" onclick="event.stopPropagation();">
                                        <i class="fas fa-route"></i> View Roadmap
                                    </a>
                                </div>
                            </div>
                            <div class="task-card-actions" onclick="event.stopPropagation();">
                                <button class="btn-outline-modern" type="button" onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)">
                                    <i class="fas fa-circle-info"></i> View Info
                                </button>
                                <button class="btn-primary-modern" type="button" onclick="openAssignDeveloperModal(<?php echo (int) $task['id']; ?>, decodeURIComponent('<?php echo rawurlencode((string) $task['title']); ?>'), '<?php echo htmlspecialchars(sulTaskAllocationAssignmentDeadlineValue($task['deadline'] ?? null, $task['department_roadmap_start'] ?? null, $task['department_roadmap_end'] ?? null), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(sulTaskAllocationDateInputValue($task['department_roadmap_start'] ?? null), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(sulTaskAllocationDateInputValue($task['department_roadmap_end'] ?? null), ENT_QUOTES); ?>')">
                                    <i class="fas fa-user-plus"></i> Assign to Developer
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No tasks ready for developer assignment</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- Section 5: In Progress -->
            <div class="tab-pane fade section-card allocation-column" id="developer-progress-panel" role="tabpanel" aria-labelledby="developer-progress-tab">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-spinner fa-pulse"></i>
                        In Progress
                        <span class="badge-count"><?php echo $inProgressTasks->num_rows; ?> tasks</span>
                    </h5>
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for active work</p>
                        </div>
                    <?php elseif($inProgressTasks->num_rows > 0): ?>
                    <?php while($task = $inProgressTasks->fetch_assoc()): 
                        $daysLeft = sulTaskAllocationDaysLeft($task['deadline'] ?? null);
                    ?>
                    <div class="task-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                    <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                    <?php echo sulTaskAllocationBulkEntityBadge($task); ?>
                                </div>
                                <div class="task-meta">
                                    <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                    <span><i class="fas fa-user"></i> Developer: <?php echo htmlspecialchars($task['developer_name']); ?></span>
                                    <span><i class="far fa-calendar-alt"></i> Due: <?php echo htmlspecialchars(sulTaskAllocationFormatDeadline($task['deadline'] ?? null)); ?></span>
                                    <span class="text-<?php echo ($daysLeft !== null && $daysLeft <= 2) ? 'danger' : 'muted'; ?>">
                                        <?php echo $daysLeft !== null ? $daysLeft . ' days left' : 'No deadline set'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn-outline-modern" onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-play-circle"></i>
                        <p>No tasks in progress</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- Section 6: SUL Code Review -->
            <div class="tab-pane fade section-card allocation-column" id="developer-review-panel" role="tabpanel" aria-labelledby="developer-review-tab">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-code-pull-request"></i>
                        SUL Code Review
                        <span class="badge-count"><?php echo $codeReviewTasks->num_rows; ?> PRs</span>
                    </h5>
                </div>
                <div class="p-3">
                <?php if(!$projectId || !$project): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>Select a project for code review</p>
                    </div>
                <?php elseif($codeReviewTasks->num_rows > 0): ?>
                    <?php while($task = $codeReviewTasks->fetch_assoc()): ?>
                    <div class="task-card">
                        <form method="POST" data-app-loading data-loading-title="Updating review" data-loading-text="Please wait while the review decision is saved.">
                            <input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="task-priority priority-<?php echo htmlspecialchars((string) $task['priority']); ?>"><?php echo ucfirst((string) $task['priority']); ?></span>
                                        <span class="task-title"><?php echo htmlspecialchars((string) $task['title']); ?></span>
                                        <?php echo sulTaskAllocationBulkEntityBadge($task); ?>
                                    </div>
                                    <div class="task-meta">
                                        <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars((string) $task['project_name']); ?></span>
                                        <span><i class="fas fa-user"></i> Developer: <?php echo htmlspecialchars((string) $task['developer_name']); ?></span>
                                        <?php if (!empty($task['branch_name'])): ?><span><i class="fas fa-code-branch"></i> <?php echo htmlspecialchars((string) $task['branch_name']); ?></span><?php endif; ?>
                                        <?php if (!empty($task['commit_hash'])): ?><span><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars(substr((string) $task['commit_hash'], 0, 7)); ?></span><?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap mt-2">
                                        <?php if (!empty($task['pull_request_url'])): ?>
                                            <a class="btn-outline-modern" href="<?php echo htmlspecialchars((string) $task['pull_request_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-code-pull-request"></i> Pull Request</a>
                                        <?php endif; ?>
                                        <?php if (!empty($task['repository_url'])): ?>
                                            <a class="btn-outline-modern" href="<?php echo htmlspecialchars((string) $task['repository_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-link"></i> Repository</a>
                                        <?php endif; ?>
                                        <button type="button" class="btn-outline-modern" onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </div>
                                    <textarea name="review_comment" class="form-control-custom mt-3" rows="2" placeholder="Approval note or revision comments"></textarea>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="submit" name="sul_approve_pr" class="btn-success-modern">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button type="submit" name="sul_request_revision" class="btn-warning-modern">
                                        <i class="fas fa-undo"></i> Request Revision
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-code-branch"></i>
                        <p>No Pull Requests waiting for SUL review</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- Section 7: Recently Completed -->
            <div class="tab-pane fade section-card allocation-column" id="developer-completed-panel" role="tabpanel" aria-labelledby="developer-completed-tab">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-check-double"></i>
                        Recently Completed
                        <span class="badge-count"><?php echo $completedTasks->num_rows; ?> tasks</span>
                    </h5>
                </div>
                <div class="p-3 allocation-column-body">
                <?php if(!$projectId || !$project): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>Select a project for completed tasks</p>
                    </div>
                <?php elseif($completedTasks->num_rows > 0): ?>
                    <?php while($task = $completedTasks->fetch_assoc()): ?>
                    <div class="task-card bg-light">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <s class="task-title"><?php echo htmlspecialchars($task['title']); ?></s>
                                    <?php echo sulTaskAllocationBulkEntityBadge($task); ?>
                                </div>
                                <div class="task-meta">
                                    <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($task['assigned_to_name']); ?></span>
                                    <span><i class="fas fa-check-circle text-success"></i> Completed: <?php echo sulTaskAllocationFormatDeadline($task['completed_at'] ?? null); ?></span>
                                </div>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn-outline-modern" onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if (empty($task['architect_reviewed_at'])): ?>
                                    <button class="btn-warning-modern" onclick="openReviseCompletedModal(<?php echo $task['id']; ?>, decodeURIComponent('<?php echo rawurlencode((string) $task['title']); ?>'))">
                                        <i class="fas fa-undo"></i> Revise
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-trophy"></i>
                        <p>No completed tasks yet</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>
            </div>
            </div>
            </div>
            <!-- End Developer Tab Panel -->
        <?php endif; ?>
            
        </div>
        <!-- End Tab Content -->
    </div>

<!-- Assign to Expert Modal -->
<?php if ($canManageExpertWorkflow): ?>
<div class="modal fade modal-modern" id="assignExpertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Assign to Expert</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Assigning expert" data-loading-text="Please wait while the expert assignment is saved.">
                <div class="modal-body">
                    <input type="hidden" name="assign_to_expert" value="1">
                    <input type="hidden" name="project_id" value="<?php echo (int) $projectId; ?>">
                    <input type="hidden" name="task_id" id="expertTaskId">
                    <p><strong>Task:</strong> <span id="expertTaskTitle"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Select Expert</label>
                        <select name="expert_id" class="form-select-custom" required>
                            <option value="">Choose an expert...</option>
                            <?php $experts->data_seek(0); while($expert = $experts->fetch_assoc()): ?>
                            <option value="<?php echo $expert['id']; ?>">
                                <?php echo htmlspecialchars($expert['full_name']); ?> 
                                (<?php echo $expert['assigned_tasks']; ?> active tasks)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="alert alert-info bg-light border-0">
                        <small><i class="fas fa-info-circle"></i> The expert will provide a three-point estimation (min/likely/max days) for this task.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-modern">Assign to Expert</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Assign to Developer Modal -->
<?php if ($canManageDeveloperWorkflow): ?>
<div class="modal fade modal-modern" id="assignDeveloperModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Assign to Developer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Assigning developer" data-loading-text="Please wait while the developer assignment is saved.">
                <div class="modal-body">
                    <input type="hidden" name="project_id" value="<?php echo (int) $projectId; ?>">
                    <input type="hidden" name="task_id" id="devTaskId">
                    <p><strong>Task:</strong> <span id="devTaskTitle"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Select Developer</label>
                        <select name="developer_id" class="form-select-custom" required>
                            <option value="">Choose a developer...</option>
                            <?php while($developer = $developers->fetch_assoc()): ?>
                            <option value="<?php echo $developer['id']; ?>">
                                <?php echo htmlspecialchars($developer['full_name']); ?> 
                                (<?php echo $developer['assigned_tasks']; ?> active tasks)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Deadline</label>
                        <input type="date" name="deadline" id="devDeadline" class="form-control-custom" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_to_developer" class="btn-primary-modern">Assign to Developer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Revise Completed Task Modal -->
<?php if ($canManageDeveloperWorkflow): ?>
<div class="modal fade modal-modern" id="reviseCompletedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Request Revision</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Sending revision" data-loading-text="Please wait while the revision request is sent.">
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="reviseTaskId">
                    <p><strong>Task:</strong> <span id="reviseTaskTitle"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Revision Notes</label>
                        <textarea name="revision_reason" class="form-control-custom" rows="3" required placeholder="Describe what the developer should revise..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="revise_completed_task" class="btn-warning-modern">Send for Revision</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reject Estimation Modal -->
<?php if ($canManageExpertWorkflow): ?>
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
                    <p><strong>Task:</strong> <span id="rejectTaskTitle"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection</label>
                        <textarea name="rejection_reason" class="form-control-custom" rows="3" required placeholder="Please provide feedback for the expert..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject_estimation" class="btn-warning-modern">Reject Estimation</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Task Details Modal -->
<div class="modal fade modal-modern" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Task Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetailsContent">
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
function openAssignExpertModal(taskId, taskTitle) {
    document.getElementById('expertTaskId').value = taskId;
    document.getElementById('expertTaskTitle').textContent = taskTitle;
    new bootstrap.Modal(document.getElementById('assignExpertModal')).show();
}

document.querySelectorAll('.js-assign-expert').forEach(button => {
    button.addEventListener('click', event => {
        event.stopPropagation();
        openAssignExpertModal(
            Number.parseInt(button.dataset.taskId || '0', 10),
            button.dataset.taskTitle || ''
        );
    });
});

const projectReviewToggles = document.querySelectorAll('.project-review-toggle');

function setProjectReviewOpen(toggle, isOpen) {
    const details = document.getElementById(toggle.getAttribute('aria-controls') || '');
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    toggle.closest('[data-project-review-card]')?.classList.toggle('is-open', isOpen);
    if (details) details.hidden = !isOpen;
}

projectReviewToggles.forEach(toggle => {
    toggle.addEventListener('click', () => {
        const shouldOpen = toggle.getAttribute('aria-expanded') !== 'true';
        projectReviewToggles.forEach(otherToggle => setProjectReviewOpen(otherToggle, false));
        setProjectReviewOpen(toggle, shouldOpen);
    });
});

const reviewResult = new URLSearchParams(window.location.search).get('review_result');
if (reviewResult) {
    const reviewTab = document.getElementById('expert-review-tab');
    if (reviewTab && window.bootstrap?.Tab) {
        bootstrap.Tab.getOrCreateInstance(reviewTab).show();
    }

    window.requestAnimationFrame(() => {
        document.querySelector('[data-workflow-feedback]')?.focus({ preventScroll: true });
    });
}

function openAssignDeveloperModal(taskId, taskTitle, deadline, minDate = '', maxDate = '') {
    document.getElementById('devTaskId').value = taskId;
    document.getElementById('devTaskTitle').textContent = taskTitle;
    const deadlineInput = document.getElementById('devDeadline');
    deadlineInput.min = minDate || '';
    deadlineInput.max = maxDate || '';
    deadlineInput.value = deadline || '';
    new bootstrap.Modal(document.getElementById('assignDeveloperModal')).show();
}

async function approveEstimation(taskId) {
    const confirmed = await appConfirm({
        title: 'Approve Estimation',
        subject: 'Approve this estimation?',
        message: 'The task will become ready for developer assignment.',
        icon: 'fas fa-circle-check fa-lg',
        confirmIcon: 'fas fa-check',
        confirmText: 'Approve'
    });
    if (!confirmed) return;

    if (window.appShowLoading) {
        window.appShowLoading('Approving estimation', 'Please wait while the task is prepared for developer assignment.');
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `<input type="hidden" name="approve_estimation" value="1"><input type="hidden" name="task_id" value="${taskId}">`;
    document.body.appendChild(form);
    form.submit();
}

function openRejectModal(taskId, taskTitle) {
    document.getElementById('rejectTaskId').value = taskId;
    document.getElementById('rejectTaskTitle').textContent = taskTitle;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function openReviseCompletedModal(taskId, taskTitle) {
    document.getElementById('reviseTaskId').value = taskId;
    document.getElementById('reviseTaskTitle').textContent = taskTitle;
    new bootstrap.Modal(document.getElementById('reviseCompletedModal')).show();
}

function formatTaskDeadlineLabel(deadline) {
    if (!deadline) {
        return 'N/A';
    }

    const parsed = new Date(deadline);
    if (Number.isNaN(parsed.getTime())) {
        return 'N/A';
    }

    return parsed.toLocaleDateString();
}

function viewTaskDetails(taskId) {
    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        dataType: 'json',
        data: { id: taskId },
        success: function(response) {
            const task = response;
            const bulkEntityCount = Number(task.bulk_entity_task_count || 0);
            const bulkEntityBadge = bulkEntityCount > 1
                ? `<span class="bulk-entity-badge ms-2" title="Architect bulk placement: these features share one roadmap developer/entity and should move together."><i class="fas fa-layer-group" aria-hidden="true"></i> Bulk assignment: ${bulkEntityCount} features</span>`
                : '';
            let html = `
                <div class="row g-4">
                    <div class="col-md-8">
                        <h5 class="mb-3 d-flex align-items-center gap-2 flex-wrap">${escapeHtml(task.title)}${bulkEntityBadge}</h5>
                        <div class="mb-3">
                            <strong>Description:</strong>
                            <p class="mt-2">${escapeHtml(task.description)}</p>
                        </div>
                        <div class="mb-2">
                            <strong>Project:</strong> ${escapeHtml(task.project_name)}
                        </div>
                        <div class="mb-2">
                            <strong>Priority:</strong> 
                            <span class="task-priority priority-${task.priority}">${task.priority}</span>
                        </div>
                        <div class="mb-2">
                            <strong>Deadline:</strong> ${formatTaskDeadlineLabel(task.deadline)}
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <p><strong>Assigned To:</strong> ${task.assigned_to_name || 'Unassigned'}</p>
                             ${task.estimated_days_min ? `
                                 <p><strong>Estimation:</strong><br>
                                 Min: ${task.estimated_days_min} days<br>
                                 Likely: ${task.estimated_days_likely} days<br>
                                 Max: ${task.estimated_days_max} days</p>
                             ` : ''}
                             <a class="btn-outline-modern d-inline-flex align-items-center gap-2 text-decoration-none" href="../roadmap/methodology.php?project_id=${Number(task.project_id || 0)}">
                                 <i class="fas fa-route"></i> View Project Roadmap
                             </a>
                         </div>
                    </div>
                </div>
            `;
            $('#taskDetailsContent').html(html);
            new bootstrap.Modal(document.getElementById('taskDetailsModal')).show();
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

const sulGanttProject = <?php echo json_encode($ganttProjectData, JSON_UNESCAPED_UNICODE); ?>;

function diffDays(startDate, endDate) {
    return Math.max(1, Math.round((endDate - startDate) / 86400000));
}

function normalizeSulGanttRows(tasks) {
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
        const committedDays = Math.max(0, parseInt(task.sales_estimate_days || '0', 10));
        const committedChoice = task.sales_estimate_choice || '';
        const totalDays = isPhase ? dateDuration : Math.max(committedDays || dateDuration || maxDays, 1);

        let minSegment = isPhase ? 0 : Math.min(minDays, totalDays);
        let likelySegment = isPhase ? 0 : Math.max(Math.min(likelyDays, totalDays) - Math.min(minDays, totalDays), 0);
        let maxSegment = isPhase ? 0 : Math.max(totalDays - Math.max(Math.min(likelyDays, totalDays), Math.min(minDays, totalDays)), 0);

        if (!isPhase && committedChoice && committedDays > 0) {
            minSegment = committedChoice === 'min' ? totalDays : 0;
            likelySegment = committedChoice === 'likely' ? totalDays : 0;
            maxSegment = committedChoice === 'max' ? totalDays : 0;
        }

        return {
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
                committedChoice
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

function renderSulThreePointGantt(target, tasks) {
    if (!target) {
        return;
    }

    if (typeof Chart === 'undefined') {
        target.innerHTML = '<div class="text-danger small">Chart.js failed to load. Please check your internet connection.</div>';
        return;
    }

    const rows = normalizeSulGanttRows(tasks);
    if (!rows.length) {
        target.innerHTML = '<div class="text-muted small">No timeline data yet.</div>';
        return;
    }

    target.innerHTML = '';
    const canvas = document.createElement('canvas');
    canvas.height = Math.max(240, rows.length * 44);
    target.appendChild(canvas);
    const ctx = canvas.getContext('2d');

    if (window.sulGanttChartInstance) {
        window.sulGanttChartInstance.destroy();
    }

    window.sulGanttChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: rows.map(task => task.name),
            datasets: [{
                label: 'Offset',
                data: rows.map(task => task.offset),
                backgroundColor: 'rgba(0,0,0,0)',
                borderWidth: 0,
                stack: 'gantt'
            }, {
                label: 'Project Phase',
                data: rows.map(task => task.phaseDuration),
                backgroundColor: '#94a3b8',
                stack: 'gantt'
            }, {
                label: 'Minimum',
                data: rows.map(task => task.minSegment),
                backgroundColor: '#60a5fa',
                stack: 'gantt'
            }, {
                label: 'Most Likely',
                data: rows.map(task => task.likelySegment),
                backgroundColor: '#34d399',
                stack: 'gantt'
            }, {
                label: 'Maximum',
                data: rows.map(task => task.maxSegment),
                backgroundColor: '#f97316',
                stack: 'gantt'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        filter: (legendItem) => legendItem.text !== 'Offset'
                    }
                },
                tooltip: {
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
                    title: { display: true, text: 'Days From Earliest Start' }
                },
                y: {
                    stacked: true
                }
            }
        }
    });
}

function initSulGanttChart() {
    if (!sulGanttProject || !Array.isArray(sulGanttProject.tasks) || sulGanttProject.tasks.length === 0) {
        return;
    }

    const container = document.getElementById('sulGanttChart');
    if (container) {
        renderSulThreePointGantt(container, sulGanttProject.tasks);
    }
}

if (document.getElementById('expert-gantt-panel')?.classList.contains('active')) {
    initSulGanttChart();
}

document.getElementById('expert-gantt-tab')?.addEventListener('shown.bs.tab', initSulGanttChart);
</script>

<?php include '../../includes/footer.php'; ?>
