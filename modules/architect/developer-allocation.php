<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$allocationRoles = [
    'Frontend Developer' => ['min' => 1, 'max' => 5],
    'Backend Developer' => ['min' => 1, 'max' => 5],
    'Full Stack Developer' => ['min' => 0, 'max' => 3],
    'QA Engineer' => ['min' => 1, 'max' => 2],
    'UI/UX Designer' => ['min' => 1, 'max' => 2],
    'DevOps Engineer' => ['min' => 0, 'max' => 2],
    'Database Administrator' => ['min' => 0, 'max' => 1],
];

function normalizeAllocationComplexity(?string $value): string
{
    $normalized = strtolower(trim((string) $value));

    return match ($normalized) {
        'simple', 'low' => 'low',
        'medium' => 'medium',
        'complex', 'high' => 'high',
        'critical' => 'critical',
        default => 'medium'
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

// Get existing allocations
$existingAllocations = [];
$allocResult = $conn->query("SELECT * FROM developer_allocations WHERE project_id = $projectId");
while($row = $allocResult->fetch_assoc()) {
    $existingAllocations[$row['role']] = $row;
}

$includedDepartments = [];
$includedDepartmentRaw = trim((string) ($project['included_departments'] ?? ''));
if ($includedDepartmentRaw !== '') {
    $decodedDepartments = json_decode($includedDepartmentRaw, true);
    if (is_array($decodedDepartments)) {
        $includedDepartments = array_values(array_intersect(array_map('strval', $decodedDepartments), array_keys($allocationRoles)));
    }
}

if (count($includedDepartments) === 0 && count($existingAllocations) > 0) {
    foreach ($existingAllocations as $role => $allocation) {
        if ((int) ($allocation['required_count'] ?? 0) > 0 && isset($allocationRoles[$role])) {
            $includedDepartments[] = $role;
        }
    }
}

if (count($includedDepartments) === 0) {
    foreach ($allocationRoles as $role => $limits) {
        if ((int) ($limits['min'] ?? 0) > 0) {
            $includedDepartments[] = $role;
        }
    }
}

$includedDepartmentLookup = array_fill_keys($includedDepartments, true);

$selectedComplexity = 'medium';
if (!empty($existingAllocations)) {
    $firstAllocation = reset($existingAllocations);
    $selectedComplexity = normalizeAllocationComplexity($firstAllocation['complexity_level'] ?? 'medium');
} elseif ($project) {
    $selectedComplexity = normalizeAllocationComplexity($project['complexity_level'] ?? 'medium');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $postedAllocations = is_array($_POST['allocations'] ?? null) ? $_POST['allocations'] : [];
    $selectedDepartments = [];
    foreach ($postedAllocations as $role => $data) {
        if (!isset($allocationRoles[$role]) || !is_array($data)) {
            continue;
        }

        $requiredCount = max(0, (int) ($data['required'] ?? 0));
        if (isset($data['included']) || $requiredCount > 0) {
            $selectedDepartments[] = (string) $role;
        }
    }

    $selectedDepartments = array_values(array_unique($selectedDepartments));
    if (count($selectedDepartments) === 0) {
        $_SESSION['error'] = 'Select at least one department included in this project.';
        header('Location: developer-allocation.php?id=' . $projectId);
        exit();
    }

    $conn->query("DELETE FROM developer_allocations WHERE project_id = $projectId");
    if(count($postedAllocations) > 0) {
        foreach($postedAllocations as $role => $data) {
            if (!isset($allocationRoles[$role]) || !in_array((string) $role, $selectedDepartments, true) || !is_array($data)) {
                continue;
            }

            $requiredCount = max(0, (int) ($data['required'] ?? 0));
            $complexity = normalizeAllocationComplexity($data['complexity'] ?? 'medium');
            $stmt = $conn->prepare("INSERT INTO developer_allocations (project_id, role, required_count, complexity_level) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isis", $projectId, $role, $requiredCount, $complexity);
            $stmt->execute();
        }
    }
    $includedDepartmentsJson = json_encode($selectedDepartments, JSON_UNESCAPED_SLASHES);
    $departmentStmt = $conn->prepare("UPDATE projects SET included_departments = ? WHERE id = ?");
    if ($departmentStmt !== false) {
        $departmentStmt->bind_param('si', $includedDepartmentsJson, $projectId);
        $departmentStmt->execute();
        $departmentStmt->close();
    }
    logActivity($user['id'], 'developer_allocation', 'projects', $projectId, null, $_POST);
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
.form-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}

.form-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.form-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-card .card-header h5 i {
    color: var(--theme-primary);
}

.form-card .card-body {
    padding: 1.5rem;
}

/* Table Styles */
.allocation-table {
    width: 100%;
    border-collapse: collapse;
}

.allocation-table thead {
    background: var(--gray-50);
}

.allocation-table th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.department-toggle-wrap {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.department-toggle-wrap input {
    width: 1rem;
    height: 1rem;
}

.department-role-note {
    display: block;
    color: var(--gray-600);
    font-size: 0.72rem;
    margin-top: 0.15rem;
}

.tooltip-helper {
    color: var(--theme-primary);
    cursor: help;
}

.allocation-table td {
    padding: 0.75rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.allocation-table tr:hover {
    background: var(--gray-50);
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

.form-control-modern,
.form-select-modern {
    width: 100%;
    padding: 0.6rem 0.8rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
    transition: all 0.2s;
    background: white;
}

.form-control-modern:focus,
.form-select-modern:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.form-control-sm {
    width: 80px;
    text-align: center;
}

/* Badges */
.badge-available {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    background: #dbeafe;
    color: #2563eb;
}

.badge-success {
    background: #d1fae5;
    color: #059669;
}

.badge-danger {
    background: #fee2e2;
    color: #dc2626;
}

/* Sidebar Card */
.info-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}

.info-card .card-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.info-card .card-header h5 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-card .card-header h5 i {
    color: var(--theme-primary);
}

.info-card .card-body {
    padding: 1.25rem 1.5rem;
}

/* Guidelines List */
.guidelines-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.guidelines-list li {
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--gray-200);
    font-size: 0.85rem;
    color: var(--gray-700);
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.guidelines-list li:last-child {
    border-bottom: none;
}

.guidelines-list li i {
    color: #3b82f6;
    margin-top: 0.2rem;
}

.guidelines-list li i.fa-exclamation-triangle {
    color: #f59e0b;
}

/* Button */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.7rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}


/* Alert */
.alert-warning-modern {
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 16px;
    padding: 1rem;
    margin-top: 1rem;
}

.alert-success-modern {
    background: #d1fae5;
    border: 1px solid #a7f3d0;
    border-radius: 16px;
    padding: 1rem;
    margin-top: 1rem;
}

/* Progress */
.progress-modern {
    height: 6px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.progress-bar-modern {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

.progress-bar-modern.success {
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
}

.progress-bar-modern.danger {
    background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%);
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern { padding: 1.5rem; }
    .breadcrumb-modern { padding: 0.5rem 0.75rem; }
    .progress-indicator { padding: 0.75rem; }
    .progress-step .step-label { font-size: 0.6rem; }
    .progress-step .step-number { width: 24px; height: 24px; font-size: 0.7rem; }
    .allocation-table { font-size: 0.8rem; }
    .allocation-table th, .allocation-table td { padding: 0.5rem; }
    .form-control-sm { width: 60px; }
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .role-name {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
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
                <i class="fas fa-users me-2"></i> 
                Developer Allocation
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
            <li class="breadcrumb-item active"><i class="fas fa-users"></i> Developer Allocation</li>
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
        <a class="progress-step active" href="developer-allocation.php?id=<?php echo $projectId; ?>"><div class="step-number">4</div><div class="step-label">Developers</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="upload-diagram.php?id=<?php echo $projectId; ?>"><div class="step-number">5</div><div class="step-label">Documents</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="summary.php?id=<?php echo $projectId; ?>"><div class="step-number">6</div><div class="step-label">Summary</div></a>
    </div>

    <div class="row g-4">
        <!-- Main Form Column -->
        <div class="col-lg-8">
            <div class="form-card">
                <div class="card-header">
                    <h5><i class="fas fa-user-plus"></i> Team Requirements</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="allocationForm">
                        <div class="mb-4">
                            <label class="form-label">
                                Project Complexity Level
                                <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Complexity is now project-level. It guides overall resource recommendations, not per-feature sizing."></i>
                            </label>
                            <select class="form-select-modern" id="complexityLevel" onchange="updateAllocation()">
                                <option value="low" <?php echo $selectedComplexity === 'low' ? 'selected' : ''; ?>>Low - Simple application with basic features</option>
                                <option value="medium" <?php echo $selectedComplexity === 'medium' ? 'selected' : ''; ?>>Medium - Standard web/mobile application</option>
                                <option value="high" <?php echo $selectedComplexity === 'high' ? 'selected' : ''; ?>>High - Complex system with advanced features</option>
                                <option value="critical" <?php echo $selectedComplexity === 'critical' ? 'selected' : ''; ?>>Critical - Enterprise-grade solution</option>
                            </select>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="allocation-table">
                                <thead>
                                    <tr>
                                        <th>Included Department <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Select every department that must participate in this project before continuing to diagram upload."></i></th>
                                        <th>Required Count</th>
                                        <th>Available</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $availableDevs = [];
                                    $devQuery = $conn->query("
                                        SELECT department, COUNT(*) as count
                                        FROM users
                                        WHERE role IN ('developer', 'expert')
                                          AND status = 'active'
                                          AND department IS NOT NULL
                                          AND department != ''
                                        GROUP BY department
                                    ");
                                    while($dev = $devQuery->fetch_assoc()) {
                                        $availableDevs[$dev['department']] = $dev['count'];
                                    }
                                    
                                    foreach($allocationRoles as $role => $limits):
                                        $isIncluded = isset($includedDepartmentLookup[$role]);
                                        $existing = array_key_exists($role, $existingAllocations)
                                            ? (int) ($existingAllocations[$role]['required_count'] ?? 0)
                                            : ($isIncluded ? (int) ($limits['min'] ?? 0) : 0);
                                        $existingComplexity = normalizeAllocationComplexity($existingAllocations[$role]['complexity_level'] ?? $selectedComplexity);
                                        $available = $availableDevs[$role] ?? 0;
                                        $isShortage = $existing > $available;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold">
                                            <label class="department-toggle-wrap">
                                                <input
                                                    type="checkbox"
                                                    class="department-toggle"
                                                    name="allocations[<?php echo $role; ?>][included]"
                                                    value="1"
                                                    data-role="<?php echo htmlspecialchars($role); ?>"
                                                    onchange="toggleDepartmentRow(this)"
                                                    <?php echo $isIncluded ? 'checked' : ''; ?>
                                                >
                                                <span><?php echo $role; ?></span>
                                            </label>
                                            <span class="department-role-note">SUL and team members are matched by this department.</span>
                                        </td>
                                        <td>
                                            <input type="number" name="allocations[<?php echo $role; ?>][required]" 
                                                   class="form-control-modern form-control-sm required-count" 
                                                   value="<?php echo $existing; ?>" 
                                                  min="0"
                                                   max="<?php echo $limits['max']; ?>"
                                                   data-min-recommended="<?php echo (int) ($limits['min'] ?? 0); ?>"
                                                   onchange="checkAvailability(this, '<?php echo $role; ?>')">
                                            <input type="hidden" name="allocations[<?php echo $role; ?>][complexity]" value="<?php echo htmlspecialchars($existingComplexity); ?>">
                                        </td>
                                        <td><span class="badge-available"><?php echo $available; ?> available</span></td>
                                        <td class="status-cell">
                                            <?php if($existing > 0): ?>
                                                <span class="badge-<?php echo $isShortage ? 'danger' : 'success'; ?>">
                                                    <?php echo $isShortage ? 'Shortage!' : 'Sufficient'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div id="alertMessages" class="mt-3"></div>
                        
                        <div class="mt-4 text-end">
                            <button type="submit" class="btn-primary-modern">
                                <i class="fas fa-arrow-right"></i> Proceed to Upload Diagrams
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Sidebar Column -->
        <div class="col-lg-4">
            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie"></i> Resource Summary</h5>
                </div>
                <div class="card-body">
                    <div id="resourceSummary"><p class="text-muted text-center py-3">Select complexity level to see recommendations</p></div>
                </div>
            </div>
            
            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle"></i> Allocation Guidelines</h5>
                </div>
                <div class="card-body">
                    <ul class="guidelines-list">
                        <li><i class="fas fa-info-circle"></i> Low complexity: 2-3 developers</li>
                        <li><i class="fas fa-info-circle"></i> Medium complexity: 4-5 developers</li>
                        <li><i class="fas fa-info-circle"></i> High complexity: 6-8 developers</li>
                        <li><i class="fas fa-info-circle"></i> Critical: 8+ developers</li>
                        <li><i class="fas fa-exclamation-triangle"></i> Red alerts indicate resource shortage</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function initAllocationTooltips() {
    if (!window.bootstrap || !bootstrap.Tooltip) {
        return;
    }
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        bootstrap.Tooltip.getOrCreateInstance(element);
    });
}

function updateAllocation() {
    const complexity = document.getElementById('complexityLevel').value;
    const recommendations = {
        low: {'Frontend Developer':1,'Backend Developer':1,'Full Stack Developer':0,'QA Engineer':1,'UI/UX Designer':1,'DevOps Engineer':0,'Database Administrator':0},
        medium: {'Frontend Developer':2,'Backend Developer':2,'Full Stack Developer':1,'QA Engineer':1,'UI/UX Designer':1,'DevOps Engineer':1,'Database Administrator':1},
        high: {'Frontend Developer':3,'Backend Developer':3,'Full Stack Developer':2,'QA Engineer':2,'UI/UX Designer':2,'DevOps Engineer':2,'Database Administrator':1},
        critical: {'Frontend Developer':4,'Backend Developer':4,'Full Stack Developer':3,'QA Engineer':3,'UI/UX Designer':2,'DevOps Engineer':3,'Database Administrator':2}
    };
    const rec = recommendations[complexity];
    if (rec) {
        for (const [role, count] of Object.entries(rec)) {
            const input = document.querySelector(`input[name="allocations[${role}][required]"]`);
            const toggle = document.querySelector(`input[name="allocations[${role}][included]"]`);
            if (input && toggle?.checked && (!input.value || input.value === '0')) input.value = count;
        }
    }
    document.querySelectorAll('input[name$="[complexity]"]').forEach(input => input.value = complexity);
    checkAllAvailability();
}

function toggleDepartmentRow(checkbox) {
    const row = checkbox.closest('tr');
    const input = row.querySelector('.required-count');
    const statusCell = row.querySelector('.status-cell');

    if (!checkbox.checked) {
        input.value = 0;
        statusCell.innerHTML = '<span class="text-muted">Not included</span>';
        checkAllAvailability();
        return;
    }

    const recommendedMin = parseInt(input.dataset.minRecommended || '0', 10);
    if ((parseInt(input.value, 10) || 0) === 0 && recommendedMin > 0) {
        input.value = recommendedMin;
    }

    checkAvailability(input, checkbox.dataset.role || row.cells[0].textContent.trim());
}

function checkAvailability(input, role) {
    const toggle = input.closest('tr').querySelector('.department-toggle');
    if (toggle && !toggle.checked) {
        input.closest('tr').querySelector('.status-cell').innerHTML = '<span class="text-muted">Not included</span>';
        checkAllAvailability();
        return;
    }

    const required = parseInt(input.value) || 0;
    const available = parseInt(input.closest('tr').querySelector('.badge-available').textContent) || 0;
    const statusCell = input.closest('tr').querySelector('.status-cell');
    if (required > 0) {
        statusCell.innerHTML = required > available ? '<span class="badge-danger">Shortage! Need ' + (required - available) + ' more</span>' : '<span class="badge-success">Sufficient</span>';
    } else { statusCell.innerHTML = ''; }
    checkAllAvailability();
}

function checkAllAvailability() {
    let shortages = [], totalRequired = 0, totalAvailable = 0, includedCount = 0;
    document.querySelectorAll('.required-count').forEach(input => {
        const row = input.closest('tr');
        const toggle = row.querySelector('.department-toggle');
        if (toggle && !toggle.checked) {
            return;
        }

        includedCount++;
        const required = parseInt(input.value) || 0;
        const available = parseInt(row.querySelector('.badge-available').textContent) || 0;
        const role = row.querySelector('.department-toggle')?.dataset.role || row.cells[0].textContent.trim();
        totalRequired += required; totalAvailable += available;
        if (required > available) shortages.push(`${role}: Need ${required - available} more (${available} available)`);
    });
    const alertDiv = document.getElementById('alertMessages');
    const summaryDiv = document.getElementById('resourceSummary');
    if (includedCount === 0) {
        alertDiv.innerHTML = `<div class="alert-warning-modern"><i class="fas fa-exclamation-triangle me-2"></i><strong>Select at least one department</strong><div class="small mt-1">Architect must identify which departments are included before moving to diagram upload.</div></div>`;
    } else if (shortages.length > 0) {
        alertDiv.innerHTML = `<div class="alert-warning-modern"><i class="fas fa-exclamation-triangle me-2"></i><strong>Resource Shortages Detected:</strong><ul class="mb-0 mt-2 ps-3">${shortages.map(s => `<li>${s}</li>`).join('')}</ul><small class="d-block mt-2">Consider adjusting allocation or requesting additional resources.</small></div>`;
    } else { alertDiv.innerHTML = ''; }
    const utilization = totalRequired > 0 ? ((totalRequired / totalAvailable) * 100).toFixed(1) : 0;
    const isOverUtilized = utilization > 100;
    summaryDiv.innerHTML = `<div class="mb-3"><strong>Included Departments:</strong> ${includedCount}</div><div class="mb-3"><strong>Total Required:</strong> ${totalRequired} people</div><div class="mb-3"><strong>Total Available:</strong> ${totalAvailable} people</div><div class="mb-3"><strong>Utilization:</strong> ${utilization}%<div class="progress-modern"><div class="progress-bar-modern ${isOverUtilized ? 'danger' : 'success'}" style="width: ${Math.min(utilization, 100)}%"></div></div></div>${includedCount === 0 ? '<div class="alert-warning-modern mb-0"><i class="fas fa-exclamation-triangle me-2"></i>No department selected yet.</div>' : (totalRequired > totalAvailable ? '<div class="alert-warning-modern mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Resource shortage detected! Total required exceeds available resources.</div>' : '<div class="alert-success-modern mb-0"><i class="fas fa-check-circle me-2"></i>Department allocation looks good!</div>')}`;
}

document.querySelectorAll('.department-toggle').forEach(toggleDepartmentRow);
updateAllocation();
initAllocationTooltips();
</script>

<?php include '../../includes/footer.php'; ?>
