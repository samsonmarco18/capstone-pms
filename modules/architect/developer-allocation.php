<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/project-template.php';
require_once '../../includes/project-compilation.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
projectWorkflowEnsureSchema($conn);
projectTemplateEnsureSchema($conn);
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$allocationRoles = [
    'Expert Department' => ['min' => 1, 'max' => 3],
    'UI/UX' => ['min' => 1, 'max' => 3],
    'Frontend' => ['min' => 1, 'max' => 5],
    'Backend' => ['min' => 1, 'max' => 5],
    'Mobile' => ['min' => 0, 'max' => 4],
    'Database' => ['min' => 0, 'max' => 2],
    'DevOps' => ['min' => 0, 'max' => 2],
    'AI' => ['min' => 0, 'max' => 3],
    'Security' => ['min' => 0, 'max' => 2],
    'QA' => ['min' => 1, 'max' => 3],
    'Other Departments' => ['min' => 0, 'max' => 5],
];
$allocationDepartmentIcons = [
    'Expert Department' => 'fa-user-tie',
    'UI/UX' => 'fa-palette',
    'Frontend' => 'fa-code',
    'Backend' => 'fa-cogs',
    'Mobile' => 'fa-mobile-alt',
    'Database' => 'fa-database',
    'DevOps' => 'fa-infinity',
    'AI' => 'fa-brain',
    'Security' => 'fa-shield-alt',
    'QA' => 'fa-clipboard-check',
    'Other Departments' => 'fa-users-cog',
];
$mandatoryDepartments = ['Expert Department'];

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

function allocationComplexityDetail(string $complexity): string
{
    return match ($complexity) {
        'low' => 'Simple application with basic features',
        'high' => 'Complex system with advanced features',
        'critical' => 'Enterprise-grade solution',
        default => 'Standard web/mobile application'
    };
}

function allocationAvailableForRole(array $availableDevs, string $role): int
{
    $available = 0;
    foreach (projectWorkflowDepartmentAliases($role) as $roleAlias) {
        $available += (int) ($availableDevs[$roleAlias] ?? 0);
    }

    return $available;
}

function allocationTemplateRoleCounts(?array $template): array
{
    if (!$template) {
        return [];
    }

    $team = (string) (($template['developer_roles_allocation'] ?? '') ?: ($template['team_composition'] ?? ''));
    $counts = [];
    foreach (explode(',', $team) as $part) {
        $part = trim($part);
        if ($part === '' || !preg_match('/(\d+)\s+(.+)/', $part, $matches)) {
            continue;
        }
        $count = max(0, (int) $matches[1]);
        $roleText = strtolower(trim((string) $matches[2]));
        $roles = [];
        if (str_contains($roleText, 'full')) {
            $roles = ['Frontend', 'Backend'];
        } elseif (str_contains($roleText, 'ui') || str_contains($roleText, 'ux')) {
            $roles = ['UI/UX'];
        } elseif (str_contains($roleText, 'front')) {
            $roles = ['Frontend'];
        } elseif (str_contains($roleText, 'back')) {
            $roles = ['Backend'];
        } elseif (str_contains($roleText, 'mobile')) {
            $roles = ['Mobile'];
        } elseif (str_contains($roleText, 'qa') || str_contains($roleText, 'test')) {
            $roles = ['QA'];
        } elseif (str_contains($roleText, 'database')) {
            $roles = ['Database'];
        } elseif (str_contains($roleText, 'devops')) {
            $roles = ['DevOps'];
        } elseif (str_contains($roleText, 'security')) {
            $roles = ['Security'];
        } elseif (str_contains($roleText, 'ai')) {
            $roles = ['AI'];
        }

        foreach ($roles as $role) {
            $counts[$role] = ($counts[$role] ?? 0) + $count;
        }
    }

    return $counts;
}

$project = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch_assoc();
if (!$project) {
    header('Location: projects.php');
    exit();
}
$projectTemplate = projectTemplateResolveForProject($conn, $project);
$workloadRecommendedTeam = is_array($projectTemplate)
    ? projectTemplateRecommendedTeam(
        (string) ($projectTemplate['project_type'] ?? $project['project_type'] ?? ''),
        (string) ($projectTemplate['core_features'] ?? $project['requested_features'] ?? ''),
        (string) ($projectTemplate['tech_stack'] ?? ''),
        max(1, (int) ($projectTemplate['duration_max_weeks'] ?? 1)),
        (float) ($projectTemplate['budget_max'] ?? $project['sales_budget'] ?? 0)
    )
    : '';
$allocationRecommendationTemplate = is_array($projectTemplate) ? $projectTemplate : [];
if ($workloadRecommendedTeam !== '') {
    $allocationRecommendationTemplate['developer_roles_allocation'] = $workloadRecommendedTeam;
    $allocationRecommendationTemplate['developer_work_plan'] = projectTemplateDeveloperWorkPlan(
        (string) ($projectTemplate['project_type'] ?? $project['project_type'] ?? ''),
        (string) ($projectTemplate['core_features'] ?? $project['requested_features'] ?? ''),
        (string) ($projectTemplate['tech_stack'] ?? ''),
        $workloadRecommendedTeam
    );
}
$templateRoleCounts = allocationTemplateRoleCounts($allocationRecommendationTemplate);
$allocationFeatureCount = count((array) ($projectTemplate['feature_items'] ?? []));
if ($allocationFeatureCount <= 0) {
    $allocationFeatureCount = count(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) ($project['requested_features'] ?? '')) ?: [])));
}
$allocationScheduleWeeks = max(1, (int) ($projectTemplate['duration_max_weeks'] ?? 0));
$templateRoleTasks = [];
foreach ((array) ($allocationRecommendationTemplate['developer_work_plan'] ?? []) as $workItem) {
    $workRole = projectWorkflowDepartmentLabel((string) ($workItem['role'] ?? ''));
    if ($workRole === '') continue;
    $templateRoleTasks[$workRole] = array_values(array_filter(array_map('strval', (array) ($workItem['tasks'] ?? []))));
}

if (!dbColumnExists($conn, 'projects', 'included_departments')) {
    $conn->query("ALTER TABLE projects ADD COLUMN included_departments " . (DB_DRIVER === 'pgsql' ? 'TEXT' : 'LONGTEXT') . " NULL");
    $project = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch_assoc();
}

// Get existing allocations
$existingAllocations = [];
$allocResult = $conn->query("SELECT * FROM developer_allocations WHERE project_id = $projectId");
while($row = $allocResult->fetch_assoc()) {
    $normalizedRole = projectWorkflowDepartmentLabel($row['role'] ?? '');
    $existingAllocations[$normalizedRole] = $row;
}

$developerAllocationReviewCompleted = false;
if (dbTableExists($conn, 'audit_logs')) {
    $completionStmt = $conn->prepare(
        "SELECT id
         FROM audit_logs
         WHERE action = 'developer_allocation'
           AND table_name = 'projects'
           AND record_id = ?
           AND status = 'success'
         ORDER BY created_at DESC, id DESC
         LIMIT 1"
    );
    if ($completionStmt !== false) {
        $completionStmt->bind_param('i', $projectId);
        $completionStmt->execute();
        $developerAllocationReviewCompleted = (bool) $completionStmt->get_result()->fetch_assoc();
        $completionStmt->close();
    }
}

$includedDepartments = [];
$includedDepartmentRaw = trim((string) ($project['included_departments'] ?? ''));
if ($includedDepartmentRaw !== '') {
    $decodedDepartments = json_decode($includedDepartmentRaw, true);
    if (is_array($decodedDepartments)) {
        $includedDepartments = array_values(array_intersect(array_map(static fn($item): string => projectWorkflowDepartmentLabel((string) $item), $decodedDepartments), array_keys($allocationRoles)));
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
    // Only workflow-mandatory departments start selected. Every DSS/template
    // department must be accepted by the Architect through Evortix first.
    $includedDepartments = $mandatoryDepartments;
}

$includedDepartmentLookup = array_fill_keys($includedDepartments, true);

$selectedComplexity = normalizeAllocationComplexity($project['complexity_level'] ?? 'medium');

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
while ($dev = $devQuery->fetch_assoc()) {
    $availableDevs[$dev['department']] = (int) $dev['count'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $postedAllocations = is_array($_POST['allocations'] ?? null) ? $_POST['allocations'] : [];
    $selectedDepartments = [];
    foreach ($postedAllocations as $role => $data) {
        if (!isset($allocationRoles[$role]) || !is_array($data)) {
            continue;
        }

        $requiredCount = max(0, (int) ($data['required'] ?? 0));
        if (isset($data['included']) || $requiredCount > 0 || in_array((string) $role, $mandatoryDepartments, true)) {
            $selectedDepartments[] = (string) $role;
        }
    }

    $selectedDepartments = array_merge($mandatoryDepartments, $selectedDepartments);
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

            $minRequired = in_array((string) $role, $mandatoryDepartments, true) ? (int) ($allocationRoles[$role]['min'] ?? 0) : 0;
            $requiredCount = max($minRequired, (int) ($data['required'] ?? 0));
            $availableForRole = allocationAvailableForRole($availableDevs, (string) $role);
            if ($availableForRole > 0 && $requiredCount > $availableForRole) {
                $_SESSION['error'] = $role . ' required count cannot exceed ' . $availableForRole . ' available active team members.';
                header('Location: developer-allocation.php?id=' . $projectId);
                exit();
            }
            $complexity = $selectedComplexity;
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
    projectCompilationRecord($conn, $projectId, (int) $user['id'], 'developer_allocation');
    header('Location: ../roadmap/methodology.php?project_id=' . $projectId);
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
    table-layout: fixed;
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

.allocation-table th:nth-child(1) {
    width: 52%;
}

.allocation-table th:nth-child(2),
.allocation-table th:nth-child(3) {
    width: 16%;
    text-align: center;
}

.allocation-table th:nth-child(4) {
    width: 16%;
    text-align: center;
}

.department-toggle-wrap {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.department-cell-layout {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.department-visual {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 62px;
    height: 62px;
    border: 0;
    background: transparent;
    color: #496d82;
    font-size: 1.7rem;
    transition: transform 0.2s ease, color 0.2s ease;
}

.department-visual::before {
    position: absolute;
    inset: 9px;
    border: 1px dashed rgba(31, 99, 146, 0.28);
    border-radius: 14px 8px 14px 8px;
    background: rgba(225, 241, 249, 0.5);
    content: "";
    transform: rotate(-5deg);
    transition: border-color 0.2s ease, background-color 0.2s ease, transform 0.2s ease;
}

.department-visual::after {
    position: absolute;
    top: 7px;
    right: 7px;
    width: 8px;
    height: 8px;
    border: 2px solid #ffffff;
    border-radius: 50%;
    background: #77b9a6;
    box-shadow: -37px 38px 0 -1px #8fa9bb;
    content: "";
}

.department-visual > i {
    position: relative;
    z-index: 1;
}

.department-cell-copy {
    flex: 1 1 auto;
    min-width: 0;
}

.allocation-table tbody tr:hover .department-visual {
    color: #1f6392;
    transform: translateY(-2px) rotate(-2deg);
}

.allocation-table tbody tr:hover .department-visual::before {
    border-color: rgba(31, 99, 146, 0.46);
    background: rgba(215, 237, 248, 0.72);
    transform: rotate(1deg);
}

@keyframes departmentIconSelected {
    0% { transform: scale(0.86); }
    62% { transform: scale(1.12); }
    100% { transform: scale(1); }
}

.allocation-table tbody tr:has(.department-toggle:checked) .department-visual {
    color: #155a86;
}

.allocation-table tbody tr:has(.department-toggle:checked) .department-visual::before {
    border-color: rgba(31, 99, 146, 0.4);
    background: rgba(209, 235, 248, 0.82);
}

.allocation-table tbody tr:has(.department-toggle:checked) .department-visual > i {
    animation: departmentIconSelected 0.42s ease-out;
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
    padding: 1rem 1.25rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.allocation-table td:nth-child(2),
.allocation-table td:nth-child(3) {
    text-align: center;
}

.allocation-table td:nth-child(4) {
    min-width: 180px;
    text-align: center;
}

.allocation-table tbody tr {
    transition: background-color 0.2s ease;
}

.allocation-table tbody tr:has(.department-toggle:checked) {
    background: #f2f8fc;
}

.allocation-table tbody tr:hover,
.allocation-table tbody tr:has(.department-toggle:checked):hover {
    background: #edf6fb;
}

.allocation-table tbody td:first-child {
    position: relative;
}

.allocation-table tbody td:first-child::before {
    position: absolute;
    top: 12px;
    bottom: 12px;
    left: 0;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: transparent;
    content: "";
    transition: background-color 0.2s ease;
}

.allocation-table tbody tr:hover td:first-child::before {
    background: var(--theme-primary);
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

.allocation-complexity-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
    padding: 0.85rem 0;
    border-bottom: 1px solid var(--gray-200);
}

.allocation-complexity-main {
    display: flex;
    align-items: center;
    min-width: 0;
    gap: 0.75rem;
}

.allocation-complexity-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    border-radius: 9px;
    background: #edf7fc;
    color: var(--theme-primary);
}

.allocation-complexity-copy {
    display: grid;
    gap: 0.15rem;
    min-width: 0;
}

.allocation-complexity-label {
    color: var(--gray-600);
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.055em;
    line-height: 1.2;
    text-transform: uppercase;
}

.allocation-complexity-value {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    color: var(--gray-900);
    font-size: 0.9rem;
    line-height: 1.35;
}

.allocation-complexity-level {
    color: #165f86;
    font-weight: 700;
}

.allocation-complexity-separator {
    color: var(--gray-400);
}

.allocation-complexity-source {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    flex: 0 0 auto;
    color: var(--gray-600);
    font-size: 0.75rem;
}

.form-control-sm {
    width: 96px;
    text-align: center;
    min-height: 44px;
    margin: 0 auto;
    border-radius: 12px;
}

/* Badges */
.badge-available {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 88px;
    min-height: 34px;
    padding: 0.4rem 0.7rem;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
    background: #dbeafe;
    color: #1d5f9b;
    border: 1px solid rgba(37, 99, 235, 0.10);
}

.badge-success,
.badge-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 0.38rem 0.7rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1.1;
    white-space: nowrap;
}

.badge-success {
    background: #dcfce7;
    color: #047857;
    border: 1px solid rgba(4, 120, 87, 0.12);
}

.badge-danger {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid rgba(185, 28, 28, 0.12);
}

.status-cell {
    color: var(--gray-600);
}

.status-cell .text-muted {
    font-size: 0.8rem;
    white-space: nowrap;
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
    .allocation-table {
        min-width: 760px;
        font-size: 0.8rem;
    }
    .allocation-table th, .allocation-table td { padding: 0.5rem; }
    .form-control-sm { width: 72px; min-height: 38px; }
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-available {
    background: rgba(59, 130, 246, 0.16);
    border-color: rgba(96, 165, 250, 0.22);
    color: #93c5fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-success {
    background: rgba(16, 185, 129, 0.14);
    border-color: rgba(52, 211, 153, 0.22);
    color: #86efac;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-danger {
    background: rgba(239, 68, 68, 0.14);
    border-color: rgba(248, 113, 113, 0.22);
    color: #fca5a5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-complexity-summary {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-complexity-icon {
    background: #0d2236;
    color: #8ad2ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-complexity-value {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-complexity-level {
    color: #8ad2ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-visual {
    border: 0;
    background: transparent;
    color: #69b9ec;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table tbody tr:hover .department-visual,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table tbody tr:has(.department-toggle:checked) .department-visual {
    color: #8ad2ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-visual::before {
    border-color: rgba(138, 210, 255, 0.24);
    background: rgba(18, 48, 74, 0.55);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table tbody tr:hover .department-visual::before,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table tbody tr:has(.department-toggle:checked) .department-visual::before {
    border-color: rgba(138, 210, 255, 0.42);
    background: rgba(21, 59, 88, 0.78);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .department-visual::after {
    border-color: #0a1c2d;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table tbody tr:has(.department-toggle:checked) {
    background: #0a1c2d !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table tbody tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-table tbody tr:has(.department-toggle:checked):hover {
    background: #0d2539 !important;
}

/* Compact allocation review tools replace the permanent right sidebar. */
.allocation-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.allocation-review-tools {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    flex: 0 0 auto;
}

.allocation-review-tool {
    position: relative;
    display: inline-flex;
    align-items: center;
    min-height: 40px;
    gap: 0.55rem;
    padding: 0.45rem 0.72rem;
    border: 1px solid rgba(31, 99, 146, 0.2);
    border-radius: 11px;
    background: #f7fbfd;
    color: #174769;
    box-shadow: 0 5px 14px rgba(15, 76, 129, 0.06);
    cursor: pointer;
    transition: border-color 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.allocation-review-tool:hover,
.allocation-review-tool:focus-visible,
.allocation-review-tool[aria-expanded="true"] {
    border-color: rgba(13, 103, 134, 0.42);
    background: #eef7fb;
    box-shadow: 0 8px 18px rgba(15, 76, 129, 0.12);
    outline: none;
    transform: translateY(-1px);
}

.allocation-review-tool-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    color: #0d6786;
    font-size: 0.9rem;
}

.allocation-resource-shortage-count {
    position: absolute;
    top: -9px;
    right: -11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 17px;
    height: 17px;
    padding: 0 4px;
    border: 2px solid #f7fbfd;
    border-radius: 999px;
    background: #dc3545;
    color: #ffffff;
    font-size: 0.58rem;
    font-weight: 800;
    line-height: 1;
}

.allocation-resource-shortage-count[hidden] {
    display: none;
}

.allocation-review-tool.has-shortage {
    border-color: rgba(217, 119, 6, 0.3);
    background: #fffaf0;
}

.allocation-card-header .allocation-review-tool.has-shortage .allocation-review-tool-icon > i,
.allocation-review-tool.has-shortage .allocation-review-tool-value {
    color: #a85d08 !important;
}

.allocation-card-header .allocation-review-tool-icon > i {
    color: #0d6786 !important;
    font-weight: 900;
}

.allocation-review-tool-copy {
    display: grid;
    gap: 0.02rem;
    min-width: 0;
    text-align: left;
}

.allocation-review-tool-label {
    color: #5f7487;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.2;
    text-transform: uppercase;
}

.allocation-review-tool-value {
    color: #174769;
    font-size: 0.76rem;
    font-weight: 700;
    line-height: 1.25;
    white-space: nowrap;
}

.allocation-review-notification {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 7px;
    height: 7px;
    border: 2px solid #f7fbfd;
    border-radius: 50%;
    background: #f59e0b;
}

.allocation-review-tool.is-reviewed .allocation-review-notification {
    display: none;
}

.allocation-review-drawer-backdrop {
    position: fixed;
    inset: 0;
    z-index: 10540;
    background: rgba(6, 17, 30, 0.42);
    opacity: 0;
    backdrop-filter: blur(2px);
    transition: opacity 0.2s ease;
}

.allocation-review-drawer-backdrop[hidden] {
    display: none;
}

.allocation-review-drawer-backdrop.is-visible {
    opacity: 1;
}

.allocation-review-drawer {
    position: fixed;
    top: 0;
    right: 0;
    z-index: 10550;
    display: grid;
    grid-template-rows: auto minmax(0, 1fr);
    width: min(430px, 100vw);
    height: 100dvh;
    border-left: 1px solid #d6e4ed;
    background: #ffffff;
    box-shadow: -24px 0 54px rgba(6, 17, 30, 0.22);
    transform: translateX(104%);
    visibility: hidden;
    transition: transform 0.24s ease, visibility 0.24s ease;
}

.allocation-review-drawer.is-open {
    transform: translateX(0);
    visibility: visible;
}

.allocation-review-drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #dce7ef;
    background: #f5f9fc;
}

.allocation-review-drawer-title {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 0;
}

.allocation-review-drawer-title > i {
    color: #0d6786;
    font-size: 1rem;
}

.allocation-review-drawer-title-copy {
    display: grid;
    gap: 0.08rem;
}

.allocation-review-drawer-title-copy strong {
    color: #17324d;
    font-size: 0.9rem;
}

.allocation-review-drawer-title-copy span {
    color: #6a7f91;
    font-size: 0.7rem;
}

.allocation-review-drawer-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    padding: 0;
    border: 1px solid #d6e4ed;
    border-radius: 9px;
    background: #ffffff;
    color: #526b80;
}

.allocation-review-drawer-close:hover,
.allocation-review-drawer-close:focus-visible {
    border-color: #0d6786;
    color: #0d6786;
    outline: none;
}

.allocation-review-drawer-body {
    min-height: 0;
    padding: 1rem 1.1rem 1.25rem;
    overflow-y: auto;
}

body.allocation-review-drawer-open {
    overflow: hidden;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-review-tool {
    border-color: rgba(138, 210, 255, 0.18);
    background: #0d2236;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-review-tool.has-shortage {
    border-color: rgba(251, 191, 36, 0.3);
    background: #2a2112;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-resource-shortage-count {
    border-color: #2a2112;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(.allocation-review-tool-label, .allocation-review-drawer-title-copy span) {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(.allocation-review-tool-value, .allocation-review-drawer-title-copy strong) {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-card-header .allocation-review-tool-icon > i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-review-drawer-title > i {
    color: #69b9ec !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-card-header .allocation-review-tool.has-shortage .allocation-review-tool-icon > i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-review-tool.has-shortage .allocation-review-tool-value {
    color: #fbbf24 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-review-drawer {
    border-left-color: rgba(138, 210, 255, 0.15);
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-review-drawer-header {
    border-bottom-color: rgba(138, 210, 255, 0.15);
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .allocation-review-drawer-close {
    border-color: rgba(138, 210, 255, 0.18);
    background: #0d2236;
    color: #b9d6e8;
}

@media (max-width: 767.98px) {
    .allocation-complexity-summary {
        align-items: flex-start;
        flex-direction: column;
        gap: 0.65rem;
    }

    .allocation-complexity-source {
        margin-left: 2.95rem;
    }

    .allocation-card-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .allocation-review-tools {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        width: 100%;
    }

    .allocation-review-tool {
        width: 100%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .department-visual,
    .allocation-table tbody tr:has(.department-toggle:checked) .department-visual > i {
        animation: none;
        transition: none;
    }

    .allocation-review-drawer,
    .allocation-review-drawer-backdrop {
        transition: none;
    }
}

html:not([data-theme="dark"]) body:not([data-theme="dark"]):not(.dark-mode)
    .chat-bot-speech.evortix-architect-dss .evortix-dss-heading {
    color: #083a5d !important;
    opacity: 1 !important;
}

html:not([data-theme="dark"]) body:not([data-theme="dark"]):not(.dark-mode)
    .chat-bot-speech.evortix-architect-dss :is(
        .evortix-dss-copy,
        .evortix-dss-points,
        .evortix-dss-points li
    ) {
    color: #175f8f !important;
    opacity: 1 !important;
}
</style>

<div class="container-fluid px-4 py-4">
    <?php di_render_project_attention_banner($conn, $user, $projectId); ?>

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
        <a class="progress-step" href="../roadmap/methodology.php?project_id=<?php echo $projectId; ?>"><div class="step-number">5</div><div class="step-label">Roadmap</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="upload-diagram.php?id=<?php echo $projectId; ?>"><div class="step-number">6</div><div class="step-label">Documents</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="summary.php?id=<?php echo $projectId; ?>"><div class="step-number">7</div><div class="step-label">Summary</div></a>
    </div>

    <?php include '../../includes/architect-project-review-panel.php'; ?>

    <div class="row g-4">
        <!-- Main Form Column -->
        <div class="col-12">
            <?php if (is_array($projectTemplate)): ?>
            <div class="info-card mb-4 architect-dss-source-card">
                <div class="card-header">
                    <h5><i class="fas fa-lightbulb"></i> Template Team Recommendation</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2"><strong><?php echo htmlspecialchars((string) ($projectTemplate['project_type'] ?? 'Matched template')); ?></strong></p>
                    <p class="mb-2"><strong>Suggested team:</strong> <?php echo htmlspecialchars($workloadRecommendedTeam !== '' ? $workloadRecommendedTeam : (string) (($projectTemplate['developer_roles_allocation'] ?? '') ?: ($projectTemplate['team_composition'] ?? 'Not specified'))); ?></p>
                    <p class="mb-0 text-muted"><?php echo htmlspecialchars((string) ($projectTemplate['implementation_notes'] ?? 'Adjust role counts based on actual client scope and available team capacity.')); ?></p>
                    <div class="architect-dss-source-controls">
                        <button type="button" class="btn btn-primary btn-sm" data-dss-team-decision="accept"><i class="fas fa-robot me-1"></i> Accept suggested team</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-dss-team-decision="skip">Skip suggestions</button>
                    </div>
                    <small class="d-block mt-2 text-muted">Recommended departments and counts remain unchanged until you accept.</small>
                </div>
            </div>
            <?php endif; ?>
            <div class="form-card">
                <div class="card-header allocation-card-header">
                    <h5><i class="fas fa-user-plus"></i> Team Requirements</h5>
                    <div class="allocation-review-tools" aria-label="Allocation review tools">
                        <button type="button" class="allocation-review-tool" data-allocation-drawer-open="resources" aria-controls="resourceAllocationDrawer" aria-expanded="false">
                            <span class="allocation-review-tool-icon">
                                <i class="fas fa-chart-pie" id="resourceToolIcon" aria-hidden="true"></i>
                                <span class="allocation-resource-shortage-count" id="resourceShortageCount" hidden>0</span>
                            </span>
                            <span class="allocation-review-tool-copy">
                                <span class="allocation-review-tool-label">Resources</span>
                                <strong class="allocation-review-tool-value" id="resourceToolValue">0 included / 0 required</strong>
                            </span>
                        </button>
                        <button type="button" class="allocation-review-tool" data-allocation-drawer-open="guidelines" aria-controls="allocationGuidelinesDrawer" aria-expanded="false">
                            <span class="allocation-review-tool-icon"><i class="fas fa-circle-info" aria-hidden="true"></i></span>
                            <span class="allocation-review-tool-copy">
                                <span class="allocation-review-tool-label">Allocation</span>
                                <strong class="allocation-review-tool-value">View guidelines</strong>
                            </span>
                            <span class="allocation-review-notification" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" id="allocationForm">
                        <div class="allocation-complexity-summary mb-4" aria-readonly="true">
                            <div class="allocation-complexity-main">
                                <span class="allocation-complexity-icon" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
                                <span class="allocation-complexity-copy">
                                    <span class="allocation-complexity-label">
                                        Project Complexity
                                        <i class="fas fa-circle-info tooltip-helper ms-1" data-bs-toggle="tooltip" title="Complexity is generated by Sales and cannot be changed during architect allocation."></i>
                                    </span>
                                    <span class="allocation-complexity-value">
                                        <strong class="allocation-complexity-level"><?php echo htmlspecialchars(ucfirst($selectedComplexity)); ?></strong>
                                        <span class="allocation-complexity-separator" aria-hidden="true">&middot;</span>
                                        <span><?php echo htmlspecialchars(allocationComplexityDetail($selectedComplexity)); ?></span>
                                    </span>
                                </span>
                            </div>
                            <input type="hidden" id="complexityLevel" value="<?php echo htmlspecialchars($selectedComplexity); ?>">
                            <span class="allocation-complexity-source"><i class="fas fa-lock"></i> Generated by Sales</span>
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
foreach($allocationRoles as $role => $limits):
    $isMandatory = in_array($role, $mandatoryDepartments, true);
    $templateRecommendedCount = (int) ($templateRoleCounts[$role] ?? 0);
    $isTemplateRecommended = $templateRecommendedCount > 0;
    $isIncluded = isset($includedDepartmentLookup[$role]);
    if ($isMandatory) {
        $isIncluded = true;
    }
    $existing = array_key_exists($role, $existingAllocations)
        ? (int) ($existingAllocations[$role]['required_count'] ?? 0)
        : ($isIncluded ? (int) ($limits['min'] ?? 0) : 0);
                                        if ($isMandatory) {
                                            $existing = max((int) ($limits['min'] ?? 0), $existing);
                                        }
                                        $existingComplexity = $selectedComplexity;
                                        $available = allocationAvailableForRole($availableDevs, $role);
                                         $inputMax = max($available, (int) ($limits['min'] ?? 0), $templateRecommendedCount);
                                         $isShortage = $existing > $available;
                                         $departmentIcon = $allocationDepartmentIcons[$role] ?? 'fa-users';
                                     ?>
                                     <tr>
                                         <td class="fw-semibold">
                                             <div class="department-cell-layout">
                                                 <span class="department-visual" aria-hidden="true">
                                                     <i class="fas <?php echo htmlspecialchars($departmentIcon); ?>"></i>
                                                 </span>
                                                 <div class="department-cell-copy">
                                            <?php if ($isMandatory): ?>
                                                <input type="hidden" name="allocations[<?php echo $role; ?>][included]" value="1">
                                            <?php endif; ?>
                                            <label class="department-toggle-wrap">
                                                <input
                                                    type="checkbox"
                                                    id="allocation_toggle_<?php echo md5($role); ?>"
                                                    class="department-toggle"
                                                    name="allocations[<?php echo $role; ?>][included]"
                                                    value="1"
                                                    data-role="<?php echo htmlspecialchars($role); ?>"
                                                    data-template-recommended="<?php echo $isTemplateRecommended ? '1' : '0'; ?>"
                                                    data-template-count="<?php echo $templateRecommendedCount; ?>"
                                                    onchange="toggleDepartmentRow(this)"
                                                    <?php echo $isIncluded ? 'checked' : ''; ?>
                                                    <?php echo $isMandatory ? 'disabled' : ''; ?>
                                                >
                                            <span><?php echo $role; ?></span>
                                            <?php if ($isTemplateRecommended): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis ms-1">Template: <?php echo $templateRecommendedCount; ?></span>
                                            <?php endif; ?>
                                            </label>
                                            <span class="department-role-note"><?php echo $isMandatory ? 'First handoff for expert estimation.' : 'SUL and team members are matched by this department.'; ?></span>
                                            <?php if (!empty($templateRoleTasks[$role])): ?>
                                                <span class="department-role-note"><strong>Template tasks:</strong> <?php echo htmlspecialchars(implode(' · ', $templateRoleTasks[$role])); ?></span>
                                             <?php endif; ?>
                                                 </div>
                                             </div>
                                         </td>
                                         <td>
                                            <input type="number" name="allocations[<?php echo $role; ?>][required]" 
                                                   id="allocation_count_<?php echo md5($role); ?>"
                                                   class="form-control-modern form-control-sm required-count" 
                                                   value="<?php echo $existing; ?>" 
                                                   min="<?php echo $isMandatory ? (int) ($limits['min'] ?? 0) : 0; ?>"
                                                   <?php echo $isMandatory ? 'required' : ''; ?>
                                                   max="<?php echo (int) $inputMax; ?>"
                                                   data-min-recommended="<?php echo (int) ($limits['min'] ?? 0); ?>"
                                                   data-template-recommended="<?php echo $isTemplateRecommended ? '1' : '0'; ?>"
                                                   data-template-count="<?php echo $templateRecommendedCount; ?>"
                                                   onchange="checkAvailability(this, '<?php echo $role; ?>')">
                                            <input type="hidden" name="allocations[<?php echo $role; ?>][complexity]" value="<?php echo htmlspecialchars($existingComplexity); ?>">
                                        </td>
                                        <td><span class="badge-available"><?php echo $available; ?> available</span></td>
                                        <td class="status-cell">
                                            <?php if($existing > 0): ?>
                                                <span class="badge-<?php echo $isShortage ? 'danger' : 'success'; ?>">
                                                    <?php echo $isShortage ? 'Need ' . ($existing - $available) . ' more' : 'Sufficient'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 text-end">
                            <button type="submit" class="btn-primary-modern">
                                <i class="fas fa-arrow-right"></i> Proceed to Roadmap
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
    </div>

    <div class="allocation-review-drawer-backdrop" data-allocation-drawer-backdrop hidden></div>

    <aside class="allocation-review-drawer" id="resourceAllocationDrawer" data-allocation-drawer="resources"
           role="dialog" aria-modal="true" aria-labelledby="resourceAllocationDrawerTitle" aria-hidden="true">
        <div class="allocation-review-drawer-header">
            <div class="allocation-review-drawer-title">
                <i class="fas fa-chart-pie" aria-hidden="true"></i>
                <span class="allocation-review-drawer-title-copy">
                    <strong id="resourceAllocationDrawerTitle">Resource Summary</strong>
                    <span>Live department capacity and utilization</span>
                </span>
            </div>
            <button type="button" class="allocation-review-drawer-close" data-allocation-drawer-close aria-label="Close resource summary">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="allocation-review-drawer-body">
            <div id="resourceSummary">
                <p class="text-muted text-center py-3">Select departments to see the resource summary</p>
            </div>
        </div>
    </aside>

    <aside class="allocation-review-drawer" id="allocationGuidelinesDrawer" data-allocation-drawer="guidelines"
           role="dialog" aria-modal="true" aria-labelledby="allocationGuidelinesDrawerTitle" aria-hidden="true">
        <div class="allocation-review-drawer-header">
            <div class="allocation-review-drawer-title">
                <i class="fas fa-circle-info" aria-hidden="true"></i>
                <span class="allocation-review-drawer-title-copy">
                    <strong id="allocationGuidelinesDrawerTitle">Allocation Guidelines</strong>
                    <span>Recommended staffing by complexity</span>
                </span>
            </div>
            <button type="button" class="allocation-review-drawer-close" data-allocation-drawer-close aria-label="Close allocation guidelines">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="allocation-review-drawer-body">
            <ul class="guidelines-list">
                <li><i class="fas fa-info-circle"></i> Low complexity: 2-3 developers</li>
                <li><i class="fas fa-info-circle"></i> Medium complexity: 4-5 developers</li>
                <li><i class="fas fa-info-circle"></i> High complexity: 6-8 developers</li>
                <li><i class="fas fa-info-circle"></i> Critical: 8+ developers</li>
                <li><i class="fas fa-exclamation-triangle"></i> Red alerts indicate resource shortage</li>
            </ul>
        </div>
    </aside>
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

const allocationDrawerButtons = Array.from(document.querySelectorAll('[data-allocation-drawer-open]'));
const allocationDrawerBackdrop = document.querySelector('[data-allocation-drawer-backdrop]');
let lastAllocationDrawerTrigger = null;
let allocationBackdropTimer = null;

function closeAllocationDrawer(returnFocus = true) {
    const openDrawer = document.querySelector('[data-allocation-drawer].is-open');
    if (!openDrawer) {
        return;
    }

    openDrawer.classList.remove('is-open');
    openDrawer.setAttribute('aria-hidden', 'true');
    allocationDrawerButtons.forEach(button => button.setAttribute('aria-expanded', 'false'));
    allocationDrawerBackdrop?.classList.remove('is-visible');
    document.body.classList.remove('allocation-review-drawer-open');

    window.clearTimeout(allocationBackdropTimer);
    allocationBackdropTimer = window.setTimeout(() => {
        if (allocationDrawerBackdrop && !document.querySelector('[data-allocation-drawer].is-open')) {
            allocationDrawerBackdrop.hidden = true;
        }
    }, 240);

    if (returnFocus) {
        lastAllocationDrawerTrigger?.focus();
    }
}

function openAllocationDrawer(name, trigger) {
    closeAllocationDrawer(false);
    const drawer = document.querySelector(`[data-allocation-drawer="${name}"]`);
    if (!drawer) {
        return;
    }

    window.clearTimeout(allocationBackdropTimer);
    if (allocationDrawerBackdrop) {
        allocationDrawerBackdrop.hidden = false;
        window.requestAnimationFrame(() => allocationDrawerBackdrop.classList.add('is-visible'));
    }

    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    trigger.setAttribute('aria-expanded', 'true');
    if (name === 'guidelines') {
        trigger.classList.add('is-reviewed');
    }
    document.body.classList.add('allocation-review-drawer-open');
    lastAllocationDrawerTrigger = trigger;
    drawer.querySelector('[data-allocation-drawer-close]')?.focus();
}

allocationDrawerButtons.forEach(button => {
    button.addEventListener('click', () => {
        const drawerName = String(button.dataset.allocationDrawerOpen || '');
        const isOpen = document.querySelector(`[data-allocation-drawer="${drawerName}"]`)?.classList.contains('is-open');
        if (isOpen) {
            closeAllocationDrawer();
        } else {
            openAllocationDrawer(drawerName, button);
        }
    });
});

document.querySelectorAll('[data-allocation-drawer-close]').forEach(button => {
    button.addEventListener('click', () => closeAllocationDrawer());
});
allocationDrawerBackdrop?.addEventListener('click', () => closeAllocationDrawer());
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        closeAllocationDrawer();
        return;
    }

    if (event.key === 'Tab') {
        const openDrawer = document.querySelector('[data-allocation-drawer].is-open');
        if (!openDrawer) {
            return;
        }

        const focusableElements = Array.from(openDrawer.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
            .filter(element => !element.disabled && element.offsetParent !== null);
        if (focusableElements.length === 0) {
            event.preventDefault();
            return;
        }

        const firstFocusable = focusableElements[0];
        const lastFocusable = focusableElements[focusableElements.length - 1];
        if (event.shiftKey && document.activeElement === firstFocusable) {
            event.preventDefault();
            lastFocusable.focus();
        } else if (!event.shiftKey && document.activeElement === lastFocusable) {
            event.preventDefault();
            firstFocusable.focus();
        }
    }
});

document.querySelectorAll('[data-dss-team-decision]').forEach((button) => {
    button.addEventListener('click', () => {
        const accept = button.dataset.dssTeamDecision === 'accept';
        document.querySelectorAll('.department-toggle[data-template-recommended="1"]').forEach((toggle) => {
            if (!toggle.disabled) toggle.checked = accept;
            const input = toggle.closest('tr')?.querySelector('.required-count');
            if (input) input.value = accept ? (input.dataset.templateCount || input.dataset.minRecommended || '0') : '0';
        });
        checkAllAvailability();
        window.appToast?.(accept ? 'Team suggestion accepted' : 'Team suggestion skipped', accept ? 'Review the selected departments and counts before continuing.' : 'No optional template departments were selected.', accept ? 'success' : 'info');
    });
});

function updateAllocation() {
    const complexity = document.getElementById('complexityLevel').value;
    document.querySelectorAll('input[name$="[complexity]"]').forEach(input => input.value = complexity);
    checkAllAvailability();
}

async function toggleDepartmentRow(checkbox) {
    const row = checkbox.closest('tr');
    const input = row.querySelector('.required-count');
    const statusCell = row.querySelector('.status-cell');

    if (!checkbox.checked) {
        if (String(checkbox.dataset.templateRecommended || '0') === '1' && String(checkbox.dataset.evortixDecision || '') !== '1') {
            const role = checkbox.dataset.role || row.cells[0].textContent.trim();
            const shouldRemove = await window.evortixConfirm?.({
                title: `Skip ${role}?`,
                message: `I recommend including ${role} based on this project's template. Skipping it may leave part of the planned work unassigned.`,
                cancelLabel: 'Keep recommendation',
                confirmLabel: 'Skip this'
            });
            if (!shouldRemove) {
                checkbox.checked = true;
                return;
            }
        }
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

async function checkAvailability(input, role) {
    const toggle = input.closest('tr').querySelector('.department-toggle');
    if (toggle && !toggle.checked) {
        input.closest('tr').querySelector('.status-cell').innerHTML = '<span class="text-muted">Not included</span>';
        checkAllAvailability();
        return;
    }

    let required = parseInt(input.value) || 0;
    const templateCount = parseInt(input.dataset.templateCount || '0', 10);
    if (String(input.dataset.templateRecommended || '0') === '1' && templateCount > 0 && required < templateCount && String(input.dataset.evortixDecision || '') !== '1') {
        const shouldReduce = await window.evortixConfirm?.({
            title: `Reduce ${role} allocation?`,
            message: `I recommend ${templateCount} ${role} resource${templateCount === 1 ? '' : 's'} for this project. Reducing it may affect the current estimate and delivery plan.`,
            cancelLabel: 'Keep recommendation',
            confirmLabel: 'Reduce allocation',
            resumeDelay: 1800
        });
        if (!shouldReduce) {
            input.value = templateCount;
            required = templateCount;
        } else if (required === 0 && toggle && !toggle.disabled) {
            toggle.dataset.evortixDecision = '1';
            toggle.checked = false;
            input.value = '0';
            delete toggle.dataset.evortixDecision;
            input.closest('tr').querySelector('.status-cell').innerHTML = '<span class="text-muted">Not included</span>';
            checkAllAvailability();
            window.evortixAnnounceNotification?.(
                `${role} removed`,
                `Done. I reduced the ${role} allocation from ${templateCount} to 0 and removed the department from this project.`,
                ''
            );
            return;
        }
    }
    const available = parseInt(input.closest('tr').querySelector('.badge-available').textContent) || 0;
    const statusCell = input.closest('tr').querySelector('.status-cell');
    if (required > 0) {
        statusCell.innerHTML = required > available ? '<span class="badge-danger">Need ' + (required - available) + ' more</span>' : '<span class="badge-success">Sufficient</span>';
    } else { statusCell.innerHTML = ''; }
    checkAllAvailability();
    if (required < templateCount && String(input.dataset.evortixDecision || '') !== '1') {
        window.evortixAnnounceNotification?.(
            `${role} allocation reduced`,
            `Done. I updated the ${role} allocation from ${templateCount} to ${required}.`,
            ''
        );
    }
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
    const summaryDiv = document.getElementById('resourceSummary');
    let allocationStateHtml = '';
    if (includedCount === 0) {
        allocationStateHtml = `<div class="alert-warning-modern mb-0"><i class="fas fa-exclamation-triangle me-2"></i><strong>Select at least one department</strong><div class="small mt-1">Identify the departments included before continuing to the roadmap.</div></div>`;
    } else if (shortages.length > 0) {
        allocationStateHtml = `<div class="alert-warning-modern mb-0"><i class="fas fa-exclamation-triangle me-2"></i><strong>Resource Shortages Detected</strong><ul class="mb-0 mt-2 ps-3">${shortages.map(shortage => `<li>${shortage}</li>`).join('')}</ul><small class="d-block mt-2">Consider adjusting allocation or requesting additional resources.</small></div>`;
    } else {
        allocationStateHtml = '<div class="alert-success-modern mb-0"><i class="fas fa-check-circle me-2"></i>Department allocation looks good!</div>';
    }

    const utilization = totalRequired > 0 && totalAvailable > 0 ? Number(((totalRequired / totalAvailable) * 100).toFixed(1)) : 0;
    const utilizationLabel = totalRequired > 0 && totalAvailable === 0 ? 'No capacity' : `${utilization}%`;
    const isOverUtilized = totalRequired > totalAvailable;
    summaryDiv.innerHTML = `${allocationStateHtml}<div class="mt-4 mb-3"><strong>Included Departments:</strong> ${includedCount}</div><div class="mb-3"><strong>Total Required:</strong> ${totalRequired} people</div><div class="mb-3"><strong>Total Available:</strong> ${totalAvailable} people</div><div class="mb-0"><strong>Utilization:</strong> ${utilizationLabel}<div class="progress-modern"><div class="progress-bar-modern ${isOverUtilized ? 'danger' : 'success'}" style="width: ${Math.min(utilization, 100)}%"></div></div></div>`;
    const resourceToolValue = document.getElementById('resourceToolValue');
    const resourceTool = document.querySelector('[data-allocation-drawer-open="resources"]');
    const resourceToolIcon = document.getElementById('resourceToolIcon');
    const resourceShortageCount = document.getElementById('resourceShortageCount');
    if (resourceToolValue) {
        resourceToolValue.textContent = shortages.length > 0
            ? `${shortages.length} shortages / ${totalRequired} required`
            : `${includedCount} included / ${totalRequired} required`;
    }
    if (resourceTool) {
        resourceTool.classList.toggle('has-shortage', shortages.length > 0);
        resourceTool.setAttribute('aria-label', shortages.length > 0
            ? `Open resource summary. ${shortages.length} shortages detected.`
            : 'Open resource summary. No shortages detected.');
    }
    if (resourceToolIcon) {
        resourceToolIcon.classList.toggle('fa-chart-pie', shortages.length === 0);
        resourceToolIcon.classList.toggle('fa-exclamation-triangle', shortages.length > 0);
    }
    if (resourceShortageCount) {
        resourceShortageCount.hidden = shortages.length === 0;
        resourceShortageCount.textContent = String(shortages.length);
    }
}

document.querySelectorAll('.department-toggle').forEach((toggle) => {
    const countInput = toggle.closest('tr')?.querySelector('.required-count');
    toggle.dataset.evortixDecision = '1';
    if (countInput) countInput.dataset.evortixDecision = '1';
    toggleDepartmentRow(toggle);
    delete toggle.dataset.evortixDecision;
    if (countInput) delete countInput.dataset.evortixDecision;
});
updateAllocation();
initAllocationTooltips();
</script>

<?php if (is_array($projectTemplate)):
    $architectDssItems = [];
    foreach ($templateRoleCounts as $role => $count) {
        if ((int) $count <= 0 || !isset($allocationRoles[$role])) continue;
        $architectDssItems[] = [
            'label' => (string) $role,
            'detail' => 'Based on ' . $allocationFeatureCount . ' planned feature' . ($allocationFeatureCount === 1 ? '' : 's') . ' across about ' . $allocationScheduleWeeks . ' weeks, Evortix recommends ' . (int) $count . ' ' . $role . ' resource' . ((int) $count === 1 ? '' : 's') . '.',
            'points' => array_slice($templateRoleTasks[$role] ?? [], 0, 3),
            'selector' => '#allocation_toggle_' . md5((string) $role),
            'countSelector' => '#allocation_count_' . md5((string) $role),
            'count' => (int) $count,
        ];
    }
    $architectDssAssistantConfig = [
        'title' => 'Team recommendations ready',
        'message' => 'I calculated role headcounts from the feature workload, delivery schedule, specialist needs, and the tasks each role must handle. Review each allocation recommendation.',
        'stateKey' => 'evortix.dss.team.project.' . (int) $projectId,
        'persistDecisions' => false,
        'autoStart' => !$developerAllocationReviewCompleted,
        'startDelay' => 100,
        'pointAtMessage' => true,
        'pointSide' => 'left',
        'items' => $architectDssItems,
        'completedTitle' => 'Team review complete',
    ];
    include '../../includes/architect-dss-assistant.php';
endif; ?>

<?php include '../../includes/footer.php'; ?>
