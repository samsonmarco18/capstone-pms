<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/schedule-calendar.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();

// Get statistics
$totalClients = $conn->query("SELECT COUNT(*) as count FROM clients")->fetch_assoc()['count'];
$activeClients = $conn->query("SELECT COUNT(*) as count FROM clients WHERE status = 'active'")->fetch_assoc()['count'];
$totalProjects = $conn->query("SELECT COUNT(*) as count FROM projects")->fetch_assoc()['count'];
$activeProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status != 'completed'")->fetch_assoc()['count'];
$completedProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status = 'completed'")->fetch_assoc()['count'];

// Get recent clients
$recentClients = $conn->query("SELECT * FROM clients ORDER BY created_at DESC LIMIT 5");

// Get projects by status
$projectStatus = $conn->query("
    SELECT status, COUNT(*) as count 
    FROM projects 
    GROUP BY status
");

// Get user's schedule and related project/client deadlines
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
            CONCAT('Project deadline: ', p.name) AS title,
            CONCAT('Client: ', c.name) AS description,
            'deadline' AS schedule_type,
            p.timeline_end AS start_date,
            '17:00:00' AS start_time,
            p.status,
            p.id AS project_id
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        WHERE p.timeline_end IS NOT NULL
            AND p.timeline_end >= CURDATE()
            AND p.status NOT IN ('completed', 'cancelled')
            AND c.created_by = {$user['id']}
        UNION ALL
        SELECT
            CONCAT('Client deadline: ', c.name) AS title,
            c.contact_person AS description,
            'deadline' AS schedule_type,
            c.deadline AS start_date,
            '17:00:00' AS start_time,
            c.status,
            NULL AS project_id
        FROM clients c
        WHERE c.deadline IS NOT NULL
            AND c.deadline >= CURDATE()
            AND c.status <> 'inactive'
            AND c.created_by = {$user['id']}
    ) upcoming
    ORDER BY start_date, start_time
    LIMIT 5
");

// Get monthly sales data
$monthlySales = $conn->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, 
           COUNT(*) as project_count,
           SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
    FROM projects 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
");

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
}

/* Page Header */
.page-header-modern {
    background: #ffffff;
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
    border: 1px solid var(--gray-200);
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.06));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--gray-900);
}

.page-header-modern h2 i {
    color: var(--theme-primary);
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: var(--gray-600);
}

.sales-overview-badge {
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
    opacity: 0.7;
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

/* Chart Cards */
.chart-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    height: 100%;
}

.chart-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.chart-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.chart-card .card-header h5 i {
    color: var(--theme-primary);
}

.chart-card .card-body {
    padding: 1.5rem;
}

/* List Cards */
.list-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    height: 100%;
}

.list-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.list-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.list-card .card-header h5 i {
    color: var(--theme-primary);
}

.list-card .card-body {
    padding: 0;
}

/* List Items */
.list-item-modern {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    transition: all 0.2s ease;
}

.list-item-modern:last-child {
    border-bottom: none;
}

.list-item-modern:hover {
    background: var(--gray-50);
}

.client-info h6 {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.client-info .client-details {
    font-size: 0.75rem;
    color: var(--gray-600);
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.client-info .client-details i {
    margin-right: 0.25rem;
    color: var(--theme-primary);
}

/* Timeline Items */
.timeline-item-modern {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    position: relative;
    padding-left: 3rem;
}

.timeline-item-modern:last-child {
    border-bottom: none;
}

.timeline-item-modern::before {
    content: '';
    position: absolute;
    left: 1.5rem;
    top: 1.5rem;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--theme-primary);
}

.timeline-item-modern::after {
    content: '';
    position: absolute;
    left: 1.5rem;
    top: 2rem;
    width: 2px;
    height: calc(100% - 1rem);
    background: var(--gray-200);
}

.timeline-item-modern:last-child::after {
    display: none;
}

.timeline-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.timeline-time {
    font-size: 0.7rem;
    color: var(--gray-600);
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.timeline-time i {
    font-size: 0.65rem;
}

.timeline-desc {
    font-size: 0.8rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
}

/* Badge */
.badge-modern {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
}

.badge-modern.active {
    background: #d1fae5;
    color: #059669;
}

.badge-modern.inactive {
    background: #fef3c7;
    color: #d97706;
}

/* View Button */
.btn-view {
    background: transparent;
    border: 1px solid var(--theme-primary);
    color: var(--theme-primary);
    padding: 0.3rem 0.8rem;
    border-radius: 8px;
    font-size: 0.7rem;
    transition: all 0.2s;
}

.btn-view:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    transform: translateY(-1px);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 2rem 1.5rem;
}

.empty-state-modern i {
    font-size: 2.5rem;
    color: var(--gray-300);
    margin-bottom: 0.5rem;
}

.empty-state-modern p {
    color: var(--gray-600);
    font-size: 0.85rem;
    margin: 0;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
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
                            <span class="badge sales-overview-badge mb-2 px-3 py-2">Sales Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Sales Dashboard - Track clients, manage projects, and monitor performance metrics for business growth.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card-modern stat-clients">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Total Clients</div>
            <div class="stat-number"><?php echo $totalClients; ?></div>
            <div class="stat-sub"><?php echo $activeClients; ?> active clients</div>
        </div>
        
        <div class="stat-card-modern stat-projects">
            <i class="fas fa-project-diagram stat-icon"></i>
            <div class="stat-label">Total Projects</div>
            <div class="stat-number"><?php echo $totalProjects; ?></div>
            <div class="stat-sub"><?php echo $completedProjects; ?> completed</div>
        </div>
        
        <div class="stat-card-modern stat-active">
            <i class="fas fa-play-circle stat-icon"></i>
            <div class="stat-label">Active Projects</div>
            <div class="stat-number"><?php echo $activeProjects; ?></div>
            <div class="stat-sub">Currently ongoing</div>
        </div>
        
        <div class="stat-card-modern stat-completion">
            <i class="fas fa-trophy stat-icon"></i>
            <div class="stat-label">Completion Rate</div>
            <div class="stat-number"><?php echo $totalProjects > 0 ? round(($completedProjects / $totalProjects) * 100) : 0; ?>%</div>
            <div class="stat-sub">Project success rate</div>
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

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-chart-line"></i>
                        Project Trends (Last 6 Months)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="projectTrendsChart" style="height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-chart-pie"></i>
                        Project Status Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="statusChart" style="height: 280px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Lists Row -->
    <div class="row g-4">
        <div class="col-md-6">
            <div class="list-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-user-plus"></i>
                        Recent Clients
                    </h5>
                </div>
                <div class="card-body">
                    <?php if($recentClients->num_rows > 0): ?>
                        <?php while($client = $recentClients->fetch_assoc()): ?>
                        <div class="list-item-modern">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="client-info">
                                    <h6><?php echo htmlspecialchars($client['name']); ?></h6>
                                    <div class="client-details">
                                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($client['email']); ?></span>
                                        <span>
                                            <i class="fas fa-circle"></i> 
                                            <span class="badge-modern <?php echo $client['status']; ?>">
                                                <?php echo ucfirst($client['status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>
                                <a href="clients.php?view=<?php echo $client['id']; ?>" class="btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state-modern">
                            <i class="fas fa-user-friends"></i>
                            <p>No clients found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="list-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-calendar-alt"></i>
                        Upcoming Schedule
                    </h5>
                </div>
                <div class="card-body">
                    <?php renderScheduleCalendar($schedules); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Project Trends Chart
const trendsData = <?php 
    $months = [];
    $counts = [];
    $completed = [];
    while($row = $monthlySales->fetch_assoc()) {
        $months[] = date('M Y', strtotime($row['month'] . '-01'));
        $counts[] = $row['project_count'];
        $completed[] = $row['completed_count'];
    }
    echo json_encode(['months' => $months, 'counts' => $counts, 'completed' => $completed]);
?>;

const trendsCtx = document.getElementById('projectTrendsChart').getContext('2d');
new Chart(trendsCtx, {
    type: 'line',
    data: {
        labels: trendsData.months,
        datasets: [
            {
                label: 'Total Projects',
                data: trendsData.counts,
                borderColor: '#1f6392',
                backgroundColor: 'rgba(31, 99, 146, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#1f6392',
                pointBorderColor: '#fff',
                pointRadius: 4,
                pointHoverRadius: 6
            },
            {
                label: 'Completed Projects',
                data: trendsData.completed,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#fff',
                pointRadius: 4,
                pointHoverRadius: 6
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    font: {
                        size: 11
                    }
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                },
                ticks: {
                    stepSize: 1,
                    font: {
                        size: 11
                    }
                }
            },
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    font: {
                        size: 11
                    }
                }
            }
        }
    }
});

// Status Chart
const statusData = <?php 
    $statuses = [];
    $statusCounts = [];
    $statusColors = [];
    while($row = $projectStatus->fetch_assoc()) {
        $statuses[] = ucfirst($row['status']);
        $statusCounts[] = $row['count'];
        // Color mapping
        switch($row['status']) {
            case 'planning':
                $statusColors[] = '#f59e0b';
                break;
            case 'in_progress':
                $statusColors[] = '#1f6392';
                break;
            case 'review':
                $statusColors[] = '#8b5cf6';
                break;
            case 'completed':
                $statusColors[] = '#10b981';
                break;
            case 'cancelled':
                $statusColors[] = '#ef4444';
                break;
            default:
                $statusColors[] = '#6c757d';
        }
    }
    echo json_encode(['labels' => $statuses, 'data' => $statusCounts, 'colors' => $statusColors]);
?>;

const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: statusData.labels,
        datasets: [{
            data: statusData.data,
            backgroundColor: statusData.colors,
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
                        size: 11
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
</script>

<?php include '../../includes/footer.php'; ?>
