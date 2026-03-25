<?php
require_once '../../includes/auth.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();

// Get statistics
$totalExperts = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'expert' OR role = 'developer'")->fetch_assoc()['count'];
$availableExperts = $conn->query("SELECT COUNT(*) as count FROM users WHERE (role = 'expert' OR role = 'developer') AND status = 'active'")->fetch_assoc()['count'];
$totalTasks = $conn->query("SELECT COUNT(*) as count FROM tasks")->fetch_assoc()['count'];
$overdueTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE deadline < NOW() AND status != 'completed'")->fetch_assoc()['count'];
$unassignedTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to IS NULL OR assigned_to = 0")->fetch_assoc()['count'];
$inProgressTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'in_progress'")->fetch_assoc()['count'];
$completedTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'completed'")->fetch_assoc()['count'];
$todoTasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'todo'")->fetch_assoc()['count'];

// Get tasks by priority
$highPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE priority = 'high' AND status != 'completed'")->fetch_assoc()['count'];
$mediumPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE priority = 'medium' AND status != 'completed'")->fetch_assoc()['count'];
$lowPriority = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE priority = 'low' AND status != 'completed'")->fetch_assoc()['count'];

// Get expert/developer availability
$expertAvailability = $conn->query("
    SELECT u.id, u.full_name, u.role, 
           COUNT(t.id) as assigned_tasks,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
    GROUP BY u.id
    ORDER BY active_tasks ASC
");

// Get projects that need task allocation
$projectsNeedingAllocation = $conn->query("
    SELECT p.*, c.name as client_name
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    WHERE p.status = 'in_progress'
    AND p.id NOT IN (SELECT DISTINCT project_id FROM tasks)
    ORDER BY p.created_at DESC
    LIMIT 5
");

// Get recent tasks
$recentTasks = $conn->query("
    SELECT t.*, p.name as project_name, u.full_name as assigned_to_name
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assigned_to = u.id
    ORDER BY t.created_at DESC
    LIMIT 10
");

// Get user's schedule
$schedules = $conn->query("
    SELECT * FROM schedules 
    WHERE user_id = {$user['id']} 
    AND start_time >= NOW() 
    ORDER BY start_time 
    LIMIT 5
");

// Get performance data for chart
$performanceData = $conn->query("
    SELECT u.full_name, 
           COUNT(t.id) as total_tasks,
           SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer')
    GROUP BY u.id
    HAVING total_tasks > 0
    LIMIT 10
");

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h2>
        <p class="text-muted">Service Unit Leader Dashboard - Monitor resources, tasks, and team performance</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);">
            <p>Total Experts/Devs</p>
            <h3><?php echo $totalExperts; ?></h3>
            <small><?php echo $availableExperts; ?> available</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
            <p>Total Tasks</p>
            <h3><?php echo $totalTasks; ?></h3>
            <small><?php echo $unassignedTasks; ?> unassigned</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
            <p>In Progress</p>
            <h3><?php echo $inProgressTasks; ?></h3>
            <small><?php echo $completedTasks; ?> completed</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #dc3545 0%, #bb2d3b 100%);">
            <p>Overdue Tasks</p>
            <h3><?php echo $overdueTasks; ?></h3>
            <small>Requires immediate attention</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Task Status Overview</h5>
            </div>
            <div class="card-body">
                <canvas id="taskStatusChart" height="250"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-md-5 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Priority Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="priorityChart" height="200"></canvas>
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
                <h5>Team Availability & Workload</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Active Tasks</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($expert = $expertAvailability->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($expert['full_name']); ?></td>
                                <td><span class="badge bg-<?php echo $expert['role'] == 'expert' ? 'warning' : 'info'; ?>">
                                    <?php echo strtoupper($expert['role']); ?>
                                </span></td>
                                <td><?php echo $expert['active_tasks']; ?></td>
                                <td>
                                    <?php if($expert['active_tasks'] >= 5): ?>
                                        <span class="badge bg-danger">Overloaded</span>
                                    <?php elseif($expert['active_tasks'] >= 3): ?>
                                        <span class="badge bg-warning">Busy</span>
                                    <?php elseif($expert['active_tasks'] > 0): ?>
                                        <span class="badge bg-info">Available</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Free</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="assignTask(<?php echo $expert['id']; ?>, '<?php echo htmlspecialchars($expert['full_name']); ?>')">
                                        <i class="fas fa-tasks"></i> Assign
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
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Projects Needing Task Allocation</h5>
            </div>
            <div class="card-body">
                <?php if($projectsNeedingAllocation->num_rows > 0): ?>
                    <div class="list-group">
                        <?php while($project = $projectsNeedingAllocation->fetch_assoc()): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($project['name']); ?></h6>
                                    <small class="text-muted">Client: <?php echo htmlspecialchars($project['client_name']); ?></small>
                                    <br>
                                    <small>Timeline: <?php echo date('M d', strtotime($project['timeline_start'])); ?> - <?php echo date('M d, Y', strtotime($project['timeline_end'])); ?></small>
                                </div>
                                <a href="task-allocation.php?project_id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-tasks"></i> Allocate Tasks
                                </a>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center">No projects pending task allocation</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card mt-4">
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

<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Recent Tasks</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Assigned To</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Deadline</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($task = $recentTasks->fetch_assoc()): 
                                $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 50)); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($task['project_name']); ?></td>
                                <td><?php echo htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $task['priority'] == 'high' ? 'danger' : 
                                            ($task['priority'] == 'medium' ? 'warning' : 'success');
                                    ?>">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $task['status'] == 'completed' ? 'success' : 
                                            ($task['status'] == 'in_progress' ? 'info' : 
                                            ($task['status'] == 'todo' ? 'secondary' : 'warning'));
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($task['deadline'])); ?>
                                    <?php if($daysLeft <= 2 && $task['status'] != 'completed'): ?>
                                        <br><small class="text-danger"><?php echo $daysLeft; ?> days left</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewTask(<?php echo $task['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if(!$task['assigned_to']): ?>
                                    <button class="btn btn-sm btn-primary" onclick="assignTaskTo(<?php echo $task['id']; ?>)">
                                        <i class="fas fa-user-plus"></i>
                                    </button>
                                    <?php endif; ?>
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

<!-- Assign Task Modal -->
<div class="modal fade" id="assignTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Task:</strong> <span id="assignTaskTitle"></span></p>
                <div class="mb-3">
                    <label class="form-label">Assign to:</label>
                    <select id="assignToUser" class="form-select">
                        <option value="">Select a team member</option>
                        <?php
                        $availableUsers = $conn->query("SELECT id, full_name, role FROM users WHERE role IN ('expert', 'developer') AND status = 'active' ORDER BY full_name");
                        while($user = $availableUsers->fetch_assoc()):
                        ?>
                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?> (<?php echo strtoupper($user['role']); ?>)</option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Send Email Notification</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="sendEmail" checked>
                        <label class="form-check-label" for="sendEmail">
                            Notify via email
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmAssign()">Assign Task</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentTaskId = null;

// Task Status Chart
const taskCtx = document.getElementById('taskStatusChart').getContext('2d');
new Chart(taskCtx, {
    type: 'doughnut',
    data: {
        labels: ['To Do', 'In Progress', 'Completed', 'Overdue'],
        datasets: [{
            data: [<?php echo $todoTasks; ?>, <?php echo $inProgressTasks; ?>, <?php echo $completedTasks; ?>, <?php echo $overdueTasks; ?>],
            backgroundColor: ['#6c757d', '#ffc107', '#28a745', '#dc3545'],
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

function assignTask(userId, userName) {
    // Show modal to select task for this user
    $.ajax({
        url: '../../api/get-unassigned-tasks.php',
        method: 'GET',
        success: function(response) {
            const tasks = JSON.parse(response);
            let html = '<div class="list-group">';
            tasks.forEach(task => {
                html += `
                    <a href="#" class="list-group-item list-group-item-action" onclick="selectTaskForUser(${task.id}, ${userId}, '${task.title}')">
                        <strong>${task.title}</strong><br>
                        <small>Project: ${task.project_name}</small>
                    </a>
                `;
            });
            html += '</div>';
            $('#assignTaskModal .modal-body').html(html);
            $('#assignTaskModal').modal('show');
        }
    });
}

function selectTaskForUser(taskId, userId, taskTitle) {
    if(confirm(`Assign "${taskTitle}" to this team member?`)) {
        $.ajax({
            url: '../../api/assign-task.php',
            method: 'POST',
            data: {
                task_id: taskId,
                user_id: userId,
                send_email: true
            },
            success: function(response) {
                const result = JSON.parse(response);
                if(result.success) {
                    showToast('Success', 'Task assigned successfully');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Error', result.error, 'danger');
                }
            }
        });
    }
}

function assignTaskTo(taskId) {
    currentTaskId = taskId;
    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        data: { id: taskId },
        success: function(response) {
            const task = JSON.parse(response);
            $('#assignTaskTitle').text(task.title);
            $('#assignTaskModal').modal('show');
        }
    });
}

function confirmAssign() {
    const userId = $('#assignToUser').val();
    const sendEmail = $('#sendEmail').is(':checked');
    
    if(!userId) {
        showToast('Error', 'Please select a team member', 'danger');
        return;
    }
    
    $.ajax({
        url: '../../api/assign-task.php',
        method: 'POST',
        data: {
            task_id: currentTaskId,
            user_id: userId,
            send_email: sendEmail
        },
        success: function(response) {
            const result = JSON.parse(response);
            if(result.success) {
                showToast('Success', 'Task assigned successfully');
                $('#assignTaskModal').modal('hide');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('Error', result.error, 'danger');
            }
        }
    });
}

function viewTask(taskId) {
    window.location.href = '../../modules/developer/task.php?id=' + taskId;
}
</script>

<?php include '../../includes/footer.php'; ?>