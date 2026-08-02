<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/schedule-calendar.php';
require_once '../../includes/methodology-roadmap.php';
require_once '../../includes/project-workflow.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();
$showEvortixSulLoginWelcome = true;
unset($_SESSION['evortix_sul_dashboard_welcome_pending']);
methodologyRoadmapEnsureSchema($conn);
projectWorkflowEnsureSchema($conn);
$sulDepartment = trim((string) ($user['department'] ?? ''));
$sulDepartmentLabel = projectWorkflowDepartmentLabel($sulDepartment);
$isExpertDepartmentSul = $sulDepartmentLabel === 'Expert Department';
$sulUserDepartmentClause = $sulDepartment !== ''
    ? projectWorkflowUserDepartmentClause($conn, 'u', $sulDepartment)
    : '1 = 0';
$sulProjectDepartmentClause = $sulDepartment !== ''
    ? projectWorkflowProjectDepartmentClause($conn, 'p', $sulDepartment)
    : '1 = 0';
$sulTaskDepartmentClause = $sulDepartment !== ''
    ? projectWorkflowTaskDepartmentClause($conn, 't', $sulDepartment, true)
    : '1 = 0';
$sulResourceProjectDepartmentClause = $sulDepartment !== ''
    ? projectWorkflowProjectDepartmentClause($conn, 'p_resource', $sulDepartment)
    : '1 = 0';

function sulDashboardFetchCount($conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
        return 0;
    }

    $row = $result->fetch_assoc() ?: [];
    return (int) ($row['count'] ?? 0);
}

function sulDashboardQuery($conn, string $sql)
{
    $result = $conn->query($sql);
    return (is_object($result) && method_exists($result, 'fetch_assoc')) ? $result : null;
}

function sulDashboardProfileUrl(?string $profilePic): string
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

function sulDashboardInitials(?string $name): string
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

function sulDashboardBulkEntityCountSelect(string $taskAlias = 't'): string
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

function sulDashboardBulkEntityBadge(array $task): string
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

$hasSalesApproval = dbColumnExists($conn, 'tasks', 'sales_approved');
$hasSalesChoice = dbColumnExists($conn, 'tasks', 'sales_estimate_days');
$salesApprovalCondition = $hasSalesApproval ? "t.sales_approved = 1" : "1 = 1";
$salesChoiceCondition = $hasSalesChoice ? "t.sales_estimate_days IS NOT NULL" : "1 = 1";
$hasProjectStartTime = dbColumnExists($conn, 'projects', 'timeline_start_time');
$hasProjectEndTime = dbColumnExists($conn, 'projects', 'timeline_end_time');
$projectStartTimeSql = $hasProjectStartTime ? "COALESCE(p.timeline_start_time, '09:00:00')" : "'09:00:00'";
$projectEndTimeSql = $hasProjectEndTime ? "COALESCE(p.timeline_end_time, '17:00:00')" : "'17:00:00'";
$bulkEntityCountSelect = sulDashboardBulkEntityCountSelect('t');

// Get statistics
$totalExperts = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM users u WHERE u.role IN ('expert', 'developer') AND {$sulUserDepartmentClause}");
$availableExperts = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM users u WHERE u.role IN ('expert', 'developer') AND u.status = 'active' AND {$sulUserDepartmentClause}");
$totalTasks = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks");
$overdueTasks = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks WHERE deadline < NOW() AND status != 'completed'");
$unassignedTasks = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks WHERE assigned_to IS NULL OR assigned_to = 0");
$inProgressTasks = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks WHERE status = 'in_progress'");
$completedTasks = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks WHERE status = 'completed'");
$todoTasks = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks WHERE status = 'todo'");
$activeProjects = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM projects WHERE status NOT IN ('completed', 'cancelled')");

$evortixSulProjectGroups = [
    'overdue' => [],
    'unassigned' => [],
    'overloaded' => [],
];
$evortixSulProjectRows = sulDashboardQuery($conn, "
    SELECT
        p.id,
        p.name,
        c.name AS client_name,
        SUM(CASE
            WHEN t.deadline < NOW() AND t.status != 'completed'
            THEN 1 ELSE 0
        END) AS overdue_count,
        SUM(CASE
            WHEN t.assigned_to IS NULL OR t.assigned_to = 0
            THEN 1 ELSE 0
        END) AS unassigned_count
    FROM projects p
    INNER JOIN tasks t ON t.project_id = p.id
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE {$sulProjectDepartmentClause}
      AND {$sulTaskDepartmentClause}
    GROUP BY p.id, p.name, c.name
    HAVING
        SUM(CASE WHEN t.deadline < NOW() AND t.status != 'completed' THEN 1 ELSE 0 END) > 0
        OR SUM(CASE WHEN t.assigned_to IS NULL OR t.assigned_to = 0 THEN 1 ELSE 0 END) > 0
");
if ($evortixSulProjectRows) {
    $evortixCountFields = [
        'overdue' => 'overdue_count',
        'unassigned' => 'unassigned_count',
    ];
    while ($evortixProject = $evortixSulProjectRows->fetch_assoc()) {
        foreach ($evortixCountFields as $statusKey => $countField) {
            $taskCount = (int) ($evortixProject[$countField] ?? 0);
            if ($taskCount <= 0) {
                continue;
            }
            $projectId = (int) ($evortixProject['id'] ?? 0);
            $evortixSulProjectGroups[$statusKey][] = [
                'id' => $projectId,
                'name' => (string) ($evortixProject['name'] ?? 'Project'),
                'client' => (string) ($evortixProject['client_name'] ?? 'No client'),
                'meta' => (string) ($evortixProject['client_name'] ?? 'No client'),
                'quantity_label' => $taskCount . ' task' . ($taskCount === 1 ? '' : 's'),
                'task_count' => $taskCount,
                'url' => 'task-allocation.php?project_id=' . $projectId . '&evortix_auto=1',
            ];
        }
    }
}

$evortixSulOverloadedRows = sulDashboardQuery($conn, "
    SELECT
        u.id,
        u.full_name,
        GREATEST(1, COALESCE(u.max_concurrent_tasks, 5)) AS max_concurrent_tasks,
        COUNT(t.id) AS active_tasks
    FROM users u
    LEFT JOIN tasks t
        ON t.assigned_to = u.id
       AND t.status NOT IN ('completed', 'merged', 'ready_for_release')
       AND {$sulTaskDepartmentClause}
       AND EXISTS (
           SELECT 1
           FROM projects p_resource
           WHERE p_resource.id = t.project_id
             AND {$sulResourceProjectDepartmentClause}
       )
    WHERE u.role = 'developer'
      AND u.status = 'active'
      AND {$sulUserDepartmentClause}
    GROUP BY u.id, u.full_name, u.max_concurrent_tasks
    HAVING COUNT(t.id) >= GREATEST(1, COALESCE(u.max_concurrent_tasks, 5))
    ORDER BY active_tasks DESC, u.full_name ASC
");
if ($evortixSulOverloadedRows) {
    while ($evortixDeveloper = $evortixSulOverloadedRows->fetch_assoc()) {
        $developerId = (int) ($evortixDeveloper['id'] ?? 0);
        $activeTaskCount = (int) ($evortixDeveloper['active_tasks'] ?? 0);
        $developerCapacity = max(1, (int) ($evortixDeveloper['max_concurrent_tasks'] ?? 5));
        $evortixSulProjectGroups['overloaded'][] = [
            'id' => $developerId,
            'name' => (string) ($evortixDeveloper['full_name'] ?? 'Developer'),
            'meta' => 'Capacity ' . $developerCapacity,
            'quantity_label' => $activeTaskCount . ' active task' . ($activeTaskCount === 1 ? '' : 's'),
            'task_count' => $activeTaskCount,
            'url' => 'resource-management.php?reallocate_developer_id=' . $developerId . '#resource-workload',
        ];
    }
}

foreach ($evortixSulProjectGroups as &$evortixProjects) {
    usort($evortixProjects, static function (array $left, array $right): int {
        $countComparison = ((int) ($right['task_count'] ?? 0)) <=> ((int) ($left['task_count'] ?? 0));
        return $countComparison !== 0
            ? $countComparison
            : strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });
}
unset($evortixProjects);
$evortixSulStatusTotals = [
    'overdue' => array_sum(array_column($evortixSulProjectGroups['overdue'], 'task_count')),
    'unassigned' => array_sum(array_column($evortixSulProjectGroups['unassigned'], 'task_count')),
    'overloaded' => count($evortixSulProjectGroups['overloaded']),
];

// Get tasks by priority
$highPriority = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks WHERE priority = 'high' AND status != 'completed'");
$mediumPriority = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks WHERE priority = 'medium' AND status != 'completed'");
$lowPriority = sulDashboardFetchCount($conn, "SELECT COUNT(*) as count FROM tasks WHERE priority = 'low' AND status != 'completed'");

// Get expert/developer availability
$expertAvailability = sulDashboardQuery($conn, "
    SELECT u.id, u.full_name, u.role, u.profile_pic,
           COUNT(t.id) as assigned_tasks,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer')
      AND u.status = 'active'
      AND {$sulUserDepartmentClause}
    GROUP BY u.id
    ORDER BY active_tasks ASC
");

// Get projects that need SUL task allocation/action
$projectsNeedingAllocation = sulDashboardQuery($conn, "
    SELECT
        p.*,
        c.name as client_name,
        COALESCE(ta.total_tasks, 0) as total_tasks,
        COALESCE(ta.needs_expert_count, 0) as needs_expert_count,
        COALESCE(ta.review_count, 0) as review_count,
        COALESCE(ta.ready_dev_count, 0) as ready_dev_count
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN (
        SELECT
            t.project_id,
            COUNT(t.id) as total_tasks,
            SUM(CASE
                WHEN (t.assigned_to IS NULL OR t.assigned_to = 0) AND t.status = 'todo'
                THEN 1 ELSE 0
            END) as needs_expert_count,
            SUM(CASE
                WHEN t.status = 'review' AND {$salesApprovalCondition} AND {$salesChoiceCondition}
                THEN 1 ELSE 0
            END) as review_count,
            SUM(CASE
                WHEN t.status = 'todo'
                  AND t.assigned_to IS NOT NULL
                  AND t.assigned_to != 0
                  AND EXISTS (
                      SELECT 1
                      FROM feature_estimations fe
                      WHERE fe.task_id = t.id AND fe.status = 'completed'
                  )
                  AND {$salesApprovalCondition}
                  AND {$salesChoiceCondition}
                THEN 1 ELSE 0
            END) as ready_dev_count
        FROM tasks t
        GROUP BY t.project_id
    ) ta ON ta.project_id = p.id
    WHERE p.status != 'completed'
      AND (
        COALESCE(ta.total_tasks, 0) = 0
        OR COALESCE(ta.needs_expert_count, 0) > 0
        OR COALESCE(ta.review_count, 0) > 0
        OR COALESCE(ta.ready_dev_count, 0) > 0
      )
    ORDER BY (
        COALESCE(ta.needs_expert_count, 0)
        + COALESCE(ta.review_count, 0)
        + COALESCE(ta.ready_dev_count, 0)
    ) DESC, p.created_at DESC
    LIMIT 5
");

// Get recent tasks
$recentTasks = sulDashboardQuery($conn, "
    SELECT t.*, p.name as project_name, u.full_name as assigned_to_name,
           {$bulkEntityCountSelect}
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE {$sulProjectDepartmentClause}
      AND {$sulTaskDepartmentClause}
    ORDER BY t.created_at DESC
    LIMIT 10
");

// Dashboard-level resource summaries. Detailed management stays on Resource Management.
$resourceOverviewDevelopers = [];
$resourceOverviewResult = sulDashboardQuery($conn, "
    SELECT
        u.id,
        u.full_name,
        SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) AS active_tasks,
        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks
    FROM users u
    LEFT JOIN tasks t
        ON t.assigned_to = u.id
       AND {$sulTaskDepartmentClause}
       AND EXISTS (
            SELECT 1
            FROM projects p_resource
            WHERE p_resource.id = t.project_id
              AND {$sulResourceProjectDepartmentClause}
       )
    WHERE u.role = 'developer'
      AND u.status = 'active'
      AND {$sulUserDepartmentClause}
    GROUP BY u.id, u.full_name
    ORDER BY active_tasks DESC, u.full_name ASC
");
if ($resourceOverviewResult) {
    while ($resourceRow = $resourceOverviewResult->fetch_assoc()) {
        $resourceOverviewDevelopers[] = $resourceRow;
    }
}

$resourceOverviewProjects = [];
$resourceProjectsResult = sulDashboardQuery($conn, "
    SELECT
        p.id,
        p.name,
        c.name AS client_name,
        COUNT(DISTINCT CASE WHEN u.id IS NOT NULL AND t.status != 'completed' THEN u.id END) AS assigned_resources,
        SUM(CASE WHEN u.id IS NOT NULL AND t.status != 'completed' THEN 1 ELSE 0 END) AS active_tasks
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    LEFT JOIN tasks t ON t.project_id = p.id AND {$sulTaskDepartmentClause}
    LEFT JOIN users u
        ON u.id = t.assigned_to
       AND u.role = 'developer'
       AND u.status = 'active'
       AND {$sulUserDepartmentClause}
    WHERE p.status NOT IN ('completed', 'cancelled')
      AND {$sulProjectDepartmentClause}
    GROUP BY p.id, p.name, c.name
    HAVING assigned_resources > 0
    ORDER BY active_tasks DESC, p.name ASC
    LIMIT 5
");
if ($resourceProjectsResult) {
    while ($resourceProject = $resourceProjectsResult->fetch_assoc()) {
        $resourceOverviewProjects[] = $resourceProject;
    }
}

$resourceOverviewUtilization = ['underutilized' => 0, 'optimal' => 0, 'overloaded' => 0];
$resourceOverviewAssignedTasks = 0;
$resourceOverviewCompletedTasks = 0;
foreach ($resourceOverviewDevelopers as $resourceRow) {
    $resourceActiveTasks = (int) ($resourceRow['active_tasks'] ?? 0);
    $resourceOverviewAssignedTasks += $resourceActiveTasks;
    $resourceOverviewCompletedTasks += (int) ($resourceRow['completed_tasks'] ?? 0);
    if ($resourceActiveTasks >= 5) {
        $resourceOverviewUtilization['overloaded']++;
    } elseif ($resourceActiveTasks >= 3) {
        $resourceOverviewUtilization['optimal']++;
    } else {
        $resourceOverviewUtilization['underutilized']++;
    }
}
$resourceOverviewCapacity = count($resourceOverviewDevelopers) * 5;
$resourceOverviewRate = $resourceOverviewCapacity > 0
    ? (int) round(($resourceOverviewAssignedTasks / $resourceOverviewCapacity) * 100)
    : 0;
$allocationProjectCount = $projectsNeedingAllocation ? (int) $projectsNeedingAllocation->num_rows : 0;
$recentTaskCount = $recentTasks ? (int) $recentTasks->num_rows : 0;

// Build SUL agenda from manual schedules, upcoming deadlines, and allocation actions
$upcomingAgenda = [];

$scheduleRows = sulDashboardQuery($conn, "
    SELECT title, description, start_date, start_time, schedule_type
    FROM schedules
    WHERE user_id = {$user['id']}
      AND start_date IS NOT NULL
      AND start_date >= CURDATE()
    ORDER BY start_date ASC, start_time ASC
    LIMIT 5
");
if ($scheduleRows) {
    while ($schedule = $scheduleRows->fetch_assoc()) {
        $agendaDate = trim((string) ($schedule['start_date'] ?? ''));
        $agendaTime = trim((string) ($schedule['start_time'] ?? ''));
        $upcomingAgenda[] = [
            'title' => (string) ($schedule['title'] ?? 'Scheduled item'),
            'description' => (string) ($schedule['description'] ?? ''),
            'date' => $agendaDate . ($agendaTime !== '' ? ' ' . $agendaTime : ''),
            'type' => ucfirst((string) ($schedule['schedule_type'] ?? 'Schedule')),
            'icon' => 'calendar-alt',
            'url' => '',
        ];
    }
}

$projectScheduleRows = sulDashboardQuery($conn, "
    SELECT p.id, p.name, p.timeline_start, {$projectStartTimeSql} AS timeline_start_time, p.timeline_end, {$projectEndTimeSql} AS timeline_end_time, c.name AS client_name
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    WHERE (p.sul_id = {$user['id']} OR p.sul_id IS NULL)
      AND p.status NOT IN ('completed', 'cancelled')
      AND (
        (p.timeline_start IS NOT NULL AND (p.timeline_start > CURDATE() OR (p.timeline_start = CURDATE() AND {$projectStartTimeSql} >= CURTIME())))
        OR (p.timeline_end IS NOT NULL AND (p.timeline_end > CURDATE() OR (p.timeline_end = CURDATE() AND {$projectEndTimeSql} >= CURTIME())))
      )
    ORDER BY COALESCE(p.timeline_end, p.timeline_start) ASC
    LIMIT 8
");
if ($projectScheduleRows) {
    while ($project = $projectScheduleRows->fetch_assoc()) {
        $clientDescription = 'Client: ' . (string) ($project['client_name'] ?? 'Client');
        if (!empty($project['timeline_start'])) {
            $upcomingAgenda[] = [
                'title' => 'Project start: ' . (string) ($project['name'] ?? 'Project'),
                'description' => $clientDescription,
                'date' => trim((string) ($project['timeline_start'] ?? '') . ' ' . (string) ($project['timeline_start_time'] ?? '09:00:00')),
                'type' => 'Project Start',
                'icon' => 'play-circle',
                'url' => 'task-allocation.php?project_id=' . (int) ($project['id'] ?? 0),
            ];
        }

        if (!empty($project['timeline_end'])) {
            $upcomingAgenda[] = [
                'title' => 'Project deadline: ' . (string) ($project['name'] ?? 'Project'),
                'description' => $clientDescription,
                'date' => trim((string) ($project['timeline_end'] ?? '') . ' ' . (string) ($project['timeline_end_time'] ?? '17:00:00')),
                'type' => 'Project Deadline',
                'icon' => 'flag-checkered',
                'url' => 'task-allocation.php?project_id=' . (int) ($project['id'] ?? 0),
            ];
        }
    }
}

$deadlineRows = sulDashboardQuery($conn, "
    SELECT t.id, t.title, t.deadline, t.status, p.name AS project_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE t.status != 'completed'
      AND t.deadline IS NOT NULL
      AND t.deadline >= CURDATE()
    ORDER BY t.deadline ASC
    LIMIT 5
");
if ($deadlineRows) {
    while ($task = $deadlineRows->fetch_assoc()) {
        $upcomingAgenda[] = [
            'title' => (string) ($task['title'] ?? 'Task deadline'),
            'description' => 'Project: ' . (string) ($task['project_name'] ?? 'Project'),
            'date' => trim((string) ($task['deadline'] ?? '') . ' 17:00:00'),
            'type' => 'Deadline',
            'icon' => 'flag-checkered',
            'url' => 'task-allocation.php?task_id=' . (int) ($task['id'] ?? 0),
        ];
    }
}

$actionRows = sulDashboardQuery($conn, "
    SELECT
        t.id,
        t.title,
        t.deadline,
        p.name AS project_name,
        CASE
            WHEN (t.assigned_to IS NULL OR t.assigned_to = 0) AND t.status = 'todo'
                THEN 'Assign Expert'
            WHEN t.status = 'review' AND {$salesApprovalCondition} AND {$salesChoiceCondition}
                THEN 'Review Estimation'
            WHEN t.status = 'todo'
              AND t.assigned_to IS NOT NULL
              AND t.assigned_to != 0
              AND EXISTS (
                  SELECT 1
                  FROM feature_estimations fe
                  WHERE fe.task_id = t.id AND fe.status = 'completed'
              )
              AND {$salesApprovalCondition}
              AND {$salesChoiceCondition}
                THEN 'Assign Developer'
            ELSE ''
        END AS action_label
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE t.status != 'completed'
    HAVING action_label != ''
    ORDER BY COALESCE(t.deadline, NOW()) ASC
    LIMIT 5
");
if ($actionRows) {
    while ($task = $actionRows->fetch_assoc()) {
        $upcomingAgenda[] = [
            'title' => (string) ($task['title'] ?? 'Task action'),
            'description' => 'Project: ' . (string) ($task['project_name'] ?? 'Project'),
            'date' => (string) ($task['deadline'] ?? date('Y-m-d H:i:s')),
            'type' => (string) ($task['action_label'] ?? 'Action'),
            'icon' => 'tasks',
            'url' => 'task-allocation.php?task_id=' . (int) ($task['id'] ?? 0),
        ];
    }
}

usort($upcomingAgenda, static function (array $left, array $right): int {
    return strtotime((string) ($left['date'] ?? '')) <=> strtotime((string) ($right['date'] ?? ''));
});
$upcomingAgenda = array_slice($upcomingAgenda, 0, 3);

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
    --gray-200: #e9ecef;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
}

/* Welcome Banner */
.welcome-banner {
    background: white;
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.welcome-banner h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--gray-900);
}

.welcome-banner h2 i {
    color: var(--theme-primary);
}

.sul-overview-badge {
    background-color: var(--theme-primary) !important;
    color: #fff !important;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card-modern {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-modern .stat-icon {
    position: absolute;
    right: 1rem;
    top: 1rem;
    opacity: 0.65;
    font-size: 1.6rem;
    color: var(--theme-primary);
}

.stat-card-modern .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stat-card-modern .stat-sub {
    font-size: 0.7rem;
    color: var(--gray-600);
}

/* Cards */
.card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-header h5 i {
    color: var(--theme-primary);
}

.card-body {
    padding: 1.5rem;
}

/* Unified resource overview */
.sul-resource-overview-card {
    margin-bottom: 1.5rem;
}

.sul-resource-overview-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(220px, 0.72fr) minmax(260px, 0.9fr);
}

.sul-resource-overview-section {
    min-width: 0;
    padding: 1.1rem 1.25rem;
    border-right: 1px solid var(--gray-200);
}

.sul-resource-overview-section:last-child {
    border-right: 0;
}

.sul-resource-section-title {
    align-items: center;
    color: var(--gray-900);
    display: flex;
    font-size: 0.86rem;
    font-weight: 600;
    gap: 0.5rem;
    margin: 0 0 0.9rem;
}

.sul-resource-section-title i {
    color: var(--theme-primary);
}

.sul-resource-workload-chart {
    height: 130px;
    margin-top: 0.35rem;
    position: relative;
}

.sul-resource-workload-summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.sul-resource-workload-total {
    align-items: baseline;
    border-bottom: 2px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0 0.55rem;
}

.sul-resource-workload-total span {
    color: var(--gray-600);
    font-size: 0.7rem;
    text-transform: uppercase;
}

.sul-resource-workload-total strong {
    color: var(--gray-900);
    font-size: 1.25rem;
    font-weight: 600;
}

.sul-resource-workload-total.is-open {
    border-color: rgba(240, 163, 23, 0.55);
}

.sul-resource-workload-total.is-completed {
    border-color: rgba(27, 141, 80, 0.5);
}

.sul-resource-utilization-chart {
    height: 175px;
    margin: 0 auto;
    max-width: 210px;
    position: relative;
}

.sul-resource-rate {
    align-items: baseline;
    border-top: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    margin-top: 0.8rem;
    padding-top: 0.75rem;
}

.sul-resource-rate span {
    color: var(--gray-600);
    font-size: 0.72rem;
    text-transform: uppercase;
}

.sul-resource-rate strong {
    color: var(--theme-primary);
    font-size: 1.15rem;
    font-weight: 600;
}

.sul-resource-project-list {
    display: grid;
}

.sul-resource-project-item {
    align-items: center;
    border-bottom: 1px solid var(--gray-200);
    display: grid;
    gap: 0.75rem;
    grid-template-columns: minmax(0, 1fr) auto;
    padding: 0.7rem 0;
}

.sul-resource-project-item:first-child {
    padding-top: 0;
}

.sul-resource-project-item:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.sul-resource-project-name {
    color: var(--gray-900);
    font-size: 0.8rem;
    font-weight: 600;
    margin: 0;
}

.sul-resource-project-meta {
    color: var(--gray-600);
    display: flex;
    flex-wrap: wrap;
    font-size: 0.68rem;
    gap: 0.35rem 0.65rem;
    margin-top: 0.2rem;
}

.sul-resource-project-link {
    align-items: center;
    border: 1px solid #c9dcea;
    border-radius: 8px;
    color: var(--theme-primary);
    display: inline-flex;
    height: 32px;
    justify-content: center;
    text-decoration: none;
    transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
    width: 32px;
}

.sul-resource-project-link:hover {
    background: #eef7fc;
    border-color: var(--theme-primary);
    color: var(--theme-primary);
    transform: translateY(-1px);
}

.sul-resource-empty {
    color: var(--gray-600);
    font-size: 0.78rem;
    padding: 2rem 0;
    text-align: center;
}

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--gray-50);
}

.data-table th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.data-table tr:hover {
    background: var(--gray-50);
}

.team-member-profile {
    align-items: center;
    display: flex;
    gap: 0.75rem;
    min-width: 0;
}

.team-member-avatar {
    align-items: center;
    background: linear-gradient(145deg, #dff1fb 0%, #c8e4f4 100%);
    border: 1px solid #bedcec;
    border-radius: 50%;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 38px;
    font-size: 0.72rem;
    font-weight: 700;
    height: 38px;
    justify-content: center;
    letter-spacing: 0.03em;
    overflow: hidden;
    position: relative;
    width: 38px;
}

.team-member-avatar img {
    height: 100%;
    inset: 0;
    object-fit: cover;
    position: absolute;
    width: 100%;
}

.team-member-name {
    color: var(--gray-900);
    font-weight: 600;
    line-height: 1.3;
    min-width: 0;
}

/* List Group Items */
.list-group-item {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    border-left: none;
    border-right: none;
    border-top: none;
}

.list-group-item:last-child {
    border-bottom: none;
}

.list-group-item:hover {
    background: var(--gray-50);
}

/* Timeline */
.timeline-item {
    position: relative;
    padding-left: 2rem;
    padding-bottom: 1rem;
    border-left: 2px solid var(--gray-200);
}

.timeline-item:last-child {
    border-left: 2px solid transparent;
    padding-bottom: 0;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -6px;
    top: 0;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--theme-primary);
}

/* Badges */
.badge-role-expert {
    background: #fef3c7;
    color: #d97706;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-role-developer {
    background: #dbeafe;
    color: #2563eb;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status-overloaded {
    background: #fee2e2;
    color: #dc2626;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status-busy {
    background: #fef3c7;
    color: #d97706;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status-available {
    background: #dbeafe;
    color: #2563eb;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status-free {
    background: #d1fae5;
    color: #059669;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-priority-high {
    background: #fee2e2;
    color: #dc2626;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-priority-medium {
    background: #fef3c7;
    color: #d97706;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-priority-low {
    background: #d1fae5;
    color: #059669;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
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
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-sm-modern {
    padding: 0.3rem 0.6rem;
    font-size: 0.7rem;
}

/* Modal */
.modal-modern .modal-content {
    border-radius: 24px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    padding: 1.5rem;
    border: none;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-modern .modal-body {
    padding: 2rem;
}

.modal-modern .modal-footer {
    padding: 1rem 2rem 2rem;
    border-top: 1px solid var(--gray-200);
}

/* Empty State */
.empty-state-mini {
    text-align: center;
    padding: 2rem 1.5rem;
}

.empty-state-mini i {
    font-size: 2rem;
    color: var(--gray-200);
    margin-bottom: 0.5rem;
}

.empty-state-mini p {
    color: var(--gray-600);
    font-size: 0.8rem;
    margin: 0;
}

.allocation-status-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0;
}

.allocation-status-tag {
    border-radius: 999px;
    padding: 0.22rem 0.55rem;
    background: #eef6ff;
    color: var(--theme-primary);
    font-size: 0.68rem;
    font-weight: 700;
}

.allocation-status-tag.warning {
    background: #fff7df;
    color: #a16207;
}

.allocation-status-tag.success {
    background: #e7f8ef;
    color: #047857;
}

.sul-dashboard-grid {
    display: grid;
    gap: 1.5rem;
}

.sul-table-card {
    min-height: 100%;
}

.sul-bounded-row {
    --sul-panel-height: 480px;
    align-items: stretch;
}

.sul-bounded-row > [class*="col-"] {
    display: flex;
    min-width: 0;
}

.sul-bounded-row > [class*="col-"] > .card,
.sul-bounded-row > .sul-insights-column > .delay-insights-wrap {
    width: 100%;
    height: var(--sul-panel-height);
    min-height: 0;
}

.dashboard-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.dashboard-panel-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    padding: 0.32rem 0.6rem;
    border: 1px solid rgba(31, 99, 146, 0.16);
    border-radius: 999px;
    background: #f7fbfe;
    color: var(--theme-primary);
    font-size: 0.68rem;
    font-weight: 700;
    white-space: nowrap;
}

.allocation-project-card,
.recent-tasks-panel {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.allocation-project-list {
    display: grid;
    align-content: start;
    gap: 0.5rem;
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 0.65rem;
    scrollbar-gutter: stable;
}

.allocation-project-item {
    position: relative;
    display: grid;
    gap: 0.55rem;
    padding: 0.75rem 0.75rem 0.75rem 0.85rem;
    border: 1px solid var(--gray-200);
    border-left: 3px solid transparent;
    border-radius: 12px;
    background: #fff;
    transition: transform 180ms ease, border-color 180ms ease, background-color 180ms ease, box-shadow 180ms ease;
}

.allocation-project-item:hover {
    transform: translateY(-1px);
    border-left-color: var(--theme-primary);
    background: #f8fcff;
    box-shadow: 0 8px 18px rgba(15, 76, 129, 0.08);
}

.allocation-project-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.allocation-project-title-wrap {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    min-width: 0;
}

.allocation-project-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #edf7fc;
    color: var(--theme-primary);
}

.allocation-project-title {
    margin: 0;
    color: var(--gray-900);
    font-size: 0.86rem;
    font-weight: 650;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.allocation-project-action {
    flex: 0 0 auto;
    padding: 0.38rem 0.65rem;
    text-decoration: none;
}

.allocation-project-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.85rem;
    color: var(--gray-600);
    font-size: 0.72rem;
    line-height: 1.35;
}

.allocation-project-meta span {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    min-width: 0;
}

.allocation-project-meta i {
    color: #5c83a5;
}

.sul-insights-column .delay-insights-wrap,
.sul-insights-column .delay-compact-card {
    height: 100%;
}

.sul-insights-column .delay-insights-wrap {
    margin-bottom: 0;
}

.sul-insights-column .delay-portfolio-body {
    grid-template-columns: minmax(190px, 0.75fr) minmax(260px, 1.25fr);
    align-content: center;
    flex: 1 1 auto;
    min-height: 0;
    gap: 1rem;
    padding: 1rem 1.25rem 1.25rem;
}

.sul-insights-column .delay-portfolio-chart-shell {
    width: min(220px, 100%);
}

.sul-insights-column .delay-portfolio-summary {
    gap: 0.65rem;
}

.sul-insights-column .delay-portfolio-legend-row,
.sul-insights-column .delay-portfolio-average {
    padding-top: 0.55rem;
    padding-bottom: 0.55rem;
}

.sul-chart-legend {
    display: grid;
    gap: 0.65rem;
    margin-top: 1rem;
}

.sul-task-status-chart {
    position: absolute;
    inset: 1.5rem;
    min-height: 0;
}

.sul-task-status-chart canvas {
    display: block;
    width: 100% !important;
    height: 100% !important;
}

.sul-task-status-card .card-body {
    position: relative;
    flex: 1 1 auto;
    min-height: 328px;
}

.sul-chart-legend span {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    padding: 0.65rem 0.75rem;
    border-radius: 12px;
    background: var(--gray-50);
    color: var(--gray-600);
    font-size: 0.82rem;
    font-weight: 700;
}

.recent-task-list {
    display: grid;
    align-content: start;
    gap: 0.5rem;
    height: 100%;
    max-height: none;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 4px;
    scrollbar-gutter: stable;
}

.recent-task-card {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    min-width: 0;
    padding: 0.72rem 0.8rem;
    border: 1px solid var(--gray-200);
    border-left: 3px solid transparent;
    border-radius: 12px;
    background: #fff;
    width: 100%;
    transition: transform 180ms ease, border-color 180ms ease, background-color 180ms ease, box-shadow 180ms ease;
}

.recent-task-card:hover {
    transform: translateY(-1px);
    border-left-color: var(--theme-primary);
    background: #f8fcff;
    box-shadow: 0 8px 18px rgba(15, 76, 129, 0.08);
}

.recent-task-card > div:first-child {
    min-width: 0;
}

.recent-task-card-head {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: flex-start;
    gap: 0.75rem;
}

.recent-task-card-head-main {
    min-width: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.recent-task-title {
    margin: 0;
    color: var(--gray-900);
    font-size: 0.92rem;
    font-weight: 700;
    line-height: 1.35;
    overflow-wrap: anywhere;
    white-space: normal;
    word-break: break-word;
}

.bulk-entity-badge {
    align-items: center;
    align-self: flex-start;
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

.recent-task-description {
    color: var(--gray-600);
    font-size: 0.8rem;
    line-height: 1.45;
    overflow-wrap: anywhere;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    white-space: normal;
    word-break: break-word;
}

.recent-tasks-panel .card-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
    padding: 0.65rem;
}

.allocation-project-list::-webkit-scrollbar,
.recent-task-list::-webkit-scrollbar {
    width: 7px;
}

.allocation-project-list::-webkit-scrollbar-track,
.recent-task-list::-webkit-scrollbar-track {
    background: transparent;
}

.allocation-project-list::-webkit-scrollbar-thumb,
.recent-task-list::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: rgba(31, 99, 146, 0.24);
}

.recent-task-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.75rem;
    color: var(--gray-600);
    font-size: 0.76rem;
}

.recent-task-meta span {
    min-width: 0;
    overflow-wrap: anywhere;
}

.recent-task-actions {
    display: flex;
    gap: 0.35rem;
    flex-shrink: 0;
    align-items: flex-start;
}

.recent-task-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recent-task-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-project-item {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-chart-legend span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-status-tag {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .dashboard-panel-count {
    background: #0a1b2c;
    border-color: rgba(90, 166, 222, 0.28);
    color: #8cc7ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-entity-badge {
    background: rgba(194, 65, 12, 0.16);
    border-color: rgba(251, 146, 60, 0.32);
    color: #fdba74;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bulk-entity-badge i {
    color: #fb923c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h1,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h3,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recent-task-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-project-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .team-member-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body strong {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .team-member-avatar {
    background: linear-gradient(145deg, #12344d 0%, #0d283d 100%);
    border-color: rgba(138, 210, 255, 0.22);
    color: #8ad8ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-sub,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recent-task-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-chart-legend span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-mini p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select {
    background-color: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-today-badge,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-date-group,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-item {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-next-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-date-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-text {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-subtitle,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-today-badge span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-next-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-next-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-time,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-kicker {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-open-btn,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-more-btn {
    background: rgba(31, 99, 146, 0.14) !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: var(--theme-primary) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-light {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-resource-overview-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-resource-rate,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-resource-project-item {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-resource-section-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-resource-project-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-resource-workload-total strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sul-resource-project-link {
    background: #0a1b2c;
    border-color: rgba(90, 166, 222, 0.28);
    color: #8cc7ff;
}

@media (max-width: 1200px) {
    .sul-resource-overview-grid {
        grid-template-columns: minmax(0, 1.4fr) minmax(230px, 0.7fr);
    }

    .sul-resource-overview-section:nth-child(2) {
        border-right: 0;
    }

    .sul-resource-overview-section:last-child {
        border-top: 1px solid var(--gray-200);
        grid-column: 1 / -1;
    }
}

/* Responsive */
@media (max-width: 768px) {
    .welcome-banner {
        padding: 1.5rem;
    }
    
    .welcome-banner h2 {
        font-size: 1.5rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .stat-card-modern .stat-number {
        font-size: 2rem;
    }

    .sul-resource-overview-grid {
        grid-template-columns: 1fr;
    }

    .sul-resource-overview-section,
    .sul-resource-overview-section:nth-child(2) {
        border-bottom: 1px solid var(--gray-200);
        border-right: 0;
    }

    .sul-resource-overview-section:last-child {
        border-bottom: 0;
        border-top: 0;
        grid-column: auto;
    }

    .recent-task-card {
        gap: 0.75rem;
    }

    .recent-task-card-head {
        grid-template-columns: 1fr;
    }

    .recent-task-actions {
        justify-content: flex-end;
    }

    .recent-task-list {
        max-height: none;
    }

    .sul-bounded-row {
        --sul-panel-height: 430px;
    }

    .sul-risk-row > .sul-insights-column > .delay-insights-wrap {
        height: auto;
    }

    .sul-risk-row .delay-portfolio-body {
        grid-template-columns: 1fr;
        padding: 1rem;
    }

    .sul-risk-row .delay-portfolio-chart-shell {
        width: min(220px, 100%);
    }
}
.chat-bot-speech.evortix-dashboard-summary { top:42%; right:calc(100% + 18px); width:min(390px,calc(100vw - 190px)); min-width:340px; padding:1rem 1.05rem 1.05rem; border-radius:22px 22px 8px 22px; pointer-events:auto; animation:evortixSulSummaryReveal .55s cubic-bezier(.16,.86,.22,1) both }
.evortix-dashboard-summary-head { position:relative; z-index:2; display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem; padding-left:.75rem }
.evortix-dashboard-summary-eyebrow { color:#157baa; font-size:.65rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase }
.chat-bot-speech .evortix-dashboard-summary-title { min-height:0; padding:0; color:#083a5d; font-size:.98rem; line-height:1.3 }
.evortix-dashboard-summary-close { display:grid; place-items:center; flex:0 0 30px; width:30px; height:30px; border:0; border-radius:50%; background:rgba(255,255,255,.7); color:#0d6f9f; box-shadow:0 5px 14px rgba(15,119,183,.15) }
.evortix-dashboard-summary-intro { position:relative; z-index:2; margin:.5rem 0 .7rem; color:#175f8f; font-size:.74rem; font-weight:700 }
.evortix-dashboard-summary-grid { position:relative; z-index:2; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.48rem }
.evortix-dashboard-summary-metric { min-width:0; padding:.62rem .68rem; border:1px solid rgba(8,123,185,.17); border-radius:12px; background:rgba(255,255,255,.78); box-shadow:0 6px 16px rgba(15,119,183,.09) }
.evortix-dashboard-summary-metric-top { display:flex; align-items:center; justify-content:space-between; gap:.5rem }
.evortix-dashboard-summary-metric i { color:#138ac1; font-size:.78rem }
.chat-bot-speech .evortix-dashboard-summary-value { min-height:0; padding:0; color:#083a5d; font-size:1.1rem; line-height:1 }
.evortix-dashboard-summary-label { margin-top:.36rem; color:#0b466d; font-size:.71rem; font-weight:850 }
.evortix-dashboard-summary-meta { margin-top:.16rem; color:#5b7f97; font-size:.63rem; font-weight:700 }
.evortix-dashboard-summary-metric.is-overdue .evortix-dashboard-summary-value,.evortix-dashboard-summary-metric.is-overdue i { color:#c2413b }
@keyframes evortixSulSummaryReveal { from { opacity:0; transform:translateY(-50%) translateX(18px) scale(.94) } to { opacity:1; transform:translateY(-50%) translateX(0) scale(1) } }
@media(max-width:640px){.chat-bot-speech.evortix-dashboard-summary{right:calc(100% + 9px);width:min(270px,calc(100vw - 145px));min-width:220px}.evortix-dashboard-summary-grid{grid-template-columns:1fr;max-height:238px;overflow-y:auto}}
</style>

<div class="container-fluid px-4 py-4 role-dashboard-shell">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm role-dashboard-hero">
                <div class="card-body p-4">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-8 role-dashboard-hero-copy">
                            <span class="badge sul-overview-badge mb-2 px-3 py-2">SUL Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Service Unit Leader Dashboard - Monitor resources, tasks, and team performance for optimal project delivery.</p>
                        </div>
                        <div class="col-lg-4 d-none d-lg-flex justify-content-end">
                            <div class="role-3d-scene role-3d-scene--sul" aria-hidden="true">
                                <div class="role-3d-platform">
                                    <span class="role-3d-node role-3d-node--one"><i class="fas fa-tasks"></i></span>
                                    <span class="role-3d-node role-3d-node--two"><i class="fas fa-project-diagram"></i></span>
                                    <span class="role-3d-orbit"></span>
                                    <span class="role-3d-core"><i class="fas fa-users"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card-modern">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Department Team</div>
            <div class="stat-number"><?php echo $totalExperts; ?></div>
            <div class="stat-sub"><?php echo $availableExperts; ?> available</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-tasks stat-icon"></i>
            <div class="stat-label">Total Tasks</div>
            <div class="stat-number"><?php echo $totalTasks; ?></div>
            <div class="stat-sub"><?php echo $unassignedTasks; ?> unassigned</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-play-circle stat-icon"></i>
            <div class="stat-label">In Progress</div>
            <div class="stat-number"><?php echo $inProgressTasks; ?></div>
            <div class="stat-sub"><?php echo $completedTasks; ?> completed</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-exclamation-triangle stat-icon"></i>
            <div class="stat-label">Overdue Tasks</div>
            <div class="stat-number" style="color: #dc2626;"><?php echo $overdueTasks; ?></div>
            <div class="stat-sub">Requires immediate attention</div>
        </div>
    </div>

    <!-- Unified resource summaries; detailed controls remain in Resource Management. -->
    <div class="card sul-resource-overview-card">
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <h5><i class="fas fa-users-cog"></i> Resource Overview</h5>
            <a href="resource-management.php" class="sul-resource-project-link" title="Open Resource Details &amp; Workload" aria-label="Open Resource Details and Workload">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        <div class="sul-resource-overview-grid">
            <section class="sul-resource-overview-section" aria-labelledby="resourceWorkloadTitle">
                <h6 class="sul-resource-section-title" id="resourceWorkloadTitle"><i class="fas fa-chart-bar" aria-hidden="true"></i> Resource Workload Overview</h6>
                <?php if (($resourceOverviewAssignedTasks + $resourceOverviewCompletedTasks) > 0): ?>
                    <div class="sul-resource-workload-summary" aria-label="Department workload totals">
                        <div class="sul-resource-workload-total is-open"><span>Open</span><strong><?php echo $resourceOverviewAssignedTasks; ?></strong></div>
                        <div class="sul-resource-workload-total is-completed"><span>Completed</span><strong><?php echo $resourceOverviewCompletedTasks; ?></strong></div>
                    </div>
                    <div class="sul-resource-workload-chart"><canvas id="resourceWorkloadChart"></canvas></div>
                <?php else: ?>
                    <div class="sul-resource-empty">No department workload activity is available yet.</div>
                <?php endif; ?>
            </section>

            <section class="sul-resource-overview-section" aria-labelledby="resourceUtilizationTitle">
                <h6 class="sul-resource-section-title" id="resourceUtilizationTitle"><i class="fas fa-chart-pie" aria-hidden="true"></i> Resource Utilization</h6>
                <?php if ($resourceOverviewDevelopers !== []): ?>
                    <div class="sul-resource-utilization-chart"><canvas id="resourceUtilizationChart"></canvas></div>
                    <div class="sul-resource-rate"><span>Utilization rate</span><strong><?php echo $resourceOverviewRate; ?>%</strong></div>
                <?php else: ?>
                    <div class="sul-resource-empty">No utilization data is available.</div>
                <?php endif; ?>
            </section>

            <section class="sul-resource-overview-section" aria-labelledby="activeResourceProjectsTitle">
                <h6 class="sul-resource-section-title" id="activeResourceProjectsTitle"><i class="fas fa-project-diagram" aria-hidden="true"></i> Projects with Active Resources</h6>
                <?php if ($resourceOverviewProjects !== []): ?>
                    <div class="sul-resource-project-list">
                        <?php foreach ($resourceOverviewProjects as $resourceProject): ?>
                            <article class="sul-resource-project-item">
                                <div>
                                    <p class="sul-resource-project-name"><?php echo htmlspecialchars((string) $resourceProject['name']); ?></p>
                                    <div class="sul-resource-project-meta">
                                        <span><?php echo htmlspecialchars((string) $resourceProject['client_name']); ?></span>
                                        <span><?php echo (int) $resourceProject['assigned_resources']; ?> resource<?php echo (int) $resourceProject['assigned_resources'] === 1 ? '' : 's'; ?></span>
                                        <span><?php echo (int) $resourceProject['active_tasks']; ?> open task<?php echo (int) $resourceProject['active_tasks'] === 1 ? '' : 's'; ?></span>
                                    </div>
                                </div>
                                <a href="task-allocation.php?project_id=<?php echo (int) $resourceProject['id']; ?>" class="sul-resource-project-link" title="Manage project resources" aria-label="Manage resources for <?php echo htmlspecialchars((string) $resourceProject['name']); ?>">
                                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sul-resource-empty">No projects currently have active department resources.</div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Row 1: Task Status Overview + Calendar -->
    <div class="row g-3 mb-4">
        <div class="col-xl-7">
            <div class="card h-100 sul-task-status-card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie"></i> Task Status Overview</h5>
                </div>
                <div class="card-body">
                    <div class="sul-task-status-chart">
                        <canvas id="taskStatusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-alt"></i> Upcoming Schedule</h5>
                </div>
                <div class="card-body p-0">
                    <?php renderScheduleAgendaPreview([], $upcomingAgenda, 'Calendar'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Priority Distribution + Projects Needing Task Allocation -->
    <div class="row g-3 mb-4 sul-bounded-row">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar"></i> Priority Distribution</h5>
                </div>
                <div class="card-body">
                    <canvas id="priorityChart" style="height: 220px; width: 100%;"></canvas>
                    <div class="sul-chart-legend">
                        <span><span class="badge-priority-high">High</span> <?php echo $highPriority; ?> tasks</span>
                        <span><span class="badge-priority-medium">Medium</span> <?php echo $mediumPriority; ?> tasks</span>
                        <span><span class="badge-priority-low">Low</span> <?php echo $lowPriority; ?> tasks</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card allocation-project-card">
                <div class="card-header dashboard-panel-header">
                    <h5><i class="fas fa-project-diagram"></i> Projects Needing Task Allocation</h5>
                    <span class="dashboard-panel-count"><?php echo $allocationProjectCount; ?></span>
                </div>
                <div class="card-body allocation-project-list">
                    <?php if($projectsNeedingAllocation && $projectsNeedingAllocation->num_rows > 0): ?>
                        <?php while($project = $projectsNeedingAllocation->fetch_assoc()): ?>
                        <?php
                            $totalTasksForProject = (int) ($project['total_tasks'] ?? 0);
                            $needsExpertCount = (int) ($project['needs_expert_count'] ?? 0);
                            $reviewCount = (int) ($project['review_count'] ?? 0);
                            $readyDevCount = (int) ($project['ready_dev_count'] ?? 0);
                            $timelineStartTimestamp = !empty($project['timeline_start']) ? strtotime((string) $project['timeline_start']) : false;
                            $timelineEndTimestamp = !empty($project['timeline_end']) ? strtotime((string) $project['timeline_end']) : false;
                            if ($timelineStartTimestamp && $timelineEndTimestamp) {
                                $allocationTimeline = date('M d', $timelineStartTimestamp) . ' – ' . date('M d, Y', $timelineEndTimestamp);
                            } elseif ($timelineStartTimestamp) {
                                $allocationTimeline = 'Starts ' . date('M d, Y', $timelineStartTimestamp);
                            } elseif ($timelineEndTimestamp) {
                                $allocationTimeline = 'Due ' . date('M d, Y', $timelineEndTimestamp);
                            } else {
                                $allocationTimeline = 'Timeline not set';
                            }
                        ?>
                        <article class="allocation-project-item">
                            <div class="allocation-project-head">
                                <div class="allocation-project-title-wrap">
                                    <span class="allocation-project-icon" aria-hidden="true"><i class="fas fa-project-diagram"></i></span>
                                    <h6 class="allocation-project-title"><?php echo htmlspecialchars($project['name']); ?></h6>
                                </div>
                                <a href="task-allocation.php?project_id=<?php echo $project['id']; ?>" class="btn-primary-modern allocation-project-action">
                                    <i class="fas fa-tasks"></i><span>Allocate</span>
                                </a>
                            </div>
                            <div class="allocation-project-meta">
                                <span><i class="fas fa-building"></i><?php echo htmlspecialchars($project['client_name']); ?></span>
                                <span><i class="far fa-calendar-alt"></i><?php echo htmlspecialchars($allocationTimeline); ?></span>
                                <span><i class="fas fa-list-check"></i><?php echo $totalTasksForProject; ?> task<?php echo $totalTasksForProject === 1 ? '' : 's'; ?></span>
                            </div>
                            <div class="allocation-status-tags">
                                <?php if($totalTasksForProject === 0): ?>
                                    <span class="allocation-status-tag warning">No tasks yet</span>
                                <?php endif; ?>
                                <?php if($needsExpertCount > 0): ?>
                                    <span class="allocation-status-tag warning"><?php echo $needsExpertCount; ?> need expert</span>
                                <?php endif; ?>
                                <?php if($reviewCount > 0): ?>
                                    <span class="allocation-status-tag"><?php echo $reviewCount; ?> for review</span>
                                <?php endif; ?>
                                <?php if($readyDevCount > 0): ?>
                                    <span class="allocation-status-tag success"><?php echo $readyDevCount; ?> ready for dev</span>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state-mini">
                            <i class="fas fa-check-circle"></i>
                            <p>No projects pending task allocation</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: Portfolio Project Risk + Recent Tasks -->
    <div class="row g-3 mb-4 sul-bounded-row sul-risk-row">
        <div class="col-xl-7 sul-insights-column">
            <?php
            delayInsightsRenderCompactList($conn, $user, [
                'limit' => 200,
                'display' => 'portfolio_chart',
                'title' => 'Overall Project Risk',
                'subtitle' => 'Portfolio-wide delivery risk across all projects visible to the Service Unit Leader.',
                'show_empty' => true,
                'empty_title' => 'No project insights yet',
                'empty_message' => 'Project insight signals will appear here when they become available.',
            ]);
            ?>
        </div>

        <div class="col-xl-5">
            <div class="card sul-table-card recent-tasks-panel">
                <div class="card-header dashboard-panel-header">
                    <h5><i class="fas fa-history"></i> Recent Tasks</h5>
                    <span class="dashboard-panel-count"><?php echo $recentTaskCount; ?></span>
                </div>
                <div class="card-body">
                    <div class="recent-task-list">
                        <?php if($recentTasks && $recentTasks->num_rows > 0): ?>
                        <?php while($task = $recentTasks->fetch_assoc()): 
                            $deadlineTimestamp = !empty($task['deadline']) ? strtotime((string) $task['deadline']) : false;
                            $daysLeft = $deadlineTimestamp ? ceil(($deadlineTimestamp - time()) / (60 * 60 * 24)) : null;
                        ?>
                        <div class="recent-task-card">
                            <div class="recent-task-card-head">
                                <div class="recent-task-card-head-main">
                                    <h6 class="recent-task-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                                    <?php echo sulDashboardBulkEntityBadge($task); ?>
                                    <?php if(!empty($task['description'])): ?>
                                        <div class="recent-task-description"><?php echo nl2br(htmlspecialchars((string) $task['description'])); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="recent-task-actions">
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn-primary-modern btn-sm-modern" title="View task" aria-label="View <?php echo htmlspecialchars((string) $task['title']); ?>" onclick="viewTask(<?php echo $task['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if(!$task['assigned_to'] && $isExpertDepartmentSul): ?>
                                        <button type="button" class="btn-primary-modern btn-sm-modern" title="Assign task" aria-label="Assign <?php echo htmlspecialchars((string) $task['title']); ?>" onclick="assignTaskTo(<?php echo $task['id']; ?>)">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="recent-task-meta">
                                <span><i class="fas fa-folder me-1"></i><?php echo htmlspecialchars($task['project_name'] ?? 'No project'); ?></span>
                                <span><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'); ?></span>
                                <?php if($deadlineTimestamp): ?>
                                    <span><i class="far fa-calendar-alt me-1"></i><?php echo date('M d, Y', $deadlineTimestamp); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="recent-task-footer">
                                <span class="badge-priority-<?php echo htmlspecialchars((string) $task['priority']); ?>"><?php echo ucfirst((string) $task['priority']); ?></span>
                                <span class="badge-status-<?php echo htmlspecialchars((string) $task['status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', (string) $task['status'])); ?>
                                </span>
                                <?php if($daysLeft !== null && $daysLeft <= 2 && $task['status'] != 'completed'): ?>
                                    <small class="text-danger fw-semibold"><?php echo $daysLeft; ?> days left</small>
                                <?php endif; ?>
                            </div>
                        </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state-mini">
                                <i class="fas fa-clipboard-list"></i>
                                <p>No recent tasks yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 4: Team Availability & Workload -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card sul-table-card">
                <div class="card-header">
                    <h5><i class="fas fa-users"></i> Team Availability & Workload</h5>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Active Tasks</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($expertAvailability && $expertAvailability->num_rows > 0): ?>
                            <?php while($expert = $expertAvailability->fetch_assoc()): ?>
                            <?php
                                $statusClass = $expert['active_tasks'] >= 5 ? 'overloaded' : ($expert['active_tasks'] >= 3 ? 'busy' : ($expert['active_tasks'] > 0 ? 'available' : 'free'));
                                $statusText = $expert['active_tasks'] >= 5 ? 'Overloaded' : ($expert['active_tasks'] >= 3 ? 'Busy' : ($expert['active_tasks'] > 0 ? 'Available' : 'Free'));
                                $expertName = trim((string) ($expert['full_name'] ?? 'Team member'));
                                $expertInitials = sulDashboardInitials($expertName);
                                $expertProfileUrl = sulDashboardProfileUrl($expert['profile_pic'] ?? null);
                            ?>
                            <tr>
                                <td>
                                    <div class="team-member-profile">
                                        <span class="team-member-avatar" aria-hidden="true">
                                            <span><?php echo htmlspecialchars($expertInitials); ?></span>
                                            <?php if ($expertProfileUrl !== ''): ?>
                                                <img src="<?php echo htmlspecialchars($expertProfileUrl); ?>" alt="" onerror="this.remove()">
                                            <?php endif; ?>
                                        </span>
                                        <span class="team-member-name"><?php echo htmlspecialchars($expertName); ?></span>
                                    </div>
                                </td>
                                <td><span class="badge-role-<?php echo $expert['role']; ?>"><?php echo strtoupper($expert['role']); ?></span></td>
                                <td><?php echo $expert['active_tasks']; ?></td>
                                <td><span class="badge-status-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                <td>
                                    <a href="task-allocation.php" class="btn-primary-modern">
                                        <i class="fas fa-tasks"></i> Assign
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    No active experts or developers are assigned to <?php echo htmlspecialchars($sulDepartmentLabel ?: 'your department'); ?>.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Assign Task Modal -->
<div class="modal fade modal-modern" id="assignTaskModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i> Assign Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Task:</strong> <span id="assignTaskTitle"></span></p>
                <div class="mb-3">
                    <label class="form-label">Assign to:</label>
                    <select id="assignToUser" class="form-select">
                        <option value="">Select a team member</option>
                        <?php
                        $assignableRoleSql = $isExpertDepartmentSul ? "'expert'" : "'developer'";
                        $assignableDepartmentSql = $sulDepartment !== '' ? ' AND ' . projectWorkflowUserDepartmentClause($conn, 'u', $sulDepartment) : ' AND 1 = 0';
                        $availableUsers = sulDashboardQuery($conn, "
                            SELECT u.id, u.full_name, u.role
                            FROM users u
                            WHERE u.role IN ({$assignableRoleSql})
                              AND u.status = 'active'
                              {$assignableDepartmentSql}
                            ORDER BY u.full_name
                        ");
                        if ($availableUsers):
                        while($assignableUser = $availableUsers->fetch_assoc()):
                        ?>
                        <option value="<?php echo $assignableUser['id']; ?>"><?php echo htmlspecialchars($assignableUser['full_name']); ?> (<?php echo strtoupper($assignableUser['role']); ?>)</option>
                        <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="sendEmail" checked>
                        <label class="form-check-label" for="sendEmail">Send Email Notification</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-primary-modern" onclick="confirmAssign()">Assign Task</button>
            </div>
        </div>
    </div>
</div>

<!-- Task Details Modal -->
<div class="modal fade modal-modern" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i> Task Details</h5>
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
let currentTaskId = null;

const resourceOverviewData = <?php
    echo json_encode([
        'workload' => [$resourceOverviewAssignedTasks, $resourceOverviewCompletedTasks],
        'utilization' => [
            (int) $resourceOverviewUtilization['underutilized'],
            (int) $resourceOverviewUtilization['optimal'],
            (int) $resourceOverviewUtilization['overloaded'],
        ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

const resourceWorkloadCanvas = document.getElementById('resourceWorkloadChart');
if (resourceWorkloadCanvas) {
    new Chart(resourceWorkloadCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['Department workload'],
            datasets: [
                {
                    label: 'Open tasks',
                    data: [resourceOverviewData.workload[0]],
                    backgroundColor: '#f0a317',
                    borderRadius: 7,
                    borderSkipped: false,
                    maxBarThickness: 48
                },
                {
                    label: 'Completed tasks',
                    data: [resourceOverviewData.workload[1]],
                    backgroundColor: '#1b8d50',
                    borderRadius: 7,
                    borderSkipped: false,
                    maxBarThickness: 48
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    stacked: true,
                    grid: { color: 'rgba(113, 131, 153, 0.12)' },
                    ticks: { precision: 0 }
                },
                y: { stacked: true, display: false, grid: { display: false } }
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label(context) {
                            return `${context.label}: ${Number(context.raw || 0)} tasks`;
                        }
                    }
                }
            }
        }
    });
}

const resourceUtilizationCanvas = document.getElementById('resourceUtilizationChart');
if (resourceUtilizationCanvas) {
    new Chart(resourceUtilizationCanvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Available', 'Optimal', 'At capacity'],
            datasets: [{
                data: resourceOverviewData.utilization,
                backgroundColor: ['#1b8d50', '#f0a317', '#e3262e'],
                borderColor: '#ffffff',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 9, usePointStyle: true } } }
        }
    });
}

// Task Status Chart
const taskCtx = document.getElementById('taskStatusChart').getContext('2d');
new Chart(taskCtx, {
    type: 'doughnut',
    data: {
        labels: ['To Do', 'In Progress', 'Completed', 'Overdue'],
        datasets: [{
            data: [<?php echo $todoTasks; ?>, <?php echo $inProgressTasks; ?>, <?php echo $completedTasks; ?>, <?php echo $overdueTasks; ?>],
            backgroundColor: ['#6c757d', '#f59e0b', '#10b981', '#dc2626'],
            borderWidth: 0,
            hoverOffset: 10
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    font: { size: 11 }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.raw || 0;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((value / total) * 100).toFixed(1);
                        return `${label}: ${value} (${percentage}%)`;
                    }
                }
            }
        }
    }
});

// Priority Chart
const priorityCtx = document.getElementById('priorityChart').getContext('2d');
new Chart(priorityCtx, {
    type: 'bar',
    data: {
        labels: ['High', 'Medium', 'Low'],
        datasets: [{
            label: 'Number of Tasks',
            data: [<?php echo $highPriority; ?>, <?php echo $mediumPriority; ?>, <?php echo $lowPriority; ?>],
            backgroundColor: ['#dc2626', '#f59e0b', '#10b981'],
            borderRadius: 8,
            barPercentage: 0.65
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 },
                grid: { color: 'rgba(0, 0, 0, 0.05)' }
            },
            x: {
                grid: { display: false }
            }
        },
        plugins: {
            legend: { display: false }
        }
    }
});

function assignTask(userId, userName) {
    $.ajax({
        url: '../../api/get-unassigned-tasks.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            const tasks = response;
            let html = '<div class="list-group">';
            tasks.forEach(task => {
                html += `
                    <a href="#" class="list-group-item list-group-item-action" onclick="selectTaskForUser(${task.id}, ${userId}, '${task.title}')">
                        <strong>${task.title}</strong><br>
                        <small>Project: ${task.project_name}</small>
                    </a>
                `;
            });
            html += '</div>';
            $('#assignTaskModal .modal-body').html(html);
            $('#assignTaskModal').modal('show');
        }
    });
}

async function selectTaskForUser(taskId, userId, taskTitle) {
    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm({
            title: 'Assign Task',
            titleIcon: 'fas fa-user-check',
            subject: `Assign "${taskTitle}" to this team member?`,
            message: 'The team member will receive this task and an email notification.',
            icon: 'fas fa-list-check fa-lg',
            confirmIcon: 'fas fa-check',
            confirmText: 'Assign Task'
        })
        : false;

    if (!confirmed) {
        return;
    }

    $.ajax({
        url: '../../api/assign-task.php',
        method: 'POST',
        dataType: 'json',
        data: { task_id: taskId, user_id: userId, send_email: true },
        success: function(response) {
            const result = response;
            if(result.success) {
                window.appToast?.('Task assigned', 'The task was assigned successfully.');
                setTimeout(() => location.reload(), 1500);
            } else {
                window.appToast?.('Assignment failed', result.error || 'The task could not be assigned.', 'error');
            }
        },
        error: function() {
            window.appToast?.('Assignment failed', 'The server could not complete the assignment.', 'error');
        }
    });
}

function assignTaskTo(taskId) {
    currentTaskId = taskId;
    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        dataType: 'json',
        data: { id: taskId },
        success: function(response) {
            const task = response;
            $('#assignTaskTitle').text(task.title);
            $('#assignTaskModal').modal('show');
        }
    });
}

function confirmAssign() {
    const userId = $('#assignToUser').val();
    const sendEmail = $('#sendEmail').is(':checked');
    
    if(!userId) {
        window.appToast?.('Team member required', 'Select a team member before assigning the task.', 'warning');
        return;
    }
    
    $.ajax({
        url: '../../api/assign-task.php',
        method: 'POST',
        dataType: 'json',
        data: { task_id: currentTaskId, user_id: userId, send_email: sendEmail },
        success: function(response) {
            const result = response;
            if(result.success) {
                $('#assignTaskModal').modal('hide');
                window.appToast?.('Task assigned', 'The task was assigned successfully.');
                setTimeout(() => location.reload(), 1500);
            } else {
                window.appToast?.('Assignment failed', result.error || 'The task could not be assigned.', 'error');
            }
        },
        error: function() {
            window.appToast?.('Assignment failed', 'The server could not complete the assignment.', 'error');
        }
    });
}

function viewTask(taskId) {
    $('#taskDetailsContent').html(`
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `);

    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        dataType: 'json',
        data: { id: taskId },
        success: function(response) {
            const task = response;
            if (task.error) {
                $('#taskDetailsContent').html(`<div class="alert alert-danger mb-0">${escapeHtml(task.error)}</div>`);
            } else {
                const bulkEntityCount = Number(task.bulk_entity_task_count || 0);
                const bulkEntityBadge = bulkEntityCount > 1
                    ? `<span class="bulk-entity-badge ms-2" title="Architect bulk placement: these features share one roadmap developer/entity and should move together."><i class="fas fa-layer-group" aria-hidden="true"></i> Bulk assignment: ${bulkEntityCount} features</span>`
                    : '';
                $('#taskDetailsContent').html(`
                    <div class="row g-4">
                        <div class="col-md-8">
                            <h5 class="mb-3 d-flex align-items-center gap-2 flex-wrap">${escapeHtml(task.title || '')}${bulkEntityBadge}</h5>
                            <p class="text-muted">${escapeHtml(task.description || 'No description provided.')}</p>
                            <div class="mb-2"><strong>Project:</strong> ${escapeHtml(task.project_name || 'No project')}</div>
                            <div class="mb-2"><strong>Client:</strong> ${escapeHtml(task.client_name || 'No client')}</div>
                            <div class="mb-2"><strong>Deadline:</strong> ${task.deadline ? new Date(task.deadline).toLocaleDateString() : 'Not set'}</div>
                            ${renderSulDashboardDeveloperWork(task)}
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded-3">
                                <p class="mb-2"><strong>Status:</strong> ${escapeHtml((task.status || '').replaceAll('_', ' '))}</p>
                                <p class="mb-2"><strong>Priority:</strong> ${escapeHtml(task.priority || '')}</p>
                                <p class="mb-0"><strong>Assigned To:</strong> ${escapeHtml(task.assigned_to_name || 'Unassigned')}</p>
                            </div>
                        </div>
                    </div>
                `);
            }
            new bootstrap.Modal(document.getElementById('taskDetailsModal')).show();
        },
        error: function() {
            $('#taskDetailsContent').html('<div class="alert alert-danger mb-0">Unable to load task details.</div>');
            new bootstrap.Modal(document.getElementById('taskDetailsModal')).show();
        }
    });
}

function renderSulDashboardDeveloperWork(task) {
    const artifacts = Array.isArray(task.department_artifacts) ? task.department_artifacts : [];
    const gitUpdates = Array.isArray(task.git_updates) ? task.git_updates : [];
    const gitRows = gitUpdates.map(update => `<div class="border rounded-3 p-3 mb-2">
        <div><strong><i class="fas fa-code-branch"></i> Branch:</strong> ${escapeHtml(update.branch_name || 'Not specified')}</div>
        ${update.commit_hash ? `<div class="small mt-1"><strong>Commit:</strong> ${escapeHtml(update.commit_hash)}</div>` : ''}
        ${update.notes ? `<p class="mb-2 mt-2">${escapeHtml(update.notes)}</p>` : ''}
        ${update.pull_request_url ? `<a class="me-2" href="${escapeHtml(update.pull_request_url)}" target="_blank" rel="noopener">Pull request</a>` : ''}
        ${update.repository_url ? `<a href="${escapeHtml(update.repository_url)}" target="_blank" rel="noopener">Repository</a>` : ''}
    </div>`).join('');
    const artifactRows = artifacts.map(artifact => `<div class="border rounded-3 p-3 mb-2">
        <strong>${escapeHtml(artifact.title || 'Developer submission')}</strong>
        ${artifact.notes ? `<p class="mb-2 mt-2">${escapeHtml(artifact.notes)}</p>` : ''}
        ${artifact.file_url ? `<a class="me-2" href="${escapeHtml(artifact.file_url)}" target="_blank" rel="noopener">${escapeHtml(artifact.original_file_name || 'View upload')}</a>` : ''}
        ${artifact.external_url ? `<a href="${escapeHtml(artifact.external_url)}" target="_blank" rel="noopener">Open link</a>` : ''}
    </div>`).join('');
    return `<div class="mt-4"><h6><i class="fas fa-laptop-code me-2"></i>Developer work for review</h6>
        ${gitRows || artifactRows ? `${gitRows}${artifactRows}` : '<div class="text-muted">No links, uploads, or Git updates have been submitted yet.</div>'}
    </div>`;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

<?php if ($showEvortixSulLoginWelcome): ?>
document.addEventListener('DOMContentLoaded', () => {
    window.setTimeout(() => {
        const float = document.getElementById('chat-bot-float');
        const speech = float?.querySelector('.chat-bot-speech');
        if (!float || !speech) return;
        speech.className = 'chat-bot-speech evortix-role-dashboard-summary is-compact has-project-list';
        const projectGroups = <?php echo json_encode(
            $evortixSulProjectGroups,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
        const statusConfig = {
            overdue: {
                label: 'Overdue',
                icon: 'fa-triangle-exclamation',
                total: <?php echo (int) ($evortixSulStatusTotals['overdue'] ?? 0); ?>,
                listTitle: 'Overdue projects',
                entityLabel: 'project',
                empty: 'No overdue projects need attention.'
            },
            unassigned: {
                label: 'Unassigned',
                icon: 'fa-user-plus',
                total: <?php echo (int) ($evortixSulStatusTotals['unassigned'] ?? 0); ?>,
                listTitle: 'Unassigned projects',
                entityLabel: 'project',
                empty: 'No unassigned projects need attention.'
            },
            overloaded: {
                label: 'Overload',
                icon: 'fa-right-left',
                total: <?php echo (int) ($evortixSulStatusTotals['overloaded'] ?? 0); ?>,
                listTitle: 'Overloaded developers',
                entityLabel: 'developer',
                empty: 'No developers need reallocation.'
            }
        };
        const statusOrder = ['overdue', 'unassigned', 'overloaded'];
        const initialStatus = statusOrder.find(status => statusConfig[status].total > 0) || 'overdue';
        speech.innerHTML = `
            <div class="evortix-role-summary-head is-compact"><strong class="evortix-role-summary-title">Live Overview</strong><button type="button" class="evortix-role-summary-close" aria-label="Dismiss dashboard overview"><i class="fas fa-xmark"></i></button></div>
            <div class="evortix-role-summary-intro is-compact">${statusConfig.overloaded.total} overloaded, ${statusConfig.unassigned.total} unassigned, and ${statusConfig.overdue.total} overdue.</div>
            <div class="evortix-project-status-tabs" role="tablist" aria-label="Workload attention status">
                ${statusOrder.map(status => `
                    <button type="button" class="evortix-project-status-tab" data-evortix-project-status="${status}" role="tab" aria-selected="false">
                        <i class="fas ${statusConfig[status].icon}" aria-hidden="true"></i>
                        <span>${statusConfig[status].label}</span>
                        <strong>${statusConfig[status].total}</strong>
                    </button>
                `).join('')}
            </div>
            <div class="evortix-project-list-head">
                <strong data-evortix-project-list-title></strong>
                <span data-evortix-project-list-count></span>
            </div>
            <div class="evortix-project-list" data-evortix-project-list></div>`;
        const renderProjectList = status => {
            const config = statusConfig[status];
            const projects = Array.isArray(projectGroups[status]) ? projectGroups[status] : [];
            speech.querySelectorAll('[data-evortix-project-status]').forEach(tab => {
                const isSelected = tab.dataset.evortixProjectStatus === status;
                tab.classList.toggle('is-active', isSelected);
                tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            });
            const title = speech.querySelector('[data-evortix-project-list-title]');
            const count = speech.querySelector('[data-evortix-project-list-count]');
            const list = speech.querySelector('[data-evortix-project-list]');
            if (title) title.textContent = config.listTitle;
            if (count) count.textContent = `${projects.length} ${config.entityLabel}${projects.length === 1 ? '' : 's'}`;
            if (!list) return;
            list.innerHTML = projects.length
                ? projects.map(project => `
                    <a class="evortix-project-link" href="${escapeHtml(project.url)}">
                        <span class="evortix-project-link-copy">
                            <strong>${escapeHtml(project.name)}</strong>
                            <small>${escapeHtml(project.meta || project.client || '')} &middot; ${escapeHtml(project.quantity_label || `${Number(project.task_count) || 0} tasks`)}</small>
                        </span>
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                `).join('')
                : `<div class="evortix-project-list-empty">${escapeHtml(config.empty)}</div>`;
        };
        speech.querySelectorAll('[data-evortix-project-status]').forEach(tab => {
            tab.addEventListener('click', () => renderProjectList(tab.dataset.evortixProjectStatus));
        });
        renderProjectList(initialStatus);
        window.chatBotWidgetRobot?.setMode?.('empty-bed');
        window.chatBotFloatRobot?.setMode?.('standing');
        float.hidden = false;
        float.setAttribute('aria-hidden', 'false');
        speech.setAttribute('aria-hidden', 'false');
        speech.querySelector('.evortix-role-summary-close')?.addEventListener('click', () => {
            speech.hidden = true;
            speech.setAttribute('aria-hidden', 'true');
        });
        document.dispatchEvent(new CustomEvent('evortix:dashboard-welcome-complete'));
    }, 700);
});
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
