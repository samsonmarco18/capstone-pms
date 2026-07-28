<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_technology'])) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $price = (float) ($_POST['price'] ?? 0);
        $expiry_date = trim((string) ($_POST['expiry_date'] ?? ''));
        
        $today = date('Y-m-d');
        if ($expiry_date && $expiry_date < $today) {
            $status = 'expired';
        } elseif ($expiry_date && $expiry_date <= date('Y-m-d', strtotime('+30 days'))) {
            $status = 'expiring';
        } else {
            $status = 'active';
        }
        
        if ($name === '' || $category === '' || $price < 0 || $expiry_date === '') {
            $_SESSION['error'] = 'Please complete all technology fields with valid values.';
        } else {
            $stmt = $conn->prepare("INSERT INTO technologies (name, category, price, expiry_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt !== false) {
                $stmt->bind_param("ssdssi", $name, $category, $price, $expiry_date, $status, $user['id']);
                
                if ($stmt->execute()) {
                    logActivity($user['id'], 'add_technology', 'technologies', $stmt->insert_id, null, $_POST);
                    $_SESSION['success'] = "Technology added successfully";
                } else {
                    $_SESSION['error'] = 'Unable to add technology.';
                }
                $stmt->close();
            } else {
                $_SESSION['error'] = 'Unable to prepare technology insert.';
            }
        }
    } elseif (isset($_POST['update_technology'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $price = (float) ($_POST['price'] ?? 0);
        $expiry_date = trim((string) ($_POST['expiry_date'] ?? ''));
        
        $oldData = null;
        $oldStmt = $conn->prepare("SELECT * FROM technologies WHERE id = ? LIMIT 1");
        if ($oldStmt !== false) {
            $oldStmt->bind_param('i', $id);
            $oldStmt->execute();
            $oldData = $oldStmt->get_result()->fetch_assoc();
            $oldStmt->close();
        }
        
        $today = date('Y-m-d');
        if ($expiry_date && $expiry_date < $today) {
            $status = 'expired';
        } elseif ($expiry_date && $expiry_date <= date('Y-m-d', strtotime('+30 days'))) {
            $status = 'expiring';
        } else {
            $status = 'active';
        }
        
        if ($id <= 0 || !$oldData || $name === '' || $category === '' || $price < 0 || $expiry_date === '') {
            $_SESSION['error'] = 'Please select a valid technology and complete all fields.';
        } else {
            $stmt = $conn->prepare("UPDATE technologies SET name=?, category=?, price=?, expiry_date=?, status=? WHERE id=?");
            if ($stmt !== false) {
                $stmt->bind_param("ssdssi", $name, $category, $price, $expiry_date, $status, $id);
                
                if ($stmt->execute()) {
                    logActivity($user['id'], 'update_technology', 'technologies', $id, $oldData, $_POST);
                    $_SESSION['success'] = "Technology updated successfully";
                } else {
                    $_SESSION['error'] = 'Unable to update technology.';
                }
                $stmt->close();
            } else {
                $_SESSION['error'] = 'Unable to prepare technology update.';
            }
        }
    } elseif (isset($_POST['delete_technology'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $oldData = null;
        $oldStmt = $conn->prepare("SELECT * FROM technologies WHERE id = ? LIMIT 1");
        if ($oldStmt !== false) {
            $oldStmt->bind_param('i', $id);
            $oldStmt->execute();
            $oldData = $oldStmt->get_result()->fetch_assoc();
            $oldStmt->close();
        }
        
        if ($id <= 0 || !$oldData) {
            $_SESSION['error'] = 'Technology not found.';
        } else {
            $stmt = $conn->prepare("DELETE FROM technologies WHERE id=?");
            if ($stmt !== false) {
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    logActivity($user['id'], 'delete_technology', 'technologies', $id, $oldData, null);
                    $_SESSION['success'] = "Technology deleted successfully";
                } else {
                    $_SESSION['error'] = 'Unable to delete technology.';
                }
                $stmt->close();
            } else {
                $_SESSION['error'] = 'Unable to prepare technology delete.';
            }
        }
    }
    
    header('Location: techstack.php');
    exit();
}

$conn->query("
    UPDATE technologies
    SET status = CASE
        WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 'expired'
        WHEN expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring'
        WHEN status <> 'inactive' THEN 'active'
        ELSE status
    END
");

$technologyStats = [
    'total' => 0,
    'active' => 0,
    'expiring' => 0,
    'expired' => 0,
    'monthly_spend' => 0,
];
$technologyStatsResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active,
        COALESCE(SUM(CASE WHEN status = 'expiring' THEN 1 ELSE 0 END), 0) AS expiring,
        COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) AS expired,
        COALESCE(SUM(price), 0) AS monthly_spend
    FROM technologies
");
if ($technologyStatsResult) {
    $technologyStatsRow = $technologyStatsResult->fetch_assoc();
    foreach ($technologyStats as $statKey => $statValue) {
        $technologyStats[$statKey] = $technologyStatsRow[$statKey] ?? $statValue;
    }
}

$technologyCategories = [];
$technologyCategoryResult = $conn->query("
    SELECT DISTINCT category
    FROM technologies
    WHERE category IS NOT NULL AND category <> ''
    ORDER BY category ASC
");
if ($technologyCategoryResult) {
    while ($technologyCategoryRow = $technologyCategoryResult->fetch_assoc()) {
        $technologyCategories[] = (string) $technologyCategoryRow['category'];
    }
}

$technologySearch = trim((string) ($_GET['search'] ?? ''));
$technologyCategoryFilter = trim((string) ($_GET['category'] ?? ''));
$technologyStatusFilter = trim((string) ($_GET['status'] ?? ''));
$allowedTechnologyPageSizes = [9, 18, 36];
$technologyPageSize = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 9;
if (!in_array($technologyPageSize, $allowedTechnologyPageSizes, true)) {
    $technologyPageSize = 9;
}
$technologyCurrentPage = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);

$technologyWhereSql = ' WHERE 1=1';
$technologyParams = [];
$technologyTypes = '';

if ($technologySearch !== '') {
    $technologySearchLike = '%' . $technologySearch . '%';
    $technologyWhereSql .= DB_DRIVER === 'pgsql'
        ? ' AND name ILIKE ?'
        : ' AND name LIKE ?';
    $technologyParams[] = $technologySearchLike;
    $technologyTypes .= 's';
}

if ($technologyCategoryFilter !== '') {
    $technologyWhereSql .= ' AND category = ?';
    $technologyParams[] = $technologyCategoryFilter;
    $technologyTypes .= 's';
}

if ($technologyStatusFilter !== '') {
    $technologyWhereSql .= ' AND status = ?';
    $technologyParams[] = $technologyStatusFilter;
    $technologyTypes .= 's';
}

$totalFilteredTechnologies = 0;
$technologyCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM technologies' . $technologyWhereSql);
if ($technologyCountStmt !== false) {
    if ($technologyTypes !== '') {
        $technologyCountStmt->bind_param($technologyTypes, ...$technologyParams);
    }
    $technologyCountStmt->execute();
    $technologyCountResult = $technologyCountStmt->get_result();
    $technologyCountRow = $technologyCountResult ? $technologyCountResult->fetch_assoc() : null;
    $totalFilteredTechnologies = (int) ($technologyCountRow['total'] ?? 0);
    $technologyCountStmt->close();
}

$technologyTotalPages = max(1, (int) ceil($totalFilteredTechnologies / $technologyPageSize));
$technologyCurrentPage = min($technologyCurrentPage, $technologyTotalPages);
$technologyPageOffset = ($technologyCurrentPage - 1) * $technologyPageSize;

$technologyQuery = '
    SELECT *
    FROM technologies
    ' . $technologyWhereSql . '
    ORDER BY CASE status
        WHEN \'expired\' THEN 1
        WHEN \'expiring\' THEN 2
        WHEN \'active\' THEN 3
        WHEN \'inactive\' THEN 4
        ELSE 5
    END,
    expiry_date ASC
    LIMIT ' . $technologyPageSize . '
    OFFSET ' . $technologyPageOffset;

$technologiesStmt = $conn->prepare($technologyQuery);
if ($technologiesStmt === false) {
    error_log('Techstack list query failed: ' . ($conn->error ?? 'unknown error'));
    $_SESSION['error'] = 'Unable to load technology stack right now.';
    $technologies = new PgsqlMysqliCompatResult([]);
} else {
    if ($technologyTypes !== '') {
        $technologiesStmt->bind_param($technologyTypes, ...$technologyParams);
    }
    $technologiesStmt->execute();
    $technologies = $technologiesStmt->get_result();
    $technologiesStmt->close();
}

$technologyPageStart = $totalFilteredTechnologies > 0 ? $technologyPageOffset + 1 : 0;
$technologyPageEnd = min($technologyPageOffset + ($technologies ? $technologies->num_rows : 0), $totalFilteredTechnologies);
$buildTechnologyPageUrl = static function (int $page, ?int $pageSize = null) use (
    $technologySearch,
    $technologyCategoryFilter,
    $technologyStatusFilter,
    $technologyPageSize
): string {
    $queryParams = [];

    if ($technologySearch !== '') {
        $queryParams['search'] = $technologySearch;
    }
    if ($technologyCategoryFilter !== '') {
        $queryParams['category'] = $technologyCategoryFilter;
    }
    if ($technologyStatusFilter !== '') {
        $queryParams['status'] = $technologyStatusFilter;
    }

    $queryParams['per_page'] = $pageSize ?? $technologyPageSize;
    if ($page > 1) {
        $queryParams['page'] = $page;
    }

    return 'techstack.php?' . http_build_query($queryParams) . '#technologyDirectory';
};

if ($technologyTotalPages <= 5) {
    $technologyPaginationPages = range(1, $technologyTotalPages);
} else {
    $technologyPaginationStart = max(1, min($technologyCurrentPage - 2, $technologyTotalPages - 4));
    $technologyPaginationPages = range($technologyPaginationStart, $technologyPaginationStart + 4);
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --theme-accent: #2c7da0;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
    --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.05), 0 10px 10px -5px rgba(0,0,0,0.01);
    --tech-surface: #ffffff;
    --tech-surface-alt: #f8fbff;
    --tech-border: var(--gray-200);
    --tech-text: var(--gray-900);
    --tech-muted: var(--gray-600);
    --tech-shadow: var(--shadow-sm);
    --tech-shadow-hover: var(--shadow-xl);
    --tech-panel-gradient: linear-gradient(135deg, var(--gray-50) 0%, #ffffff 100%);
    --tech-panel-tint: rgba(31, 99, 146, 0.1);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) {
    --tech-surface: rgba(8, 20, 33, 0.94);
    --tech-surface-alt: rgba(10, 24, 38, 0.98);
    --tech-border: rgba(138, 210, 255, 0.12);
    --tech-text: #d8e6f3;
    --tech-muted: #90aeca;
    --tech-shadow: 0 10px 28px rgba(0, 0, 0, 0.32);
    --tech-shadow-hover: 0 18px 38px rgba(0, 0, 0, 0.42);
    --tech-panel-gradient: linear-gradient(180deg, rgba(10, 24, 38, 0.98) 0%, rgba(7, 16, 27, 0.98) 100%);
    --tech-panel-tint: rgba(31, 99, 146, 0.16);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border-radius: 24px;
    padding: 2rem 2rem;
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
    letter-spacing: -0.01em;
    color: white !important;
}

.page-header-modern h2 i {
    color: white !important;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9) !important;
}

/* Add Button */
.btn-add-modern {
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white !important;
    padding: 0.7rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s ease;
    backdrop-filter: blur(10px);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-add-modern:hover {
    background: white;
    color: var(--theme-primary) !important;
    transform: translateY(-2px);
    border-color: white;
}

.btn-add-modern i {
    color: white !important;
}

.btn-add-modern:hover i {
    color: var(--theme-primary) !important;
}

/* Stats Row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-mini {
    background: var(--tech-surface);
    border-radius: 16px;
    padding: 1rem 1.5rem;
    flex: 1;
    min-width: 150px;
    box-shadow: var(--tech-shadow);
    border: 1px solid var(--tech-border);
    transition: all 0.2s ease;
}

.stat-card-mini:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-mini .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--tech-muted);
    letter-spacing: 0.5px;
}

.stat-card-mini .stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    margin-top: 0.25rem;
}

.technology-directory-card {
    overflow: hidden;
    border: 1px solid var(--tech-border);
    border-radius: 22px;
    background: var(--tech-surface);
    box-shadow: var(--tech-shadow);
}

.technology-directory-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--tech-border);
}

.technology-directory-head h5 {
    margin: 0;
    color: var(--tech-text);
    font-size: 1.05rem;
    font-weight: 600;
}

.technology-directory-head h5 i {
    margin-right: 0.55rem;
    color: var(--theme-primary);
}

.technology-directory-count {
    color: var(--tech-muted);
    font-size: 0.78rem;
    white-space: nowrap;
}

.technology-toolbar {
    display: grid;
    grid-template-columns: minmax(280px, 1.4fr) minmax(160px, 0.65fr) minmax(150px, 0.6fr) auto;
    align-items: center;
    gap: 1rem;
    padding: 0.95rem 1.5rem 1.1rem;
    background: var(--tech-surface);
}

.technology-directory-body > .tech-grid {
    padding: 0 1.5rem 1.5rem;
}

.technology-directory-body .empty-state-modern {
    border: 0;
    border-radius: 0;
    box-shadow: none;
}

.technology-filter-input {
    position: relative;
    min-width: 0;
}

.technology-filter-input > i {
    position: absolute;
    top: 50%;
    left: 0.1rem;
    z-index: 1;
    color: #668096;
    font-size: 0.82rem;
    transform: translateY(-50%);
    pointer-events: none;
}

.technology-filter-input input,
.technology-filter-input select {
    width: 100%;
    min-height: 42px;
    padding: 0.52rem 1.8rem 0.52rem 1.65rem;
    border: 0;
    border-bottom: 1px solid #cbd8e3;
    border-radius: 0;
    outline: 0;
    background: transparent;
    color: var(--tech-text);
    font-size: 0.82rem;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.technology-filter-input input:focus,
.technology-filter-input select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 2px 0 rgba(31, 99, 146, 0.12);
}

.technology-filter-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.65rem;
    white-space: nowrap;
}

.technology-filter-clear {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    min-height: 42px;
    padding: 0.55rem 0.3rem;
    color: var(--tech-muted);
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
}

.technology-filter-clear:hover {
    color: var(--theme-primary);
}

.btn-add-technology-toolbar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 42px;
    padding: 0.62rem 1.05rem;
    border: 1px solid var(--theme-primary);
    border-radius: 10px;
    background: var(--theme-primary);
    color: #fff;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
    box-shadow: 0 6px 14px rgba(31, 99, 146, 0.16);
    transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.btn-add-technology-toolbar:hover,
.btn-add-technology-toolbar:focus-visible {
    border-color: var(--theme-primary-dark);
    background: var(--theme-primary-dark);
    color: #fff;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.22);
    transform: translateY(-1px);
}

/* Technology Cards - Professional Grid */
.tech-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.technology-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin: 0;
    padding: 0.85rem 1.5rem;
    border: 0;
    border-top: 1px solid var(--tech-border);
    border-radius: 0;
    background: var(--tech-surface);
    box-shadow: none;
}

.technology-pagination__meta,
.technology-pagination__controls,
.technology-pagination__pages {
    display: flex;
    align-items: center;
}

.technology-pagination__meta {
    gap: 0.75rem;
}

.technology-pagination__summary {
    color: var(--tech-muted);
    font-size: 0.78rem;
    white-space: nowrap;
}

.technology-pagination__size {
    height: 36px;
    min-width: 98px;
    padding: 0 1.8rem 0 0.7rem;
    border: 1px solid var(--tech-border);
    border-radius: 9px;
    background: var(--tech-surface);
    color: var(--tech-text);
    font-size: 0.78rem;
    font-weight: 600;
}

.technology-pagination__controls {
    gap: 0.55rem;
}

.technology-pagination__pages {
    overflow: hidden;
    border: 1px solid var(--tech-border);
    border-radius: 9px;
    background: var(--tech-surface);
}

.technology-pagination__page,
.technology-pagination__step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    color: var(--tech-text);
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
}

.technology-pagination__page {
    min-width: 36px;
    padding: 0 0.55rem;
}

.technology-pagination__page:hover,
.technology-pagination__page.is-current {
    background: #edf5fa;
    color: var(--theme-primary);
}

.technology-pagination__page.is-current {
    box-shadow: inset 0 0 0 1px #cfe1ec;
    pointer-events: none;
}

.technology-pagination__step {
    min-width: 76px;
    padding: 0 0.75rem;
    border: 1px solid var(--tech-border);
    border-radius: 9px;
    background: var(--tech-surface);
}

.technology-pagination__step:hover {
    border-color: #9bbdd1;
    color: var(--theme-primary);
}

.technology-pagination__step.is-disabled {
    background: var(--tech-surface-alt);
    color: var(--tech-muted);
    opacity: 0.55;
    pointer-events: none;
}

.tech-card-modern {
    background: var(--tech-surface);
    border-radius: 20px;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: var(--tech-shadow);
    border: 1px solid var(--tech-border);
    position: relative;
}

.tech-card-modern:hover {
    transform: translateY(-4px);
    box-shadow: var(--tech-shadow-hover);
    border-color: var(--theme-primary-light);
}

/* Status Dot */
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
    background: #9ca3af;
}

.status-dot.active {
    background: #10b981;
}

.status-dot.expiring {
    background: #f59e0b;
}

.status-dot.expired {
    background: #ef4444;
}

.status-text {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--gray-700, #495057);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.status-corner {
    position: absolute;
    top: 12px;
    right: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0;
    background: transparent;
}

/* Card Header */
.card-header-modern {
    padding: 1.5rem 1.5rem 0 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.icon-wrapper {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.1) 0%, rgba(31, 99, 146, 0.05) 100%);
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.icon-wrapper i {
    font-size: 28px;
    color: var(--theme-primary);
}

.tech-info h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--tech-text);
    margin-bottom: 0.25rem;
}

.tech-info .category {
    font-size: 0.75rem;
    color: var(--tech-muted);
    display: inline-block;
    padding: 0.2rem 0.6rem;
    background: var(--tech-surface-alt);
    border-radius: 20px;
}

/* Pricing Section */
.pricing-section {
    padding: 1rem 1.5rem;
    background: var(--tech-panel-gradient);
    margin: 1rem 1.5rem;
    border-radius: 16px;
    text-align: center;
    border: 1px solid var(--tech-border);
}

.price-amount {
    font-size: 2rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

.price-period {
    font-size: 0.8rem;
    color: var(--tech-muted);
}

/* Meta Info */
.meta-info {
    padding: 0 1.5rem;
    margin-bottom: 1rem;
}

.meta-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px dashed var(--tech-border);
}

.meta-item:last-child {
    border-bottom: none;
}

.meta-label {
    font-size: 0.75rem;
    color: var(--tech-muted);
}

.meta-value {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--tech-text);
}

.expiry-warning {
    color: #f59e0b;
    font-size: 0.7rem;
    margin-top: 0.25rem;
}

/* Card Actions */
.card-actions-modern {
    padding: 1rem 1.5rem 1.5rem;
    display: flex;
    gap: 0.75rem;
}

.btn-modern {
    flex: 1;
    padding: 0.6rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
    transition: all 0.2s ease;
    cursor: pointer;
    text-align: center;
    border: none;
}

.btn-outline-modern {
    background: transparent;
    border: 1px solid var(--theme-primary);
    color: var(--theme-primary);
}

.btn-outline-modern:hover {
    background: var(--theme-primary);
    color: white;
    transform: translateY(-1px);
}

.btn-danger-modern {
    background: transparent;
    border: 1px solid #ef4444;
    color: #ef4444;
}

.btn-danger-modern:hover {
    background: #ef4444;
    color: white;
    transform: translateY(-1px);
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.2rem;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
}

.btn-success-modern {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.btn-success-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 4rem 2rem;
    background: var(--tech-surface);
    border-radius: 24px;
    border: 1px solid var(--tech-border);
    box-shadow: var(--tech-shadow);
}

.empty-state-modern i {
    font-size: 4rem;
    color: var(--tech-muted);
    margin-bottom: 1rem;
}

.empty-state-modern h4 {
    color: var(--tech-text);
    margin-bottom: 0.5rem;
}

/* Modal Styling */
.modal-modern .modal-content {
    border: 1px solid var(--tech-border);
    border-radius: 18px;
    box-shadow: 0 24px 70px rgba(15, 43, 67, 0.24);
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, #1f6392 0%, #0f4a6e 100%) !important;
    border: 0;
    color: #ffffff;
    padding: 1.1rem 1.35rem;
}

.modal-modern .modal-header .modal-title {
    align-items: center;
    color: #ffffff !important;
    display: flex;
    font-size: 1.05rem;
    font-weight: 700;
    gap: 0.7rem;
    margin: 0;
}

.modal-title-icon {
    align-items: center;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 9px;
    display: inline-flex;
    height: 34px;
    justify-content: center;
    width: 34px;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.75;
}

.modal-modern .modal-body {
    padding: 1.5rem;
}

.modal-modern .modal-footer {
    background: var(--tech-surface-alt);
    border-top: 1px solid var(--tech-border);
    gap: 0.65rem;
    padding: 1rem 1.5rem;
}

.modal-modern .modal-content {
    background: var(--tech-surface);
    color: var(--tech-text);
    box-shadow: var(--tech-shadow-hover);
}

.modal-modern .modal-body,
.modal-modern .modal-footer {
    background: var(--tech-surface);
}

.modal-modern .form-label,
.modal-modern .text-muted {
    color: var(--tech-muted) !important;
}

.modal-modern .form-label {
    font-size: 0.72rem;
    font-weight: 700 !important;
    letter-spacing: 0.045em;
    margin-bottom: 0.45rem;
    text-transform: uppercase;
}

.modal-modern .form-control,
.modal-modern .form-select {
    background: var(--tech-surface-alt);
    border-color: var(--tech-border);
    border-radius: 10px;
    color: var(--tech-text);
    min-height: 46px;
    padding: 0.65rem 0.85rem;
}

.modal-modern .form-control:focus,
.modal-modern .form-select:focus {
    background: var(--tech-surface-alt);
    border-color: var(--theme-primary);
    color: var(--tech-text);
    box-shadow: 0 0 0 0.2rem rgba(31, 99, 146, 0.18);
}

.technology-create-modal .modal-dialog {
    max-width: 680px;
}

.modal-secondary-action {
    align-items: center;
    background: transparent;
    border: 1px solid #cbd9e4;
    border-radius: 10px;
    color: #405a70;
    display: inline-flex;
    font-size: 0.85rem;
    font-weight: 700;
    justify-content: center;
    min-height: 44px;
    padding: 0.65rem 1.05rem;
}

.modal-secondary-action:hover {
    background: #eef5f9;
    border-color: #abc1d1;
    color: #183f59;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    box-shadow: 0 16px 38px rgba(0, 0, 0, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-mini,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content {
    background: var(--tech-surface);
    border-color: var(--tech-border);
    box-shadow: var(--tech-shadow);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-card-modern:hover {
    box-shadow: var(--tech-shadow-hover);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-mini .stat-number,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .price-amount {
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-info h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .meta-value {
    color: var(--tech-text) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-title {
    color: #ffffff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-mini .stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-info .category,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .price-period,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .meta-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expiry-warning,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .text-muted {
    color: var(--tech-muted) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-info .category {
    background: var(--tech-surface-alt);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pricing-section {
    background: linear-gradient(180deg, rgba(11, 28, 44, 0.98) 0%, rgba(8, 20, 33, 0.98) 100%);
    border-color: var(--tech-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-actions-modern .btn-outline-modern {
    border-color: rgba(143, 211, 255, 0.4);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-actions-modern .btn-outline-modern:hover {
    background: rgba(31, 99, 146, 0.2);
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-danger-modern {
    border-color: rgba(239, 68, 68, 0.55);
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-danger-modern:hover {
    background: rgba(239, 68, 68, 0.9);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-primary-modern {
    box-shadow: 0 10px 22px rgba(31, 99, 146, 0.26);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-header {
    background: linear-gradient(135deg, #0f4a6e 0%, #0a3a55 100%);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer {
    border-top-color: var(--tech-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-secondary-action {
    border-color: rgba(143, 211, 255, 0.32);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-secondary-action:hover {
    background: rgba(31, 99, 146, 0.18);
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control::placeholder {
    color: rgba(144, 174, 202, 0.75);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .btn.btn-light {
    background: rgba(144, 174, 202, 0.12);
    border-color: rgba(144, 174, 202, 0.18);
    color: var(--tech-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .btn.btn-light:hover {
    background: rgba(144, 174, 202, 0.18);
}

.delete-confirm-note {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1rem;
    border-radius: 16px;
    background: rgba(220, 38, 38, 0.06);
    border: 1px solid rgba(220, 38, 38, 0.12);
    color: var(--tech-text);
}

.delete-confirm-note i {
    color: #dc2626;
    margin-top: 0.15rem;
    flex-shrink: 0;
}

.btn-delete-cancel {
    background: var(--tech-surface-alt);
    border: 1px solid var(--tech-border);
    color: var(--tech-text);
    border-radius: 12px;
    padding: 0.65rem 1.15rem;
    font-weight: 600;
}

.btn-delete-cancel:hover {
    background: var(--tech-panel-tint);
    color: var(--tech-text);
}

.btn-delete-confirm {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    border: 1px solid #dc2626;
    color: #fff;
    border-radius: 12px;
    padding: 0.65rem 1.15rem;
    font-weight: 600;
    box-shadow: 0 10px 22px rgba(220, 38, 38, 0.18);
}

.btn-delete-confirm:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-note {
    background: rgba(220, 38, 38, 0.10);
    border-color: rgba(220, 38, 38, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-delete-cancel {
    background: var(--tech-surface-alt);
    border-color: var(--tech-border);
    color: var(--tech-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-delete-cancel:hover {
    background: rgba(144, 174, 202, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .status-text {
    color: #cfe4f4;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .technology-filter-input input,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .technology-filter-input select {
    border-color: var(--tech-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .technology-filter-input > i {
    color: #90aeca;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .technology-pagination__page:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .technology-pagination__page.is-current {
    background: rgba(31, 99, 146, 0.28);
    color: #fff;
}

/* Responsive */
@media (max-width: 1100px) {
    .technology-toolbar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .technology-filter-input--search,
    .technology-filter-actions {
        grid-column: 1 / -1;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-row {
        flex-direction: column;
    }
    
    .tech-grid {
        grid-template-columns: 1fr;
    }

    .technology-toolbar {
        grid-template-columns: 1fr;
        padding: 0.9rem 1rem 1rem;
    }

    .technology-filter-input--search,
    .technology-filter-actions {
        grid-column: auto;
    }

    .technology-filter-actions {
        justify-content: space-between;
        flex-wrap: wrap;
    }

    .technology-directory-head {
        align-items: flex-start;
        padding: 1rem;
    }

    .technology-directory-body > .tech-grid {
        padding: 0 1rem 1rem;
    }

    .technology-pagination {
        align-items: stretch;
        flex-direction: column;
        padding: 0.9rem 1rem;
    }

    .technology-pagination__meta,
    .technology-pagination__controls {
        justify-content: center;
        flex-wrap: wrap;
    }

    .technology-pagination__pages {
        max-width: 100%;
        overflow-x: auto;
    }

    .technology-pagination__step {
        min-width: 40px;
        padding: 0 0.65rem;
    }
    
    .card-actions-modern {
        flex-wrap: wrap;
    }
    
    .btn-modern {
        min-width: 100px;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Modern Header -->
    <div class="page-header-modern">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2>
                    <i class="fas fa-cubes me-2"></i> 
                    Technology Portfolio
                </h2>
                <p>Manage and monitor your organization's technology stack, subscriptions, and renewals</p>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-row">
        <div class="stat-card-mini">
            <div class="stat-label">Total Technologies</div>
            <div class="stat-number"><?php echo (int) $technologyStats['total']; ?></div>
        </div>
        <div class="stat-card-mini">
            <div class="stat-label">Active Services</div>
            <div class="stat-number" style="color: #10b981;"><?php echo (int) $technologyStats['active']; ?></div>
        </div>
        <div class="stat-card-mini">
            <div class="stat-label">Expiring Soon</div>
            <div class="stat-number" style="color: #f59e0b;"><?php echo (int) $technologyStats['expiring']; ?></div>
        </div>
        <div class="stat-card-mini">
            <div class="stat-label">Expired Services</div>
            <div class="stat-number" style="color: #ef4444;"><?php echo (int) $technologyStats['expired']; ?></div>
        </div>
        <div class="stat-card-mini">
            <div class="stat-label">Monthly Spend</div>
            <div class="stat-number">$<?php echo number_format((float) $technologyStats['monthly_spend'], 0); ?></div>
        </div>
    </div>

    <section class="technology-directory-card" id="technologyDirectory">
        <header class="technology-directory-head">
            <h5><i class="fas fa-list"></i>Technology Directory</h5>
            <span class="technology-directory-count">
                <?php echo $totalFilteredTechnologies; ?> technolog<?php echo $totalFilteredTechnologies === 1 ? 'y' : 'ies'; ?>
            </span>
        </header>
        <form method="GET" action="techstack.php#technologyDirectory" class="technology-toolbar" id="technologyFilterForm">
            <input type="hidden" name="per_page" value="<?php echo $technologyPageSize; ?>">
            <div class="technology-filter-input technology-filter-input--search">
                <label class="visually-hidden" for="technologySearch">Search technologies</label>
                <i class="fas fa-search" aria-hidden="true"></i>
                <input
                    type="search"
                    id="technologySearch"
                    name="search"
                    placeholder="Search technologies"
                    value="<?php echo htmlspecialchars($technologySearch); ?>"
                    autocomplete="off"
                >
            </div>
            <div class="technology-filter-input">
                <label class="visually-hidden" for="technologyCategory">Filter by category</label>
                <i class="fas fa-layer-group" aria-hidden="true"></i>
                <select id="technologyCategory" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($technologyCategories as $technologyCategory): ?>
                    <option value="<?php echo htmlspecialchars($technologyCategory); ?>" <?php echo $technologyCategoryFilter === $technologyCategory ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($technologyCategory); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="technology-filter-input">
                <label class="visually-hidden" for="technologyStatus">Filter by status</label>
                <i class="fas fa-filter" aria-hidden="true"></i>
                <select id="technologyStatus" name="status">
                    <option value="">All statuses</option>
                    <option value="active" <?php echo $technologyStatusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="expiring" <?php echo $technologyStatusFilter === 'expiring' ? 'selected' : ''; ?>>Expiring soon</option>
                    <option value="expired" <?php echo $technologyStatusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    <option value="inactive" <?php echo $technologyStatusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="technology-filter-actions">
                <?php if ($technologySearch !== '' || $technologyCategoryFilter !== '' || $technologyStatusFilter !== ''): ?>
                <a href="techstack.php?per_page=<?php echo $technologyPageSize; ?>#technologyDirectory" class="technology-filter-clear">
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                    <span>Clear</span>
                </a>
                <?php endif; ?>
                <button type="button" class="btn-add-technology-toolbar" data-bs-toggle="modal" data-bs-target="#addTechnologyModal">
                    <i class="fas fa-plus" aria-hidden="true"></i>
                    <span>Add Technology</span>
                </button>
            </div>
            <button type="submit" class="visually-hidden">Apply filters</button>
        </form>

        <div class="technology-directory-body">
    <!-- Technologies Grid -->
    <?php if($totalFilteredTechnologies > 0): ?>
        <div class="tech-grid">
            <?php while($tech = $technologies->fetch_assoc()): ?>
            <div class="tech-card-modern">
                <div class="status-corner">
                    <span class="status-dot <?php echo $tech['status']; ?>"></span>
                    <span class="status-text"><?php echo strtoupper($tech['status']); ?></span>
                </div>
                
                <!-- Card Header -->
                <div class="card-header-modern">
                    <div class="icon-wrapper">
                        <i class="fas fa-<?php 
                            echo $tech['category'] == 'Cloud Platform' ? 'cloud' : 
                                ($tech['category'] == 'Database' ? 'database' : 
                                ($tech['category'] == 'Storage' ? 'hdd' : 
                                ($tech['category'] == 'Networking' ? 'network-wired' : 
                                ($tech['category'] == 'AI/ML' ? 'brain' : 
                                ($tech['category'] == 'IoT' ? 'microchip' : 
                                ($tech['category'] == 'DevOps' ? 'code-branch' : 'shield-alt')))))); 
                        ?>"></i>
                    </div>
                    <div class="tech-info">
                        <h4><?php echo htmlspecialchars($tech['name']); ?></h4>
                        <span class="category"><?php echo htmlspecialchars($tech['category']); ?></span>
                    </div>
                </div>
                
                <!-- Pricing Section -->
                <div class="pricing-section">
                    <span class="price-amount">$<?php echo number_format($tech['price'], 2); ?></span>
                    <span class="price-period">/month</span>
                </div>
                
                <!-- Meta Information -->
                <div class="meta-info">
                    <div class="meta-item">
                        <span class="meta-label">
                            <i class="fas fa-calendar-alt me-1"></i> Expiry Date
                        </span>
                        <span class="meta-value">
                            <?php
                            $expiryValue = trim((string) ($tech['expiry_date'] ?? ''));
                            echo $expiryValue !== '' ? htmlspecialchars(date('M d, Y', strtotime($expiryValue))) : 'No expiry date';
                            ?>
                        </span>
                    </div>
                    <?php 
                    $daysLeft = $expiryValue !== '' ? (int) ceil((strtotime($expiryValue) - time()) / 86400) : 0;
                    if($tech['status'] == 'expiring' && $daysLeft > 0):
                    ?>
                    <div class="expiry-warning">
                        <i class="fas fa-clock me-1"></i> <?php echo $daysLeft; ?> days remaining
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Actions -->
                <div class="card-actions-modern">
                    <button class="btn-modern btn-outline-modern" onclick="editTechnology(<?php echo htmlspecialchars(json_encode($tech)); ?>)">
                        <i class="fas fa-edit me-1"></i> Edit
                    </button>
                    <button
                        type="button"
                        class="btn-modern btn-danger-modern"
                        data-tech-id="<?php echo (int) $tech['id']; ?>"
                        data-tech-name="<?php echo htmlspecialchars($tech['name'], ENT_QUOTES); ?>"
                        onclick="openDeleteTechnologyModal(this)"
                    >
                        <i class="fas fa-trash-alt me-1"></i>
                    </button>
                </div>
                
                <?php if(in_array($tech['status'], ['expiring', 'expired'], true)): ?>
                <div style="padding: 0 1.5rem 1.5rem;">
                    <button class="btn-modern btn-success-modern w-100" onclick="openRenewTechnologyModal(<?php echo htmlspecialchars(json_encode($tech)); ?>)">
                        <i class="fas fa-sync-alt me-1"></i> Renew Subscription
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        </div>
        <div class="technology-pagination" aria-label="Technology portfolio pagination">
            <div class="technology-pagination__meta">
                <span class="technology-pagination__summary">
                    <?php echo $technologyPageStart; ?>&ndash;<?php echo $technologyPageEnd; ?> of <?php echo $totalFilteredTechnologies; ?>
                </span>
                <label class="visually-hidden" for="technologyPageSize">Technologies per page</label>
                <select class="technology-pagination__size" id="technologyPageSize" aria-label="Technologies per page">
                    <?php foreach ($allowedTechnologyPageSizes as $pageSizeOption): ?>
                    <option value="<?php echo $pageSizeOption; ?>" <?php echo $pageSizeOption === $technologyPageSize ? 'selected' : ''; ?>>
                        <?php echo $pageSizeOption; ?> items
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <nav class="technology-pagination__controls" aria-label="Technology pages">
                <?php if ($technologyCurrentPage > 1): ?>
                <a class="technology-pagination__step" href="<?php echo htmlspecialchars($buildTechnologyPageUrl($technologyCurrentPage - 1)); ?>">Previous</a>
                <?php else: ?>
                <span class="technology-pagination__step is-disabled" aria-disabled="true">Previous</span>
                <?php endif; ?>
                <span class="technology-pagination__pages">
                    <?php foreach ($technologyPaginationPages as $pageNumber): ?>
                    <a
                        class="technology-pagination__page<?php echo $pageNumber === $technologyCurrentPage ? ' is-current' : ''; ?>"
                        href="<?php echo htmlspecialchars($buildTechnologyPageUrl($pageNumber)); ?>"
                        <?php echo $pageNumber === $technologyCurrentPage ? 'aria-current="page"' : ''; ?>
                    ><?php echo $pageNumber; ?></a>
                    <?php endforeach; ?>
                </span>
                <?php if ($technologyCurrentPage < $technologyTotalPages): ?>
                <a class="technology-pagination__step" href="<?php echo htmlspecialchars($buildTechnologyPageUrl($technologyCurrentPage + 1)); ?>">Next</a>
                <?php else: ?>
                <span class="technology-pagination__step is-disabled" aria-disabled="true">Next</span>
                <?php endif; ?>
            </nav>
        </div>
    <?php else: ?>
        <div class="empty-state-modern">
            <?php if ((int) $technologyStats['total'] > 0): ?>
                <i class="fas fa-search"></i>
                <h4>No Matching Technologies</h4>
                <p>Adjust the search or filters to view more portfolio items.</p>
                <a class="btn-primary-modern" href="techstack.php?per_page=<?php echo $technologyPageSize; ?>#technologyDirectory">
                    <i class="fas fa-rotate-left"></i> Clear Filters
                </a>
            <?php else: ?>
                <i class="fas fa-cube"></i>
                <h4>No Technologies in Portfolio</h4>
                <p>Get started by adding your first technology or service.</p>
                <button class="btn-add-technology-toolbar" data-bs-toggle="modal" data-bs-target="#addTechnologyModal">
                    <i class="fas fa-plus"></i> Add Your First Technology
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
        </div>
    </section>
</div>

<!-- Delete Technology Modal -->
<div class="modal fade modal-modern" id="deleteTechnologyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-trash-alt me-2"></i> Delete Technology
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="deleteTechnologyForm" data-app-loading data-loading-title="Deleting technology" data-loading-text="Please wait while the technology item is removed.">
                <input type="hidden" name="delete_technology" value="1">
                <input type="hidden" name="id" id="delete_technology_id">
                <div class="modal-body">
                    <div class="delete-confirm-note mb-0">
                        <i class="fas fa-triangle-exclamation fa-lg"></i>
                        <div>
                            <div class="fw-semibold mb-1" id="deleteTechnologyName">Selected technology will appear here.</div>
                            <div class="small text-muted">This action cannot be undone and the technology entry will be removed permanently.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-delete-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-delete-confirm">
                        <i class="fas fa-trash-alt me-1"></i> Yes, delete it
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Renew Technology Modal -->
<div class="modal fade modal-modern" id="renewTechnologyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-sync-alt me-2"></i> Renew Subscription
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="renewTechnologyForm">
                <input type="hidden" name="id" id="renew_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Technology Name</label>
                        <input type="text" id="renew_name" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Expiry Date</label>
                        <input type="date" id="renew_current_expiry_date" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Monthly Cost ($)</label>
                        <input type="number" id="renew_current_price" class="form-control" step="0.01" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Expiry Date</label>
                        <input type="date" name="expiry_date" id="renew_expiry_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Monthly Cost Increase ($)</label>
                        <input type="number" name="price_increase" id="renew_price_increase" class="form-control" step="0.01" min="0" required>
                        <small class="text-muted d-block mt-1">This amount will be added to the current monthly cost.</small>
                    </div>
                    <div class="alert alert-danger d-none mb-0" id="renewError"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-modern px-4">
                        <i class="fas fa-save me-1"></i> Renew
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Technology Modal -->
<div class="modal fade modal-modern technology-create-modal" id="addTechnologyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-title-icon"><i class="fas fa-cubes"></i></span>
                    Add New Technology
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Adding technology" data-loading-text="Please wait while the technology item is saved.">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Technology Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g., Microsoft Azure" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select" required>
                                <option value="Cloud Platform">Cloud Platform</option>
                                <option value="Database">Database</option>
                                <option value="Storage">Storage</option>
                                <option value="Networking">Networking</option>
                                <option value="AI/ML">AI/ML</option>
                                <option value="IoT">IoT</option>
                                <option value="DevOps">DevOps</option>
                                <option value="Security">Security</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Monthly Price ($)</label>
                            <input type="number" name="price" class="form-control" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-secondary-action" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_technology" class="btn-primary-modern px-4">Add Technology</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Technology Modal -->
<div class="modal fade modal-modern" id="editTechnologyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i> Edit Technology
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Updating technology" data-loading-text="Please wait while the technology item is updated.">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Technology Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category</label>
                        <select name="category" id="edit_category" class="form-select" required>
                            <option value="Cloud Platform">Cloud Platform</option>
                            <option value="Database">Database</option>
                            <option value="Storage">Storage</option>
                            <option value="Networking">Networking</option>
                            <option value="AI/ML">AI/ML</option>
                            <option value="IoT">IoT</option>
                            <option value="DevOps">DevOps</option>
                            <option value="Security">Security</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Monthly Price ($)</label>
                        <input type="number" name="price" id="edit_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Expiry Date</label>
                        <input type="date" name="expiry_date" id="edit_expiry_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_technology" class="btn-primary-modern px-4">Update Technology</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const technologyFilterForm = document.getElementById('technologyFilterForm');
const technologySearchInput = document.getElementById('technologySearch');
const technologyCategoryFilter = document.getElementById('technologyCategory');
const technologyStatusFilter = document.getElementById('technologyStatus');
const technologyPageSize = document.getElementById('technologyPageSize');
let technologySearchTimer = null;

function submitTechnologyFilters() {
    technologyFilterForm?.requestSubmit();
}

technologySearchInput?.addEventListener('input', () => {
    window.clearTimeout(technologySearchTimer);
    technologySearchTimer = window.setTimeout(submitTechnologyFilters, 450);
});

technologyCategoryFilter?.addEventListener('change', submitTechnologyFilters);
technologyStatusFilter?.addEventListener('change', submitTechnologyFilters);

technologyPageSize?.addEventListener('change', function() {
    const params = new URLSearchParams(window.location.search);
    params.set('per_page', this.value);
    params.delete('page');
    window.location.href = 'techstack.php?' + params.toString() + '#technologyDirectory';
});

function editTechnology(tech) {
    document.getElementById('edit_id').value = tech.id;
    document.getElementById('edit_name').value = tech.name;
    document.getElementById('edit_category').value = tech.category;
    document.getElementById('edit_price').value = tech.price;
    document.getElementById('edit_expiry_date').value = tech.expiry_date;
    
    new bootstrap.Modal(document.getElementById('editTechnologyModal')).show();
}

function openDeleteTechnologyModal(button) {
    const techId = button.getAttribute('data-tech-id') || '';
    const techName = button.getAttribute('data-tech-name') || 'this technology';

    document.getElementById('delete_technology_id').value = techId;
    document.getElementById('deleteTechnologyName').textContent = `Technology: ${techName}`;

    new bootstrap.Modal(document.getElementById('deleteTechnologyModal')).show();
}

function openRenewTechnologyModal(tech) {
    document.getElementById('renew_id').value = tech.id;
    document.getElementById('renew_name').value = tech.name;
    document.getElementById('renew_current_expiry_date').value = tech.expiry_date || '';
    document.getElementById('renew_current_price').value = Number(tech.price || 0).toFixed(2);
    document.getElementById('renew_price_increase').value = Number(tech.price || 0).toFixed(2);

    const currentDate = tech.expiry_date ? new Date(`${tech.expiry_date}T00:00:00`) : new Date();
    const baseDate = currentDate < new Date() ? new Date() : currentDate;
    baseDate.setFullYear(baseDate.getFullYear() + 1);
    document.getElementById('renew_expiry_date').value = baseDate.toISOString().slice(0, 10);

    document.getElementById('renewError').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('renewTechnologyModal')).show();
}

document.getElementById('renewTechnologyForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const errorBox = document.getElementById('renewError');
    errorBox.classList.add('d-none');

    $.ajax({
        url: '../../api/renew-technology.php',
        method: 'POST',
        data: $(this).serialize(),
        success: function(response) {
            const result = typeof response === 'string' ? JSON.parse(response) : response;
            if (result.success) {
                location.reload();
                return;
            }

            errorBox.textContent = result.error || 'Unable to renew subscription';
            errorBox.classList.remove('d-none');
        },
        error: function(xhr) {
            let message = 'Unable to renew subscription';
            try {
                message = JSON.parse(xhr.responseText).error || message;
            } catch (error) {}
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        }
    });
});
</script>

<?php
include '../../includes/footer.php';
?>
