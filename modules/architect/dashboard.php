<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();

// Get statistics
$totalProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE architect_id = {$user['id']} OR architect_id IS NULL")->fetch_assoc()['count'];
$pendingProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'pending'")->fetch_assoc()['count'];
$inProgressProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'in_progress'")->fetch_assoc()['count'];
$completedProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE (architect_id = {$user['id']} OR architect_id IS NULL) AND status = 'completed'")->fetch_assoc()['count'];

// Get projects under review
$reviewProjects = $conn->query("
    SELECT p.*, c.name as client_name 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    WHERE (p.architect_id = {$user['id']} OR p.architect_id IS NULL) 
    AND p.status = 'review'
    ORDER BY p.created_at DESC 
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

<div class="row">
    <div class="col-12 mb-4">
        <h2>Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h2>
        <p class="text-muted">Architect Dashboard - Monitor and manage projects for technical evaluation</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);">
            <p>Total Projects</p>
            <h3><?php echo $totalProjects; ?></h3>
            <small>Assigned for evaluation</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
            <p>Pending Review</p>
            <h3><?php echo $pendingProjects; ?></h3>
            <small>Awaiting analysis</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
            <p>In Progress</p>
            <h3><?php echo $inProgressProjects; ?></h3>
            <small>Currently evaluating</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
            <p>Completed</p>
            <h3><?php echo $completedProjects; ?></h3>
            <small>Architecture finalized</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Projects Overview</h5>
            </div>
            <div class="card-body">
                <canvas id="projectsChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Upcoming Schedule</h5>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <?php if($schedules->num_rows > 0): ?>
                        <?php while($schedule = $schedules->fetch_assoc()): ?>
                        <div class="timeline-item">
                            <h6><?php echo htmlspecialchars($schedule['title']); ?></h6>
                            <small class="text-muted">
                                <?php echo date('M d, Y h:i A', strtotime($schedule['start_time'])); ?>
                            </small>
                            <p class="mb-0"><?php echo htmlspecialchars($schedule['description']); ?></p>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted">No upcoming schedules</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Projects Ready for Review</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($project = $reviewProjects->fetch_assoc()): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><?php echo htmlspecialchars($project['name']); ?></h6>
                                <small class="text-muted">Client: <?php echo htmlspecialchars($project['client_name']); ?></small>
                            </div>
                            <a href="project-review.php?id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i> Review
                            </a>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted">
                                Created: <?php echo date('M d, Y', strtotime($project['created_at'])); ?>
                            </small>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($reviewProjects->num_rows == 0): ?>
                        <p class="text-muted text-center">No projects ready for review</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Recent Activities</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($activity = $activities->fetch_assoc()): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong>
                            <small class="text-muted"><?php echo date('H:i', strtotime($activity['created_at'])); ?></small>
                        </div>
                        <p class="mb-0"><?php echo htmlspecialchars($activity['action']); ?></p>
                        <small class="text-muted"><?php echo date('M d, Y', strtotime($activity['created_at'])); ?></small>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Projects Chart
const ctx = document.getElementById('projectsChart').getContext('2d');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['Pending Review', 'In Progress', 'Completed'],
        datasets: [{
            data: [<?php echo $pendingProjects; ?>, <?php echo $inProgressProjects; ?>, <?php echo $completedProjects; ?>],
            backgroundColor: ['#ffc107', '#17a2b8', '#28a745'],
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
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed;
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