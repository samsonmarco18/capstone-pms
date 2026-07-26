<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-template.php';
require_once '../../includes/project-compilation.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
projectTemplateEnsureSchema($conn);
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pageError = null;

function architectFeatureNormalizeTitle(string $title): string
{
    return strtolower(preg_replace('/\s+/', ' ', trim($title)));
}

function architectFeatureParseSalesItems(?string $rawFeatures): array
{
    $raw = trim((string) $rawFeatures);
    if ($raw === '') {
        return [];
    }

    $items = [];
    $seen = [];
    $lines = preg_split('/\R/', $raw) ?: [];

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^(additional requirements|budget)\s*:/i', $line)) {
            break;
        }

        $line = preg_replace('/^\s*(?:[-*]|\d+[\.)])\s*/', '', $line);
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $normalized = architectFeatureNormalizeTitle($line);
        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }

        $seen[$normalized] = true;
        $items[] = $line;
    }

    return $items;
}

$projectResult = $conn->query("
    SELECT p.*, c.name AS client_name
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    WHERE p.id = $projectId
");

if ($projectResult === false) {
    error_log('Architect features project query failed: ' . ($conn->error ?? 'unknown error'));
    $pageError = 'Unable to load the project right now.';
}

$project = $projectResult ? $projectResult->fetch_assoc() : null;
if (!$project && $pageError === null) {
    header('Location: projects.php');
    exit();
}

$projectTemplate = null;
$templateFeatureLookup = [];
if ($project) {
    $projectTemplate = projectTemplateResolveForProject($conn, $project);
    if (is_array($projectTemplate)) {
        foreach (($projectTemplate['feature_items'] ?? []) as $templateFeature) {
            $normalizedFeature = architectFeatureNormalizeTitle((string) $templateFeature);
            if ($normalizedFeature !== '') {
                $templateFeatureLookup[$normalizedFeature] = true;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $project) {
    $features = $_POST['new_features'] ?? [];
    $existingFeatureControls = $_POST['existing_features'] ?? [];
    if (!is_array($existingFeatureControls)) {
        $existingFeatureControls = [];
    }
    $existingFeatureTitles = [];
    $existingFeatureRows = [];

    $existingTitleResult = $conn->query("
        SELECT id, title, description, priority, status, assigned_to
        FROM tasks
        WHERE project_id = $projectId
          AND task_type = 'feature'
    ");

    if ($existingTitleResult !== false) {
        while ($row = $existingTitleResult->fetch_assoc()) {
            $featureId = (int) ($row['id'] ?? 0);
            if ($featureId > 0) {
                $existingFeatureRows[$featureId] = $row;
            }
            $normalizedTitle = architectFeatureNormalizeTitle((string) ($row['title'] ?? ''));
            if ($normalizedTitle !== '') {
                $existingFeatureTitles[$normalizedTitle] = true;
            }
        }
    }

    $conn->begin_transaction();
    try {
        $savedCount = 0;
        $skippedCount = 0;
        $updatedCount = 0;
        $removedCount = 0;
        $lockedCount = 0;

        foreach ($existingFeatureControls as $featureId => $control) {
            $featureId = (int) $featureId;
            if ($featureId <= 0 || !is_array($control) || !isset($existingFeatureRows[$featureId])) {
                continue;
            }

            $existingRow = $existingFeatureRows[$featureId];
            $oldTitle = trim((string) ($existingRow['title'] ?? ''));
            $oldNormalizedTitle = architectFeatureNormalizeTitle($oldTitle);
            $status = (string) ($existingRow['status'] ?? 'todo');
            $isEditable = empty($existingRow['assigned_to']) && $status === 'todo';
            if (!$isEditable) {
                $lockedCount++;
                continue;
            }

            $includeFeature = (string) ($control['include'] ?? '') === '1';
            if (!$includeFeature) {
                $deleteStmt = $conn->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ? AND task_type = 'feature' AND status = 'todo' AND (assigned_to IS NULL OR assigned_to = 0)");
                if ($deleteStmt === false) {
                    throw new RuntimeException('Failed to prepare feature removal.');
                }
                $deleteStmt->bind_param('ii', $featureId, $projectId);
                if (!$deleteStmt->execute()) {
                    $deleteStmt->close();
                    throw new RuntimeException('Failed to remove saved feature.');
                }
                $removedCount += $deleteStmt->affected_rows > 0 ? 1 : 0;
                $deleteStmt->close();
                if ($oldNormalizedTitle !== '') {
                    unset($existingFeatureTitles[$oldNormalizedTitle]);
                }
                continue;
            }

            $title = trim((string) ($control['title'] ?? $oldTitle));
            $description = trim((string) ($control['description'] ?? ($existingRow['description'] ?? '')));
            if ($title === '') {
                $title = $oldTitle;
            }

            $normalizedTitle = architectFeatureNormalizeTitle($title);
            if ($normalizedTitle !== $oldNormalizedTitle && isset($existingFeatureTitles[$normalizedTitle])) {
                $skippedCount++;
                continue;
            }

            $updateStmt = $conn->prepare("
                UPDATE tasks
                SET title = ?, description = ?
                WHERE id = ? AND project_id = ? AND task_type = 'feature' AND status = 'todo' AND (assigned_to IS NULL OR assigned_to = 0)
            ");
            if ($updateStmt === false) {
                throw new RuntimeException('Failed to prepare saved feature update.');
            }
            $updateStmt->bind_param('ssii', $title, $description, $featureId, $projectId);
            if (!$updateStmt->execute()) {
                $updateStmt->close();
                throw new RuntimeException('Failed to update saved feature.');
            }
            if ($updateStmt->affected_rows > 0) {
                $updatedCount++;
            }
            $updateStmt->close();

            if ($oldNormalizedTitle !== '') {
                unset($existingFeatureTitles[$oldNormalizedTitle]);
            }
            if ($normalizedTitle !== '') {
                $existingFeatureTitles[$normalizedTitle] = true;
            }
        }

        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            if (array_key_exists('include', $feature) && (string) ($feature['include'] ?? '') !== '1') {
                continue;
            }

            $title = trim((string) ($feature['title'] ?? ''));
            $description = trim((string) ($feature['description'] ?? ''));
            $priority = strtolower(trim((string) ($feature['priority'] ?? 'medium')));

            if ($title === '') {
                continue;
            }

            if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
                $priority = 'medium';
            }

            $normalizedTitle = architectFeatureNormalizeTitle($title);
            if (isset($existingFeatureTitles[$normalizedTitle])) {
                $skippedCount++;
                continue;
            }

            $stmt = $conn->prepare("
                INSERT INTO tasks (project_id, title, description, task_type, priority, status, assigned_by)
                VALUES (?, ?, ?, 'feature', ?, 'todo', ?)
            ");

            if ($stmt === false) {
                throw new RuntimeException('Failed to prepare feature insert.');
            }

            $stmt->bind_param('isssi', $projectId, $title, $description, $priority, $user['id']);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Failed to save feature.');
            }
            $stmt->close();
            $existingFeatureTitles[$normalizedTitle] = true;
            $savedCount++;
        }

        if (count($existingFeatureTitles) === 0) {
            throw new RuntimeException('At least one feature is required.');
        }

        logActivity($user['id'], 'define_features', 'projects', $projectId, null, [
            'new_features' => $features,
            'existing_features' => $existingFeatureControls,
            'saved_count' => $savedCount,
            'updated_count' => $updatedCount,
            'removed_count' => $removedCount,
            'locked_count' => $lockedCount,
            'skipped_duplicate_count' => $skippedCount,
        ]);
        $conn->commit();
        $compilationNo = projectCompilationRecord($conn, $projectId, (int) $user['id'], 'features');
        $changes = [];
        if ($savedCount > 0) {
            $changes[] = $savedCount . ' new';
        }
        if ($updatedCount > 0) {
            $changes[] = $updatedCount . ' updated';
        }
        if ($removedCount > 0) {
            $changes[] = $removedCount . ' removed';
        }

        if ($changes !== []) {
            $_SESSION['success'] = 'Features saved: ' . implode(', ', $changes) . '.';
        } else {
            $_SESSION['success'] = $skippedCount > 0
                ? 'Duplicate feature titles were skipped. Existing saved features were kept.'
                : 'Saved features were kept. Continuing to technology recommendations.';
        }
        header('Location: tech-recommendation.php?id=' . $projectId);
        exit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Architect features save failed: ' . $e->getMessage());
        $_SESSION['error'] = 'Failed to save features. Please try again.';
    }
}

$existingFeatures = [];
$existingFeatureLookup = [];
if ($project) {
    $featureResult = $conn->query("
        SELECT id, title, description, priority, status, assigned_to
        FROM tasks
        WHERE project_id = $projectId
          AND task_type = 'feature'
        ORDER BY id ASC
    ");

    if ($featureResult === false) {
        error_log('Architect features list query failed: ' . ($conn->error ?? 'unknown error'));
        $pageError = 'Feature records are unavailable right now. You can still add new features below.';
    } else {
        while ($row = $featureResult->fetch_assoc()) {
            $existingFeatures[] = $row;
            $normalizedTitle = architectFeatureNormalizeTitle((string) ($row['title'] ?? ''));
            if ($normalizedTitle !== '') {
                $existingFeatureLookup[$normalizedTitle] = true;
            }
        }
    }
}

$salesRequestedFeatures = [];
$salesFeatureLookup = [];
if ($project) {
    foreach (architectFeatureParseSalesItems($project['requested_features'] ?? '') as $salesFeature) {
        $normalizedSalesFeature = architectFeatureNormalizeTitle($salesFeature);
        if ($normalizedSalesFeature !== '') {
            $salesFeatureLookup[$normalizedSalesFeature] = true;
        }
        if (!isset($existingFeatureLookup[$normalizedSalesFeature])) {
            $salesRequestedFeatures[] = $salesFeature;
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
    --gray-400: #ced4da;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    color: white;
}

.card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.feature-item {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.feature-section-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.85rem;
    color: var(--gray-900);
    font-size: 0.95rem;
    font-weight: 700;
}

.saved-feature-list {
    display: grid;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.saved-feature-columns {
    display: grid;
    grid-template-columns: 18px minmax(180px, 1fr) minmax(220px, 1.4fr);
    gap: 0.75rem;
    padding: 0 1rem;
    color: var(--gray-600);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    line-height: 1.3;
    text-transform: uppercase;
}

.saved-feature {
    display: grid;
    grid-template-columns: 18px minmax(180px, 1fr) minmax(220px, 1.4fr);
    gap: 0.75rem;
    align-items: start;
    background: #ffffff;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.9rem 1rem;
}

.saved-feature.is-locked {
    background: var(--gray-50);
}

.saved-feature-check {
    display: flex;
    align-items: center;
    min-height: 38px;
    padding-top: 0;
    padding-left: 0;
}

.saved-feature-check .form-check-input {
    float: none;
    margin: 0;
}

.saved-feature-field-label,
.additional-feature-field-label {
    display: none;
}

.saved-feature-title {
    color: var(--gray-900);
    font-weight: 700;
    word-break: break-word;
}

.saved-feature-description {
    color: var(--gray-600);
    font-size: 0.88rem;
    word-break: break-word;
}

.saved-feature-status-note {
    grid-column: 2 / -1;
}

.additional-feature-columns {
    padding: 0 1rem 0.45rem;
    color: var(--gray-600);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    line-height: 1.3;
    text-transform: uppercase;
}

.sales-requested-list {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}
.architect-dss-source-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
    padding: 1rem 1.1rem;
    border: 1px solid #b9e3f5;
    border-radius: 16px;
    background: linear-gradient(135deg, #f5fcff, #e8f7fd);
    color: #174f73;
    box-shadow: 0 8px 22px rgba(31, 99, 146, .08);
}
.architect-dss-source-controls strong { color: #0d5f8f; }
.architect-dss-source-controls span { color: #52758d; font-size: .78rem; }
.saved-feature.is-recommended { border-color: #9edcf3; background: linear-gradient(135deg, #ffffff, #f1fbff); }
.saved-feature-recommendation { display: inline-flex; align-items: center; gap: .3rem; margin-bottom: .35rem; padding: .2rem .48rem; border-radius: 999px; background: #e2f5fd; color: #126b9b; font-size: .64rem; font-weight: 800; }
.saved-feature-list {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .75rem;
    margin-bottom: 1.25rem;
}
.saved-feature-columns { display: none !important; }
.saved-feature {
    display: flex;
    align-items: center;
    gap: .65rem;
    min-height: 70px;
    padding: .8rem 1rem;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 4px 12px rgba(15, 76, 129, .035);
}
.saved-feature:hover { border-color: #9edcf3; box-shadow: 0 8px 18px rgba(31, 99, 146, .08); }
.saved-feature-check { flex: 0 0 auto; margin: 0; }
.saved-feature-check .form-check-input { margin: 0; }
.saved-feature > div:nth-of-type(2) { min-width: 0; flex: 1; }
.saved-feature > div:nth-of-type(3),
.saved-feature-field-label,
.saved-feature [data-existing-feature-field],
.saved-feature-recommendation,
.saved-feature-status-note { display: none !important; }
.saved-feature-display-title { display: block; color: var(--gray-900); font-size: .88rem; font-weight: 650; line-height: 1.3; word-break: break-word; }
.saved-feature.is-locked { opacity: .72; }
@media (max-width: 1100px) { .saved-feature-list { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 820px) { .saved-feature-list { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .saved-feature-list { grid-template-columns: 1fr; } }
@media (max-width: 720px) {
    .architect-dss-source-controls { align-items: stretch; flex-direction: column; }
    .architect-dss-source-controls .d-flex { width: 100%; }
    .architect-dss-source-controls .btn { flex: 1; }
}

.sales-requested-feature {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0.5rem;
    align-items: center;
    min-height: 56px;
    background: #ffffff;
    border: 1px solid var(--gray-200);
    border-radius: 10px;
    padding: 0.7rem 0.8rem;
    box-shadow: 0 4px 12px rgba(15, 76, 129, 0.035);
}

.sales-requested-feature .form-check {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    width: 100%;
    margin: 0;
}

.sales-requested-feature .form-check-input {
    flex: 0 0 auto;
    margin-top: 0;
}

.sales-requested-title {
    color: var(--gray-900);
    font-size: 0.86rem;
    font-weight: 600;
    word-break: break-word;
}

.sales-requested-meta {
    display: none !important;
}

.additional-features {
    border-top: 1px solid var(--gray-200);
    padding-top: 1.25rem;
}

.feature-action-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 1.25rem;
    padding-top: 1.1rem;
    border-top: 1px solid var(--gray-200);
}

.feature-action-primary {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.65rem;
}

.btn-add {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    background: transparent;
    border: 1px dashed var(--gray-600);
    color: var(--gray-600);
    padding: 0.65rem 1rem;
    border-radius: 40px;
    font-size: 0.82rem;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-add:hover {
    border-color: var(--theme-primary);
    background: rgba(31, 99, 146, 0.07);
    color: var(--theme-primary);
}

.btn-save {
    order: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    min-height: 42px;
    padding: 0.68rem 1.35rem;
    border-radius: 40px;
    font-size: 0.82rem;
    font-weight: 700;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.18);
    transition: all 0.2s ease;
    white-space: nowrap;
}

.btn-save:hover {
    transform: translateY(-1px);
    color: #ffffff;
    box-shadow: 0 11px 22px rgba(31, 99, 146, 0.26);
}

.feature-action-primary [data-generic-save-draft] {
    order: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    margin: 0 !important;
    padding: 0.62rem 1rem;
    border-color: rgba(31, 99, 146, 0.45);
    border-radius: 40px;
    background: #ffffff;
    color: var(--theme-primary);
    font-size: 0.78rem;
    font-weight: 650;
}

.feature-action-primary [data-generic-save-draft]:hover {
    border-color: var(--theme-primary);
    background: #eef7fd;
    color: var(--theme-dark);
}

.feature-action-primary [data-generic-draft-status] {
    order: 0;
    margin: 0 !important;
    color: #047857 !important;
    font-size: 0.7rem !important;
    font-weight: 700;
}

.feature-action-primary [data-generic-draft-status]:empty {
    display: none;
}

.btn-remove {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    width: 42px;
    height: 42px;
    padding: 0;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
}

.btn-remove:hover:not(:disabled) {
    background: #fecaca;
    color: #b91c1c;
    transform: translateY(-1px);
}

.btn-remove i {
    font-size: 0.95rem;
}

.btn-remove:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

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
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-400);
    margin: 0 0.25rem;
}

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

@media (max-width: 768px) {
    .page-header-modern { padding: 1.5rem; }
    .breadcrumb-modern { padding: 0.5rem 0.75rem; }
    .progress-indicator { padding: 0.75rem; }
    .progress-step .step-label { font-size: 0.6rem; }
    .progress-step .step-number { width: 24px; height: 24px; font-size: 0.7rem; }
    .saved-feature { grid-template-columns: 1fr; }
    .saved-feature-columns,
    .additional-feature-columns {
        display: none;
    }
    .saved-feature-field-label,
    .additional-feature-field-label {
        display: block;
    }
    .saved-feature-check {
        min-height: auto;
    }
    .saved-feature-status-note {
        grid-column: 1;
    }
    .sales-requested-feature { grid-template-columns: 1fr; }
    .feature-action-bar {
        align-items: stretch;
        flex-direction: column;
    }
    .feature-action-bar .btn-add {
        align-self: flex-start;
    }
    .feature-action-primary {
        display: grid;
        grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
        width: 100%;
    }
    .feature-action-primary [data-generic-draft-status] {
        grid-column: 1 / -1;
        text-align: right;
    }
    .feature-action-primary [data-generic-save-draft],
    .feature-action-primary .btn-save {
        width: 100%;
    }
}

@media (max-width: 1199.98px) {
    .sales-requested-list {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 991.98px) {
    .sales-requested-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 575.98px) {
    .sales-requested-list {
        grid-template-columns: minmax(0, 1fr);
    }
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-card {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-requested-feature,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .saved-feature,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) label {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .saved-feature-description,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .saved-feature-columns,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .additional-feature-columns,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .saved-feature-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-requested-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-section-title {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) textarea {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-action-bar {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-add {
    border-color: #6f8ba5;
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-add:hover {
    border-color: #8fd3ff;
    background: rgba(31, 99, 146, 0.18);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-action-primary [data-generic-save-draft] {
    border-color: rgba(86, 171, 224, 0.38);
    background: #0a1b2c;
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-action-primary [data-generic-save-draft]:hover {
    border-color: #8fd3ff;
    background: rgba(31, 99, 146, 0.22);
    color: #ffffff;
}
</style>

<div class="container-fluid px-4 py-4">
    <?php if ($pageError): ?>
        <div class="alert alert-warning"><?php echo htmlspecialchars($pageError); ?></div>
    <?php endif; ?>
    <?php if (!$project): ?>
        <div class="alert alert-warning">Project not found.</div>
    <?php else: ?>
    <div class="page-header-modern">
        <h2><i class="fas fa-list-check me-2"></i> Define Features</h2>
        <p class="mb-0">Project: <?php echo htmlspecialchars($project['name']); ?></p>
    </div>

    <nav aria-label="breadcrumb" class="breadcrumb-modern">
        <ol class="breadcrumb-modern" style="display: flex; flex-wrap: wrap; align-items: center; margin: 0; padding: 0; list-style: none;">
            <li class="breadcrumb-item"><a href="projects.php"><i class="fas fa-folder-open"></i> Projects</a></li>
            <li class="breadcrumb-item"><a href="project-review.php?id=<?php echo $projectId; ?>"><i class="fas fa-clipboard-list"></i> Project Review</a></li>
            <li class="breadcrumb-item active"><i class="fas fa-list-check"></i> Features</li>
        </ol>
    </nav>

    <div class="progress-indicator">
        <a class="progress-step completed" href="project-review.php?id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Project Review</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step active" href="features.php?id=<?php echo $projectId; ?>"><div class="step-number">2</div><div class="step-label">Features</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="tech-recommendation.php?id=<?php echo $projectId; ?>"><div class="step-number">3</div><div class="step-label">Technology</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="developer-allocation.php?id=<?php echo $projectId; ?>"><div class="step-number">4</div><div class="step-label">Developers</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="../roadmap/methodology.php?project_id=<?php echo $projectId; ?>"><div class="step-number">5</div><div class="step-label">Roadmap</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="upload-diagram.php?id=<?php echo $projectId; ?>"><div class="step-number">6</div><div class="step-label">Documents</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="summary.php?id=<?php echo $projectId; ?>"><div class="step-number">7</div><div class="step-label">Summary</div></a>
    </div>

    <?php include '../../includes/architect-project-review-panel.php'; ?>

    <?php if (count($salesRequestedFeatures) > 0): ?>
    <div class="architect-dss-source-controls">
        <div><strong><i class="fas fa-robot me-1"></i> DSS feature review</strong><br><span>The assistant found <?php echo count($salesRequestedFeatures); ?> intake feature(s). Accept or skip them before saving; none are added automatically.</span></div>
        <div class="d-flex gap-2"><button type="button" class="btn btn-primary btn-sm" data-dss-feature-decision="accept">Accept suggestions</button><button type="button" class="btn btn-outline-secondary btn-sm" data-dss-feature-decision="skip">Skip suggestions</button></div>
    </div>
    <?php endif; ?>

    <form method="POST" class="card p-4">
        <?php if (count($existingFeatures) > 0): ?>
            <div class="feature-section-title">
                <i class="fas fa-check-circle text-success"></i>
                Saved Features
            </div>
            <div class="saved-feature-list">
                <div class="saved-feature-columns" aria-hidden="true">
                    <span></span>
                    <span>Feature Title</span>
                    <span>Description</span>
                </div>
                <?php foreach ($existingFeatures as $feature): ?>
                    <?php
                        $featureId = (int) ($feature['id'] ?? 0);
                        $featureTitle = (string) ($feature['title'] ?? '');
                        $featureDescription = (string) ($feature['description'] ?? '');
                        $featureStatus = (string) ($feature['status'] ?? 'todo');
                        $isFeatureEditable = empty($feature['assigned_to']) && $featureStatus === 'todo';
                        $normalizedFeatureTitle = architectFeatureNormalizeTitle($featureTitle);
                        $isRecommendedFeature = isset($templateFeatureLookup[$normalizedFeatureTitle]) || isset($salesFeatureLookup[$normalizedFeatureTitle]);
                    ?>
                    <div class="saved-feature <?php echo $isFeatureEditable ? '' : 'is-locked'; ?> <?php echo $isRecommendedFeature ? 'is-recommended' : ''; ?>" data-existing-feature>
                        <div class="form-check saved-feature-check">
                            <input type="hidden" name="existing_features[<?php echo $featureId; ?>][include]" value="<?php echo $isFeatureEditable ? '0' : '1'; ?>">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                value="1"
                                name="existing_features[<?php echo $featureId; ?>][include]"
                                id="existingFeature<?php echo $featureId; ?>"
                                data-existing-feature-toggle
                                data-recommended-feature="<?php echo $isRecommendedFeature ? htmlspecialchars($featureTitle) : ''; ?>"
                                checked
                                <?php echo $isFeatureEditable ? '' : 'disabled'; ?>
                            >
                        </div>
                        <div>
                            <?php if ($isRecommendedFeature): ?><span class="saved-feature-recommendation"><i class="fas fa-wand-magic-sparkles"></i> Evortix recommended</span><?php endif; ?>
                            <span class="saved-feature-display-title"><?php echo htmlspecialchars($featureTitle); ?></span>
                            <label class="form-label saved-feature-field-label" for="existingFeatureTitle<?php echo $featureId; ?>">Feature Title</label>
                            <input
                                type="text"
                                class="form-control"
                                id="existingFeatureTitle<?php echo $featureId; ?>"
                                name="existing_features[<?php echo $featureId; ?>][title]"
                                value="<?php echo htmlspecialchars($featureTitle); ?>"
                                data-existing-feature-field
                                <?php echo $isFeatureEditable ? '' : 'disabled'; ?>
                            >
                        </div>
                        <div>
                            <label class="form-label saved-feature-field-label" for="existingFeatureDescription<?php echo $featureId; ?>">Description</label>
                            <input
                                type="text"
                                class="form-control"
                                id="existingFeatureDescription<?php echo $featureId; ?>"
                                name="existing_features[<?php echo $featureId; ?>][description]"
                                value="<?php echo htmlspecialchars($featureDescription); ?>"
                                placeholder="No description added."
                                data-existing-feature-field
                                <?php echo $isFeatureEditable ? '' : 'disabled'; ?>
                            >
                        </div>
                        <?php if (!$isFeatureEditable): ?>
                            <small class="saved-feature-description saved-feature-status-note">Locked after assignment or progress.</small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (count($salesRequestedFeatures) > 0): ?>
            <div class="feature-section-title">
                <i class="fas fa-user-tie"></i>
                Sales Requested Features
            </div>
            <div class="sales-requested-list">
                <?php foreach ($salesRequestedFeatures as $index => $salesFeature): ?>
                    <div class="sales-requested-feature" data-sales-requested-feature>
                        <div class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                value="1"
                                name="new_features[<?php echo $index; ?>][include]"
                                id="salesFeature<?php echo $index; ?>"
                                data-sales-feature-toggle
                                data-recommended-feature="<?php echo htmlspecialchars($salesFeature); ?>"
                                checked
                            >
                            <label class="form-check-label" for="salesFeature<?php echo $index; ?>">
                                <span class="sales-requested-title"><?php echo htmlspecialchars($salesFeature); ?></span>
                                <span class="sales-requested-meta d-block">From Sales intake</span>
                            </label>
                        </div>
                        <input
                            type="hidden"
                            name="new_features[<?php echo $index; ?>][title]"
                            value="<?php echo htmlspecialchars($salesFeature); ?>"
                            data-sales-feature-field
                                <?php echo isset($existingFeatureLookup[architectFeatureNormalizeTitle($salesFeature)]) ? '' : 'disabled'; ?>
                        >
                        <input
                            type="hidden"
                            name="new_features[<?php echo $index; ?>][description]"
                            value="Requested by Sales during client intake."
                            data-sales-feature-field
                                <?php echo isset($existingFeatureLookup[architectFeatureNormalizeTitle($salesFeature)]) ? '' : 'disabled'; ?>
                        >
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="additional-features">
            <div class="feature-section-title">
                <i class="fas fa-plus-circle"></i>
                Additional Features
            </div>
            <div class="additional-feature-columns" aria-hidden="true">
                <div class="row g-3">
                    <div class="col-md-5">Feature Title</div>
                    <div class="col-md-6">Description</div>
                    <div class="col-md-1 text-center">Action</div>
                </div>
            </div>
            <div id="featuresContainer">
                <div class="feature-item">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label additional-feature-field-label">Feature Title</label>
                            <input type="text" name="new_features[<?php echo count($salesRequestedFeatures); ?>][title]" class="form-control" aria-label="Feature title" <?php echo count($existingFeatures) === 0 && count($salesRequestedFeatures) === 0 ? 'required' : ''; ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label additional-feature-field-label">Description</label>
                            <input type="text" name="new_features[<?php echo count($salesRequestedFeatures); ?>][description]" class="form-control" aria-label="Feature description">
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn-remove" onclick="removeFeature(this)" aria-label="Remove feature">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="feature-action-bar">
            <button type="button" class="btn-add" onclick="addFeature()">
                <i class="fas fa-plus"></i> Add Feature
            </button>
            <div class="feature-action-primary">
                <button type="submit" class="btn-save">
                    Save Features &amp; Continue <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
const salesRequestedFeatureCount = <?php echo count($salesRequestedFeatures); ?>;
let featureIndex = salesRequestedFeatureCount + 1;
const hasSavedFeatures = <?php echo count($existingFeatures) > 0 ? 'true' : 'false'; ?>;

function addFeature() {
    const container = document.getElementById('featuresContainer');
    const item = document.createElement('div');
    item.className = 'feature-item';
    item.innerHTML = `
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label additional-feature-field-label">Feature Title</label>
                <input type="text" name="new_features[${featureIndex}][title]" class="form-control" aria-label="Feature title">
            </div>
            <div class="col-md-6">
                <label class="form-label additional-feature-field-label">Description</label>
                <input type="text" name="new_features[${featureIndex}][description]" class="form-control" aria-label="Feature description">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn-remove" onclick="removeFeature(this)" aria-label="Remove feature">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.appendChild(item);
    featureIndex++;
    updateFeatureIndexes();
    updateRemoveButtons();
}

function removeFeature(button) {
    const item = button.closest('.feature-item');
    const allItems = document.querySelectorAll('.feature-item');
    if (!item) return;
    if (allItems.length <= 1) {
        if (hasSavedFeatures) {
            item.remove();
            updateFeatureIndexes();
            updateRemoveButtons();
            return;
        }

        item.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field.tagName === 'SELECT') {
                field.selectedIndex = 0;
            } else {
                field.value = '';
            }
        });
        return;
    }
    item.remove();
    updateFeatureIndexes();
    updateRemoveButtons();
}

function updateFeatureIndexes() {
    const items = document.querySelectorAll('.feature-item');
    items.forEach((item, idx) => {
        const nextIndex = salesRequestedFeatureCount + idx;
        item.querySelectorAll('input, select, textarea').forEach((field) => {
            const name = field.getAttribute('name');
            if (!name) return;
            field.setAttribute('name', name.replace(/new_features\[\d+\]/, `new_features[${nextIndex}]`));
        });
    });
    featureIndex = salesRequestedFeatureCount + items.length;
}

function updateRemoveButtons() {
    const items = document.querySelectorAll('.feature-item');
    const disable = items.length <= 1 && !hasSavedFeatures;
    items.forEach((item) => {
        const btn = item.querySelector('.btn-remove');
        if (!btn) return;
        btn.disabled = disable;
        btn.title = disable ? 'At least one feature is required.' : 'Remove feature row';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-dss-feature-decision]').forEach((button) => {
        button.addEventListener('click', () => {
            const accept = button.dataset.dssFeatureDecision === 'accept';
            document.querySelectorAll('[data-sales-feature-toggle]').forEach((checkbox) => {
                checkbox.checked = accept;
                checkbox.closest('[data-sales-requested-feature]')?.querySelectorAll('[data-sales-feature-field]').forEach((field) => { field.disabled = !accept; });
            });
            window.appToast?.(accept ? 'Feature suggestions accepted' : 'Feature suggestions skipped', accept ? 'Review the selected features before continuing.' : 'No intake feature will be added.', accept ? 'success' : 'info');
        });
    });
    document.querySelectorAll('[data-existing-feature-toggle]').forEach((checkbox) => {
        const wrapper = checkbox.closest('[data-existing-feature]');
        const fields = wrapper ? Array.from(wrapper.querySelectorAll('[data-existing-feature-field]')) : [];

        function syncExistingFeatureFields() {
            fields.forEach((field) => {
                field.disabled = checkbox.disabled || !checkbox.checked;
            });
        }

        checkbox.addEventListener('change', async () => {
            if (!checkbox.checked && checkbox.dataset.recommendedFeature && checkbox.dataset.evortixDecision !== '1') {
                const suggestionName = checkbox.dataset.recommendedFeature || 'this feature';
                const shouldRemove = await window.evortixConfirm?.({
                    title: `Remove ${suggestionName}?`,
                    message: `I recommend this feature from the selected project template. Removing it may change the requirements and estimate.`,
                    cancelLabel: 'Keep feature',
                    confirmLabel: 'Remove feature'
                });
                if (!shouldRemove) {
                    checkbox.checked = true;
                }
            }
            syncExistingFeatureFields();
        });

        syncExistingFeatureFields();
    });

    document.querySelectorAll('[data-sales-feature-toggle]').forEach((checkbox) => {
        const wrapper = checkbox.closest('[data-sales-requested-feature]');
        const fields = wrapper ? Array.from(wrapper.querySelectorAll('[data-sales-feature-field]')) : [];

        function syncSalesFeatureFields() {
            fields.forEach((field) => {
                field.disabled = !checkbox.checked;
            });
        }

        checkbox.addEventListener('change', async () => {
            if (!checkbox.checked && checkbox.dataset.evortixDecision !== '1') {
                const suggestionName = checkbox.dataset.recommendedFeature || 'this feature';
                const shouldRemove = await window.evortixConfirm?.({
                    title: `Skip ${suggestionName}?`,
                    message: `Sales requested this feature during intake. Skipping it may leave the Architect plan incomplete.`,
                    cancelLabel: 'Keep feature',
                    confirmLabel: 'Skip feature'
                });
                if (!shouldRemove) {
                    checkbox.checked = true;
                }
            }
            syncSalesFeatureFields();
        });
        syncSalesFeatureFields();
    });
    updateFeatureIndexes();
    updateRemoveButtons();
});
</script>

<?php
    $architectDssItems = [];
    foreach ($salesRequestedFeatures as $index => $salesFeature) {
        if (isset($existingFeatureLookup[architectFeatureNormalizeTitle((string) $salesFeature)])) continue;
        $architectDssItems[] = [
            'label' => (string) $salesFeature,
            'detail' => 'Sales requested this feature during client intake. Include it in the Architect feature plan?',
            'selector' => '#salesFeature' . $index,
        ];
    }
    if ($architectDssItems !== []):
    $architectDssAssistantConfig = [
        'title' => 'Feature recommendations ready',
        'message' => 'I found ' . count($architectDssItems) . ' feature recommendation' . (count($architectDssItems) === 1 ? '' : 's') . ' from the Sales intake and project template. Review them one at a time.',
        'stateKey' => 'evortix.dss.features.project.' . (int) $projectId,
        'showSourceControls' => true,
        'items' => $architectDssItems,
        'completedTitle' => 'Feature review complete',
    ];
    include '../../includes/architect-dss-assistant.php';
    endif;
?>

<?php include '../../includes/footer.php'; ?>
