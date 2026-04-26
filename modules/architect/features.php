<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$project = $conn->query("
    SELECT p.*, c.name AS client_name
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    WHERE p.id = $projectId
")->fetch_assoc();

if (!$project) {
    header('Location: projects.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $project) {
    $features = $_POST['features'] ?? [];

    $conn->begin_transaction();
    try {
        $conn->query("
            DELETE FROM tasks 
            WHERE project_id = $projectId 
              AND task_type = 'feature'
              AND (assigned_to IS NULL OR assigned_to = 0)
              AND status = 'todo'
        ");

        foreach ($features as $feature) {
            $title = trim((string) ($feature['title'] ?? ''));
            $description = trim((string) ($feature['description'] ?? ''));
            $priority = $feature['priority'] ?? 'medium';
            if ($title === '') {
                continue;
            }
            $stmt = $conn->prepare("
                INSERT INTO tasks (project_id, title, description, task_type, priority, status, assigned_by) 
                VALUES (?, ?, ?, 'feature', ?, 'todo', ?)
            ");
            $stmt->bind_param("isssi", $projectId, $title, $description, $priority, $user['id']);
            $stmt->execute();
        }

        logActivity($user['id'], 'define_features', 'projects', $projectId, null, $_POST);
        $conn->commit();
        $_SESSION['success'] = "Features saved successfully.";
        header('Location: tech-recommendation.php?id=' . $projectId);
        exit();
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['error'] = "Failed to save features. Please try again.";
    }
}

$existingFeatures = [];
if ($project) {
    $featureResult = $conn->query("
        SELECT id, title, description, priority
        FROM tasks
        WHERE project_id = $projectId
          AND task_type = 'feature'
        ORDER BY id ASC
    ");
    while ($row = $featureResult->fetch_assoc()) {
        $existingFeatures[] = $row;
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
    font-size: 1rem;
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
</style>

<div class="container-fluid px-4 py-4">
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
        <div class="progress-step completed"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Project Review</div></div>
        <div class="progress-line completed"></div>
        <div class="progress-step active"><div class="step-number">2</div><div class="step-label">Features</div></div>
        <div class="progress-line"></div>
        <div class="progress-step"><div class="step-number">3</div><div class="step-label">Technology</div></div>
        <div class="progress-line"></div>
        <div class="progress-step"><div class="step-number">4</div><div class="step-label">Developers</div></div>
        <div class="progress-line"></div>
        <div class="progress-step"><div class="step-number">5</div><div class="step-label">Documents</div></div>
        <div class="progress-line"></div>
        <div class="progress-step"><div class="step-number">6</div><div class="step-label">Summary</div></div>
    </div>

    <form method="POST" class="card p-4">
        <div id="featuresContainer">
            <?php if (count($existingFeatures) > 0): ?>
                <?php foreach ($existingFeatures as $idx => $feature): ?>
                    <div class="feature-item">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Feature Title</label>
                                <input type="text" name="features[<?php echo $idx; ?>][title]" class="form-control" value="<?php echo htmlspecialchars($feature['title']); ?>" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Description</label>
                                <input type="text" name="features[<?php echo $idx; ?>][description]" class="form-control" value="<?php echo htmlspecialchars($feature['description']); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Priority</label>
                                <select name="features[<?php echo $idx; ?>][priority]" class="form-select">
                                    <?php foreach (['low','medium','high','critical'] as $priority): ?>
                                        <option value="<?php echo $priority; ?>" <?php echo $feature['priority'] === $priority ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($priority); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn-remove" onclick="removeFeature(this)" aria-label="Remove feature">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="feature-item">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Feature Title</label>
                            <input type="text" name="features[0][title]" class="form-control" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Description</label>
                            <input type="text" name="features[0][description]" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Priority</label>
                            <select name="features[0][priority]" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn-remove" onclick="removeFeature(this)" aria-label="Remove feature">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
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
let featureIndex = <?php echo max(0, count($existingFeatures)); ?>;

function addFeature() {
    const container = document.getElementById('featuresContainer');
    const item = document.createElement('div');
    item.className = 'feature-item';
    item.innerHTML = `
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Feature Title</label>
                <input type="text" name="features[${featureIndex}][title]" class="form-control" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Description</label>
                <input type="text" name="features[${featureIndex}][description]" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Priority</label>
                <select name="features[${featureIndex}][priority]" class="form-select">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                </select>
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
        item.querySelectorAll('input, select, textarea').forEach((field) => {
            const name = field.getAttribute('name');
            if (!name) return;
            field.setAttribute('name', name.replace(/features\[\d+\]/, `features[${idx}]`));
        });
    });
    featureIndex = items.length;
}

function updateRemoveButtons() {
    const items = document.querySelectorAll('.feature-item');
    const disable = items.length <= 1;
    items.forEach((item) => {
        const btn = item.querySelector('.btn-remove');
        if (!btn) return;
        btn.disabled = disable;
        btn.title = disable ? 'At least one feature is required.' : 'Remove feature';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    updateFeatureIndexes();
    updateRemoveButtons();
});
</script>

<?php include '../../includes/footer.php'; ?>

