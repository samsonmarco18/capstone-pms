<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/schedule-calendar.php';
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

// Get deadline-driven task queue
$upcomingTasks = $conn->query("
    SELECT t.*, p.name as project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = {$user['id']}
    AND t.status NOT IN ('completed', 'cancelled')
    AND p.status NOT IN ('completed', 'cancelled')
    AND t.deadline IS NOT NULL
    ORDER BY 
        CASE WHEN t.deadline < CURDATE() THEN 0 ELSE 1 END,
        t.deadline ASC
    LIMIT 5
");

// Get user's schedule
$schedules = $conn->query("
    SELECT * FROM schedules 
    WHERE user_id = {$user['id']} 
    AND status <> 'cancelled'
    AND (
        start_date > CURDATE()
        OR (start_date = CURDATE() AND COALESCE(start_time, '00:00:00') >= CURTIME())
    )
    ORDER BY start_date, start_time 
");

$developerAgenda = [];
$developerAgendaRows = $conn->query("
    SELECT t.id, t.title, t.description, t.deadline, t.priority, p.name AS project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = {$user['id']}
      AND t.status NOT IN ('completed', 'cancelled')
      AND p.status NOT IN ('completed', 'cancelled')
      AND t.deadline IS NOT NULL
      AND t.deadline >= CURDATE()
    ORDER BY t.deadline ASC
    LIMIT 8
");
while ($task = $developerAgendaRows->fetch_assoc()) {
    $developerAgenda[] = [
        'title' => (string) ($task['title'] ?? 'Task deadline'),
        'description' => 'Project: ' . (string) ($task['project_name'] ?? 'Project'),
        'date' => trim((string) ($task['deadline'] ?? '') . ' 17:00:00'),
        'type' => ucfirst((string) ($task['priority'] ?? 'Task')) . ' Priority',
        'url' => 'task.php?id=' . (int) ($task['id'] ?? 0),
    ];
}
// Get tasks that need developer attention beyond calendar deadlines
$attentionTasks = $conn->query("
    SELECT t.*, p.name as project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = {$user['id']}
    AND t.status != 'completed'
    AND (
        t.status IN ('blocked', 'in_review', 'review')
        OR t.deadline < CURDATE()
        OR (t.deadline IS NULL AND t.status IN ('todo', 'in_progress'))
    )
    ORDER BY
        CASE
            WHEN t.status = 'blocked' THEN 0
            WHEN t.deadline < CURDATE() THEN 1
            WHEN t.status IN ('in_review', 'review') THEN 2
            ELSE 3
        END,
        COALESCE(t.deadline, DATE(t.created_at)) ASC
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

.attention-list {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.attention-item {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 1rem;
    background: #fff;
}

.attention-top {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    align-items: flex-start;
    margin-bottom: 0.6rem;
}

.attention-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
}

.attention-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem 0.75rem;
    color: var(--gray-600);
    font-size: 0.76rem;
    margin-bottom: 0.75rem;
}

.attention-reason {
    flex: 0 0 auto;
    padding: 0.25rem 0.65rem;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    background: #dbeafe;
    color: #2563eb;
}

.attention-reason.urgent {
    background: #fee2e2;
    color: #dc2626;
}

.attention-reason.warning {
    background: #fef3c7;
    color: #d97706;
}

.attention-actions {
    display: flex;
    justify-content: flex-end;
}

@media (max-width: 575.98px) {
    .attention-top,
    .attention-actions {
        display: block;
    }

    .attention-reason {
        display: inline-flex;
        margin-top: 0.5rem;
    }
}

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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .welcome-banner,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .attention-item {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item-custom:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr:hover {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .welcome-banner h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-item h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .attention-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .welcome-banner p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card .stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card .stat-sub,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-item small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-item p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .attention-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item-custom,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-custom {
    background: rgba(157, 182, 207, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-days.normal {
    background: rgba(37, 99, 235, 0.16);
    color: #93c5fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-days.warning,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .attention-reason.warning {
    background: rgba(217, 119, 6, 0.16);
    color: #fbbf24;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-days.urgent,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-urgent,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .attention-reason.urgent {
    background: rgba(220, 38, 38, 0.16);
    color: #fca5a5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .attention-reason {
    background: rgba(31, 99, 146, 0.16);
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .agenda-preview {
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
    delayInsightsRenderCompactList($conn, $user, [
        'limit' => 4,
        'title' => 'Project Insight List',
        'subtitle' => 'Small one-line project signals with direct links to the full insight page.',
        'button_label' => 'View Project',
    ]);
    ?>

    <div class="row g-4 mb-4 align-items-stretch">
        <div class="col-xl-6">
            <div class="card h-100">
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

        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-alt"></i> Upcoming Schedule</h5>
                </div>
                <div class="card-body p-0">
                    <?php renderScheduleAgendaPreview($schedules, $developerAgenda, 'Calendar'); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card h-100">
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

        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-exclamation-circle"></i> Needs Attention</h5>
                </div>
                <div class="card-body">
                    <div class="attention-list">
                    <?php while($task = $attentionTasks->fetch_assoc()): ?>
                    <?php
                        $statusLabel = ucwords(str_replace('_', ' ', (string) ($task['status'] ?? 'todo')));
                        $attentionReason = $statusLabel;
                        $attentionTone = 'normal';
                        if ((string) ($task['status'] ?? '') === 'blocked') {
                            $attentionReason = 'Blocked';
                            $attentionTone = 'urgent';
                        } elseif (!empty($task['deadline']) && strtotime((string) $task['deadline']) < strtotime(date('Y-m-d'))) {
                            $attentionReason = 'Overdue';
                            $attentionTone = 'urgent';
                        } elseif (in_array((string) ($task['status'] ?? ''), ['in_review', 'review'], true)) {
                            $attentionReason = 'Review Needed';
                            $attentionTone = 'warning';
                        } elseif (empty($task['deadline'])) {
                            $attentionReason = 'No Deadline Set';
                            $attentionTone = 'warning';
                        }
                    ?>
                    <div class="attention-item <?php echo htmlspecialchars($attentionTone); ?>">
                        <div class="attention-top">
                            <h6 class="attention-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                            <span class="attention-reason <?php echo htmlspecialchars($attentionTone); ?>"><?php echo htmlspecialchars($attentionReason); ?></span>
                        </div>
                        <div class="attention-meta">
                            <span><i class="fas fa-folder-open me-1"></i><?php echo htmlspecialchars($task['project_name']); ?></span>
                            <span><i class="fas fa-signal me-1"></i><?php echo htmlspecialchars(ucfirst((string) ($task['priority'] ?? 'medium'))); ?> priority</span>
                            <?php if(!empty($task['deadline'])): ?>
                            <span><i class="far fa-calendar-alt me-1"></i>Due <?php echo date('M d, Y', strtotime((string) $task['deadline'])); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="attention-actions">
                            <a href="task.php?id=<?php echo (int) $task['id']; ?>" class="btn-view">
                                <i class="fas fa-tasks"></i> View Task
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    </div>
                    <?php if($attentionTasks->num_rows == 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No tasks need attention</p>
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
const developerDarkMode = document.documentElement.dataset.theme === 'dark'
    || document.body.dataset.theme === 'dark'
    || document.body.classList.contains('dark-mode');
const developerChartText = developerDarkMode ? '#9db6cf' : '#6c757d';
const developerChartGrid = developerDarkMode ? 'rgba(157, 182, 207, 0.16)' : 'rgba(0, 0, 0, 0.05)';

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
                    color: developerChartText,
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
                ticks: { stepSize: 1, color: developerChartText },
                grid: { color: developerChartGrid }
            },
            x: {
                ticks: { color: developerChartText },
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
