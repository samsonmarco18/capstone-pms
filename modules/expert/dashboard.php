<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/schedule-calendar.php';
require_once '../../includes/dashboard-planner.php';
requireRole('expert');

$conn = getConnection();
$user = getCurrentUser();
dashboardPlannerHandleRequest($conn, (int) $user['id']);
$dashboardPlanner = dashboardPlannerBuild($conn, (int) $user['id']);
$hasProjectStartTime = dbColumnExists($conn, 'projects', 'timeline_start_time');
$hasProjectEndTime = dbColumnExists($conn, 'projects', 'timeline_end_time');
$projectStartTimeSql = $hasProjectStartTime ? "COALESCE(p.timeline_start_time, '09:00:00')" : "'09:00:00'";
$projectEndTimeSql = $hasProjectEndTime ? "COALESCE(p.timeline_end_time, '17:00:00')" : "'17:00:00'";

// Get estimation statistics
$totalEstimations = $conn->query("SELECT COUNT(*) as count FROM feature_estimations WHERE expert_id = {$user['id']}")->fetch_assoc()['count'];
$pendingEstimations = $conn->query("SELECT COUNT(*) as count FROM feature_estimations WHERE expert_id = {$user['id']} AND status = 'pending'")->fetch_assoc()['count'];
$completedEstimations = $conn->query("SELECT COUNT(*) as count FROM feature_estimations WHERE expert_id = {$user['id']} AND status = 'completed'")->fetch_assoc()['count'];

// Get assigned tasks for estimation
$pendingTasks = $conn->query("
    SELECT t.*, p.name as project_name 
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = {$user['id']} 
    AND t.status != 'completed'
    ORDER BY t.deadline ASC
    LIMIT 5
");

// Get team composition recommendations
$teamCompositions = $conn->query("
    SELECT tc.*, p.name as project_name 
    FROM team_compositions tc
    JOIN projects p ON tc.project_id = p.id
    WHERE tc.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY tc.created_at DESC
    LIMIT 5
");

// Get recent feature estimations for this expert
$recentFeatureEstimates = $conn->query("
    SELECT 
        fe.task_id,
        fe.min_days,
        fe.likely_days,
        fe.max_days,
        fe.phase_name,
        fe.status,
        COALESCE(fe.submitted_at, fe.updated_at) as latest_update,
        t.title as task_title,
        p.name as project_name,
        p.id as project_id
    FROM feature_estimations fe
    JOIN tasks t ON fe.task_id = t.id
    JOIN projects p ON fe.project_id = p.id
    WHERE fe.expert_id = {$user['id']}
      AND COALESCE(fe.submitted_at, fe.updated_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY latest_update DESC
    LIMIT 5
");

// Get this expert's own manual schedule. Assigned work deadlines are listed in the agenda below.
$schedules = $conn->query("
    SELECT title, description, schedule_type, start_date, start_time, status, project_id
    FROM schedules
    WHERE user_id = {$user['id']}
      AND status <> 'cancelled'
      AND (
          start_date > CURDATE()
          OR (start_date = CURDATE() AND COALESCE(start_time, '00:00:00') >= CURTIME())
      )
    ORDER BY start_date, start_time
    LIMIT 5
");
// Build Expert agenda from assigned estimation tasks and deadlines.
$expertAgenda = [];

$agendaTaskRows = $conn->query("
    SELECT t.id, t.title, t.description, t.deadline, t.status, p.name AS project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = {$user['id']}
      AND t.status != 'completed'
    ORDER BY COALESCE(t.deadline, NOW()) ASC
    LIMIT 8
");
while ($task = $agendaTaskRows->fetch_assoc()) {
    $hasDeadline = !empty($task['deadline']);
    $expertAgenda[] = [
        'title' => (string) ($task['title'] ?? 'Assigned task'),
        'description' => 'Project: ' . (string) ($task['project_name'] ?? 'Project'),
        'date' => $hasDeadline ? (string) $task['deadline'] : date('Y-m-d H:i:s'),
        'type' => ((string) ($task['status'] ?? '') === 'todo') ? 'Estimate Needed' : 'Assigned Task',
        'icon' => ((string) ($task['status'] ?? '') === 'todo') ? 'calculator' : 'flag-checkered',
        'url' => 'feature-estimation.php?task_id=' . (int) ($task['id'] ?? 0),
    ];
}

usort($expertAgenda, static function (array $left, array $right): int {
    return strtotime((string) ($left['date'] ?? '')) <=> strtotime((string) ($right['date'] ?? ''));
});
$expertAgenda = array_slice($expertAgenda, 0, 5);

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

.welcome-banner p {
    margin-bottom: 0;
    font-size: 0.9rem;
    color: var(--gray-600);
}

.expert-overview-badge {
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

.stat-card {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card .stat-icon {
    position: absolute;
    right: 1.5rem;
    top: 1.5rem;
    opacity: 0.65;
    font-size: 3rem;
    color: var(--theme-primary);
}

.stat-card .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card .stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stat-card .stat-sub {
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

.card-no-border {
    border: none;
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

/* List Group Items */
.list-group-item-custom {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    transition: background 0.2s;
}

.list-group-item-custom:last-child {
    border-bottom: none;
}

.list-group-item-custom:hover {
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

.timeline-item h6 {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: var(--gray-900);
}

.timeline-item small {
    font-size: 0.7rem;
    color: var(--gray-600);
}

.timeline-item p {
    font-size: 0.8rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
    margin-bottom: 0;
}

/* Table Styles */
.table-responsive-custom {
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
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
    font-size: 0.85rem;
}

.data-table tr:hover {
    background: var(--gray-50);
}

/* Badges */
.badge-urgent {
    background: #fee2e2;
    color: #dc2626;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-estimate {
    background: var(--theme-primary);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

/* Buttons */
.btn-estimate {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.4rem 1rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-estimate:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Progress Bar */
.progress-custom {
    height: 6px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar-custom {
    height: 100%;
    background: linear-gradient(90deg, var(--theme-primary) 0%, var(--theme-light) 100%);
    border-radius: 10px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 2rem;
}

.empty-state i {
    font-size: 3rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state p {
    color: var(--gray-500);
    font-size: 0.85rem;
    margin: 0;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
}

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
    
    .stat-card .stat-number {
        font-size: 2rem;
    }
    
    .card-header {
        padding: 1rem 1.25rem;
    }
    
    .card-body {
        padding: 1.25rem;
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
                            <span class="badge expert-overview-badge mb-2 px-3 py-2">Expert Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Expert/Senior Dashboard - Technical estimations, cost analysis, and project planning for successful delivery.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Total Estimations</div>
                            <div class="h1 fw-bold mb-2" style="color: #1f6392;"><?php echo $totalEstimations; ?></div>
                            <small class="text-muted">All time</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-chart-line" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Pending</div>
                            <div class="h1 fw-bold mb-2" style="color: #f59e0b;"><?php echo $pendingEstimations; ?></div>
                            <small class="text-muted">Awaiting estimation</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-clock" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Completed</div>
                            <div class="h1 fw-bold mb-2" style="color: #10b981;"><?php echo $completedEstimations; ?></div>
                            <small class="text-muted">Submitted</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-check-circle" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Active Projects</div>
                            <div class="h1 fw-bold mb-2" style="color: #1f6392;"><?php echo $pendingTasks->num_rows; ?></div>
                            <small class="text-muted">Currently estimating</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-project-diagram" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
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

    <div class="row g-4">
        <div class="col-xl-7">
            <!-- Pending Feature Estimations -->
            <div class="card mb-4 card-no-border">
                <div class="card-header">
                    <h5><i class="fas fa-calculator"></i> Pending Feature Estimations</h5>
                </div>
                <div>
                    <?php if($pendingTasks->num_rows > 0): ?>
                        <?php while($task = $pendingTasks->fetch_assoc()): 
                            $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="list-group-item-custom">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <h6 class="fw-semibold mb-0"><?php echo htmlspecialchars($task['title']); ?></h6>
                                        <?php if($daysLeft <= 3): ?>
                                            <span class="badge-urgent">Urgent</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mb-1">
                                        <small class="text-muted"><i class="fas fa-folder me-1"></i><?php echo htmlspecialchars($task['project_name']); ?></small>
                                        <small class="text-muted ms-3"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></small>
                                    </div>
                                    <p class="small text-muted mb-0"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?></p>
                                </div>
                                <a href="feature-estimation.php?task_id=<?php echo $task['id']; ?>" class="btn-estimate ms-3">
                                    <i class="fas fa-calculator"></i> Estimate
                                </a>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending estimations</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Team Compositions -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-users"></i> Recent Team Compositions</h5>
                </div>
                <div class="table-responsive-custom">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Role</th>
                                <th class="text-center">Count</th>
                                <th class="text-center">Allocation %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($tc = $teamCompositions->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($tc['project_name']); ?></td>
                                <td><?php echo htmlspecialchars($tc['role']); ?></td>
                                <td class="text-center"><?php echo $tc['required_count']; ?></td>
                                <td class="text-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress-custom flex-grow-1">
                                            <div class="progress-bar-custom" style="width: <?php echo $tc['allocation_percentage']; ?>%;"></div>
                                        </div>
                                        <span class="small"><?php echo $tc['allocation_percentage']; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if($teamCompositions->num_rows == 0): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No team compositions available</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-xl-5">
            <!-- Upcoming Schedule -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-alt"></i> Upcoming Schedule</h5>
                </div>
                <div class="card-body p-0">
                    <?php renderScheduleAgendaPreview($schedules, $expertAgenda, 'Calendar'); ?>
                </div>
            </div>
            
            <!-- Recent Feature Estimates -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line"></i> Recent Feature Estimates</h5>
                </div>
                <div>
                    <?php if($recentFeatureEstimates->num_rows > 0): ?>
                        <?php while($estimate = $recentFeatureEstimates->fetch_assoc()): ?>
                        <?php
                            $minDays = (int) ($estimate['min_days'] ?? 0);
                            $likelyDays = (int) ($estimate['likely_days'] ?? 0);
                            $maxDays = (int) ($estimate['max_days'] ?? 0);
                            $pertDays = round(($minDays + (4 * $likelyDays) + $maxDays) / 6, 1);
                        ?>
                        <div class="list-group-item-custom">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="fw-semibold mb-1"><?php echo htmlspecialchars($estimate['task_title']); ?></div>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($estimate['project_name']); ?>
                                        <?php if(!empty($estimate['phase_name'])): ?>
                                            &bull; <?php echo htmlspecialchars($estimate['phase_name']); ?>
                                        <?php endif; ?>
                                        <?php if(!empty($estimate['latest_update'])): ?>
                                            &bull; Updated <?php echo date('M d, Y', strtotime((string) $estimate['latest_update'])); ?>
                                        <?php endif; ?>
                                    </small>
                                    <div class="small text-muted mt-1">
                                        Min <?php echo $minDays; ?>d &bull; Likely <?php echo $likelyDays; ?>d &bull; Max <?php echo $maxDays; ?>d
                                    </div>
                                    <div class="mt-2">
                                        <a href="feature-estimation.php?task_id=<?php echo (int) $estimate['task_id']; ?>" class="btn-estimate">
                                            Review Estimate
                                        </a>
                                    </div>
                                </div>
                                <span class="badge-estimate"><?php echo number_format($pertDays, 1); ?>d PERT</span>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <p>No recent feature estimates</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
