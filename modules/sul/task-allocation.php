<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/methodology-roadmap.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/project-estimation.php';
require_once '../../includes/task-reopen.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();
$userId = (int) ($user['id'] ?? 0);
workflowEnsureSchema($conn);
methodologyRoadmapEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);
projectEstimationEnsureSchema($conn);
taskReopenEnsureSchema($conn);

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

function sulTaskAllocationProfileUrl(?string $profilePic): string
{
    $profilePic = trim((string) $profilePic);
    if ($profilePic === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $profilePic)) {
        return $profilePic;
    }

    return BASE_URL . ltrim(str_replace('\\', '/', $profilePic), '/');
}

function sulTaskAllocationInitials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($parts === []) {
        return '?';
    }

    $substring = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    $uppercase = function_exists('mb_strtoupper') ? 'mb_strtoupper' : 'strtoupper';
    $first = $substring((string) $parts[0], 0, 1);
    $last = count($parts) > 1 ? $substring((string) $parts[count($parts) - 1], 0, 1) : '';

    return $uppercase($first . $last);
}

function sulTaskAllocationWorkload(int $activeTasks): array
{
    if ($activeTasks <= 2) {
        return ['key' => 'light', 'label' => 'Light load'];
    }

    if ($activeTasks <= 5) {
        return ['key' => 'moderate', 'label' => 'Moderate'];
    }

    return ['key' => 'high', 'label' => 'High load'];
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

function sulTaskAllocationBulkEntityKeySelect(string $taskAlias = 't'): string
{
    $taskAlias = preg_replace('/[^A-Za-z0-9_]/', '', $taskAlias) ?: 't';

    return "(
        SELECT mt_current.developer_entity_key
        FROM methodology_tasks mt_current
        WHERE mt_current.project_id = {$taskAlias}.project_id
          AND mt_current.task_id = {$taskAlias}.id
          AND COALESCE(mt_current.developer_entity_key, '') <> ''
        ORDER BY mt_current.id ASC
        LIMIT 1
    ) AS bulk_entity_key";
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
$bulkEntityCountSelect = sulTaskAllocationBulkEntityCountSelect('t');
$bulkEntityKeySelect = sulTaskAllocationBulkEntityKeySelect('t');

// Get all unassigned tasks (need expert assignment)
$unassignedTasks = $conn->query("
    SELECT t.*, p.name as project_name, p.client_id,
           {$bulkEntityCountSelect},
           {$bulkEntityKeySelect},
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

$unassignedAssignmentGroups = [];
if ($unassignedTasks) {
    $priorityRanks = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
    while ($unassignedTask = $unassignedTasks->fetch_assoc()) {
        $entityKey = trim((string) ($unassignedTask['bulk_entity_key'] ?? ''));
        $isBulkEntity = $entityKey !== '' && (int) ($unassignedTask['bulk_entity_task_count'] ?? 0) > 1;
        $groupKey = $isBulkEntity
            ? 'bulk:' . (int) $unassignedTask['project_id'] . ':' . $entityKey
            : 'task:' . (int) $unassignedTask['id'];

        if (!isset($unassignedAssignmentGroups[$groupKey])) {
            $unassignedAssignmentGroups[$groupKey] = [
                'representative' => $unassignedTask,
                'features' => [],
                'is_bulk' => $isBulkEntity,
                'priority' => (string) ($unassignedTask['priority'] ?? 'medium'),
                'deadline' => $unassignedTask['deadline'] ?? null,
            ];
        }

        $unassignedAssignmentGroups[$groupKey]['features'][] = $unassignedTask;

        $currentPriority = (string) ($unassignedAssignmentGroups[$groupKey]['priority'] ?? 'medium');
        $candidatePriority = (string) ($unassignedTask['priority'] ?? 'medium');
        if (($priorityRanks[$candidatePriority] ?? 99) < ($priorityRanks[$currentPriority] ?? 99)) {
            $unassignedAssignmentGroups[$groupKey]['priority'] = $candidatePriority;
        }

        $candidateDeadline = !empty($unassignedTask['deadline']) ? strtotime((string) $unassignedTask['deadline']) : false;
        $groupDeadline = !empty($unassignedAssignmentGroups[$groupKey]['deadline'])
            ? strtotime((string) $unassignedAssignmentGroups[$groupKey]['deadline'])
            : false;
        if ($candidateDeadline !== false && ($groupDeadline === false || $candidateDeadline < $groupDeadline)) {
            $unassignedAssignmentGroups[$groupKey]['deadline'] = $unassignedTask['deadline'];
        }
    }
    $unassignedTasks->data_seek(0);
}
$unassignedAssignmentCount = count($unassignedAssignmentGroups);

// Get tasks pending expert estimation (assigned to expert but not yet estimated)
$pendingEstimationTasks = $conn->query("
    SELECT t.*, u.full_name as expert_name, p.name as project_name,
           {$bulkEntityCountSelect},
           {$bulkEntityKeySelect},
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

$pendingEstimationAssignmentGroups = [];
if ($pendingEstimationTasks) {
    $priorityRanks = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
    while ($pendingEstimationTask = $pendingEstimationTasks->fetch_assoc()) {
        $entityKey = trim((string) ($pendingEstimationTask['bulk_entity_key'] ?? ''));
        $isBulkEntity = $entityKey !== '' && (int) ($pendingEstimationTask['bulk_entity_task_count'] ?? 0) > 1;
        $groupKey = $isBulkEntity
            ? 'bulk:' . (int) $pendingEstimationTask['project_id'] . ':' . (int) $pendingEstimationTask['assigned_to'] . ':' . $entityKey
            : 'task:' . (int) $pendingEstimationTask['id'];

        if (!isset($pendingEstimationAssignmentGroups[$groupKey])) {
            $pendingEstimationAssignmentGroups[$groupKey] = [
                'representative' => $pendingEstimationTask,
                'features' => [],
                'is_bulk' => $isBulkEntity,
                'priority' => (string) ($pendingEstimationTask['priority'] ?? 'medium'),
                'deadline' => $pendingEstimationTask['deadline'] ?? null,
            ];
        }

        $pendingEstimationAssignmentGroups[$groupKey]['features'][(int) $pendingEstimationTask['id']] = $pendingEstimationTask;

        $currentPriority = (string) ($pendingEstimationAssignmentGroups[$groupKey]['priority'] ?? 'medium');
        $candidatePriority = (string) ($pendingEstimationTask['priority'] ?? 'medium');
        if (($priorityRanks[$candidatePriority] ?? 99) < ($priorityRanks[$currentPriority] ?? 99)) {
            $pendingEstimationAssignmentGroups[$groupKey]['priority'] = $candidatePriority;
        }

        $candidateDeadline = !empty($pendingEstimationTask['deadline']) ? strtotime((string) $pendingEstimationTask['deadline']) : false;
        $groupDeadline = !empty($pendingEstimationAssignmentGroups[$groupKey]['deadline'])
            ? strtotime((string) $pendingEstimationAssignmentGroups[$groupKey]['deadline'])
            : false;
        if ($candidateDeadline !== false && ($groupDeadline === false || $candidateDeadline < $groupDeadline)) {
            $pendingEstimationAssignmentGroups[$groupKey]['deadline'] = $pendingEstimationTask['deadline'];
        }
    }
    $pendingEstimationTasks->data_seek(0);
}
$pendingEstimationAssignmentCount = count($pendingEstimationAssignmentGroups);

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
           {$bulkEntityKeySelect},
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

$readyDeveloperAssignmentGroups = [];
if ($readyForDevTasks) {
    $priorityRanks = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
    while ($readyDeveloperTask = $readyForDevTasks->fetch_assoc()) {
        $entityKey = trim((string) ($readyDeveloperTask['bulk_entity_key'] ?? ''));
        $isBulkEntity = $entityKey !== '' && (int) ($readyDeveloperTask['bulk_entity_task_count'] ?? 0) > 1;
        $groupKey = $isBulkEntity
            ? 'bulk:' . (int) $readyDeveloperTask['project_id'] . ':' . $entityKey
            : 'task:' . (int) $readyDeveloperTask['id'];

        if (!isset($readyDeveloperAssignmentGroups[$groupKey])) {
            $readyDeveloperAssignmentGroups[$groupKey] = [
                'representative' => $readyDeveloperTask,
                'features' => [],
                'is_bulk' => $isBulkEntity,
                'priority' => (string) ($readyDeveloperTask['priority'] ?? 'medium'),
                'deadline' => $readyDeveloperTask['deadline'] ?? null,
            ];
        }

        $readyDeveloperAssignmentGroups[$groupKey]['features'][(int) $readyDeveloperTask['id']] = $readyDeveloperTask;

        $currentPriority = (string) ($readyDeveloperAssignmentGroups[$groupKey]['priority'] ?? 'medium');
        $candidatePriority = (string) ($readyDeveloperTask['priority'] ?? 'medium');
        if (($priorityRanks[$candidatePriority] ?? 99) < ($priorityRanks[$currentPriority] ?? 99)) {
            $readyDeveloperAssignmentGroups[$groupKey]['priority'] = $candidatePriority;
        }

        $candidateDeadline = !empty($readyDeveloperTask['deadline']) ? strtotime((string) $readyDeveloperTask['deadline']) : false;
        $groupDeadline = !empty($readyDeveloperAssignmentGroups[$groupKey]['deadline'])
            ? strtotime((string) $readyDeveloperAssignmentGroups[$groupKey]['deadline'])
            : false;
        if ($candidateDeadline !== false && ($groupDeadline === false || $candidateDeadline < $groupDeadline)) {
            $readyDeveloperAssignmentGroups[$groupKey]['deadline'] = $readyDeveloperTask['deadline'];
        }
    }
    $readyForDevTasks->data_seek(0);
}
$readyDeveloperAssignmentCount = count($readyDeveloperAssignmentGroups);

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
    SELECT u.id, u.full_name, u.profile_pic, u.designation,
           COUNT(t.id) as assigned_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
    WHERE u.role = 'expert' AND u.status = 'active'
      {$sulUserDepartmentFilter}
    GROUP BY u.id, u.full_name, u.profile_pic, u.designation
    ORDER BY assigned_tasks ASC, u.full_name ASC
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
$selectedExpertTaskCount = $unassignedAssignmentCount + $pendingEstimationAssignmentCount + $expertReviewCount;
$expertAllocationBadgeCount = ($projectId && $project) ? $selectedExpertTaskCount : $expertProjectOptionTaskCount;

// Get available developers
$developers = $conn->query("
    SELECT u.id, u.full_name, u.profile_pic, u.designation,
           COUNT(t.id) as assigned_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
    WHERE u.role = 'developer' AND u.status = 'active'
      {$sulUserDepartmentFilter}
    GROUP BY u.id, u.full_name, u.profile_pic, u.designation
    ORDER BY assigned_tasks ASC, u.full_name ASC
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
            ) AS review_count,
            (
                SELECT COUNT(DISTINCT rr.id)
                FROM task_reopen_requests rr
                WHERE rr.project_id = p.id
                  AND rr.status = 'pending_sul'
            ) AS reopen_count
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
        + (int) ($developerProjectOption['review_count'] ?? 0)
        + (int) ($developerProjectOption['reopen_count'] ?? 0);
}
$reopenRequests = $canManageDeveloperWorkflow ? taskReopenLoadSulRequests($conn, $sulDepartment, ($projectId && $project) ? $projectId : 0) : [];
$developerReopenRequestCount = count($reopenRequests);
$selectedDeveloperTaskCount = $readyDeveloperAssignmentCount + $inProgressTasks->num_rows + $codeReviewTasks->num_rows + $developerReopenRequestCount;
$developerAllocationBadgeCount = ($projectId && $project) ? $selectedDeveloperTaskCount : $developerProjectOptionTaskCount;

// Handle task operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['sul_approve_reopen']) || isset($_POST['sul_reject_reopen'])) {
        if (!$canManageDeveloperWorkflow) {
            $_SESSION['error'] = "Only department SULs outside Expert Department can review developer reopen requests.";
            header("Location: task-allocation.php");
            exit();
        }

        $requestId = (int) ($_POST['reopen_request_id'] ?? 0);
        $comment = trim((string) ($_POST['sul_comment'] ?? ''));
        $decision = isset($_POST['sul_approve_reopen']) ? 'approve' : 'reject';

        if ($requestId <= 0 || ($decision === 'reject' && $comment === '')) {
            $_SESSION['error'] = $decision === 'reject'
                ? "Please provide a reason when rejecting a reopen request."
                : "Please select a valid reopen request.";
            header("Location: task-allocation.php?project_id=" . (int) $projectId . "#developer-reopen-panel");
            exit();
        }

        $result = taskReopenSulDecision($conn, $requestId, $userId, $decision, $comment, $sulDepartment);
        $_SESSION[$result['success'] ? 'success' : 'error'] = $result['message'];
        header("Location: task-allocation.php?project_id=" . (int) $projectId . "#developer-reopen-panel");
        exit();
    }

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

        $assignmentProjectId = (int) ($validTaskRow['project_id'] ?? $projectId);
        $assignmentTasks = [];
        $selectedTaskDetails = $conn->query("SELECT id, title FROM tasks WHERE id = {$taskId}")->fetch_assoc();
        if ($selectedTaskDetails) {
            $assignmentTasks[$taskId] = $selectedTaskDetails;
        }

        $bulkEntityRow = $conn->query("
            SELECT developer_entity_key
            FROM methodology_tasks
            WHERE project_id = {$assignmentProjectId}
              AND task_id = {$taskId}
              AND COALESCE(developer_entity_key, '') <> ''
            ORDER BY id ASC
            LIMIT 1
        ")->fetch_assoc();
        $bulkEntityKey = trim((string) ($bulkEntityRow['developer_entity_key'] ?? ''));

        if ($bulkEntityKey !== '') {
            $safeBulkEntityKey = $conn->real_escape_string($bulkEntityKey);
            $bulkAssignmentRows = $conn->query("
                SELECT DISTINCT t.id, t.title
                FROM methodology_tasks mt
                JOIN tasks t ON t.id = mt.task_id
                JOIN projects p ON p.id = t.project_id
                WHERE mt.project_id = {$assignmentProjectId}
                  AND mt.developer_entity_key = '{$safeBulkEntityKey}'
                  AND t.status = 'todo'
                  AND (t.assigned_to IS NULL OR t.assigned_to = 0)
                  AND {$sulProjectFilter}
                ORDER BY t.id ASC
            ");

            if ($bulkAssignmentRows) {
                while ($bulkAssignmentTask = $bulkAssignmentRows->fetch_assoc()) {
                    $bulkAssignmentTaskId = (int) ($bulkAssignmentTask['id'] ?? 0);
                    if ($bulkAssignmentTaskId > 0) {
                        $assignmentTasks[$bulkAssignmentTaskId] = $bulkAssignmentTask;
                    }
                }
            }
        }

        if ($assignmentTasks === []) {
            $_SESSION['error'] = "No unassigned features remain in this assignment group.";
            header("Location: task-allocation.php?project_id=" . $assignmentProjectId);
            exit();
        }

        $assignmentTaskIds = array_map('intval', array_keys($assignmentTasks));
        $assignmentTaskIdList = implode(',', $assignmentTaskIds);
        $stmt = $conn->prepare("
            UPDATE tasks
            SET assigned_to = ?, status = 'todo'
            WHERE id IN ({$assignmentTaskIdList})
              AND status = 'todo'
              AND (assigned_to IS NULL OR assigned_to = 0)
        ");
        $stmt->bind_param("i", $expertId);
        
        if ($stmt->execute()) {
            $assignmentCount = count($assignmentTasks);
            foreach ($assignmentTasks as $assignmentTask) {
                createNotification(
                    (int) $expertId,
                    "New Task Assignment: " . (string) ($assignmentTask['title'] ?? 'Feature'),
                    $assignmentCount > 1
                        ? "This feature was assigned with {$assignmentCount} grouped features for estimation."
                        : "You have been assigned a new task for estimation.",
                    'task',
                    'tasks',
                    (int) ($assignmentTask['id'] ?? 0),
                    BASE_URL . 'modules/expert/feature-estimation.php'
                );
            }
            
            $_SESSION['success'] = $assignmentCount > 1
                ? "{$assignmentCount} grouped features assigned to the Expert successfully."
                : "Task assigned to the Expert successfully.";
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
            SELECT t.id, t.project_id, t.title, p.name AS project_name
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
        $validTask = $taskCheck->get_result()->fetch_assoc() ?: null;
        $taskCheck->close();

        if (!$validTask) {
            $_SESSION['error'] = "Approval cannot proceed until Sales approval and selected days are complete.";
            header("Location: task-allocation.php");
            exit();
        }
        
        $stmt = $conn->prepare("UPDATE tasks SET status = 'todo' WHERE id = ?");
        $stmt->bind_param("i", $taskId);
        
        if ($stmt->execute()) {
            $projectForAllocation = (int) ($validTask['project_id'] ?? 0);
            $taskTitle = (string) ($validTask['title'] ?? 'Feature');
            $projectName = (string) ($validTask['project_name'] ?? 'Project');
            $assignedDepartments = [];
            $roadmapRows = projectWorkflowRoadmapTaskRows($conn, $projectForAllocation, $taskId);
            $completeExpertLane = $conn->prepare("
                UPDATE methodology_task_departments
                SET status = 'completed', approved_by = ?, approved_at = NOW(), updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND project_id = ?
            ");

            foreach ($roadmapRows as $roadmapRow) {
                $department = projectWorkflowDepartmentLabel((string) ($roadmapRow['department_label'] ?? ''));
                $departmentProgressId = (int) ($roadmapRow['department_progress_id'] ?? 0);
                $requiredCount = max(0, (int) ($roadmapRow['required_count'] ?? 0));

                if ($department === 'Expert Department') {
                    if ($completeExpertLane && $departmentProgressId > 0) {
                        $completeExpertLane->bind_param('iii', $userId, $departmentProgressId, $projectForAllocation);
                        $completeExpertLane->execute();
                    }
                    continue;
                }

                if ($department !== '' && $requiredCount > 0) {
                    $assignedDepartments[] = $department;
                }
            }

            if ($completeExpertLane) {
                $completeExpertLane->close();
            }

            projectWorkflowSyncRoadmap($conn, $projectForAllocation);
            $assignedDepartments = array_values(array_unique($assignedDepartments));
            foreach ($assignedDepartments as $department) {
                projectWorkflowNotifyDepartmentSuls(
                    $conn,
                    $department,
                    $taskTitle . ' Ready for ' . $department . ' Assignment',
                    $projectName . ': the feature estimation is approved and ready for developer assignment.',
                    BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectForAllocation,
                    $projectForAllocation
                );
            }

            $_SESSION['success'] = "Estimation approved! Task is now ready for Developer assignment.";
            header("Location: task-allocation.php?project_id=" . $projectForAllocation);
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

.workflow-owner-summary {
    align-items: center;
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 18px;
    box-shadow: var(--shadow-sm);
    display: flex;
    gap: 0.9rem;
    justify-content: space-between;
    padding: 0.95rem 1.1rem;
}

.workflow-owner-identity {
    align-items: center;
    display: flex;
    gap: 0.85rem;
    min-width: 0;
}

.workflow-owner-icon,
.project-context-icon {
    align-items: center;
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.14), rgba(14, 165, 233, 0.1));
    border-radius: 14px;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 44px;
    height: 44px;
    justify-content: center;
    width: 44px;
}

.workflow-owner-copy,
.project-context-copy {
    min-width: 0;
}

.workflow-owner-eyebrow,
.project-context-eyebrow {
    color: var(--gray-500);
    display: block;
    font-size: 0.66rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.workflow-owner-copy strong,
.project-context-title {
    color: var(--gray-800);
    display: block;
    font-size: 1rem;
    font-weight: 800;
    line-height: 1.25;
    margin: 0;
}

.workflow-owner-copy small,
.project-context-subtitle {
    color: #64748b;
    display: block;
    font-size: 0.78rem;
    margin-top: 0.15rem;
}

.workflow-owner-count {
    align-items: center;
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 999px;
    color: #92400e;
    display: inline-flex;
    flex: 0 0 auto;
    font-size: 0.76rem;
    font-weight: 800;
    gap: 0.35rem;
    padding: 0.45rem 0.75rem;
    white-space: nowrap;
}

.project-context-card {
    overflow: visible;
    padding: 1rem;
}

.project-context-layout {
    align-items: center;
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(0, 1fr) minmax(340px, 0.8fr);
}

.project-context-main {
    align-items: flex-start;
    display: flex;
    gap: 0.9rem;
    min-width: 0;
}

.project-context-title {
    font-size: 1.05rem;
}

.project-context-metrics {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.65rem;
}

.project-context-pill {
    align-items: center;
    background: #f8fafc;
    border: 1px solid var(--gray-200);
    border-radius: 999px;
    color: #475569;
    display: inline-flex;
    font-size: 0.7rem;
    font-weight: 800;
    gap: 0.35rem;
    padding: 0.38rem 0.62rem;
}

.project-context-pill strong {
    color: var(--theme-primary);
    font-size: 0.82rem;
}

.project-context-actions {
    align-items: end;
    display: grid;
    gap: 0.55rem;
    grid-template-columns: minmax(0, 1fr) auto;
}

.project-context-label {
    color: var(--gray-500);
    display: block;
    font-size: 0.68rem;
    font-weight: 800;
    grid-column: 1 / -1;
    letter-spacing: 0.06em;
    margin: 0;
    text-transform: uppercase;
}

.project-context-actions .form-select-custom {
    min-height: 42px;
}

.project-context-empty-action {
    align-items: center;
    color: #94a3b8;
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 700;
    min-height: 42px;
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
    display: block;
    min-width: 0;
    margin-top: 1rem;
}

.workflow-tabs {
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    background: var(--gray-50);
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    gap: 0.3rem;
    margin: 0 0 1rem;
    max-width: 100%;
    overflow-x: auto;
    padding: 0.45rem;
    scrollbar-color: rgba(31, 99, 146, 0.28) transparent;
    scrollbar-width: thin;
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 88px;
    z-index: 20;
}

.workflow-tabs::-webkit-scrollbar {
    height: 5px;
}

.workflow-tabs::-webkit-scrollbar-track {
    background: transparent;
}

.workflow-tabs::-webkit-scrollbar-thumb {
    background: rgba(31, 99, 146, 0.28);
    border-radius: 999px;
}

.workflow-tabs .nav-item {
    display: flex;
    flex: 1 0 172px;
    min-width: 0;
}

.workflow-tabs .nav-link {
    width: 100%;
    border: 0;
    border-radius: 11px;
    color: var(--gray-600);
    background: transparent;
    font-size: 0.82rem;
    font-weight: 600;
    min-height: 48px;
    padding: 0.7rem 0.8rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    position: relative;
    text-align: center;
    white-space: nowrap;
}

.workflow-tabs .nav-link:hover {
    color: var(--theme-primary);
    background: rgba(255, 255, 255, 0.72);
}

.workflow-tabs .nav-link.active {
    color: var(--theme-primary);
    background: #ffffff;
    box-shadow: 0 3px 10px rgba(31, 99, 146, 0.1), inset 0 0 0 1px rgba(31, 99, 146, 0.12);
}

.workflow-tabs .nav-link.active::after {
    background: var(--theme-primary);
    border-radius: 999px;
    bottom: 0.28rem;
    content: "";
    height: 3px;
    left: 22%;
    position: absolute;
    right: 22%;
}

.workflow-tabs .nav-link i {
    flex: 0 0 auto;
    width: 16px;
    color: var(--theme-primary);
    text-align: center;
}

.workflow-tabs .badge-count {
    flex: 0 0 auto;
    margin-left: 0.1rem;
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

.task-allocation-table-wrap {
    border: 1px solid var(--gray-200);
    border-radius: 14px;
    max-width: 100%;
    overflow-x: auto;
}

.task-allocation-table {
    background: #fff;
    border-collapse: separate;
    border-spacing: 0;
    margin: 0;
    min-width: 850px;
    width: 100%;
}

.developer-assignment-table {
    min-width: 1050px;
}

.task-allocation-table thead th {
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    color: var(--gray-600);
    font-size: .7rem;
    font-weight: 800;
    letter-spacing: .04em;
    padding: .8rem .9rem;
    text-transform: uppercase;
    white-space: nowrap;
}

.task-allocation-table tbody td {
    border-bottom: 1px solid var(--gray-200);
    color: var(--gray-700);
    font-size: .78rem;
    padding: .85rem .9rem;
    vertical-align: middle;
}

.task-allocation-table tbody tr:last-child td {
    border-bottom: 0;
}

.task-allocation-table tbody tr {
    cursor: pointer;
    transition: background-color .18s ease;
}

.task-allocation-table tbody tr.is-bulk-assignment {
    cursor: default;
}

.task-allocation-table tbody tr:hover,
.task-allocation-table tbody tr:focus-visible {
    background: #f8fbff;
    outline: none;
}

.allocation-task-name {
    color: var(--gray-900);
    display: block;
    font-size: .84rem;
    font-weight: 700;
}

.allocation-task-secondary {
    color: var(--gray-500);
    display: block;
    font-size: .7rem;
    margin-top: .2rem;
}

.allocation-scope-single {
    align-items: center;
    color: var(--gray-600);
    display: inline-flex;
    font-size: .72rem;
    gap: .35rem;
    white-space: nowrap;
}

.bulk-assignment-details {
    display: inline-block;
    max-width: 290px;
    position: relative;
}

.bulk-assignment-details summary {
    align-items: center;
    cursor: pointer;
    display: inline-flex;
    gap: .4rem;
    list-style: none;
    outline: none;
}

.bulk-assignment-details summary::-webkit-details-marker {
    display: none;
}

.bulk-assignment-details summary:focus-visible {
    border-radius: 20px;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, .18);
}

.bulk-assignment-chevron {
    color: var(--gray-500);
    font-size: .62rem;
    transition: transform .18s ease;
}

.bulk-assignment-details[open] .bulk-assignment-chevron {
    transform: rotate(180deg);
}

.bulk-assignment-feature-list {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 11px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .1);
    display: grid;
    gap: .3rem;
    left: 0;
    margin: 0;
    max-width: calc(100vw - 24px);
    min-width: 290px;
    padding: .55rem;
    position: fixed;
    top: 0;
    width: 290px;
    z-index: 1085;
}

.bulk-assignment-feature-note {
    color: var(--gray-500);
    font-size: .64rem;
    padding: .1rem .25rem .25rem;
}

.bulk-assignment-feature {
    align-items: center;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    color: var(--gray-700);
    display: flex;
    font-size: .7rem;
    font-weight: 700;
    gap: .65rem;
    justify-content: space-between;
    padding: .45rem .55rem;
    text-align: left;
    width: 100%;
}

.bulk-assignment-feature span {
    align-items: center;
    display: inline-flex;
    gap: .4rem;
    min-width: 0;
}

.bulk-assignment-feature .bulk-feature-copy {
    align-items: flex-start;
    display: flex;
    flex-direction: column;
    gap: .12rem;
}

.bulk-feature-copy strong {
    align-items: center;
    display: inline-flex;
    gap: .4rem;
}

.bulk-feature-copy small {
    color: var(--gray-500);
    font-size: .61rem;
    font-weight: 600;
}

.bulk-assignment-feature:hover {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.allocation-deadline {
    color: var(--gray-800);
    display: block;
    font-weight: 700;
    white-space: nowrap;
}

.allocation-deadline-note {
    color: var(--gray-500);
    display: block;
    font-size: .68rem;
    margin-top: .2rem;
    white-space: nowrap;
}

.allocation-deadline-note.is-urgent {
    color: #dc2626;
    font-weight: 700;
}

.allocation-table-actions {
    align-items: center;
    display: flex;
    gap: .4rem;
    justify-content: center;
    white-space: nowrap;
}

.allocation-actions-heading {
    min-width: 245px;
    text-align: center;
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

.btn-primary-modern:disabled {
    box-shadow: none;
    cursor: not-allowed;
    opacity: .5;
    transform: none;
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

.sul-gantt-shell {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 14px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
    overflow-x: auto;
    padding: 1rem;
}

.sul-gantt-chart {
    min-height: 360px;
    min-width: 760px;
    position: relative;
}

.sul-gantt-chart canvas {
    min-height: 320px;
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

.assign-expert-modal .modal-dialog {
    max-width: 820px;
}

.assign-expert-intro {
    align-items: center;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    display: flex;
    gap: .8rem;
    margin-bottom: 1.15rem;
    padding: .85rem 1rem;
}

.assign-expert-intro-icon {
    align-items: center;
    background: #dbeafe;
    border-radius: 11px;
    color: #1d4ed8;
    display: inline-flex;
    flex: 0 0 38px;
    height: 38px;
    justify-content: center;
}

.assign-expert-intro-copy {
    min-width: 0;
}

.assign-expert-intro-label,
.assign-expert-intro-title {
    display: block;
}

.assign-expert-intro-label {
    color: #64748b;
    font-size: .67rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.assign-expert-intro-title {
    color: #0f172a;
    font-size: .95rem;
    font-weight: 800;
    margin-top: .12rem;
    overflow-wrap: anywhere;
}

.developer-deadline-panel {
    align-items: center;
    background: #f8fafc;
    border: 1px solid #dbe3ed;
    border-radius: 14px;
    display: grid;
    gap: .75rem;
    grid-template-columns: auto minmax(0, 1fr) minmax(170px, 220px);
    margin-bottom: 1.15rem;
    padding: .8rem .9rem;
}

.developer-deadline-icon {
    align-items: center;
    background: #e0f2fe;
    border-radius: 10px;
    color: #0369a1;
    display: inline-flex;
    height: 36px;
    justify-content: center;
    width: 36px;
}

.developer-deadline-panel label span,
.developer-deadline-panel label small {
    display: block;
}

.developer-deadline-panel label span {
    color: #0f172a;
    font-size: .76rem;
    font-weight: 800;
}

.developer-deadline-panel label small {
    color: #64748b;
    font-size: .64rem;
    margin-top: .12rem;
}

.expert-selection-heading {
    align-items: flex-end;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    margin-bottom: .65rem;
}

.expert-selection-heading h6,
.expert-selection-heading p {
    margin: 0;
}

.expert-selection-heading h6 {
    color: #0f172a;
    font-size: .86rem;
    font-weight: 800;
}

.expert-selection-heading p {
    color: #64748b;
    font-size: .7rem;
    margin-top: .15rem;
}

.expert-workload-legend {
    align-items: center;
    color: #64748b;
    display: flex;
    flex-wrap: wrap;
    font-size: .65rem;
    gap: .6rem;
    justify-content: flex-end;
}

.expert-workload-legend span {
    align-items: center;
    display: inline-flex;
    gap: .28rem;
    white-space: nowrap;
}

.expert-workload-legend i {
    border-radius: 50%;
    height: 7px;
    width: 7px;
}

.expert-workload-legend .is-light i {
    background: #16a34a;
}

.expert-workload-legend .is-moderate i {
    background: #d97706;
}

.expert-workload-legend .is-high i {
    background: #dc2626;
}

.expert-selection-table-wrap {
    border: 1px solid #dbe3ed;
    border-radius: 14px;
    max-height: 390px;
    overflow: auto;
}

.expert-selection-table {
    border-collapse: separate;
    border-spacing: 0;
    margin: 0;
    min-width: 670px;
    width: 100%;
}

.expert-selection-table thead {
    position: sticky;
    top: 0;
    z-index: 2;
}

.expert-selection-table th {
    background: #f8fafc;
    border-bottom: 1px solid #dbe3ed;
    color: #64748b;
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .04em;
    padding: .68rem .8rem;
    text-transform: uppercase;
    white-space: nowrap;
}

.expert-selection-table td {
    border-bottom: 1px solid #e6ebf1;
    color: #475569;
    font-size: .76rem;
    padding: .72rem .8rem;
    vertical-align: middle;
}

.expert-selection-table tbody tr:last-child td {
    border-bottom: 0;
}

.expert-choice-row {
    cursor: pointer;
    outline: none;
    transition: background-color .16s ease, box-shadow .16s ease;
}

.expert-choice-row:hover,
.expert-choice-row:focus-visible {
    background: #f8fbff;
}

.expert-choice-row.is-selected {
    background: #eff6ff;
    box-shadow: inset 4px 0 0 #1f6392;
}

.expert-identity {
    align-items: center;
    display: flex;
    gap: .7rem;
    min-width: 210px;
}

.expert-avatar {
    align-items: center;
    background: #dbeafe;
    border: 2px solid #fff;
    border-radius: 50%;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .14);
    color: #1d4ed8;
    display: inline-flex;
    flex: 0 0 42px;
    font-size: .7rem;
    font-weight: 900;
    height: 42px;
    justify-content: center;
    overflow: hidden;
    position: relative;
    width: 42px;
}

.expert-avatar img {
    height: 100%;
    inset: 0;
    object-fit: cover;
    position: absolute;
    width: 100%;
}

.expert-name,
.expert-role {
    display: block;
}

.expert-name {
    color: #0f172a;
    font-size: .8rem;
    font-weight: 800;
}

.expert-role {
    color: #64748b;
    font-size: .67rem;
    margin-top: .12rem;
}

.expert-recommended {
    background: #dcfce7;
    border-radius: 20px;
    color: #166534;
    display: inline-flex;
    font-size: .58rem;
    font-weight: 800;
    margin-top: .28rem;
    padding: .16rem .42rem;
    text-transform: uppercase;
}

.expert-active-count,
.expert-workload {
    align-items: center;
    border-radius: 20px;
    display: inline-flex;
    font-size: .67rem;
    font-weight: 800;
    gap: .32rem;
    padding: .28rem .55rem;
    white-space: nowrap;
}

.expert-active-count.is-light,
.expert-workload.is-light {
    background: #dcfce7;
    color: #166534;
}

.expert-active-count.is-moderate,
.expert-workload.is-moderate {
    background: #fef3c7;
    color: #92400e;
}

.expert-active-count.is-high,
.expert-workload.is-high {
    background: #fee2e2;
    color: #991b1b;
}

.expert-select-indicator {
    align-items: center;
    border: 2px solid #cbd5e1;
    border-radius: 50%;
    color: transparent;
    display: inline-flex;
    height: 24px;
    justify-content: center;
    transition: all .16s ease;
    width: 24px;
}

.expert-choice-row.is-selected .expert-select-indicator {
    background: #1f6392;
    border-color: #1f6392;
    color: #fff;
}

.expert-assignment-note {
    align-items: center;
    color: #64748b;
    display: flex;
    font-size: .68rem;
    gap: .45rem;
    margin-top: .75rem;
}

.expert-assignment-note i {
    color: #1f6392;
}

.expert-selection-summary {
    color: #64748b;
    font-size: .7rem;
    margin-right: auto;
}

.expert-selection-summary strong {
    color: #0f172a;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-owner-summary,
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-owner-copy strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-title {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-owner-icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-icon {
    background: rgba(31, 99, 146, 0.16);
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-pill {
    background: rgba(31, 99, 146, 0.12);
    border-color: rgba(31, 99, 146, 0.24);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-pill strong {
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .assign-expert-intro {
    background: rgba(31, 99, 146, 0.16);
    border-color: rgba(96, 165, 250, 0.3);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .assign-expert-intro-icon {
    background: rgba(37, 99, 235, 0.24);
    color: #93c5fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-deadline-panel {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-deadline-icon {
    background: rgba(14, 116, 144, .22);
    color: #7dd3fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .assign-expert-intro-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-selection-heading p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-workload-legend,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-role,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-assignment-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-selection-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-deadline-panel label small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-feature-copy small {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .assign-expert-intro-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-selection-heading h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-selection-summary strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-deadline-panel label span {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-selection-table-wrap {
    border-color: rgba(31, 99, 146, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-selection-table th {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.28);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-selection-table td {
    border-color: rgba(31, 99, 146, 0.22);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-choice-row:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-choice-row:focus-visible {
    background: rgba(31, 99, 146, 0.1);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-choice-row.is-selected {
    background: rgba(37, 99, 235, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-avatar {
    background: rgba(37, 99, 235, 0.24);
    border-color: #071321;
    color: #93c5fd;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-outline-modern {
    background: rgba(31, 99, 146, 0.12);
    border-color: rgba(31, 99, 146, 0.3);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-allocation-table-wrap {
    border-color: rgba(31, 99, 146, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-allocation-table,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-allocation-table thead th {
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-allocation-table thead th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-allocation-table tbody td {
    border-color: rgba(31, 99, 146, 0.24);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-allocation-table tbody tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-allocation-table tbody tr:focus-visible {
    background: rgba(31, 99, 146, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-task-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-deadline {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-task-secondary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-scope-single,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-deadline-note {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-deadline-note.is-urgent {
    color: #fca5a5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-entity-badge {
    background: rgba(194, 65, 12, 0.16);
    border-color: rgba(251, 146, 60, 0.32);
    color: #fdba74;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-entity-badge i {
    color: #fb923c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-assignment-feature-list {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.28);
    box-shadow: 0 12px 28px rgba(0, 0, 0, .24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-assignment-feature-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-assignment-chevron {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-assignment-feature {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-assignment-feature:hover {
    background: rgba(37, 99, 235, .16);
    border-color: rgba(96, 165, 250, .32);
    color: #93c5fd;
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
    .project-context-layout {
        grid-template-columns: 1fr;
    }

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

    .workflow-owner-summary {
        align-items: flex-start;
        flex-direction: column;
    }

    .workflow-owner-count {
        align-self: flex-start;
    }

    .project-context-card {
        padding: 0.85rem;
    }

    .project-context-main {
        gap: 0.7rem;
    }

    .project-context-actions {
        grid-template-columns: 1fr;
    }

    .project-context-actions .btn-primary-modern,
    .project-context-actions .btn-outline-modern {
        justify-content: center;
        width: 100%;
    }

    .assign-expert-modal .modal-body {
        padding: 1rem;
    }

    .developer-deadline-panel {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .developer-deadline-panel input {
        grid-column: 1 / -1;
    }

    .expert-selection-heading {
        align-items: flex-start;
        flex-direction: column;
        gap: .55rem;
    }

    .expert-workload-legend {
        justify-content: flex-start;
    }

    .expert-selection-table-wrap {
        max-height: 330px;
    }

    .expert-selection-summary {
        flex-basis: 100%;
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
}
</style>

<div class="container-fluid px-4 py-4">
    <?php di_render_project_attention_banner($conn, $user, $projectId); ?>

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

    <?php if ($canManageExpertWorkflow || $canManageDeveloperWorkflow): ?>
        <?php
            $activeWorkflowLabel = $canManageExpertWorkflow ? 'Expert' : 'Developer';
            $activeWorkflowIcon = $canManageExpertWorkflow ? 'fa-user-tie' : 'fa-code';
            $activeWorkflowCount = $canManageExpertWorkflow ? $expertAllocationBadgeCount : $developerAllocationBadgeCount;
            $activeWorkflowDescription = $canManageExpertWorkflow
                ? 'Expert Department allocation queue'
                : $sulDepartmentLabel . ' developer delivery queue';
        ?>
        <div class="workflow-owner-summary mb-4" aria-label="Active workflow">
            <div class="workflow-owner-identity">
                <span class="workflow-owner-icon" aria-hidden="true"><i class="fas <?php echo $activeWorkflowIcon; ?>"></i></span>
                <span class="workflow-owner-copy">
                    <span class="workflow-owner-eyebrow">Active workflow</span>
                    <strong><?php echo htmlspecialchars($activeWorkflowLabel); ?> Workflow</strong>
                    <small><?php echo htmlspecialchars($activeWorkflowDescription); ?></small>
                </span>
            </div>
            <span class="workflow-owner-count">
                <i class="fas fa-list-check" aria-hidden="true"></i>
                <?php echo (int) $activeWorkflowCount; ?> <?php echo (int) $activeWorkflowCount === 1 ? 'item' : 'items'; ?>
            </span>
        </div>
    <?php endif; ?>

    <div id="allocationContent">
        
        <!-- Expert Tab Panel -->
        <?php if ($canManageExpertWorkflow): ?>
        <div id="expert-panel">
            <div class="section-card project-context-card mb-4">
                <div class="project-context-layout">
                    <div class="project-context-main">
                        <span class="project-context-icon" aria-hidden="true"><i class="fas fa-folder-open"></i></span>
                        <div class="project-context-copy">
                            <span class="project-context-eyebrow">Project Allocation</span>
                            <h5 class="project-context-title">
                                <?php echo ($projectId && $project) ? htmlspecialchars((string) $project['name']) : 'Select a project'; ?>
                            </h5>
                            <span class="project-context-subtitle"><?php echo htmlspecialchars($sulDepartmentLabel); ?> - Expert estimation workflow</span>
                            <?php if ($projectId && $project): ?>
                                <div class="project-context-metrics" aria-label="Selected project queue summary">
                                    <span class="project-context-pill"><strong><?php echo $unassignedAssignmentCount; ?></strong> unassigned</span>
                                    <span class="project-context-pill"><strong><?php echo $pendingEstimationAssignmentCount; ?></strong> pending</span>
                                    <span class="project-context-pill"><strong><?php echo $expertReviewCount; ?></strong> review</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="GET" class="project-context-actions">
                        <label for="expertProjectSelect" class="project-context-label">Department Project</label>
                        <select id="expertProjectSelect" name="project_id" class="form-select-custom" onchange="this.form.submit()" aria-label="Select project for expert allocation" required>
                            <option value="">Select project</option>
                            <?php foreach ($expertProjectOptions as $expertProjectOption): ?>
                                <?php
                                    $optionUnassigned = (int) ($expertProjectOption['unassigned_count'] ?? 0);
                                    $optionPending = (int) ($expertProjectOption['pending_count'] ?? 0);
                                    $optionReview = (int) ($expertProjectOption['review_count'] ?? 0) + (int) ($expertProjectOption['project_review_count'] ?? 0);
                                ?>
                                <option value="<?php echo (int) $expertProjectOption['id']; ?>" <?php echo (int) $expertProjectOption['id'] === (int) $projectId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $expertProjectOption['name']); ?>
                                    (<?php echo $optionUnassigned; ?> unassigned features, <?php echo $optionPending; ?> pending, <?php echo $optionReview; ?> review)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($projectId && $project): ?>
                            <a href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php?project_id=<?php echo (int) $projectId; ?>" class="btn-primary-modern text-decoration-none">
                                <i class="fas fa-route"></i> Open Roadmap
                            </a>
                        <?php else: ?>
                            <span class="project-context-empty-action">Roadmap unlocks after selection</span>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="workflow-layout">
            <ul class="nav workflow-tabs" id="expertWorkflowTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="expert-unassigned-tab" data-bs-toggle="tab" data-bs-target="#expert-unassigned-panel" type="button" role="tab" aria-controls="expert-unassigned-panel" aria-selected="true">
                        <i class="fas fa-user-plus"></i> Unassigned <span class="badge-count"><?php echo $unassignedAssignmentCount; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="expert-pending-tab" data-bs-toggle="tab" data-bs-target="#expert-pending-panel" type="button" role="tab" aria-controls="expert-pending-panel" aria-selected="false">
                        <i class="fas fa-calculator"></i> Pending Estimation <span class="badge-count"><?php echo $pendingEstimationAssignmentCount; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="expert-review-tab" data-bs-toggle="tab" data-bs-target="#expert-review-panel" type="button" role="tab" aria-controls="expert-review-panel" aria-selected="false">
                        <i class="fas fa-clipboard-list"></i> Ready for Review <span class="badge-count"><?php echo $expertReviewCount; ?></span>
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
                        Unassigned Assignments
                        <span class="badge-count"><?php echo $unassignedAssignmentCount; ?> <?php echo $unassignedAssignmentCount === 1 ? 'assignment' : 'assignments'; ?></span>
                    </h5>
                    
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for expert assignment</p>
                        </div>
                    <?php elseif($unassignedAssignmentCount > 0): ?>
                        <div class="task-allocation-table-wrap">
                            <table class="task-allocation-table" aria-label="Unassigned expert assignments">
                                <thead>
                                    <tr>
                                        <th scope="col">Priority</th>
                                        <th scope="col">Task / Feature Group</th>
                                        <th scope="col">Assignment Scope</th>
                                        <th scope="col">Deadline</th>
                                        <th scope="col" class="allocation-actions-heading">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($unassignedAssignmentGroups as $assignmentGroup):
                                    $task = $assignmentGroup['representative'];
                                    $features = $assignmentGroup['features'];
                                    $featureCount = count($features);
                                    $isBulkAssignment = (bool) ($assignmentGroup['is_bulk'] ?? false) && $featureCount > 1;
                                    $assignmentPriority = (string) ($assignmentGroup['priority'] ?? $task['priority'] ?? 'medium');
                                    $assignmentDeadline = $assignmentGroup['deadline'] ?? null;
                                    $daysLeft = sulTaskAllocationDaysLeft($assignmentDeadline);
                                    $deadline = sulTaskAllocationFormatDeadline($assignmentDeadline);
                                    $featureTitles = array_map(
                                        static fn(array $feature): string => (string) ($feature['title'] ?? 'Feature'),
                                        $features
                                    );
                                    $assignmentTitle = $isBulkAssignment
                                        ? 'Bulk assignment: ' . implode(', ', $featureTitles)
                                        : (string) ($task['title'] ?? 'Feature');
                                ?>
                                    <tr
                                        class="<?php echo $isBulkAssignment ? 'is-bulk-assignment' : ''; ?>"
                                        <?php if (!$isBulkAssignment): ?>
                                            tabindex="0"
                                            onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)"
                                            onkeydown="if(event.key === 'Enter' || event.key === ' '){ event.preventDefault(); viewTaskDetails(<?php echo (int) $task['id']; ?>); }"
                                        <?php endif; ?>
                                    >
                                        <td>
                                            <span class="task-priority priority-<?php echo htmlspecialchars($assignmentPriority); ?>">
                                                <?php echo htmlspecialchars(ucfirst($assignmentPriority)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($isBulkAssignment): ?>
                                                <span class="allocation-task-name"><?php echo $featureCount; ?> grouped features</span>
                                                <span class="allocation-task-secondary">One expert will receive every feature in this group</span>
                                            <?php else: ?>
                                                <span class="allocation-task-name"><?php echo htmlspecialchars((string) $task['title']); ?></span>
                                                <span class="allocation-task-secondary">Awaiting expert assignment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isBulkAssignment): ?>
                                                <details class="bulk-assignment-details" onclick="event.stopPropagation();">
                                                    <summary>
                                                        <span class="bulk-entity-badge">
                                                            <i class="fas fa-layer-group" aria-hidden="true"></i>
                                                            Bulk assignment: <?php echo $featureCount; ?> features
                                                        </span>
                                                        <i class="fas fa-chevron-down bulk-assignment-chevron" aria-hidden="true"></i>
                                                    </summary>
                                                    <div class="bulk-assignment-feature-list">
                                                        <span class="bulk-assignment-feature-note">These features will be assigned together:</span>
                                                        <?php foreach ($features as $feature): ?>
                                                            <button type="button" class="bulk-assignment-feature" onclick="this.closest('details').open = false; viewTaskDetails(<?php echo (int) $feature['id']; ?>)">
                                                                <span><i class="far fa-file-lines" aria-hidden="true"></i><?php echo htmlspecialchars((string) $feature['title']); ?></span>
                                                                <i class="fas fa-circle-info" aria-hidden="true"></i>
                                                            </button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php else: ?>
                                                <span class="allocation-scope-single">
                                                    <i class="far fa-file-lines" aria-hidden="true"></i> Single feature
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($daysLeft !== null): ?>
                                                <span class="allocation-deadline"><?php echo htmlspecialchars($deadline); ?></span>
                                                <span class="allocation-deadline-note<?php echo $daysLeft <= 2 ? ' is-urgent' : ''; ?>">
                                                    <?php
                                                        if ($daysLeft < 0) {
                                                            echo abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' overdue';
                                                        } elseif ($daysLeft === 0) {
                                                            echo 'Due today';
                                                        } else {
                                                            echo $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left';
                                                        }
                                                    ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="allocation-deadline">No deadline</span>
                                                <span class="allocation-deadline-note">Schedule not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="allocation-table-actions" onclick="event.stopPropagation();">
                                                <?php if (!$isBulkAssignment): ?>
                                                    <button class="btn-outline-modern" type="button" onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)">
                                                        <i class="fas fa-circle-info"></i> View Info
                                                    </button>
                                                <?php endif; ?>
                                                <button
                                                    class="btn-primary-modern js-assign-expert"
                                                    type="button"
                                                    data-task-id="<?php echo (int) $task['id']; ?>"
                                                    data-task-title="<?php echo htmlspecialchars($assignmentTitle, ENT_QUOTES, 'UTF-8'); ?>"
                                                >
                                                    <i class="fas fa-user-plus"></i> Assign to Expert
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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
                <span class="badge-count"><?php echo $pendingEstimationAssignmentCount; ?> <?php echo $pendingEstimationAssignmentCount === 1 ? 'assignment' : 'assignments'; ?></span>
            </h5>
        </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for pending estimations</p>
                        </div>
                    <?php elseif($pendingEstimationAssignmentCount > 0): ?>
                        <div class="task-allocation-table-wrap">
                            <table class="task-allocation-table" aria-label="Pending expert estimation assignments">
                                <thead>
                                    <tr>
                                        <th scope="col">Priority</th>
                                        <th scope="col">Task / Feature Group</th>
                                        <th scope="col">Assignment Scope</th>
                                        <th scope="col">Expert</th>
                                        <th scope="col">Deadline</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($pendingEstimationAssignmentGroups as $assignmentGroup):
                                    $task = $assignmentGroup['representative'];
                                    $features = $assignmentGroup['features'];
                                    $featureCount = count($features);
                                    $isBulkAssignment = (bool) ($assignmentGroup['is_bulk'] ?? false) && $featureCount > 1;
                                    $assignmentPriority = (string) ($assignmentGroup['priority'] ?? $task['priority'] ?? 'medium');
                                    $assignmentDeadline = $assignmentGroup['deadline'] ?? null;
                                    $daysLeft = sulTaskAllocationDaysLeft($assignmentDeadline);
                                    $deadline = sulTaskAllocationFormatDeadline($assignmentDeadline);
                                ?>
                                    <tr
                                        class="<?php echo $isBulkAssignment ? 'is-bulk-assignment' : ''; ?>"
                                        <?php if (!$isBulkAssignment): ?>
                                            tabindex="0"
                                            onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)"
                                            onkeydown="if(event.key === 'Enter' || event.key === ' '){ event.preventDefault(); viewTaskDetails(<?php echo (int) $task['id']; ?>); }"
                                        <?php endif; ?>
                                    >
                                        <td>
                                            <span class="task-priority priority-<?php echo htmlspecialchars($assignmentPriority); ?>">
                                                <?php echo htmlspecialchars(ucfirst($assignmentPriority)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($isBulkAssignment): ?>
                                                <span class="allocation-task-name"><?php echo $featureCount; ?> grouped features</span>
                                                <span class="allocation-task-secondary">Waiting for one expert estimate set</span>
                                            <?php else: ?>
                                                <span class="allocation-task-name"><?php echo htmlspecialchars((string) $task['title']); ?></span>
                                                <span class="allocation-task-secondary">Awaiting expert estimation</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isBulkAssignment): ?>
                                                <details class="bulk-assignment-details" onclick="event.stopPropagation();">
                                                    <summary>
                                                        <span class="bulk-entity-badge">
                                                            <i class="fas fa-layer-group" aria-hidden="true"></i>
                                                            Bulk assignment: <?php echo $featureCount; ?> features
                                                        </span>
                                                        <i class="fas fa-chevron-down bulk-assignment-chevron" aria-hidden="true"></i>
                                                    </summary>
                                                    <div class="bulk-assignment-feature-list">
                                                        <span class="bulk-assignment-feature-note">These features are pending together:</span>
                                                        <?php foreach ($features as $feature): ?>
                                                            <button type="button" class="bulk-assignment-feature" onclick="this.closest('details').open = false; viewTaskDetails(<?php echo (int) $feature['id']; ?>)">
                                                                <span><i class="far fa-file-lines" aria-hidden="true"></i><?php echo htmlspecialchars((string) $feature['title']); ?></span>
                                                                <i class="fas fa-circle-info" aria-hidden="true"></i>
                                                            </button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php else: ?>
                                                <span class="allocation-scope-single">
                                                    <i class="far fa-file-lines" aria-hidden="true"></i> Single feature
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="allocation-deadline"><?php echo htmlspecialchars((string) ($task['expert_name'] ?? 'Expert')); ?></span>
                                            <span class="allocation-deadline-note">Assigned expert</span>
                                        </td>
                                        <td>
                                            <?php if ($daysLeft !== null): ?>
                                                <span class="allocation-deadline"><?php echo htmlspecialchars($deadline); ?></span>
                                                <span class="allocation-deadline-note<?php echo $daysLeft <= 2 ? ' is-urgent' : ''; ?>">
                                                    <?php
                                                        if ($daysLeft < 0) {
                                                            echo abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' overdue';
                                                        } elseif ($daysLeft === 0) {
                                                            echo 'Due today';
                                                        } else {
                                                            echo $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left';
                                                        }
                                                    ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="allocation-deadline">No deadline</span>
                                                <span class="allocation-deadline-note">Schedule not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="estimation-badge"><i class="fas fa-hourglass-half"></i> Waiting for estimation</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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
                            <div class="task-allocation-table-wrap">
                                <table class="task-allocation-table" aria-label="Task duration estimates ready for review">
                                    <thead>
                                        <tr>
                                            <th scope="col">Priority</th>
                                            <th scope="col">Task</th>
                                            <th scope="col">Expert</th>
                                            <th scope="col">Estimate</th>
                                            <th scope="col">Deadline</th>
                                            <th scope="col" class="allocation-actions-heading">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php while($task = $reviewTasks->fetch_assoc()):
                                        $daysLeft = sulTaskAllocationDaysLeft($task['deadline'] ?? null);
                                        $pertEstimate = round(((float) $task['min_days'] + 4 * (float) $task['likely_days'] + (float) $task['max_days']) / 6, 1);
                                    ?>
                                        <tr tabindex="0" onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)" onkeydown="if(event.key === 'Enter' || event.key === ' '){ event.preventDefault(); viewTaskDetails(<?php echo (int) $task['id']; ?>); }">
                                            <td>
                                                <span class="task-priority priority-<?php echo htmlspecialchars((string) $task['priority']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst((string) $task['priority'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="allocation-task-name"><?php echo htmlspecialchars((string) $task['title']); ?></span>
                                                <span class="allocation-task-secondary"><?php echo htmlspecialchars((string) $task['project_name']); ?></span>
                                                <?php echo sulTaskAllocationBulkEntityBadge($task); ?>
                                            </td>
                                            <td>
                                                <span class="allocation-deadline"><?php echo htmlspecialchars((string) ($task['expert_name'] ?? 'Expert')); ?></span>
                                                <span class="allocation-deadline-note">Submitted estimate</span>
                                            </td>
                                            <td>
                                                <span class="estimation-badge"><i class="fas fa-chart-line"></i> PERT: <?php echo $pertEstimate; ?> days</span>
                                                <span class="allocation-deadline-note">Min <?php echo (int) $task['min_days']; ?> | Likely <?php echo (int) $task['likely_days']; ?> | Max <?php echo (int) $task['max_days']; ?></span>
                                            </td>
                                            <td>
                                                <?php if ($daysLeft !== null): ?>
                                                    <span class="allocation-deadline"><?php echo htmlspecialchars(sulTaskAllocationFormatDeadline($task['deadline'] ?? null)); ?></span>
                                                    <span class="allocation-deadline-note<?php echo $daysLeft <= 2 ? ' is-urgent' : ''; ?>">
                                                        <?php
                                                            if ($daysLeft < 0) {
                                                                echo abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' overdue';
                                                            } elseif ($daysLeft === 0) {
                                                                echo 'Due today';
                                                            } else {
                                                                echo $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left';
                                                            }
                                                        ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="allocation-deadline">No deadline</span>
                                                    <span class="allocation-deadline-note">Schedule not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="allocation-table-actions" onclick="event.stopPropagation();">
                                                    <button class="btn-success-modern" type="button" onclick="approveEstimation(<?php echo (int) $task['id']; ?>)">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn-warning-modern" type="button" onclick="openRejectModal(<?php echo (int) $task['id']; ?>, <?php echo htmlspecialchars(json_encode((string) $task['title']), ENT_QUOTES, 'UTF-8'); ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
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

            <div class="tab-pane fade section-card allocation-column" id="expert-gantt-panel" role="tabpanel" aria-labelledby="expert-gantt-tab">
                <?php if (!$projectId || !$project): ?>
                    <div class="p-3 allocation-column-body">
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project to view its Gantt chart.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="section-header">
                        <div>
                            <h5>
                                <i class="fas fa-stream"></i>
                                Project Gantt
                            </h5>
                            <small class="text-muted"><?php echo htmlspecialchars((string) $project['name']); ?></small>
                        </div>
                    </div>
                    <div class="p-3 allocation-column-body">
                        <?php if (!$canShowGantt): ?>
                            <div class="empty-state">
                                <i class="fas fa-lock"></i>
                                <p>Gantt chart is waiting for Sales-approved estimates.</p>
                            </div>
                        <?php elseif (!$ganttProjectData || empty($ganttProjectData['tasks'])): ?>
                            <div class="empty-state">
                                <i class="fas fa-stream"></i>
                                <p>No Gantt timeline data is available for this project yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="sul-gantt-shell">
                                <div id="sulGanttChart" class="sul-gantt-chart" aria-label="Project Gantt chart"></div>
                            </div>
                        <?php endif; ?>
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
            <div id="developer-panel">
            <div class="section-card project-context-card mb-4">
                <div class="project-context-layout">
                    <div class="project-context-main">
                        <span class="project-context-icon" aria-hidden="true"><i class="fas fa-folder-open"></i></span>
                        <div class="project-context-copy">
                            <span class="project-context-eyebrow">Project Allocation</span>
                            <h5 class="project-context-title">
                                <?php echo ($projectId && $project) ? htmlspecialchars((string) $project['name']) : 'Select a project'; ?>
                            </h5>
                            <span class="project-context-subtitle"><?php echo htmlspecialchars($sulDepartmentLabel); ?> - Developer delivery workflow</span>
                            <?php if ($projectId && $project): ?>
                                <div class="project-context-metrics" aria-label="Selected project queue summary">
                                    <span class="project-context-pill"><strong><?php echo $readyDeveloperAssignmentCount; ?></strong> ready</span>
                                    <span class="project-context-pill"><strong><?php echo $inProgressTasks->num_rows; ?></strong> active</span>
                                    <span class="project-context-pill"><strong><?php echo $codeReviewTasks->num_rows; ?></strong> review</span>
                                    <span class="project-context-pill"><strong><?php echo $developerReopenRequestCount; ?></strong> reopen</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="GET" class="project-context-actions">
                        <label for="developerProjectSelect" class="project-context-label">Department Project</label>
                        <select id="developerProjectSelect" name="project_id" class="form-select-custom" onchange="this.form.submit()" aria-label="Select project for developer allocation" required>
                            <option value="">Select project</option>
                            <?php foreach ($developerProjectOptions as $developerProjectOption): ?>
                                <?php
                                    $optionReady = (int) ($developerProjectOption['ready_count'] ?? 0);
                                    $optionProgress = (int) ($developerProjectOption['in_progress_count'] ?? 0);
                                    $optionReview = (int) ($developerProjectOption['review_count'] ?? 0);
                                    $optionReopen = (int) ($developerProjectOption['reopen_count'] ?? 0);
                                ?>
                                <option value="<?php echo (int) $developerProjectOption['id']; ?>" <?php echo (int) $developerProjectOption['id'] === (int) $projectId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $developerProjectOption['name']); ?>
                                    (<?php echo $optionReady; ?> ready features, <?php echo $optionProgress; ?> active, <?php echo $optionReview; ?> review, <?php echo $optionReopen; ?> reopen)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($projectId && $project): ?>
                            <a href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php?project_id=<?php echo (int) $projectId; ?>" class="btn-outline-modern text-decoration-none">
                                <i class="fas fa-route"></i> Open Roadmap
                            </a>
                        <?php else: ?>
                            <span class="project-context-empty-action">Roadmap unlocks after selection</span>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="workflow-layout">
            <ul class="nav workflow-tabs" id="developerWorkflowTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="developer-ready-tab" data-bs-toggle="tab" data-bs-target="#developer-ready-panel" type="button" role="tab" aria-controls="developer-ready-panel" aria-selected="true">
                        <i class="fas fa-code"></i> Ready for Assignment <span class="badge-count"><?php echo $readyDeveloperAssignmentCount; ?></span>
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
                    <button class="nav-link" id="developer-reopen-tab" data-bs-toggle="tab" data-bs-target="#developer-reopen-panel" type="button" role="tab" aria-controls="developer-reopen-panel" aria-selected="false">
                        <i class="fas fa-rotate-left"></i> Reopen Requests <span class="badge-count"><?php echo $developerReopenRequestCount; ?></span>
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
                        <span class="badge-count"><?php echo $readyDeveloperAssignmentCount; ?> <?php echo $readyDeveloperAssignmentCount === 1 ? 'assignment' : 'assignments'; ?></span>
                    </h5>
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if(!$projectId || !$project): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Select a project for developer assignment</p>
                        </div>
                    <?php elseif($readyDeveloperAssignmentCount > 0): ?>
                        <div class="task-allocation-table-wrap">
                            <table class="task-allocation-table developer-assignment-table" aria-label="Developer assignments ready for allocation">
                                <thead>
                                    <tr>
                                        <th scope="col">Priority</th>
                                        <th scope="col">Task / Feature Group</th>
                                        <th scope="col">Estimate</th>
                                        <th scope="col">Assignment Scope</th>
                                        <th scope="col">Deadline</th>
                                        <th scope="col" class="allocation-actions-heading">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($readyDeveloperAssignmentGroups as $assignmentGroup):
                                    $task = $assignmentGroup['representative'];
                                    $features = array_values($assignmentGroup['features']);
                                    $featureCount = count($features);
                                    $isBulkAssignment = (bool) ($assignmentGroup['is_bulk'] ?? false) && $featureCount > 1;
                                    $assignmentPriority = (string) ($assignmentGroup['priority'] ?? $task['priority'] ?? 'medium');
                                    $assignmentDeadline = $assignmentGroup['deadline'] ?? null;
                                    $daysLeft = sulTaskAllocationDaysLeft($assignmentDeadline);
                                    $deadline = sulTaskAllocationFormatDeadline($assignmentDeadline);
                                    $featureTitles = [];
                                    $pertEstimates = [];
                                    $expertNames = [];
                                    $roadmapStarts = [];
                                    $roadmapEnds = [];
                                    foreach ($features as $feature) {
                                        $featureTitles[] = (string) ($feature['title'] ?? 'Feature');
                                        $pertEstimates[] = round(
                                            ((float) ($feature['min_days'] ?? 0)
                                                + (4 * (float) ($feature['likely_days'] ?? 0))
                                                + (float) ($feature['max_days'] ?? 0)) / 6,
                                            1
                                        );
                                        if (!empty($feature['expert_name'])) {
                                            $expertNames[(string) $feature['expert_name']] = (string) $feature['expert_name'];
                                        }
                                        $roadmapStart = sulTaskAllocationDateInputValue($feature['department_roadmap_start'] ?? null);
                                        $roadmapEnd = sulTaskAllocationDateInputValue($feature['department_roadmap_end'] ?? null);
                                        if ($roadmapStart !== '') $roadmapStarts[] = $roadmapStart;
                                        if ($roadmapEnd !== '') $roadmapEnds[] = $roadmapEnd;
                                    }
                                    $minimumPert = min($pertEstimates);
                                    $maximumPert = max($pertEstimates);
                                    $pertLabel = $minimumPert === $maximumPert
                                        ? $minimumPert . ' days'
                                        : $minimumPert . '–' . $maximumPert . ' days';
                                    $groupRoadmapStart = $roadmapStarts !== [] ? max($roadmapStarts) : '';
                                    $groupRoadmapEnd = $roadmapEnds !== [] ? min($roadmapEnds) : '';
                                    $assignmentDeadlineValue = sulTaskAllocationAssignmentDeadlineValue(
                                        $assignmentDeadline,
                                        $groupRoadmapStart,
                                        $groupRoadmapEnd
                                    );
                                    $assignmentTitle = $isBulkAssignment
                                        ? 'Bulk assignment: ' . implode(', ', $featureTitles)
                                        : (string) ($task['title'] ?? 'Feature');
                                ?>
                                    <tr
                                        class="<?php echo $isBulkAssignment ? 'is-bulk-assignment' : ''; ?>"
                                        <?php if (!$isBulkAssignment): ?>
                                            tabindex="0"
                                            onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)"
                                            onkeydown="if(event.key === 'Enter' || event.key === ' '){ event.preventDefault(); viewTaskDetails(<?php echo (int) $task['id']; ?>); }"
                                        <?php endif; ?>
                                    >
                                        <td>
                                            <span class="task-priority priority-<?php echo htmlspecialchars($assignmentPriority); ?>">
                                                <?php echo htmlspecialchars(ucfirst($assignmentPriority)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="allocation-task-name">
                                                <?php echo $isBulkAssignment ? $featureCount . ' grouped features' : htmlspecialchars((string) $task['title']); ?>
                                            </span>
                                            <span class="allocation-task-secondary">
                                                <?php echo $isBulkAssignment ? 'Assigned together to one developer' : 'Ready for developer allocation'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="estimation-badge"><i class="fas fa-chart-line"></i> PERT: <?php echo htmlspecialchars($pertLabel); ?></span>
                                            <?php if ($expertNames !== []): ?>
                                                <span class="allocation-task-secondary">Estimated by <?php echo htmlspecialchars(implode(', ', $expertNames)); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isBulkAssignment): ?>
                                                <details class="bulk-assignment-details" onclick="event.stopPropagation();">
                                                    <summary>
                                                        <span class="bulk-entity-badge">
                                                            <i class="fas fa-layer-group" aria-hidden="true"></i>
                                                            Bulk assignment: <?php echo $featureCount; ?> features
                                                        </span>
                                                        <i class="fas fa-chevron-down bulk-assignment-chevron" aria-hidden="true"></i>
                                                    </summary>
                                                    <div class="bulk-assignment-feature-list">
                                                        <span class="bulk-assignment-feature-note">These features will be assigned together:</span>
                                                        <?php foreach ($features as $index => $feature): ?>
                                                            <button type="button" class="bulk-assignment-feature" onclick="this.closest('details').open = false; viewTaskDetails(<?php echo (int) $feature['id']; ?>)">
                                                                <span class="bulk-feature-copy">
                                                                    <strong><i class="far fa-file-lines" aria-hidden="true"></i><?php echo htmlspecialchars((string) $feature['title']); ?></strong>
                                                                    <small>PERT: <?php echo htmlspecialchars((string) $pertEstimates[$index]); ?> days</small>
                                                                </span>
                                                                <i class="fas fa-circle-info" aria-hidden="true"></i>
                                                            </button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php else: ?>
                                                <span class="allocation-scope-single"><i class="far fa-file-lines" aria-hidden="true"></i> Single feature</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($daysLeft !== null): ?>
                                                <span class="allocation-deadline"><?php echo htmlspecialchars($deadline); ?></span>
                                                <span class="allocation-deadline-note<?php echo $daysLeft <= 2 ? ' is-urgent' : ''; ?>">
                                                    <?php
                                                        if ($daysLeft < 0) {
                                                            echo abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' overdue';
                                                        } elseif ($daysLeft === 0) {
                                                            echo 'Due today';
                                                        } else {
                                                            echo $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left';
                                                        }
                                                    ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="allocation-deadline">No deadline</span>
                                                <span class="allocation-deadline-note">Set during assignment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="allocation-table-actions" onclick="event.stopPropagation();">
                                                <?php if (!$isBulkAssignment): ?>
                                                    <button class="btn-outline-modern" type="button" onclick="viewTaskDetails(<?php echo (int) $task['id']; ?>)">
                                                        <i class="fas fa-circle-info"></i> View Info
                                                    </button>
                                                <?php endif; ?>
                                                <button
                                                    class="btn-primary-modern"
                                                    type="button"
                                                    onclick="openAssignDeveloperModal(<?php echo (int) $task['id']; ?>, decodeURIComponent('<?php echo rawurlencode($assignmentTitle); ?>'), '<?php echo htmlspecialchars($assignmentDeadlineValue, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($groupRoadmapStart, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($groupRoadmapEnd, ENT_QUOTES); ?>')"
                                                >
                                                    <i class="fas fa-user-plus"></i> Assign Developer
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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

            <!-- Section 7: Developer Reopen Requests -->
            <div class="tab-pane fade section-card allocation-column" id="developer-reopen-panel" role="tabpanel" aria-labelledby="developer-reopen-tab">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-rotate-left"></i>
                        Developer Reopen Requests
                        <span class="badge-count"><?php echo $developerReopenRequestCount; ?> requests</span>
                    </h5>
                </div>
                <div class="p-3 allocation-column-body">
                    <?php if($developerReopenRequestCount > 0): ?>
                        <?php foreach($reopenRequests as $request): ?>
                        <div class="task-card">
                            <form method="POST" data-app-loading data-loading-title="Reviewing reopen request" data-loading-text="Please wait while the reopen request is updated.">
                                <input type="hidden" name="reopen_request_id" value="<?php echo (int) $request['id']; ?>">
                                <input type="hidden" name="project_id" value="<?php echo (int) ($request['project_id'] ?? $projectId); ?>">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="task-priority priority-<?php echo strtolower((string) ($request['severity'] ?? 'minor')) === 'major' ? 'critical' : 'medium'; ?>">
                                                <?php echo htmlspecialchars(taskReopenSeverityLabel((string) ($request['severity'] ?? 'minor'))); ?>
                                            </span>
                                            <span class="task-title"><?php echo htmlspecialchars((string) $request['task_title']); ?></span>
                                        </div>
                                        <div class="task-meta">
                                            <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars((string) $request['project_name']); ?></span>
                                            <span><i class="fas fa-user"></i> Developer: <?php echo htmlspecialchars((string) ($request['developer_name'] ?? $request['requester_name'] ?? 'Developer')); ?></span>
                                            <span><i class="fas fa-clock"></i> Requested: <?php echo sulTaskAllocationFormatDeadline($request['created_at'] ?? null); ?></span>
                                            <span><i class="fas fa-list-check"></i> Current status: <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($request['task_status'] ?? 'unknown'))); ?></span>
                                        </div>
                                        <div class="alert alert-light border mt-3 mb-3">
                                            <div class="fw-semibold mb-1">Developer reason</div>
                                            <div><?php echo nl2br(htmlspecialchars((string) ($request['reason'] ?? ''))); ?></div>
                                        </div>
                                        <div class="small text-muted mb-3">
                                            <?php if (strtolower((string) ($request['severity'] ?? 'minor')) === 'major'): ?>
                                                Major means the change may affect other work. Accepting sends it to Architect so later phases can be reopened if needed.
                                            <?php else: ?>
                                                Minor means the change should only reopen this task for revision.
                                            <?php endif; ?>
                                        </div>
                                        <textarea name="sul_comment" class="form-control-custom" rows="2" placeholder="SUL note for Architect or rejection reason"></textarea>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="submit" name="sul_approve_reopen" class="btn-success-modern">
                                            <i class="fas fa-check"></i> Accept and Send to Architect
                                        </button>
                                        <button type="submit" name="sul_reject_reopen" class="btn-warning-modern">
                                            <i class="fas fa-ban"></i> Reject
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No developer reopen requests are waiting for SUL review</p>
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
<div class="modal fade modal-modern assign-expert-modal" id="assignExpertModal" tabindex="-1" aria-labelledby="assignExpertModalTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignExpertModalTitle"><i class="fas fa-user-plus me-2"></i>Assign to Expert</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="assignExpertForm" data-app-loading data-loading-title="Assigning expert" data-loading-text="Please wait while the expert assignment is saved.">
                <div class="modal-body">
                    <input type="hidden" name="assign_to_expert" value="1">
                    <input type="hidden" name="project_id" value="<?php echo (int) $projectId; ?>">
                    <input type="hidden" name="task_id" id="expertTaskId">

                    <div class="assign-expert-intro">
                        <span class="assign-expert-intro-icon" aria-hidden="true"><i class="fas fa-list-check"></i></span>
                        <span class="assign-expert-intro-copy">
                            <span class="assign-expert-intro-label">Task to assign</span>
                            <span class="assign-expert-intro-title" id="expertTaskTitle"></span>
                        </span>
                    </div>

                    <div class="expert-selection-heading">
                        <div>
                            <h6>Choose the right expert</h6>
                            <p>Compare current workload before assigning this task.</p>
                        </div>
                        <div class="expert-workload-legend" aria-label="Workload color guide">
                            <span class="is-light"><i aria-hidden="true"></i>0–2 light</span>
                            <span class="is-moderate"><i aria-hidden="true"></i>3–5 moderate</span>
                            <span class="is-high"><i aria-hidden="true"></i>6+ high</span>
                        </div>
                    </div>

                    <?php if ($experts->num_rows > 0): ?>
                        <div class="expert-selection-table-wrap">
                            <table class="expert-selection-table" aria-label="Available experts and their current workloads">
                                <thead>
                                    <tr>
                                        <th scope="col">Expert</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Active Tasks</th>
                                        <th scope="col">Capacity</th>
                                        <th scope="col" class="text-center">Select</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                    $experts->data_seek(0);
                                    $expertPosition = 0;
                                    while ($expert = $experts->fetch_assoc()):
                                        $activeTaskCount = (int) ($expert['assigned_tasks'] ?? 0);
                                        $workload = sulTaskAllocationWorkload($activeTaskCount);
                                        $expertName = (string) ($expert['full_name'] ?? 'Expert');
                                        $expertRole = trim((string) ($expert['designation'] ?? ''));
                                        $expertRole = $expertRole !== '' ? $expertRole : 'Expert';
                                        $profileUrl = sulTaskAllocationProfileUrl($expert['profile_pic'] ?? null);
                                        $isRecommended = $expertPosition === 0;
                                ?>
                                    <tr
                                        class="expert-choice-row"
                                        tabindex="0"
                                        role="radio"
                                        aria-checked="false"
                                        data-expert-choice
                                        data-expert-name="<?php echo htmlspecialchars($expertName, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <td>
                                            <span class="expert-identity">
                                                <span class="expert-avatar">
                                                    <span><?php echo htmlspecialchars(sulTaskAllocationInitials($expertName)); ?></span>
                                                    <?php if ($profileUrl !== ''): ?>
                                                        <img src="<?php echo htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                                                    <?php endif; ?>
                                                </span>
                                                <span>
                                                    <span class="expert-name"><?php echo htmlspecialchars($expertName); ?></span>
                                                    <?php if ($isRecommended): ?>
                                                        <span class="expert-recommended"><i class="fas fa-star me-1" aria-hidden="true"></i>Lightest workload</span>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </td>
                                        <td><span class="expert-role"><?php echo htmlspecialchars($expertRole); ?></span></td>
                                        <td>
                                            <span class="expert-active-count is-<?php echo htmlspecialchars($workload['key']); ?>">
                                                <i class="fas fa-list-check" aria-hidden="true"></i>
                                                <?php echo $activeTaskCount; ?> active
                                            </span>
                                        </td>
                                        <td>
                                            <span class="expert-workload is-<?php echo htmlspecialchars($workload['key']); ?>">
                                                <?php echo htmlspecialchars($workload['label']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <input
                                                class="visually-hidden expert-choice-input"
                                                type="radio"
                                                name="expert_id"
                                                value="<?php echo (int) $expert['id']; ?>"
                                                required
                                                tabindex="-1"
                                            >
                                            <span class="expert-select-indicator" aria-hidden="true"><i class="fas fa-check"></i></span>
                                        </td>
                                    </tr>
                                <?php
                                        $expertPosition++;
                                    endwhile;
                                ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="expert-assignment-note">
                            <i class="fas fa-circle-info" aria-hidden="true"></i>
                            The selected expert will prepare the minimum, likely, and maximum day estimates.
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <p>No active experts are available in this department.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <span class="expert-selection-summary" id="expertSelectionSummary">
                        <?php echo $experts->num_rows > 0 ? 'No expert selected' : 'No experts available'; ?>
                    </span>
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-modern" id="confirmExpertAssignment" disabled>
                        <i class="fas fa-user-check" aria-hidden="true"></i> Assign to Expert
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Assign to Developer Modal -->
<?php if ($canManageDeveloperWorkflow): ?>
<div class="modal fade modal-modern assign-expert-modal" id="assignDeveloperModal" tabindex="-1" aria-labelledby="assignDeveloperModalTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignDeveloperModalTitle"><i class="fas fa-user-plus me-2"></i>Assign to Developer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="assignDeveloperForm" data-app-loading data-loading-title="Assigning developer" data-loading-text="Please wait while the developer assignment is saved.">
                <div class="modal-body">
                    <input type="hidden" name="project_id" value="<?php echo (int) $projectId; ?>">
                    <input type="hidden" name="task_id" id="devTaskId">

                    <div class="assign-expert-intro">
                        <span class="assign-expert-intro-icon" aria-hidden="true"><i class="fas fa-code"></i></span>
                        <span class="assign-expert-intro-copy">
                            <span class="assign-expert-intro-label">Task to assign</span>
                            <span class="assign-expert-intro-title" id="devTaskTitle"></span>
                        </span>
                    </div>

                    <div class="developer-deadline-panel">
                        <span class="developer-deadline-icon" aria-hidden="true"><i class="far fa-calendar-check"></i></span>
                        <label for="devDeadline">
                            <span>Delivery deadline</span>
                            <small id="developerDeadlineHint">Must stay inside the selected roadmap window.</small>
                        </label>
                        <input type="date" name="deadline" id="devDeadline" class="form-control-custom" required aria-describedby="developerDeadlineHint">
                    </div>

                    <div class="expert-selection-heading">
                        <div>
                            <h6>Choose the right developer</h6>
                            <p>Compare profiles and active workload before assigning.</p>
                        </div>
                        <div class="expert-workload-legend" aria-label="Workload color guide">
                            <span class="is-light"><i aria-hidden="true"></i>0–2 light</span>
                            <span class="is-moderate"><i aria-hidden="true"></i>3–5 moderate</span>
                            <span class="is-high"><i aria-hidden="true"></i>6+ high</span>
                        </div>
                    </div>

                    <?php if ($developers->num_rows > 0): ?>
                        <div class="expert-selection-table-wrap">
                            <table class="expert-selection-table" aria-label="Available developers and their current workloads">
                                <thead>
                                    <tr>
                                        <th scope="col">Developer</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Active Tasks</th>
                                        <th scope="col">Capacity</th>
                                        <th scope="col" class="text-center">Select</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                    $developers->data_seek(0);
                                    $developerPosition = 0;
                                    while ($developer = $developers->fetch_assoc()):
                                        $activeTaskCount = (int) ($developer['assigned_tasks'] ?? 0);
                                        $workload = sulTaskAllocationWorkload($activeTaskCount);
                                        $developerName = (string) ($developer['full_name'] ?? 'Developer');
                                        $developerRole = trim((string) ($developer['designation'] ?? ''));
                                        $developerRole = $developerRole !== '' ? $developerRole : 'Developer';
                                        $profileUrl = sulTaskAllocationProfileUrl($developer['profile_pic'] ?? null);
                                        $isRecommended = $developerPosition === 0;
                                ?>
                                    <tr
                                        class="expert-choice-row developer-choice-row"
                                        tabindex="0"
                                        role="radio"
                                        aria-checked="false"
                                        data-developer-choice
                                        data-developer-name="<?php echo htmlspecialchars($developerName, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <td>
                                            <span class="expert-identity">
                                                <span class="expert-avatar">
                                                    <span><?php echo htmlspecialchars(sulTaskAllocationInitials($developerName)); ?></span>
                                                    <?php if ($profileUrl !== ''): ?>
                                                        <img src="<?php echo htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                                                    <?php endif; ?>
                                                </span>
                                                <span>
                                                    <span class="expert-name"><?php echo htmlspecialchars($developerName); ?></span>
                                                    <?php if ($isRecommended): ?>
                                                        <span class="expert-recommended"><i class="fas fa-star me-1" aria-hidden="true"></i>Lightest workload</span>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </td>
                                        <td><span class="expert-role"><?php echo htmlspecialchars($developerRole); ?></span></td>
                                        <td>
                                            <span class="expert-active-count is-<?php echo htmlspecialchars($workload['key']); ?>">
                                                <i class="fas fa-list-check" aria-hidden="true"></i>
                                                <?php echo $activeTaskCount; ?> active
                                            </span>
                                        </td>
                                        <td>
                                            <span class="expert-workload is-<?php echo htmlspecialchars($workload['key']); ?>">
                                                <?php echo htmlspecialchars($workload['label']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <input
                                                class="visually-hidden developer-choice-input"
                                                type="radio"
                                                name="developer_id"
                                                value="<?php echo (int) $developer['id']; ?>"
                                                required
                                                tabindex="-1"
                                            >
                                            <span class="expert-select-indicator" aria-hidden="true"><i class="fas fa-check"></i></span>
                                        </td>
                                    </tr>
                                <?php
                                        $developerPosition++;
                                    endwhile;
                                ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="expert-assignment-note">
                            <i class="fas fa-circle-info" aria-hidden="true"></i>
                            Bulk feature groups are assigned to the selected developer together.
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <p>No active developers are available in this department.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <span class="expert-selection-summary" id="developerSelectionSummary">
                        <?php echo $developers->num_rows > 0 ? 'No developer selected' : 'No developers available'; ?>
                    </span>
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_to_developer" class="btn-primary-modern" id="confirmDeveloperAssignment" disabled>
                        <i class="fas fa-user-check" aria-hidden="true"></i> Assign to Developer
                    </button>
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
const bulkAssignmentDetails = Array.from(document.querySelectorAll('.bulk-assignment-details'));

function positionBulkAssignmentMenu(details) {
    if (!details?.open) return;

    const summary = details.querySelector('summary');
    const menu = details.querySelector('.bulk-assignment-feature-list');
    if (!summary || !menu) return;

    const gap = 8;
    const edge = 12;
    const summaryRect = summary.getBoundingClientRect();
    const menuWidth = Math.min(320, Math.max(290, summaryRect.width));
    const left = Math.min(
        Math.max(edge, summaryRect.left),
        Math.max(edge, window.innerWidth - menuWidth - edge)
    );

    menu.style.width = `${menuWidth}px`;
    menu.style.left = `${left}px`;
    menu.style.top = `${summaryRect.bottom + gap}px`;

    const menuHeight = menu.getBoundingClientRect().height;
    if (summaryRect.bottom + gap + menuHeight > window.innerHeight - edge) {
        menu.style.top = `${Math.max(edge, summaryRect.top - menuHeight - gap)}px`;
    }
}

bulkAssignmentDetails.forEach(details => {
    details.addEventListener('toggle', () => {
        if (!details.open) return;

        bulkAssignmentDetails.forEach(otherDetails => {
            if (otherDetails !== details) otherDetails.open = false;
        });
        window.requestAnimationFrame(() => positionBulkAssignmentMenu(details));
    });
});

function repositionOpenBulkAssignmentMenus() {
    bulkAssignmentDetails.forEach(details => positionBulkAssignmentMenu(details));
}

window.addEventListener('resize', repositionOpenBulkAssignmentMenus);
window.addEventListener('scroll', repositionOpenBulkAssignmentMenus, true);

document.addEventListener('click', event => {
    bulkAssignmentDetails.forEach(details => {
        if (details.open && !details.contains(event.target)) details.open = false;
    });
});

document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;

    bulkAssignmentDetails.forEach(details => {
        if (!details.open) return;
        details.open = false;
        details.querySelector('summary')?.focus();
    });
});

const expertChoiceRows = Array.from(document.querySelectorAll('[data-expert-choice]'));
const confirmExpertAssignment = document.getElementById('confirmExpertAssignment');
const expertSelectionSummary = document.getElementById('expertSelectionSummary');

function clearExpertChoice() {
    expertChoiceRows.forEach(row => {
        row.classList.remove('is-selected');
        row.setAttribute('aria-checked', 'false');
        const input = row.querySelector('.expert-choice-input');
        if (input) input.checked = false;
    });

    if (confirmExpertAssignment) confirmExpertAssignment.disabled = true;
    if (expertSelectionSummary && expertChoiceRows.length > 0) {
        expertSelectionSummary.textContent = 'No expert selected';
    }
}

function selectExpertChoice(row) {
    const input = row?.querySelector('.expert-choice-input');
    if (!input) return;

    expertChoiceRows.forEach(otherRow => {
        const isSelected = otherRow === row;
        otherRow.classList.toggle('is-selected', isSelected);
        otherRow.setAttribute('aria-checked', isSelected ? 'true' : 'false');
        const otherInput = otherRow.querySelector('.expert-choice-input');
        if (otherInput) otherInput.checked = isSelected;
    });

    if (confirmExpertAssignment) confirmExpertAssignment.disabled = false;
    if (expertSelectionSummary) {
        expertSelectionSummary.replaceChildren(
            document.createTextNode('Selected: '),
            Object.assign(document.createElement('strong'), {
                textContent: row.dataset.expertName || 'Expert'
            })
        );
    }
}

expertChoiceRows.forEach(row => {
    row.addEventListener('click', () => selectExpertChoice(row));
    row.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            selectExpertChoice(row);
        }
    });
});

function openAssignExpertModal(taskId, taskTitle) {
    document.getElementById('expertTaskId').value = taskId;
    document.getElementById('expertTaskTitle').textContent = taskTitle;
    clearExpertChoice();
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

const developerChoiceRows = Array.from(document.querySelectorAll('[data-developer-choice]'));
const confirmDeveloperAssignment = document.getElementById('confirmDeveloperAssignment');
const developerSelectionSummary = document.getElementById('developerSelectionSummary');

function clearDeveloperChoice() {
    developerChoiceRows.forEach(row => {
        row.classList.remove('is-selected');
        row.setAttribute('aria-checked', 'false');
        const input = row.querySelector('.developer-choice-input');
        if (input) input.checked = false;
    });

    if (confirmDeveloperAssignment) confirmDeveloperAssignment.disabled = true;
    if (developerSelectionSummary && developerChoiceRows.length > 0) {
        developerSelectionSummary.textContent = 'No developer selected';
    }
}

function selectDeveloperChoice(row) {
    const input = row?.querySelector('.developer-choice-input');
    if (!input) return;

    developerChoiceRows.forEach(otherRow => {
        const isSelected = otherRow === row;
        otherRow.classList.toggle('is-selected', isSelected);
        otherRow.setAttribute('aria-checked', isSelected ? 'true' : 'false');
        const otherInput = otherRow.querySelector('.developer-choice-input');
        if (otherInput) otherInput.checked = isSelected;
    });

    if (confirmDeveloperAssignment) confirmDeveloperAssignment.disabled = false;
    if (developerSelectionSummary) {
        developerSelectionSummary.replaceChildren(
            document.createTextNode('Selected: '),
            Object.assign(document.createElement('strong'), {
                textContent: row.dataset.developerName || 'Developer'
            })
        );
    }
}

developerChoiceRows.forEach(row => {
    row.addEventListener('click', () => selectDeveloperChoice(row));
    row.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            selectDeveloperChoice(row);
        }
    });
});

function openAssignDeveloperModal(taskId, taskTitle, deadline, minDate = '', maxDate = '') {
    document.getElementById('devTaskId').value = taskId;
    document.getElementById('devTaskTitle').textContent = taskTitle;
    const deadlineInput = document.getElementById('devDeadline');
    deadlineInput.min = minDate || '';
    deadlineInput.max = maxDate || '';
    deadlineInput.value = deadline || '';
    clearDeveloperChoice();
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

function activateSulHashTab() {
    const hash = window.location.hash;
    if (!hash || typeof bootstrap === 'undefined' || !bootstrap.Tab) {
        return;
    }

    const trigger = document.querySelector(`[data-bs-target="${hash}"]`);
    if (trigger) {
        bootstrap.Tab.getOrCreateInstance(trigger).show();
        document.querySelector(hash)?.scrollIntoView({ block: 'start' });
    }
}

activateSulHashTab();

if (document.getElementById('expert-gantt-panel')?.classList.contains('active')) {
    initSulGanttChart();
}

document.getElementById('expert-gantt-tab')?.addEventListener('shown.bs.tab', initSulGanttChart);
</script>

<?php include '../../includes/footer.php'; ?>
