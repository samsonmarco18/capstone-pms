<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $project) {
    $features = $_POST['new_features'] ?? [];
    $existingFeatureTitles = [];

    $existingTitleResult = $conn->query("
        SELECT title
        FROM tasks
        WHERE project_id = $projectId
          AND task_type = 'feature'
    ");

    if ($existingTitleResult !== false) {
        while ($row = $existingTitleResult->fetch_assoc()) {
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
            'saved_count' => $savedCount,
            'skipped_duplicate_count' => $skippedCount,
        ]);
        $conn->commit();
        if ($savedCount > 0) {
            $_SESSION['success'] = $savedCount . ' new feature' . ($savedCount === 1 ? '' : 's') . ' saved successfully.';
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
        SELECT id, title, description, priority
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
if ($project) {
    foreach (architectFeatureParseSalesItems($project['requested_features'] ?? '') as $salesFeature) {
        if (!isset($existingFeatureLookup[architectFeatureNormalizeTitle($salesFeature)])) {
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

.saved-feature {
    display: grid;
    grid-template-columns: minmax(180px, 1fr) minmax(220px, 1.4fr);
    gap: 0.75rem;
    align-items: start;
    background: #ffffff;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.9rem 1rem;
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

.sales-requested-list {
    display: grid;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.sales-requested-feature {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0.85rem;
    align-items: center;
    background: #f8fbfd;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.9rem 1rem;
}

.sales-requested-feature .form-check {
    display: flex;
    gap: 0.65rem;
    align-items: flex-start;
    margin: 0;
}

.sales-requested-feature .form-check-input {
    margin-top: 0.15rem;
}

.sales-requested-title {
    color: var(--gray-900);
    font-weight: 700;
    word-break: break-word;
}

.sales-requested-meta {
    color: var(--gray-600);
    font-size: 0.78rem;
    margin-top: 0.2rem;
}

.additional-features {
    border-top: 1px solid var(--gray-200);
    padding-top: 1.25rem;
}

.btn-add {
    background: transparent;
    border: 1px dashed var(--gray-600);
    color: var(--gray-600);
    padding: 0.5rem 1rem;
    border-radius: 40px;
}

.btn-save {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.7rem 1.5rem;
    border-radius: 40px;
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
    .sales-requested-feature { grid-template-columns: 1fr; }
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

    <form method="POST" class="card p-4">
        <?php if (count($existingFeatures) > 0): ?>
            <div class="feature-section-title">
                <i class="fas fa-check-circle text-success"></i>
                Saved Features
            </div>
            <div class="saved-feature-list">
                <?php foreach ($existingFeatures as $feature): ?>
                    <div class="saved-feature">
                        <div>
                            <div class="saved-feature-title"><?php echo htmlspecialchars($feature['title']); ?></div>
                        </div>
                        <div class="saved-feature-description">
                            <?php echo htmlspecialchars(trim((string) $feature['description']) !== '' ? $feature['description'] : 'No description added.'); ?>
                        </div>
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
                            disabled
                        >
                        <input
                            type="hidden"
                            name="new_features[<?php echo $index; ?>][description]"
                            value="Requested by Sales during client intake."
                            data-sales-feature-field
                            disabled
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
            <div id="featuresContainer">
                <div class="feature-item">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Feature Title</label>
                            <input type="text" name="new_features[<?php echo count($salesRequestedFeatures); ?>][title]" class="form-control" <?php echo count($existingFeatures) === 0 && count($salesRequestedFeatures) === 0 ? 'required' : ''; ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Description</label>
                            <input type="text" name="new_features[<?php echo count($salesRequestedFeatures); ?>][description]" class="form-control">
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

        <div class="d-flex justify-content-between flex-wrap gap-2 mt-3">
            <button type="button" class="btn-add" onclick="addFeature()">
                <i class="fas fa-plus me-1"></i> Add Feature
            </button>
            <button type="submit" class="btn-save">
                <i class="fas fa-arrow-right me-1"></i> Save Features & Continue
            </button>
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
                <label class="form-label">Feature Title</label>
                <input type="text" name="new_features[${featureIndex}][title]" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Description</label>
                <input type="text" name="new_features[${featureIndex}][description]" class="form-control">
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
    document.querySelectorAll('[data-sales-feature-toggle]').forEach((checkbox) => {
        const wrapper = checkbox.closest('[data-sales-requested-feature]');
        const fields = wrapper ? Array.from(wrapper.querySelectorAll('[data-sales-feature-field]')) : [];

        function syncSalesFeatureFields() {
            fields.forEach((field) => {
                field.disabled = !checkbox.checked;
            });
        }

        checkbox.addEventListener('change', syncSalesFeatureFields);
        syncSalesFeatureFields();
    });
    updateFeatureIndexes();
    updateRemoveButtons();
});
</script>

<?php include '../../includes/footer.php'; ?>
