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

/* Cards */
.estimation-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: all 0.2s ease;
    height: 100%;
}

.estimation-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.card-header-custom {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.card-header-custom h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0 0 0.25rem 0;
}

.card-header-custom .project-name {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin: 0;
}

.card-body-custom {
    padding: 1.5rem;
}

/* Form Elements */
.form-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
    display: block;
}

.form-control-custom {
    width: 100%;
    padding: 0.6rem 0.8rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.form-control-custom:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

textarea.form-control-custom {
    resize: vertical;
    min-height: 80px;
}

/* Buttons */
.btn-submit {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.7rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Alert Info */
.alert-info-custom {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.75rem;
    margin-top: 1rem;
}

.alert-info-custom small {
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.alert-info-custom i {
    color: var(--theme-primary);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
}

.empty-state i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state h5 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--gray-500);
    margin-bottom: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .page-header-modern h2 {
        font-size: 1.5rem;
    }
    
    .card-header-custom {
        padding: 1rem 1.25rem;
    }
    
    .card-body-custom {
        padding: 1.25rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-calculator me-2"></i> 
                Feature Estimation
            </h2>
            <p>Provide three-point estimations for each assigned feature</p>
        </div>
    </div>

    <div class="row g-4">
        <?php if($tasks->num_rows > 0): ?>
            <?php while($task = $tasks->fetch_assoc()): 
                // Get existing estimation
                $estimation = $conn->query("SELECT * FROM feature_estimations WHERE task_id = {$task['id']} AND expert_id = {$user['id']}")->fetch_assoc();
            ?>
            <div class="col-lg-6">
                <div class="estimation-card">
                    <div class="card-header-custom">
                        <h5><?php echo htmlspecialchars($task['title']); ?></h5>
                        <p class="project-name">
                            <i class="fas fa-folder me-1"></i>
                            <?php echo htmlspecialchars($task['project_name']); ?>
                        </p>
                    </div>
                    <div class="card-body-custom">
                        <div class="mb-3">
                            <div class="text-muted small mb-2">Description</div>
                            <p class="mb-0 text-secondary"><?php echo htmlspecialchars($task['description']); ?></p>
                        </div>
                        
                        <form method="POST" class="mt-4">
                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Expectation Description</label>
                                <textarea name="expectation" class="form-control-custom" rows="2" required placeholder="What should this feature accomplish?"><?php echo htmlspecialchars($estimation['expectation_description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Minimum Days (Best)</label>
                                    <input type="number" name="min_days" class="form-control-custom" step="0.5" 
                                           value="<?php echo $estimation['min_days'] ?? ''; ?>" required placeholder="0.5">
                                    <small class="text-muted d-block mt-1">Optimistic estimate</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Most Likely Days</label>
                                    <input type="number" name="likely_days" class="form-control-custom" step="0.5" 
                                           value="<?php echo $estimation['likely_days'] ?? ''; ?>" required placeholder="1">
                                    <small class="text-muted d-block mt-1">Realistic estimate</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Maximum Days (Worst)</label>
                                    <input type="number" name="max_days" class="form-control-custom" step="0.5" 
                                           value="<?php echo $estimation['max_days'] ?? ''; ?>" required placeholder="2">
                                    <small class="text-muted d-block mt-1">Pessimistic estimate</small>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn-submit">
                                <i class="fas fa-calculator"></i> Submit Estimation
                            </button>
                        </form>
                        
                        <?php if($estimation): ?>
                        <div class="alert-info-custom">
                            <small>
                                <i class="fas fa-chart-line"></i> 
                                <strong>PERT Estimate:</strong> 
                                <?php echo round(($estimation['min_days'] + 4*$estimation['likely_days'] + $estimation['max_days'])/6, 1); ?> days
                                <span class="text-muted">(Min: <?php echo $estimation['min_days']; ?> | Likely: <?php echo $estimation['likely_days']; ?> | Max: <?php echo $estimation['max_days']; ?>)</span>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h5>No pending estimations</h5>
                    <p>All assigned features have been estimated. Great job!</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>