<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();

// Get all projects assigned to this architect
$projects = $conn->query("
    SELECT p.*, c.name as client_name 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    WHERE p.architect_id = {$user['id']} OR p.architect_id IS NULL
    ORDER BY p.created_at DESC
");

// Get statistics
$totalProjects = $projects->num_rows;
$pendingProjects = 0;
$inProgressProjects = 0;
$completedProjects = 0;
$reviewProjects = 0;

$projects->data_seek(0);
while($project = $projects->fetch_assoc()) {
    if($project['status'] == 'pending') $pendingProjects++;
    elseif($project['status'] == 'in_progress') $inProgressProjects++;
    elseif($project['status'] == 'completed') $completedProjects++;
    elseif($project['status'] == 'review') $reviewProjects++;
}
$projects->data_seek(0);

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
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08) !important;
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

/* Stats Row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-modern {
    background: white;
    border-radius: 20px;
    padding: 1.25rem 1.5rem;
    flex: 1;
    min-width: 180px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
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
    font-size: 2rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.card-header-modern {
    padding: 1.5rem 2rem;
    background: #ffffff;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.card-header-modern h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-header-modern h5 i {
    color: var(--theme-primary);
}

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

.project-table {
    width: 100%;
    border-collapse: collapse;
}

.project-table thead {
    background: var(--gray-50);
}

.project-table th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.project-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.project-table tr:hover {
    background: var(--gray-50);
}

.project-name {
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

/* Badges */
.badge-status {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status.pending {
    background: #fef3c7;
    color: #d97706;
}

.badge-status.review {
    background: #dbeafe;
    color: #2563eb;
}

.badge-status.in_progress {
    background: #e0e7ff;
    color: #4f46e5;
}

.badge-status.completed {
    background: #d1fae5;
    color: #059669;
}

/* Action Button */
.btn-review {
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
    text-decoration: none;
}

.btn-review:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-secondary-action {
    background: white;
    color: var(--theme-primary);
    border: 1px solid rgba(31, 99, 146, 0.2);
    padding: 0.4rem 0.9rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    text-decoration: none;
}

.btn-secondary-action:hover {
    background: var(--gray-50);
    color: var(--theme-dark);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state-modern i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state-modern h4 {
    color: var(--gray-900);
    margin-bottom: 0.5rem;
}

.empty-state-modern p {
    color: var(--gray-600);
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-row {
        flex-direction: column;
    }
    
    .stat-card-modern {
        min-width: auto;
    }
    
    .card-header-modern {
        flex-direction: column;
        align-items: stretch;
    }
    
    .project-table th,
    .project-table td {
        padding: 0.75rem 1rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-clipboard-list me-2"></i> 
                Projects for Technical Evaluation
            </h2>
            <p>Review and evaluate project requirements, provide technical recommendations</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-project-diagram stat-icon"></i>
            <div class="stat-label">Total Projects</div>
            <div class="stat-number"><?php echo $totalProjects; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-clock stat-icon"></i>
            <div class="stat-label">Pending Review</div>
            <div class="stat-number" style="color: #f59e0b;"><?php echo $pendingProjects; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-spinner stat-icon"></i>
            <div class="stat-label">In Progress</div>
            <div class="stat-number" style="color: #3b82f6;"><?php echo $inProgressProjects; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-check-circle stat-icon"></i>
            <div class="stat-label">Completed</div>
            <div class="stat-number" style="color: #10b981;"><?php echo $completedProjects; ?></div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list"></i>
                Project Directory
            </h5>
            <div>
                <span class="text-muted small">Showing <?php echo $totalProjects; ?> projects</span>
            </div>
        </div>
        
        <!-- Projects Table -->
        <div class="table-container">
            <?php if($totalProjects > 0): ?>
            <table class="project-table">
                <thead>
                    <tr>
                        <th>Project Name</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Timeline</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </thead>
                <tbody>
                    <?php while($project = $projects->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="project-name"><?php echo htmlspecialchars($project['name']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($project['client_name']); ?></td>
                        <td>
                            <span class="badge-status <?php echo $project['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            if($project['timeline_start'] && $project['timeline_end']) {
                                echo date('M d', strtotime($project['timeline_start'])) . ' - ' . 
                                     date('M d, Y', strtotime($project['timeline_end']));
                            } else {
                                echo '<span class="text-muted">Not set</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                        <td>
                            <div class="d-flex flex-wrap gap-2">
                            <a href="project-review.php?id=<?php echo $project['id']; ?>" class="btn-review">
                                <i class="fas fa-eye"></i> Review
                            </a>
                            <a href="../version-control/repositories.php?project_id=<?php echo $project['id']; ?>" class="btn-secondary-action">
                                <i class="fas fa-code-branch"></i> Version Control
                            </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-folder-open"></i>
                <h4>No Projects Found</h4>
                <p>No projects have been assigned for technical evaluation yet</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
