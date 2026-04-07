<?php
require_once '../../includes/auth.php';
requireRole('expert');

$conn = getConnection();
$user = getCurrentUser();

// Check if project_phases column exists (auto-create if missing)
$hasProjectPhases = $conn->query("SHOW COLUMNS FROM projects LIKE 'project_phases'")->num_rows > 0;
if (!$hasProjectPhases) {
    try {
        $conn->query("ALTER TABLE projects ADD COLUMN project_phases LONGTEXT NULL");
        $hasProjectPhases = $conn->query("SHOW COLUMNS FROM projects LIKE 'project_phases'")->num_rows > 0;
    } catch (Throwable $e) {
        // Leave $hasProjectPhases as false; UI will show warning
    }
}

// Check if phase_name column exists in feature_estimations
$hasPhaseName = $conn->query("SHOW COLUMNS FROM feature_estimations LIKE 'phase_name'")->num_rows > 0;
if (!$hasPhaseName) {
    try {
        $conn->query("ALTER TABLE feature_estimations ADD COLUMN phase_name VARCHAR(150) NULL");
        $hasPhaseName = $conn->query("SHOW COLUMNS FROM feature_estimations LIKE 'phase_name'")->num_rows > 0;
    } catch (Throwable $e) {
        // Leave $hasPhaseName as false
    }
}

// Check if sales approval columns exist in tasks (auto-create if missing)
$hasSalesApproval = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_approved'")->num_rows > 0;
if (!$hasSalesApproval) {
    try {
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved TINYINT(1) DEFAULT 0");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_by INT NULL");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_at DATETIME NULL");
        $hasSalesApproval = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_approved'")->num_rows > 0;
    } catch (Throwable $e) {
        // Leave $hasSalesApproval as false; sales approval will be skipped
    }
}

// Get tasks assigned to this expert that are pending estimation (status = 'todo')
$tasks = $conn->query("
    SELECT t.*, p.name as project_name, p.id as project_id,
           p.timeline_start, p.timeline_end" . ($hasProjectPhases ? ", p.project_phases" : "") . "
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = {$user['id']} 
      AND t.task_type = 'feature'
      AND t.status IN ('todo', 'in_review')
    ORDER BY 
        CASE t.status WHEN 'todo' THEN 1 ELSE 2 END,
        t.deadline ASC
");

// Get projects for project setup (show all active projects)
$expertProjectsResult = $conn->query("
    SELECT p.id, p.name, p.timeline_start, p.timeline_end" . ($hasProjectPhases ? ", p.project_phases" : "") . "
    FROM projects p
    WHERE p.status != 'completed'
    ORDER BY p.name ASC
");

$expertProjects = [];
while ($row = $expertProjectsResult->fetch_assoc()) {
    $expertProjects[] = $row;
}

$projectIds = array_map(static fn($row) => (int) $row['id'], $expertProjects);
$teamCompositionsByProject = [];
$costEstimationsByProject = [];

if (!empty($projectIds)) {
    $projectIdList = implode(',', $projectIds);
    $teamResult = $conn->query("SELECT project_id, role, required_count, allocation_percentage FROM team_compositions WHERE project_id IN ($projectIdList)");
    while ($team = $teamResult->fetch_assoc()) {
        $pid = (int) $team['project_id'];
        if (!isset($teamCompositionsByProject[$pid])) {
            $teamCompositionsByProject[$pid] = [];
        }
        $teamCompositionsByProject[$pid][] = [
            'role' => $team['role'],
            'required_count' => (int) $team['required_count'],
            'allocation_percentage' => (int) $team['allocation_percentage'],
        ];
    }

    $costResult = $conn->query("SELECT project_id, component, azure_service, monthly_cost FROM cost_estimations WHERE project_id IN ($projectIdList)");
    while ($cost = $costResult->fetch_assoc()) {
        $pid = (int) $cost['project_id'];
        if (!isset($costEstimationsByProject[$pid])) {
            $costEstimationsByProject[$pid] = [];
        }
        $costEstimationsByProject[$pid][] = [
            'component' => $cost['component'],
            'azure_service' => $cost['azure_service'],
            'monthly_cost' => (float) $cost['monthly_cost'],
        ];
    }
}

// Handle Project Setup Update (phases + team composition + cost estimation)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['project_setup'])) {
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $phases = $_POST['phases_json'] ?? '[]';
    $teamJson = $_POST['team_json'] ?? '[]';
    $costJson = $_POST['cost_json'] ?? '[]';
    $projectStart = !empty($_POST['project_start']) ? $_POST['project_start'] : null;
    $projectEnd = !empty($_POST['project_end']) ? $_POST['project_end'] : null;

    if ($projectId > 0) {
        if ($hasProjectPhases) {
            $stmt = $conn->prepare("UPDATE projects SET project_phases = ?, timeline_start = ?, timeline_end = ? WHERE id = ?");
            $stmt->bind_param("sssi", $phases, $projectStart, $projectEnd, $projectId);
            $stmt->execute();
        }

        $teamData = json_decode($teamJson, true);
        if (is_array($teamData)) {
            $conn->query("DELETE FROM team_compositions WHERE project_id = $projectId");
            foreach ($teamData as $team) {
                $role = trim((string) ($team['role'] ?? ''));
                $required = (int) ($team['required_count'] ?? 0);
                $allocation = (int) ($team['allocation_percentage'] ?? 0);
                if ($role === '') {
                    continue;
                }
                $stmt = $conn->prepare("INSERT INTO team_compositions (project_id, role, required_count, allocation_percentage) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isii", $projectId, $role, $required, $allocation);
                $stmt->execute();
            }
        }

        $costData = json_decode($costJson, true);
        if (is_array($costData)) {
            $conn->query("DELETE FROM cost_estimations WHERE project_id = $projectId");
            foreach ($costData as $cost) {
                $component = trim((string) ($cost['component'] ?? ''));
                $service = trim((string) ($cost['azure_service'] ?? ''));
                $monthly = (float) ($cost['monthly_cost'] ?? 0);
                if ($component === '') {
                    continue;
                }
                $stmt = $conn->prepare("INSERT INTO cost_estimations (project_id, component, azure_service, monthly_cost) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("issd", $projectId, $component, $service, $monthly);
                $stmt->execute();
            }
        }

        logActivity($user['id'], 'project_setup', 'projects', $projectId, null, [
            'project_phases' => $phases,
            'team_compositions' => $teamJson,
            'cost_estimations' => $costJson,
        ]);

        // Auto-submit to Sales if all features are estimated
        $totalStmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM tasks 
            WHERE project_id = ? AND task_type = 'feature' AND assigned_to = ?
        ");
        $totalStmt->bind_param("ii", $projectId, $user['id']);
        $totalStmt->execute();
        $totalCount = (int) ($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalStmt->close();

        $doneStmt = $conn->prepare("
            SELECT COUNT(*) as done 
            FROM feature_estimations fe
            JOIN tasks t ON fe.task_id = t.id
            WHERE t.project_id = ? AND t.task_type = 'feature' AND t.assigned_to = ?
              AND fe.expert_id = ? AND fe.status = 'completed'
        ");
        $doneStmt->bind_param("iii", $projectId, $user['id'], $user['id']);
        $doneStmt->execute();
        $doneCount = (int) ($doneStmt->get_result()->fetch_assoc()['done'] ?? 0);
        $doneStmt->close();

        if ($totalCount > 0 && $doneCount === $totalCount) {
            $salesColumns = $hasSalesApproval ? ", sales_approved = 0, sales_approved_by = NULL, sales_approved_at = NULL" : "";
            $submitStmt = $conn->prepare("
                UPDATE tasks 
                SET status = 'in_review'{$salesColumns}
                WHERE project_id = ? AND task_type = 'feature' AND assigned_to = ?
            ");
            $submitStmt->bind_param("ii", $projectId, $user['id']);
            $submitStmt->execute();
            $submitStmt->close();

            $salesUsers = $conn->query("SELECT id FROM users WHERE role = 'sales' AND status = 'active'");
            while ($sales = $salesUsers->fetch_assoc()) {
                createNotification(
                    (int) $sales['id'],
                    "Estimations Ready for Approval",
                    "Feature estimations are ready for sales approval.",
                    'approval',
                    'projects',
                    (int) $projectId,
                    BASE_URL . 'modules/sales/estimation-approval.php'
                );
            }

            $_SESSION['success'] = "Project setup saved and estimations sent to Sales.";
        } else {
            $_SESSION['error'] = "Project setup not submitted. Estimated features: {$doneCount} / {$totalCount}. Complete all feature estimates before saving setup.";
        }
    } else {
        $_SESSION['error'] = "Please select a project to save setup.";
    }

    header('Location: feature-estimation.php');
    exit();
}

// Handle task estimation save (draft)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['task_id']) && !isset($_POST['submit_all'])) {
    $taskId = $_POST['task_id'];
    $minDays = $_POST['min_days'];
    $likelyDays = $_POST['likely_days'];
    $maxDays = $_POST['max_days'];
    $expectation = $_POST['expectation'];
    $phaseName = $_POST['phase_name'] ?? null;
    $plannedStart = !empty($_POST['planned_start']) ? $_POST['planned_start'] : null;
    $plannedEnd = !empty($_POST['planned_end']) ? $_POST['planned_end'] : null;
    
    // Get the project_id for this task
    $taskInfo = $conn->query("SELECT project_id FROM tasks WHERE id = $taskId")->fetch_assoc();
    $projectId = $taskInfo['project_id'];
    
    // Check if estimation exists
    $existing = $conn->query("SELECT id FROM feature_estimations WHERE task_id = $taskId AND expert_id = {$user['id']}");
    
    if($existing->num_rows > 0) {
        if ($hasPhaseName) {
            $stmt = $conn->prepare("UPDATE feature_estimations SET min_days=?, likely_days=?, max_days=?, phase_name=?, expectation_description=?, status='completed', submitted_at=NOW() WHERE task_id=? AND expert_id=?");
            $stmt->bind_param("iiissii", $minDays, $likelyDays, $maxDays, $phaseName, $expectation, $taskId, $user['id']);
        } else {
            $stmt = $conn->prepare("UPDATE feature_estimations SET min_days=?, likely_days=?, max_days=?, expectation_description=?, status='completed', submitted_at=NOW() WHERE task_id=? AND expert_id=?");
            $stmt->bind_param("iiisii", $minDays, $likelyDays, $maxDays, $expectation, $taskId, $user['id']);
        }
    } else {
        if ($hasPhaseName) {
            $stmt = $conn->prepare("INSERT INTO feature_estimations (task_id, expert_id, project_id, min_days, likely_days, max_days, phase_name, expectation_description, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())");
            $stmt->bind_param("iiiiiiss", $taskId, $user['id'], $projectId, $minDays, $likelyDays, $maxDays, $phaseName, $expectation);
        } else {
            $stmt = $conn->prepare("INSERT INTO feature_estimations (task_id, expert_id, project_id, min_days, likely_days, max_days, expectation_description, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NOW())");
            $stmt->bind_param("iiiiiis", $taskId, $user['id'], $projectId, $minDays, $likelyDays, $maxDays, $expectation);
        }
    }
    
    if($stmt->execute()) {
        // Save draft estimations without sending to Sales yet
        $taskStmt = $conn->prepare("UPDATE tasks SET started_at = ?, deadline = ?, estimated_days_min = ?, estimated_days_likely = ?, estimated_days_max = ? WHERE id = ?");
        $taskStmt->bind_param("ssiiii", $plannedStart, $plannedEnd, $minDays, $likelyDays, $maxDays, $taskId);
        $taskStmt->execute();
        
        logActivity($user['id'], 'submit_estimation', 'tasks', $taskId, null, $_POST);
        $_SESSION['success'] = "Estimation saved successfully";
    } else {
        $_SESSION['error'] = "Failed to save estimation: " . $conn->error;
    }
    
    header('Location: feature-estimation.php');
    exit();
}

// Bulk submission handled on project setup save.

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
    --gray-500: #adb5bd;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-800: #343a40;
    --gray-900: #212529;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
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

/* Timeline Section */
    .timeline-section {
        background: white;
        border-radius: 20px;
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-sm);
        margin-bottom: 2rem;
    }

.timeline-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.timeline-header h5 {
    margin: 0 0 0.25rem 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
}

.gantt-container {
    padding: 1.5rem;
}

.gantt-container canvas {
    width: 100% !important;
    height: 320px !important;
}

/* Phase Management Section */
.phase-management-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 2rem;
}

.phase-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.phase-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
}

.phase-header h5 i {
    color: var(--theme-primary);
    margin-right: 0.5rem;
}

.project-selector {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.phase-list {
    padding: 1.5rem;
}

.phase-item {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    padding: 1rem;
    background: var(--gray-50);
    border-radius: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.phase-name {
    flex: 2;
    min-width: 150px;
}

.phase-name input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    font-size: 0.85rem;
}

.phase-dates {
    flex: 1;
    min-width: 120px;
}

.phase-dates input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    font-size: 0.85rem;
}

.phase-remove {
    flex-shrink: 0;
}

.btn-remove-phase {
    background: #fee2e2;
    border: none;
    color: #dc2626;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.7rem;
    cursor: pointer;
}

.btn-remove-phase:hover {
    background: #dc2626;
    color: white;
}

.btn-add-phase {
    background: transparent;
    border: 1px dashed var(--gray-400);
    color: var(--gray-600);
    padding: 0.5rem 1rem;
    border-radius: 40px;
    font-size: 0.8rem;
    margin-top: 1rem;
    cursor: pointer;
    width: 100%;
}

.btn-add-phase:hover {
    border-color: var(--theme-primary);
    color: var(--theme-primary);
}

.btn-save-phases {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.5rem 1.5rem;
    border-radius: 40px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
}

.btn-save-phases:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
}

/* Project Setup Sections */
.setup-section {
    border-top: 1px solid var(--gray-200);
}

.setup-section-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.setup-section-header h6 {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
}

.setup-section-header h6 i {
    color: var(--theme-primary);
    margin-right: 0.5rem;
}

.setup-section-body {
    padding: 1.5rem;
}

.team-item,
.cost-item {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}

.team-role {
    font-weight: 600;
    color: var(--gray-800);
}

.btn-remove-item {
    background: #fee2e2;
    border: none;
    color: #dc2626;
    padding: 0.35rem 0.7rem;
    border-radius: 8px;
    font-size: 0.7rem;
    cursor: pointer;
}

.btn-remove-item:hover {
    background: #dc2626;
    color: white;
}

/* Gantt Preview */
.gantt-preview {
    display: grid;
    gap: 0.5rem;
}

.gantt-row-preview {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.gantt-label {
    width: 50px;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--gray-600);
    text-transform: uppercase;
}

.gantt-bar-track {
    flex: 1;
    height: 10px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
    display: flex;
}

.gantt-bar {
    height: 100%;
    transition: width 0.2s ease;
}

.min-bar { background: #60a5fa; }
.likely-bar { background: #34d399; }
.max-bar { background: #f97316; }

/* Feature List */
.feature-list {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.feature-row {
    border-bottom: 1px solid var(--gray-200);
    padding: 1.25rem 1.5rem;
}

.feature-row:last-child {
    border-bottom: none;
}

.feature-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
}

.feature-meta {
    font-size: 0.75rem;
    color: var(--gray-500);
}

.feature-row.incomplete {
    border-left: 4px solid #f59e0b;
    background: #fff7ed;
}

.feature-row.complete {
    border-left: 4px solid #10b981;
    background: #f0fdf4;
}

.progress-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
    text-transform: uppercase;
}

.progress-badge.ready {
    background: #d1fae5;
    color: #059669;
}

.progress-badge.pending {
    background: #fef3c7;
    color: #d97706;
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

.form-control-custom {
    width: 100%;
    padding: 0.6rem 0.8rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.form-control-custom:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

textarea.form-control-custom {
    resize: vertical;
    min-height: 80px;
}

/* Buttons */
.btn-submit {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.7rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Alert Info */
.alert-info-custom {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.75rem;
    margin-top: 1rem;
}

.alert-info-custom small {
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.alert-info-custom i {
    color: var(--theme-primary);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
}

.empty-state i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state h5 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--gray-500);
    margin-bottom: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .page-header-modern h2 {
        font-size: 1.5rem;
    }
    
    .phase-item {
        flex-direction: column;
        align-items: stretch;
    }
    
    .card-header-custom {
        padding: 1rem 1.25rem;
    }
    
    .card-body-custom {
        padding: 1.25rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-calculator me-2"></i> 
                Timeline Feature Recommendations
            </h2>
            <p>Provide three-point estimations and build the realtime project timeline</p>
        </div>
    </div>

    <div class="phase-management-card">
        <div class="phase-header">
            <h5><i class="fas fa-folder-open me-2"></i>Select Project</h5>
        </div>
        <div class="project-selector">
            <label class="form-label">Choose a project to load features, timeline, and estimations</label>
            <div class="row g-2">
                <div class="col-md-8">
                    <select id="phaseProjectSelect" class="form-control-custom">
                        <option value="">-- Select a project --</option>
                        <?php foreach ($expertProjects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" 
                                    data-phases='<?php echo $hasProjectPhases ? htmlspecialchars($proj['project_phases'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>'
                                    data-team='<?php echo htmlspecialchars(json_encode($teamCompositionsByProject[(int) $proj['id']] ?? []), ENT_QUOTES, 'UTF-8'); ?>'
                                    data-cost='<?php echo htmlspecialchars(json_encode($costEstimationsByProject[(int) $proj['id']] ?? []), ENT_QUOTES, 'UTF-8'); ?>'
                                    data-start="<?php echo $proj['timeline_start']; ?>"
                                    data-end="<?php echo $proj['timeline_end']; ?>">
                                <?php echo htmlspecialchars($proj['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="button" class="btn-save-phases" id="saveSetupBtn" style="display: none;" onclick="saveProjectSetup()">
                        <i class="fas fa-save"></i> Save Project Setup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="projectSections" style="display: none;">
        <div class="phase-management-card">
            <div class="phase-header">
                <h5><i class="fas fa-stream"></i> Project Timeline Phases</h5>
            </div>
            <form method="POST" id="projectSetupForm">
                <input type="hidden" name="project_setup" value="1">
                <input type="hidden" name="project_id" id="setupProjectId">
                <input type="hidden" name="phases_json" id="phasesJson">
                <input type="hidden" name="team_json" id="teamJson">
                <input type="hidden" name="cost_json" id="costJson">
                <input type="hidden" name="project_start" id="projectStartHidden">
                <input type="hidden" name="project_end" id="projectEndHidden">
                <div id="phaseEditor">
                    <div class="project-selector" style="border-top: 1px solid var(--gray-200);">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Project Start Date</label>
                                <input type="date" id="projectStartInput" class="form-control-custom">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Project End Date</label>
                                <input type="date" id="projectEndInput" class="form-control-custom">
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2">These dates define the project timeline used in the Gantt chart.</small>
                    </div>
                    <div class="phase-list">
                        <div id="phasesContainer">
                            <!-- Phases will be added here dynamically -->
                        </div>
                        <button type="button" class="btn-add-phase" onclick="addPhase()">
                            <i class="fas fa-plus"></i> Add Phase
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="timeline-section">
            <div class="timeline-header">
                <h5><i class="fas fa-stream me-2"></i>Timeline Feature Recommendations</h5>
                <p class="text-muted mb-0 small">Real-time Gantt chart updates as you estimate each feature.</p>
            </div>
            <div id="expertGanttContainer" class="gantt-container">
                <div class="text-muted small">No timeline data yet.</div>
            </div>
        </div>

        <!-- Feature List -->
        <?php if($tasks->num_rows > 0): ?>
        <div class="feature-list">
            <div class="feature-row" id="featureProgressRow">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="feature-title">Feature Estimation Progress</div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="feature-meta" id="featureProgressText">Estimated: 0 / 0</div>
                        <span class="progress-badge pending" id="featureProgressBadge">Not Ready</span>
                    </div>
                </div>
                <small class="text-muted">All features must be estimated before submission to Sales.</small>
            </div>
            <?php while($task = $tasks->fetch_assoc()): 
                $estimation = $conn->query("SELECT * FROM feature_estimations WHERE task_id = {$task['id']} AND expert_id = {$user['id']}")->fetch_assoc();
                $isEstimated = $estimation && !empty($estimation['min_days']) && !empty($estimation['likely_days']) && !empty($estimation['max_days']);
                $projectPhases = [];
                if($hasProjectPhases && !empty($task['project_phases'])) {
                    $projectPhases = json_decode($task['project_phases'], true);
                }
                if (empty($projectPhases) || !is_array($projectPhases)) {
                    $projectPhases = [
                        ['name' => 'Planning'],
                        ['name' => 'Research'],
                        ['name' => 'Design'],
                        ['name' => 'Implementation'],
                        ['name' => 'Follow Up'],
                    ];
                }
            ?>
            <?php $isReadonly = $task['status'] !== 'todo'; ?>
            <div class="feature-row estimation-card <?php echo $isEstimated ? 'complete' : 'incomplete'; ?>" data-project-id="<?php echo (int) $task['project_id']; ?>" data-estimated="<?php echo $isEstimated ? '1' : '0'; ?>" style="display:none;">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <div class="feature-title"><?php echo htmlspecialchars($task['title']); ?></div>
                        <div class="feature-meta"><i class="fas fa-folder me-1"></i><?php echo htmlspecialchars($task['project_name']); ?></div>
                    </div>
                    <?php if ($isReadonly): ?>
                        <span class="progress-badge ready">Submitted</span>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <div class="text-muted small mb-2">Description</div>
                    <p class="mb-0 text-secondary"><?php echo htmlspecialchars($task['description']); ?></p>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Expectation Description</label>
                        <textarea name="expectation" class="form-control-custom" rows="2" required placeholder="What should this feature accomplish?" <?php echo $isReadonly ? 'disabled' : ''; ?>><?php echo htmlspecialchars($estimation['expectation_description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Project Phase</label>
                        <select name="phase_name" class="form-control-custom" <?php echo $isReadonly ? 'disabled' : ''; ?>>
                            <option value="">-- Select phase --</option>
                            <?php if(!empty($projectPhases)): ?>
                                <?php foreach($projectPhases as $phase): ?>
                                    <?php $phaseName = $phase['name'] ?? ''; ?>
                                    <option value="<?php echo htmlspecialchars($phaseName); ?>" <?php echo ($estimation['phase_name'] ?? '') === $phaseName ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($phaseName); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <small class="text-muted d-block mt-1">Assign this feature to a project phase</small>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Minimum Days (Best)</label>
                            <input type="number" name="min_days" class="form-control-custom estimate-input" step="0.5" <?php echo $isReadonly ? 'disabled' : ''; ?>
                                   value="<?php echo $estimation['min_days'] ?? ''; ?>" required placeholder="0.5">
                            <small class="text-muted d-block mt-1">Optimistic estimate</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Most Likely Days</label>
                            <input type="number" name="likely_days" class="form-control-custom estimate-input" step="0.5" <?php echo $isReadonly ? 'disabled' : ''; ?>
                                   value="<?php echo $estimation['likely_days'] ?? ''; ?>" required placeholder="1">
                            <small class="text-muted d-block mt-1">Realistic estimate</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Maximum Days (Worst)</label>
                            <input type="number" name="max_days" class="form-control-custom estimate-input" step="0.5" <?php echo $isReadonly ? 'disabled' : ''; ?>
                                   value="<?php echo $estimation['max_days'] ?? ''; ?>" required placeholder="2">
                            <small class="text-muted d-block mt-1">Pessimistic estimate</small>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Planned Start Date</label>
                            <input type="date" name="planned_start" class="form-control-custom estimate-start" <?php echo $isReadonly ? 'disabled' : ''; ?>
                                   value="<?php echo $task['started_at'] ? date('Y-m-d', strtotime($task['started_at'])) : ''; ?>">
                            <small class="text-muted d-block mt-1">Set the task start for Gantt</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Planned End Date</label>
                            <input type="date" name="planned_end" class="form-control-custom estimate-end" <?php echo $isReadonly ? 'disabled' : ''; ?>
                                   value="<?php echo $task['deadline'] ? date('Y-m-d', strtotime($task['deadline'])) : ''; ?>">
                            <small class="text-muted d-block mt-1">Set the task end for Gantt</small>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="text-muted small mb-2">Gantt Preview (Min / Likely / Max)</div>
                        <div class="gantt-preview" data-task-id="<?php echo $task['id']; ?>">
                            <div class="gantt-row-preview">
                                <span class="gantt-label">Days</span>
                                <div class="gantt-bar-track">
                                    <div class="gantt-bar min-bar"></div>
                                    <div class="gantt-bar likely-bar"></div>
                                    <div class="gantt-bar max-bar"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!$isReadonly): ?>
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i> Save Estimation
                        </button>
                    <?php else: ?>
                        <div class="text-muted small">This feature has been submitted to Sales.</div>
                    <?php endif; ?>
                </form>
                
                <?php if($estimation): ?>
                <div class="alert-info-custom">
                    <small>
                        <i class="fas fa-chart-line"></i> 
                        <strong>PERT Estimate:</strong> 
                        <?php echo round(($estimation['min_days'] + 4*$estimation['likely_days'] + $estimation['max_days'])/6, 1); ?> days
                        <span class="text-muted">(Min: <?php echo $estimation['min_days']; ?> | Likely: <?php echo $estimation['likely_days']; ?> | Max: <?php echo $estimation['max_days']; ?>)</span>
                    </small>
                </div>
        <?php endif; ?>
    </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-check-circle"></i>
            <h5>No pending estimations</h5>
            <p>All assigned features have been estimated. Great job!</p>
        </div>
        <?php endif; ?>

        <div id="teamEditor" class="setup-section">
            <div class="setup-section-header">
                <h6><i class="fas fa-users"></i> Team Composition</h6>
            </div>
            <div class="setup-section-body" id="teamContainer"></div>
        </div>
        <div id="costEditor" class="setup-section">
            <div class="setup-section-header">
                <h6><i class="fas fa-cloud"></i> Cost Estimation</h6>
            </div>
            <div class="setup-section-body">
                <div id="costContainer"></div>
                <button type="button" class="btn-add-phase" onclick="addCostComponent()">
                    <i class="fas fa-plus"></i> Add Cost Component
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentProjectId = null;
let phases = [];
let teamComposition = [];
let costComponents = [];

// Default phases template
const defaultPhases = [
    { name: "Planning", start_date: "", end_date: "" },
    { name: "Research", start_date: "", end_date: "" },
    { name: "Design", start_date: "", end_date: "" },
    { name: "Implementation", start_date: "", end_date: "" },
    { name: "Follow Up", start_date: "", end_date: "" }
];

// Default team composition roles
const defaultTeamRoles = [
    { role: "Senior Backend Developer", required_count: 1, allocation_percentage: 100 },
    { role: "Senior Frontend Developer", required_count: 1, allocation_percentage: 100 },
    { role: "Professional Web Developer", required_count: 2, allocation_percentage: 100 },
    { role: "QA Engineer", required_count: 1, allocation_percentage: 50 },
    { role: "UI/UX Designer", required_count: 1, allocation_percentage: 50 },
    { role: "DevOps Engineer", required_count: 1, allocation_percentage: 25 },
    { role: "Database Administrator", required_count: 1, allocation_percentage: 25 },
    { role: "Project Manager", required_count: 1, allocation_percentage: 50 }
];

// Default cost components
const defaultCostComponents = [
    { component: "Web App", azure_service: "Azure App Service", monthly_cost: 100 },
    { component: "API", azure_service: "Azure API Management", monthly_cost: 50 },
    { component: "Database", azure_service: "Azure SQL Database", monthly_cost: 150 },
    { component: "Storage", azure_service: "Azure Blob Storage", monthly_cost: 30 },
    { component: "CDN", azure_service: "Azure CDN", monthly_cost: 20 },
    { component: "Monitoring", azure_service: "Azure Monitor", monthly_cost: 15 }
];

function loadProjectSetup() {
    const select = document.getElementById('phaseProjectSelect');
    if (!select) return;
    const projectId = select.value;
    const selectedOption = select.options[select.selectedIndex];
    const phasesData = selectedOption.getAttribute('data-phases');
    const teamData = selectedOption.getAttribute('data-team');
    const costData = selectedOption.getAttribute('data-cost');
    const projectStart = selectedOption.getAttribute('data-start');
    const projectEnd = selectedOption.getAttribute('data-end');
    
    if (!projectId) {
        document.getElementById('saveSetupBtn').style.display = 'none';
        const sections = document.getElementById('projectSections');
        if (sections) sections.style.display = 'none';
        return;
    }
    
    currentProjectId = projectId;
    document.getElementById('setupProjectId').value = projectId;

    const projectStartInput = document.getElementById('projectStartInput');
    const projectEndInput = document.getElementById('projectEndInput');
    if (projectStartInput) projectStartInput.value = projectStart || '';
    if (projectEndInput) projectEndInput.value = projectEnd || '';
    
    if (phasesData && phasesData !== 'null' && phasesData !== '') {
        try {
            phases = JSON.parse(phasesData);
            if (!Array.isArray(phases) || phases.length === 0) {
                phases = JSON.parse(JSON.stringify(defaultPhases));
            }
        } catch(e) {
            phases = JSON.parse(JSON.stringify(defaultPhases));
        }
    } else {
        phases = JSON.parse(JSON.stringify(defaultPhases));
    }
    
    if (teamData && teamData !== 'null' && teamData !== '') {
        try {
            teamComposition = JSON.parse(teamData);
        } catch (e) {
            teamComposition = JSON.parse(JSON.stringify(defaultTeamRoles));
        }
    } else {
        teamComposition = JSON.parse(JSON.stringify(defaultTeamRoles));
    }

    if (!Array.isArray(teamComposition) || teamComposition.length === 0) {
        teamComposition = JSON.parse(JSON.stringify(defaultTeamRoles));
    }

    if (costData && costData !== 'null' && costData !== '') {
        try {
            costComponents = JSON.parse(costData);
        } catch (e) {
            costComponents = JSON.parse(JSON.stringify(defaultCostComponents));
        }
    } else {
        costComponents = JSON.parse(JSON.stringify(defaultCostComponents));
    }

    if (!Array.isArray(costComponents) || costComponents.length === 0) {
        costComponents = JSON.parse(JSON.stringify(defaultCostComponents));
    }

    renderTeam();
    renderCost();

    const sections = document.getElementById('projectSections');
    if (sections) sections.style.display = 'block';
    document.getElementById('saveSetupBtn').style.display = 'block';
    filterFeatureList();
    renderExpertGantt();
}

function renderPhases() {
    const container = document.getElementById('phasesContainer');
    let html = '';
    
    phases.forEach((phase, index) => {
        html += `
            <div class="phase-item" data-phase-index="${index}">
                <div class="phase-name">
                    <label class="form-label">Phase Name</label>
                    <input type="text" class="form-control-custom phase-name-input" value="${escapeHtml(phase.name)}" placeholder="e.g., Planning">
                </div>
                <div class="phase-dates">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control-custom phase-start-input" value="${phase.start_date || ''}">
                </div>
                <div class="phase-dates">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control-custom phase-end-input" value="${phase.end_date || ''}">
                </div>
                <div class="phase-remove">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn-remove-phase" onclick="removePhase(${index})">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    
    // Add event listeners to update phases array
    document.querySelectorAll('.phase-name-input').forEach((input, idx) => {
        input.addEventListener('change', (e) => { phases[idx].name = e.target.value; });
    });
    document.querySelectorAll('.phase-start-input').forEach((input, idx) => {
        input.addEventListener('change', (e) => { phases[idx].start_date = e.target.value; });
    });
    document.querySelectorAll('.phase-end-input').forEach((input, idx) => {
        input.addEventListener('change', (e) => { phases[idx].end_date = e.target.value; });
    });
}

function addPhase() {
    phases.push({ name: "New Phase", start_date: "", end_date: "" });
    renderPhases();
}

function removePhase(index) {
    phases.splice(index, 1);
    renderPhases();
}

function renderTeam() {
    const container = document.getElementById('teamContainer');
    if (!container) return;
    let html = '';

    teamComposition.forEach((item, index) => {
        html += `
            <div class="team-item" data-team-index="${index}">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <div class="team-role">${escapeHtml(item.role)}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Number of People</label>
                        <input type="number" class="form-control-custom team-count-input" value="${item.required_count ?? 0}" min="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Allocation Percentage</label>
                        <div class="d-flex">
                            <input type="number" class="form-control-custom team-allocation-input" value="${item.allocation_percentage ?? 0}" min="0" max="100" style="border-radius: 10px 0 0 10px;">
                            <span class="input-group-text" style="border-radius: 0 10px 10px 0;">%</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;

    document.querySelectorAll('.team-count-input').forEach((input, idx) => {
        input.addEventListener('change', (e) => { teamComposition[idx].required_count = parseInt(e.target.value) || 0; });
    });
    document.querySelectorAll('.team-allocation-input').forEach((input, idx) => {
        input.addEventListener('change', (e) => { teamComposition[idx].allocation_percentage = parseInt(e.target.value) || 0; });
    });
}

function renderCost() {
    const container = document.getElementById('costContainer');
    if (!container) return;
    let html = '';

    costComponents.forEach((item, index) => {
        html += `
            <div class="cost-item" data-cost-index="${index}">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Component</label>
                        <input type="text" class="form-control-custom cost-component-input" value="${escapeHtml(item.component || '')}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Azure Service</label>
                        <select class="form-control-custom cost-service-input">
                            ${renderServiceOptions(item.azure_service)}
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Monthly Cost ($)</label>
                        <input type="number" class="form-control-custom cost-amount-input" step="0.01" value="${item.monthly_cost ?? 0}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="button" class="btn-remove-item w-100" onclick="removeCostComponent(${index})">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;

    document.querySelectorAll('.cost-component-input').forEach((input, idx) => {
        input.addEventListener('change', (e) => { costComponents[idx].component = e.target.value; });
    });
    document.querySelectorAll('.cost-service-input').forEach((select, idx) => {
        select.addEventListener('change', (e) => { costComponents[idx].azure_service = e.target.value; });
    });
    document.querySelectorAll('.cost-amount-input').forEach((input, idx) => {
        input.addEventListener('change', (e) => { costComponents[idx].monthly_cost = parseFloat(e.target.value) || 0; });
    });
}

function renderServiceOptions(selected) {
    const services = [
        "Azure App Service",
        "Azure Functions",
        "Azure SQL Database",
        "Azure Cosmos DB",
        "Azure Blob Storage",
        "Azure CDN",
        "Azure Monitor"
    ];
    return services.map(service => {
        const isSelected = service === selected ? 'selected' : '';
        return `<option value="${service}" ${isSelected}>${service}</option>`;
    }).join('');
}

function addCostComponent() {
    costComponents.push({ component: "New Component", azure_service: "Azure App Service", monthly_cost: 0 });
    renderCost();
}

function removeCostComponent(index) {
    costComponents.splice(index, 1);
    renderCost();
}

function saveProjectSetup() {
    if (!currentProjectId) {
        alert('Please select a project first');
        return;
    }
    
    const phasesToSave = [];
    document.querySelectorAll('.phase-item').forEach(item => {
        const nameInput = item.querySelector('.phase-name-input');
        const startInput = item.querySelector('.phase-start-input');
        const endInput = item.querySelector('.phase-end-input');
        
        if (nameInput && nameInput.value.trim()) {
            phasesToSave.push({
                name: nameInput.value.trim(),
                start_date: startInput ? startInput.value : '',
                end_date: endInput ? endInput.value : ''
            });
        }
    });
    
    if (phasesToSave.length === 0) {
        alert('Please add at least one phase');
        return;
    }

    const progressText = document.getElementById('featureProgressText');
    if (progressText) {
        const match = progressText.textContent.match(/Estimated:\s*(\d+)\s*\/\s*(\d+)/i);
        if (match) {
            const estimated = parseInt(match[1], 10);
            const total = parseInt(match[2], 10);
            if (total > 0 && estimated !== total) {
                alert(`Please complete all feature estimates before saving setup. (${estimated}/${total})`);
                return;
            }
        }
    }
    
    document.getElementById('phasesJson').value = JSON.stringify(phasesToSave);
    document.getElementById('teamJson').value = JSON.stringify(teamComposition);
    document.getElementById('costJson').value = JSON.stringify(costComponents);
    const startInput = document.getElementById('projectStartInput');
    const endInput = document.getElementById('projectEndInput');
    if (startInput) document.getElementById('projectStartHidden').value = startInput.value || '';
    if (endInput) document.getElementById('projectEndHidden').value = endInput.value || '';
    document.getElementById('projectSetupForm').submit();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateGanttPreview(card) {
    if (!card) return;
    const minVal = parseFloat(card.querySelector('input[name="min_days"]')?.value) || 0;
    const likelyVal = parseFloat(card.querySelector('input[name="likely_days"]')?.value) || 0;
    const maxVal = parseFloat(card.querySelector('input[name="max_days"]')?.value) || 0;
    const maxScale = Math.max(minVal, likelyVal, maxVal, 1);

    const minBar = card.querySelector('.min-bar');
    const likelyBar = card.querySelector('.likely-bar');
    const maxBar = card.querySelector('.max-bar');

    const minWidth = (minVal / maxScale) * 100;
    const likelyWidth = (Math.max(likelyVal - minVal, 0) / maxScale) * 100;
    const maxWidth = (Math.max(maxVal - Math.max(likelyVal, minVal), 0) / maxScale) * 100;

    if (minBar) minBar.style.width = `${minWidth}%`;
    if (likelyBar) likelyBar.style.width = `${likelyWidth}%`;
    if (maxBar) maxBar.style.width = `${maxWidth}%`;
}

function bindPreviewHandlers() {
    document.querySelectorAll('.estimation-card').forEach(card => {
        const inputs = card.querySelectorAll('.estimate-input');
        inputs.forEach(input => {
            input.addEventListener('input', () => updateGanttPreview(card));
        });
        updateGanttPreview(card);
    });
}

function buildGanttTasks() {
    const tasks = [];
    const projectStartInput = document.getElementById('projectStartInput');
    const projectStartFallback = projectStartInput?.value || new Date().toISOString().slice(0, 10);
    document.querySelectorAll('.feature-row').forEach(row => {
        if (currentProjectId && row.getAttribute('data-project-id') !== currentProjectId) {
            return;
        }
        const title = row.querySelector('.feature-title')?.textContent?.trim() || 'Feature';
        const taskId = row.querySelector('input[name="task_id"]')?.value || title;
        const startInput = row.querySelector('input[name="planned_start"]')?.value;
        const endInput = row.querySelector('input[name="planned_end"]')?.value;
        const likelyDays = parseFloat(row.querySelector('input[name="likely_days"]')?.value || '0');
        const minDays = parseFloat(row.querySelector('input[name="min_days"]')?.value || '0');
        const maxDays = parseFloat(row.querySelector('input[name="max_days"]')?.value || '0');

        let startDate = startInput || projectStartFallback;
        let endDate = endInput;

        if (startDate && !endDate && likelyDays > 0) {
            const start = new Date(startDate + 'T00:00:00');
            start.setDate(start.getDate() + Math.max(1, Math.round(likelyDays)));
            endDate = start.toISOString().slice(0, 10);
        }

        if (!endDate) {
            const duration = likelyDays || minDays || maxDays || 1;
            const start = new Date(startDate + 'T00:00:00');
            start.setDate(start.getDate() + Math.max(1, Math.round(duration)));
            endDate = start.toISOString().slice(0, 10);
        }

        tasks.push({
            id: `task-${taskId}`,
            name: title,
            start: startDate,
            end: endDate,
            progress: 0,
            custom_class: 'gantt-task'
        });
    });
    return tasks;
}

function renderExpertGantt() {
    const container = document.getElementById('expertGanttContainer');
    if (!container) return;
    const tasks = buildGanttTasks();
    if (tasks.length === 0) {
        container.innerHTML = '<div class="text-muted small">No timeline data yet.</div>';
        return;
    }
    if (typeof Chart === 'undefined') {
        container.innerHTML = '<div class="text-danger small">Chart.js failed to load. Please check your internet connection.</div>';
        return;
    }

    container.innerHTML = '<canvas id="expertGanttCanvas" height="240"></canvas>';
    const ctx = document.getElementById('expertGanttCanvas').getContext('2d');

    const labels = tasks.map(t => t.name);
    const starts = tasks.map(t => new Date(t.start + 'T00:00:00'));
    const ends = tasks.map(t => new Date(t.end + 'T00:00:00'));
    const minStart = new Date(Math.min(...starts.map(d => d.getTime())));
    const offsets = starts.map(start => Math.max(0, Math.round((start - minStart) / (1000 * 60 * 60 * 24))));
    const durations = starts.map((start, idx) => {
        const end = ends[idx];
        const diff = Math.max(1, Math.round((end - start) / (1000 * 60 * 60 * 24)));
        return diff;
    });

    if (window.expertGanttChart) {
        window.expertGanttChart.destroy();
    }

    window.expertGanttChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Offset',
                data: offsets,
                backgroundColor: 'rgba(0,0,0,0)',
                borderWidth: 0,
                stack: 'gantt'
            }, {
                label: 'Duration (days)',
                data: durations,
                backgroundColor: '#1f6392',
                stack: 'gantt'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    stacked: true,
                    title: { display: true, text: 'Days' }
                },
                y: {
                    stacked: true
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
}

function bindGanttHandlers() {
    document.addEventListener('input', (e) => {
        if (e.target.matches('input[name="planned_start"], input[name="planned_end"], input[name="likely_days"], input[name="min_days"], input[name="max_days"]')) {
            renderExpertGantt();
            const row = e.target.closest('.feature-row');
            if (row) {
                const minVal = parseFloat(row.querySelector('input[name="min_days"]')?.value) || 0;
                const likelyVal = parseFloat(row.querySelector('input[name="likely_days"]')?.value) || 0;
                const maxVal = parseFloat(row.querySelector('input[name="max_days"]')?.value) || 0;
                const isDone = (minVal > 0 && likelyVal > 0 && maxVal > 0);
                row.setAttribute('data-estimated', isDone ? '1' : '0');
                row.classList.toggle('complete', isDone);
                row.classList.toggle('incomplete', !isDone);
                filterFeatureList();
            }
        }
    });
    renderExpertGantt();
}

function filterFeatureList() {
    let total = 0;
    let estimated = 0;
    document.querySelectorAll('.feature-row').forEach(row => {
        if (row.id === 'featureProgressRow') {
            return;
        }
        const match = !currentProjectId || row.getAttribute('data-project-id') === currentProjectId;
        row.style.display = match ? '' : 'none';
        if (match) {
            total += 1;
            if (row.getAttribute('data-estimated') === '1') {
                estimated += 1;
            }
        }
    });
    const progressText = document.getElementById('featureProgressText');
    const progressBadge = document.getElementById('featureProgressBadge');
    if (progressText) {
        progressText.textContent = `Estimated: ${estimated} / ${total}`;
    }
    if (progressBadge) {
        const ready = total > 0 && estimated === total;
        progressBadge.textContent = ready ? 'Ready' : 'Not Ready';
        progressBadge.classList.toggle('ready', ready);
        progressBadge.classList.toggle('pending', !ready);
    }
}

// Initialize
const phaseSelect = document.getElementById('phaseProjectSelect');
if (phaseSelect) {
    phaseSelect.addEventListener('change', loadProjectSetup);
    // Auto-select first project if none selected
    if (!phaseSelect.value && phaseSelect.options.length > 1) {
        phaseSelect.value = phaseSelect.options[1].value;
        loadProjectSetup();
    }
}
bindPreviewHandlers();
bindGanttHandlers();
filterFeatureList();
setTimeout(renderExpertGantt, 50);
</script>

<?php include '../../includes/footer.php'; ?>
