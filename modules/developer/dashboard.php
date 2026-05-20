<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
requireRole('developer');

$conn = getConnection();
$user = getCurrentUser();

// Get task statistics
$totalTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = {$user['id']}")->fetch_assoc()['count'];
$completedTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = {$user['id']} AND status = 'completed'")->fetch_assoc()['count'];
$inProgressTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = {$user['id']} AND status = 'in_progress'")->fetch_assoc()['count'];
$overdueTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = {$user['id']} AND deadline < NOW() AND status != 'completed'")->fetch_assoc()['count'];

// Calculate performance percentage
$performance = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

// Get tasks by priority
$highPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = {$user['id']} AND priority = 'high' AND status != 'completed'")->fetch_assoc()['count'];
$mediumPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = {$user['id']} AND priority = 'medium' AND status != 'completed'")->fetch_assoc()['count'];
$lowPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = {$user['id']} AND priority = 'low' AND status != 'completed'")->fetch_assoc()['count'];

// Get upcoming deadlines
$upcomingTasks = $conn->query("
    SELECT * FROM tasks 
    WHERE assigned_to = {$user['id']} 
    AND status != 'completed' 
    AND deadline >= NOW() 
    ORDER BY deadline ASC 
    LIMIT 5
");

// Get user's schedule
$schedules = $conn->query("
    SELECT * FROM schedules 
    WHERE user_id = {$user['id']} 
    AND start_time >= NOW() 
    ORDER BY start_time 
    LIMIT 5
");

// Get recent completed tasks
$recentCompleted = $conn->query("
    SELECT * FROM tasks 
    WHERE assigned_to = {$user['id']} 
    AND status = 'completed' 
    ORDER BY completed_at DESC 
    LIMIT 5
");

$trackedGitTasks = $conn->query("
    SELECT COUNT(DISTINCT tgu.task_id) AS count
    FROM task_git_updates tgu
    INNER JOIN tasks t ON t.id = tgu.task_id
    WHERE t.assigned_to = {$user['id']}
")->fetch_assoc()['count'];

$recentGitActivity = $conn->query("
    SELECT tgu.*, t.title AS task_title, p.name AS project_name
    FROM task_git_updates tgu
    INNER JOIN tasks t ON t.id = tgu.task_id
    LEFT JOIN projects p ON p.id = t.project_id
    WHERE t.assigned_to = {$user['id']}
    ORDER BY tgu.created_at DESC, tgu.id DESC
    LIMIT 5
");

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

.developer-overview-badge {
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

.badge-days {
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-days.urgent { background: #fee2e2; color: #dc2626; }
.badge-days.warning { background: #fef3c7; color: #d97706; }
.badge-days.normal { background: #dbeafe; color: #2563eb; }

/* Progress Bar */
.progress-custom {
    height: 8px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar-custom {
    height: 100%;
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
    border-radius: 10px;
}

/* Buttons */
.btn-view {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.3rem 0.8rem;
    border-radius: 8px;
    font-size: 0.7rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-view:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(31, 99, 146, 0.3);
    color: white;
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
                            <span class="badge developer-overview-badge mb-2 px-3 py-2">Developer Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Developer Dashboard - Track your tasks, manage code, and monitor project progress for efficient delivery.</p>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Total Tasks</div>
                            <div class="h1 fw-bold mb-2" style="color: #1f6392;"><?php echo $totalTasks; ?></div>
                            <small class="text-muted">Assigned to you</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-tasks" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Completed Tasks</div>
                            <div class="h1 fw-bold mb-2" style="color: #10b981;"><?php echo $completedTasks; ?></div>
                            <small class="text-muted">Successfully delivered</small>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">In Progress</div>
                            <div class="h1 fw-bold mb-2" style="color: #f59e0b;"><?php echo $inProgressTasks; ?></div>
                            <small class="text-muted">Currently working on</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-play-circle" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Overdue Tasks</div>
                            <div class="h1 fw-bold mb-2" style="color: #dc2626;"><?php echo $overdueTasks; ?></div>
                            <small class="text-muted">Requires attention</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-exclamation-triangle" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    delayInsightsRenderSection($conn, $user, [
        'limit' => 3,
        'title' => 'Project Delay Insight Notifications',
        'subtitle' => 'Developers can see projected delay risk, the current success chance, and the overdue or blocked work that is pushing projects behind schedule.',
    ]);
    ?>

    <div class="row g-4 mb-4">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie"></i> Performance Overview</h5>
                </div>
                <div class="card-body">
                    <canvas id="performanceChart" style="height: 280px; width: 100%;"></canvas>
                    <div class="text-center mt-3">
                        <h4 class="mb-2"><?php echo $performance; ?>% Completion Rate</h4>
                        <div class="progress-custom">
                            <div class="progress-bar-custom" style="width: <?php echo $performance; ?>%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar"></i> Task Priority Distribution</h5>
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

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-alt"></i> Upcoming Deadlines</h5>
                </div>
                <div>
                    <?php while($task = $upcomingTasks->fetch_assoc()): 
                        $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        $badgeClass = $daysLeft <= 2 ? 'urgent' : ($daysLeft <= 5 ? 'warning' : 'normal');
                    ?>
                    <div class="list-group-item-custom">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div class="flex-grow-1">
                                <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($task['title']); ?></h6>
                                <small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 80)); ?></small>
                                <div class="mt-1">
                                    <small class="text-muted"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></small>
                                </div>
                            </div>
                            <span class="badge-days <?php echo $badgeClass; ?>">
                                <?php echo $daysLeft; ?> days left
                            </span>
                        </div>
                        <div class="mt-2">
                            <a href="task.php?id=<?php echo $task['id']; ?>" class="btn-view">
                                <i class="fas fa-tasks"></i> View Task
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($upcomingTasks->num_rows == 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No upcoming tasks</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-week"></i> Schedule & Events</h5>
                </div>
                <div class="card-body">
                    <?php while($schedule = $schedules->fetch_assoc()): ?>
                    <div class="timeline-item mb-3">
                        <h6><?php echo htmlspecialchars($schedule['title']); ?></h6>
                        <small><i class="far fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($schedule['start_time'])); ?></small>
                        <?php if(!empty($schedule['description'])): ?>
                        <p><?php echo htmlspecialchars($schedule['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                    <?php if($schedules->num_rows == 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-week"></i>
                        <p>No upcoming schedules</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Completed Tasks -->
    <div class="row mt-4">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-code-branch"></i> Recent Git Activity</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="text-muted text-uppercase small fw-semibold">Tracked Tasks</div>
                            <div class="h3 mb-0" style="color: #1f6392;"><?php echo $trackedGitTasks; ?></div>
                        </div>
                        <i class="fas fa-code-branch" style="font-size: 2.25rem; color: var(--theme-primary); opacity: 0.65;"></i>
                    </div>
                    <?php
                    $gitActionLabels = [
                        'branch_created' => 'Branch Created',
                        'commit_pushed' => 'Commit Pushed',
                        'pull_request_opened' => 'Pull Request Opened',
                        'merged' => 'Merged',
                        'deployed' => 'Deployed',
                        'note' => 'General Note',
                    ];
                    $gitStatusLabels = [
                        'active' => 'Active',
                        'in_review' => 'In Review',
                        'merged' => 'Merged',
                        'closed' => 'Closed',
                    ];
                    ?>
                    <?php while($git = $recentGitActivity->fetch_assoc()): ?>
                    <div class="timeline-item mb-3">
                        <h6><?php echo htmlspecialchars($git['task_title']); ?></h6>
                        <small>
                            <i class="fas fa-code-branch"></i>
                            <?php echo htmlspecialchars($git['branch_name']); ?>
                            <?php if (!empty($git['commit_hash'])): ?>
                            | <code><?php echo htmlspecialchars(substr($git['commit_hash'], 0, 7)); ?></code>
                            <?php endif; ?>
                        </small>
                        <p class="mb-1"><?php echo htmlspecialchars($gitActionLabels[$git['git_action']] ?? 'Git Update'); ?> | <?php echo htmlspecialchars($gitStatusLabels[$git['status']] ?? ucfirst($git['status'])); ?></p>
                        <?php if (!empty($git['project_name'])): ?>
                        <p class="mb-1">Project: <?php echo htmlspecialchars($git['project_name']); ?></p>
                        <?php endif; ?>
                        <small><i class="far fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($git['created_at'])); ?></small>
                        <div class="mt-2">
                            <a href="task.php?id=<?php echo $git['task_id']; ?>" class="btn-view">
                                <i class="fas fa-eye"></i> View Task
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($recentGitActivity->num_rows == 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-code-branch"></i>
                        <p>No Git activity logged yet</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-history"></i> Recent Completed Tasks</h5>
                </div>
                <div class="table-responsive-custom">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Completed On</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($task = $recentCompleted->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($task['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 60)); ?></small>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($task['completed_at'])); ?></td>
                                <td class="text-center">
                                    <button class="btn-view" onclick="viewTask(<?php echo $task['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Performance Chart
const perfCtx = document.getElementById('performanceChart').getContext('2d');
new Chart(perfCtx, {
    type: 'doughnut',
    data: {
        labels: ['Completed', 'In Progress', 'Pending'],
        datasets: [{
            data: [<?php echo $completedTasks; ?>, <?php echo $inProgressTasks; ?>, <?php echo $totalTasks - ($completedTasks + $inProgressTasks); ?>],
            backgroundColor: ['#10b981', '#f59e0b', '#6c757d'],
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

function viewTask(taskId) {
    window.location.href = 'task.php?id=' + taskId;
}
</script>

<?php include '../../includes/footer.php'; ?>
