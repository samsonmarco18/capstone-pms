<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
requireRole('sales');

$conn = getConnection();
workflowEnsureSchema($conn);

$selectedTaskId = (int) ($_GET['task_id'] ?? 0);
$filterSql = $selectedTaskId > 0 ? ' AND t.id = ' . $selectedTaskId : '';

$completedFeatures = $conn->query("
    SELECT t.id, t.title, t.description, t.completed_at, t.merged_at, t.release_notified_at,
           p.name AS project_name, c.name AS client_name, u.full_name AS developer_name, architect.full_name AS architect_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN users u ON u.id = t.assigned_to
    LEFT JOIN users architect ON architect.id = t.architect_reviewed_by
    WHERE t.status = 'completed'
      AND t.architect_reviewed_at IS NOT NULL
      {$filterSql}
    ORDER BY t.release_notified_at DESC, t.completed_at DESC
");

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-bullhorn me-2 text-primary"></i>Release Status</h2>
            <p class="text-muted mb-0">Read-only list of features completed after Architect merge.</p>
        </div>
        <span class="badge bg-success fs-6"><?php echo $completedFeatures ? (int) $completedFeatures->num_rows : 0; ?> completed</span>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if($completedFeatures && $completedFeatures->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Feature</th>
                                <th>Project / Client</th>
                                <th>Developer</th>
                                <th>Architect</th>
                                <th>Release Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($feature = $completedFeatures->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) $feature['title']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars(substr((string) ($feature['description'] ?? ''), 0, 110)); ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars((string) $feature['project_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars((string) ($feature['client_name'] ?? 'No client')); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($feature['developer_name'] ?? 'Unassigned')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($feature['architect_name'] ?? 'Architect')); ?></td>
                                    <td>
                                        <span class="badge bg-success">Completed</span>
                                        <div class="small text-muted mt-1">
                                            Merged: <?php echo !empty($feature['merged_at']) ? date('M d, Y h:i A', strtotime((string) $feature['merged_at'])) : 'n/a'; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-box-open fa-3x mb-3"></i>
                    <p class="mb-0">No merged features are ready for Sales yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
