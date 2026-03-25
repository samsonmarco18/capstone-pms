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

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5>Projects for Technical Evaluation</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Project Name</th>
                                <th>Client</th>
                                <th>Status</th>
                                <th>Timeline</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($project = $projects->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($project['name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($project['client_name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $project['status'] == 'pending' ? 'warning' : 
                                            ($project['status'] == 'review' ? 'info' : 
                                            ($project['status'] == 'in_progress' ? 'primary' : 
                                            ($project['status'] == 'completed' ? 'success' : 'secondary'))); 
                                    ?>">
                                        <?php echo ucfirst($project['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    if($project['timeline_start'] && $project['timeline_end']) {
                                        echo date('M d', strtotime($project['timeline_start'])) . ' - ' . 
                                             date('M d, Y', strtotime($project['timeline_end']));
                                    } else {
                                        echo 'Not set';
                                    }
                                    ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                                <td>
                                    <a href="project-review.php?id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> Review
                                    </a>
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

<?php include '../../includes/footer.php'; ?>