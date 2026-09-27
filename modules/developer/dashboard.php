<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/schedule-calendar.php';
requireRole('developer');

$conn = getConnection();
$user = getCurrentUser();

// Get task statistics
$developerActiveProjectJoin = "JOIN projects p ON p.id = tasks.project_id AND p.status != 'cancelled'";
$totalTasks = $conn->query("SELECT COUNT(*) as count FROM tasks {$developerActiveProjectJoin} WHERE tasks.assigned_to = {$user['id']}")->fetch_assoc()['count'];
$completedTasks = $conn->query("SELECT COUNT(*) as count FROM tasks {$developerActiveProjectJoin} WHERE tasks.assigned_to = {$user['id']} AND tasks.status = 'completed'")->fetch_assoc()['count'];
$inProgressTasks = $conn->query("SELECT COUNT(*) as count FROM tasks {$developerActiveProjectJoin} WHERE tasks.assigned_to = {$user['id']} AND tasks.status = 'in_progress'")->fetch_assoc()['count'];
$overdueTasks = $conn->query("SELECT COUNT(*) as count FROM tasks {$developerActiveProjectJoin} WHERE tasks.assigned_to = {$user['id']} AND tasks.deadline < NOW() AND tasks.status != 'completed'")->fetch_assoc()['count'];

// Calculate performance percentage
$performance = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

// Get tasks by priority
$highPriority = $conn->query("SELECT COUNT(*) as count FROM tasks {$developerActiveProjectJoin} WHERE tasks.assigned_to = {$user['id']} AND tasks.priority = 'high' AND tasks.status != 'completed'")->fetch_assoc()['count'];
$mediumPriority = $conn->query("SELECT COUNT(*) as count FROM tasks {$developerActiveProjectJoin} WHERE tasks.assigned_to = {$user['id']} AND tasks.priority = 'medium' AND tasks.status != 'completed'")->fetch_assoc()['count'];
$lowPriority = $conn->query("SELECT COUNT(*) as count FROM tasks {$developerActiveProjectJoin} WHERE tasks.assigned_to = {$user['id']} AND tasks.priority = 'low' AND tasks.status != 'completed'")->fetch_assoc()['count'];

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
    AND p.status != 'cancelled'
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
    SELECT tasks.* FROM tasks
    JOIN projects p ON p.id = tasks.project_id
    WHERE assigned_to = {$user['id']} 
    AND tasks.status = 'completed'
    AND p.status != 'cancelled'
    ORDER BY tasks.completed_at DESC
    LIMIT 5
");

$trackedGitTasks = $conn->query("
    SELECT COUNT(DISTINCT tgu.task_id) AS count
    FROM task_git_updates tgu
    INNER JOIN tasks t ON t.id = tgu.task_id
    INNER JOIN projects p ON p.id = t.project_id
    WHERE t.assigned_to = {$user['id']}
      AND p.status != 'cancelled'
")->fetch_assoc()['count'];

$recentGitActivity = $conn->query("
    SELECT tgu.*, t.title AS task_title, p.name AS project_name
    FROM task_git_updates tgu
    INNER JOIN tasks t ON t.id = tgu.task_id
    LEFT JOIN projects p ON p.id = t.project_id
    WHERE t.assigned_to = {$user['id']}
      AND p.status != 'cancelled'
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

.developer-git-card .card-body {
    padding: 1rem 1.25rem 1.15rem;
}

.git-activity-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding-bottom: 0.85rem;
    border-bottom: 1px solid var(--gray-200);
}

.git-activity-summary__label {
    color: var(--gray-600);
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.git-activity-summary__value {
    margin-top: 0.15rem;
    color: var(--theme-primary);
    font-size: 1.55rem;
    font-weight: 700;
    line-height: 1;
}

.git-activity-summary__icon,
.git-activity-item__mark {
    display: inline-grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: 10px;
    background: #eaf4fa;
    color: var(--theme-primary);
}

.git-activity-summary__icon {
    width: 2.5rem;
    height: 2.5rem;
    font-size: 1rem;
}

.git-activity-list {
    display: grid;
}

.git-activity-item {
    display: grid;
    grid-template-columns: 2.25rem minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.7rem;
    padding: 0.78rem 0;
    border-bottom: 1px solid var(--gray-200);
}

.git-activity-item:last-child {
    border-bottom: 0;
}

.git-activity-item__mark {
    width: 2.25rem;
    height: 2.25rem;
    font-size: 0.78rem;
}

.git-activity-item__content {
    min-width: 0;
}

.git-activity-item__top,
.git-activity-item__detail,
.git-activity-item__meta {
    display: flex;
    align-items: center;
}

.git-activity-item__top {
    gap: 0.45rem;
    min-width: 0;
}

.git-activity-item__title {
    min-width: 0;
    margin: 0;
    overflow: hidden;
    color: var(--gray-900);
    font-size: 0.82rem;
    font-weight: 650;
    line-height: 1.25;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.git-activity-item__state {
    flex: 0 0 auto;
    padding: 0.18rem 0.45rem;
    border-radius: 999px;
    background: #eaf4fa;
    color: var(--theme-primary);
    font-size: 0.61rem;
    font-weight: 700;
    line-height: 1.2;
}

.git-activity-item__detail,
.git-activity-item__meta {
    flex-wrap: wrap;
    gap: 0.25rem 0.65rem;
    margin-top: 0.24rem;
    color: var(--gray-600);
    font-size: 0.68rem;
    line-height: 1.35;
}

.git-activity-item__detail code {
    padding: 0.08rem 0.28rem;
    border-radius: 4px;
    background: var(--gray-100);
    color: var(--gray-700);
    font-size: 0.64rem;
}

.git-activity-open {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.32rem;
    min-height: 32px;
    padding: 0.42rem 0.6rem;
    border: 1px solid rgba(31, 99, 146, 0.22);
    border-radius: 8px;
    background: #fff;
    color: var(--theme-primary);
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1;
    text-decoration: none;
    transition: background 0.2s, border-color 0.2s, color 0.2s;
}

.git-activity-open:hover {
    border-color: rgba(31, 99, 146, 0.36);
    background: #edf7fb;
    color: var(--theme-dark);
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

/* Keep the paired performance and schedule cards compact and visually balanced. */
.developer-overview-row .developer-performance-body {
    display: flex;
    min-height: 450px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.developer-performance-chart {
    position: relative;
    width: min(100%, 390px);
    height: 340px;
    margin: 0 auto 0.35rem;
}

.developer-performance-chart canvas {
    width: 100% !important;
    height: 100% !important;
}

.developer-performance-summary {
    width: min(100%, 390px);
    text-align: center;
}

.developer-performance-rate {
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 0.4rem;
    margin-bottom: 0.65rem;
    color: #17324f;
}

.developer-performance-rate strong {
    font-size: 1.65rem;
    font-weight: 700;
    line-height: 1;
}

.developer-performance-rate span {
    color: var(--gray-600);
    font-size: 0.88rem;
    font-weight: 500;
}

.developer-overview-row .developer-schedule-body .agenda-preview {
    gap: 0.7rem;
    padding: 1rem 1.15rem;
}

.developer-overview-row .developer-schedule-body .agenda-summary {
    grid-template-columns: 66px minmax(0, 1fr);
    padding: 0.65rem;
}

.developer-overview-row .developer-schedule-body .agenda-today-badge {
    min-height: 68px;
}

.developer-overview-row .developer-schedule-body .agenda-list {
    gap: 0.5rem;
}

.developer-overview-row .developer-schedule-body .agenda-date-group {
    padding: 0.55rem 0.75rem;
}

.developer-overview-row .developer-schedule-body .agenda-date-row {
    margin-bottom: 0.25rem;
}

.developer-overview-row .developer-schedule-body .agenda-item {
    padding: 0.25rem 0;
}

.developer-overview-row .developer-schedule-body .agenda-color {
    height: 32px;
}

.developer-insights-column {
    min-width: 0;
}

.developer-insights-column .delay-insights-wrap,
.developer-insights-column .delay-compact-card {
    width: 100%;
    height: 100%;
}

.developer-insights-column .delay-insights-wrap {
    margin-bottom: 0;
}

.developer-priority-chart {
    position: relative;
    width: min(100%, 920px);
    height: 260px;
    margin: 0 auto;
}

.developer-priority-chart canvas {
    width: 100% !important;
    height: 100% !important;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-item__title {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-summary__label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-item__detail,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-item__meta {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-summary__icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-item__mark,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-item__state {
    background: rgba(31, 99, 146, 0.2);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-item__detail code {
    background: rgba(157, 182, 207, 0.12);
    color: #c5d8e9;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-open {
    border-color: rgba(138, 210, 255, 0.18);
    background: #0a1b2c;
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .git-activity-open:hover {
    border-color: rgba(138, 210, 255, 0.34);
    background: rgba(31, 99, 146, 0.2);
    color: #d7e4f2;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-performance-rate {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-performance-rate span {
    color: #9db6cf;
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

    .developer-git-card .card-body {
        padding: 0.9rem 1rem 1rem;
    }

    .git-activity-item {
        grid-template-columns: 2.1rem minmax(0, 1fr);
        gap: 0.6rem;
    }

    .git-activity-item__mark {
        width: 2.1rem;
        height: 2.1rem;
    }

    .git-activity-open {
        grid-column: 2;
        justify-self: start;
    }
}
</style>

<div class="container-fluid px-4 py-4 role-dashboard-shell">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm role-dashboard-hero">
                <div class="card-body p-4">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-8 role-dashboard-hero-copy">
                            <span class="badge developer-overview-badge mb-2 px-3 py-2">Developer Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Developer Dashboard - Track your tasks, manage code, and monitor project progress for efficient delivery.</p>
                        </div>
                        <div class="col-lg-4 d-none d-lg-flex justify-content-end">
                            <div class="role-3d-scene role-3d-scene--developer" aria-hidden="true">
                                <div class="role-3d-platform">
                                    <span class="role-3d-node role-3d-node--one"><i class="fas fa-terminal"></i></span>
                                    <span class="role-3d-node role-3d-node--two"><i class="fas fa-code-branch"></i></span>
                                    <span class="role-3d-orbit"></span>
                                    <span class="role-3d-core"><i class="fas fa-code"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4 role-stats-row">
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
            <div class="card border-0 shadow-sm h-100 dashboard-overdue-card <?php echo (int) $overdueTasks > 0 ? 'is-overdue-alert' : ''; ?>">
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

    <div class="row g-4 mb-4 align-items-stretch developer-overview-row">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie"></i> Performance Overview</h5>
                </div>
                <div class="card-body developer-performance-body">
                    <div class="developer-performance-chart">
                        <canvas id="performanceChart"></canvas>
                    </div>
                    <div class="developer-performance-summary">
                        <div class="developer-performance-rate">
                            <strong><?php echo $performance; ?>%</strong>
                            <span>Completion rate</span>
                        </div>
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
                <div class="card-body p-0 developer-schedule-body">
                    <?php renderScheduleAgendaPreview($schedules, $developerAgenda, 'Calendar'); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4 align-items-stretch developer-secondary-row">
        <div class="col-xl-6 developer-insights-column">
            <?php
            delayInsightsRenderCompactList($conn, $user, [
                'limit' => 200,
                'display' => 'portfolio_chart',
                'title' => 'Overall Project Risk',
                'subtitle' => 'Portfolio-wide delivery risk across your assigned projects.',
                'show_empty' => true,
                'empty_title' => 'No project insights yet',
                'empty_message' => 'Project delivery signals will appear here when an assigned project becomes available.',
            ]);
            ?>
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

    <div class="row g-4">
        <div class="col-12">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar"></i> Task Priority Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="developer-priority-chart">
                        <canvas id="priorityChart"></canvas>
                    </div>
                    <div class="mt-3 d-flex justify-content-between gap-2 flex-wrap">
                        <span><span class="badge-priority-high">High</span> <?php echo $highPriority; ?> tasks</span>
                        <span><span class="badge-priority-medium">Medium</span> <?php echo $mediumPriority; ?> tasks</span>
                        <span><span class="badge-priority-low">Low</span> <?php echo $lowPriority; ?> tasks</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Completed Tasks -->
    <div class="row mt-4">
        <div class="col-xl-6">
            <div class="card developer-git-card">
                <div class="card-header">
                    <h5><i class="fas fa-code-branch"></i> Recent Git Activity</h5>
                </div>
                <div class="card-body">
                    <div class="git-activity-summary">
                        <div>
                            <div class="git-activity-summary__label">Tracked Tasks</div>
                            <div class="git-activity-summary__value"><?php echo $trackedGitTasks; ?></div>
                        </div>
                        <span class="git-activity-summary__icon" aria-hidden="true">
                            <i class="fas fa-code-branch"></i>
                        </span>
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
                    <div class="git-activity-list">
                    <?php while($git = $recentGitActivity->fetch_assoc()): ?>
                    <?php
                    $gitActionLabel = $gitActionLabels[$git['git_action']] ?? 'Git Update';
                    $gitStatusLabel = $gitStatusLabels[$git['status']] ?? ucfirst($git['status']);
                    $gitActivityLabel = strcasecmp($gitActionLabel, $gitStatusLabel) === 0
                        ? $gitActionLabel
                        : $gitActionLabel . ' / ' . $gitStatusLabel;
                    ?>
                    <article class="git-activity-item">
                        <span class="git-activity-item__mark" aria-hidden="true">
                            <i class="fas fa-code-branch"></i>
                        </span>
                        <div class="git-activity-item__content">
                            <div class="git-activity-item__top">
                                <h6 class="git-activity-item__title"><?php echo htmlspecialchars($git['task_title']); ?></h6>
                                <span class="git-activity-item__state"><?php echo htmlspecialchars($gitActivityLabel); ?></span>
                            </div>
                            <div class="git-activity-item__detail">
                                <span><i class="fas fa-code-branch"></i> <?php echo htmlspecialchars($git['branch_name']); ?></span>
                                <?php if (!empty($git['commit_hash'])): ?>
                                <code><?php echo htmlspecialchars(substr((string) ($git['commit_hash'] ?? ''), 0, 7)); ?></code>
                                <?php endif; ?>
                            </div>
                            <div class="git-activity-item__meta">
                                <?php if (!empty($git['project_name'])): ?>
                                <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($git['project_name']); ?></span>
                                <?php endif; ?>
                                <span><i class="far fa-clock"></i> <?php echo !empty($git['created_at']) ? date('M d, Y h:i A', strtotime((string) $git['created_at'])) : 'No date'; ?></span>
                            </div>
                        </div>
                        <a href="task.php?id=<?php echo $git['task_id']; ?>" class="git-activity-open" aria-label="Open <?php echo htmlspecialchars($git['task_title']); ?>">
                            <i class="fas fa-eye"></i>
                            <span>Open</span>
                        </a>
                    </article>
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
                                    <small class="text-muted"><?php echo htmlspecialchars(substr((string) ($task['description'] ?? ''), 0, 60)); ?></small>
                                </td>
                                <td><?php echo !empty($task['completed_at']) ? date('M d, Y', strtotime((string) $task['completed_at'])) : 'No date'; ?></td>
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
        maintainAspectRatio: false,
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
        maintainAspectRatio: false,
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
