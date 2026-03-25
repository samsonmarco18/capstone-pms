<?php
require_once '../../includes/auth.php';
requireRole('expert');

$conn = getConnection();
$user = getCurrentUser();

// Get tasks assigned to this expert
$tasks = $conn->query("
    SELECT t.*, p.name as project_name, p.id as project_id
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = {$user['id']} AND t.status != 'completed'
    ORDER BY t.deadline ASC
");

// Handle estimation submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $taskId = $_POST['task_id'];
    $minDays = $_POST['min_days'];
    $likelyDays = $_POST['likely_days'];
    $maxDays = $_POST['max_days'];
    $expectation = $_POST['expectation'];
    
    // Check if estimation exists
    $existing = $conn->query("SELECT id FROM feature_estimations WHERE task_id = $taskId AND expert_id = {$user['id']}");
    
    if($existing->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE feature_estimations SET min_days=?, likely_days=?, max_days=?, expectation_description=?, status='completed', submitted_at=NOW() WHERE task_id=? AND expert_id=?");
        $stmt->bind_param("iiisii", $minDays, $likelyDays, $maxDays, $expectation, $taskId, $user['id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO feature_estimations (task_id, expert_id, min_days, likely_days, max_days, expectation_description, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())");
        $stmt->bind_param("iiiiis", $taskId, $user['id'], $minDays, $likelyDays, $maxDays, $expectation);
    }
    
    if($stmt->execute()) {
        // Update task status
        $conn->query("UPDATE tasks SET status = 'review' WHERE id = $taskId");
        
        logActivity($user['id'], 'submit_estimation', 'tasks', $taskId, null, $_POST);
        $_SESSION['success'] = "Estimation submitted successfully";
    }
    
    header('Location: feature-estimation.php');
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Feature Estimation</h2>
        <p class="text-muted">Provide three-point estimations for each assigned feature</p>
    </div>
</div>

<div class="row">
    <?php while($task = $tasks->fetch_assoc()): 
        // Get existing estimation
        $estimation = $conn->query("SELECT * FROM feature_estimations WHERE task_id = {$task['id']} AND expert_id = {$user['id']}")->fetch_assoc();
    ?>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5><?php echo htmlspecialchars($task['title']); ?></h5>
                <p class="text-muted mb-0">Project: <?php echo htmlspecialchars($task['project_name']); ?></p>
            </div>
            <div class="card-body">
                <p><strong>Description:</strong></p>
                <p><?php echo htmlspecialchars($task['description']); ?></p>
                
                <form method="POST" class="mt-3">
                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Expectation Description</label>
                        <textarea name="expectation" class="form-control" rows="2" required><?php echo htmlspecialchars($estimation['expectation_description'] ?? ''); ?></textarea>
                        <small class="text-muted">What should this feature accomplish?</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Minimum Days (Best Case)</label>
                            <input type="number" name="min_days" class="form-control" step="0.5" 
                                   value="<?php echo $estimation['min_days'] ?? ''; ?>" required>
                            <small class="text-muted">Optimistic estimate</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Most Likely Days</label>
                            <input type="number" name="likely_days" class="form-control" step="0.5" 
                                   value="<?php echo $estimation['likely_days'] ?? ''; ?>" required>
                            <small class="text-muted">Realistic estimate</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Maximum Days (Worst Case)</label>
                            <input type="number" name="max_days" class="form-control" step="0.5" 
                                   value="<?php echo $estimation['max_days'] ?? ''; ?>" required>
                            <small class="text-muted">Pessimistic estimate</small>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-calculator"></i> Submit Estimation
                    </button>
                </form>
                
                <?php if($estimation): ?>
                <div class="mt-3 alert alert-info">
                    <small>
                        <i class="fas fa-chart-line"></i> 
                        Expected Duration: <?php echo round(($estimation['min_days'] + 4*$estimation['likely_days'] + $estimation['max_days'])/6, 1); ?> days
                        (PERT Estimate)
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endwhile; ?>
    
    <?php if($tasks->num_rows == 0): ?>
    <div class="col-12">
        <div class="alert alert-info text-center">
            <i class="fas fa-check-circle fa-3x mb-3"></i>
            <h5>No pending estimations</h5>
            <p>All assigned features have been estimated. Great job!</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>