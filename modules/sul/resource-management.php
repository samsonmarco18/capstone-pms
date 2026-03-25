<?php
require_once '../../includes/auth.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();

// Get all resources (experts and developers)
$resources = $conn->query("
    SELECT u.*, 
           COUNT(t.id) as total_tasks,
           SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
           SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as active_tasks,
           SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END) as pending_tasks,
           SUM(CASE WHEN t.status != 'completed' AND t.deadline < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
           ROUND(AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL 
               THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) / 24 
               ELSE NULL END), 1) as avg_completion_days
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
    GROUP BY u.id
    ORDER BY active_tasks DESC
");

// Get workload distribution by role
$workloadByRole = $conn->query("
    SELECT u.role, 
           COUNT(DISTINCT u.id) as member_count,
           COUNT(t.id) as total_tasks,
           SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
    GROUP BY u.role
");

// Get skill distribution
$skills = $conn->query("
    SELECT 
        SUM(CASE WHEN role = 'expert' THEN 1 ELSE 0 END) as experts,
        SUM(CASE WHEN role = 'developer' THEN 1 ELSE 0 END) as developers,
        COUNT(*) as total
    FROM users 
    WHERE role IN ('expert', 'developer') AND status = 'active'
")->fetch_assoc();

// Get projects with resource allocation
$projects = $conn->query("
    SELECT p.*, c.name as client_name,
           COUNT(DISTINCT t.id) as total_tasks,
           COUNT(DISTINCT t.assigned_to) as assigned_resources,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN tasks t ON p.id = t.project_id
    WHERE p.status != 'completed'
    GROUP BY p.id
    ORDER BY p.created_at DESC
    LIMIT 10
");

// Handle resource updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_allocation'])) {
        $userId = $_POST['user_id'];
        $maxTasks = $_POST['max_tasks'];
        
        $stmt = $conn->prepare("UPDATE users SET max_concurrent_tasks = ? WHERE id = ?");
        $stmt->bind_param("ii", $maxTasks, $userId);
        $stmt->execute();
        
        logActivity($user['id'], 'update_resource_allocation', 'users', $userId, null, $_POST);
        $_SESSION['success'] = "Resource allocation updated successfully";
        header('Location: resource-management.php');
        exit();
    }
    
    if (isset($_POST['set_availability'])) {
        $userId = $_POST['user_id'];
        $status = $_POST['availability_status'];
        
        $stmt = $conn->prepare("UPDATE users SET availability_status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $userId);
        $stmt->execute();
        
        logActivity($user['id'], 'update_availability', 'users', $userId, null, $_POST);
        $_SESSION['success'] = "Availability status updated successfully";
        header('Location: resource-management.php');
        exit();
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Resource Management</h2>
        <p class="text-muted">Manage team resources, workload, and availability</p>
    </div>
</div>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);">
            <p>Total Resources</p>
            <h3><?php echo $skills['total']; ?></h3>
            <small><?php echo $skills['experts']; ?> Experts | <?php echo $skills['developers']; ?> Developers</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
            <p>Active Tasks</p>
            <h3><?php 
                $activeTotal = 0;
                $workloadByRole->data_seek(0);
                while($row = $workloadByRole->fetch_assoc()) {
                    $activeTotal += $row['active_tasks'];
                }
                echo $activeTotal;
            ?></h3>
            <small>Across all resources</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
            <p>Avg Completion Time</p>
            <h3><?php 
                $avgTime = $conn->query("
                    SELECT AVG(TIMESTAMPDIFF(DAY, created_at, completed_at)) as avg_days 
                    FROM tasks 
                    WHERE status = 'completed' AND completed_at IS NOT NULL
                ")->fetch_assoc()['avg_days'];
                echo round($avgTime ?? 0, 1);
            ?> days</h3>
            <small>Average task completion</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #dc3545 0%, #bb2d3b 100%);">
            <p>Overdue Tasks</p>
            <h3><?php 
                $overdue = $conn->query("
                    SELECT COUNT(*) as count FROM tasks 
                    WHERE status != 'completed' AND deadline < NOW()
                ")->fetch_assoc()['count'];
                echo $overdue;
            ?></h3>
            <small>Need immediate attention</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Resource Workload Overview</h5>
            </div>
            <div class="card-body">
                <canvas id="workloadChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Workload by Role</h5>
            </div>
            <div class="card-body">
                <canvas id="roleWorkloadChart" height="250"></canvas>
                <div class="mt-3">
                    <?php 
                    $workloadByRole->data_seek(0);
                    while($role = $workloadByRole->fetch_assoc()): 
                        $avgPerMember = $role['member_count'] > 0 ? round($role['active_tasks'] / $role['member_count'], 1) : 0;
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between">
                            <span><strong><?php echo ucfirst($role['role']); ?></strong> (<?php echo $role['member_count']; ?> members)</span>
                            <span><?php echo $role['active_tasks']; ?> active tasks</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar bg-primary" style="width: <?php echo min(100, ($role['active_tasks'] / ($role['member_count'] * 5)) * 100); ?>%">
                                <?php echo $avgPerMember; ?> avg per member
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Resource Details & Workload</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            32
                                <th>Resource</th>
                                <th>Role</th>
                                <th>Active Tasks</th>
                                <th>Completed</th>
                                <th>Pending</th>
                                <th>Overdue</th>
                                <th>Avg Days</th>
                                <th>Workload</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($resource = $resources->fetch_assoc()): 
                                $workloadPercent = min(100, ($resource['active_tasks'] / 5) * 100);
                                $statusColor = $resource['active_tasks'] >= 5 ? 'danger' : ($resource['active_tasks'] >= 3 ? 'warning' : 'success');
                                $statusText = $resource['active_tasks'] >= 5 ? 'Overloaded' : ($resource['active_tasks'] >= 3 ? 'Busy' : ($resource['active_tasks'] > 0 ? 'Available' : 'Free'));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($resource['full_name']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($resource['email']); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $resource['role'] == 'expert' ? 'warning' : 'info'; ?>">
                                        <?php echo strtoupper($resource['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo $resource['active_tasks']; ?></td>
                                <td><?php echo $resource['completed_tasks']; ?></td>
                                <td><?php echo $resource['pending_tasks']; ?></td>
                                <td>
                                    <?php if($resource['overdue_tasks'] > 0): ?>
                                        <span class="badge bg-danger"><?php echo $resource['overdue_tasks']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $resource['avg_completion_days'] ?? 'N/A'; ?> days</td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php echo $statusColor; ?>" style="width: <?php echo $workloadPercent; ?>%">
                                            <?php echo $workloadPercent; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $statusColor; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="editAllocation(<?php echo $resource['id']; ?>, '<?php echo htmlspecialchars($resource['full_name']); ?>', <?php echo $resource['active_tasks']; ?>)">
                                        <i class="fas fa-sliders-h"></i> Allocate
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="viewResourceTasks(<?php echo $resource['id']; ?>)">
                                        <i class="fas fa-tasks"></i> Tasks
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

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Projects with Active Resources</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($project = $projects->fetch_assoc()): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><?php echo htmlspecialchars($project['name']); ?></h6>
                                <small class="text-muted">Client: <?php echo htmlspecialchars($project['client_name']); ?></small>
                                <br>
                                <small>
                                    <i class="fas fa-users"></i> <?php echo $project['assigned_resources']; ?> resources | 
                                    <i class="fas fa-tasks"></i> <?php echo $project['active_tasks']; ?> active tasks
                                </small>
                            </div>
                            <a href="task-allocation.php?project_id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-chart-line"></i> Manage
                            </a>
                        </div>
                        <div class="mt-2">
                            <div class="progress">
                                <?php 
                                $completionPercent = $project['total_tasks'] > 0 ? round(($project['total_tasks'] - $project['active_tasks']) / $project['total_tasks'] * 100) : 0;
                                ?>
                                <div class="progress-bar bg-success" style="width: <?php echo $completionPercent; ?>%">
                                    <?php echo $completionPercent; ?>% Complete
                                </div>
                            </div>
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
                <h5>Resource Utilization</h5>
            </div>
            <div class="card-body">
                <canvas id="utilizationChart" height="250"></canvas>
                <div class="mt-3">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Utilization Rate:</strong> 
                        <?php 
                        $totalResources = $skills['total'];
                        $overloaded = $conn->query("
                            SELECT COUNT(*) as count FROM (
                                SELECT u.id, COUNT(t.id) as task_count
                                FROM users u
                                LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
                                WHERE u.role IN ('expert', 'developer')
                                GROUP BY u.id
                                HAVING task_count >= 5
                            ) as overloaded
                        ")->fetch_assoc()['count'];
                        
                        $utilizationRate = $totalResources > 0 ? round(($overloaded / $totalResources) * 100) : 0;
                        ?>
                        <strong><?php echo $overloaded; ?></strong> out of <strong><?php echo $totalResources; ?></strong> resources are overloaded (<?php echo $utilizationRate; ?>%)
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Allocation Modal -->
<div class="modal fade" id="editAllocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Resource Allocation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="alloc_user_id">
                    <p><strong>Resource:</strong> <span id="alloc_user_name"></span></p>
                    <p><strong>Current Active Tasks:</strong> <span id="alloc_current_tasks"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Maximum Concurrent Tasks</label>
                        <input type="number" name="max_tasks" id="alloc_max_tasks" class="form-control" min="1" max="10" required>
                        <small class="text-muted">Recommended: 3-5 tasks per resource</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Availability Status</label>
                        <select name="availability_status" id="alloc_status" class="form-select">
                            <option value="available">Available</option>
                            <option value="busy">Busy</option>
                            <option value="on_leave">On Leave</option>
                            <option value="sick">Sick Leave</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_allocation" class="btn btn-primary">Update Allocation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Resource Tasks Modal -->
<div class="modal fade" id="resourceTasksModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Resource Tasks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="resourceTasksContent">
                <!-- Tasks will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
// Workload Chart
const workloadData = <?php 
    $names = [];
    $active = [];
    $completed = [];
    $resources->data_seek(0);
    while($row = $resources->fetch_assoc()) {
        $names[] = $row['full_name'];
        $active[] = $row['active_tasks'];
        $completed[] = $row['completed_tasks'];
    }
    echo json_encode(['names' => $names, 'active' => $active, 'completed' => $completed]);
?>;

const workloadCtx = document.getElementById('workloadChart').getContext('2d');
new Chart(workloadCtx, {
    type: 'bar',
    data: {
        labels: workloadData.names,
        datasets: [
            {
                label: 'Active Tasks',
                data: workloadData.active,
                backgroundColor: '#ffc107',
                borderRadius: 4
            },
            {
                label: 'Completed Tasks',
                data: workloadData.completed,
                backgroundColor: '#28a745',
                borderRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Number of Tasks'
                }
            },
            x: {
                ticks: {
                    maxRotation: 45,
                    minRotation: 45
                }
            }
        },
        plugins: {
            tooltip: {
                mode: 'index',
                intersect: false
            }
        }
    }
});

// Role Workload Chart
const roleData = <?php 
    $roles = [];
    $activeTasks = [];
    $workloadByRole->data_seek(0);
    while($row = $workloadByRole->fetch_assoc()) {
        $roles[] = ucfirst($row['role']);
        $activeTasks[] = $row['active_tasks'];
    }
    echo json_encode(['roles' => $roles, 'active' => $activeTasks]);
?>;

const roleCtx = document.getElementById('roleWorkloadChart').getContext('2d');
new Chart(roleCtx, {
    type: 'pie',
    data: {
        labels: roleData.roles,
        datasets: [{
            data: roleData.active,
            backgroundColor: ['#0d6efd', '#20c997'],
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
                        return `${label}: ${value} active tasks`;
                    }
                }
            }
        }
    }
});

// Utilization Chart
const utilCtx = document.getElementById('utilizationChart').getContext('2d');
new Chart(utilCtx, {
    type: 'doughnut',
    data: {
        labels: ['Underutilized (0-2 tasks)', 'Optimal (3-4 tasks)', 'Overloaded (5+ tasks)'],
        datasets: [{
            data: [
                <?php 
                    $underutilized = $conn->query("
                        SELECT COUNT(*) as count FROM (
                            SELECT u.id, COUNT(t.id) as task_count
                            FROM users u
                            LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
                            WHERE u.role IN ('expert', 'developer')
                            GROUP BY u.id
                            HAVING task_count <= 2
                        ) as under
                    ")->fetch_assoc()['count'];
                    echo $underutilized;
                ?>,
                <?php 
                    $optimal = $conn->query("
                        SELECT COUNT(*) as count FROM (
                            SELECT u.id, COUNT(t.id) as task_count
                            FROM users u
                            LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
                            WHERE u.role IN ('expert', 'developer')
                            GROUP BY u.id
                            HAVING task_count BETWEEN 3 AND 4
                        ) as opt
                    ")->fetch_assoc()['count'];
                    echo $optimal;
                ?>,
                <?php 
                    $overloaded = $conn->query("
                        SELECT COUNT(*) as count FROM (
                            SELECT u.id, COUNT(t.id) as task_count
                            FROM users u
                            LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
                            WHERE u.role IN ('expert', 'developer')
                            GROUP BY u.id
                            HAVING task_count >= 5
                        ) as over
                    ")->fetch_assoc()['count'];
                    echo $overloaded;
                ?>
            ],
            backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
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

function editAllocation(userId, userName, currentTasks) {
    document.getElementById('alloc_user_id').value = userId;
    document.getElementById('alloc_user_name').textContent = userName;
    document.getElementById('alloc_current_tasks').textContent = currentTasks;
    document.getElementById('alloc_max_tasks').value = Math.max(5, currentTasks + 1);
    new bootstrap.Modal(document.getElementById('editAllocationModal')).show();
}

function viewResourceTasks(userId) {
    $.ajax({
        url: '../../api/get-resource-tasks.php',
        method: 'GET',
        data: { user_id: userId },
        success: function(response) {
            const tasks = JSON.parse(response);
            let html = '<div class="table-responsive"><table class="table">';
            html += `
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Project</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Deadline</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
            `;
            
            tasks.forEach(task => {
                const statusColor = task.status == 'completed' ? 'success' : 
                                   (task.status == 'in_progress' ? 'info' : 
                                   (task.status == 'todo' ? 'secondary' : 'warning'));
                const priorityColor = task.priority == 'high' ? 'danger' : 
                                     (task.priority == 'medium' ? 'warning' : 'success');
                
                html += `
                    <tr>
                        <td>
                            <strong>${escapeHtml(task.title)}</strong>
                            <br>
                            <small>${escapeHtml(task.description.substring(0, 80))}</small>
                        </td>
                        <td>${escapeHtml(task.project_name)}</td>
                        <td><span class="badge bg-${priorityColor}">${task.priority}</span></td>
                        <td><span class="badge bg-${statusColor}">${task.status}</span></td>
                        <td>${new Date(task.deadline).toLocaleDateString()}</td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="viewTaskDetail(${task.id})">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            
            if(tasks.length === 0) {
                html = '<p class="text-muted text-center">No tasks assigned to this resource</p>';
            }
            
            $('#resourceTasksContent').html(html);
            new bootstrap.Modal(document.getElementById('resourceTasksModal')).show();
        }
    });
}

function viewTaskDetail(taskId) {
    window.location.href = '../../modules/developer/task.php?id=' + taskId;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include '../../includes/footer.php'; ?>