<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? $_GET['id'] : 0;

$project = $conn->query("
    SELECT p.*, c.name as client_name 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    WHERE p.id = $projectId
")->fetch_assoc();

if (!$project) {
    header('Location: projects.php');
    exit();
}

// Get technology recommendations
$techRecommendations = $conn->query("
    SELECT tr.*, t.name as tech_name, t.category 
    FROM tech_recommendations tr
    JOIN technologies t ON tr.tech_id = t.id
    WHERE tr.project_id = $projectId
");

// Get developer allocations
$allocations = $conn->query("
    SELECT * FROM developer_allocations 
    WHERE project_id = $projectId
");

// Get architecture documents
$documents = $conn->query("
    SELECT * FROM architecture_documents 
    WHERE project_id = $projectId
");

// Handle submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $conn->query("UPDATE projects SET status = 'in_progress' WHERE id = $projectId");
    
    // Create notification for SUL
    $title = "New Architecture Plan Ready for Review";
    $message = "Project: " . $project['name'] . " is ready for SUL review and assignment";
    
    // Get all SUL users
    $sulUsers = $conn->query("SELECT id FROM users WHERE role = 'sul'");
    while($sul = $sulUsers->fetch_assoc()) {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, 'project', ?)");
        $link = "/modules/sul/task-allocation.php?project_id=" . $projectId;
        $stmt->bind_param("isss", $sul['id'], $title, $message, $link);
        $stmt->execute();
    }
    
    logActivity($user['id'], 'submit_architecture', 'projects', $projectId);
    $_SESSION['success'] = "Architecture plan submitted to SUL successfully";
    
    header('Location: projects.php');
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Architecture Plan Summary</h2>
        <p class="text-muted">Project: <?php echo htmlspecialchars($project['name']); ?> | Client: <?php echo htmlspecialchars($project['client_name']); ?></p>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5>Project Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="30%">Project Name:</th>
                        <td><?php echo htmlspecialchars($project['name']); ?></td>
                    </tr>
                    <tr>
                        <th>Client:</th>
                        <td><?php echo htmlspecialchars($project['client_name']); ?></td>
                    </tr>
                    <tr>
                        <th>Timeline:</th>
                        <td><?php echo date('M d, Y', strtotime($project['timeline_start'])); ?> - <?php echo date('M d, Y', strtotime($project['timeline_end'])); ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="badge bg-warning">Ready for SUL Review</span></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>Technology Stack Recommendations</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Technology</th>
                                <th>Category</th>
                                <th>Service Type</th>
                                <th>Monthly Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalCost = 0;
                            while($tech = $techRecommendations->fetch_assoc()): 
                                $totalCost += $tech['estimated_cost'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tech['tech_name']); ?></td>
                                <td><?php echo htmlspecialchars($tech['category']); ?></td>
                                <td><?php echo htmlspecialchars($tech['service_type']); ?></td>
                                <td>$<?php echo number_format($tech['estimated_cost'], 2); ?></td>
                            </tr>
                            <?php endwhile; ?>
                            <tr class="table-primary">
                                <td colspan="3"><strong>Total Monthly Cost</strong></td>
                                <td><strong>$<?php echo number_format($totalCost, 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>Developer Allocation</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Required Count</th>
                                <th>Complexity Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($alloc = $allocations->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($alloc['role']); ?></td>
                                <td><?php echo $alloc['required_count']; ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $alloc['complexity_level'] == 'low' ? 'success' : 
                                            ($alloc['complexity_level'] == 'medium' ? 'info' : 
                                            ($alloc['complexity_level'] == 'high' ? 'warning' : 'danger'));
                                    ?>">
                                        <?php echo ucfirst($alloc['complexity_level']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>Architecture Documents</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($doc = $documents->fetch_assoc()): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-file-pdf text-danger me-2"></i>
                                <strong><?php echo str_replace('_', ' ', ucfirst($doc['document_type'])); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($doc['description']); ?></small>
                            </div>
                            <a href="<?php echo BASE_URL; ?>/<?php echo $doc['file_path']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5>Submission Summary</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Total Technologies Recommended:</label>
                    <h4><?php echo $techRecommendations->num_rows; ?></h4>
                </div>
                <div class="mb-3">
                    <label class="form-label">Total Developers Needed:</label>
                    <h4><?php 
                        $totalDevs = 0;
                        $allocations->data_seek(0);
                        while($alloc = $allocations->fetch_assoc()) {
                            $totalDevs += $alloc['required_count'];
                        }
                        echo $totalDevs;
                    ?></h4>
                </div>
                <div class="mb-3">
                    <label class="form-label">Documents Uploaded:</label>
                    <h4><?php echo $documents->num_rows; ?></h4>
                </div>
                <hr>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Once submitted, this plan will be sent to the Service Unit Leader for review and task allocation.
                </div>
                
                <form method="POST">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmCheck" required>
                        <label class="form-check-label" for="confirmCheck">
                            I confirm that all information is accurate and complete
                        </label>
                    </div>
                    <button type="submit" class="btn btn-success w-100" id="submitBtn" disabled>
                        <i class="fas fa-paper-plane"></i> Submit to SUL
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('confirmCheck').addEventListener('change', function() {
    document.getElementById('submitBtn').disabled = !this.checked;
});
</script>

<?php include '../../includes/footer.php'; ?>