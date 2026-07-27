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
$selectedReleaseStatus = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$selectedReleaseTimeline = strtolower(trim((string) ($_GET['timeline'] ?? 'all')));

$allowedReleaseStatuses = ['all', 'not_started', 'in_progress', 'complete'];
$allowedReleaseTimelines = ['all', 'overdue', 'next_7_days', 'next_30_days', 'no_target_date'];

if (!in_array($selectedReleaseStatus, $allowedReleaseStatuses, true)) {
    $selectedReleaseStatus = 'all';
}
if (!in_array($selectedReleaseTimeline, $allowedReleaseTimelines, true)) {
    $selectedReleaseTimeline = 'all';
}

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
    if ($status === 'todo') {
        return 'Not started';
    }

    return ucfirst(str_replace('_', ' ', $status));
}

function releaseChecklistMatchesFilters(array $task, string $statusFilter, string $timelineFilter): bool
{
    $isComplete = !empty($task['is_complete']);
    $taskStatus = strtolower(trim((string) ($task['status'] ?? 'todo')));

    if ($statusFilter === 'complete' && !$isComplete) {
        return false;
    }
    if ($statusFilter === 'not_started' && ($isComplete || !in_array($taskStatus, ['todo', 'pending'], true))) {
        return false;
    }
    if ($statusFilter === 'in_progress' && ($isComplete || in_array($taskStatus, ['todo', 'pending'], true))) {
        return false;
    }

    if ($timelineFilter === 'all') {
        return true;
    }

    $deadline = trim((string) ($task['effective_deadline'] ?? ''));
    if ($timelineFilter === 'no_target_date') {
        return $deadline === '';
    }
    if ($deadline === '') {
        return false;
    }

    $deadlineTimestamp = strtotime($deadline);
    if ($deadlineTimestamp === false) {
        return false;
    }

    $todayTimestamp = strtotime('today');
    $deadlineDayTimestamp = strtotime(date('Y-m-d', $deadlineTimestamp));

    if ($timelineFilter === 'overdue') {
        return !$isComplete && $deadlineDayTimestamp < $todayTimestamp;
    }

    $windowDays = $timelineFilter === 'next_7_days' ? 7 : 30;
    $windowEndTimestamp = strtotime('+' . $windowDays . ' days', $todayTimestamp);

    return $deadlineDayTimestamp >= $todayTimestamp && $deadlineDayTimestamp <= $windowEndTimestamp;
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

$projectOptionsStmt = $conn->prepare("
    SELECT
        p.id,
        p.name,
        p.status,
        p.client_id,
        c.name AS client_name,
        MAX(p.updated_at) AS updated_at,
        COUNT(CASE WHEN t.task_type = 'feature' THEN 1 END) AS feature_count,
        COUNT(CASE WHEN t.task_type = 'enhancement' THEN 1 END) AS additional_count
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    LEFT JOIN tasks t ON t.project_id = p.id AND t.task_type IN ('feature', 'enhancement')
    GROUP BY p.id, p.name, p.status, p.client_id, c.name
    ORDER BY c.name ASC, updated_at DESC, p.id DESC
");

$projectOptions = [];
if ($projectOptionsStmt !== false) {
    $projectOptionsStmt->execute();
    $projectOptionsResult = $projectOptionsStmt->get_result();
    if ($projectOptionsResult) {
        while ($projectRow = $projectOptionsResult->fetch_assoc()) {
            $projectOptions[] = [
                'id' => (int) ($projectRow['id'] ?? 0),
                'name' => (string) ($projectRow['name'] ?? 'Project'),
                'status' => (string) ($projectRow['status'] ?? 'pending'),
                'client_id' => (int) ($projectRow['client_id'] ?? 0),
                'client_name' => (string) ($projectRow['client_name'] ?? 'Client'),
                'feature_count' => (int) ($projectRow['feature_count'] ?? 0),
                'additional_count' => (int) ($projectRow['additional_count'] ?? 0),
            ];
        }
    }
    $projectOptionsStmt->close();
}

$clientOptionsById = [];
foreach ($projectOptions as $projectOption) {
    $clientId = (int) ($projectOption['client_id'] ?? 0);
    if ($clientId <= 0) {
        continue;
    }

    if (!isset($clientOptionsById[$clientId])) {
        $clientOptionsById[$clientId] = [
            'id' => $clientId,
            'name' => (string) ($projectOption['client_name'] ?? 'Client'),
            'project_count' => 0,
        ];
    }

    $clientOptionsById[$clientId]['project_count']++;
}
$clientOptions = array_values($clientOptionsById);

if ($selectedProjectId <= 0 && $selectedClientId > 0) {
    foreach ($projectOptions as $projectOption) {
        if ((int) $projectOption['client_id'] === $selectedClientId) {
            $selectedProjectId = (int) $projectOption['id'];
            break;
        }
    }
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
        WHERE p.id = ?
        LIMIT 1
    ");
    if ($projectStmt !== false) {
        $projectStmt->bind_param('i', $selectedProjectId);
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
        WHERE p.id = ?
        LIMIT 1
    ");
    if ($projectStmt !== false) {
        $projectStmt->bind_param('i', $selectedProjectId);
        $projectStmt->execute();
        $selectedProject = $projectStmt->get_result()->fetch_assoc() ?: null;
        $projectStmt->close();
    }
}

if ($selectedProject) {
    $selectedClientId = (int) ($selectedProject['client_id'] ?? 0);
}

$featureItems = [];
$additionalItems = [];
$featureCompleteCount = 0;
$featurePendingCount = 0;
$additionalCompleteCount = 0;
$additionalPendingCount = 0;
$additionalRequirementsText = '';
$selectedProjectRepository = null;
$selectedProjectReceipt = null;
$canDownloadSelectedProjectRepository = false;

if ($selectedProject) {
    $projectJustCompleted = false;
    $projectRequirementsText = (string) ($selectedProject['requested_features'] ?? '');
    if (preg_match('/Additional Requirements:\s*(.*?)(?:\R{2,}Budget:|\z)/is', $projectRequirementsText, $matches)) {
        $additionalRequirementsText = trim((string) ($matches[1] ?? ''));
    }

    $taskStmt = $conn->prepare("
        SELECT
            t.id,
            t.title,
            t.description,
            t.task_type,
            t.status,
            t.priority,
            t.deadline AS task_deadline,
            COALESCE(
                t.deadline,
                (
                    SELECT MAX(mt_deadline.end_date)
                    FROM methodology_tasks mt_deadline
                    WHERE mt_deadline.task_id = t.id
                ),
                project.timeline_end
            ) AS effective_deadline,
            t.completed_at,
            t.merged_at,
            t.architect_reviewed_at,
            t.release_notified_at,
            t.created_at,
            architect.full_name AS architect_name,
            developer.full_name AS developer_name
        FROM tasks t
        JOIN projects project ON project.id = t.project_id
        LEFT JOIN users architect ON architect.id = t.assigned_by
        LEFT JOIN users developer ON developer.id = t.assigned_to
        WHERE t.project_id = ?
          AND t.task_type IN ('feature', 'enhancement')
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
$filteredFeatureItems = array_values(array_filter(
    $featureItems,
    static fn(array $item): bool => releaseChecklistMatchesFilters($item, $selectedReleaseStatus, $selectedReleaseTimeline)
));
$filteredAdditionalItems = array_values(array_filter(
    $additionalItems,
    static fn(array $item): bool => releaseChecklistMatchesFilters($item, $selectedReleaseStatus, $selectedReleaseTimeline)
));
$activeReleaseFilterCount = ($selectedReleaseStatus !== 'all' ? 1 : 0)
    + ($selectedReleaseTimeline !== 'all' ? 1 : 0);
$clearReleaseFiltersUrl = 'release-status.php?' . http_build_query(array_filter([
    'client_id' => $selectedClientId > 0 ? $selectedClientId : null,
    'project_id' => $selectedProjectId > 0 ? $selectedProjectId : null,
]));

if ($selectedProject) {
    $isProjectReleaseComplete = (string) ($selectedProject['status'] ?? '') === 'completed'
        || ($projectTotalCount > 0 && $projectCompleteCount === $projectTotalCount);

    if ($isProjectReleaseComplete && (string) ($selectedProject['status'] ?? '') !== 'completed') {
        $completeProjectStmt = $conn->prepare("UPDATE projects SET status = 'completed' WHERE id = ?");
        if ($completeProjectStmt !== false) {
            $completeProjectStmt->bind_param('i', $selectedProjectId);
            $completeProjectStmt->execute();
            $projectJustCompleted = $completeProjectStmt->affected_rows > 0;
            $completeProjectStmt->close();
            $selectedProject['status'] = 'completed';
        }
    }

    if ($isProjectReleaseComplete) {
        $selectedProjectReceipt = workflowEnsureFinalReceiptForProject($conn, $selectedProjectId, $currentUserId);
        if ($projectJustCompleted) projectTemplateCaptureProject($conn, $selectedProjectId);
    }

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

    if (!$selectedProjectReceipt) {
        $receiptStmt = $conn->prepare("
            SELECT r.id AS receipt_id, r.receipt_number, r.status, r.report_id
            FROM receipts r
            JOIN sales_reports sr ON sr.id = r.report_id
            WHERE sr.project_id = ?
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 1
        ");
        if ($receiptStmt !== false) {
            $receiptStmt->bind_param('i', $selectedProjectId);
            $receiptStmt->execute();
            $selectedProjectReceipt = $receiptStmt->get_result()->fetch_assoc() ?: null;
            $receiptStmt->close();
        }
    }
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

.release-workspace {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.filter-card {
    padding: 1.15rem 1.35rem;
    margin: 0;
    border: 0;
    border-bottom: 1px solid var(--gray-200);
    border-radius: 0;
    box-shadow: none;
    background: transparent;
}

.release-filter-grid {
    display: grid;
    grid-template-columns:
        minmax(175px, 0.8fr)
        minmax(230px, 1.05fr)
        minmax(160px, 0.68fr)
        minmax(150px, 0.62fr)
        auto;
    gap: 0.85rem;
    width: 100%;
    align-items: end;
}

.release-filter-field {
    min-width: 0;
}

.release-filter-field .form-label {
    display: flex;
    align-items: center;
    gap: 0.42rem;
    margin-bottom: 0.45rem;
    color: #526275;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.045em;
    text-transform: uppercase;
}

.release-filter-field .form-label i {
    color: var(--theme-primary);
    font-size: 0.72rem;
}

.release-filter-field .filter-optional {
    color: #8a97a6;
    font-size: 0.64rem;
    font-weight: 500;
    letter-spacing: 0;
    text-transform: none;
}

.release-filter-field .form-select {
    min-height: 46px;
    border-color: #d4e0ea;
    border-radius: 10px;
    color: #26384b;
    box-shadow: none;
}

.release-filter-field .form-select:focus {
    border-color: #7eb2d2;
    box-shadow: 0 0 0 3px rgba(30, 111, 160, 0.1);
}

.release-filter-actions {
    display: flex;
    min-height: 46px;
    align-items: center;
    gap: 0.8rem;
    padding-bottom: 1px;
    white-space: nowrap;
}

.release-clear-filters {
    display: inline-flex;
    min-height: 42px;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    padding: 0.55rem 0.8rem;
    border: 1px solid #d1dbe5;
    border-radius: 9px;
    background: #fff;
    color: #435366;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
    transition: border-color 0.18s ease, background-color 0.18s ease, color 0.18s ease;
}

.release-clear-filters:hover {
    border-color: #91b7cf;
    background: #f5faff;
    color: var(--theme-primary);
}

.release-clear-filters.is-disabled {
    pointer-events: none;
    opacity: 0.48;
}

.release-active-filter-count {
    color: #728095;
    font-size: 0.76rem;
}

.release-active-filter-count strong {
    color: #34475a;
    font-weight: 600;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0;
    margin: 0;
    border-bottom: 1px solid var(--gray-200);
}

.release-metric {
    min-width: 0;
    padding: 1.05rem 1.35rem;
    border: 0;
    border-right: 1px solid var(--gray-200);
    border-radius: 0;
    background: #fbfdff;
    box-shadow: none;
}

.release-metric:last-child {
    border-right: 0;
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
    font-size: 1.55rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

.summary-sub {
    display: none;
}

.checklist-card {
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    overflow: hidden;
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
    padding: 0 1.35rem 1.1rem;
}

.checklist-section {
    padding: 1.15rem 0;
    margin: 0;
    border-bottom: 1px solid var(--gray-200);
}

.checklist-section:last-child {
    border-bottom: 0;
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
    font-size: 0.78rem;
}

.feature-list {
    border-top: 1px solid var(--gray-200);
    border-bottom: 1px solid var(--gray-200);
}

.feature-list-head,
.feature-item {
    display: grid;
    grid-template-columns: minmax(280px, 1fr) minmax(180px, 0.42fr) 150px 96px;
    column-gap: 1.25rem;
    align-items: center;
}

.feature-list-head {
    min-height: 42px;
    padding: 0 0.75rem;
    background: #f7f9fb;
    border-bottom: 1px solid var(--gray-200);
    color: var(--gray-500);
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.055em;
    text-transform: uppercase;
}

.feature-item {
    min-height: 72px;
    padding: 0.8rem 0.75rem;
    border: 0;
    border-bottom: 1px solid var(--gray-200);
    border-radius: 0;
    background: transparent;
    transition: background-color 0.18s ease;
}

.feature-item:last-child {
    border-bottom: 0;
}

.feature-item:hover {
    background: #f7fafc;
}

.feature-item.is-complete {
    background: #f8fcfa;
}

.feature-main {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 0.75rem;
}

.feature-main > div {
    min-width: 0;
}

.feature-state-icon {
    display: inline-flex;
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #fff4e8;
    color: #c96a10;
    font-size: 0.75rem;
}

.feature-state-icon.complete {
    background: #e8f8ef;
    color: #118a50;
}

.feature-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-900);
}

.feature-description {
    display: -webkit-box;
    margin-top: 0.18rem;
    overflow: hidden;
    color: var(--gray-600);
    font-size: 0.78rem;
    line-height: 1.4;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 1;
}

.feature-owner,
.feature-deadline {
    display: inline-flex;
    min-width: 0;
    align-items: center;
    gap: 0.45rem;
    color: var(--gray-600);
    font-size: 0.8rem;
}

.feature-owner i,
.feature-deadline i {
    width: 14px;
    color: #7890a5;
    text-align: center;
}

.feature-owner span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.feature-owner.unassigned,
.feature-deadline.missing {
    color: #b55a09;
}

.feature-status {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    justify-self: start;
    font-size: 0.76rem;
    font-weight: 700;
    white-space: nowrap;
}

.feature-status::before {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ed9516;
    content: "";
}

.feature-status.pending {
    color: #a85a0a;
}

.feature-status.complete {
    color: #087b45;
}

.feature-status.complete::before {
    background: #12a15d;
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
    background: transparent;
    border: 0;
    border-left: 3px solid #c6dcea;
    border-radius: 0;
    padding: 0.25rem 0 0.25rem 0.9rem;
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

.release-toolbar {
    display: flex;
    justify-content: flex-end;
    gap: 0.65rem;
    padding: 0.85rem 1.35rem 0;
}

.release-workspace .form-label {
    margin-bottom: 0.35rem;
    color: var(--gray-500);
    font-size: 0.7rem;
    font-weight: 700 !important;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.release-workspace .form-select {
    min-height: 46px;
    border-color: #d5e2ec;
    border-radius: 10px;
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

.release-receipt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    min-height: 40px;
    padding: 0.55rem 0.85rem;
    border-radius: 8px;
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #047857;
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
}

.release-receipt-btn:hover {
    background: #d1fae5;
    color: #047857;
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(4, 120, 87, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .release-workspace {
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .release-metric {
    background: #081827;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-list-head,
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-title h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requirements-box {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-sub,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-title small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-list-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-description,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-owner,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-deadline,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pill {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-list,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-list-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requirements-box,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-item:hover {
    background: #0c2033;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-state-icon {
    background: rgba(249, 115, 22, 0.14);
    color: #fdba74;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-state-icon.complete {
    background: rgba(16, 185, 129, 0.14);
    color: #34d399;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .release-filter-field .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .release-active-filter-count {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .release-active-filter-count strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .release-clear-filters {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.28);
    color: #b7c9da;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .release-clear-filters:hover {
    background: #0c2033;
    border-color: rgba(31, 99, 146, 0.5);
    color: #d7e4f2;
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

@media (max-width: 1280px) {
    .release-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .release-filter-actions {
        grid-column: 1 / -1;
        justify-content: flex-end;
    }
}

@media (max-width: 992px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .release-metric:nth-child(2) {
        border-right: 0;
    }

    .release-metric:nth-child(-n + 2) {
        border-bottom: 1px solid var(--gray-200);
    }

    .feature-list-head,
    .feature-item {
        grid-template-columns: minmax(220px, 1fr) minmax(150px, 0.5fr) 130px 86px;
        column-gap: 0.8rem;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .release-filter-grid {
        grid-template-columns: 1fr;
    }

    .release-filter-actions {
        grid-column: auto;
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .release-metric,
    .release-metric:nth-child(2) {
        border-right: 0;
        border-bottom: 1px solid var(--gray-200);
    }

    .release-metric:last-child {
        border-bottom: 0;
    }

    .checklist-body {
        padding: 1rem;
    }

    .feature-list-head {
        display: none;
    }

    .feature-item {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.55rem 1rem;
        padding: 0.9rem 0.35rem;
    }

    .feature-main {
        grid-column: 1 / -1;
    }

    .feature-owner,
    .feature-deadline {
        padding-left: 46px;
    }

    .feature-status {
        grid-column: 2;
        grid-row: 2 / span 2;
        align-self: center;
    }

    .release-actions {
        justify-content: stretch;
        width: 100%;
    }

    .release-download-btn {
        width: 100%;
    }

    .release-receipt-btn {
        width: 100%;
    }
}
</style>

<div class="container-fluid py-4">
    <div class="page-header-modern">
        <h2 class="mb-1"><i class="fas fa-bullhorn me-2"></i>Release Status</h2>
        <p class="mb-0">Track approved features through final release.</p>
    </div>

    <section class="release-workspace">
        <form method="GET" class="filter-card">
            <div class="release-filter-grid">
                <div class="release-filter-field">
                    <label for="releaseClientFilter" class="form-label">
                        <i class="fas fa-users" aria-hidden="true"></i>
                        Client
                    </label>
                    <select
                        id="releaseClientFilter"
                        name="client_id"
                        class="form-select"
                        onchange="this.form.elements.project_id.value = ''; this.form.submit();"
                    >
                        <?php if ($clientOptions): ?>
                            <?php foreach ($clientOptions as $client): ?>
                                <option value="<?php echo (int) $client['id']; ?>" <?php echo (int) $client['id'] === (int) $selectedClientId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="">No clients available</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="release-filter-field">
                    <label for="releaseProjectFilter" class="form-label">
                        <i class="fas fa-briefcase" aria-hidden="true"></i>
                        Project
                    </label>
                    <select id="releaseProjectFilter" name="project_id" class="form-select" onchange="this.form.submit()">
                        <?php
                        $hasClientProjects = false;
                        foreach ($projectOptions as $project):
                            if ((int) $project['client_id'] !== (int) $selectedClientId) {
                                continue;
                            }
                            $hasClientProjects = true;
                        ?>
                            <option value="<?php echo (int) $project['id']; ?>" <?php echo (int) $project['id'] === (int) $selectedProjectId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$hasClientProjects): ?>
                            <option value="">No projects available for this client</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="release-filter-field">
                    <label for="releaseStatusFilter" class="form-label">
                        <i class="fas fa-filter" aria-hidden="true"></i>
                        Status <span class="filter-optional">(Optional)</span>
                    </label>
                    <select id="releaseStatusFilter" name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $selectedReleaseStatus === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="not_started" <?php echo $selectedReleaseStatus === 'not_started' ? 'selected' : ''; ?>>Not started</option>
                        <option value="in_progress" <?php echo $selectedReleaseStatus === 'in_progress' ? 'selected' : ''; ?>>In progress</option>
                        <option value="complete" <?php echo $selectedReleaseStatus === 'complete' ? 'selected' : ''; ?>>Complete</option>
                    </select>
                </div>

                <div class="release-filter-field">
                    <label for="releaseTimelineFilter" class="form-label">
                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                        Timeline <span class="filter-optional">(Optional)</span>
                    </label>
                    <select id="releaseTimelineFilter" name="timeline" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $selectedReleaseTimeline === 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="overdue" <?php echo $selectedReleaseTimeline === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        <option value="next_7_days" <?php echo $selectedReleaseTimeline === 'next_7_days' ? 'selected' : ''; ?>>Next 7 days</option>
                        <option value="next_30_days" <?php echo $selectedReleaseTimeline === 'next_30_days' ? 'selected' : ''; ?>>Next 30 days</option>
                        <option value="no_target_date" <?php echo $selectedReleaseTimeline === 'no_target_date' ? 'selected' : ''; ?>>No target date</option>
                    </select>
                </div>

                <div class="release-filter-actions">
                    <a
                        href="<?php echo htmlspecialchars($clearReleaseFiltersUrl); ?>"
                        class="release-clear-filters <?php echo $activeReleaseFilterCount === 0 ? 'is-disabled' : ''; ?>"
                        <?php echo $activeReleaseFilterCount === 0 ? 'aria-disabled="true"' : ''; ?>
                    >
                        Clear Filters
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </a>
                    <span class="release-active-filter-count">
                        <strong><?php echo $activeReleaseFilterCount; ?></strong>
                        Active Filter<?php echo $activeReleaseFilterCount === 1 ? '' : 's'; ?>
                    </span>
                </div>
            </div>
        </form>

    <?php if ($selectedProject): ?>
        <div class="summary-grid">
            <div class="release-metric">
                <div class="summary-label">Completed</div>
                <div class="summary-value"><?php echo $projectCompleteCount; ?></div>
                <div class="summary-sub">Checklist items finished</div>
            </div>
            <div class="release-metric">
                <div class="summary-label">Pending</div>
                <div class="summary-value"><?php echo max(0, $projectTotalCount - $projectCompleteCount); ?></div>
                <div class="summary-sub">Still waiting for release</div>
            </div>
            <div class="release-metric">
                <div class="summary-label">Additional Requirements</div>
                <div class="summary-value"><?php echo $projectAdditionalCount; ?></div>
                <div class="summary-sub">Architect enhancement items</div>
            </div>
            <div class="release-metric">
                <div class="summary-label">Checklist Progress</div>
                <div class="summary-value"><?php echo $projectProgress; ?>%</div>
                <div class="summary-sub">Architect checklist completion</div>
            </div>
        </div>

        <div class="checklist-card" id="insight-task-problems">
            <?php if (
                ($selectedProjectReceipt && (int) ($selectedProjectReceipt['receipt_id'] ?? 0) > 0)
                || ($canDownloadSelectedProjectRepository && $selectedProjectRepository)
            ): ?>
                <div class="release-toolbar">
                    <?php if ($selectedProjectReceipt && (int) ($selectedProjectReceipt['receipt_id'] ?? 0) > 0): ?>
                        <?php $selectedReceiptStatus = (string) ($selectedProjectReceipt['status'] ?? 'draft'); ?>
                        <a
                            class="release-receipt-btn"
                            href="<?php echo htmlspecialchars(BASE_URL . 'modules/sales/receipt.php?receipt_id=' . (int) $selectedProjectReceipt['receipt_id']); ?>"
                            title="Open receipt for Sales review"
                        >
                            <i class="fas fa-receipt"></i>
                            <?php echo $selectedReceiptStatus === 'draft' ? 'Review Receipt' : 'Final Receipt'; ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($canDownloadSelectedProjectRepository && $selectedProjectRepository): ?>
                        <a
                            class="release-download-btn"
                            href="<?php echo htmlspecialchars(BASE_URL . 'api/version-control/download-repository.php?repository_id=' . (int) $selectedProjectRepository['id']); ?>"
                            title="Download <?php echo htmlspecialchars((string) (($selectedProjectRepository['default_branch_name'] ?? '') ?: 'main')); ?> branch code"
                        >
                            <i class="fas fa-download"></i>
                            Download Main Branch
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="checklist-body">
                <div class="checklist-section">
                    <div class="section-title">
                        <h6>Features from Architect</h6>
                        <small>
                            <?php echo count($filteredFeatureItems); ?>
                            <?php echo $activeReleaseFilterCount > 0 ? 'of ' . count($featureItems) : ''; ?>
                            features
                        </small>
                    </div>
                    <?php if ($filteredFeatureItems): ?>
                        <div class="feature-list">
                            <div class="feature-list-head" aria-hidden="true">
                                <span>Feature</span>
                                <span>Owner</span>
                                <span>Deadline</span>
                                <span>Status</span>
                            </div>
                            <?php foreach ($filteredFeatureItems as $feature): ?>
                                <?php
                                $featureDescription = trim((string) ($feature['description'] ?? ''));
                                $showFeatureDescription = $featureDescription !== ''
                                    && strcasecmp($featureDescription, 'Requested by Sales during client intake.') !== 0
                                    && strcasecmp($featureDescription, 'No description provided.') !== 0;
                                ?>
                                <div class="feature-item <?php echo $feature['is_complete'] ? 'is-complete' : ''; ?>">
                                    <div class="feature-main">
                                        <span class="feature-state-icon <?php echo $feature['is_complete'] ? 'complete' : ''; ?>" aria-hidden="true">
                                            <i class="fas <?php echo $feature['is_complete'] ? 'fa-check' : 'fa-clock'; ?>"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="feature-title"><?php echo htmlspecialchars((string) $feature['title']); ?></div>
                                            <?php if ($showFeatureDescription): ?>
                                                <div class="feature-description"><?php echo htmlspecialchars($featureDescription); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="feature-owner <?php echo empty($feature['developer_name']) ? 'unassigned' : ''; ?>">
                                        <i class="fas <?php echo !empty($feature['developer_name']) ? 'fa-user' : 'fa-user-slash'; ?>"></i>
                                        <span><?php echo !empty($feature['developer_name']) ? htmlspecialchars((string) $feature['developer_name']) : 'Unassigned'; ?></span>
                                    </div>
                                    <div class="feature-deadline <?php echo empty($feature['effective_deadline']) ? 'missing' : ''; ?>">
                                        <i class="fas fa-calendar-day"></i>
                                        <span><?php echo !empty($feature['effective_deadline']) ? htmlspecialchars(date('M d, Y', strtotime((string) $feature['effective_deadline']))) : 'No target date'; ?></span>
                                    </div>
                                    <span class="feature-status <?php echo $feature['is_complete'] ? 'complete' : 'pending'; ?>">
                                        <?php echo htmlspecialchars((string) $feature['status_label']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas <?php echo $activeReleaseFilterCount > 0 ? 'fa-filter' : 'fa-clipboard-list'; ?>"></i>
                            <p class="mb-0">
                                <?php echo $activeReleaseFilterCount > 0
                                    ? 'No features match the selected status and timeline filters.'
                                    : 'No architect features have been defined for this project yet.'; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($additionalItems): ?>
                    <div class="checklist-section">
                        <div class="section-title">
                            <h6>Additional Requirements from Architect</h6>
                            <small>
                                <?php echo count($filteredAdditionalItems); ?>
                                <?php echo $activeReleaseFilterCount > 0 ? 'of ' . count($additionalItems) : ''; ?>
                                items
                            </small>
                        </div>
                        <?php if ($filteredAdditionalItems): ?>
                            <div class="feature-list">
                                <div class="feature-list-head" aria-hidden="true">
                                    <span>Requirement</span>
                                    <span>Owner</span>
                                    <span>Deadline</span>
                                    <span>Status</span>
                                </div>
                            <?php foreach ($filteredAdditionalItems as $feature): ?>
                                <?php
                                $featureDescription = trim((string) ($feature['description'] ?? ''));
                                $showFeatureDescription = $featureDescription !== ''
                                    && strcasecmp($featureDescription, 'Requested by Sales during client intake.') !== 0
                                    && strcasecmp($featureDescription, 'No description provided.') !== 0;
                                ?>
                                <div class="feature-item <?php echo $feature['is_complete'] ? 'is-complete' : ''; ?>">
                                    <div class="feature-main">
                                        <span class="feature-state-icon <?php echo $feature['is_complete'] ? 'complete' : ''; ?>" aria-hidden="true">
                                            <i class="fas <?php echo $feature['is_complete'] ? 'fa-check' : 'fa-clock'; ?>"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="feature-title"><?php echo htmlspecialchars((string) $feature['title']); ?></div>
                                            <?php if ($showFeatureDescription): ?>
                                                <div class="feature-description"><?php echo htmlspecialchars($featureDescription); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="feature-owner <?php echo empty($feature['developer_name']) ? 'unassigned' : ''; ?>">
                                        <i class="fas <?php echo !empty($feature['developer_name']) ? 'fa-user' : 'fa-user-slash'; ?>"></i>
                                        <span><?php echo !empty($feature['developer_name']) ? htmlspecialchars((string) $feature['developer_name']) : 'Unassigned'; ?></span>
                                    </div>
                                    <div class="feature-deadline <?php echo empty($feature['effective_deadline']) ? 'missing' : ''; ?>">
                                        <i class="fas fa-calendar-day"></i>
                                        <span><?php echo !empty($feature['effective_deadline']) ? htmlspecialchars(date('M d, Y', strtotime((string) $feature['effective_deadline']))) : 'No target date'; ?></span>
                                    </div>
                                    <span class="feature-status <?php echo $feature['is_complete'] ? 'complete' : 'pending'; ?>">
                                        <?php echo htmlspecialchars((string) $feature['status_label']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-filter"></i>
                                <p class="mb-0">No additional requirements match the selected filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($additionalRequirementsText !== ''): ?>
                    <div class="checklist-section">
                        <div class="section-title">
                            <h6>Additional Requirements Notes</h6>
                            <small>From Sales intake / project brief</small>
                        </div>
                        <div class="requirements-box"><?php echo htmlspecialchars($additionalRequirementsText); ?></div>
                    </div>
                <?php endif; ?>

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
    </section>
</div>

<?php include '../../includes/footer.php'; ?>
