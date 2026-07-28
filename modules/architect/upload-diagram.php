<?php
require_once '../../includes/auth.php';
require_once '../../includes/file-storage.php';
require_once '../../includes/methodology-roadmap.php';
require_once '../../includes/project-compilation.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
methodologyRoadmapEnsureSchema($conn);

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
    header('Location: ../roadmap/methodology.php?project_id=' . $projectId);
    exit();
}

$methodologyCompletion = methodologyRoadmapCompletion($conn, $projectId);
if (!(bool) ($methodologyCompletion['is_complete'] ?? false)) {
    if ((int) ($methodologyCompletion['feature_count'] ?? 0) <= 0) {
        $_SESSION['error'] = 'Save at least one project feature before uploading architecture documents.';
    } elseif ((int) ($methodologyCompletion['phase_count'] ?? 0) <= 0) {
        $_SESSION['error'] = 'Apply a methodology template or add at least one roadmap phase before uploading architecture documents.';
    } elseif ((int) ($methodologyCompletion['unscheduled_count'] ?? 0) > 0) {
        $_SESSION['error'] = 'Schedule all saved features in the roadmap before uploading architecture documents. '
            . (int) ($methodologyCompletion['unscheduled_count'] ?? 0)
            . ' feature'
            . ((int) ($methodologyCompletion['unscheduled_count'] ?? 0) === 1 ? ' is' : 's are')
            . ' still unscheduled.';
    } else {
        $_SESSION['error'] = 'Complete the methodology roadmap before uploading architecture documents.';
    }
    header('Location: ../roadmap/methodology.php?project_id=' . $projectId);
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
$submissionChecklistItems = [
    ['label' => 'Architecture Plan', 'note' => 'Required for Summary', 'complete' => $hasArchitecturePlan],
    ['label' => 'Workflow Diagram', 'note' => 'Required for Summary', 'complete' => $hasWorkflowDiagram],
    ['label' => 'Supporting Technical Document', 'note' => 'Optional supporting evidence', 'complete' => $hasSupportingTechnicalDoc],
    ['label' => 'Ready for Summary', 'note' => 'All required documents are uploaded', 'complete' => $checklistReadyForSummary],
];
$submissionChecklistCompleted = count(array_filter(
    $submissionChecklistItems,
    static fn(array $item): bool => (bool) $item['complete']
));

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $docType = normalizeArchitectureDocumentType($_POST['document_type'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $uploadErrorLabels = [
        UPLOAD_ERR_INI_SIZE => 'The selected file is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The selected file is larger than the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please choose a file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
    ];

    $file = $_FILES['diagram'] ?? null;
    $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = $uploadErrorLabels[$uploadError] ?? 'Document upload failed. Please try again.';
    } else {
        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'];
        $maxFileSize = 10 * 1024 * 1024;

        if (!in_array($extension, $allowedExtensions, true)) {
            $_SESSION['error'] = 'Only PDF, PNG, JPG, DOC, and DOCX files are allowed.';
        } elseif ((int) ($file['size'] ?? 0) > $maxFileSize) {
            $_SESSION['error'] = 'The selected file must be 10MB or smaller.';
        } else {
            try {
                $target = storageBuildUploadTarget($originalName, 'architecture');
                $originalFileName = $target['original_name'];

                if (!is_uploaded_file((string) $file['tmp_name']) || !move_uploaded_file((string) $file['tmp_name'], $target['absolute_path'])) {
                    $_SESSION['error'] = 'The document could not be saved. Please try uploading it again.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO architecture_documents (project_id, document_type, file_name, file_path, description, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt === false) {
                        $_SESSION['error'] = 'The document was uploaded, but the database record could not be prepared.';
                    } else {
                        $stmt->bind_param("issssi", $projectId, $docType, $originalFileName, $target['relative_path'], $description, $user['id']);
                        if ($stmt->execute()) {
                            logActivity($user['id'], 'upload_diagram', 'projects', $projectId, null, [
                                'document_type' => $docType,
                                'file_name' => $originalFileName,
                            ]);
                            $_SESSION['success'] = 'Document uploaded successfully';
                        } else {
                            $_SESSION['error'] = 'The document was uploaded, but it could not be saved to the document list.';
                        }
                        $stmt->close();
                    }
                }
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Document upload failed because the upload folder could not be prepared.';
            }
        }
    }
    
    projectCompilationRecord($conn, $projectId, (int) $user['id'], 'documents');
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
    --gray-500: #adb5bd;
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

.form-card .card-body {
    container-type: inline-size;
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

.document-upload-field {
    align-items: stretch;
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.05), #ffffff 58%);
    border: 1px dashed rgba(31, 99, 146, 0.28);
    border-radius: 12px;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    min-height: 46px;
    overflow: hidden;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.document-upload-field:hover,
.document-upload-field:focus-within {
    border-color: rgba(31, 99, 146, 0.58);
    box-shadow: 0 8px 20px rgba(31, 99, 146, 0.11);
}

.document-upload-button {
    align-items: center;
    background: #e8f4fa;
    border-right: 1px solid rgba(31, 99, 146, 0.14);
    color: var(--theme-primary);
    cursor: pointer;
    display: inline-flex;
    font-size: 0.86rem;
    font-weight: 700;
    gap: 0.45rem;
    justify-content: center;
    margin: 0;
    min-height: 44px;
    padding: 0 0.9rem;
    transition: background-color 0.18s ease, color 0.18s ease;
    white-space: nowrap;
}

.document-upload-button:hover {
    background: var(--theme-primary);
    color: #ffffff;
}

.document-upload-summary {
    align-self: center;
    color: var(--gray-700);
    font-size: 0.88rem;
    min-width: 0;
    overflow: hidden;
    padding: 0 0.9rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Document Item */
.document-item {
    align-items: center;
    background: #ffffff;
    border: 1px solid var(--gray-200);
    border-left: 3px solid transparent;
    border-radius: 16px;
    display: grid;
    gap: 0.85rem;
    grid-template-columns: 46px minmax(0, 1fr) auto;
    margin-bottom: 0.75rem;
    padding: 0.9rem 1rem;
    transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.document-item:hover {
    background: #f2f8fc;
    border-color: #d8e8f1;
    border-left-color: var(--theme-primary);
    box-shadow: 0 8px 20px rgba(31, 99, 146, 0.09);
}

.document-item:last-child {
    margin-bottom: 0;
}

.document-item__icon {
    align-items: center;
    align-self: start;
    background: #e9f5fb;
    border-radius: 12px;
    color: var(--theme-primary);
    display: inline-flex;
    font-size: 1rem;
    height: 44px;
    justify-content: center;
    width: 44px;
}

.document-item__content {
    min-width: 0;
}

.document-item__heading {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.document-item__title {
    color: var(--gray-900);
    font-size: 0.95rem;
    font-weight: 650;
    line-height: 1.25;
}

.document-item__format {
    background: #edf4f8;
    border-radius: 999px;
    color: #537089;
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    padding: 0.22rem 0.48rem;
}

.document-item__description {
    color: var(--gray-600);
    font-size: 0.82rem;
    line-height: 1.4;
    margin: 0.32rem 0 0;
    overflow-wrap: anywhere;
}

.document-item__meta {
    align-items: center;
    color: #71869b;
    display: flex;
    font-size: 0.74rem;
    gap: 0.35rem;
    margin-top: 0.42rem;
}

.document-item__actions {
    align-items: center;
    display: flex;
    gap: 0.45rem;
}

.document-item__action {
    align-items: center;
    border: 1px solid transparent;
    border-radius: 10px;
    display: inline-flex;
    flex: 0 0 auto;
    height: 38px;
    justify-content: center;
    padding: 0;
    text-decoration: none;
    transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease, transform 0.18s ease;
    width: 38px;
}

.document-item__action--view {
    background: #e8f4fa;
    border-color: #cfe4ef;
    color: var(--theme-primary);
}

.document-item__action--view:hover {
    background: var(--theme-primary);
    border-color: var(--theme-primary);
    color: #ffffff;
    transform: translateY(-1px);
}

.document-item__action--delete {
    background: #fff0f1;
    border-color: #ffdadd;
    color: #dc2f3b;
}

.document-item__action--delete:hover {
    background: #dc2f3b;
    border-color: #dc2f3b;
    color: #ffffff;
    transform: translateY(-1px);
}

.document-item__action:focus-visible {
    outline: 3px solid rgba(31, 99, 146, 0.2);
    outline-offset: 2px;
}

/* Document form actions */
.document-actions {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(120px, 0.7fr) minmax(0, 1.15fr);
    gap: 0.65rem;
    align-items: stretch;
    margin-top: 1.5rem;
}

.document-actions .btn-upload,
.document-actions .btn-success-modern,
.document-actions [data-generic-save-draft] {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-width: 0;
    min-height: 48px;
    margin: 0 !important;
    padding: 0.7rem 0.85rem;
    border-radius: 12px;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1.2;
    text-align: center;
    text-decoration: none;
    white-space: nowrap;
    transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease, border-color 0.2s ease;
}

.document-actions i {
    margin: 0 !important;
    font-size: 0.9rem;
}

.document-actions .btn-upload {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: 1px solid transparent;
    box-shadow: 0 5px 14px rgba(31, 99, 146, 0.2);
}

.document-actions [data-generic-save-draft] {
    background: white;
    color: var(--theme-primary);
    border: 1px solid rgba(31, 99, 146, 0.45);
    box-shadow: none;
}

.document-actions .btn-success-modern {
    background: linear-gradient(135deg, #10a977 0%, #07845e 100%);
    color: #ffffff !important;
    border: 1px solid transparent;
    box-shadow: 0 5px 14px rgba(5, 150, 105, 0.2);
}

.document-actions .btn-success-modern :is(span, i) {
    color: inherit !important;
}

.document-actions .btn-upload:hover,
.document-actions .btn-success-modern:hover,
.document-actions [data-generic-save-draft]:hover {
    transform: translateY(-2px);
}

.document-actions .btn-upload:hover {
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.28);
    color: white;
}

.document-actions [data-generic-save-draft]:hover {
    background: rgba(31, 99, 146, 0.07);
    border-color: var(--theme-primary);
    color: var(--theme-dark);
}

.document-actions .btn-success-modern:hover {
    box-shadow: 0 8px 18px rgba(5, 150, 105, 0.27);
    color: #ffffff !important;
}

.document-actions :is(button, a):focus-visible {
    outline: 3px solid rgba(31, 99, 146, 0.22);
    outline-offset: 2px;
}

.document-actions .btn-success-modern.disabled {
    background: var(--gray-200);
    color: var(--gray-500) !important;
    border-color: var(--gray-300);
    box-shadow: none;
    cursor: not-allowed;
    opacity: 1;
    transform: none;
}

.document-actions [data-generic-draft-status] {
    display: none;
    grid-column: 1 / -1;
    order: 4;
    margin: 0 !important;
    color: var(--gray-500);
    font-size: 0.75rem;
    text-align: center;
}

.document-actions [data-generic-draft-status]:not(:empty) {
    display: block;
}

/* Submission checklist control */
.document-upload-card-header {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
}

.submission-checklist-trigger {
    align-items: center;
    background: #edf7fb;
    border: 1px solid rgba(31, 99, 146, 0.22);
    border-radius: 12px;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 auto;
    height: 42px;
    justify-content: center;
    padding: 0;
    position: relative;
    transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
    width: 42px;
}

.submission-checklist-trigger:hover {
    background: #dff0f8;
    border-color: rgba(31, 99, 146, 0.5);
    transform: translateY(-1px);
}

.submission-checklist-trigger.needs-attention {
    background: #fff8df;
    border-color: #f0c75e;
    color: #a86600;
    animation: submissionChecklistAttention 2.2s ease-in-out infinite;
}

.submission-checklist-trigger__status {
    align-items: center;
    background: #e11d48;
    border: 2px solid #ffffff;
    border-radius: 50%;
    color: #ffffff;
    display: inline-flex;
    font-size: 0.46rem;
    height: 17px;
    justify-content: center;
    position: absolute;
    right: -5px;
    top: -5px;
    width: 17px;
}

.submission-checklist-trigger.is-complete .submission-checklist-trigger__status {
    background: #129568;
}

@keyframes submissionChecklistAttention {
    0%, 100% { box-shadow: 0 0 0 0 rgba(217, 146, 17, 0); }
    50% { box-shadow: 0 0 0 6px rgba(217, 146, 17, 0.13); }
}

.submission-checklist-panel {
    --bs-offcanvas-width: min(400px, 100vw);
}

.submission-checklist-panel .offcanvas-header {
    border-bottom: 1px solid var(--gray-200);
    padding: 1.1rem 1.25rem;
}

.submission-checklist-panel__heading {
    align-items: center;
    display: flex;
    gap: 0.75rem;
}

.submission-checklist-panel__heading-icon {
    align-items: center;
    background: #e9f5fb;
    border-radius: 11px;
    color: var(--theme-primary);
    display: inline-flex;
    flex: 0 0 auto;
    height: 40px;
    justify-content: center;
    width: 40px;
}

.submission-checklist-panel__heading h5 {
    color: var(--gray-900);
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
}

.submission-checklist-panel__heading small {
    color: var(--gray-500);
    display: block;
    margin-top: 0.12rem;
}

.submission-checklist-progress {
    background: #f6f9fb;
    border: 1px solid var(--gray-200);
    border-radius: 14px;
    margin-bottom: 1rem;
    padding: 0.9rem;
}

.submission-checklist-progress__meta {
    align-items: center;
    color: var(--gray-700);
    display: flex;
    font-size: 0.82rem;
    justify-content: space-between;
    margin-bottom: 0.55rem;
}

.submission-checklist-progress .progress {
    background: #dfe9ef;
    height: 7px;
}

.submission-checklist-progress .progress-bar {
    background: #16835b;
}

.submission-checklist-list {
    display: grid;
    gap: 0.65rem;
}

.submission-checklist-item {
    align-items: center;
    background: #ffffff;
    border: 1px solid var(--gray-200);
    border-radius: 13px;
    display: grid;
    gap: 0.75rem;
    grid-template-columns: auto minmax(0, 1fr);
    padding: 0.85rem;
}

.submission-checklist-item__icon {
    align-items: center;
    background: #f1f5f8;
    border-radius: 50%;
    color: #8092a3;
    display: inline-flex;
    height: 32px;
    justify-content: center;
    width: 32px;
}

.submission-checklist-item.is-complete .submission-checklist-item__icon {
    background: #def7eb;
    color: #12865e;
}

.submission-checklist-item strong {
    color: var(--gray-900);
    display: block;
    font-size: 0.88rem;
}

.submission-checklist-item small {
    color: var(--gray-500);
    display: block;
    margin-top: 0.12rem;
}

.submission-checklist-guidance {
    background: #fff8df;
    border: 1px solid #f2d47d;
    border-radius: 13px;
    color: #76500c;
    display: flex;
    font-size: 0.84rem;
    gap: 0.6rem;
    line-height: 1.45;
    margin-top: 1rem;
    padding: 0.85rem;
}

@media (prefers-reduced-motion: reduce) {
    .submission-checklist-trigger.needs-attention {
        animation: none;
    }
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern { padding: 1.5rem; }
    .breadcrumb-modern { padding: 0.5rem 0.75rem; }
    .progress-indicator { padding: 0.75rem; }
    .progress-step .step-label { font-size: 0.6rem; }
    .progress-step .step-number { width: 24px; height: 24px; font-size: 0.7rem; }
}

@media (max-width: 575.98px) {
    .document-item {
        align-items: start;
        grid-template-columns: 42px minmax(0, 1fr);
        padding: 0.8rem;
    }

    .document-item__icon {
        height: 40px;
        width: 40px;
    }

    .document-item__actions {
        grid-column: 2;
        justify-content: flex-start;
    }
}

@media (max-width: 575.98px) {
    .document-actions {
        grid-template-columns: 1fr;
    }

    .document-actions [data-generic-draft-status] {
        grid-column: 1;
    }
}

@container (max-width: 520px) {
    .document-actions {
        grid-template-columns: 1fr;
    }

    .document-actions [data-generic-draft-status] {
        grid-column: 1;
    }
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .documents-card,
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-upload-field {
    background: #071321;
    border-color: rgba(138, 210, 255, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-upload-button {
    background: rgba(31, 99, 146, 0.24);
    border-color: rgba(138, 210, 255, 0.14);
    color: #8fd3ff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-upload-button:hover {
    background: #1f6392;
    color: #ffffff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-upload-summary {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-checklist-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-checklist-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-checklist-progress {
    background: #071321;
    border-color: rgba(138, 210, 255, 0.18);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-checklist-panel .offcanvas-header {
    border-color: rgba(138, 210, 255, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-checklist-item strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-checklist-panel__heading h5 {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .submission-checklist-trigger__status {
    border-color: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-item:hover {
    background: #0b1d2e !important;
    border-color: rgba(113, 178, 224, 0.35) !important;
    border-left-color: #55b5e8 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-item__title {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-item__format,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-item__icon {
    background: rgba(31, 99, 146, 0.24);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-item__description,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-item__meta {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-actions [data-generic-save-draft] {
    background: #0a1b2c;
    border-color: rgba(113, 178, 224, 0.55);
    color: #8bc7f0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .document-actions [data-generic-save-draft]:hover {
    background: rgba(31, 99, 146, 0.2);
    color: #c4e6ff;
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
        <a
            class="progress-step"
            href="summary.php?id=<?php echo $projectId; ?>"
            data-summary-gate="<?php echo $checklistReadyForSummary ? '' : 'Upload both an Architecture Plan and a Workflow Diagram before proceeding to Summary.'; ?>"
        ><div class="step-number">7</div><div class="step-label">Summary</div></a>
    </div>

    <div class="alert alert-warning d-none" role="alert" id="documentInlineError">
        <strong>Needs attention</strong>
        <span></span>
    </div>

    <?php include '../../includes/architect-project-review-panel.php'; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="form-card">
                <div class="card-header document-upload-card-header">
                    <h5><i class="fas fa-plus-circle"></i> Upload New Document</h5>
                    <button
                        type="button"
                        class="submission-checklist-trigger <?php echo $checklistReadyForSummary ? 'is-complete' : 'needs-attention'; ?>"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#submissionChecklistPanel"
                        aria-controls="submissionChecklistPanel"
                        aria-label="Open submission checklist"
                        title="Submission checklist"
                    >
                        <i class="fas fa-list-check" aria-hidden="true"></i>
                        <span class="submission-checklist-trigger__status" aria-hidden="true">
                            <i class="fas <?php echo $checklistReadyForSummary ? 'fa-check' : 'fa-exclamation'; ?>"></i>
                        </span>
                    </button>
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
                            <div class="document-upload-field">
                                <label for="architectDocumentFile" class="document-upload-button">
                                    <i class="fas fa-paperclip" aria-hidden="true"></i>
                                    <span>Choose File</span>
                                </label>
                                <span class="document-upload-summary" id="architectDocumentFileSummary">No file chosen</span>
                                <input
                                    id="architectDocumentFile"
                                    type="file"
                                    name="diagram"
                                    class="visually-hidden"
                                    accept=".pdf,.png,.jpg,.jpeg,.doc,.docx"
                                    aria-describedby="architectDocumentFileSummary"
                                    required
                                >
                            </div>
                            <small class="text-muted d-block mt-1">Accepted formats: PDF, PNG, JPG, DOC, DOCX (Max 10MB)</small>
                        </div>
                        
                        <div class="document-actions">
                            <button type="submit" class="btn-upload"><i class="fas fa-upload" aria-hidden="true"></i><span>Upload Document</span></button>
                            <a href="summary.php?id=<?php echo $projectId; ?>" class="btn-success-modern <?php echo $checklistReadyForSummary ? '' : 'disabled'; ?>" <?php echo $checklistReadyForSummary ? '' : 'aria-disabled="true" tabindex="-1"'; ?>><span>Proceed to Summary</span><i class="fas fa-arrow-right" aria-hidden="true"></i></a>
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
                        <?php
                            $documentType = (string) ($doc['document_type'] ?? 'document');
                            $documentLabel = ucwords(str_replace('_', ' ', $documentType));
                            $documentIcon = $documentType === 'architecture_plan'
                                ? 'fa-file-code'
                                : ($documentType === 'workflow_diagram' ? 'fa-project-diagram' : 'fa-file-lines');
                            $documentExtension = strtoupper(pathinfo((string) ($doc['file_path'] ?? ''), PATHINFO_EXTENSION));
                            $documentDescription = trim((string) ($doc['description'] ?? ''));
                        ?>
                        <div class="document-item">
                            <span class="document-item__icon" aria-hidden="true">
                                <i class="fas <?php echo htmlspecialchars($documentIcon); ?>"></i>
                            </span>
                            <div class="document-item__content">
                                <div class="document-item__heading">
                                    <strong class="document-item__title"><?php echo htmlspecialchars($documentLabel); ?></strong>
                                    <?php if ($documentExtension !== ''): ?>
                                        <span class="document-item__format"><?php echo htmlspecialchars($documentExtension); ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="document-item__description"><?php echo htmlspecialchars($documentDescription !== '' ? $documentDescription : 'No description provided.'); ?></p>
                                <div class="document-item__meta">
                                    <i class="far fa-clock" aria-hidden="true"></i>
                                    <span>Uploaded <?php echo !empty($doc['created_at']) ? date('M d, Y \a\t g:i A', strtotime((string) $doc['created_at'])) : 'with no recorded date'; ?></span>
                                </div>
                            </div>
                            <div class="document-item__actions">
                                <a
                                    href="<?php echo BASE_URL; ?>/<?php echo htmlspecialchars((string) $doc['file_path']); ?>"
                                    class="document-item__action document-item__action--view"
                                    target="_blank"
                                    rel="noopener"
                                    aria-label="View <?php echo htmlspecialchars($documentLabel); ?>"
                                    title="View document"
                                ><i class="fas fa-eye" aria-hidden="true"></i></a>
                                <button
                                    type="button"
                                    class="document-item__action document-item__action--delete"
                                    onclick="deleteDocument(<?php echo (int) $doc['id']; ?>)"
                                    aria-label="Delete <?php echo htmlspecialchars($documentLabel); ?>"
                                    title="Delete document"
                                ><i class="fas fa-trash" aria-hidden="true"></i></button>
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

</div>

<div class="offcanvas offcanvas-end submission-checklist-panel" tabindex="-1" id="submissionChecklistPanel" aria-labelledby="submissionChecklistPanelTitle">
    <div class="offcanvas-header">
        <div class="submission-checklist-panel__heading">
            <span class="submission-checklist-panel__heading-icon"><i class="fas fa-list-check" aria-hidden="true"></i></span>
            <span>
                <h5 id="submissionChecklistPanelTitle">Submission Checklist</h5>
                <small>Documents required before Summary</small>
            </span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div class="submission-checklist-progress">
            <div class="submission-checklist-progress__meta">
                <span>Completion</span>
                <strong><?php echo (int) $submissionChecklistCompleted; ?> of <?php echo count($submissionChecklistItems); ?></strong>
            </div>
            <div class="progress" role="progressbar" aria-label="Submission checklist completion" aria-valuenow="<?php echo (int) $submissionChecklistCompleted; ?>" aria-valuemin="0" aria-valuemax="<?php echo count($submissionChecklistItems); ?>">
                <div class="progress-bar" style="width: <?php echo count($submissionChecklistItems) > 0 ? (int) round(($submissionChecklistCompleted / count($submissionChecklistItems)) * 100) : 0; ?>%"></div>
            </div>
        </div>

        <div class="submission-checklist-list">
            <?php foreach ($submissionChecklistItems as $checklistItem): ?>
                <div class="submission-checklist-item <?php echo $checklistItem['complete'] ? 'is-complete' : ''; ?>">
                    <span class="submission-checklist-item__icon">
                        <i class="fas <?php echo $checklistItem['complete'] ? 'fa-check' : 'fa-minus'; ?>" aria-hidden="true"></i>
                    </span>
                    <span>
                        <strong><?php echo htmlspecialchars((string) $checklistItem['label']); ?></strong>
                        <small><?php echo htmlspecialchars((string) $checklistItem['note']); ?></small>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!$checklistReadyForSummary): ?>
            <div class="submission-checklist-guidance">
                <i class="fas fa-triangle-exclamation mt-1" aria-hidden="true"></i>
                <span>Upload both an Architecture Plan and a Workflow Diagram before proceeding to Summary.</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const summaryGateLink = document.querySelector('[data-summary-gate]');
const documentInlineError = document.getElementById('documentInlineError');
const architectDocumentFile = document.getElementById('architectDocumentFile');
const architectDocumentFileSummary = document.getElementById('architectDocumentFileSummary');

architectDocumentFile?.addEventListener('change', () => {
    const selectedFile = architectDocumentFile.files?.[0];
    const fileName = selectedFile?.name || 'No file chosen';

    if (architectDocumentFileSummary) {
        architectDocumentFileSummary.textContent = fileName;
        if (selectedFile) {
            architectDocumentFileSummary.title = fileName;
        } else {
            architectDocumentFileSummary.removeAttribute('title');
        }
    }
});

summaryGateLink?.addEventListener('click', (event) => {
    const message = String(summaryGateLink.dataset.summaryGate || '').trim();
    if (message === '') {
        return;
    }

    event.preventDefault();
    const messageElement = documentInlineError?.querySelector('span');
    if (messageElement) messageElement.textContent = message;
    documentInlineError?.classList.remove('d-none');
    documentInlineError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

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
