<?php
require_once '../../includes/auth.php';
require_once '../../includes/file-storage.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

function normalizeArchitectureDocumentType(?string $value): string
{
    $normalized = strtolower(trim((string) $value));

    return match ($normalized) {
        'architecture_plan' => 'architecture_plan',
        'workflow_diagram' => 'workflow_diagram',
        'system_design', 'system_flow' => 'system_flow',
        'technical_spec', 'deployment_diagram' => 'deployment_diagram',
        'database_schema' => 'database_schema',
        default => 'architecture_plan'
    };
}

$project = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch_assoc();

if (!$project) {
    header('Location: projects.php');
    exit();
}

if (!dbColumnExists($conn, 'projects', 'included_departments')) {
    $conn->query("ALTER TABLE projects ADD COLUMN included_departments " . (DB_DRIVER === 'pgsql' ? 'TEXT' : 'LONGTEXT') . " NULL");
    $project = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch_assoc();
}

$hasIncludedDepartments = false;
$includedDepartmentRaw = trim((string) ($project['included_departments'] ?? ''));
if ($includedDepartmentRaw !== '') {
    $decodedDepartments = json_decode($includedDepartmentRaw, true);
    $hasIncludedDepartments = is_array($decodedDepartments) && count($decodedDepartments) > 0;
}

if (!$hasIncludedDepartments && dbTableExists($conn, 'developer_allocations')) {
    $allocationCount = $conn->query("SELECT COUNT(*) AS count FROM developer_allocations WHERE project_id = $projectId")->fetch_assoc();
    $hasIncludedDepartments = (int) ($allocationCount['count'] ?? 0) > 0;
}

if (!$hasIncludedDepartments) {
    $_SESSION['error'] = 'Select the departments included in this project before uploading architecture documents.';
    header('Location: developer-allocation.php?id=' . $projectId);
    exit();
}

// Get existing documents
$documents = $conn->query("
    SELECT * FROM architecture_documents 
    WHERE project_id = $projectId 
    ORDER BY created_at DESC
");

$uploadedDocumentTypes = [];
if ($documents) {
    while ($documentRow = $documents->fetch_assoc()) {
        $uploadedDocumentTypes[] = (string) ($documentRow['document_type'] ?? '');
    }
    $documents->data_seek(0);
}
$requiredDocumentTypes = ['architecture_plan', 'workflow_diagram'];
$hasArchitecturePlan = in_array('architecture_plan', $uploadedDocumentTypes, true);
$hasWorkflowDiagram = in_array('workflow_diagram', $uploadedDocumentTypes, true);
$hasSupportingTechnicalDoc = count(array_diff(array_unique($uploadedDocumentTypes), $requiredDocumentTypes)) > 0;
$checklistReadyForSummary = $hasArchitecturePlan && $hasWorkflowDiagram;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $docType = normalizeArchitectureDocumentType($_POST['document_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if(isset($_FILES['diagram']) && $_FILES['diagram']['error'] == 0) {
        $target = storageBuildUploadTarget((string) $_FILES['diagram']['name'], 'architecture');
        $originalFileName = $target['original_name'];
        
        if (move_uploaded_file($_FILES['diagram']['tmp_name'], $target['absolute_path'])) {
            $stmt = $conn->prepare("INSERT INTO architecture_documents (project_id, document_type, file_name, file_path, description, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssi", $projectId, $docType, $originalFileName, $target['relative_path'], $description, $user['id']);
            $stmt->execute();
            
            logActivity($user['id'], 'upload_diagram', 'projects', $projectId, null, $_POST);
            $_SESSION['success'] = "Document uploaded successfully";
        }
    }
    
    header('Location: upload-diagram.php?id=' . $projectId);
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
    text-decoration: none;
    transition: transform 0.2s ease;
}

a.progress-step:hover {
    text-decoration: none;
    transform: translateY(-2px);
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

/* Form Card */
.form-card, .documents-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    height: 100%;
}

.form-card .card-header, .documents-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.form-card .card-header h5, .documents-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-card .card-header h5 i, .documents-card .card-header h5 i {
    color: var(--theme-primary);
}

.form-card .card-body, .documents-card .card-body {
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

.form-control-modern, .form-select-modern {
    width: 100%;
    padding: 0.7rem 1rem;
    border: 1px solid var(--gray-300);
    border-radius: 12px;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.form-control-modern:focus, .form-select-modern:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

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

/* Buttons */
.btn-upload {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-upload:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
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

.btn-danger-modern {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
}

.btn-danger-modern:hover {
    transform: translateY(-1px);
    color: white;
}

.btn-success-modern {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-success-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    color: white;
}


/* Checklist Card */
.checklist-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    margin-top: 1.5rem;
}

.checklist-card .card-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.checklist-card .card-header h5 {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 0;
    color: var(--gray-900);
}

.checklist-card .card-body {
    padding: 1.25rem 1.5rem;
}

.checklist-item {
    margin-bottom: 0.75rem;
}

.checklist-item:last-child {
    margin-bottom: 0;
}

.form-check-input:checked {
    background-color: var(--theme-primary);
    border-color: var(--theme-primary);
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern { padding: 1.5rem; }
    .breadcrumb-modern { padding: 0.5rem 0.75rem; }
    .progress-indicator { padding: 0.75rem; }
    .progress-step .step-label { font-size: 0.6rem; }
    .progress-step .step-number { width: 24px; height: 24px; font-size: 0.7rem; }
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .documents-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .upload-area {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .documents-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) label {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-meta {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-upload me-2"></i> 
                Upload Architecture Plan and Workflow Diagram
            </h2>
            <p>Project: <?php echo htmlspecialchars($project['name']); ?></p>
        </div>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-modern">
        <ol class="breadcrumb-modern" style="display: flex; flex-wrap: wrap; align-items: center; margin: 0; padding: 0; list-style: none;">
            <li class="breadcrumb-item"><a href="projects.php"><i class="fas fa-folder-open"></i> Projects</a></li>
            <li class="breadcrumb-item"><a href="project-review.php?id=<?php echo $projectId; ?>"><i class="fas fa-clipboard-list"></i> Project Review</a></li>
            <li class="breadcrumb-item"><a href="features.php?id=<?php echo $projectId; ?>"><i class="fas fa-list-check"></i> Features</a></li>
            <li class="breadcrumb-item"><a href="tech-recommendation.php?id=<?php echo $projectId; ?>"><i class="fas fa-microchip"></i> Technology</a></li>
            <li class="breadcrumb-item"><a href="developer-allocation.php?id=<?php echo $projectId; ?>"><i class="fas fa-users"></i> Developers</a></li>
            <li class="breadcrumb-item"><a href="../roadmap/methodology.php?project_id=<?php echo $projectId; ?>"><i class="fas fa-route"></i> Roadmap</a></li>
            <li class="breadcrumb-item active"><i class="fas fa-upload"></i> Documents</li>
        </ol>
    </nav>

    <!-- Progress Indicator -->
    <div class="progress-indicator">
        <a class="progress-step completed" href="project-review.php?id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Project Review</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step completed" href="features.php?id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Features</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step completed" href="tech-recommendation.php?id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Technology</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step completed" href="developer-allocation.php?id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Developers</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step completed" href="../roadmap/methodology.php?project_id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Roadmap</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step active" href="upload-diagram.php?id=<?php echo $projectId; ?>"><div class="step-number">6</div><div class="step-label">Documents</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="summary.php?id=<?php echo $projectId; ?>"><div class="step-number">7</div><div class="step-label">Summary</div></a>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="form-card">
                <div class="card-header">
                    <h5><i class="fas fa-plus-circle"></i> Upload New Document</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" data-app-loading data-loading-title="Uploading document" data-loading-text="Please wait while the architecture document is uploaded.">
                        <div class="mb-3">
                            <label class="form-label">Document Type</label>
                            <select name="document_type" class="form-select-modern" required>
                                <option value="architecture_plan">Architecture Plan</option>
                                <option value="workflow_diagram">Workflow Diagram</option>
                                <option value="system_flow">System Flow Diagram</option>
                                <option value="deployment_diagram">Deployment Diagram</option>
                                <option value="database_schema">Database Schema</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control-modern" rows="3" required placeholder="Brief description of the document..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">File (PDF, Image, or Document)</label>
                            <input type="file" name="diagram" class="form-control-modern" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" required>
                            <small class="text-muted d-block mt-1">Accepted formats: PDF, PNG, JPG, DOC, DOCX (Max 10MB)</small>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <button type="submit" class="btn-upload"><i class="fas fa-upload"></i> Upload Document</button>
                            <a href="summary.php?id=<?php echo $projectId; ?>" class="btn-success-modern <?php echo $checklistReadyForSummary ? '' : 'disabled'; ?>" <?php echo $checklistReadyForSummary ? '' : 'aria-disabled="true" tabindex="-1"'; ?>><i class="fas fa-check"></i> Proceed to Summary</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="documents-card">
                <div class="card-header">
                    <h5><i class="fas fa-folder-open"></i> Uploaded Documents</h5>
                </div>
                <div class="card-body">
                    <?php if($documents->num_rows > 0): ?>
                        <?php while($doc = $documents->fetch_assoc()): ?>
                        <div class="document-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <i class="fas fa-file-<?php 
                                        echo $doc['document_type'] == 'architecture_plan' ? 'code' : 
                                            ($doc['document_type'] == 'workflow_diagram' ? 'project-diagram' : 'file');
                                    ?> fa-lg text-primary me-2"></i>
                                    <strong><?php echo str_replace('_', ' ', ucfirst($doc['document_type'])); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($doc['description']); ?></small>
                                    <div class="mt-1">
                                        <small class="text-muted"><i class="far fa-clock me-1"></i> Uploaded: <?php echo !empty($doc['created_at']) ? date('M d, Y H:i', strtotime((string) $doc['created_at'])) : 'No date'; ?></small>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="<?php echo BASE_URL; ?>/<?php echo $doc['file_path']; ?>" class="btn-review" target="_blank"><i class="fas fa-eye"></i></a>
                                    <button type="button" class="btn-danger-modern" onclick="deleteDocument(<?php echo $doc['id']; ?>)"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No documents uploaded yet</p>
                            <small class="text-muted">Upload architecture documents using the form on the left</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="checklist-card">
                <div class="card-header">
                    <h5><i class="fas fa-check-circle"></i> Submission Checklist</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3"><div class="checklist-item"><div class="form-check"><input class="form-check-input" type="checkbox" id="check1" <?php echo $hasArchitecturePlan ? 'checked' : ''; ?> disabled><label class="form-check-label" for="check1">Architecture Plan Uploaded</label></div></div></div>
                        <div class="col-md-3"><div class="checklist-item"><div class="form-check"><input class="form-check-input" type="checkbox" id="check2" <?php echo $hasWorkflowDiagram ? 'checked' : ''; ?> disabled><label class="form-check-label" for="check2">Workflow Diagram Uploaded</label></div></div></div>
                        <div class="col-md-3"><div class="checklist-item"><div class="form-check"><input class="form-check-input" type="checkbox" id="check3" <?php echo $hasSupportingTechnicalDoc ? 'checked' : ''; ?> disabled><label class="form-check-label" for="check3">Supporting Technical Doc Uploaded</label></div></div></div>
                        <div class="col-md-3"><div class="checklist-item"><div class="form-check"><input class="form-check-input" type="checkbox" id="check4" <?php echo $checklistReadyForSummary ? 'checked' : ''; ?> disabled><label class="form-check-label" for="check4">Ready for Summary</label></div></div></div>
                    </div>
                    <?php if (!$checklistReadyForSummary): ?>
                        <div class="alert alert-warning small mb-0 mt-3">
                            Upload both an Architecture Plan and a Workflow Diagram before proceeding to Summary.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
async function deleteDocument(id) {
    const confirmed = await appConfirm({
        title: 'Delete Document',
        subject: 'Delete this architecture document?',
        message: 'The uploaded document will be removed permanently.',
        icon: 'fas fa-triangle-exclamation fa-lg',
        variant: 'danger',
        confirmIcon: 'fas fa-trash-alt',
        confirmText: 'Yes, delete it'
    });
    if (!confirmed) return;

    if (window.appShowLoading) {
        window.appShowLoading('Deleting document', 'Please wait while the document is removed.');
    }

    $.ajax({
        url: '../../api/delete-document.php',
        method: 'POST',
        data: { id: id },
        success: function() { location.reload(); },
        complete: function() {
            if (window.appHideLoading) window.appHideLoading();
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
