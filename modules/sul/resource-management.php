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

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: white;
}

.page-header-modern h2 i {
    color: white;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9);
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
    opacity: 0.65;
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

/* Cards */
.card {
    background: white;
    border-radius: 20px;
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

/* Table Styles */
.table-container {
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
    padding: 1rem 1.25rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
}

.data-table tr:hover {
    background: var(--gray-50);
}

/* Progress Bar */
.progress-modern {
    height: 8px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar-modern {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

/* Badges */
.badge-role-expert {
    background: #fef3c7;
    color: #d97706;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-role-developer {
    background: #dbeafe;
    color: #2563eb;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status-overloaded {
    background: #fee2e2;
    color: #dc2626;
}

.badge-status-busy {
    background: #fef3c7;
    color: #d97706;
}

.badge-status-available {
    background: #d1fae5;
    color: #059669;
}

.badge-status-free {
    background: #d1fae5;
    color: #059669;
}

/* Buttons */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-secondary-modern {
    background: #e9ecef;
    color: var(--gray-700);
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
}

.btn-secondary-modern:hover {
    background: var(--gray-300);
}

/* List Group */
.list-group-item {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--gray-200);
    border-left: none;
    border-right: none;
    border-top: none;
}

.list-group-item:last-child {
    border-bottom: none;
}

.list-group-item:hover {
    background: var(--gray-50);
}

/* Modal */
.modal-modern .modal-content {
    border-radius: 24px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    padding: 1.5rem;
    border: none;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-modern .modal-body {
    padding: 2rem;
}

.modal-modern .modal-footer {
    padding: 1rem 2rem 2rem;
    border-top: 1px solid var(--gray-200);
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
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-users me-2"></i> 
                Resource Management
            </h2>
            <p>Manage team resources, workload, and availability</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card-modern">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Total Resources</div>
            <div class="stat-number"><?php echo $skills['total']; ?></div>
            <div class="stat-sub"><?php echo $skills['experts']; ?> Experts | <?php echo $skills['developers']; ?> Developers</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-tasks stat-icon"></i>
            <div class="stat-label">Active Tasks</div>
            <div class="stat-number"><?php 
                $activeTotal = 0;
                $workloadByRole->data_seek(0);
                while($row = $workloadByRole->fetch_assoc()) {
                    $activeTotal += $row['active_tasks'];
                }
                echo $activeTotal;
            ?></div>
            <div class="stat-sub">Across all resources</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-clock stat-icon"></i>
            <div class="stat-label">Avg Completion Time</div>
            <div class="stat-number"><?php 
                $avgTime = $conn->query("
                    SELECT AVG(TIMESTAMPDIFF(DAY, created_at, completed_at)) as avg_days 
                    FROM tasks 
                    WHERE status = 'completed' AND completed_at IS NOT NULL
                ")->fetch_assoc()['avg_days'];
                echo round($avgTime ?? 0, 1);
            ?></div>
            <div class="stat-sub">days per task</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-exclamation-triangle stat-icon"></i>
            <div class="stat-label">Overdue Tasks</div>
            <div class="stat-number" style="color: #dc2626;"><?php 
                $overdue = $conn->query("
                    SELECT COUNT(*) as count FROM tasks 
                    WHERE status != 'completed' AND deadline < NOW()
                ")->fetch_assoc()['count'];
                echo $overdue;
            ?></div>
            <div class="stat-sub">Need immediate attention</div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar me-2"></i>Resource Workload Overview</h5>
                </div>
                <div class="card-body">
                    <canvas id="workloadChart" style="height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Workload by Role</h5>
                </div>
                <div class="card-body">
                    <canvas id="roleWorkloadChart" style="height: 200px; width: 100%;"></canvas>
                    <div class="mt-3">
                        <?php 
                        $workloadByRole->data_seek(0);
                        while($role = $workloadByRole->fetch_assoc()): 
                            $avgPerMember = $role['member_count'] > 0 ? round($role['active_tasks'] / $role['member_count'], 1) : 0;
                        ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between mb-1">
                                <span><strong><?php echo ucfirst($role['role']); ?></strong> (<?php echo $role['member_count']; ?> members)</span>
                                <span><?php echo $role['active_tasks']; ?> active tasks</span>
                            </div>
                            <div class="progress-modern">
                                <div class="progress-bar-modern" style="width: <?php echo min(100, ($role['active_tasks'] / ($role['member_count'] * 5)) * 100); ?>%; background: var(--theme-primary);"></div>
                            </div>
                            <small class="text-muted"><?php echo $avgPerMember; ?> avg per member</small>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Resource Details Table -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-list me-2"></i>Resource Details & Workload</h5>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    60
                        <th>Resource</th>
                        <th>Role</th>
                        <th>Active</th>
                        <th>Completed</th>
                        <th>Pending</th>
                        <th>Overdue</th>
                        <th>Avg Days</th>
                        <th>Workload</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </thead>
                <tbody>
                    <?php while($resource = $resources->fetch_assoc()): 
                        $workloadPercent = min(100, ($resource['active_tasks'] / 5) * 100);
                        $statusClass = $resource['active_tasks'] >= 5 ? 'overloaded' : ($resource['active_tasks'] >= 3 ? 'busy' : ($resource['active_tasks'] > 0 ? 'available' : 'free'));
                        $statusText = $resource['active_tasks'] >= 5 ? 'Overloaded' : ($resource['active_tasks'] >= 3 ? 'Busy' : ($resource['active_tasks'] > 0 ? 'Available' : 'Free'));
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($resource['full_name']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($resource['email']); ?></small>
                        </td>
                        <td><span class="badge-role-<?php echo $resource['role']; ?>"><?php echo strtoupper($resource['role']); ?></span></td>
                        <td><?php echo $resource['active_tasks']; ?></td>
                        <td><?php echo $resource['completed_tasks']; ?></td>
                        <td><?php echo $resource['pending_tasks']; ?></td>
                        <td><?php if($resource['overdue_tasks'] > 0): ?><span class="badge-status-overloaded"><?php echo $resource['overdue_tasks']; ?></span><?php else: ?>0<?php endif; ?></td>
                        <td><?php echo $resource['avg_completion_days'] ?? 'N/A'; ?>d</td>
                        <td>
                            <div class="progress-modern" style="width: 100px;">
                                <div class="progress-bar-modern" style="width: <?php echo $workloadPercent; ?>%; background: <?php echo $resource['active_tasks'] >= 5 ? '#dc2626' : ($resource['active_tasks'] >= 3 ? '#f59e0b' : '#10b981'); ?>;"></div>
                            </div>
                            <small><?php echo $workloadPercent; ?>%</small>
                        </td>
                        <td><span class="badge-status-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                        <td>
                            <button class="btn-primary-modern btn-sm-modern" onclick="editAllocation(<?php echo $resource['id']; ?>, '<?php echo htmlspecialchars($resource['full_name']); ?>', <?php echo $resource['active_tasks']; ?>)">
                                <i class="fas fa-sliders-h"></i>
                            </button>
                            <button class="btn-secondary-modern btn-sm-modern" onclick="viewResourceTasks(<?php echo $resource['id']; ?>)">
                                <i class="fas fa-tasks"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-project-diagram me-2"></i>Projects with Active Resources</h5>
                </div>
                <div class="card-body p-0">
                    <?php while($project = $projects->fetch_assoc()): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($project['name']); ?></h6>
                                <small class="text-muted">Client: <?php echo htmlspecialchars($project['client_name']); ?></small>
                                <div class="mt-1">
                                    <small><i class="fas fa-users me-1"></i> <?php echo $project['assigned_resources']; ?> resources</small>
                                    <small class="ms-2"><i class="fas fa-tasks me-1"></i> <?php echo $project['active_tasks']; ?> active tasks</small>
                                </div>
                            </div>
                            <a href="task-allocation.php?project_id=<?php echo $project['id']; ?>" class="btn-primary-modern">
                                <i class="fas fa-chart-line"></i> Manage
                            </a>
                        </div>
                        <?php 
                        $completionPercent = $project['total_tasks'] > 0 ? round(($project['total_tasks'] - $project['active_tasks']) / $project['total_tasks'] * 100) : 0;
                        ?>
                        <div class="mt-2">
                            <div class="progress-modern">
                                <div class="progress-bar-modern" style="width: <?php echo $completionPercent; ?>%; background: #10b981;"></div>
                            </div>
                            <small class="text-muted"><?php echo $completionPercent; ?>% Complete</small>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Resource Utilization</h5>
                </div>
                <div class="card-body">
                    <canvas id="utilizationChart" style="height: 220px; width: 100%;"></canvas>
                    <div class="mt-3 p-3 bg-light rounded-3">
                        <i class="fas fa-info-circle text-primary me-2"></i>
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
<div class="modal fade modal-modern" id="editAllocationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sliders-h me-2"></i>Edit Resource Allocation</h5>
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
                    <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_allocation" class="btn-primary-modern">Update Allocation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Resource Tasks Modal -->
<div class="modal fade modal-modern" id="resourceTasksModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-tasks me-2"></i>Resource Tasks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="resourceTasksContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            { label: 'Active Tasks', data: workloadData.active, backgroundColor: '#f59e0b', borderRadius: 8 },
            { label: 'Completed Tasks', data: workloadData.completed, backgroundColor: '#10b981', borderRadius: 8 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Tasks' } } },
        plugins: { legend: { position: 'top' } }
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
        datasets: [{ data: roleData.active, backgroundColor: ['#1f6392', '#3a7ca3'], borderWidth: 0 }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { position: 'bottom' } }
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
                    $underutilized = $conn->query("SELECT COUNT(*) as count FROM (SELECT u.id, COUNT(t.id) as task_count FROM users u LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed' WHERE u.role IN ('expert', 'developer') GROUP BY u.id HAVING task_count <= 2) as under")->fetch_assoc()['count'];
                    echo $underutilized;
                ?>,
                <?php 
                    $optimal = $conn->query("SELECT COUNT(*) as count FROM (SELECT u.id, COUNT(t.id) as task_count FROM users u LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed' WHERE u.role IN ('expert', 'developer') GROUP BY u.id HAVING task_count BETWEEN 3 AND 4) as opt")->fetch_assoc()['count'];
                    echo $optimal;
                ?>,
                <?php 
                    $overloaded = $conn->query("SELECT COUNT(*) as count FROM (SELECT u.id, COUNT(t.id) as task_count FROM users u LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed' WHERE u.role IN ('expert', 'developer') GROUP BY u.id HAVING task_count >= 5) as over")->fetch_assoc()['count'];
                    echo $overloaded;
                ?>
            ],
            backgroundColor: ['#10b981', '#f59e0b', '#dc2626'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        cutout: '60%',
        plugins: { legend: { position: 'bottom' } }
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
            let html = '<div class="table-responsive"><table class="data-table">';
            html += '<thead><tr><th>Task</th><th>Project</th><th>Priority</th><th>Status</th><th>Deadline</th><th>Actions</th></tr></thead><tbody>';
            tasks.forEach(task => {
                html += `
                    <tr>
                        <td><div class="fw-semibold">${escapeHtml(task.title)}</div><small>${escapeHtml(task.description.substring(0, 60))}</small></td>
                        <td>${escapeHtml(task.project_name)}</td>
                        <td><span class="badge-priority-${task.priority}">${task.priority}</span></td>
                        <td><span class="badge-status-${task.status}">${task.status}</span></td>
                        <td>${new Date(task.deadline).toLocaleDateString()}</td>
                        <td><button class="btn-primary-modern btn-sm-modern" onclick="viewTaskDetail(${task.id})"><i class="fas fa-eye"></i></button></td>
                    </tr>
                `;
            });
            html += '</tbody></table></div>';
            if(tasks.length === 0) html = '<p class="text-center text-muted py-4">No tasks assigned to this resource</p>';
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
