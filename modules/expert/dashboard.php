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

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-800: #343a40;
    --gray-900: #212529;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

/* Welcome Banner */
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

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card .stat-icon {
    position: absolute;
    right: 1.5rem;
    top: 1.5rem;
    opacity: 0.65;
    font-size: 3rem;
    color: var(--theme-primary);
}

.stat-card .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card .stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stat-card .stat-sub {
    font-size: 0.7rem;
    color: var(--gray-600);
}

/* Cards */
.card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card-no-border {
    border: none;
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

/* List Group Items */
.list-group-item-custom {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    transition: background 0.2s;
}

.list-group-item-custom:last-child {
    border-bottom: none;
}

.list-group-item-custom:hover {
    background: var(--gray-50);
}

/* Timeline */
.timeline-item {
    position: relative;
    padding-left: 2rem;
    padding-bottom: 1rem;
    border-left: 2px solid var(--gray-200);
}

.timeline-item:last-child {
    border-left: 2px solid transparent;
    padding-bottom: 0;
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

.timeline-item h6 {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: var(--gray-900);
}

.timeline-item small {
    font-size: 0.7rem;
    color: var(--gray-600);
}

.timeline-item p {
    font-size: 0.8rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
    margin-bottom: 0;
}

/* Table Styles */
.table-responsive-custom {
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
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
    font-size: 0.85rem;
}

.data-table tr:hover {
    background: var(--gray-50);
}

/* Badges */
.badge-urgent {
    background: #fee2e2;
    color: #dc2626;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-cost {
    background: var(--theme-primary);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

/* Buttons */
.btn-estimate {
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

.btn-estimate:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Progress Bar */
.progress-custom {
    height: 6px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar-custom {
    height: 100%;
    background: linear-gradient(90deg, var(--theme-primary) 0%, var(--theme-light) 100%);
    border-radius: 10px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 2rem;
}

.empty-state i {
    font-size: 3rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state p {
    color: var(--gray-500);
    font-size: 0.85rem;
    margin: 0;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
}

@media (max-width: 768px) {
    .welcome-banner {
        padding: 1.5rem;
    }
    
    .welcome-banner h2 {
        font-size: 1.5rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .stat-card .stat-number {
        font-size: 2rem;
    }
    
    .card-header {
        padding: 1rem 1.25rem;
    }
    
    .card-body {
        padding: 1.25rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <h2>
            <i class="fas fa-chart-line me-2"></i> 
            Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!
        </h2>
        <p>Expert/Senior Dashboard - Technical estimations and project planning</p>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Total Estimations</div>
                            <div class="h1 fw-bold mb-2" style="color: #1f6392;"><?php echo $totalEstimations; ?></div>
                            <small class="text-muted">All time</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-chart-line" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Pending</div>
                            <div class="h1 fw-bold mb-2" style="color: #f59e0b;"><?php echo $pendingEstimations; ?></div>
                            <small class="text-muted">Awaiting estimation</small>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Completed</div>
                            <div class="h1 fw-bold mb-2" style="color: #10b981;"><?php echo $completedEstimations; ?></div>
                            <small class="text-muted">Submitted</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-check-circle" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
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
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Active Projects</div>
                            <div class="h1 fw-bold mb-2" style="color: #1f6392;"><?php echo $pendingTasks->num_rows; ?></div>
                            <small class="text-muted">Currently estimating</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-project-diagram" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <!-- Pending Feature Estimations -->
            <div class="card mb-4 card-no-border">
                <div class="card-header">
                    <h5><i class="fas fa-calculator"></i> Pending Feature Estimations</h5>
                </div>
                <div>
                    <?php if($pendingTasks->num_rows > 0): ?>
                        <?php while($task = $pendingTasks->fetch_assoc()): 
                            $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="list-group-item-custom">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <h6 class="fw-semibold mb-0"><?php echo htmlspecialchars($task['title']); ?></h6>
                                        <?php if($daysLeft <= 3): ?>
                                            <span class="badge-urgent">Urgent</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mb-1">
                                        <small class="text-muted"><i class="fas fa-folder me-1"></i><?php echo htmlspecialchars($task['project_name']); ?></small>
                                        <small class="text-muted ms-3"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></small>
                                    </div>
                                    <p class="small text-muted mb-0"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?></p>
                                </div>
                                <a href="feature-estimation.php?task_id=<?php echo $task['id']; ?>" class="btn-estimate ms-3">
                                    <i class="fas fa-calculator"></i> Estimate
                                </a>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending estimations</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Team Compositions -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-users"></i> Recent Team Compositions</h5>
                </div>
                <div class="table-responsive-custom">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Role</th>
                                <th class="text-center">Count</th>
                                <th class="text-center">Allocation %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($tc = $teamCompositions->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($tc['project_name']); ?></td>
                                <td><?php echo htmlspecialchars($tc['role']); ?></td>
                                <td class="text-center"><?php echo $tc['required_count']; ?></td>
                                <td class="text-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress-custom flex-grow-1">
                                            <div class="progress-bar-custom" style="width: <?php echo $tc['allocation_percentage']; ?>%;"></div>
                                        </div>
                                        <span class="small"><?php echo $tc['allocation_percentage']; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if($teamCompositions->num_rows == 0): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No team compositions available</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-xl-5">
            <!-- Upcoming Schedule -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-alt"></i> Upcoming Schedule</h5>
                </div>
                <div class="card-body">
                    <?php if($schedules->num_rows > 0): ?>
                        <?php while($schedule = $schedules->fetch_assoc()): ?>
                        <div class="timeline-item mb-3">
                            <h6><?php echo htmlspecialchars($schedule['title']); ?></h6>
                            <small><i class="far fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($schedule['start_time'])); ?></small>
                            <?php if(!empty($schedule['description'])): ?>
                            <p><?php echo htmlspecialchars($schedule['description']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-week"></i>
                            <p>No upcoming schedules</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Cost Estimates -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-dollar-sign"></i> Recent Cost Estimates</h5>
                </div>
                <div>
                    <?php if($costEstimates->num_rows > 0): ?>
                        <?php while($cost = $costEstimates->fetch_assoc()): ?>
                        <div class="list-group-item-custom">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold mb-1"><?php echo htmlspecialchars($cost['project_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($cost['component']); ?> (<?php echo htmlspecialchars($cost['azure_service']); ?>)</small>
                                </div>
                                <span class="badge-cost">$<?php echo number_format($cost['monthly_cost'], 2); ?></span>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <p>No cost estimates available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
