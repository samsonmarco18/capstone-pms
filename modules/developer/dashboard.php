<?php
require_once '../../includes/auth.php';
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

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h2>
        <p class="text-muted">Developer Dashboard - Track your tasks and performance</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);">
            <p>Total Tasks</p>
            <h3><?php echo $totalTasks; ?></h3>
            <small>Assigned to you</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
            <p>Completed Tasks</p>
            <h3><?php echo $completedTasks; ?></h3>
            <small>Successfully delivered</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
            <p>In Progress</p>
            <h3><?php echo $inProgressTasks; ?></h3>
            <small>Currently working on</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #dc3545 0%, #bb2d3b 100%);">
            <p>Overdue Tasks</p>
            <h3><?php echo $overdueTasks; ?></h3>
            <small>Requires attention</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Performance Overview</h5>
            </div>
            <div class="card-body">
                <canvas id="performanceChart" height="250"></canvas>
                <div class="text-center mt-3">
                    <h4><?php echo $performance; ?>% Completion Rate</h4>
                    <div class="progress">
                        <div class="progress-bar bg-success" style="width: <?php echo $performance; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Task Priority Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="priorityChart" height="250"></canvas>
                <div class="mt-3">
                    <div class="d-flex justify-content-between">
                        <span><span class="badge bg-danger">High</span> <?php echo $highPriority; ?> tasks</span>
                        <span><span class="badge bg-warning">Medium</span> <?php echo $mediumPriority; ?> tasks</span>
                        <span><span class="badge bg-success">Low</span> <?php echo $lowPriority; ?> tasks</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Upcoming Deadlines</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($task = $upcomingTasks->fetch_assoc()): 
                        $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        $badgeClass = $daysLeft <= 2 ? 'danger' : ($daysLeft <= 5 ? 'warning' : 'info');
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><?php echo htmlspecialchars($task['title']); ?></h6>
                                <small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?></small>
                            </div>
                            <span class="badge bg-<?php echo $badgeClass; ?>">
                                <?php echo $daysLeft; ?> days left
                            </span>
                        </div>
                        <div class="mt-2">
                            <a href="task.php?id=<?php echo $task['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-tasks"></i> View Task
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($upcomingTasks->num_rows == 0): ?>
                        <p class="text-muted text-center">No upcoming tasks</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Schedule & Events</h5>
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

<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Recent Completed Tasks</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Completed On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($task = $recentCompleted->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 80)); ?></small>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($task['completed_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewTask(<?php echo $task['id']; ?>)">
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
// Performance Chart
const perfCtx = document.getElementById('performanceChart').getContext('2d');
new Chart(perfCtx, {
    type: 'doughnut',
    data: {
        labels: ['Completed', 'In Progress', 'Pending'],
        datasets: [{
            data: [<?php echo $completedTasks; ?>, <?php echo $inProgressTasks; ?>, <?php echo $totalTasks - ($completedTasks + $inProgressTasks); ?>],
            backgroundColor: ['#28a745', '#ffc107', '#6c757d'],
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

// Priority Chart
const priorityCtx = document.getElementById('priorityChart').getContext('2d');
new Chart(priorityCtx, {
    type: 'bar',
    data: {
        labels: ['High', 'Medium', 'Low'],
        datasets: [{
            label: 'Number of Tasks',
            data: [<?php echo $highPriority; ?>, <?php echo $mediumPriority; ?>, <?php echo $lowPriority; ?>],
            backgroundColor: ['#dc3545', '#ffc107', '#28a745'],
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        },
        plugins: {
            legend: {
                display: false
            }
        }
    }
});

function viewTask(taskId) {
    window.location.href = 'task.php?id=' + taskId;
}
</script>

<?php include '../../includes/footer.php'; ?>