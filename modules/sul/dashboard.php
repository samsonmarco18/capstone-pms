<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();
$hasSalesApproval = dbColumnExists($conn, 'tasks', 'sales_approved');
$hasSalesChoice = dbColumnExists($conn, 'tasks', 'sales_estimate_days');
$salesApprovalCondition = $hasSalesApproval ? "t.sales_approved = 1" : "1 = 1";
$salesChoiceCondition = $hasSalesChoice ? "t.sales_estimate_days IS NOT NULL" : "1 = 1";

// Get statistics
$totalExperts = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'expert' OR role = 'developer'")->fetch_assoc()['count'];
$availableExperts = $conn->query("SELECT COUNT(*) as count FROM users WHERE (role = 'expert' OR role = 'developer') AND status = 'active'")->fetch_assoc()['count'];
$totalTasks = $conn->query("SELECT COUNT(*) as count FROM tasks")->fetch_assoc()['count'];
$overdueTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE deadline < NOW() AND status != 'completed'")->fetch_assoc()['count'];
$unassignedTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to IS NULL OR assigned_to = 0")->fetch_assoc()['count'];
$inProgressTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'in_progress'")->fetch_assoc()['count'];
$completedTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'completed'")->fetch_assoc()['count'];
$todoTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'todo'")->fetch_assoc()['count'];

// Get tasks by priority
$highPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE priority = 'high' AND status != 'completed'")->fetch_assoc()['count'];
$mediumPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE priority = 'medium' AND status != 'completed'")->fetch_assoc()['count'];
$lowPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE priority = 'low' AND status != 'completed'")->fetch_assoc()['count'];

// Get expert/developer availability
$expertAvailability = $conn->query("
    SELECT u.id, u.full_name, u.role, 
           COUNT(t.id) as assigned_tasks,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
    GROUP BY u.id
    ORDER BY active_tasks ASC
");

// Get projects that need SUL task allocation/action
$projectsNeedingAllocation = $conn->query("
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
$recentTasks = $conn->query("
    SELECT t.*, p.name as project_name, u.full_name as assigned_to_name
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assigned_to = u.id
    ORDER BY t.created_at DESC
    LIMIT 10
");

// Build SUL agenda from manual schedules, upcoming deadlines, and allocation actions
$upcomingAgenda = [];

$scheduleRows = $conn->query("
    SELECT title, description, start_date, start_time, schedule_type
    FROM schedules
    WHERE user_id = {$user['id']}
      AND start_date IS NOT NULL
      AND start_date >= CURDATE()
    ORDER BY start_date ASC, start_time ASC
    LIMIT 5
");
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

$deadlineRows = $conn->query("
    SELECT t.id, t.title, t.deadline, t.status, p.name AS project_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE t.status != 'completed'
      AND t.deadline IS NOT NULL
      AND t.deadline >= NOW()
    ORDER BY t.deadline ASC
    LIMIT 5
");
while ($task = $deadlineRows->fetch_assoc()) {
    $upcomingAgenda[] = [
        'title' => (string) ($task['title'] ?? 'Task deadline'),
        'description' => 'Project: ' . (string) ($task['project_name'] ?? 'Project'),
        'date' => (string) ($task['deadline'] ?? ''),
        'type' => 'Deadline',
        'icon' => 'flag-checkered',
        'url' => 'task-allocation.php?task_id=' . (int) ($task['id'] ?? 0),
    ];
}

$actionRows = $conn->query("
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

usort($upcomingAgenda, static function (array $left, array $right): int {
    return strtotime((string) ($left['date'] ?? '')) <=> strtotime((string) ($right['date'] ?? ''));
});
$upcomingAgenda = array_slice($upcomingAgenda, 0, 5);

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
    margin-top: 0.5rem;
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
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-12">
                            <span class="badge sul-overview-badge mb-2 px-3 py-2">SUL Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Service Unit Leader Dashboard - Monitor resources, tasks, and team performance for optimal project delivery.</p>
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
            <div class="stat-label">Total Experts/Devs</div>
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

    <?php
    delayInsightsRenderCompactList($conn, $user, [
        'limit' => 4,
        'title' => 'Project Insight List',
        'subtitle' => 'Small one-line project signals with direct links to the full insight page.',
        'button_label' => 'View Project',
    ]);
    ?>

    <!-- Row 1: Task Status + Projects Needing Allocation -->
    <div class="row g-3 mb-4">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie"></i> Task Status Overview</h5>
                </div>
                <div class="card-body">
                    <canvas id="taskStatusChart" style="height: 280px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-xl-5">
            <!-- Upcoming Schedule -->
            <div class="card mb-3">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-alt"></i> Upcoming Schedule</h5>
                </div>
                <div class="card-body">
                    <?php if($upcomingAgenda): ?>
                        <?php foreach($upcomingAgenda as $agendaItem): ?>
                        <div class="timeline-item mb-3">
                            <h6 class="mb-1">
                                <?php if(!empty($agendaItem['url'])): ?>
                                    <a href="<?php echo htmlspecialchars($agendaItem['url']); ?>" class="text-decoration-none text-dark">
                                        <?php echo htmlspecialchars($agendaItem['title']); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($agendaItem['title']); ?>
                                <?php endif; ?>
                            </h6>
                            <small class="text-muted">
                                <i class="fas fa-<?php echo htmlspecialchars($agendaItem['icon']); ?> me-1"></i>
                                <?php echo htmlspecialchars($agendaItem['type']); ?>
                                <span class="mx-1">-</span>
                                <i class="far fa-clock me-1"></i>
                                <?php echo date('M d, Y h:i A', strtotime((string) $agendaItem['date'])); ?>
                            </small>
                            <?php if(!empty($agendaItem['description'])): ?>
                            <p class="mb-0 small text-muted mt-1"><?php echo htmlspecialchars($agendaItem['description']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-mini">
                            <i class="fas fa-calendar-week"></i>
                            <p>No upcoming agenda items</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Projects Needing Allocation -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-project-diagram"></i> Projects Needing Task Allocation</h5>
                </div>
                <div class="card-body p-0">
                    <?php if($projectsNeedingAllocation->num_rows > 0): ?>
                        <?php while($project = $projectsNeedingAllocation->fetch_assoc()): ?>
                        <?php
                            $totalTasksForProject = (int) ($project['total_tasks'] ?? 0);
                            $needsExpertCount = (int) ($project['needs_expert_count'] ?? 0);
                            $reviewCount = (int) ($project['review_count'] ?? 0);
                            $readyDevCount = (int) ($project['ready_dev_count'] ?? 0);
                        ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($project['name']); ?></h6>
                                    <small class="text-muted">
                                        <i class="fas fa-building me-1"></i>
                                        Client: <?php echo htmlspecialchars($project['client_name']); ?>
                                    </small>
                                    <div class="mt-1">
                                        <small class="text-muted">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            Timeline: <?php echo date('M d', strtotime($project['timeline_start'])); ?> - <?php echo date('M d, Y', strtotime($project['timeline_end'])); ?>
                                        </small>
                                    </div>
                                    <div class="mt-1">
                                        <small class="text-muted">
                                            <i class="fas fa-tasks me-1"></i>
                                            Pending tasks: <?php echo (int) $project['pending_task_count']; ?>
                                        </small>
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
                                </div>
                                <a href="task-allocation.php?project_id=<?php echo $project['id']; ?>" class="btn-primary-modern">
                                    <i class="fas fa-tasks"></i> Allocate
                                </a>
                            </div>
                        </div>
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

    <!-- Row 2: Team Availability + Priority Distribution -->
    <div class="row g-3 mb-4">
        <!-- Team Availability -->
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-users"></i> Team Availability & Workload</h5>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Active Tasks</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </thead>
                        <tbody>
                            <?php while($expert = $expertAvailability->fetch_assoc()): ?>
                            <?php
                                $statusClass = $expert['active_tasks'] >= 5 ? 'overloaded' : ($expert['active_tasks'] >= 3 ? 'busy' : ($expert['active_tasks'] > 0 ? 'available' : 'free'));
                                $statusText = $expert['active_tasks'] >= 5 ? 'Overloaded' : ($expert['active_tasks'] >= 3 ? 'Busy' : ($expert['active_tasks'] > 0 ? 'Available' : 'Free'));
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($expert['full_name']); ?></td>
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
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar"></i> Priority Distribution</h5>
                </div>
                <div class="card-body">
                    <canvas id="priorityChart" style="height: 220px; width: 100%;"></canvas>
                    <div class="mt-3 d-flex justify-content-between gap-2 flex-wrap">
                        <span><span class="badge-priority-high">High</span> <?php echo $highPriority; ?> tasks</span>
                        <span><span class="badge-priority-medium">Medium</span> <?php echo $mediumPriority; ?> tasks</span>
                        <span><span class="badge-priority-low">Low</span> <?php echo $lowPriority; ?> tasks</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Tasks -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-history"></i> Recent Tasks</h5>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Assigned To</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Deadline</th>
                                <th>Actions</th>
                            </thead>
                        <tbody>
                            <?php while($task = $recentTasks->fetch_assoc()): 
                                $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($task['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 50)); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($task['project_name']); ?></td>
                                <td><?php echo htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                <td><span class="badge-priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span></td>
                                <td>
                                    <span class="badge-status-<?php echo $task['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($task['deadline'])); ?>
                                    <?php if($daysLeft <= 2 && $task['status'] != 'completed'): ?>
                                        <br><small class="text-danger"><?php echo $daysLeft; ?> days left</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button class="btn-primary-modern btn-sm-modern" onclick="viewTask(<?php echo $task['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if(!$task['assigned_to']): ?>
                                        <a href="task-allocation.php?task_id=<?php echo (int) $task['id']; ?>" class="btn-primary-modern btn-sm-modern" title="Assign task">
                                            <i class="fas fa-user-plus"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
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
                        $availableUsers = $conn->query("SELECT id, full_name, role FROM users WHERE role IN ('expert', 'developer') AND status = 'active' ORDER BY full_name");
                        while($user = $availableUsers->fetch_assoc()):
                        ?>
                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?> (<?php echo strtoupper($user['role']); ?>)</option>
                        <?php endwhile; ?>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
let currentTaskId = null;

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
        maintainAspectRatio: true,
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

function selectTaskForUser(taskId, userId, taskTitle) {
    if(confirm(`Assign "${taskTitle}" to this team member?`)) {
        $.ajax({
            url: '../../api/assign-task.php',
            method: 'POST',
            dataType: 'json',
            data: { task_id: taskId, user_id: userId, send_email: true },
            success: function(response) {
                const result = response;
                if(result.success) {
                    alert('✓ Task assigned successfully');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('✗ Error: ' + result.error);
                }
            }
        });
    }
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
        alert('Please select a team member');
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
                alert('✓ Task assigned successfully');
                $('#assignTaskModal').modal('hide');
                setTimeout(() => location.reload(), 1500);
            } else {
                alert('✗ Error: ' + result.error);
            }
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
                $('#taskDetailsContent').html(`
                    <div class="row g-4">
                        <div class="col-md-8">
                            <h5 class="mb-3">${escapeHtml(task.title || '')}</h5>
                            <p class="text-muted">${escapeHtml(task.description || 'No description provided.')}</p>
                            <div class="mb-2"><strong>Project:</strong> ${escapeHtml(task.project_name || 'No project')}</div>
                            <div class="mb-2"><strong>Client:</strong> ${escapeHtml(task.client_name || 'No client')}</div>
                            <div class="mb-2"><strong>Deadline:</strong> ${task.deadline ? new Date(task.deadline).toLocaleDateString() : 'Not set'}</div>
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

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}
</script>

<?php include '../../includes/footer.php'; ?>
