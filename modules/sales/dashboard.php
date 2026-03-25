<?php
require_once '../../includes/auth.php';
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

// Get user's schedule
$schedules = $conn->query("
    SELECT * FROM schedules 
    WHERE user_id = {$user['id']} 
    AND start_time >= NOW() 
    ORDER BY start_time 
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

<div class="row">
    <div class="col-12 mb-4">
        <h2>Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h2>
        <p class="text-muted">Sales Dashboard - Track clients, projects, and performance</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);">
            <p>Total Clients</p>
            <h3><?php echo $totalClients; ?></h3>
            <small><?php echo $activeClients; ?> active</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
            <p>Total Projects</p>
            <h3><?php echo $totalProjects; ?></h3>
            <small><?php echo $completedProjects; ?> completed</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
            <p>Active Projects</p>
            <h3><?php echo $activeProjects; ?></h3>
            <small>Currently ongoing</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
            <p>Completion Rate</p>
            <h3><?php echo $totalProjects > 0 ? round(($completedProjects / $totalProjects) * 100) : 0; ?>%</h3>
            <small>Project success rate</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Project Trends</h5>
            </div>
            <div class="card-body">
                <canvas id="projectTrendsChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Project Status Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="statusChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Recent Clients</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($client = $recentClients->fetch_assoc()): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><?php echo htmlspecialchars($client['name']); ?></h6>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($client['email']); ?> | 
                                    Status: <span class="badge bg-<?php echo $client['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($client['status']); ?>
                                    </span>
                                </small>
                            </div>
                            <a href="clients.php?id=<?php echo $client['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Upcoming Schedule</h5>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <?php while($schedule = $schedules->fetch_assoc()): ?>
                    <div class="timeline-item">
                        <h6><?php echo htmlspecialchars($schedule['title']); ?></h6>
                        <small class="text-muted">
                            <?php echo date('M d, Y h:i A', strtotime($schedule['start_time'])); ?>
                        </small>
                        <p class="mb-0"><?php echo htmlspecialchars($schedule['description']); ?></p>
                    </div>
                    <?php endwhile; ?>
                    <?php if($schedules->num_rows == 0): ?>
                        <p class="text-muted">No upcoming schedules</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

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
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.4
            },
            {
                label: 'Completed Projects',
                data: trendsData.completed,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                fill: true,
                tension: 0.4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'top'
            }
        }
    }
});

// Status Chart
const statusData = <?php 
    $statuses = [];
    $statusCounts = [];
    while($row = $projectStatus->fetch_assoc()) {
        $statuses[] = ucfirst($row['status']);
        $statusCounts[] = $row['count'];
    }
    echo json_encode(['labels' => $statuses, 'data' => $statusCounts]);
?>;

const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: statusData.labels,
        datasets: [{
            data: statusData.data,
            backgroundColor: ['#ffc107', '#17a2b8', '#28a745', '#dc3545', '#6c757d'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>