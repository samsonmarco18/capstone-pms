<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-template.php';
require_once '../../includes/project-compilation.php';
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
$projectTemplate = projectTemplateResolveForProject($conn, $project);

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

        $technologyFamilies = [
            '/\b(html|css|javascript|js)\b/i', '/\breact/i', '/\bvue/i', '/\bangular/i',
            '/\b(php|laravel)\b/i', '/\bnode/i', '/\b(asp\.net|\.net)\b/i', '/\bdjango/i',
            '/\bflutter/i', '/\bkotlin/i', '/\bmysql/i', '/\b(postgres|postgresql)\b/i',
            '/\b(sql server|azure sql)\b/i', '/\b(firebase|firestore)\b/i',
            '/\bazure/i', '/\baws/i', '/\bgoogle cloud|\bgcp\b/i', '/\b(vps|shared hosting)\b/i',
            '/\b(oauth|openid|oidc)\b/i', '/\b(payment|gateway)\b/i', '/\b(email|sms)\b/i',
            '/\bpush notification/i', '/\b(map|gps)\b/i', '/\b(report|export)\b/i',
        ];
        foreach ($technologyFamilies as $familyPattern) {
            if (preg_match($familyPattern, (string) $item) === 1 && preg_match($familyPattern, $haystack) === 1) {
                return true;
            }
        }
    }

    return false;
}

function architectTechFeatureList(array $project, ?array $template): array
{
    $source = trim((string) ($template['features'] ?? $project['requested_features'] ?? ''));
    $features = preg_split('/[\r\n,;]+/', $source) ?: [];
    return array_values(array_filter(array_map('trim', $features), static fn(string $feature): bool => $feature !== ''));
}

function architectTechRelevantFeatures(array $features, string $category): string
{
    $patterns = [
        'Frontend' => '/dashboard|portal|form|screen|interface|catalog|cart|search|registration|scheduling/i',
        'Backend' => '/workflow|processing|management|transaction|notification|report|checkout|rules|api/i',
        'Database' => '/record|profile|history|catalog|inventory|report|transaction|patient|account/i',
        'Hosting' => '/portal|web|dashboard|online|upload|notification|integration|api/i',
        'Security' => '/login|account|role|payment|patient|medical|record|admin|audit/i',
        'Integrations' => '/payment|export|email|sms|notification|integration|third.party|api/i',
    ];
    $matches = array_values(array_filter($features, static fn(string $feature): bool => preg_match($patterns[$category] ?? '/./', $feature) === 1));
    $selected = array_slice($matches !== [] ? $matches : $features, 0, 4);
    return $selected !== [] ? implode(', ', $selected) : 'The approved core project features';
}

function architectTechEvortixBrief(array $project, ?array $template): array
{
    $features = architectTechFeatureList($project, $template);
    $stack = (string) ($template['tech_stack'] ?? '');
    $stackItems = array_values(array_filter(array_map('trim', preg_split('/[,|]+/', $stack) ?: [])));
    $findStack = static function (string $pattern, string $fallback) use ($stackItems): string {
        foreach ($stackItems as $item) {
            if (preg_match($pattern, $item) === 1) return $item;
        }
        return $fallback;
    };

    $frontend = $findStack('/html|css|javascript|\bjs\b|react|vue|angular|frontend|bootstrap|tailwind|flutter|kotlin/i', 'HTML/CSS + JavaScript');
    $backend = $findStack('/node|php|laravel|django|rails|spring|\.net|backend/i', 'PHP/Laravel');
    $database = trim((string) ($template['database_technology'] ?? $project['database_server'] ?? '')) ?: $findStack('/mysql|maria|postgres|sql|mongo|firebase/i', 'MySQL');
    $hosting = trim((string) ($template['hosting_platform'] ?? $project['cloud_platform'] ?? '')) ?: 'Managed VPS Hosting';
    $security = trim((string) ($template['auth_method'] ?? '')) ?: 'OAuth / OpenID Connect with role-based access';
    $integrations = trim((string) ($template['third_party_services'] ?? $template['integrations'] ?? '')) ?: 'Email/SMS Notification API as needed';

    return [
        ['category' => 'Frontend', 'recommendation' => $frontend, 'why' => 'Matches the user-facing experience and can reuse the project’s selected web stack.', 'features' => architectTechRelevantFeatures($features, 'Frontend'), 'impact' => 'Low to medium complexity; open-source frameworks usually have no license cost.', 'alternatives' => 'Vue, Angular, or server-rendered templates with Bootstrap.'],
        ['category' => 'Backend', 'recommendation' => $backend, 'why' => 'Supports business rules, APIs, authentication, and maintainable feature workflows.', 'features' => architectTechRelevantFeatures($features, 'Backend'), 'impact' => 'Medium complexity; cost is mainly engineering and application hosting.', 'alternatives' => 'Laravel/PHP, Node.js, ASP.NET Core, Django, or Spring Boot.'],
        ['category' => 'Database', 'recommendation' => $database, 'why' => 'Provides structured, reliable storage for the approved operational data.', 'features' => architectTechRelevantFeatures($features, 'Database'), 'impact' => 'Medium complexity; managed backups and high availability may add monthly cost.', 'alternatives' => 'PostgreSQL, MySQL/MariaDB, SQL Server, or MongoDB when document storage is justified.'],
        ['category' => 'Hosting', 'recommendation' => $hosting, 'why' => 'Provides a deployable environment with scaling, monitoring, and backup options.', 'features' => architectTechRelevantFeatures($features, 'Hosting'), 'impact' => 'Adds recurring infrastructure cost; managed hosting reduces operational complexity.', 'alternatives' => 'Azure App Service, AWS, Google Cloud, a managed VPS, or compatible shared hosting.'],
        ['category' => 'Security', 'recommendation' => $security, 'why' => 'Protects project data and enforces the access boundaries implied by the requirements.', 'features' => architectTechRelevantFeatures($features, 'Security'), 'impact' => 'Adds testing and implementation effort; managed identity or security services may add cost.', 'alternatives' => 'Application-native RBAC, OAuth/OIDC providers, cloud identity services, or a managed WAF.'],
        ['category' => 'Integrations', 'recommendation' => $integrations, 'why' => 'Connects the system to external services required by approved workflows.', 'features' => architectTechRelevantFeatures($features, 'Integrations'), 'impact' => 'Complexity depends on vendor APIs, rate limits, testing environments, and usage fees.', 'alternatives' => 'Direct REST APIs, webhooks, queued adapters, or an integration platform.'],
    ];
}

function architectTechEnsureRelevantCatalog(mysqli|PgsqlMysqliCompatConnection $conn, int $createdBy): void
{
    $catalog = [
        ['HTML/CSS', 'Frontend', 0, 'Standards-based responsive web interface'],
        ['JavaScript', 'Frontend', 0, 'Browser application language'],
        ['React', 'Frontend', 0, 'Component-based web interface library'],
        ['Vue.js', 'Frontend', 0, 'Progressive web interface framework'],
        ['Angular', 'Frontend', 0, 'Enterprise frontend framework'],
        ['Bootstrap', 'Frontend', 0, 'Responsive interface component toolkit'],
        ['PHP/Laravel', 'Backend', 0, 'PHP application framework and runtime'],
        ['Laravel', 'Backend', 0, 'PHP web application framework'],
        ['Node.js', 'Backend', 0, 'JavaScript server runtime'],
        ['ASP.NET Core', 'Backend', 0, 'Cross-platform .NET application framework'],
        ['Django', 'Backend', 0, 'Python web application framework'],
        ['Flutter', 'Mobile', 0, 'Cross-platform mobile application framework'],
        ['Kotlin', 'Mobile', 0, 'Native Android development language'],
        ['Kotlin/Flutter', 'Mobile', 0, 'Native or cross-platform mobile delivery option'],
        ['MySQL', 'Database', 0, 'Relational application database'],
        ['PostgreSQL', 'Database', 0, 'Advanced open-source relational database'],
        ['SQL Server', 'Database', 500, 'Microsoft relational database platform'],
        ['Firebase / Firestore', 'Database', 0, 'Managed realtime document database'],
        ['Azure App Service', 'Hosting', 100, 'Managed Azure application hosting'],
        ['AWS Application Hosting', 'Hosting', 150, 'Managed AWS application hosting baseline'],
        ['Google Cloud Run', 'Hosting', 100, 'Managed container application hosting'],
        ['Managed VPS Hosting', 'Hosting', 100, 'Managed virtual server hosting'],
        ['OAuth / OpenID Connect', 'Security', 0, 'Standards-based authentication and authorization'],
        ['Managed Web Application Firewall', 'Security', 100, 'Managed application traffic protection'],
        ['Payment Gateway API', 'Integration', 0, 'Online payment service integration'],
        ['Email/SMS Notification API', 'Integration', 0, 'Transactional notification integration'],
        ['Push Notification Service', 'Integration', 0, 'Mobile and web push messaging'],
        ['Maps and GPS API', 'Integration', 0, 'Mapping, geocoding, and location services'],
        ['Reporting and Export Service', 'Integration', 0, 'Document, spreadsheet, and analytics export support'],
    ];
    $exists = $conn->prepare('SELECT id FROM technologies WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $insert = $conn->prepare("INSERT INTO technologies (name, category, price, status, renewal_cost, description, created_by) VALUES (?, ?, ?, 'active', ?, ?, ?)");
    if (!$exists || !$insert) return;
    foreach ($catalog as [$name, $category, $price, $description]) {
        $exists->bind_param('s', $name);
        $exists->execute();
        if ($exists->get_result()->fetch_assoc()) continue;
        $renewalCost = (float) $price;
        $insert->bind_param('ssddsi', $name, $category, $price, $renewalCost, $description, $createdBy);
        $insert->execute();
    }
    $exists->close();
    $insert->close();
}
architectTechEnsureRelevantCatalog($conn, (int) $user['id']);
$evortixTechnologyBrief = architectTechEvortixBrief($project, is_array($projectTemplate) ? $projectTemplate : null);
$evortixBriefByCategory = [];
foreach ($evortixTechnologyBrief as $briefItem) {
    $evortixBriefByCategory[(string) ($briefItem['category'] ?? '')] = $briefItem;
}
$technologies = $conn->query("SELECT * FROM technologies WHERE status = 'active' ORDER BY category ASC, name ASC");
$technologyCategories = [];
$technologyCategoryResult = $conn->query("SELECT DISTINCT category FROM technologies WHERE status = 'active' AND category IS NOT NULL AND category <> '' ORDER BY category ASC");
if ($technologyCategoryResult) {
    while ($categoryRow = $technologyCategoryResult->fetch_assoc()) {
        $technologyCategories[] = trim((string) ($categoryRow['category'] ?? ''));
    }
}

$existingRecommendations = [];
$architectTechDssItems = [];
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
    projectCompilationRecord($conn, $projectId, (int) $user['id'], 'technology');
    
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
    gap: 0.65rem;
}

.technology-column-headings {
    padding: 0 1rem 0.1rem;
}

.technology-column-headings > .row {
    align-items: center;
}

.technology-column-headings span {
    display: block;
    color: #677b8d;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.055em;
    line-height: 24px;
    text-transform: uppercase;
}

.technology-notes-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    min-height: 24px;
}

.technology-category-filter {
    width: min(150px, 72%);
    height: 24px;
    min-height: 24px;
    padding: 0 1.65rem 0 0.35rem;
    border: 0;
    border-bottom: 1px solid #b9cddd;
    border-radius: 0;
    background-color: transparent;
    color: #31536f;
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 24px;
    outline: none;
}

.technology-category-filter:focus {
    border-bottom-color: var(--theme-primary);
    box-shadow: 0 2px 0 rgba(31, 99, 146, 0.12);
}

.technology-category-empty {
    padding: 1.5rem;
    border: 1px dashed #c9d9e5;
    border-radius: 12px;
    color: #687d90;
    font-size: 0.86rem;
    text-align: center;
}

.tech-item {
    position: relative;
    background: #ffffff;
    border: 1px solid #dbe7ef;
    border-radius: 14px;
    padding: 0.85rem 1rem;
    margin-bottom: 0;
    transition: border-color 0.22s ease, box-shadow 0.22s ease, background 0.22s ease;
    box-shadow: 0 4px 12px rgba(15, 76, 129, 0.045);
    overflow: hidden;
}

.tech-item::before {
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

.tech-item:hover {
    border-color: rgba(31, 99, 146, 0.3);
    background: #f5fafd;
    box-shadow: 0 8px 18px rgba(15, 76, 129, 0.08);
}

.tech-item:hover::before {
    background: var(--theme-primary);
}

.tech-item:has(.tech-checkbox:checked) {
    border-color: rgba(31, 99, 146, 0.34);
    background: #f2f8fc;
    box-shadow: 0 6px 16px rgba(15, 76, 129, 0.07);
}

.tech-item:has(.tech-checkbox:checked):hover {
    background: #edf6fb;
}

.tech-checkbox {
    width: 18px;
    height: 18px;
    margin: 0;
    cursor: pointer;
    flex: 0 0 auto;
}

.tech-item .row {
    align-items: center;
}

.tech-item .form-check {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin: 0;
    min-height: 48px;
    padding-left: 0;
}

.tech-item .tech-field-label {
    display: none;
}

.tech-item .form-check > .form-check-input {
    margin-left: 0;
}

.tech-title-wrap {
    display: grid;
    align-content: center;
    gap: 0.25rem;
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
    margin-top: 0;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-item:hover {
    background: #0b2033 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-item:has(.tech-checkbox:checked) {
    background: #0a1c2d !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-item:has(.tech-checkbox:checked):hover {
    background: #0d2539 !important;
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

/* Compact review tools replace the permanent right sidebar. */
.technology-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.tech-review-tools {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    flex: 0 0 auto;
}

.tech-review-tool {
    position: relative;
    display: inline-flex;
    align-items: center;
    min-height: 40px;
    gap: 0.55rem;
    padding: 0.45rem 0.7rem;
    border: 1px solid rgba(31, 99, 146, 0.2);
    border-radius: 11px;
    background: #f7fbfd;
    color: #174769;
    box-shadow: 0 5px 14px rgba(15, 76, 129, 0.06);
    cursor: pointer;
    transition: border-color 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.tech-review-tool:hover,
.tech-review-tool:focus-visible,
.tech-review-tool[aria-expanded="true"] {
    border-color: rgba(13, 103, 134, 0.42);
    background: #eef7fb;
    box-shadow: 0 8px 18px rgba(15, 76, 129, 0.12);
    outline: none;
    transform: translateY(-1px);
}

.tech-review-tool-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: auto;
    height: auto;
    border-radius: 0;
    background: transparent;
    color: #0d6786;
    font-size: 0.9rem;
    transform-origin: 50% 88%;
}

.technology-card-header .tech-review-tool-icon > i {
    color: #0d6786 !important;
    font-weight: 900;
}

.tech-review-tool-copy {
    display: grid;
    gap: 0.02rem;
    min-width: 0;
    text-align: left;
}

.tech-review-tool-label {
    color: #5f7487;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.2;
    text-transform: uppercase;
}

.tech-review-tool-value {
    color: #174769;
    font-size: 0.76rem;
    font-weight: 750;
    line-height: 1.25;
    white-space: nowrap;
}

.tech-review-notification {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 7px;
    height: 7px;
    border: 2px solid #ffffff;
    border-radius: 50%;
    background: #f59e0b;
}

.tech-review-tool.is-reviewed .tech-review-notification {
    display: none;
}

@keyframes techReviewToolAttention {
    0%, 100% { transform: rotate(0deg) scale(1); }
    25% { transform: rotate(-7deg) scale(1.04); }
    55% { transform: rotate(6deg) scale(1.04); }
    78% { transform: rotate(-3deg) scale(1.02); }
}

.tech-review-tool.is-attention .tech-review-tool-icon {
    animation: techReviewToolAttention 0.72s ease-in-out 2;
}

.tech-review-drawer-backdrop {
    position: fixed;
    inset: 0;
    z-index: 10540;
    background: rgba(6, 17, 30, 0.42);
    opacity: 0;
    backdrop-filter: blur(2px);
    transition: opacity 0.2s ease;
}

.tech-review-drawer-backdrop[hidden] {
    display: none;
}

.tech-review-drawer-backdrop.is-visible {
    opacity: 1;
}

.tech-review-drawer {
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

.tech-review-drawer.is-open {
    transform: translateX(0);
    visibility: visible;
}

.tech-review-drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #dce7ef;
    background: #f5f9fc;
}

.tech-review-drawer-title {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 0;
}

.tech-review-drawer-title > i {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    background: #0d6786;
    color: #ffffff;
}

.tech-review-drawer-title-copy {
    display: grid;
    gap: 0.08rem;
}

.tech-review-drawer-title-copy strong {
    color: #17324d;
    font-size: 0.9rem;
}

.tech-review-drawer-title-copy span {
    color: #6a7f91;
    font-size: 0.7rem;
}

.tech-review-drawer-close {
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

.tech-review-drawer-close:hover,
.tech-review-drawer-close:focus-visible {
    border-color: #0d6786;
    color: #0d6786;
    outline: none;
}

.tech-review-drawer-body {
    min-height: 0;
    padding: 1rem 1.1rem 1.25rem;
    overflow-y: auto;
}

.tech-review-drawer-total {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 1rem;
    padding: 0.85rem 0;
    border-top: 1px solid #dce7ef;
}

.tech-review-drawer-total span {
    color: #536b7f;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
}

.tech-review-drawer-total strong {
    color: #0d6786;
    font-size: 1.05rem;
}

body.tech-review-drawer-open {
    overflow: hidden;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-review-tool {
    border-color: rgba(138, 210, 255, 0.18);
    background: #0d2236;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .technology-card-header .tech-review-tool-icon > i {
    color: #69b9ec !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .technology-category-filter {
    border-bottom-color: rgba(138, 210, 255, 0.26);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .technology-category-filter option {
    background: #0a1b2c;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(.tech-review-tool-label, .tech-review-drawer-title-copy span) {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(.tech-review-tool-value, .tech-review-drawer-title-copy strong) {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-review-notification {
    border-color: #0d2236;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-review-drawer {
    border-left-color: rgba(138, 210, 255, 0.15);
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-review-drawer-header {
    border-bottom-color: rgba(138, 210, 255, 0.15);
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-review-drawer-close {
    border-color: rgba(138, 210, 255, 0.18);
    background: #0d2236;
    color: #b9d6e8;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-review-drawer-total {
    border-top-color: rgba(138, 210, 255, 0.15);
}

@media (max-width: 767.98px) {
    .technology-card-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .tech-review-tools {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        width: 100%;
    }

    .tech-review-tool {
        width: 100%;
    }

    .technology-column-headings {
        padding: 0;
    }

    .technology-column-headings .row > div {
        display: none;
    }

    .technology-column-headings .row > div:last-child {
        display: block;
    }

    .technology-notes-heading > span {
        display: none;
    }

    .technology-category-filter {
        width: 100%;
        min-height: 40px;
    }

    .tech-item .tech-field-label {
        display: block;
    }
}

@media (prefers-reduced-motion: reduce) {
    .tech-review-tool.is-attention .tech-review-tool-icon {
        animation: none;
    }

    .tech-review-drawer,
    .tech-review-drawer-backdrop {
        transition: none;
    }
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
    <div class="info-card mb-4 architect-dss-source-card">
        <div class="card-header">
            <h5><i class="fas fa-lightbulb"></i> Template Technology Recommendation</h5>
        </div>
        <div class="card-body">
            <p class="mb-2"><strong><?php echo htmlspecialchars((string) ($projectTemplate['project_type'] ?? 'Matched template')); ?></strong></p>
            <p class="mb-2"><strong>Suggested stack:</strong> <?php echo htmlspecialchars((string) ($projectTemplate['tech_stack'] ?? 'Not specified')); ?></p>
            <p class="mb-2"><strong>Methodology:</strong> <?php echo htmlspecialchars((string) ($projectTemplate['methodology'] ?? 'Not specified')); ?></p>
            <p class="mb-0 text-muted"><?php echo htmlspecialchars((string) ($projectTemplate['architecture_notes'] ?? 'Use this template as a starting point and adjust the selected technologies for the client.')); ?></p>
            <div class="architect-dss-source-controls">
                <button type="button" class="btn btn-primary btn-sm" data-dss-tech-decision="accept"><i class="fas fa-robot me-1"></i> Accept suggested stack</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-dss-tech-decision="skip">Skip suggestions</button>
            </div>
            <small class="d-block mt-2 text-muted">The DSS assistant will not select anything until you accept.</small>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Main Form Column -->
        <div class="col-12">
            <form method="POST" id="techForm">
                <div class="form-card">
                    <div class="card-header technology-card-header">
                        <h5><i class="fas fa-server"></i> Select Technologies/Services</h5>
                        <div class="tech-review-tools" aria-label="Technology review tools">
                            <button type="button" class="tech-review-tool" data-review-drawer-open="cost" aria-controls="costReviewDrawer" aria-expanded="false">
                                <span class="tech-review-tool-icon"><i class="fas fa-chart-line" aria-hidden="true"></i></span>
                                <span class="tech-review-tool-copy">
                                    <span class="tech-review-tool-label">Monthly cost</span>
                                    <strong class="tech-review-tool-value" id="costToolTotal">PHP 0.00</strong>
                                </span>
                            </button>
                            <button type="button" class="tech-review-tool is-attention" data-review-drawer-open="guidance" aria-controls="guidanceReviewDrawer" aria-expanded="false">
                                <span class="tech-review-tool-icon"><i class="fas fa-robot" aria-hidden="true"></i></span>
                                <span class="tech-review-tool-copy">
                                    <span class="tech-review-tool-label">Evortix</span>
                                    <strong class="tech-review-tool-value">Review guidance</strong>
                                </span>
                                <span class="tech-review-notification" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="technologiesContainer">
                            <div class="technology-column-headings" aria-hidden="true">
                                <div class="row g-2">
                                    <div class="col-md-4"><span>Technology</span></div>
                                    <div class="col-md-3"><span>Service type</span></div>
                                    <div class="col-md-2"><span>Monthly cost (PHP)</span></div>
                                    <div class="col-md-3">
                                        <div class="technology-notes-heading">
                                            <span>Notes</span>
                                            <label class="visually-hidden" for="technologyCategoryFilter">Filter technologies by category</label>
                                            <select id="technologyCategoryFilter" class="technology-category-filter" aria-label="Filter technologies by category">
                                                <option value="">All categories</option>
                                                <?php foreach ($technologyCategories as $technologyCategory): ?>
                                                    <?php if ($technologyCategory !== ''): ?>
                                                        <option value="<?php echo htmlspecialchars($technologyCategory); ?>"><?php echo htmlspecialchars($technologyCategory); ?></option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php while($tech = $technologies->fetch_assoc()): ?>
                            <?php
                                $savedRecommendation = $existingRecommendations[(int) $tech['id']] ?? null;
                                $templateRecommended = architectTechIsTemplateRecommended($projectTemplate, (string) ($tech['name'] ?? ''), (string) ($tech['category'] ?? ''));
                                if ($templateRecommended && !$savedRecommendation) {
                                    $catalogCategory = strtolower((string) ($tech['category'] ?? ''));
                                    $briefCategory = str_contains($catalogCategory, 'front') || str_contains($catalogCategory, 'mobile') ? 'Frontend'
                                        : (str_contains($catalogCategory, 'back') ? 'Backend'
                                        : (str_contains($catalogCategory, 'database') || str_contains($catalogCategory, 'sql') ? 'Database'
                                        : (str_contains($catalogCategory, 'cloud') || str_contains($catalogCategory, 'host') ? 'Hosting'
                                        : (str_contains($catalogCategory, 'security') ? 'Security'
                                        : (str_contains($catalogCategory, 'integration') ? 'Integrations' : '')))));
                                    $brief = $evortixBriefByCategory[$briefCategory] ?? [];
                                    $architectTechDssItems[] = [
                                        'label' => (string) ($tech['name'] ?? 'Technology'),
                                        'detail' => 'Evortix recommends this ' . strtolower($briefCategory !== '' ? $briefCategory : 'technology') . ' stack item for the approved project features.',
                                        'points' => array_values(array_filter([
                                            !empty($brief['why']) ? 'Why it fits: ' . $brief['why'] : '',
                                            !empty($brief['features']) ? 'Required by: ' . $brief['features'] : '',
                                            !empty($brief['impact']) ? 'Cost/complexity: ' . $brief['impact'] : '',
                                            !empty($brief['alternatives']) ? 'Alternatives: ' . $brief['alternatives'] : '',
                                        ])),
                                        'selector' => '#tech_' . (int) $tech['id'],
                                    ];
                                }
                                // DSS recommendations remain suggestions until the architect explicitly accepts them.
                                $isChecked = (bool) $savedRecommendation;
                            ?>
                            <div class="tech-item" data-tech-category="<?php echo htmlspecialchars((string) ($tech['category'] ?? '')); ?>">
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
                                                    <label class="form-check-label" for="tech_<?php echo $tech['id']; ?>">
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
                                        <label class="form-label tech-field-label" for="tech_service_type_<?php echo $tech['id']; ?>">Service Type</label>
                                        <select id="tech_service_type_<?php echo $tech['id']; ?>" name="technologies[<?php echo $tech['id']; ?>][service_type]" class="form-select-modern" <?php echo $isChecked ? '' : 'disabled'; ?>>
                                            <option value="Development" <?php echo (($savedRecommendation['service_type'] ?? '') === 'Development') ? 'selected' : ''; ?>>Development</option>
                                            <option value="Testing" <?php echo (($savedRecommendation['service_type'] ?? '') === 'Testing') ? 'selected' : ''; ?>>Testing</option>
                                            <option value="Production" <?php echo (($savedRecommendation['service_type'] ?? '') === 'Production') ? 'selected' : ''; ?>>Production</option>
                                            <option value="Backup" <?php echo (($savedRecommendation['service_type'] ?? '') === 'Backup') ? 'selected' : ''; ?>>Backup</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label tech-field-label" for="tech_cost_<?php echo $tech['id']; ?>">Monthly Cost (PHP)</label>
                                        <input type="number" id="tech_cost_<?php echo $tech['id']; ?>" name="technologies[<?php echo $tech['id']; ?>][cost]"
                                               class="form-control-modern" step="0.01" 
                                               value="<?php echo htmlspecialchars((string) ($savedRecommendation['estimated_cost'] ?? $tech['price'])); ?>" <?php echo $isChecked ? '' : 'disabled'; ?>>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label tech-field-label" for="tech_notes_<?php echo $tech['id']; ?>">Notes</label>
                                        <input type="text" id="tech_notes_<?php echo $tech['id']; ?>" name="technologies[<?php echo $tech['id']; ?>][notes]"
                                               class="form-control-modern" placeholder="Optional" value="<?php echo htmlspecialchars((string) ($savedRecommendation['notes'] ?? ($templateRecommended ? 'Recommended by selected project template.' : ''))); ?>" <?php echo $isChecked ? '' : 'disabled'; ?>>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                            <div class="technology-category-empty" id="technologyCategoryEmpty" hidden>
                                No technologies are available in this category.
                            </div>
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
        
    </div>

    <div class="tech-review-drawer-backdrop" data-review-drawer-backdrop hidden></div>

    <aside class="tech-review-drawer" id="costReviewDrawer" data-review-drawer="cost"
           role="dialog" aria-modal="true" aria-labelledby="costReviewDrawerTitle" aria-hidden="true">
        <div class="tech-review-drawer-header">
            <div class="tech-review-drawer-title">
                <i class="fas fa-chart-line" aria-hidden="true"></i>
                <span class="tech-review-drawer-title-copy">
                    <strong id="costReviewDrawerTitle">Cost Summary</strong>
                    <span>Selected recurring technology costs</span>
                </span>
            </div>
            <button type="button" class="tech-review-drawer-close" data-review-drawer-close aria-label="Close cost summary">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="tech-review-drawer-body">
            <div id="costSummary">
                <p class="text-muted text-center py-3">Select technologies to see cost summary</p>
            </div>
            <div class="tech-review-drawer-total">
                <span>Total monthly cost</span>
                <strong id="totalCost">PHP 0.00</strong>
            </div>
        </div>
    </aside>

    <aside class="tech-review-drawer" id="guidanceReviewDrawer" data-review-drawer="guidance"
           role="dialog" aria-modal="true" aria-labelledby="guidanceReviewDrawerTitle" aria-hidden="true">
        <div class="tech-review-drawer-header">
            <div class="tech-review-drawer-title">
                <i class="fas fa-robot" aria-hidden="true"></i>
                <span class="tech-review-drawer-title-copy">
                    <strong id="guidanceReviewDrawerTitle">Evortix Review Guidance</strong>
                    <span>Checks before approving the final stack</span>
                </span>
            </div>
            <button type="button" class="tech-review-drawer-close" data-review-drawer-close aria-label="Close review guidance">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="tech-review-drawer-body">
            <ul class="tips-list">
                <li><i class="fas fa-check-circle"></i> Evortix generates the category recommendations and rationale</li>
                <li><i class="fas fa-check-circle"></i> Confirm that cited features match the approved scope</li>
                <li><i class="fas fa-check-circle"></i> Review recurring costs and delivery complexity</li>
                <li><i class="fas fa-check-circle"></i> The Architect approves or adjusts the final stack</li>
            </ul>
        </div>
    </aside>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('[data-dss-tech-decision]').on('click', function() {
        const accept = $(this).data('dss-tech-decision') === 'accept';
        $('.tech-checkbox[data-template-recommended="1"]').each(function() {
            $(this).prop('checked', accept).trigger('change');
        });
        window.appToast?.(accept ? 'Suggestions accepted' : 'Suggestions skipped', accept ? 'Template technologies are now selected for your review.' : 'No template technology was selected.', accept ? 'success' : 'info');
    });
    $('.tech-checkbox').change(async function() {
        if (!$(this).is(':checked') && String($(this).data('template-recommended')) === '1' && String(this.dataset.evortixDecision || '') !== '1') {
            const suggestionName = $(this).data('template-name') || 'this technology';
            const shouldRemove = await window.evortixConfirm?.({
                title: `Remove ${suggestionName}?`,
                message: `I recommend this technology for the selected project template. Removing it may affect compatibility, cost, or the delivery plan.`,
                cancelLabel: 'Keep technology',
                confirmLabel: 'Remove technology'
            });
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

    $('#technologyCategoryFilter').on('change', function() {
        const selectedCategory = String($(this).val() || '').trim().toLowerCase();
        let visibleCount = 0;

        $('.tech-item').each(function() {
            const itemCategory = String($(this).data('tech-category') || '').trim().toLowerCase();
            const matchesCategory = selectedCategory === '' || itemCategory === selectedCategory;
            $(this).prop('hidden', !matchesCategory);
            if (matchesCategory) {
                visibleCount += 1;
            }
        });

        $('#technologyCategoryEmpty').prop('hidden', visibleCount !== 0);
    });

    $('input[type="number"][name^="technologies"]').on('input change', updateCostSummary);

    const drawerButtons = Array.from(document.querySelectorAll('[data-review-drawer-open]'));
    const drawerBackdrop = document.querySelector('[data-review-drawer-backdrop]');
    let lastDrawerTrigger = null;
    let backdropTimer = null;

    function closeReviewDrawer(returnFocus = true) {
        const openDrawer = document.querySelector('[data-review-drawer].is-open');
        if (!openDrawer) {
            return;
        }

        openDrawer.classList.remove('is-open');
        openDrawer.setAttribute('aria-hidden', 'true');
        drawerButtons.forEach(button => button.setAttribute('aria-expanded', 'false'));
        drawerBackdrop?.classList.remove('is-visible');
        document.body.classList.remove('tech-review-drawer-open');

        window.clearTimeout(backdropTimer);
        backdropTimer = window.setTimeout(() => {
            if (drawerBackdrop && !document.querySelector('[data-review-drawer].is-open')) {
                drawerBackdrop.hidden = true;
            }
        }, 240);

        if (returnFocus) {
            lastDrawerTrigger?.focus();
        }
    }

    function openReviewDrawer(name, trigger) {
        closeReviewDrawer(false);
        const drawer = document.querySelector(`[data-review-drawer="${name}"]`);
        if (!drawer) {
            return;
        }

        window.clearTimeout(backdropTimer);
        if (drawerBackdrop) {
            drawerBackdrop.hidden = false;
            window.requestAnimationFrame(() => drawerBackdrop.classList.add('is-visible'));
        }

        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.remove('is-attention');
        if (name === 'guidance') {
            trigger.classList.add('is-reviewed');
        }
        document.body.classList.add('tech-review-drawer-open');
        lastDrawerTrigger = trigger;
        drawer.querySelector('[data-review-drawer-close]')?.focus();
    }

    drawerButtons.forEach(button => {
        button.addEventListener('click', () => {
            const drawerName = String(button.dataset.reviewDrawerOpen || '');
            const isOpen = document.querySelector(`[data-review-drawer="${drawerName}"]`)?.classList.contains('is-open');
            if (isOpen) {
                closeReviewDrawer();
            } else {
                openReviewDrawer(drawerName, button);
            }
        });
    });

    document.querySelectorAll('[data-review-drawer-close]').forEach(button => {
        button.addEventListener('click', () => closeReviewDrawer());
    });
    drawerBackdrop?.addEventListener('click', () => closeReviewDrawer());
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeReviewDrawer();
            return;
        }

        if (event.key === 'Tab') {
            const openDrawer = document.querySelector('[data-review-drawer].is-open');
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
        $('#costToolTotal').text('PHP ' + total.toFixed(2));

        const costTool = document.querySelector('[data-review-drawer-open="cost"]');
        const previousTotal = Number(costTool?.dataset.total);
        if (costTool && costTool.dataset.total !== undefined && Number.isFinite(previousTotal) && Math.abs(previousTotal - total) > 0.001) {
            costTool.classList.remove('is-attention');
            window.requestAnimationFrame(() => costTool.classList.add('is-attention'));
            window.setTimeout(() => costTool.classList.remove('is-attention'), 1600);
        }
        if (costTool) {
            costTool.dataset.total = String(total);
        }
    }

    updateCostSummary();
});
</script>

<?php if (is_array($projectTemplate)):
    $architectDssAssistantConfig = [
        'title' => 'Technology recommendations ready',
        'message' => 'I prepared the frontend, backend, database, hosting, security, and integration recommendations with feature, cost, complexity, and alternative guidance. Review my proposed stack one item at a time.',
        'stateKey' => 'evortix.dss.technology.v2.project.' . (int) $projectId,
        'items' => $architectTechDssItems,
        'completedTitle' => 'Technology review complete',
    ];
    include '../../includes/architect-dss-assistant.php';
endif; ?>

<?php include '../../includes/footer.php'; ?>
