<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/workflow.php';
require_once '../../includes/methodology-roadmap.php';
require_once '../../includes/project-workflow.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
projectWorkflowEnsureSchema($conn);
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$hasCloudPlatform = dbColumnExists($conn, 'projects', 'cloud_platform');
$hasDatabaseServer = dbColumnExists($conn, 'projects', 'database_server');
$hasStorageRequirements = dbColumnExists($conn, 'projects', 'storage_requirements');
$hasAdditionalServices = dbColumnExists($conn, 'projects', 'additional_services');
$hasSalesBudget = dbColumnExists($conn, 'projects', 'sales_budget');

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

$featureCountRow = $conn->query("SELECT COUNT(*) AS total FROM tasks WHERE project_id = $projectId AND task_type = 'feature'")->fetch_assoc();
$featureCount = (int) ($featureCountRow['total'] ?? 0);
$expertAssignmentCountRow = $conn->query("
    SELECT COUNT(*) AS total
    FROM tasks
    WHERE project_id = $projectId
      AND task_type = 'feature'
      AND assigned_to IS NOT NULL
      AND assigned_to != 0
")->fetch_assoc();
$expertAssignmentCount = (int) ($expertAssignmentCountRow['total'] ?? 0);

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

$techRecommendationCount = (int) $techRecommendations->num_rows;
$allocationCount = (int) $allocations->num_rows;
$documentCount = (int) $documents->num_rows;
$documentTypesResult = $conn->query("SELECT DISTINCT document_type FROM architecture_documents WHERE project_id = $projectId");
$documentTypes = [];
while ($documentTypeRow = $documentTypesResult->fetch_assoc()) {
    $documentTypes[] = (string) $documentTypeRow['document_type'];
}
$hasRequiredDocuments = in_array('architecture_plan', $documentTypes, true) && in_array('workflow_diagram', $documentTypes, true);
$methodologyCompletion = methodologyRoadmapCompletion($conn, $projectId);
$summaryReadyForSubmit = $featureCount > 0
    && $techRecommendationCount > 0
    && $allocationCount > 0
    && $hasRequiredDocuments
    && (bool) ($methodologyCompletion['is_complete'] ?? false);
$isSubmittedToSul = trim((string) ($project['workflow_status'] ?? '')) === 'architect_submitted';
$isAwaitingSulReview = $isSubmittedToSul
    && $expertAssignmentCount === 0
    && trim((string) ($project['status'] ?? '')) === 'in_progress';

// Handle submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($isSubmittedToSul) {
        $_SESSION['success'] = 'This architecture plan has already been submitted to the Service Unit Leader.';
        header('Location: projects.php?submitted=' . $projectId);
        exit();
    }

    // Check if confirmation checkbox is checked
    if (empty($_POST['confirmCheck'])) {
        $_SESSION['error'] = 'Please confirm that all information is accurate and complete before submitting.';
        header('Location: summary.php?id=' . $projectId);
        exit();
    }

    if (!$hasRequiredDocuments) {
        $_SESSION['error'] = 'Upload the required Architecture Plan and Workflow Diagram before submitting to SUL.';
        header('Location: upload-diagram.php?id=' . $projectId);
        exit();
    }

    if ($featureCount <= 0 || $techRecommendationCount <= 0 || $allocationCount <= 0 || !(bool) ($methodologyCompletion['is_complete'] ?? false)) {
        $_SESSION['error'] = 'Please complete every architect step and schedule every feature in the methodology roadmap before submitting to SUL.';
        header('Location: summary.php?id=' . $projectId);
        exit();
    }

    $firstHandoffDepartment = '';
    $firstHandoffRow = $conn->query("
        SELECT mt.department
        FROM methodology_tasks mt
        INNER JOIN methodology_phases mp ON mp.id = mt.phase_id
        WHERE mt.project_id = {$projectId}
          AND mt.department IS NOT NULL
          AND mt.department != ''
        ORDER BY COALESCE(mp.sequence_no, mp.id) ASC, mt.id ASC
        LIMIT 1
    ");
    if ($departmentRow = $firstHandoffRow->fetch_assoc()) {
        $firstHandoffDepartment = projectWorkflowDepartmentLabel($departmentRow['department'] ?? '');
    }
    if ($firstHandoffDepartment !== 'Expert Department') {
        $_SESSION['error'] = 'Expert Department must be the first department before Architect submission because Expert SUL receives the project for estimation first.';
        header('Location: summary.php?id=' . $projectId);
        exit();
    }

    $lastDepartment = '';
    $lastDepartmentRow = $conn->query("
        SELECT mt.department
        FROM methodology_tasks mt
        INNER JOIN methodology_phases mp ON mp.id = mt.phase_id
        WHERE mt.project_id = {$projectId}
          AND mt.department IS NOT NULL
          AND mt.department != ''
        ORDER BY COALESCE(mp.sequence_no, mp.id) DESC, mt.id DESC
        LIMIT 1
    ")->fetch_assoc();
    $lastDepartment = projectWorkflowDepartmentLabel($lastDepartmentRow['department'] ?? '');
    if ($lastDepartment !== 'QA') {
        $_SESSION['error'] = 'QA must be the final department before Architect submission. Place QA as the last roadmap phase.';
        header('Location: summary.php?id=' . $projectId);
        exit();
    }

    $selectedDepartments = [];
    $departmentRaw = trim((string) ($project['included_departments'] ?? ''));
    $decodedDepartments = $departmentRaw !== '' ? json_decode($departmentRaw, true) : [];
    if (is_array($decodedDepartments)) {
        $selectedDepartments = array_map(static fn($department): string => projectWorkflowDepartmentLabel((string) $department), $decodedDepartments);
    }
    if (!in_array('Expert Department', $selectedDepartments, true)) {
        $_SESSION['error'] = 'Expert Department must be included so the Expert SUL can receive the project for estimation.';
        header('Location: summary.php?id=' . $projectId);
        exit();
    }

    $submitStatement = $conn->prepare("
        UPDATE projects
        SET status = 'in_progress', workflow_status = 'architect_submitted'
        WHERE id = ?
          AND (workflow_status IS NULL OR workflow_status != 'architect_submitted')
    ");
    if ($submitStatement === false) {
        $_SESSION['error'] = 'The plan could not be submitted right now. Please try again.';
        header('Location: summary.php?id=' . $projectId);
        exit();
    }

    $submitStatement->bind_param('i', $projectId);
    $submitSucceeded = $submitStatement->execute();
    $submittedRows = (int) $conn->affected_rows;
    $submitStatement->close();

    if (!$submitSucceeded) {
        $_SESSION['error'] = 'The plan could not be submitted right now. Please try again.';
        header('Location: summary.php?id=' . $projectId);
        exit();
    }

    if ($submittedRows === 0) {
        $_SESSION['success'] = 'This architecture plan has already been submitted to the Service Unit Leader.';
        header('Location: projects.php?submitted=' . $projectId);
        exit();
    }

    projectWorkflowNotifyDepartmentSuls(
        $conn,
        'Expert Department',
        'Project Ready for Expert Estimation: ' . (string) $project['name'],
        'Architect submitted the completed plan. Assign the project feature tasks to an Expert Developer for estimation.',
        BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId,
        $projectId
    );
    
    logActivity($user['id'], 'submit_architecture', 'projects', $projectId);
    $_SESSION['success'] = 'Architecture plan submitted successfully. The Expert Department SUL has been notified for estimation assignment.';
    
    header('Location: projects.php?submitted=' . $projectId);
    exit();
}

$flashSuccess = trim((string) ($_SESSION['success'] ?? ''));
$flashError = trim((string) ($_SESSION['error'] ?? ''));
unset($_SESSION['success'], $_SESSION['error']);

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

.badge-complexity.low,
.badge-complexity.simple { background: #d1fae5; color: #059669; }
.badge-complexity.medium { background: #dbeafe; color: #2563eb; }
.badge-complexity.high,
.badge-complexity.complex { background: #fef3c7; color: #d97706; }
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
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    color: #213f61;
    line-height: 1.55;
}

.alert-info-modern i {
    color: #2563eb;
    flex: 0 0 auto;
    margin-top: 0.2rem;
}

.workflow-feedback {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
    margin-bottom: 1.5rem;
    padding: 1rem 1.1rem;
    border: 1px solid;
    border-radius: 14px;
    box-shadow: var(--shadow-sm);
}

.workflow-feedback > i {
    flex: 0 0 auto;
    margin-top: 0.15rem;
    font-size: 1.15rem;
}

.workflow-feedback p {
    margin: 0;
    line-height: 1.5;
}

.workflow-feedback.success {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #076447;
}

.workflow-feedback.error {
    background: #fff1f2;
    border-color: #fecdd3;
    color: #9f1239;
}

.submission-state {
    padding: 1.25rem;
    border: 1px solid #a7f3d0;
    border-radius: 16px;
    background: linear-gradient(145deg, #ecfdf5 0%, #f0fdfa 100%);
    text-align: center;
}

.submission-state__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    margin-bottom: 0.75rem;
    border-radius: 50%;
    background: #10b981;
    color: white;
    box-shadow: 0 7px 18px rgba(16, 185, 129, 0.24);
}

.submission-state__label {
    display: block;
    margin-bottom: 0.3rem;
    color: #047857;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.submission-state h6 {
    margin-bottom: 0.45rem;
    color: #064e3b;
    font-size: 1rem;
    font-weight: 700;
}

.submission-state p {
    margin-bottom: 1rem;
    color: #41685d;
    font-size: 0.82rem;
    line-height: 1.55;
}

.submission-state__action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    min-height: 40px;
    padding: 0.55rem 1rem;
    border: 1px solid rgba(5, 150, 105, 0.3);
    border-radius: 10px;
    background: white;
    color: #047857;
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
}

.submission-state__action:hover {
    background: #d1fae5;
    color: #065f46;
}

.submission-confirmation {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    margin-bottom: 1rem;
    padding: 0.25rem 0;
}

.submission-confirmation .form-check-input {
    flex: 0 0 auto;
    margin: 0.25rem 0 0;
}

.submission-confirmation .form-check-label {
    color: #213f61;
    line-height: 1.5;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-item {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-table tr,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr.total-row {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-name {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-meta {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-feedback.success,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-state {
    background: #08251f;
    border-color: rgba(52, 211, 153, 0.38);
    color: #a7f3d0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-feedback.error {
    background: #2b1118;
    border-color: rgba(251, 113, 133, 0.38);
    color: #fecdd3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-state h6 {
    color: #d1fae5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-state p {
    color: #9cc8bb;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-state__action {
    background: #0a1b2c;
    border-color: rgba(52, 211, 153, 0.38);
    color: #a7f3d0;
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

    <?php if ($flashSuccess !== ''): ?>
        <div class="workflow-feedback success" role="status">
            <i class="fas fa-circle-check" aria-hidden="true"></i>
            <p><?php echo htmlspecialchars($flashSuccess); ?></p>
        </div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="workflow-feedback error" role="alert">
            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
            <p><?php echo htmlspecialchars($flashError); ?></p>
        </div>
    <?php endif; ?>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-modern">
        <ol class="breadcrumb-modern" style="display: flex; flex-wrap: wrap; align-items: center; margin: 0; padding: 0; list-style: none;">
            <li class="breadcrumb-item"><a href="projects.php"><i class="fas fa-folder-open"></i> Projects</a></li>
            <li class="breadcrumb-item"><a href="project-review.php?id=<?php echo $projectId; ?>"><i class="fas fa-clipboard-list"></i> Project Review</a></li>
            <li class="breadcrumb-item"><a href="features.php?id=<?php echo $projectId; ?>"><i class="fas fa-list-check"></i> Features</a></li>
            <li class="breadcrumb-item"><a href="tech-recommendation.php?id=<?php echo $projectId; ?>"><i class="fas fa-microchip"></i> Technology</a></li>
            <li class="breadcrumb-item"><a href="developer-allocation.php?id=<?php echo $projectId; ?>"><i class="fas fa-users"></i> Developers</a></li>
            <li class="breadcrumb-item"><a href="../roadmap/methodology.php?project_id=<?php echo $projectId; ?>"><i class="fas fa-route"></i> Roadmap</a></li>
            <li class="breadcrumb-item"><a href="upload-diagram.php?id=<?php echo $projectId; ?>"><i class="fas fa-upload"></i> Documents</a></li>
            <li class="breadcrumb-item active"><i class="fas fa-check-circle"></i> Summary</li>
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
        <a class="progress-step completed" href="upload-diagram.php?id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Documents</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step active" href="summary.php?id=<?php echo $projectId; ?>"><div class="step-number">7</div><div class="step-label">Summary</div></a>
    </div>

    <?php include '../../includes/architect-project-review-panel.php'; ?>

    <?php
    delayInsightsRenderSection($conn, $user, [
        'project_id' => $projectId,
        'title' => 'Project Insight Notifications',
        'subtitle' => 'Before submission, review the predicted delay percentage, success rate, recommended adjustments, and the delay causes or owners that already need attention for this project.',
    ]);
    ?>

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
                        <tr><td>Timeline:</td><td><?php echo !empty($project['timeline_start']) ? date('M d, Y', strtotime((string) $project['timeline_start'])) : 'No start date'; ?> - <?php echo !empty($project['timeline_end']) ? date('M d, Y', strtotime((string) $project['timeline_end'])) : 'No end date'; ?></td></tr>
                        <tr><td>Status:</td><td><span class="badge-status pending">Ready for SUL Review</span></td></tr>
                        <?php if ($hasSalesBudget && !empty($project['sales_budget'])): ?>
                        <tr><td>Budget:</td><td><?php echo number_format((float) $project['sales_budget'], 2); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($hasCloudPlatform && !empty($project['cloud_platform'])): ?>
                        <tr><td>Cloud Platform:</td><td><?php echo htmlspecialchars($project['cloud_platform']); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($hasDatabaseServer && !empty($project['database_server'])): ?>
                        <tr><td>Database Server:</td><td><?php echo htmlspecialchars($project['database_server']); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($hasStorageRequirements && !empty($project['storage_requirements'])): ?>
                        <tr><td>Storage:</td><td><?php echo htmlspecialchars($project['storage_requirements']); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($hasAdditionalServices && !empty($project['additional_services'])): ?>
                        <tr><td>Additional Services:</td><td><?php echo htmlspecialchars($project['additional_services']); ?></td></tr>
                        <?php endif; ?>
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
                                <tr><td class="fw-semibold"><?php echo htmlspecialchars($tech['tech_name']); ?></td><td><?php echo htmlspecialchars($tech['category']); ?></td><td><?php echo htmlspecialchars($tech['service_type']); ?></td><td class="text-end">&#8369;<?php echo number_format($tech['estimated_cost'], 2); ?></td></tr>
                                <?php endwhile; ?>
                                <tr class="total-row"><td colspan="3"><strong>Total Monthly Cost</strong></td><td class="text-end"><strong>&#8369;<?php echo number_format($totalCost, 2); ?></strong></td></tr>
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
                                    <div class="mt-1"><small class="text-muted"><i class="far fa-clock me-1"></i> Uploaded: <?php echo !empty($doc['created_at']) ? date('M d, Y H:i', strtotime((string) $doc['created_at'])) : 'No date'; ?></small></div>
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
                    <div class="mb-3"><label class="text-muted small text-uppercase fw-semibold">Total Features</label><h3 class="mb-0" style="color: var(--theme-primary);"><?php echo $featureCount; ?></h3></div>
                    <div class="mb-3"><label class="text-muted small text-uppercase fw-semibold">Scheduled in Roadmap</label><h3 class="mb-0" style="color: var(--theme-primary);"><?php echo (int) ($methodologyCompletion['scheduled_feature_count'] ?? 0); ?>/<?php echo (int) ($methodologyCompletion['feature_count'] ?? 0); ?></h3></div>
                    <div class="mb-3"><label class="text-muted small text-uppercase fw-semibold">Total Technologies Recommended</label><h3 class="mb-0" style="color: var(--theme-primary);"><?php echo $techRecommendationCount; ?></h3></div>
                    <div class="mb-3"><label class="text-muted small text-uppercase fw-semibold">Total Developers Needed</label><h3 class="mb-0" style="color: var(--theme-primary);"><?php $totalDevs = 0; $allocations->data_seek(0); while($alloc = $allocations->fetch_assoc()) { $totalDevs += $alloc['required_count']; } echo $totalDevs; ?></h3></div>
                    <div class="mb-3"><label class="text-muted small text-uppercase fw-semibold">Documents Uploaded</label><h3 class="mb-0" style="color: var(--theme-primary);"><?php echo $documentCount; ?></h3></div>
                    <hr>
                    <?php if ($isSubmittedToSul): ?>
                        <div class="submission-state" role="status">
                            <span class="submission-state__icon"><i class="fas fa-check" aria-hidden="true"></i></span>
                            <span class="submission-state__label">Submitted to SUL</span>
                            <h6><?php echo $isAwaitingSulReview ? 'Awaiting SUL Review' : 'Handoff Complete'; ?></h6>
                            <p><?php echo $isAwaitingSulReview
                                ? 'This summary is now read-only. The Service Unit Leader has been notified and can review the plan for the next assignment step.'
                                : 'This submitted summary is read-only. The Service Unit Leader has already moved the project into the next workflow step.'; ?></p>
                            <a href="projects.php" class="submission-state__action"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Projects</a>
                        </div>
                    <?php else: ?>
                        <?php if (!$summaryReadyForSubmit): ?>
                        <div class="alert alert-warning small">
                            Complete Features, Technology, Developer Allocation, Methodology Roadmap, Architecture Plan, and Workflow Diagram before final submission.
                            <?php if (!(bool) ($methodologyCompletion['is_complete'] ?? false)): ?>
                                <div class="mt-2">
                                    <a href="../roadmap/methodology.php?project_id=<?php echo $projectId; ?>" class="alert-link">
                                        Schedule <?php echo (int) ($methodologyCompletion['unscheduled_count'] ?? 0); ?> unscheduled feature task<?php echo (int) ($methodologyCompletion['unscheduled_count'] ?? 0) === 1 ? '' : 's'; ?>.
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="alert-info-modern"><i class="fas fa-info-circle"></i><span>Once submitted, this plan will be sent to the Service Unit Leader for review and task allocation.</span></div>
                        <form method="POST" id="summaryForm" data-generic-draft-disabled="true" data-app-loading data-loading-title="Sending to SUL" data-loading-text="Please wait while the architecture plan is submitted and the Service Unit Leader is notified.">
                            <div class="submission-confirmation"><input class="form-check-input" type="checkbox" id="confirmCheck" name="confirmCheck" value="1" required><label class="form-check-label" for="confirmCheck">I confirm that all information is accurate and complete</label></div>
                            <button type="submit" class="btn-submit" id="submitBtn" <?php echo $summaryReadyForSubmit ? 'disabled' : 'disabled title="Complete all required architect steps first"'; ?>><i class="fas fa-paper-plane"></i> Submit to SUL</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const confirmCheck = document.getElementById('confirmCheck');
    const submitBtn = document.getElementById('submitBtn');
    const summaryForm = document.getElementById('summaryForm');
    const summaryReady = <?php echo json_encode($summaryReadyForSubmit); ?>;

    if (!confirmCheck || !submitBtn || !summaryForm) {
        return;
    }

    function syncSubmitButton() {
        submitBtn.disabled = !summaryReady || !confirmCheck.checked;
    }

    function handleFormSubmit(e) {
        // Only prevent submission if checkbox is not checked
        if (!summaryReady) {
            e.preventDefault();
            window.appToast?.('Summary not ready', 'Complete all required architect steps, including the methodology roadmap, before submitting.', 'warning');
            return false;
        }

        if (!confirmCheck.checked) {
            e.preventDefault();
            window.appToast?.('Confirmation required', 'Check the confirmation box before submitting.', 'warning');
            return false;
        }
    }

    confirmCheck.addEventListener('change', syncSubmitButton);
    summaryForm.addEventListener('submit', handleFormSubmit);
    syncSubmitButton();
});
</script>

<?php include '../../includes/footer.php'; ?>
