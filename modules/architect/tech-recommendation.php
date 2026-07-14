<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-template.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
projectTemplateEnsureSchema($conn);
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$hasCloudPlatform = dbColumnExists($conn, 'projects', 'cloud_platform');
$hasDatabaseServer = dbColumnExists($conn, 'projects', 'database_server');
$hasStorageRequirements = dbColumnExists($conn, 'projects', 'storage_requirements');
$hasAdditionalServices = dbColumnExists($conn, 'projects', 'additional_services');

$project = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch_assoc();
if (!$project) {
    header('Location: projects.php');
    exit();
}
$projectTemplate = !empty($project['project_template_snapshot'])
    ? json_decode((string) $project['project_template_snapshot'], true)
    : null;
if (!is_array($projectTemplate)) {
    $projectTemplate = projectTemplateFindByType($conn, (string) ($project['project_type'] ?? ''));
}

function architectTechNormalize(string $value): string
{
    return strtolower(trim((string) preg_replace('/[^a-z0-9+#.]+/i', ' ', $value)));
}

function architectTechIsTemplateRecommended(array $template = null, string $technologyName = '', string $technologyCategory = ''): bool
{
    if (!$template || $technologyName === '') {
        return false;
    }

    $needle = architectTechNormalize($technologyName);
    $category = architectTechNormalize($technologyCategory);
    $haystack = trim($needle . ' ' . $category);
    foreach (($template['tech_items'] ?? []) as $item) {
        $candidate = architectTechNormalize((string) $item);
        if ($candidate !== '' && ($candidate === $needle || str_contains($candidate, $needle) || str_contains($needle, $candidate))) {
            return true;
        }

        $isDatabaseRecommendation = preg_match('/\b(mysql|mariadb|postgres|postgresql|sql|database|firestore|firebase)\b/i', (string) $item) === 1;
        $isDatabaseService = str_contains($haystack, 'database') || str_contains($haystack, 'sql');
        if ($isDatabaseRecommendation && $isDatabaseService) {
            return true;
        }

        $isHostingRecommendation = preg_match('/\b(hosting|vps|cloud|aws|azure|web app|managed application)\b/i', (string) $item) === 1;
        $isHostingService = str_contains($haystack, 'cloud') || str_contains($haystack, 'hosting') || str_contains($haystack, 'web app');
        if ($isHostingRecommendation && $isHostingService) {
            return true;
        }
    }

    return false;
}
$technologies = $conn->query("SELECT * FROM technologies WHERE status = 'active'");

$existingRecommendations = [];
$existingRecommendationResult = $conn->query("
    SELECT tech_id, service_type, estimated_cost, notes
    FROM tech_recommendations
    WHERE project_id = $projectId
");
while ($row = $existingRecommendationResult->fetch_assoc()) {
    $existingRecommendations[(int) $row['tech_id']] = $row;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cloudPlatform = trim((string) ($_POST['cloud_platform'] ?? ''));
    $databaseServer = trim((string) ($_POST['database_server'] ?? ''));
    $storageRequirements = trim((string) ($_POST['storage'] ?? ''));
    $additionalServices = trim((string) ($_POST['additional_services'] ?? ''));

    // Clear existing recommendations
    $conn->query("DELETE FROM tech_recommendations WHERE project_id = $projectId");
    
    // Save new recommendations
    if(isset($_POST['technologies'])) {
        foreach($_POST['technologies'] as $tech) {
            $techId = (int) ($tech['id'] ?? 0);
            $serviceType = trim((string) ($tech['service_type'] ?? ''));
            $cost = (float) ($tech['cost'] ?? 0);
            $annualCost = $cost * 12;
            $notes = trim((string) ($tech['notes'] ?? ''));

            if ($techId <= 0) {
                continue;
            }
            
            $stmt = $conn->prepare("
                INSERT INTO tech_recommendations (project_id, tech_id, service_type, estimated_cost, annual_cost, notes, createdby)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisddsi", $projectId, $techId, $serviceType, $cost, $annualCost, $notes, $user['id']);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($hasCloudPlatform || $hasDatabaseServer || $hasStorageRequirements || $hasAdditionalServices) {
        $updateParts = [];
        $types = '';
        $params = [];

        if ($hasCloudPlatform) {
            $updateParts[] = 'cloud_platform = ?';
            $types .= 's';
            $params[] = $cloudPlatform;
        }
        if ($hasDatabaseServer) {
            $updateParts[] = 'database_server = ?';
            $types .= 's';
            $params[] = $databaseServer;
        }
        if ($hasStorageRequirements) {
            $updateParts[] = 'storage_requirements = ?';
            $types .= 's';
            $params[] = $storageRequirements;
        }
        if ($hasAdditionalServices) {
            $updateParts[] = 'additional_services = ?';
            $types .= 's';
            $params[] = $additionalServices;
        }

        if (!empty($updateParts)) {
            $types .= 'i';
            $params[] = $projectId;
            $projectUpdateStmt = $conn->prepare("UPDATE projects SET " . implode(', ', $updateParts) . " WHERE id = ?");
            $projectUpdateStmt->bind_param($types, ...$params);
            $projectUpdateStmt->execute();
            $projectUpdateStmt->close();
        }
    }
    
    logActivity($user['id'], 'tech_recommendation', 'projects', $projectId, null, $_POST);
    
    header('Location: developer-allocation.php?id=' . $projectId);
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

/* Tech Item */
#technologiesContainer {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.tech-item {
    background:
        radial-gradient(circle at 100% 0%, rgba(34, 211, 238, 0.12), transparent 34%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.82) 0%, rgba(255, 255, 255, 0) 38%, rgba(31, 99, 146, 0.055) 100%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.42), transparent 56%),
        rgba(255, 255, 255, 0.94);
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 20px;
    padding: 1rem 1.15rem;
    margin-bottom: 0;
    transition: border-color 0.22s ease, box-shadow 0.22s ease, background 0.22s ease;
    box-shadow: 0 10px 24px rgba(15, 76, 129, 0.07), 0 0 18px rgba(34, 211, 238, 0.05);
    overflow: hidden;
}

.tech-item:hover {
    border-color: rgba(34, 211, 238, 0.24);
    box-shadow: 0 14px 30px rgba(15, 76, 129, 0.10), 0 0 24px rgba(34, 211, 238, 0.08);
}

.tech-checkbox {
    width: 18px;
    height: 18px;
    margin-top: 0.12rem;
    cursor: pointer;
    flex: 0 0 auto;
}

.tech-item .row {
    align-items: end;
}

.tech-item .form-check {
    display: grid;
    grid-template-columns: 20px minmax(0, 1fr);
    column-gap: 0.6rem;
    align-items: start;
    margin: 0;
    min-height: 72px;
}

.tech-item .form-check > .form-check-input {
    margin-left: 0;
}

.tech-title-wrap {
    min-width: 0;
}

.tech-title-line {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
}

.tech-template-badge {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    padding: 0.18rem 0.5rem;
    border-radius: 999px;
    background: rgba(31, 99, 146, 0.10);
    border: 1px solid rgba(31, 99, 146, 0.14);
    color: var(--theme-primary);
    font-size: 0.7rem;
    font-weight: 600;
    line-height: 1;
}

.tech-category {
    display: block;
    margin-top: 0.45rem;
    color: #5f7898;
    font-size: 0.82rem;
    line-height: 1.35;
}

.form-check-label {
    font-weight: 600;
    color: var(--gray-900);
    cursor: pointer;
    font-size: 0.98rem;
    line-height: 1.25;
    overflow-wrap: anywhere;
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
    min-height: 48px;
    padding: 0.65rem 0.9rem;
    border: 1px solid var(--gray-300);
    border-radius: 12px;
    font-size: 0.9rem;
    transition: all 0.2s;
    background: white;
}

.form-control-modern:focus,
.form-select-modern:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.form-control-modern:disabled,
.form-select-modern:disabled {
    background: var(--gray-100);
    cursor: not-allowed;
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

/* Cost Summary Table */
.cost-table {
    width: 100%;
    border-collapse: collapse;
}

.cost-table td {
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--gray-200);
}

.cost-table tr:last-child td {
    border-bottom: none;
}

.cost-table .total-row {
    background: var(--gray-50);
    font-weight: 600;
}

/* Tips List */
.tips-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.tips-list li {
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--gray-200);
    font-size: 0.85rem;
    color: var(--gray-700);
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.tips-list li:last-child {
    border-bottom: none;
}

.tips-list li i {
    color: #f59e0b;
    margin-top: 0.2rem;
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


/* Responsive */
@media (max-width: 768px) {
    .page-header-modern { padding: 1.5rem; }
    .breadcrumb-modern { padding: 0.5rem 0.75rem; }
    .progress-indicator { padding: 0.75rem; }
    .progress-step .step-label { font-size: 0.6rem; }
    .progress-step .step-number { width: 24px; height: 24px; font-size: 0.7rem; }
    .tech-item { padding: 0.9rem; }
    .tech-item .row > div { margin-bottom: 0.65rem; }
    .tech-item .row > div:last-child { margin-bottom: 0; }
    .tech-item .form-check { min-height: auto; }
    .form-card .card-body { padding: 1.25rem; }
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-option,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-item {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .cost-table .total-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-item .form-check-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-item .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .cost-table td {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-item small.text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-description,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select-modern {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-modern:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select-modern:focus {
    background: #071321 !important;
    border-color: #4aa8e8 !important;
    box-shadow: 0 0 0 0.25rem rgba(74, 168, 232, 0.16) !important;
    color: #f1f7fc !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-modern:disabled,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select-modern:disabled {
    background: #06111e !important;
    color: #7f9db7 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-item {
    background:
        radial-gradient(circle at 100% 0%, rgba(76, 184, 255, 0.14), transparent 34%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.035) 0%, rgba(255, 255, 255, 0) 38%, rgba(76, 184, 255, 0.08) 100%),
        linear-gradient(180deg, rgba(138, 210, 255, 0.055), transparent 56%),
        rgba(10, 22, 35, 0.88) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-template-badge {
    background: rgba(76, 184, 255, 0.14);
    border-color: rgba(138, 210, 255, 0.18);
    color: #93c5fd;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-category {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .cost-table td {
    border-bottom-color: rgba(138, 210, 255, 0.12) !important;
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-microchip me-2"></i> 
                Technology Stack & Resources Recommendation
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
            <li class="breadcrumb-item active"><i class="fas fa-microchip"></i> Technology</li>
        </ol>
    </nav>

    <!-- Progress Indicator -->
    <div class="progress-indicator">
        <a class="progress-step completed" href="project-review.php?id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Project Review</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step completed" href="features.php?id=<?php echo $projectId; ?>"><div class="step-number"><i class="fas fa-check"></i></div><div class="step-label">Features</div></a>
        <div class="progress-line completed"></div>
        <a class="progress-step active" href="tech-recommendation.php?id=<?php echo $projectId; ?>"><div class="step-number">3</div><div class="step-label">Technology</div></a>
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

    <?php if (is_array($projectTemplate)): ?>
    <div class="info-card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-lightbulb"></i> Template Technology Recommendation</h5>
        </div>
        <div class="card-body">
            <p class="mb-2"><strong><?php echo htmlspecialchars((string) ($projectTemplate['project_type'] ?? 'Matched template')); ?></strong></p>
            <p class="mb-2"><strong>Suggested stack:</strong> <?php echo htmlspecialchars((string) ($projectTemplate['tech_stack'] ?? 'Not specified')); ?></p>
            <p class="mb-2"><strong>Methodology:</strong> <?php echo htmlspecialchars((string) ($projectTemplate['methodology'] ?? 'Not specified')); ?></p>
            <p class="mb-0 text-muted"><?php echo htmlspecialchars((string) ($projectTemplate['architecture_notes'] ?? 'Use this template as a starting point and adjust the selected technologies for the client.')); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Main Form Column -->
        <div class="col-lg-8">
            <form method="POST" id="techForm">
                <div class="form-card">
                    <div class="card-header">
                        <h5><i class="fas fa-server"></i> Select Technologies/Services</h5>
                    </div>
                    <div class="card-body">
                        <div id="technologiesContainer">
                            <?php while($tech = $technologies->fetch_assoc()): ?>
                            <?php
                                $savedRecommendation = $existingRecommendations[(int) $tech['id']] ?? null;
                                $templateRecommended = architectTechIsTemplateRecommended($projectTemplate, (string) ($tech['name'] ?? ''), (string) ($tech['category'] ?? ''));
                                $isChecked = $savedRecommendation || $templateRecommended;
                            ?>
                            <div class="tech-item">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input tech-checkbox" type="checkbox" 
                                                   name="technologies[<?php echo $tech['id']; ?>][id]" 
                                                   value="<?php echo $tech['id']; ?>" 
                                                   id="tech_<?php echo $tech['id']; ?>"
                                                   data-template-recommended="<?php echo $templateRecommended ? '1' : '0'; ?>"
                                                   data-template-name="<?php echo htmlspecialchars((string) $tech['name']); ?>"
                                                   <?php echo $isChecked ? 'checked' : ''; ?>>
                                            <div class="tech-title-wrap">
                                                <div class="tech-title-line">
                                                    <label class="form-check-label fw-bold" for="tech_<?php echo $tech['id']; ?>">
                                                        <?php echo htmlspecialchars($tech['name']); ?>
                                                    </label>
                                                    <?php if ($templateRecommended): ?>
                                                        <span class="tech-template-badge">Template</span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="tech-category">Category: <?php echo htmlspecialchars((string) $tech['category']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Service Type</label>
                                        <select name="technologies[<?php echo $tech['id']; ?>][service_type]" class="form-select-modern" <?php echo $isChecked ? '' : 'disabled'; ?>>
                                            <option value="Development" <?php echo (($savedRecommendation['service_type'] ?? '') === 'Development') ? 'selected' : ''; ?>>Development</option>
                                            <option value="Testing" <?php echo (($savedRecommendation['service_type'] ?? '') === 'Testing') ? 'selected' : ''; ?>>Testing</option>
                                            <option value="Production" <?php echo (($savedRecommendation['service_type'] ?? '') === 'Production') ? 'selected' : ''; ?>>Production</option>
                                            <option value="Backup" <?php echo (($savedRecommendation['service_type'] ?? '') === 'Backup') ? 'selected' : ''; ?>>Backup</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Monthly Cost (PHP)</label>
                                        <input type="number" name="technologies[<?php echo $tech['id']; ?>][cost]" 
                                               class="form-control-modern" step="0.01" 
                                               value="<?php echo htmlspecialchars((string) ($savedRecommendation['estimated_cost'] ?? $tech['price'])); ?>" <?php echo $isChecked ? '' : 'disabled'; ?>>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Notes</label>
                                        <input type="text" name="technologies[<?php echo $tech['id']; ?>][notes]" 
                                               class="form-control-modern" placeholder="Optional" value="<?php echo htmlspecialchars((string) ($savedRecommendation['notes'] ?? ($templateRecommended ? 'Recommended by selected project template.' : ''))); ?>" <?php echo $isChecked ? '' : 'disabled'; ?>>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
                
                <div class="form-card">
                    <div class="card-header">
                        <h5><i class="fas fa-cloud"></i> Infrastructure Requirements</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Cloud Platform</label>
                                <select name="cloud_platform" class="form-select-modern">
                                    <option value="Azure" <?php echo (($project['cloud_platform'] ?? '') === 'Azure') ? 'selected' : ''; ?>>Microsoft Azure</option>
                                    <option value="AWS" <?php echo (($project['cloud_platform'] ?? '') === 'AWS') ? 'selected' : ''; ?>>Amazon Web Services</option>
                                    <option value="GCP" <?php echo (($project['cloud_platform'] ?? '') === 'GCP') ? 'selected' : ''; ?>>Google Cloud Platform</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Database Server</label>
                                <select name="database_server" class="form-select-modern">
                                    <option value="Azure SQL" <?php echo (($project['database_server'] ?? '') === 'Azure SQL') ? 'selected' : ''; ?>>Azure SQL Database</option>
                                    <option value="MySQL" <?php echo (($project['database_server'] ?? '') === 'MySQL') ? 'selected' : ''; ?>>MySQL</option>
                                    <option value="PostgreSQL" <?php echo (($project['database_server'] ?? '') === 'PostgreSQL') ? 'selected' : ''; ?>>PostgreSQL</option>
                                    <option value="MongoDB" <?php echo (($project['database_server'] ?? '') === 'MongoDB') ? 'selected' : ''; ?>>MongoDB</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Storage Requirements</label>
                                <input type="text" name="storage" class="form-control-modern" placeholder="e.g., 100GB SSD" value="<?php echo htmlspecialchars((string) ($project['storage_requirements'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Additional Services</label>
                                <input type="text" name="additional_services" class="form-control-modern" placeholder="CDN, Load Balancer, etc." value="<?php echo htmlspecialchars((string) ($project['additional_services'] ?? '')); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3 text-end">
                    <button type="submit" class="btn-primary-modern">
                        <i class="fas fa-arrow-right"></i> Proceed to Developer Allocation
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Sidebar Column -->
        <div class="col-lg-4">
            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line"></i> Cost Summary</h5>
                </div>
                <div class="card-body">
                    <div id="costSummary"><p class="text-muted text-center py-3">Select technologies to see cost summary</p></div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <strong>Total Monthly Cost:</strong>
                        <h4 class="text-primary mb-0" id="totalCost">&#8369;0.00</h4>
                    </div>
                </div>
            </div>
            
            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-lightbulb"></i> Recommendation Tips</h5>
                </div>
                <div class="card-body">
                    <ul class="tips-list">
                        <li><i class="fas fa-lightbulb"></i> Choose technologies that align with project requirements</li>
                        <li><i class="fas fa-lightbulb"></i> Consider scalability for future growth</li>
                        <li><i class="fas fa-lightbulb"></i> Balance cost vs performance</li>
                        <li><i class="fas fa-lightbulb"></i> Ensure compatibility with existing systems</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('.tech-checkbox').change(function() {
        if (!$(this).is(':checked') && String($(this).data('template-recommended')) === '1') {
            const suggestionName = $(this).data('template-name') || 'this technology';
            const shouldRemove = window.confirm(`The selected project template recommends adding "${suggestionName}" based on similar completed projects. Are you sure you want to remove or skip this recommendation?`);
            if (!shouldRemove) {
                $(this).prop('checked', true);
            }
        }
        const container = $(this).closest('.tech-item');
        const inputs = container.find('select, input[type="number"], input[type="text"]');
        if($(this).is(':checked')) { inputs.prop('disabled', false); } 
        else { inputs.prop('disabled', true); }
        updateCostSummary();
    });
    
    function updateCostSummary() {
        let total = 0;
        let summaryHtml = '<table class="cost-table">';
        $('.tech-checkbox:checked').each(function() {
            const container = $(this).closest('.tech-item');
            const name = container.find('.form-check-label').text().trim();
            const cost = parseFloat(container.find('input[type="number"]').val()) || 0;
            total += cost;
            summaryHtml += `<tr><td>${name}</td><td class="text-end">PHP ${cost.toFixed(2)}</td></tr>`;
        });
        summaryHtml += `<tr class="total-row"><td><strong>Total</strong></td><td class="text-end"><strong>PHP ${total.toFixed(2)}</strong></td></tr></table>`;
        if($('.tech-checkbox:checked').length > 0) { $('#costSummary').html(summaryHtml); } 
        else { $('#costSummary').html('<p class="text-muted text-center py-3">Select technologies to see cost summary</p>'); }
        $('#totalCost').text('PHP ' + total.toFixed(2));
    }

    updateCostSummary();
});
</script>

<?php include '../../includes/footer.php'; ?>
