<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/project-progress.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();
$currentUserId = (int) ($user['id'] ?? 0);
workflowEnsureSchema($conn);

$selectedTaskId = (int) ($_GET['task_id'] ?? 0);
$selectedClientId = (int) ($_GET['client_id'] ?? 0);
$selectedProjectId = (int) ($_GET['project_id'] ?? 0);

function releaseChecklistIsComplete(array $task): bool
{
    $status = (string) ($task['status'] ?? '');

    return !empty($task['merged_at'])
        || !empty($task['completed_at'])
        || !empty($task['architect_reviewed_at'])
        || in_array($status, ['merged', 'ready_for_release', 'completed'], true);
}

function releaseChecklistSourceLabel(array $task): string
{
    $architectName = trim((string) ($task['architect_name'] ?? ''));
    return $architectName !== '' ? $architectName : 'Architect';
}

function releaseChecklistStatusLabel(array $task): string
{
    if (releaseChecklistIsComplete($task)) {
        return 'Complete';
    }

    $status = (string) ($task['status'] ?? 'todo');
    return ucfirst(str_replace('_', ' ', $status));
}

$taskProjectLookup = null;
if ($selectedTaskId > 0) {
    $taskLookupStmt = $conn->prepare("
        SELECT t.project_id, p.client_id
        FROM tasks t
        JOIN projects p ON p.id = t.project_id
        WHERE t.id = ?
        LIMIT 1
    ");
    if ($taskLookupStmt !== false) {
        $taskLookupStmt->bind_param('i', $selectedTaskId);
        $taskLookupStmt->execute();
        $taskProjectLookup = $taskLookupStmt->get_result()->fetch_assoc() ?: null;
        $taskLookupStmt->close();
    }
}

if ($selectedTaskId > 0) {
    $selectedProjectId = (int) ($taskProjectLookup['project_id'] ?? $selectedProjectId);
    $selectedClientId = (int) ($taskProjectLookup['client_id'] ?? $selectedClientId);
}

$clientsResult = $conn->query("
    SELECT c.id, c.name AS client_name, COUNT(p.id) AS project_count
    FROM clients c
    JOIN projects p ON p.client_id = c.id
    GROUP BY c.id, c.name
    ORDER BY c.name ASC
");

$clients = [];
while ($clientRow = $clientsResult ? $clientsResult->fetch_assoc() : null) {
    if (!$clientRow) {
        break;
    }
    $clients[] = [
        'id' => (int) ($clientRow['id'] ?? 0),
        'name' => (string) ($clientRow['client_name'] ?? 'Client'),
        'project_count' => (int) ($clientRow['project_count'] ?? 0),
    ];
}

if ($selectedClientId <= 0 && $clients) {
    $selectedClientId = (int) $clients[0]['id'];
}

$projectOptionsStmt = $conn->prepare("
    SELECT
        p.id,
        p.name,
        p.status,
        MAX(p.updated_at) AS updated_at,
        COUNT(CASE WHEN t.task_type = 'feature' THEN 1 END) AS feature_count,
        COUNT(CASE WHEN t.task_type = 'enhancement' THEN 1 END) AS additional_count
    FROM projects p
    LEFT JOIN tasks t ON t.project_id = p.id AND t.task_type IN ('feature', 'enhancement')
    WHERE p.client_id = ?
    GROUP BY p.id, p.name, p.status
    ORDER BY updated_at DESC, p.id DESC
");

$projectOptions = [];
if ($projectOptionsStmt !== false && $selectedClientId > 0) {
    $projectOptionsStmt->bind_param('i', $selectedClientId);
    $projectOptionsStmt->execute();
    $projectOptionsResult = $projectOptionsStmt->get_result();
    if ($projectOptionsResult) {
        while ($projectRow = $projectOptionsResult->fetch_assoc()) {
            $projectOptions[] = [
                'id' => (int) ($projectRow['id'] ?? 0),
                'name' => (string) ($projectRow['name'] ?? 'Project'),
                'status' => (string) ($projectRow['status'] ?? 'pending'),
                'feature_count' => (int) ($projectRow['feature_count'] ?? 0),
                'additional_count' => (int) ($projectRow['additional_count'] ?? 0),
            ];
        }
    }
    $projectOptionsStmt->close();
}

if ($selectedProjectId <= 0 && $projectOptions) {
    $selectedProjectId = (int) $projectOptions[0]['id'];
}

$selectedProject = null;
if ($selectedProjectId > 0) {
    $projectStmt = $conn->prepare("
        SELECT p.*, c.name AS client_name, c.contact_person, c.email AS client_email, c.phone AS client_phone,
               c.created_by AS client_created_by
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ? AND p.client_id = ?
        LIMIT 1
    ");
    if ($projectStmt !== false) {
        $projectStmt->bind_param('ii', $selectedProjectId, $selectedClientId);
        $projectStmt->execute();
        $selectedProject = $projectStmt->get_result()->fetch_assoc() ?: null;
        $projectStmt->close();
    }
}

if (!$selectedProject && $projectOptions) {
    $selectedProjectId = (int) $projectOptions[0]['id'];
    $selectedProject = null;
    $projectStmt = $conn->prepare("
        SELECT p.*, c.name AS client_name, c.contact_person, c.email AS client_email, c.phone AS client_phone,
               c.created_by AS client_created_by
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ? AND p.client_id = ?
        LIMIT 1
    ");
    if ($projectStmt !== false) {
        $projectStmt->bind_param('ii', $selectedProjectId, $selectedClientId);
        $projectStmt->execute();
        $selectedProject = $projectStmt->get_result()->fetch_assoc() ?: null;
        $projectStmt->close();
    }
}

$featureItems = [];
$additionalItems = [];
$featureCompleteCount = 0;
$featurePendingCount = 0;
$additionalCompleteCount = 0;
$additionalPendingCount = 0;
$additionalRequirementsText = '';
$selectedProjectRepository = null;
$canDownloadSelectedProjectRepository = false;

if ($selectedProject) {
    $additionalRequirementsText = trim((string) ($selectedProject['requested_features'] ?? ''));
    $canDownloadSelectedProjectRepository = (string) ($selectedProject['status'] ?? '') === 'completed'
        && $currentUserId > 0
        && (
            (int) ($selectedProject['created_by'] ?? 0) === $currentUserId
            || (int) ($selectedProject['client_created_by'] ?? 0) === $currentUserId
        );

    if ($canDownloadSelectedProjectRepository) {
        $repositoryStmt = $conn->prepare("
            SELECT id, name, default_branch_name
            FROM project_repositories
            WHERE project_id = ?
              AND repository_status = 'active'
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");

        if ($repositoryStmt !== false) {
            $repositoryStmt->bind_param('i', $selectedProjectId);
            $repositoryStmt->execute();
            $selectedProjectRepository = $repositoryStmt->get_result()->fetch_assoc() ?: null;
            $repositoryStmt->close();
        }
    }

    $taskStmt = $conn->prepare("
        SELECT
            t.id,
            t.title,
            t.description,
            t.task_type,
            t.status,
            t.priority,
            t.completed_at,
            t.merged_at,
            t.architect_reviewed_at,
            t.release_notified_at,
            t.created_at,
            architect.full_name AS architect_name,
            developer.full_name AS developer_name
        FROM tasks t
        JOIN users architect ON architect.id = t.assigned_by
        LEFT JOIN users developer ON developer.id = t.assigned_to
        WHERE t.project_id = ?
          AND t.task_type IN ('feature', 'enhancement')
          AND architect.role = 'architect'
        ORDER BY CASE WHEN t.task_type = 'feature' THEN 0 ELSE 1 END, t.created_at ASC, t.id ASC
    ");

    if ($taskStmt !== false) {
        $taskStmt->bind_param('i', $selectedProjectId);
        $taskStmt->execute();
        $taskResult = $taskStmt->get_result();
        if ($taskResult) {
            while ($task = $taskResult->fetch_assoc()) {
                $task['is_complete'] = releaseChecklistIsComplete($task);
                $task['status_label'] = releaseChecklistStatusLabel($task);
                $task['source_label'] = releaseChecklistSourceLabel($task);

                $checklistItems[] = $task;
                if ((string) $task['task_type'] === 'enhancement') {
                    $additionalItems[] = $task;
                    if ($task['is_complete']) {
                        $additionalCompleteCount++;
                    } else {
                        $additionalPendingCount++;
                    }
                } else {
                    $featureItems[] = $task;
                    if ($task['is_complete']) {
                        $featureCompleteCount++;
                    } else {
                        $featurePendingCount++;
                    }
                }
            }
        }
        $taskStmt->close();
    }
}

$projectFeatureCount = count($featureItems);
$projectAdditionalCount = count($additionalItems);
$projectTotalCount = $projectFeatureCount + $projectAdditionalCount;
$projectCompleteCount = $featureCompleteCount + $additionalCompleteCount;
$projectProgress = $projectTotalCount > 0 ? (int) round(($projectCompleteCount / $projectTotalCount) * 100) : 0;

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
    --gray-500: #6b7280;
    --gray-600: #6c757d;
    --gray-800: #343a40;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
    color: #fff;
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 320px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.12));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 0.45rem;
}

.page-header-modern p {
    margin-bottom: 0;
    color: rgba(255,255,255,0.9);
}

.filter-card,
.summary-card,
.checklist-card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
}

.filter-card {
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.summary-card {
    padding: 1rem 1.1rem;
}

.summary-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-500);
    font-weight: 700;
    margin-bottom: 0.35rem;
}

.summary-value {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--theme-primary);
    line-height: 1;
}

.summary-sub {
    margin-top: 0.3rem;
    font-size: 0.78rem;
    color: var(--gray-600);
}

.checklist-card {
    overflow: hidden;
}

.checklist-card .card-head {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: center;
}

.checklist-card .card-head h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
}

.source-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    background: #eef6ff;
    color: var(--theme-primary);
    font-size: 0.75rem;
    font-weight: 700;
}

.project-meta {
    display: grid;
    gap: 0.25rem;
    font-size: 0.85rem;
    color: var(--gray-600);
}

.progress-shell {
    display: grid;
    gap: 0.5rem;
}

.progress-track {
    height: 10px;
    border-radius: 999px;
    background: var(--gray-200);
    overflow: hidden;
}

.progress-bar-modern {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.checklist-body {
    padding: 1.5rem;
}

.checklist-section {
    margin-bottom: 1.5rem;
}

.checklist-section:last-child {
    margin-bottom: 0;
}

.section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0.85rem;
}

.section-title h6 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
}

.section-title small {
    color: var(--gray-500);
}

.feature-list {
    display: grid;
    gap: 0.75rem;
}

.feature-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 0.85rem;
    align-items: start;
    padding: 1rem;
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
}

.feature-item.is-complete {
    border-color: #bbf7d0;
    background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);
}

.feature-item input[type="checkbox"] {
    margin-top: 0.2rem;
    width: 1rem;
    height: 1rem;
}

.feature-title {
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 0.2rem;
}

.feature-description {
    color: var(--gray-600);
    font-size: 0.85rem;
    line-height: 1.5;
}

.feature-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.55rem;
}

.pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.28rem 0.6rem;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid var(--gray-200);
    color: var(--gray-600);
    font-size: 0.72rem;
    font-weight: 700;
}

.pill.complete {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.pill.pending {
    background: #fff7ed;
    border-color: #fed7aa;
    color: #c2410c;
}

.pill.architect {
    background: #eef6ff;
    border-color: #dbeafe;
    color: var(--theme-primary);
}

.empty-state {
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--gray-600);
}

.empty-state i {
    font-size: 2.3rem;
    color: var(--gray-300);
    margin-bottom: 0.75rem;
}

.requirements-box {
    background: var(--gray-50);
    border: 1px dashed var(--gray-200);
    border-radius: 16px;
    padding: 1rem;
    white-space: pre-wrap;
    line-height: 1.6;
    color: var(--gray-800);
}

.checklist-summary-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.checklist-summary-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.7rem;
    border-radius: 999px;
    background: #eef6ff;
    color: var(--theme-primary);
    font-size: 0.75rem;
    font-weight: 700;
}

.checklist-summary-tag.warning {
    background: #fff7ed;
    color: #c2410c;
}

.checklist-summary-tag.success {
    background: #ecfdf5;
    color: #047857;
}

.release-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.65rem;
}

.release-download-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    min-height: 40px;
    padding: 0.55rem 0.85rem;
    border-radius: 8px;
    background: var(--theme-primary);
    color: #fff;
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
}

.release-download-btn:hover {
    background: var(--theme-dark);
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-card {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    border: 1px solid rgba(31, 99, 146, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.12));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-card .card-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-item.is-complete,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requirements-box,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pill,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .source-badge,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-summary-tag {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-card .card-head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-title h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requirements-box {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-sub,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-title small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-description,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pill {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-card .card-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requirements-box,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control:focus {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.45);
    color: #d7e4f2;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-track {
    background: rgba(157, 182, 207, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pill.complete,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-summary-tag.success {
    background: rgba(16, 185, 129, 0.14);
    border-color: rgba(16, 185, 129, 0.3);
    color: #34d399;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pill.pending,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-summary-tag.warning {
    background: rgba(249, 115, 22, 0.14);
    border-color: rgba(249, 115, 22, 0.3);
    color: #fdba74;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pill.architect,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .source-badge,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .checklist-summary-tag {
    background: rgba(31, 99, 146, 0.14);
    border-color: rgba(31, 99, 146, 0.3);
    color: var(--theme-primary);
}

@media (max-width: 992px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .checklist-body {
        padding: 1rem;
    }

    .feature-item {
        grid-template-columns: 1fr;
    }

    .release-actions {
        justify-content: stretch;
        width: 100%;
    }

    .release-download-btn {
        width: 100%;
    }
}
</style>

<div class="container-fluid py-4">
    <div class="page-header-modern">
        <h2 class="mb-1"><i class="fas fa-bullhorn me-2"></i>Release Status</h2>
        <p class="mb-0">Architect-created feature checklist, grouped by client and project, with complete and pending items shown separately.</p>
    </div>

    <form method="GET" class="filter-card">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label fw-semibold">Client</label>
                <select name="client_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo (int) $client['id']; ?>" <?php echo (int) $client['id'] === (int) $selectedClientId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($client['name']); ?> (<?php echo (int) $client['project_count']; ?> projects)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-5">
                <label class="form-label fw-semibold">Project</label>
                <select name="project_id" class="form-select" onchange="this.form.submit()">
                    <?php if ($projectOptions): ?>
                        <?php foreach ($projectOptions as $project): ?>
                            <option value="<?php echo (int) $project['id']; ?>" <?php echo (int) $project['id'] === (int) $selectedProjectId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['name']); ?>
                                <?php echo $project['feature_count'] > 0 || $project['additional_count'] > 0 ? ' (' . (int) $project['feature_count'] . ' features, ' . (int) $project['additional_count'] . ' additional)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="">No projects available</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter me-1"></i> Load Checklist
                </button>
            </div>
        </div>
    </form>

    <?php if ($selectedProject): ?>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Completed</div>
                <div class="summary-value"><?php echo $projectCompleteCount; ?></div>
                <div class="summary-sub">Checklist items finished</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Pending</div>
                <div class="summary-value"><?php echo max(0, $projectTotalCount - $projectCompleteCount); ?></div>
                <div class="summary-sub">Still waiting for release</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Additional Requirements</div>
                <div class="summary-value"><?php echo $projectAdditionalCount; ?></div>
                <div class="summary-sub">Architect enhancement items</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Checklist Progress</div>
                <div class="summary-value"><?php echo $projectProgress; ?>%</div>
                <div class="summary-sub">Architect checklist completion</div>
            </div>
        </div>

        <div class="checklist-card">
            <div class="card-head">
                <div>
                    <h5><i class="fas fa-list-check me-2 text-primary"></i><?php echo htmlspecialchars((string) $selectedProject['name']); ?></h5>
                    <div class="project-meta">
                        <span>Client: <?php echo htmlspecialchars((string) $selectedProject['client_name']); ?></span>
                        <span>Status: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $selectedProject['status']))); ?></span>
                    </div>
                </div>
                <div class="progress-shell" style="min-width: 240px;">
                <div class="d-flex justify-content-between gap-2">
                    <small class="text-muted fw-semibold">Architect checklist</small>
                    <small class="text-muted fw-semibold"><?php echo $projectCompleteCount; ?>/<?php echo $projectTotalCount; ?></small>
                </div>
                    <div class="progress-track">
                        <div class="progress-bar-modern" style="width: <?php echo $projectProgress; ?>%;"></div>
                    </div>
                </div>
            </div>

            <div class="checklist-body">
                <div class="checklist-section">
                    <div class="section-title">
                        <h6>Features from Architect</h6>
                        <small><?php echo $featureCompleteCount; ?> complete, <?php echo $featurePendingCount; ?> pending</small>
                    </div>
                    <?php if ($featureItems): ?>
                        <div class="feature-list">
                            <?php foreach ($featureItems as $feature): ?>
                                <div class="feature-item <?php echo $feature['is_complete'] ? 'is-complete' : ''; ?>">
                                    <input type="checkbox" disabled <?php echo $feature['is_complete'] ? 'checked' : ''; ?>>
                                    <div>
                                        <div class="feature-title"><?php echo htmlspecialchars((string) $feature['title']); ?></div>
                                        <div class="feature-description">
                                            <?php echo htmlspecialchars((string) ($feature['description'] ?? 'No description provided.')); ?>
                                        </div>
                                        <div class="feature-meta">
                                            <span class="pill <?php echo $feature['is_complete'] ? 'complete' : 'pending'; ?>">
                                                <i class="fas fa-<?php echo $feature['is_complete'] ? 'check-circle' : 'clock'; ?>"></i>
                                                <?php echo htmlspecialchars((string) $feature['status_label']); ?>
                                            </span>
                                            <span class="pill architect">
                                                <i class="fas fa-user-gear"></i>
                                                <?php echo htmlspecialchars((string) $feature['source_label']); ?>
                                            </span>
                                            <?php if (!empty($feature['developer_name'])): ?>
                                                <span class="pill">
                                                    <i class="fas fa-user"></i>
                                                    <?php echo htmlspecialchars((string) $feature['developer_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="pill <?php echo $feature['is_complete'] ? 'complete' : 'pending'; ?>">
                                        <?php echo $feature['is_complete'] ? 'Complete' : 'Pending'; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <p class="mb-0">No architect features have been defined for this project yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="checklist-section">
                    <div class="section-title">
                        <h6>Additional Requirements from Architect</h6>
                        <small><?php echo $additionalCompleteCount; ?> complete, <?php echo $additionalPendingCount; ?> pending</small>
                    </div>

                    <?php if ($additionalItems): ?>
                        <div class="feature-list">
                            <?php foreach ($additionalItems as $feature): ?>
                                <div class="feature-item <?php echo $feature['is_complete'] ? 'is-complete' : ''; ?>">
                                    <input type="checkbox" disabled <?php echo $feature['is_complete'] ? 'checked' : ''; ?>>
                                    <div>
                                        <div class="feature-title"><?php echo htmlspecialchars((string) $feature['title']); ?></div>
                                        <div class="feature-description">
                                            <?php echo htmlspecialchars((string) ($feature['description'] ?? 'No description provided.')); ?>
                                        </div>
                                        <div class="feature-meta">
                                            <span class="pill <?php echo $feature['is_complete'] ? 'complete' : 'pending'; ?>">
                                                <i class="fas fa-<?php echo $feature['is_complete'] ? 'check-circle' : 'clock'; ?>"></i>
                                                <?php echo htmlspecialchars((string) $feature['status_label']); ?>
                                            </span>
                                            <span class="pill architect">
                                                <i class="fas fa-user-gear"></i>
                                                <?php echo htmlspecialchars((string) $feature['source_label']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <span class="pill <?php echo $feature['is_complete'] ? 'complete' : 'pending'; ?>">
                                        <?php echo $feature['is_complete'] ? 'Complete' : 'Pending'; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($additionalRequirementsText !== ''): ?>
                        <div class="requirements-box"><?php echo htmlspecialchars($additionalRequirementsText); ?></div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-square-minus"></i>
                            <p class="mb-0">No additional requirements were captured for this project.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($additionalRequirementsText !== ''): ?>
                    <div class="checklist-section">
                        <div class="section-title">
                            <h6>Additional Requirements Notes</h6>
                            <small>From Sales intake / project brief</small>
                        </div>
                        <div class="requirements-box"><?php echo htmlspecialchars($additionalRequirementsText); ?></div>
                    </div>
                <?php endif; ?>

                <div class="checklist-section">
                    <div class="section-title">
                        <h6>Release Checklist Snapshot</h6>
                        <small>Architect items only</small>
                    </div>
                    <div class="checklist-summary-tags">
                        <span class="checklist-summary-tag success"><i class="fas fa-check"></i> <?php echo $projectCompleteCount; ?> complete</span>
                        <span class="checklist-summary-tag warning"><i class="fas fa-hourglass-half"></i> <?php echo max(0, $projectTotalCount - $projectCompleteCount); ?> pending</span>
                        <span class="checklist-summary-tag"><i class="fas fa-list-check"></i> <?php echo $projectFeatureCount; ?> features</span>
                        <span class="checklist-summary-tag"><i class="fas fa-bell-concierge"></i> <?php echo $projectAdditionalCount; ?> additional requirements</span>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="checklist-card">
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <p class="mb-0">No project checklist is available yet for this client.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
