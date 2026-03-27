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
    
    $title = "New Architecture Plan Ready for Review";
    $message = "Project: " . $project['name'] . " is ready for SUL review and assignment";
    
    $sulUsers = $conn->query("SELECT id FROM users WHERE role = 'sul'");
    while($sul = $sulUsers->fetch_assoc()) {
        createNotification(
            (int) $sul['id'],
            $title,
            $message,
            'project',
            'projects',
            (int) $projectId,
            BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId
        );
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

.card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
.card:hover { transform: translateY(-2px); box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08) !important; }

/* Breadcrumb Styles */
.breadcrumb-modern {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    padding: 0.75rem 1rem;
    margin-bottom: 1.5rem;
    background: white;
    border-radius: 12px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.breadcrumb-modern .breadcrumb-item {
    display: inline-flex;
    align-items: center;
    font-size: 0.85rem;
}

.breadcrumb-modern .breadcrumb-item a {
    color: var(--theme-primary);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    transition: all 0.2s;
}

.breadcrumb-modern .breadcrumb-item a:hover {
    background: var(--gray-100);
    color: var(--theme-dark);
}

.breadcrumb-modern .breadcrumb-item.active {
    color: var(--gray-600);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.breadcrumb-modern .breadcrumb-item + .breadcrumb-item::before {
    content: "›";
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--gray-400);
    margin: 0 0.25rem;
}

/* Progress Indicator */
.progress-indicator {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    background: white;
    padding: 1rem 1.5rem;
    border-radius: 16px;
    border: 1px solid var(--gray-200);
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
}

.progress-step .step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--gray-200);
    color: var(--gray-600);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.85rem;
}

.progress-step .step-label {
    font-size: 0.7rem;
    font-weight: 500;
    color: var(--gray-600);
    text-align: center;
}

.progress-step.completed .step-number {
    background: #10b981;
    color: white;
}

.progress-step.completed .step-number i {
    font-size: 0.75rem;
}

.progress-step.active .step-number {
    background: var(--theme-primary);
    color: white;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.2);
}

.progress-step.active .step-label {
    color: var(--theme-primary);
    font-weight: 600;
}

.progress-line {
    flex: 0.5;
    height: 2px;
    background: var(--gray-200);
}

.progress-line.completed {
    background: #10b981;
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

/* Info Table */
.info-table {
    width: 100%;
    border-collapse: collapse;
}

.info-table td {
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--gray-200);
}

.info-table td:first-child {
    font-weight: 600;
    color: var(--gray-600);
    width: 30%;
}

.info-table tr:last-child td {
    border-bottom: none;
}

/* Data Table */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--gray-50);
}

.data-table th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--gray-200);
}

.data-table tr:last-child td {
    border-bottom: none;
}

.total-row {
    background: var(--gray-50);
    font-weight: 600;
}

/* Badges */
.badge-complexity {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-complexity.low { background: #d1fae5; color: #059669; }
.badge-complexity.medium { background: #dbeafe; color: #2563eb; }
.badge-complexity.high { background: #fef3c7; color: #d97706; }
.badge-complexity.critical { background: #fee2e2; color: #dc2626; }

/* Document Item */
.document-item {
    padding: 1rem;
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    margin-bottom: 0.75rem;
    transition: all 0.2s;
}

.document-item:hover {
    border-color: var(--theme-primary);
    background: var(--gray-50);
}

/* Alert */
.alert-info-modern {
    background: #dbeafe;
    border: 1px solid #bfdbfe;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.alert-info-modern i {
    color: #2563eb;
}

/* Button */
.btn-submit {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 0.7rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    width: 100%;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    color: white;
}

.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}


.btn-review {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.4rem 1rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-review:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern { padding: 1.5rem; }
    .breadcrumb-modern { padding: 0.5rem 0.75rem; }
    .progress-indicator { padding: 0.75rem; }
    .progress-step .step-label { font-size: 0.6rem; }
    .progress-step .step-number { width: 24px; height: 24px; font-size: 0.7rem; }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Back Button -->
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-project-diagram me-2"></i> 
                Architecture Plan Summary
            </h2>
            <p>Project: <?php echo htmlspecialchars($project['name']); ?> | Client: <?php echo htmlspecialchars($project['client_name']); ?></p>
        </div>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-modern">
        <ol class="breadcrumb-modern" style="display: flex; flex-wrap: wrap; align-items: center; margin: 0; padding: 0; list-style: none;">
            <li class="breadcrumb-item"><a href="projects.php"><i class="fas fa-folder-open"></i> Projects</a></li>
            <li class="breadcrumb-item"><a href="project-review.php?id=<?php echo $projectId; ?>"><i class="fas fa-clipboard-list"></i> Project Review</a></li>
            <li class="breadcrumb-item"><a href="tech-recommendation.php?id=<?php echo $projectId; ?>"><i class="fas fa-microchip"></i> Technology</a></li>
            <li class="breadcrumb-item"><a href="developer-allocation.php?id=<?php echo $projectId; ?>"><i class="fas fa-users"></i> Developers</a></li>
            <li class="breadcrumb-item"><a href="upload-diagram.php?id=<?php echo $projectId; ?>"><i class="fas fa-upload"></i> Documents</a></li>
            <li class="breadcrumb-item active"><i class="fas fa-check-circle"></i> Summary</li>
        </ol>
    </nav>

    <!-- Progress Indicator -->
    <div class="progress-indicator">
        <div class="progress-step completed"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Project Review</div></div>
        <div class="progress-line completed"></div>
        <div class="progress-step completed"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Technology</div></div>
        <div class="progress-line completed"></div>
        <div class="progress-step completed"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Developers</div></div>
        <div class="progress-line completed"></div>
        <div class="progress-step completed"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Documents</div></div>
        <div class="progress-line completed"></div>
        <div class="progress-step active"><div class="step-number">5</div><div class="step-label">Summary</div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Project Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: var(--theme-primary);"><i class="fas fa-info-circle me-2"></i>Project Information</h5>
                </div>
                <div class="card-body p-4">
                    <table class="info-table">
                        <tr><td>Project Name:</td><td class="fw-semibold"><?php echo htmlspecialchars($project['name']); ?></td></tr>
                        <tr><td>Client:</td><td><?php echo htmlspecialchars($project['client_name']); ?></td></tr>
                        <tr><td>Timeline:</td><td><?php echo date('M d, Y', strtotime($project['timeline_start'])); ?> - <?php echo date('M d, Y', strtotime($project['timeline_end'])); ?></td></tr>
                        <tr><td>Status:</td><td><span class="badge-status pending">Ready for SUL Review</span></td></tr>
                    </table>
                </div>
            </div>
            
            <!-- Technology Stack -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: var(--theme-primary);"><i class="fas fa-microchip me-2"></i>Technology Stack Recommendations</h5>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Technology</th><th>Category</th><th>Service Type</th><th class="text-end">Monthly Cost</th></tr></thead>
                            <tbody>
                                <?php $totalCost = 0; while($tech = $techRecommendations->fetch_assoc()): $totalCost += $tech['estimated_cost']; ?>
                                <tr><td class="fw-semibold"><?php echo htmlspecialchars($tech['tech_name']); ?></td><td><?php echo htmlspecialchars($tech['category']); ?></td><td><?php echo htmlspecialchars($tech['service_type']); ?></td><td class="text-end">$<?php echo number_format($tech['estimated_cost'], 2); ?></td></tr>
                                <?php endwhile; ?>
                                <tr class="total-row"><td colspan="3"><strong>Total Monthly Cost</strong></td><td class="text-end"><strong>$<?php echo number_format($totalCost, 2); ?></strong></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Developer Allocation -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: var(--theme-primary);"><i class="fas fa-users me-2"></i>Developer Allocation</h5>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Role</th><th class="text-center">Required Count</th><th>Complexity Level</th></tr></thead>
                            <tbody>
                                <?php while($alloc = $allocations->fetch_assoc()): ?>
                                <tr><td class="fw-semibold"><?php echo htmlspecialchars($alloc['role']); ?></td><td class="text-center"><?php echo $alloc['required_count']; ?></td><td><span class="badge-complexity <?php echo $alloc['complexity_level']; ?>"><?php echo ucfirst($alloc['complexity_level']); ?></span></td></tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Architecture Documents -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: var(--theme-primary);"><i class="fas fa-file-alt me-2"></i>Architecture Documents</h5>
                </div>
                <div class="card-body p-4">
                    <?php if($documents->num_rows > 0): ?>
                        <?php while($doc = $documents->fetch_assoc()): ?>
                        <div class="document-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-file-pdf text-danger me-2"></i>
                                    <strong><?php echo str_replace('_', ' ', ucfirst($doc['document_type'])); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($doc['description']); ?></small>
                                    <div class="mt-1"><small class="text-muted"><i class="far fa-clock me-1"></i> Uploaded: <?php echo date('M d, Y H:i', strtotime($doc['created_at'])); ?></small></div>
                                </div>
                                <a href="<?php echo BASE_URL; ?>/<?php echo $doc['file_path']; ?>" class="btn-review" target="_blank"><i class="fas fa-eye"></i> View</a>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted text-center py-4">No documents uploaded yet</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold" style="color: var(--theme-primary);"><i class="fas fa-chart-simple me-2"></i>Submission Summary</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3"><label class="text-muted small text-uppercase fw-semibold">Total Technologies Recommended</label><h3 class="mb-0" style="color: var(--theme-primary);"><?php echo $techRecommendations->num_rows; ?></h3></div>
                    <div class="mb-3"><label class="text-muted small text-uppercase fw-semibold">Total Developers Needed</label><h3 class="mb-0" style="color: var(--theme-primary);"><?php $totalDevs = 0; $allocations->data_seek(0); while($alloc = $allocations->fetch_assoc()) { $totalDevs += $alloc['required_count']; } echo $totalDevs; ?></h3></div>
                    <div class="mb-3"><label class="text-muted small text-uppercase fw-semibold">Documents Uploaded</label><h3 class="mb-0" style="color: var(--theme-primary);"><?php echo $documents->num_rows; ?></h3></div>
                    <hr>
                    <div class="alert-info-modern"><i class="fas fa-info-circle me-2"></i>Once submitted, this plan will be sent to the Service Unit Leader for review and task allocation.</div>
                    <form method="POST">
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="confirmCheck" required><label class="form-check-label" for="confirmCheck">I confirm that all information is accurate and complete</label></div>
                        <button type="submit" class="btn-submit" id="submitBtn" disabled><i class="fas fa-paper-plane"></i> Submit to SUL</button>
                    </form>
                </div>
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
