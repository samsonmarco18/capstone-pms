<?php
require_once '../../includes/auth.php';
requireRole('expert');

$conn = getConnection();
$user = getCurrentUser();

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

// Get cost estimations summary
$costEstimates = $conn->query("
    SELECT ce.*, p.name as project_name 
    FROM cost_estimations ce
    JOIN projects p ON ce.project_id = p.id
    WHERE ce.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY ce.created_at DESC
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

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h2>
        <p class="text-muted">Expert/Senior Dashboard - Technical estimations and project planning</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);">
            <p>Total Estimations</p>
            <h3><?php echo $totalEstimations; ?></h3>
            <small>All time</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
            <p>Pending</p>
            <h3><?php echo $pendingEstimations; ?></h3>
            <small>Awaiting estimation</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
            <p>Completed</p>
            <h3><?php echo $completedEstimations; ?></h3>
            <small>Submitted</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
            <p>Active Projects</p>
            <h3><?php echo $pendingTasks->num_rows; ?></h3>
            <small>Currently estimating</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Pending Feature Estimations</h5>
            </div>
            <div class="card-body">
                <?php if($pendingTasks->num_rows > 0): ?>
                    <div class="list-group">
                        <?php while($task = $pendingTasks->fetch_assoc()): 
                            $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($task['title']); ?></h6>
                                    <small class="text-muted">Project: <?php echo htmlspecialchars($task['project_name']); ?></small>
                                    <p class="mb-1 small"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?></p>
                                    <small class="text-muted">Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></small>
                                    <?php if($daysLeft <= 3): ?>
                                        <span class="badge bg-danger ms-2">Urgent</span>
                                    <?php endif; ?>
                                </div>
                                <a href="feature-estimation.php?task_id=<?php echo $task['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-calculator"></i> Estimate
                                </a>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center">No pending estimations</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>Recent Team Compositions</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Role</th>
                                <th>Count</th>
                                <th>Allocation %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($tc = $teamCompositions->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tc['project_name']); ?></td>
                                <td><?php echo htmlspecialchars($tc['role']); ?></td>
                                <td><?php echo $tc['required_count']; ?></td>
                                <td><?php echo $tc['allocation_percentage']; ?>%</td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-5 mb-4">
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
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>Recent Cost Estimates</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($cost = $costEstimates->fetch_assoc()): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <strong><?php echo htmlspecialchars($cost['project_name']); ?></strong>
                            <span class="badge bg-primary">$<?php echo number_format($cost['monthly_cost'], 2); ?></span>
                        </div>
                        <small class="text-muted"><?php echo htmlspecialchars($cost['component']); ?> (<?php echo $cost['azure_service']; ?>)</small>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>