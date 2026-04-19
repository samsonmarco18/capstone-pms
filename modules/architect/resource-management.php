<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();

// Get developers
$developers = $conn->query("
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
    WHERE u.role = 'developer' AND u.status = 'active'
    GROUP BY u.id
    ORDER BY active_tasks DESC
");

// Get experts
$experts = $conn->query("
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
    WHERE u.role = 'expert' AND u.status = 'active'
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

// Get available developers (not already in architect's team)
$availableDevelopers = $conn->query("
    SELECT u.id, u.full_name, u.email, u.department
    FROM users u
    WHERE u.role = 'developer' AND u.status = 'active'
    ORDER BY u.full_name
");

// Get available experts (not already in architect's team)
$availableExperts = $conn->query("
    SELECT u.id, u.full_name, u.email, u.department
    FROM users u
    WHERE u.role = 'expert' AND u.status = 'active'
    ORDER BY u.full_name
");

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

    if (isset($_POST['add_resource'])) {
        $userId = (int) $_POST['user_id'];
        $role = $_POST['role'];

        if (!in_array($role, ['developer', 'expert'])) {
            $_SESSION['error'] = "Invalid role selected";
        } else {
            // Check if user exists and is the correct role
            $stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE id = ? AND role = ? AND status = 'active'");
            $stmt->bind_param("is", $userId, $role);
            $stmt->execute();
            $existingUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$existingUser) {
                $_SESSION['error'] = "Selected user not found or not available";
            } else {
                // Check if user is already in architect's team (you might want to add a team assignment table)
                // For now, just log the assignment
                logActivity($user['id'], 'assign_resource_to_team', 'users', $userId, null, ['assigned_by_architect' => $user['id'], 'role' => $role]);
                $_SESSION['success'] = ucfirst($role) . " " . htmlspecialchars($existingUser['full_name']) . " has been assigned to your team";
            }
        }
        header('Location: resource-management.php');
        exit();
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<?php
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
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

/* Welcome Section */
.welcome-banner {
    background: white;
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.welcome-banner h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--gray-900);
}

.welcome-banner h2 i {
    color: var(--theme-primary);
}

.welcome-banner p {
    margin-bottom: 0;
    font-size: 0.9rem;
    color: var(--gray-600);
}

.architect-overview-badge {
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

/* Role Workload */
.role-card-body {
    display: block !important;
    text-align: left !important;
}

.role-chart {
    position: relative;
    height: 140px;
    width: 100%;
    margin-top: 0.75rem;
}

.role-breakdown {
    text-align: left;
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

/* Tabs */
.nav-tabs {
    border-bottom: 2px solid var(--gray-200);
    margin-bottom: 0;
}

.nav-tabs .nav-link {
    border: none;
    border-bottom: 3px solid transparent;
    color: var(--gray-600);
    font-weight: 500;
    padding: 0.75rem 1.5rem;
    transition: all 0.2s;
}

.nav-tabs .nav-link:hover {
    border-bottom-color: var(--theme-light);
    color: var(--theme-primary);
}

.nav-tabs .nav-link.active {
    background: none;
    border-bottom-color: var(--theme-primary);
    color: var(--theme-primary);
}

.nav-tabs .nav-link .badge {
    font-size: 0.65rem;
    padding: 0.2rem 0.5rem;
}

/* Tab Content */
.tab-content {
    padding-top: 1rem;
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
    .welcome-banner {
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
                            <span class="badge architect-overview-badge mb-2 px-3 py-2">Architect Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Architect Resource Management - Oversee team resources, monitor workload, and manage technical talent for your projects.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flashSuccess): ?><div class="alert alert-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger border-0"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card-modern">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Total Experts/Devs</div>
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
                <div class="card-body role-card-body">
                    <div class="role-breakdown">
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
                    <div class="role-chart">
                        <canvas id="roleWorkloadChart"></canvas>
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
        <div class="card-body">
            <!-- Nav tabs -->
            <ul class="nav nav-tabs" id="resourceTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="developers-tab" data-bs-toggle="tab" data-bs-target="#developers" type="button" role="tab" aria-controls="developers" aria-selected="true">
                        <i class="fas fa-code me-2"></i>Developers
                        <span class="badge bg-primary ms-2"><?php echo $skills['developers']; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="experts-tab" data-bs-toggle="tab" data-bs-target="#experts" type="button" role="tab" aria-controls="experts" aria-selected="false">
                        <i class="fas fa-brain me-2"></i>Experts
                        <span class="badge bg-warning ms-2"><?php echo $skills['experts']; ?></span>
                    </button>
                </li>
            </ul>

            <!-- Tab content -->
            <div class="tab-content mt-4" id="resourceTabsContent">
                <!-- Developers Tab -->
                <div class="tab-pane fade show active" id="developers" role="tabpanel" aria-labelledby="developers-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Developer Resources</h6>
                        <button class="btn-primary-modern" data-bs-toggle="modal" data-bs-target="#addDeveloperModal">
                            <i class="fas fa-user-plus me-2"></i>Assign Developer
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($resource = $developers->fetch_assoc()):
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

                <!-- Experts Tab -->
                <div class="tab-pane fade" id="experts" role="tabpanel" aria-labelledby="experts-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Expert Resources</h6>
                        <button class="btn-primary-modern" data-bs-toggle="modal" data-bs-target="#addExpertModal">
                            <i class="fas fa-user-plus me-2"></i>Assign Expert
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($resource = $experts->fetch_assoc()):
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
            </div>
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
                            <a href="developer-allocation.php?id=<?php echo $project['id']; ?>" class="btn-primary-modern">
                                <i class="fas fa-chart-line"></i> Allocate
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

<!-- Add Developer Modal -->
<div class="modal fade modal-modern" id="addDeveloperModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-code me-2"></i>Assign Developer to Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="role" value="developer">
                    <div class="mb-3">
                        <label class="form-label">Select Developer</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Choose a developer...</option>
                            <?php $availableDevelopers->data_seek(0); while($dev = $availableDevelopers->fetch_assoc()): ?>
                            <option value="<?php echo $dev['id']; ?>">
                                <?php echo htmlspecialchars($dev['full_name']); ?>
                                (<?php echo htmlspecialchars($dev['email']); ?>)
                                <?php if($dev['department']): ?> - <?php echo htmlspecialchars($dev['department']); ?><?php endif; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="text-muted">Only developers created by admin are available for assignment</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_resource" class="btn-primary-modern">Assign Developer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Expert Modal -->
<div class="modal fade modal-modern" id="addExpertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-brain me-2"></i>Assign Expert to Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="role" value="expert">
                    <div class="mb-3">
                        <label class="form-label">Select Expert</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Choose an expert...</option>
                            <?php $availableExperts->data_seek(0); while($exp = $availableExperts->fetch_assoc()): ?>
                            <option value="<?php echo $exp['id']; ?>">
                                <?php echo htmlspecialchars($exp['full_name']); ?>
                                (<?php echo htmlspecialchars($exp['email']); ?>)
                                <?php if($exp['department']): ?> - <?php echo htmlspecialchars($exp['department']); ?><?php endif; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="text-muted">Only experts created by admin are available for assignment</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_resource" class="btn-primary-modern">Assign Expert</button>
                </div>
            </form>
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
    $developers->data_seek(0);
    while($row = $developers->fetch_assoc()) {
        $names[] = $row['full_name'] . ' (Dev)';
        $active[] = $row['active_tasks'];
        $completed[] = $row['completed_tasks'];
    }
    $experts->data_seek(0);
    while($row = $experts->fetch_assoc()) {
        $names[] = $row['full_name'] . ' (Exp)';
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
    echo json_encode(['roles' => $roles, 'activeTasks' => $activeTasks]);
?>;

const roleCtx = document.getElementById('roleWorkloadChart').getContext('2d');
new Chart(roleCtx, {
    type: 'doughnut',
    data: {
        labels: roleData.roles,
        datasets: [{
            data: roleData.activeTasks,
            backgroundColor: ['#3a7ca3', '#f59e0b'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// Utilization Chart
const utilizationData = <?php
    $utilizationLabels = ['Available', 'Busy', 'Overloaded'];
    $utilizationValues = [0, 0, $overloaded];

    // Calculate available and busy
    $busy = $conn->query("
        SELECT COUNT(*) as count FROM (
            SELECT u.id, COUNT(t.id) as task_count
            FROM users u
            LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
            WHERE u.role IN ('expert', 'developer')
            GROUP BY u.id
            HAVING task_count >= 3 AND task_count < 5
        ) as busy
    ")->fetch_assoc()['count'];

    $available = $conn->query("
        SELECT COUNT(*) as count FROM (
            SELECT u.id, COUNT(t.id) as task_count
            FROM users u
            LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
            WHERE u.role IN ('expert', 'developer')
            GROUP BY u.id
            HAVING task_count > 0 AND task_count < 3
        ) as available
    ")->fetch_assoc()['count'];

    $free = $totalResources - $available - $busy - $overloaded;

    echo json_encode(['labels' => ['Free', 'Available', 'Busy', 'Overloaded'], 'values' => [$free, $available, $busy, $overloaded]]);
?>;

const utilizationCtx = document.getElementById('utilizationChart').getContext('2d');
new Chart(utilizationCtx, {
    type: 'pie',
    data: {
        labels: utilizationData.labels,
        datasets: [{
            data: utilizationData.values,
            backgroundColor: ['#10b981', '#3a7ca3', '#f59e0b', '#dc2626'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

function editAllocation(userId, userName, currentTasks) {
    document.getElementById('alloc_user_id').value = userId;
    document.getElementById('alloc_user_name').textContent = userName;
    document.getElementById('alloc_current_tasks').textContent = currentTasks;
    document.getElementById('alloc_max_tasks').value = 5; // Default
    new bootstrap.Modal(document.getElementById('editAllocationModal')).show();
}

function viewResourceTasks(userId) {
    const modal = new bootstrap.Modal(document.getElementById('resourceTasksModal'));
    const content = document.getElementById('resourceTasksContent');

    content.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    modal.show();

    fetch('api/get-resource-tasks.php?user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            let html = '<div class="list-group">';
            if (data.length === 0) {
                html += '<div class="list-group-item text-center text-muted">No tasks assigned</div>';
            } else {
                data.forEach(task => {
                    const statusClass = task.status === 'completed' ? 'success' : (task.status === 'in_progress' ? 'primary' : 'warning');
                    const statusText = task.status === 'completed' ? 'Completed' : (task.status === 'in_progress' ? 'In Progress' : 'Pending');
                    html += `
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">${task.title}</h6>
                                    <p class="mb-1 text-muted small">${task.description || 'No description'}</p>
                                    <small class="text-muted">Project: ${task.project_name}</small>
                                </div>
                                <span class="badge bg-${statusClass}">${statusText}</span>
                            </div>
                            ${task.deadline ? `<small class="text-muted">Due: ${new Date(task.deadline).toLocaleDateString()}</small>` : ''}
                        </div>
                    `;
                });
            }
            html += '</div>';
            content.innerHTML = html;
        })
        .catch(error => {
            content.innerHTML = '<div class="alert alert-danger">Error loading tasks</div>';
        });
}
</script>

<?php include '../../includes/footer.php'; ?>