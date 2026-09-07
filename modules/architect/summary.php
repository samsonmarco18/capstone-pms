<?php
require_once '../../includes/auth.php';
require_once '../../includes/file-storage.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/workflow.php';
require_once '../../includes/methodology-roadmap.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/project-template.php';
require_once '../../includes/project-compilation.php';
require_once '../../includes/version-control-actions.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
projectWorkflowEnsureSchema($conn);
projectTemplateEnsureSchema($conn);
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

projectWorkflowNormalizePreSubmitFeatureTasks($conn, $projectId);

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
$totalDevelopersNeededRow = $conn->query("SELECT COALESCE(SUM(required_count), 0) AS total FROM developer_allocations WHERE project_id = $projectId")->fetch_assoc();
$totalDevelopersNeeded = (int) ($totalDevelopersNeededRow['total'] ?? 0);
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
$summaryStatusLabel = $isSubmittedToSul
    ? ($isAwaitingSulReview ? 'Awaiting SUL Review' : 'Submitted to SUL')
    : ($summaryReadyForSubmit ? 'Ready for SUL Review' : 'Requirements Incomplete');
$summaryTimelineDays = null;
if (!empty($project['timeline_start']) && !empty($project['timeline_end'])) {
    $summaryTimelineStart = strtotime((string) $project['timeline_start']);
    $summaryTimelineEnd = strtotime((string) $project['timeline_end']);
    if ($summaryTimelineStart !== false && $summaryTimelineEnd !== false && $summaryTimelineEnd >= $summaryTimelineStart) {
        $summaryTimelineDays = (int) floor(($summaryTimelineEnd - $summaryTimelineStart) / 86400) + 1;
    }
}

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

    try {
        $planningRepository = vcProvisionProjectPlanningRepository(
            $conn,
            $user,
            $projectId,
            (string) $project['name']
        );
    } catch (Throwable $repositoryError) {
        error_log('Architect repository provisioning failed: ' . $repositoryError->getMessage());
        $_SESSION['error'] = 'The plan is ready, but its repository and feature branches could not be created: '
            . $repositoryError->getMessage();
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

    $submittedCompilationNo = projectCompilationRecord($conn, $projectId, (int) $user['id'], 'architect_submission');

    projectWorkflowNotifyDepartmentSuls(
        $conn,
        'Expert Department',
        'Project Ready for Expert Estimation: ' . (string) $project['name'],
        'Architect submitted the completed plan. Assign the project feature tasks to an Expert Developer for estimation.',
        BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId,
        $projectId
    );
    
    logActivity($user['id'], 'submit_architecture', 'projects', $projectId);
    $_SESSION['success'] = 'Architecture compilation #' . $submittedCompilationNo
        . ' submitted successfully. The managed repository is ready with main and '
        . (int) ($planningRepository['feature_count'] ?? 0)
        . ' feature branch'
        . ((int) ($planningRepository['feature_count'] ?? 0) === 1 ? '' : 'es')
        . '. The Expert Department SUL has been notified for estimation assignment.';
    
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

/* Summary-only project insight notification */
.summary-insight-notification {
    --summary-insight-border: #d8e7ef;
    --summary-insight-track: #dce6ec;
    --summary-insight-center: #ffffff;
    background: #ffffff;
    border: 1px solid var(--summary-insight-border);
    border-radius: 18px;
    box-shadow: 0 12px 28px rgba(31, 99, 146, 0.08);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.summary-insight-notification[hidden] {
    display: none !important;
}

.summary-insight-notification__header {
    align-items: center;
    border-bottom: 1px solid var(--summary-insight-border);
    display: flex;
    gap: 0.75rem;
    justify-content: space-between;
    padding: 0.82rem 1rem;
}

.summary-insight-notification__heading {
    align-items: center;
    display: flex;
    gap: 0.65rem;
    min-width: 0;
}

.summary-insight-notification__heading-icon {
    align-items: center;
    background: #e9f5fb;
    border-radius: 9px;
    color: #0d6786;
    display: inline-flex;
    flex: 0 0 auto;
    height: 34px;
    justify-content: center;
    width: 34px;
}

.summary-insight-notification__heading h3 {
    color: #17324d;
    font-size: 0.92rem;
    font-weight: 650;
    margin: 0;
}

.summary-insight-notification__heading p {
    color: #6c7f90;
    font-size: 0.74rem;
    line-height: 1.4;
    margin: 0.12rem 0 0;
}

.summary-insight-notification__close {
    align-items: center;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 9px;
    color: #75899a;
    display: inline-flex;
    flex: 0 0 auto;
    height: 34px;
    justify-content: center;
    padding: 0;
    transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    width: 34px;
}

.summary-insight-notification__close:hover {
    background: #f2f7fa;
    border-color: #d8e7ef;
    color: #17324d;
}

.summary-insight-notification__body {
    background: linear-gradient(180deg, #f8fbfd 0%, #ffffff 100%);
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.1rem 1.25rem;
}

.summary-insight-project-head {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
}

.summary-insight-project-head h4 {
    color: #17324d;
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 0.28rem;
}

.summary-insight-project-meta {
    color: #687b8d;
    display: flex;
    flex-wrap: wrap;
    font-size: 0.74rem;
    line-height: 1.5;
}

.summary-insight-project-meta > span + span::before {
    color: #9aa9b6;
    content: '|';
    margin: 0 0.38rem;
}

.summary-insight-project-meta strong {
    font-weight: 650;
}

.summary-insight-signal-layout {
    align-items: center;
    display: grid;
    gap: 1.5rem;
    grid-template-columns: minmax(250px, 330px) minmax(0, 1fr);
}

.summary-insight-metrics {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    width: min(100%, 20rem);
}

.summary-insight-metric {
    --summary-insight-color: #0d6786;
    align-content: center;
    display: grid;
    gap: 0.45rem;
    justify-items: center;
    min-width: 0;
}

.summary-insight-metric.is-delay {
    --summary-insight-color: #dc2626;
}

.summary-insight-metric.is-success {
    --summary-insight-color: #15803d;
}

.summary-insight-gauge {
    background: conic-gradient(
        from 220deg,
        var(--summary-insight-color) 0 var(--summary-insight-value, 0%),
        var(--summary-insight-track) var(--summary-insight-value, 0%) 100%
    );
    border-radius: 50%;
    display: grid;
    height: 6.25rem;
    place-items: center;
    position: relative;
    width: 6.25rem;
}

.summary-insight-gauge::before {
    background: var(--summary-insight-center);
    border-radius: inherit;
    content: '';
    inset: 0.55rem;
    position: absolute;
}

.summary-insight-gauge strong {
    color: var(--summary-insight-color);
    font-size: 1.3rem;
    line-height: 1;
    position: relative;
    z-index: 1;
}

.summary-insight-gauge__icon {
    align-items: center;
    background: var(--summary-insight-color);
    border: 3px solid var(--summary-insight-center);
    border-radius: 50%;
    box-sizing: content-box;
    color: #ffffff;
    display: inline-flex;
    font-size: 0.65rem;
    height: 1.55rem;
    justify-content: center;
    position: absolute;
    right: 0.04rem;
    top: -0.08rem;
    width: 1.55rem;
    z-index: 2;
}

.summary-insight-metric__label {
    color: #4d5d6b;
    font-size: 0.67rem;
    font-weight: 750;
    letter-spacing: 0.05em;
    text-align: center;
    text-transform: uppercase;
}

.summary-insight-copy {
    border-left: 1px solid var(--summary-insight-border);
    min-width: 0;
    padding-left: 1.5rem;
}

.summary-insight-assessment {
    align-items: center;
    display: flex;
    gap: 0.65rem;
}

.summary-insight-assessment__icon {
    align-items: center;
    background: #e9f5fb;
    border-radius: 50%;
    color: #0d6786;
    display: inline-flex;
    flex: 0 0 auto;
    height: 34px;
    justify-content: center;
    width: 34px;
}

.summary-insight-assessment__copy {
    display: grid;
    gap: 0.05rem;
}

.summary-insight-assessment__copy span {
    color: #6c7f90;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.summary-insight-assessment__copy strong {
    color: #17324d;
    font-size: 0.88rem;
    font-weight: 750;
}

.summary-insight-copy__top {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    margin-bottom: 0.65rem;
}

.summary-insight-copy__top strong {
    color: #17324d;
    font-size: 0.9rem;
    font-weight: 650;
}

.summary-insight-risk {
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.67rem;
    font-weight: 700;
    padding: 0.28rem 0.55rem;
}

.summary-insight-risk.is-low { background: #dff7ec; color: #087653; }
.summary-insight-risk.is-medium { background: #fff4d6; color: #966300; }
.summary-insight-risk.is-high { background: #ffe5e7; color: #b4232c; }

.summary-insight-copy p {
    color: #536474;
    font-size: 0.82rem;
    line-height: 1.55;
    margin: 0;
}

.summary-insight-assessment + p {
    margin-top: 0.65rem;
}

.summary-insight-tips {
    color: #536474;
    display: grid;
    font-size: 0.75rem;
    gap: 0.28rem;
    line-height: 1.45;
    margin: 0.65rem 0 0;
    padding-left: 1rem;
}

.summary-insight-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 0.65rem;
}

.summary-insight-tag {
    background: #fff4e5;
    border: 1px solid #f6d29d;
    border-radius: 999px;
    color: #8a5310;
    font-size: 0.67rem;
    font-weight: 650;
    padding: 0.28rem 0.52rem;
}

.summary-insight-review-guidance {
    align-items: flex-start;
    border-left: 3px solid #83b7ce;
    color: #5b6d7d;
    display: flex;
    font-size: 0.74rem;
    gap: 0.5rem;
    line-height: 1.5;
    margin-top: 0.7rem;
    padding: 0.25rem 0.65rem;
}

.summary-insight-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    justify-content: flex-end;
    margin-top: 0;
}

.summary-insight-action {
    align-items: center;
    background: #0d6786;
    border: 1px solid #0d6786;
    border-radius: 9px;
    color: #ffffff;
    display: inline-flex;
    font-size: 0.74rem;
    font-weight: 650;
    gap: 0.4rem;
    min-height: 36px;
    padding: 0.48rem 0.72rem;
    text-decoration: none;
}

.summary-insight-action:hover {
    background: #09536d;
    color: #ffffff;
}

.summary-insight-action:not(.is-secondary),
.summary-insight-action:not(.is-secondary):hover {
    color: #ffffff !important;
}

.summary-insight-action:not(.is-secondary) i {
    color: inherit !important;
}

.summary-insight-action.is-secondary {
    background: #ffffff;
    border-color: #cfe1eb;
    color: #0d6786;
}

.summary-insight-action.is-secondary:hover {
    background: #edf7fb;
}

@media (max-width: 767.98px) {
    .summary-insight-signal-layout {
        grid-template-columns: 1fr;
    }

    .summary-insight-copy {
        border-left: 0;
        border-top: 1px solid var(--summary-insight-border);
        padding-left: 0;
        padding-top: 1rem;
    }

    .summary-insight-project-head {
        align-items: flex-start;
        flex-direction: column;
    }
}

/* Final architecture review sheet */
.architecture-summary-sheet {
    --summary-sheet-border: #dce7ef;
    --summary-sheet-label: #64788a;
    --summary-sheet-value: #17324d;
    background: #ffffff;
    border: 1px solid var(--summary-sheet-border);
    border-radius: 18px;
    box-shadow: 0 14px 34px rgba(31, 99, 146, 0.08);
    overflow: hidden;
}

.architecture-summary-section + .architecture-summary-section {
    border-top: 10px solid #eef5f9;
}

.architecture-summary-section__header {
    align-items: center;
    background: linear-gradient(135deg, #0d6786 0%, #0a5570 100%);
    color: #ffffff !important;
    display: flex;
    gap: 0.65rem;
    min-height: 46px;
    padding: 0.7rem 1rem;
}

.architecture-summary-section__icon {
    align-items: center;
    background: rgba(255, 255, 255, 0.14);
    border-radius: 8px;
    display: inline-flex;
    flex: 0 0 auto;
    height: 28px;
    justify-content: center;
    width: 28px;
}

.architecture-summary-section__icon i {
    color: #ffffff !important;
    opacity: 1;
}

.architecture-summary-section__header h2 {
    color: #ffffff !important;
    font-size: 0.8rem;
    font-weight: 750;
    letter-spacing: 0.045em;
    margin: 0;
    text-transform: uppercase;
}

.summary-review-rows--overview {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.summary-review-rows--overview .summary-review-row {
    grid-template-columns: minmax(150px, 225px) minmax(0, 1fr);
}

.summary-review-rows--overview .summary-review-row:nth-child(odd) {
    border-right: 1px solid var(--summary-sheet-border);
}

.summary-review-rows--overview .summary-review-row:nth-last-child(-n + 2) {
    border-bottom: 0;
}

.summary-review-rows {
    background: #ffffff;
}

.summary-review-row {
    align-items: start;
    border-bottom: 1px solid var(--summary-sheet-border);
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(190px, 280px) minmax(0, 1fr);
    padding: 0.72rem 1rem;
}

.summary-review-row:last-child {
    border-bottom: 0;
}

.summary-review-label {
    color: var(--summary-sheet-label);
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.045em;
    line-height: 1.45;
    text-transform: uppercase;
}

.summary-review-value {
    color: var(--summary-sheet-value);
    font-size: 0.84rem;
    font-weight: 550;
    line-height: 1.5;
    min-width: 0;
    overflow-wrap: anywhere;
}

.summary-review-status {
    background: #e9f5fb;
    border-radius: 999px;
    color: #0d6786;
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.28rem 0.58rem;
}

.summary-review-status.is-incomplete {
    background: #fff4d6;
    color: #966300;
}

.summary-review-table-wrap {
    overflow-x: auto;
}

.summary-review-table {
    border-collapse: collapse;
    min-width: 680px;
    width: 100%;
}

.summary-review-table--technology {
    table-layout: fixed;
}

.summary-review-table--technology col:nth-child(1) { width: 32%; }
.summary-review-table--technology col:nth-child(2) { width: 23%; }
.summary-review-table--technology col:nth-child(3) { width: 23%; }
.summary-review-table--technology col:nth-child(4) { width: 22%; }

.summary-review-table--technology :is(th, td):last-child {
    text-align: left;
}

.summary-review-table th {
    background: #f7fafc;
    border-bottom: 1px solid var(--summary-sheet-border);
    color: var(--summary-sheet-label);
    font-size: 0.67rem;
    font-weight: 700;
    letter-spacing: 0.045em;
    padding: 0.65rem 1rem;
    text-align: left;
    text-transform: uppercase;
}

.summary-review-table td {
    background: #ffffff;
    border-bottom: 1px solid var(--summary-sheet-border);
    color: var(--summary-sheet-value);
    font-size: 0.82rem;
    padding: 0.72rem 1rem;
    vertical-align: middle;
}

.summary-review-table tbody tr:last-child td {
    border-bottom: 0;
}

.summary-review-table .summary-review-total td {
    background: #f7fafc;
    font-weight: 700;
}

.summary-document-list {
    background: #ffffff;
}

.summary-document-row {
    align-items: center;
    border-bottom: 1px solid var(--summary-sheet-border);
    border-left: 3px solid transparent;
    display: grid;
    gap: 0.8rem;
    grid-template-columns: 38px minmax(0, 1fr) auto;
    padding: 0.72rem 1rem 0.72rem 0.85rem;
    transition: background-color 0.18s ease, border-color 0.18s ease;
}

.summary-document-row:last-child {
    border-bottom: 0;
}

.summary-document-row:hover {
    background: #f2f8fc;
    border-left-color: #2b8ab8;
}

.summary-document-row__icon {
    align-items: center;
    background: #e9f5fb;
    border-radius: 9px;
    color: #0d6786;
    display: inline-flex;
    height: 36px;
    justify-content: center;
    width: 36px;
}

.summary-document-row__copy {
    min-width: 0;
}

.summary-document-row__copy strong {
    color: var(--summary-sheet-value);
    display: block;
    font-size: 0.84rem;
    font-weight: 650;
}

.summary-document-row__copy span,
.summary-document-row__copy small {
    color: var(--summary-sheet-label);
    display: block;
    font-size: 0.74rem;
    line-height: 1.45;
    margin-top: 0.14rem;
}

.summary-document-row__action {
    align-items: center;
    background: #e8f4fa;
    border: 1px solid #cfe4ef;
    border-radius: 9px;
    color: #0d6786;
    display: inline-flex;
    height: 36px;
    justify-content: center;
    text-decoration: none;
    transition: background-color 0.18s ease, color 0.18s ease;
    width: 36px;
}

.summary-document-row__action:hover {
    background: #0d6786;
    color: #ffffff;
}

.summary-document-empty {
    color: var(--summary-sheet-label);
    font-size: 0.82rem;
    margin: 0;
    padding: 1rem;
    text-align: center;
}

.summary-submission-panel {
    border-top: 1px solid var(--summary-sheet-border);
    padding: 1rem;
}

.summary-submission-layout {
    align-items: start;
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(0, 1fr) minmax(500px, 0.9fr);
}

.summary-submission-notices {
    display: grid;
    gap: 0.55rem;
    min-width: 0;
}

.summary-submission-notice {
    align-items: flex-start;
    background: transparent;
    border: 0;
    border-left: 3px solid #69a9c8;
    border-radius: 0;
    color: #315d78;
    display: flex;
    font-size: 0.8rem;
    gap: 0.6rem;
    line-height: 1.5;
    margin: 0;
    padding: 0.35rem 0.7rem;
}

.summary-submission-notice.is-warning {
    background: transparent;
    border-left-color: #d79a20;
    color: #76500c;
}

.summary-submission-notice > i {
    color: #1f6392 !important;
    flex: 0 0 auto;
    margin-top: 0.2rem !important;
}

.summary-submission-notice.is-warning > i {
    color: #b7790c !important;
}

.summary-submission-notice a {
    color: inherit;
    font-weight: 700;
}

.architecture-summary-sheet .submission-confirmation {
    align-items: center;
    background: transparent;
    border: 1px solid var(--summary-sheet-border);
    border-radius: 10px;
    margin: 0;
    min-height: 44px;
    padding: 0.55rem 0.7rem;
}

.architecture-summary-sheet .btn-submit {
    align-items: center;
    background: #0d6786;
    border-radius: 11px;
    display: inline-flex;
    gap: 0.45rem;
    justify-content: center;
    min-height: 44px;
    min-width: 154px;
    padding: 0.65rem 1rem;
    white-space: nowrap;
    width: auto;
}

.architecture-summary-sheet .btn-submit:hover:not(:disabled) {
    background: #09536d;
    box-shadow: 0 6px 14px rgba(13, 103, 134, 0.22);
}

.architecture-summary-sheet .btn-submit:disabled {
    background: #dfe8ed;
    color: #718497;
    opacity: 1;
}

.architecture-summary-sheet .btn-submit i {
    color: #ffffff !important;
    opacity: 1;
}

.architecture-summary-sheet .btn-submit:disabled i {
    color: #718497 !important;
}

.summary-submission-form {
    align-items: stretch;
    display: grid;
    gap: 0.7rem;
    grid-template-columns: minmax(0, 1fr);
}

.summary-submission-form .btn-submit {
    justify-self: end;
}

.architecture-summary-sheet .submission-state {
    border-radius: 12px;
    box-shadow: none;
    margin: 0;
}

@media (max-width: 767.98px) {
    .summary-review-row {
        gap: 0.3rem;
        grid-template-columns: 1fr;
    }

    .summary-document-row {
        grid-template-columns: 36px minmax(0, 1fr) auto;
    }

    .summary-review-rows--overview {
        grid-template-columns: 1fr;
    }

    .summary-review-rows--overview .summary-review-row {
        border-bottom: 1px solid var(--summary-sheet-border);
        border-right: 0;
        grid-template-columns: 1fr;
    }

    .summary-review-rows--overview .summary-review-row:last-child {
        border-bottom: 0;
    }

    .summary-submission-form {
        grid-template-columns: 1fr;
    }

    .architecture-summary-sheet .btn-submit {
        justify-self: stretch;
        width: 100%;
    }
}

@media (max-width: 1199.98px) {
    .summary-submission-layout {
        align-items: stretch;
        grid-template-columns: 1fr;
    }
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architecture-summary-sheet {
    --summary-sheet-border: rgba(138, 210, 255, 0.15);
    --summary-sheet-label: #8eabc1;
    --summary-sheet-value: #d7e4f2;
    background: #071321;
    border-color: var(--summary-sheet-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architecture-summary-section + .architecture-summary-section {
    border-top-color: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(.summary-review-rows, .summary-review-table td, .summary-document-list) {
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(.summary-review-table th, .summary-review-table .summary-review-total td, .architecture-summary-sheet .submission-confirmation) {
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-document-row:hover {
    background: #0b1d2e;
    border-left-color: #55b5e8;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-document-row__icon {
    background: rgba(31, 99, 146, 0.24);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-submission-notice {
    background: transparent;
    border-left-color: #69a9c8;
    color: #b9d2e5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-submission-notice.is-warning {
    background: transparent;
    border-left-color: #d79a20;
    color: #f5d58a;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-notification {
    --summary-insight-border: rgba(138, 210, 255, 0.15);
    --summary-insight-track: #183246;
    --summary-insight-center: #071321;
    background: #071321;
    border-color: var(--summary-insight-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-notification__body {
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-notification__heading h3,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-copy__top strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-project-head h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-assessment__copy strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-notification__heading p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-copy p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-metric__label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-project-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-assessment__copy span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-tips,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-review-guidance {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-notification__heading-icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-action.is-secondary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-assessment__icon {
    background: rgba(31, 99, 146, 0.24);
    border-color: rgba(138, 210, 255, 0.18);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-notification__close:hover {
    background: #0d2236;
    border-color: rgba(138, 210, 255, 0.18);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-insight-tag {
    background: rgba(146, 77, 0, 0.18);
    border-color: rgba(253, 186, 116, 0.24);
    color: #fdba74;
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
    $summaryProjectInsights = delayInsightsLoadInsights($conn, $user, ['project_id' => $projectId]);
    $summaryProjectInsight = $summaryProjectInsights[0] ?? null;
    if (is_array($summaryProjectInsight)):
        $summaryInsightBefore = (array) ($summaryProjectInsight['before'] ?? []);
        $summaryInsightAfter = (array) ($summaryProjectInsight['after'] ?? []);
        $summaryInsightProject = (array) ($summaryProjectInsight['project'] ?? []);
        $summaryInsightDelay = max(0, min(100, (int) ($summaryInsightBefore['delay_probability'] ?? 0)));
        $summaryInsightSuccess = max(0, min(100, (int) ($summaryInsightBefore['success_probability'] ?? 0)));
        $summaryInsightRiskLevel = (string) ($summaryInsightBefore['risk_level'] ?? 'Low');
        $summaryInsightRiskClass = $summaryInsightRiskLevel === 'High' ? 'is-high' : ($summaryInsightRiskLevel === 'Medium' ? 'is-medium' : 'is-low');
        $summaryInsightAssessmentTitle = $summaryInsightRiskLevel === 'High'
            ? 'High delivery risk detected'
            : ($summaryInsightRiskLevel === 'Medium' ? 'Moderate delivery risk detected' : 'Healthy delivery outlook');
        $summaryInsightProblemAction = delayInsightsProblemAction($summaryProjectInsight, $user);
        $summaryInsightUrl = BASE_URL . 'modules/insights/decision-center.php?project_id=' . $projectId;
    ?>
        <section class="summary-insight-notification" id="summaryProjectInsightNotification" aria-labelledby="summaryProjectInsightTitle">
            <header class="summary-insight-notification__header">
                <div class="summary-insight-notification__heading">
                    <span class="summary-insight-notification__heading-icon"><i class="fas fa-lightbulb" aria-hidden="true"></i></span>
                    <span>
                        <h3 id="summaryProjectInsightTitle">Project Insight Notifications</h3>
                        <p>Before submission, review the predicted delay percentage, success rate, recommended adjustments, and the delay causes or owners that already need attention for this project.</p>
                    </span>
                </div>
                <button type="button" class="summary-insight-notification__close" data-summary-insight-dismiss aria-label="Dismiss project insight notification" title="Dismiss notification">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </header>
            <div class="summary-insight-notification__body">
                <div class="summary-insight-project-head">
                    <div>
                        <h4><?php echo htmlspecialchars((string) ($summaryInsightProject['name'] ?? $project['name'])); ?></h4>
                        <div class="summary-insight-project-meta">
                            <span><strong>Client:</strong> <?php echo htmlspecialchars((string) ($summaryInsightProject['client_name'] ?? $project['client_name'])); ?></span>
                            <span><strong>Status:</strong> <?php echo htmlspecialchars((string) ($summaryInsightProject['status_label'] ?? $summaryStatusLabel)); ?></span>
                            <span><strong>Timeline:</strong> <?php echo htmlspecialchars((string) ($summaryInsightProject['timeline_label'] ?? 'No timeline')); ?></span>
                        </div>
                    </div>
                    <span class="summary-insight-risk <?php echo $summaryInsightRiskClass; ?>"><i class="fas fa-signal me-1" aria-hidden="true"></i><?php echo htmlspecialchars($summaryInsightRiskLevel); ?> Risk</span>
                </div>
                <div class="summary-insight-signal-layout">
                    <div class="summary-insight-metrics" aria-label="Project delivery probabilities">
                        <div class="summary-insight-metric is-delay">
                            <div class="summary-insight-gauge" role="img" aria-label="Delay chance: <?php echo $summaryInsightDelay; ?> percent" style="--summary-insight-value: <?php echo $summaryInsightDelay; ?>%;">
                                <strong><?php echo $summaryInsightDelay; ?>%</strong>
                                <span class="summary-insight-gauge__icon"><i class="fas fa-history" aria-hidden="true"></i></span>
                            </div>
                            <span class="summary-insight-metric__label">Delay Chance</span>
                        </div>
                        <div class="summary-insight-metric is-success">
                            <div class="summary-insight-gauge" role="img" aria-label="Success chance: <?php echo $summaryInsightSuccess; ?> percent" style="--summary-insight-value: <?php echo $summaryInsightSuccess; ?>%;">
                                <strong><?php echo $summaryInsightSuccess; ?>%</strong>
                                <span class="summary-insight-gauge__icon"><i class="fas fa-check" aria-hidden="true"></i></span>
                            </div>
                            <span class="summary-insight-metric__label">Success Chance</span>
                        </div>
                    </div>
                    <div class="summary-insight-copy">
                        <div class="summary-insight-assessment">
                            <span class="summary-insight-assessment__icon"><i class="fas fa-chart-line" aria-hidden="true"></i></span>
                            <span class="summary-insight-assessment__copy">
                                <span>Risk Assessment</span>
                                <strong><?php echo htmlspecialchars($summaryInsightAssessmentTitle); ?></strong>
                            </span>
                        </div>
                        <p><?php echo htmlspecialchars((string) ($summaryInsightBefore['summary'] ?? 'Review the current planning signals before submission.')); ?></p>
                        <?php if (!empty($summaryInsightBefore['tips'])): ?>
                            <ul class="summary-insight-tips">
                                <?php foreach (array_slice((array) $summaryInsightBefore['tips'], 0, 3) as $summaryInsightTip): ?>
                                    <li><?php echo htmlspecialchars((string) $summaryInsightTip); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($summaryInsightAfter['reasons'])): ?>
                            <div class="summary-insight-tags">
                                <?php foreach (array_slice((array) $summaryInsightAfter['reasons'], 0, 3) as $summaryInsightReason): ?>
                                    <span class="summary-insight-tag"><?php echo htmlspecialchars((string) $summaryInsightReason); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($summaryInsightAfter['summary']) || !empty($summaryInsightAfter['note'])): ?>
                            <div class="summary-insight-review-guidance">
                                <i class="fas fa-circle-info mt-1" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars(trim((string) ($summaryInsightAfter['summary'] ?? '') . ' ' . (string) ($summaryInsightAfter['note'] ?? ''))); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="summary-insight-actions">
                    <?php if (is_array($summaryInsightProblemAction)): ?>
                        <a class="summary-insight-action is-secondary" href="<?php echo htmlspecialchars((string) $summaryInsightProblemAction['url']); ?>"><i class="fas fa-location-arrow" aria-hidden="true"></i> <?php echo htmlspecialchars((string) $summaryInsightProblemAction['label']); ?></a>
                    <?php endif; ?>
                    <a class="summary-insight-action" href="<?php echo htmlspecialchars($summaryInsightUrl); ?>"><i class="fas fa-arrows-rotate" aria-hidden="true"></i> Live Insights</a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <article class="architecture-summary-sheet" aria-label="Architecture plan final review">
        <section class="architecture-summary-section architecture-summary-section--overview">
            <header class="architecture-summary-section__header">
                <span class="architecture-summary-section__icon"><i class="fas fa-circle-info" aria-hidden="true"></i></span>
                <h2>Project Overview</h2>
            </header>
            <div class="summary-review-rows summary-review-rows--overview">
                <div class="summary-review-row"><span class="summary-review-label">Project Name</span><span class="summary-review-value"><?php echo htmlspecialchars((string) $project['name']); ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Timeline</span><span class="summary-review-value"><?php echo !empty($project['timeline_start']) ? date('M d, Y', strtotime((string) $project['timeline_start'])) : 'Not set'; ?> &ndash; <?php echo !empty($project['timeline_end']) ? date('M d, Y', strtotime((string) $project['timeline_end'])) : 'Not set'; ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Planning Basis</span><span class="summary-review-value"><?php echo $featureCount; ?> requested feature<?php echo $featureCount === 1 ? '' : 's'; ?><?php if ($summaryTimelineDays !== null): ?> | <?php echo $summaryTimelineDays; ?> day<?php echo $summaryTimelineDays === 1 ? '' : 's'; ?><?php endif; ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Generated Complexity</span><span class="summary-review-value"><?php echo htmlspecialchars(ucwords(strtolower((string) ($project['complexity_level'] ?? 'Medium')))); ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Priority</span><span class="summary-review-value"><?php echo htmlspecialchars(ucwords(strtolower((string) ($project['priority'] ?? 'Medium')))); ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Budget</span><span class="summary-review-value"><?php echo $hasSalesBudget && !empty($project['sales_budget']) ? '&#8369;' . number_format((float) $project['sales_budget'], 2) : 'Not provided'; ?></span></div>
            </div>
        </section>

        <section class="architecture-summary-section">
            <header class="architecture-summary-section__header">
                <span class="architecture-summary-section__icon"><i class="fas fa-microchip" aria-hidden="true"></i></span>
                <h2>Technology Stack</h2>
            </header>
            <div class="summary-review-table-wrap">
                <table class="summary-review-table summary-review-table--technology">
                    <colgroup><col><col><col><col></colgroup>
                    <thead><tr><th>Technology</th><th>Category</th><th>Service Type</th><th>Monthly Cost</th></tr></thead>
                    <tbody>
                        <?php $totalCost = 0.0; while ($tech = $techRecommendations->fetch_assoc()): $totalCost += (float) $tech['estimated_cost']; ?>
                            <tr><td><?php echo htmlspecialchars((string) $tech['tech_name']); ?></td><td><?php echo htmlspecialchars((string) $tech['category']); ?></td><td><?php echo htmlspecialchars((string) $tech['service_type']); ?></td><td>&#8369;<?php echo number_format((float) $tech['estimated_cost'], 2); ?></td></tr>
                        <?php endwhile; ?>
                        <tr class="summary-review-total"><td colspan="3">Total Monthly Cost</td><td>&#8369;<?php echo number_format($totalCost, 2); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="architecture-summary-section">
            <header class="architecture-summary-section__header">
                <span class="architecture-summary-section__icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                <h2>Developer Allocation</h2>
            </header>
            <div class="summary-review-table-wrap">
                <table class="summary-review-table">
                    <thead><tr><th>Role</th><th>Required Count</th><th>Complexity Level</th></tr></thead>
                    <tbody>
                        <?php while ($alloc = $allocations->fetch_assoc()): ?>
                            <?php $complexityLevel = strtolower(trim((string) ($alloc['complexity_level'] ?? 'medium'))); ?>
                            <tr><td><?php echo htmlspecialchars((string) $alloc['role']); ?></td><td><?php echo (int) $alloc['required_count']; ?></td><td><span class="badge-complexity <?php echo htmlspecialchars($complexityLevel); ?>"><?php echo htmlspecialchars(ucfirst($complexityLevel)); ?></span></td></tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="architecture-summary-section">
            <header class="architecture-summary-section__header">
                <span class="architecture-summary-section__icon"><i class="fas fa-file-lines" aria-hidden="true"></i></span>
                <h2>Architecture Documents</h2>
            </header>
            <?php if ($documents->num_rows > 0): ?>
                <div class="summary-document-list">
                    <?php while ($doc = $documents->fetch_assoc()): ?>
                        <?php $documentLabel = ucwords(str_replace('_', ' ', (string) ($doc['document_type'] ?? 'document'))); ?>
                        <div class="summary-document-row">
                            <span class="summary-document-row__icon"><i class="fas fa-file-code" aria-hidden="true"></i></span>
                            <span class="summary-document-row__copy">
                                <strong><?php echo htmlspecialchars($documentLabel); ?></strong>
                                <span><?php echo htmlspecialchars(trim((string) ($doc['description'] ?? '')) ?: 'No description provided.'); ?></span>
                                <small><i class="far fa-clock me-1" aria-hidden="true"></i>Uploaded <?php echo !empty($doc['created_at']) ? date('M d, Y \a\t g:i A', strtotime((string) $doc['created_at'])) : 'with no recorded date'; ?></small>
                            </span>
                            <a href="<?php echo htmlspecialchars(storagePublicUrl((string) $doc['file_path'])); ?>" class="summary-document-row__action" target="_blank" rel="noopener" aria-label="View <?php echo htmlspecialchars($documentLabel); ?>" title="View document"><i class="fas fa-eye" aria-hidden="true"></i></a>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <p class="summary-document-empty">No documents uploaded yet.</p>
            <?php endif; ?>
        </section>

        <section class="architecture-summary-section">
            <header class="architecture-summary-section__header">
                <span class="architecture-summary-section__icon"><i class="fas fa-paper-plane" aria-hidden="true"></i></span>
                <h2>Submission Summary</h2>
            </header>
            <div class="summary-review-rows">
                <div class="summary-review-row"><span class="summary-review-label">Total Features</span><span class="summary-review-value"><?php echo $featureCount; ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Scheduled in Roadmap</span><span class="summary-review-value"><?php echo (int) ($methodologyCompletion['scheduled_feature_count'] ?? 0); ?> of <?php echo (int) ($methodologyCompletion['feature_count'] ?? 0); ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Technologies Recommended</span><span class="summary-review-value"><?php echo $techRecommendationCount; ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Developers Needed</span><span class="summary-review-value"><?php echo $totalDevelopersNeeded; ?></span></div>
                <div class="summary-review-row"><span class="summary-review-label">Documents Uploaded</span><span class="summary-review-value"><?php echo $documentCount; ?></span></div>
            </div>
            <div class="summary-submission-panel">
                <?php if ($isSubmittedToSul): ?>
                    <div class="submission-state" role="status">
                        <span class="submission-state__icon"><i class="fas fa-check" aria-hidden="true"></i></span>
                        <span class="submission-state__label">Submitted to SUL</span>
                        <h6><?php echo $isAwaitingSulReview ? 'Awaiting SUL Review' : 'Handoff Complete'; ?></h6>
                        <p><?php echo $isAwaitingSulReview ? 'This summary is read-only while the Service Unit Leader reviews the plan.' : 'The Service Unit Leader has moved this project into the next workflow step.'; ?></p>
                        <a href="projects.php" class="submission-state__action"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Projects</a>
                    </div>
                <?php else: ?>
                    <div class="summary-submission-layout">
                        <div class="summary-submission-notices">
                            <?php if (!$summaryReadyForSubmit): ?>
                                <div class="summary-submission-notice is-warning">
                                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                                    <span>
                                        Complete Features, Technology, Developer Allocation, Methodology Roadmap, Architecture Plan, and Workflow Diagram before submission.
                                        <?php if (!(bool) ($methodologyCompletion['is_complete'] ?? false)): ?>
                                            <a href="../roadmap/methodology.php?project_id=<?php echo $projectId; ?>">Schedule <?php echo (int) ($methodologyCompletion['unscheduled_count'] ?? 0); ?> remaining feature task<?php echo (int) ($methodologyCompletion['unscheduled_count'] ?? 0) === 1 ? '' : 's'; ?>.</a>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="summary-submission-notice"><i class="fas fa-circle-info" aria-hidden="true"></i><span>Submission sends this plan to the Service Unit Leader for review and task allocation.</span></div>
                        </div>
                        <form method="POST" id="summaryForm" class="summary-submission-form" data-generic-draft-disabled="true" data-app-loading data-loading-title="Sending to SUL" data-loading-text="Please wait while the architecture plan is submitted and the Service Unit Leader is notified.">
                            <div class="submission-confirmation"><input class="form-check-input" type="checkbox" id="confirmCheck" name="confirmCheck" value="1" required><label class="form-check-label" for="confirmCheck">I confirm that all information is accurate and complete.</label></div>
                            <button type="submit" class="btn-submit" id="submitBtn" <?php echo $summaryReadyForSubmit ? 'disabled' : 'disabled title="Complete all required architect steps first"'; ?>><i class="fas fa-paper-plane" aria-hidden="true"></i><span>Submit to SUL</span></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </article>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const summaryInsightNotification = document.getElementById('summaryProjectInsightNotification');
    const summaryInsightDismiss = summaryInsightNotification?.querySelector('[data-summary-insight-dismiss]');
    const confirmCheck = document.getElementById('confirmCheck');
    const submitBtn = document.getElementById('submitBtn');
    const summaryForm = document.getElementById('summaryForm');
    const summaryReady = <?php echo json_encode($summaryReadyForSubmit); ?>;

    summaryInsightDismiss?.addEventListener('click', () => {
        summaryInsightNotification.hidden = true;
    });

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
