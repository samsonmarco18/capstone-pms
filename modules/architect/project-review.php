<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? $_GET['id'] : 0;

// Get project details
$project = $conn->query("
    SELECT p.*, c.name as client_name, c.email as client_email 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    WHERE p.id = $projectId
")->fetch_assoc();

if (!$project) {
    header('Location: projects.php');
    exit();
}

// Update project status if form submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $mom = trim((string) ($_POST['mom'] ?? ''));
    $features = trim((string) ($_POST['features'] ?? ''));
    $timelineStart = $_POST['timeline_start'] ?: null;
    $timelineEnd = $_POST['timeline_end'] ?: null;
    
    $stmt = $conn->prepare("
        UPDATE projects
        SET meeting_notes = ?, mom = ?, requested_features = ?, timeline_start = ?, timeline_end = ?, status = 'review', architect_id = COALESCE(architect_id, ?)
        WHERE id = ?
    ");
    $stmt->bind_param("sssssii", $notes, $mom, $features, $timelineStart, $timelineEnd, $user['id'], $projectId);
    $stmt->execute();
    
    logActivity($user['id'], 'review_project', 'projects', $projectId, null, $_POST);
    
    header('Location: tech-recommendation.php?id=' . $projectId);
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Project Requirement Review</h2>
        <p class="text-muted">Project: <?php echo htmlspecialchars($project['name']); ?> | Client: <?php echo htmlspecialchars($project['client_name']); ?></p>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5>Project Details & Requirements</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Meeting Notes</label>
                        <textarea name="notes" class="form-control" rows="4"><?php echo htmlspecialchars($project['meeting_notes']); ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Minutes of Meeting (MOM)</label>
                        <textarea name="mom" class="form-control" rows="4"><?php echo htmlspecialchars($project['mom']); ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Requested System Features</label>
                        <textarea name="features" class="form-control" rows="6" placeholder="List all requested features..."><?php echo htmlspecialchars($project['requested_features']); ?></textarea>
                        <small class="text-muted">Separate each feature with a new line for better readability</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Timeline Requested by Client</label>
                        <div class="row">
                            <div class="col-md-6">
                                <input type="date" name="timeline_start" class="form-control" value="<?php echo $project['timeline_start']; ?>" required>
                            </div>
                            <div class="col-md-6">
                                <input type="date" name="timeline_end" class="form-control" value="<?php echo $project['timeline_end']; ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> Proceed to Technology Recommendation
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5>Client Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Company:</strong> <?php echo htmlspecialchars($project['client_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($project['client_email']); ?></p>
                <p><strong>Project Created:</strong> <?php echo date('M d, Y', strtotime($project['created_at'])); ?></p>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5>Review Guidelines</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <li><i class="fas fa-check-circle text-success me-2"></i> Analyze all requirements thoroughly</li>
                    <li><i class="fas fa-check-circle text-success me-2"></i> Note any ambiguities in requirements</li>
                    <li><i class="fas fa-check-circle text-success me-2"></i> Consider scalability requirements</li>
                    <li><i class="fas fa-check-circle text-success me-2"></i> Identify potential technical challenges</li>
                    <li><i class="fas fa-check-circle text-success me-2"></i> Document assumptions made</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
