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

// Get statistics
$totalProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE architect_id = {$user['id']} OR architect_id IS NULL")->fetch_assoc()['count'];
$pendingProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'pending'")->fetch_assoc()['count'];
$inProgressProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'in_progress'")->fetch_assoc()['count'];
$completedProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'completed'")->fetch_assoc()['count'];

// Get projects ready for architect review with quick readiness context
$reviewProjects = $conn->query("
    SELECT 
        p.*, 
        c.name as client_name,
        (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) as task_count,
        (SELECT COUNT(*) FROM architecture_documents ad WHERE ad.project_id = p.id) as document_count
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    WHERE (p.architect_id = {$user['id']} OR p.architect_id IS NULL) 
    AND p.status = 'review'
    ORDER BY COALESCE(p.timeline_end, DATE(p.created_at)) ASC, p.created_at DESC 
    LIMIT 5
");

// Get user's schedule
$schedules = $conn->query("
    SELECT title, description, schedule_type, start_date, start_time, status, project_id
    FROM (
        SELECT
            s.title,
            s.description,
            s.schedule_type,
            s.start_date,
            COALESCE(s.start_time, '00:00:00') AS start_time,
            s.status,
            s.project_id
        FROM schedules s
        WHERE s.user_id = {$user['id']}
          AND s.status <> 'cancelled'
          AND (
              s.start_date > CURDATE()
              OR (s.start_date = CURDATE() AND COALESCE(s.start_time, '00:00:00') >= CURTIME())
          )
        UNION ALL
        SELECT
            CONCAT('Project start: ', p.name) AS title,
            CONCAT('Client: ', c.name) AS description,
            'milestone' AS schedule_type,
            p.timeline_start AS start_date,
            {$projectStartTimeSql} AS start_time,
            p.status,
            p.id AS project_id
        FROM projects p
        JOIN clients c ON p.client_id = c.id
        WHERE (p.architect_id = {$user['id']} OR p.architect_id IS NULL)
          AND p.timeline_start IS NOT NULL
          AND (
              p.timeline_start > CURDATE()
              OR (p.timeline_start = CURDATE() AND {$projectStartTimeSql} >= CURTIME())
          )
          AND p.status NOT IN ('completed', 'cancelled')
        UNION ALL
        SELECT
            CONCAT('Project deadline: ', p.name) AS title,
            CONCAT('Client: ', c.name) AS description,
            'deadline' AS schedule_type,
            p.timeline_end AS start_date,
            {$projectEndTimeSql} AS start_time,
            p.status,
            p.id AS project_id
        FROM projects p
        JOIN clients c ON p.client_id = c.id
        WHERE (p.architect_id = {$user['id']} OR p.architect_id IS NULL)
          AND p.timeline_end IS NOT NULL
          AND (
              p.timeline_end > CURDATE()
              OR (p.timeline_end = CURDATE() AND {$projectEndTimeSql} >= CURTIME())
          )
          AND p.status NOT IN ('completed', 'cancelled')
    ) upcoming
    ORDER BY start_date, start_time
    LIMIT 5
");
// Build Architect agenda from manual schedules and project deadlines
$architectAgenda = [];

$scheduleRows = $conn->query("
    SELECT title, description, start_date, start_time, schedule_type
    FROM schedules
    WHERE user_id = {$user['id']}
      AND start_date IS NOT NULL
      AND start_date >= CURDATE()
      AND status IN ('scheduled', 'in_progress')
    ORDER BY start_date ASC, start_time ASC
    LIMIT 5
");
while ($schedule = $scheduleRows->fetch_assoc()) {
    $agendaDate = trim((string) ($schedule['start_date'] ?? ''));
    $agendaTime = trim((string) ($schedule['start_time'] ?? ''));
    $architectAgenda[] = [
        'title' => (string) ($schedule['title'] ?? 'Scheduled item'),
        'description' => (string) ($schedule['description'] ?? ''),
        'date' => $agendaDate . ($agendaTime !== '' ? ' ' . $agendaTime : ''),
        'type' => ucfirst((string) ($schedule['schedule_type'] ?? 'Schedule')),
        'icon' => 'calendar-alt',
        'url' => '',
    ];
}

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

.btn-review {
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

.btn-review:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
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
</style>

<div class="container-fluid px-4 py-3">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-12">
                            <span class="badge architect-overview-badge mb-2 px-3 py-2">Architect Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Monitor and manage projects for technical evaluation and architecture design.</p>
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

    <?php
    delayInsightsRenderCompactList($conn, $user, [
        'limit' => 4,
        'title' => 'Project Insight List',
        'subtitle' => 'Small one-line project signals with direct links to the full insight page.',
        'button_label' => 'View Project',
    ]);
    ?>

    <!-- Charts Section -->
    <div class="row g-3 mb-4">
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: #1f6392;">Projects Overview</h5>
                </div>
                <div class="card-body p-4 pt-2">
                    <canvas id="projectsChart" style="max-height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: #1f6392;">Upcoming Schedule</h5>
                </div>
                <div class="card-body p-4">
                    <?php renderScheduleCalendar($schedules); ?>
                    <?php if($architectAgenda): ?>
                        <?php foreach($architectAgenda as $agendaItem): ?>
                        <div class="timeline-item mb-3">
                            <div class="timeline-title">
                                <i class="fas fa-<?php echo htmlspecialchars($agendaItem['icon']); ?> me-1"></i>
                                <?php echo htmlspecialchars($agendaItem['title']); ?>
                            </div>
                            <div class="timeline-time">
                                <span class="badge bg-light text-dark me-2"><?php echo htmlspecialchars($agendaItem['type']); ?></span>
                                <i class="far fa-clock"></i>
                                <?php echo date('M d, Y h:i A', strtotime((string) $agendaItem['date'])); ?>
                            </div>
                            <?php if(!empty($agendaItem['description'])): ?>
                            <div class="timeline-desc">
                                <?php echo htmlspecialchars($agendaItem['description']); ?>
                            </div>
                            <?php endif; ?>
                            <?php if(!empty($agendaItem['url'])): ?>
                            <a href="<?php echo htmlspecialchars($agendaItem['url']); ?>" class="btn-review mt-2">
                                <i class="fas fa-arrow-right"></i> Open
                            </a>
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
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: #1f6392;">Projects Ready for Review</h5>
                </div>
                <div class="card-body p-4">
                    <?php if($reviewProjects->num_rows > 0): ?>
                        <?php while($project = $reviewProjects->fetch_assoc()): ?>
                        <div class="d-flex justify-content-between align-items-start mb-3 pb-3 border-bottom">
                            <div>
                                <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($project['name']); ?></h6>
                                <small class="text-muted">
                                    <i class="fas fa-building me-1"></i>
                                    <?php echo htmlspecialchars($project['client_name']); ?>
                                </small>
                                <div class="mt-1">
                                    <small class="text-muted">
                                        <i class="far fa-calendar-alt me-1"></i>
                                        Target: <?php echo !empty($project['timeline_end']) ? date('M d, Y', strtotime($project['timeline_end'])) : 'No target date'; ?>
                                    </small>
                                </div>
                                <div class="mt-1">
                                    <small class="text-muted">
                                        <i class="fas fa-list-check me-1"></i>
                                        <?php echo (int) $project['task_count']; ?> task<?php echo (int) $project['task_count'] === 1 ? '' : 's'; ?>
                                        &bull;
                                        <i class="fas fa-file-alt me-1"></i>
                                        <?php echo (int) $project['document_count']; ?> document<?php echo (int) $project['document_count'] === 1 ? '' : 's'; ?>
                                    </small>
                                </div>
                            </div>
                            <a href="project-review.php?id=<?php echo $project['id']; ?>" class="btn-review">
                                <i class="fas fa-eye"></i> Review
                            </a>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state-mini">
                            <i class="fas fa-check-circle"></i>
                            <p>No projects ready for review</p>
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
                                <span class="activity-time"><?php echo date('h:i A', strtotime($activity['created_at'])); ?></span>
                            </div>
                            <div class="activity-action">
                                <?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?>
                            </div>
                            <div class="activity-date">
                                <i class="far fa-calendar-alt me-1"></i>
                                <?php echo date('M d, Y', strtotime($activity['created_at'])); ?>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
