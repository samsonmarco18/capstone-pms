<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/schedule-calendar.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$hasProjectStartTime = dbColumnExists($conn, 'projects', 'timeline_start_time');
$hasProjectEndTime = dbColumnExists($conn, 'projects', 'timeline_end_time');
$projectStartTimeSql = $hasProjectStartTime ? "COALESCE(p.timeline_start_time, '09:00:00')" : "'09:00:00'";
$projectEndTimeSql = $hasProjectEndTime ? "COALESCE(p.timeline_end_time, '17:00:00')" : "'17:00:00'";

function architectDashboardRequestedFeatureCount(?string $requestedFeatures): int
{
    $features = [];
    foreach (preg_split('/\R/', trim((string) $requestedFeatures)) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(additional requirements|budget)\s*:/i', $line)) {
            break;
        }

        $line = trim((string) preg_replace('/^\s*(?:[-*]|\d+[.)])\s*/', '', $line));
        if ($line !== '') {
            $features[strtolower($line)] = true;
        }
    }

    return count($features);
}

// Get statistics
$totalProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE architect_id = {$user['id']} OR architect_id IS NULL")->fetch_assoc()['count'];
$pendingProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'pending'")->fetch_assoc()['count'];
$inProgressProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'in_progress'")->fetch_assoc()['count'];
$completedProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'completed'")->fetch_assoc()['count'];

// Sales creates new architecture-review intakes as pending projects.
$reviewProjects = $conn->query("
    SELECT 
        p.*, 
        c.name as client_name,
        (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) as task_count
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    WHERE (p.architect_id = {$user['id']} OR p.architect_id IS NULL) 
    AND p.status IN ('pending', 'review')
    ORDER BY COALESCE(p.timeline_end, DATE(p.created_at)) ASC, p.created_at DESC 
    LIMIT 5
");

// Get this architect's own manual schedule. Architecture project deadlines are listed in the agenda below.
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
// Build Architect agenda from architecture-stage project deadlines.
$architectAgenda = [];

$projectMilestones = $conn->query("
    SELECT p.id, p.name, p.status, p.timeline_end, {$projectEndTimeSql} AS timeline_end_time, c.name as client_name
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    WHERE (p.architect_id = {$user['id']} OR p.architect_id IS NULL)
      AND p.status IN ('pending', 'requirements_gathering', 'architectural_review', 'tech_recommendation', 'developer_allocation', 'arch_documentation')
      AND p.timeline_end IS NOT NULL
      AND p.timeline_end >= CURDATE()
    ORDER BY p.timeline_end ASC
    LIMIT 8
");
while ($project = $projectMilestones->fetch_assoc()) {
    $architectAgenda[] = [
        'title' => (string) ($project['name'] ?? 'Project milestone'),
        'description' => 'Client: ' . (string) ($project['client_name'] ?? 'Client'),
        'date' => trim((string) ($project['timeline_end'] ?? '') . ' ' . (string) ($project['timeline_end_time'] ?? '17:00:00')),
        'type' => 'Project Deadline',
        'icon' => 'flag-checkered',
        'url' => 'projects.php',
    ];
}

usort($architectAgenda, static function (array $left, array $right): int {
    return strtotime((string) ($left['date'] ?? '')) <=> strtotime((string) ($right['date'] ?? ''));
});
$architectAgenda = array_slice($architectAgenda, 0, 5);

// Get recent activities
$activities = $conn->query("
    SELECT al.*, u.full_name 
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    WHERE al.table_name = 'projects'
    ORDER BY al.created_at DESC 
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
}

.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08) !important;
}

.architect-overview-badge {
    background-color: var(--theme-primary) !important;
    color: #fff !important;
}

.timeline-item {
    position: relative;
    padding-left: 2rem;
    padding-bottom: 1rem;
    border-left: 2px solid #e9ecef;
}

.timeline-item:last-child {
    border-left: 2px solid transparent;
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

.timeline-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: #212529;
    margin-bottom: 0.25rem;
}

.timeline-time {
    font-size: 0.7rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.timeline-desc {
    font-size: 0.8rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.activity-item {
    padding: 0.75rem 0;
    border-bottom: 1px solid #e9ecef;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.25rem;
}

.activity-user {
    font-weight: 600;
    font-size: 0.85rem;
    color: #212529;
}

.activity-time {
    font-size: 0.65rem;
    color: #6c757d;
}

.activity-action {
    font-size: 0.8rem;
    color: #495057;
    margin-bottom: 0.25rem;
}

.activity-date {
    font-size: 0.65rem;
    color: #6c757d;
}

.review-project-list {
    display: grid;
    gap: 0.75rem;
}

.review-project-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem;
    border: 1px solid #e5edf3;
    border-radius: 12px;
    background: #fbfdff;
}

.review-project-main {
    min-width: 0;
}

.review-project-title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.3rem;
}

.review-project-title-row h6 {
    margin: 0;
    color: #1f2937;
    font-size: 0.9rem;
}

.review-project-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.25rem 0.48rem;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 0.62rem;
    font-weight: 700;
    white-space: nowrap;
}

.review-project-client {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    color: #64748b;
    font-size: 0.75rem;
}

.review-project-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem 0.85rem;
    margin-top: 0.55rem;
}

.review-project-meta span {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    color: #64748b;
    font-size: 0.7rem;
}

.review-project-meta i,
.review-project-client i {
    color: var(--theme-primary);
}

.btn-review {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: #fff !important;
    border: 1px solid rgba(255, 255, 255, 0.12);
    min-width: 126px;
    min-height: 44px;
    padding: 0.7rem 1rem;
    border-radius: 10px;
    box-shadow: 0 5px 0 rgba(9, 55, 84, 0.55), 0 10px 20px rgba(15, 74, 110, 0.2);
    font-size: 0.78rem;
    font-weight: 700;
    line-height: 1;
    text-decoration: none !important;
    white-space: nowrap;
    transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    flex: 0 0 auto;
}

.btn-review i,
.btn-review span {
    color: inherit !important;
}

.btn-review:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 0 rgba(9, 55, 84, 0.5), 0 14px 24px rgba(15, 74, 110, 0.28);
    filter: brightness(1.06);
    color: #fff !important;
    text-decoration: none !important;
}

.btn-review:focus-visible {
    outline: 3px solid rgba(56, 189, 248, 0.3);
    outline-offset: 3px;
}

@media (max-width: 576px) {
    .review-project-item {
        align-items: stretch;
        flex-direction: column;
    }

    .review-project-item .btn-review {
        justify-content: center;
        width: 100%;
    }
}

.empty-state-mini {
    text-align: center;
    padding: 2rem 1.5rem;
}

.empty-state-mini i {
    font-size: 2rem;
    color: #dee2e6;
    margin-bottom: 0.5rem;
}

.empty-state-mini p {
    color: #6c757d;
    font-size: 0.8rem;
    margin: 0;
}

.architect-project-overview-card {
    display: flex;
    flex-direction: column;
}

.architect-project-overview-body {
    display: flex;
    flex: 1 1 auto;
    align-items: center;
    justify-content: center;
    min-height: 360px;
}

.architect-project-overview-body canvas {
    display: block;
    width: 100% !important;
    max-width: 620px;
    max-height: 380px;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .dashboard-hero,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-content {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .list-group-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table thead th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table tbody tr:hover {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h1,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h3,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table td {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table th {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .review-project-item {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .review-project-title-row h6 {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .review-project-client,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .review-project-meta span {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .review-project-badge {
    background: rgba(31, 99, 146, 0.2);
    color: #8fd3ff;
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
</style>

<div class="container-fluid px-4 py-3 role-dashboard-shell">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm role-dashboard-hero">
                <div class="card-body p-4">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-8 role-dashboard-hero-copy">
                            <span class="badge architect-overview-badge mb-2 px-3 py-2">Architect Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Monitor and manage projects for technical evaluation and architecture design.</p>
                        </div>
                        <div class="col-lg-4 d-none d-lg-flex justify-content-end">
                            <div class="role-3d-scene role-3d-scene--architect" aria-hidden="true">
                                <div class="role-3d-platform">
                                    <span class="role-3d-node role-3d-node--one"><i class="fas fa-cubes"></i></span>
                                    <span class="role-3d-node role-3d-node--two"><i class="fas fa-project-diagram"></i></span>
                                    <span class="role-3d-orbit"></span>
                                    <span class="role-3d-core"><i class="fas fa-drafting-compass"></i></span>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Total Projects</div>
                            <div class="h1 fw-bold mb-2" style="color: #1f6392;"><?php echo $totalProjects; ?></div>
                            <small class="text-muted">Assigned for evaluation</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-project-diagram" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Pending Review</div>
                            <div class="h1 fw-bold mb-2" style="color: #f59e0b;"><?php echo $pendingProjects; ?></div>
                            <small class="text-muted">Awaiting analysis</small>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">In Progress</div>
                            <div class="h1 fw-bold mb-2" style="color: #3b82f6;"><?php echo $inProgressProjects; ?></div>
                            <small class="text-muted">Currently evaluating</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-spinner" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
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
                            <div class="h1 fw-bold mb-2" style="color: #10b981;"><?php echo $completedProjects; ?></div>
                            <small class="text-muted">Architecture finalized</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-check-circle" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 1: Project Overview + Upcoming Schedule -->
    <div class="row g-3 mb-4">
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100 architect-project-overview-card">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: #1f6392;">Project Overview</h5>
                </div>
                <div class="card-body p-4 architect-project-overview-body">
                    <canvas id="projectsChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: #1f6392;">Upcoming Schedule</h5>
                </div>
                <div class="card-body p-0">
                    <?php renderScheduleAgendaPreview($schedules, $architectAgenda, 'Calendar'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Portfolio Project Risk -->
    <?php
    delayInsightsRenderCompactList($conn, $user, [
        'limit' => 200,
        'display' => 'portfolio_chart',
        'title' => 'Overall Project Risk',
        'subtitle' => 'Portfolio-wide delivery risk across all monitored projects.',
        'show_empty' => true,
        'empty_title' => 'No project insights yet',
        'empty_message' => 'Project insight signals will appear here when they become available.',
    ]);
    ?>

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                    <h5 class="mb-1 fw-semibold" style="color: #1f6392;">Projects Ready for Review</h5>
                    <small class="text-muted">New Sales intakes awaiting architecture planning</small>
                </div>
                <div class="card-body p-4">
                    <?php if($reviewProjects->num_rows > 0): ?>
                        <div class="review-project-list">
                        <?php while($project = $reviewProjects->fetch_assoc()): ?>
                            <?php
                            $requestedFeatureCount = architectDashboardRequestedFeatureCount($project['requested_features'] ?? '');
                            $reviewStarted = (int) ($project['task_count'] ?? 0) > 0;
                            ?>
                            <article class="review-project-item">
                                <div class="review-project-main">
                                    <div class="review-project-title-row">
                                        <h6><?php echo htmlspecialchars((string) $project['name']); ?></h6>
                                        <span class="review-project-badge">
                                            <i class="fas <?php echo $reviewStarted ? 'fa-pen-ruler' : 'fa-inbox'; ?>"></i>
                                            <?php echo $reviewStarted ? 'Planning started' : 'New Sales intake'; ?>
                                        </span>
                                    </div>
                                    <div class="review-project-client">
                                        <i class="fas fa-building"></i>
                                        <span><?php echo htmlspecialchars((string) $project['client_name']); ?></span>
                                    </div>
                                    <div class="review-project-meta">
                                        <span>
                                            <i class="far fa-calendar-alt"></i>
                                            Target <?php echo !empty($project['timeline_end']) ? date('M d, Y', strtotime((string) $project['timeline_end'])) : 'not set'; ?>
                                        </span>
                                        <span>
                                            <i class="fas fa-list-check"></i>
                                            <?php echo $requestedFeatureCount; ?> requested feature<?php echo $requestedFeatureCount === 1 ? '' : 's'; ?>
                                        </span>
                                        <span>
                                            <i class="far fa-clock"></i>
                                            Received <?php echo !empty($project['created_at']) ? date('M d, Y', strtotime((string) $project['created_at'])) : 'recently'; ?>
                                        </span>
                                    </div>
                                </div>
                                <a href="project-review.php?id=<?php echo (int) $project['id']; ?>" class="btn-review">
                                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                    <span><?php echo $reviewStarted ? 'Continue' : 'Start Review'; ?></span>
                                </a>
                            </article>
                        <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state-mini">
                            <i class="fas fa-check-circle"></i>
                            <p>No new Sales intakes are waiting for review.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: #1f6392;">Recent Activities</h5>
                </div>
                <div class="card-body p-4">
                    <?php if($activities->num_rows > 0): ?>
                        <?php while($activity = $activities->fetch_assoc()): ?>
                        <div class="activity-item">
                            <div class="activity-header">
                                <span class="activity-user">
                                    <i class="fas fa-user-circle me-1"></i>
                                    <?php echo htmlspecialchars($activity['full_name']); ?>
                                </span>
                                <span class="activity-time"><?php echo !empty($activity['created_at']) ? date('h:i A', strtotime((string) $activity['created_at'])) : 'No time'; ?></span>
                            </div>
                            <div class="activity-action">
                                <?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?>
                            </div>
                            <div class="activity-date">
                                <i class="far fa-calendar-alt me-1"></i>
                                <?php echo !empty($activity['created_at']) ? date('M d, Y', strtotime((string) $activity['created_at'])) : 'No date'; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state-mini">
                            <i class="fas fa-inbox"></i>
                            <p>No recent activities</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Projects Chart
    const ctx = document.getElementById('projectsChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Pending Review', 'In Progress', 'Completed'],
            datasets: [{
                data: [<?php echo $pendingProjects; ?>, <?php echo $inProgressProjects; ?>, <?php echo $completedProjects; ?>],
                backgroundColor: ['#f59e0b', '#3b82f6', '#10b981'],
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
                        font: {
                            size: 12,
                            weight: '500'
                        }
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
});
</script>

<?php include '../../includes/footer.php'; ?>
