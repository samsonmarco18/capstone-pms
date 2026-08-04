<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();

// Get filters
$search = trim((string) ($_GET['search'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? ''));
$userFilter = max(0, (int) ($_GET['user_id'] ?? 0));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$allowedAuditPageSizes = [25, 50, 100];
$auditPageSize = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 25;
if (!in_array($auditPageSize, $allowedAuditPageSizes, true)) {
    $auditPageSize = 25;
}
$auditCurrentPage = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);

$auditWhereSql = ' WHERE 1=1';
$auditParams = [];
$auditTypes = '';

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $auditWhereSql .= DB_DRIVER === 'pgsql'
        ? ' AND (al.action ILIKE ? OR al.table_name ILIKE ? OR u.full_name ILIKE ?)'
        : ' AND (al.action LIKE ? OR al.table_name LIKE ? OR u.full_name LIKE ?)';
    $auditParams[] = $searchLike;
    $auditParams[] = $searchLike;
    $auditParams[] = $searchLike;
    $auditTypes .= 'sss';
}

if ($actionFilter !== '') {
    $auditWhereSql .= ' AND al.action = ?';
    $auditParams[] = $actionFilter;
    $auditTypes .= 's';
}

if ($userFilter > 0) {
    $auditWhereSql .= ' AND al.user_id = ?';
    $auditParams[] = $userFilter;
    $auditTypes .= 'i';
}

if ($dateFrom !== '') {
    $auditWhereSql .= ' AND DATE(al.created_at) >= ?';
    $auditParams[] = $dateFrom;
    $auditTypes .= 's';
}

if ($dateTo !== '') {
    $auditWhereSql .= ' AND DATE(al.created_at) <= ?';
    $auditParams[] = $dateTo;
    $auditTypes .= 's';
}

$totalFilteredLogs = 0;
$auditCountStmt = $conn->prepare('
    SELECT COUNT(*) AS total
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    ' . $auditWhereSql);
if ($auditCountStmt !== false) {
    if ($auditTypes !== '') {
        $auditCountStmt->bind_param($auditTypes, ...$auditParams);
    }
    $auditCountStmt->execute();
    $auditCountResult = $auditCountStmt->get_result();
    $auditCountRow = $auditCountResult ? $auditCountResult->fetch_assoc() : null;
    $totalFilteredLogs = (int) ($auditCountRow['total'] ?? 0);
    $auditCountStmt->close();
}

$auditTotalPages = max(1, (int) ceil($totalFilteredLogs / $auditPageSize));
$auditCurrentPage = min($auditCurrentPage, $auditTotalPages);
$auditPageOffset = ($auditCurrentPage - 1) * $auditPageSize;

$auditQuery = '
    SELECT al.*, u.username, u.full_name
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    ' . $auditWhereSql . '
    ORDER BY al.created_at DESC
    LIMIT ' . $auditPageSize . '
    OFFSET ' . $auditPageOffset;

$auditStmt = $conn->prepare($auditQuery);
if ($auditStmt === false) {
    $logs = new PgsqlMysqliCompatResult([]);
} else {
    if ($auditTypes !== '') {
        $auditStmt->bind_param($auditTypes, ...$auditParams);
    }
    $auditStmt->execute();
    $logs = $auditStmt->get_result();
    $auditStmt->close();
}

$auditPageStart = $totalFilteredLogs > 0 ? $auditPageOffset + 1 : 0;
$auditPageEnd = min($auditPageOffset + ($logs ? $logs->num_rows : 0), $totalFilteredLogs);
$buildAuditPageUrl = static function (int $page, ?int $pageSize = null) use (
    $search,
    $actionFilter,
    $userFilter,
    $dateFrom,
    $dateTo,
    $auditPageSize
): string {
    $queryParams = [];

    if ($search !== '') {
        $queryParams['search'] = $search;
    }
    if ($actionFilter !== '') {
        $queryParams['action'] = $actionFilter;
    }
    if ($userFilter > 0) {
        $queryParams['user_id'] = $userFilter;
    }
    if ($dateFrom !== '') {
        $queryParams['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $queryParams['date_to'] = $dateTo;
    }

    $queryParams['per_page'] = $pageSize ?? $auditPageSize;
    if ($page > 1) {
        $queryParams['page'] = $page;
    }

    return 'audit-log.php?' . http_build_query($queryParams) . '#auditDirectory';
};

if ($auditTotalPages <= 5) {
    $auditPaginationPages = range(1, $auditTotalPages);
} else {
    $auditPaginationStart = max(1, min($auditCurrentPage - 2, $auditTotalPages - 4));
    $auditPaginationPages = range($auditPaginationStart, $auditPaginationStart + 4);
}

$auditQuoteIdentifier = static function (string $identifier): string {
    $identifierQuote = DB_DRIVER === 'pgsql' ? '"' : '`';
    return $identifierQuote . str_replace($identifierQuote, $identifierQuote . $identifierQuote, $identifier) . $identifierQuote;
};

$auditLogCanRestore = static function (array $log) use ($conn, $auditQuoteIdentifier): bool {
    if (stripos((string) ($log['action'] ?? ''), 'delete') === false || empty($log['old_data'])) {
        return false;
    }

    $tableName = (string) ($log['table_name'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        return false;
    }

    $oldData = json_decode((string) $log['old_data'], true);
    if (!is_array($oldData) || !isset($oldData['id'])) {
        return false;
    }

    $quotedTableName = $auditQuoteIdentifier($tableName);
    $existingStmt = $conn->prepare("SELECT id FROM {$quotedTableName} WHERE id = ? LIMIT 1");
    if ($existingStmt === false) {
        return false;
    }

    $existingId = (int) $oldData['id'];
    $existingStmt->bind_param('i', $existingId);
    $existingStmt->execute();
    $exists = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    return !$exists;
};

// Get unique actions for filter
$actions = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
$users = $conn->query("SELECT id, full_name FROM users ORDER BY full_name");

// Get statistics
$totalLogs = $conn->query("SELECT COUNT(*) as count FROM audit_logs")->fetch_assoc()['count'];
$todayLogs = $conn->query("SELECT COUNT(*) as count FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];
$deleteActions = $conn->query("SELECT COUNT(*) as count FROM audit_logs WHERE action LIKE '%delete%'")->fetch_assoc()['count'];

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
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

/* Stats Row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-modern {
    background: white;
    border-radius: 20px;
    padding: 1.25rem 1.5rem;
    flex: 1;
    min-width: 180px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-modern .stat-icon {
    position: absolute;
    right: 1rem;
    top: 1rem;
    opacity: 0.65;
    font-size: 1.6rem;
    color: var(--theme-primary);
}

.stat-card-modern .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.card-header-modern {
    padding: 1.5rem 2rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.card-header-modern h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
}

.audit-directory-count {
    color: var(--gray-600);
    font-size: 0.78rem;
    white-space: nowrap;
}

/* Filter Section */
.filter-section {
    padding: 1rem 2rem 1.15rem;
    background: #fff;
}

.filter-group {
    display: grid;
    grid-template-columns: minmax(210px, 1.2fr) minmax(155px, 0.8fr) minmax(155px, 0.8fr) minmax(135px, 0.65fr) minmax(135px, 0.65fr) auto;
    align-items: center;
    gap: 1rem;
}

.filter-input {
    position: relative;
    min-width: 0;
}

.filter-input > i {
    position: absolute;
    top: 50%;
    left: 0.1rem;
    z-index: 1;
    color: #668096;
    font-size: 0.82rem;
    transform: translateY(-50%);
    pointer-events: none;
}

.filter-input input,
.filter-input select {
    width: 100%;
    min-height: 42px;
    padding: 0.52rem 1.8rem 0.52rem 1.65rem;
    border: 0;
    border-bottom: 1px solid #cbd8e3;
    border-radius: 0;
    outline: 0;
    background: transparent;
    color: #344b61;
    font-size: 0.82rem;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.filter-input input:focus,
.filter-input select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 2px 0 rgba(31, 99, 146, 0.12);
}

.audit-toolbar-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.55rem;
    white-space: nowrap;
}

.audit-filter-clear {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    min-height: 40px;
    padding: 0.5rem 0.25rem;
    color: var(--gray-600);
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
}

.audit-filter-clear:hover {
    color: var(--theme-primary);
}

.btn-audit-toolbar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    min-height: 40px;
    padding: 0.55rem 0.75rem;
    border: 1px solid var(--theme-primary);
    border-radius: 9px;
    background: var(--theme-primary);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
    transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
}

.btn-audit-toolbar.is-secondary {
    background: transparent;
    color: var(--theme-primary);
}

.btn-audit-toolbar:hover,
.btn-audit-toolbar:focus-visible {
    border-color: var(--theme-primary-dark);
    background: var(--theme-primary-dark);
    color: #fff;
}

/* Action Buttons */
.btn-action {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 500;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-action-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    box-shadow: none;
}

.btn-action-success:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}

.btn-action-info {
    background: linear-gradient(135deg, #1f6392 0%, #1f6392 100%);
    border: none;
    box-shadow: none;
}

.btn-action-info:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
}

.btn-action-info:focus,
.btn-action-info:focus-visible,
.btn-action-success:focus,
.btn-action-success:focus-visible {
    outline: none;
    box-shadow: none;
    border: none;
}

/* Header Buttons (match Tech Stack Add button) */
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

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

.audit-table {
    width: 100%;
    border-collapse: collapse;
}

.audit-table thead {
    background: var(--gray-50);
}

.audit-table th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.audit-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.audit-table tr:hover {
    background: var(--gray-50);
}

.audit-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 1.5rem;
    border-top: 1px solid var(--gray-200);
    background: #fff;
}

.audit-pagination__meta,
.audit-pagination__controls,
.audit-pagination__pages {
    display: flex;
    align-items: center;
}

.audit-pagination__meta {
    gap: 0.75rem;
}

.audit-pagination__summary {
    color: var(--gray-600);
    font-size: 0.78rem;
    white-space: nowrap;
}

.audit-pagination__size {
    height: 36px;
    min-width: 98px;
    padding: 0 1.8rem 0 0.7rem;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background: #fff;
    color: var(--gray-900);
    font-size: 0.78rem;
    font-weight: 600;
}

.audit-pagination__controls {
    gap: 0.55rem;
}

.audit-pagination__pages {
    overflow: hidden;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background: #fff;
}

.audit-pagination__page,
.audit-pagination__step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    color: var(--gray-900);
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
}

.audit-pagination__page {
    min-width: 36px;
    padding: 0 0.55rem;
}

.audit-pagination__page:hover,
.audit-pagination__page.is-current {
    background: #edf5fa;
    color: var(--theme-primary);
}

.audit-pagination__page.is-current {
    box-shadow: inset 0 0 0 1px #cfe1ec;
    pointer-events: none;
}

.audit-pagination__step {
    min-width: 76px;
    padding: 0 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background: #fff;
}

.audit-pagination__step:hover {
    border-color: #9bbdd1;
    color: var(--theme-primary);
}

.audit-pagination__step.is-disabled {
    background: var(--gray-50);
    color: var(--gray-600);
    opacity: 0.55;
    pointer-events: none;
}

.user-info {
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.user-username {
    font-size: 0.7rem;
    color: var(--gray-600);
}

/* Action Badges */
.badge-action {
    display: inline-block;
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-action.create {
    background: #d1fae5;
    color: #059669;
}

.badge-action.update {
    background: #fef3c7;
    color: #d97706;
}

.badge-action.delete {
    background: #fee2e2;
    color: #dc2626;
}

.badge-action.login {
    background: #dbeafe;
    color: #2563eb;
}

.badge-record-id {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.3rem 0.75rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    background: #eef8ff;
    color: #1f6392;
    border: 1px solid rgba(31, 99, 146, 0.14);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state-modern i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state-modern h4 {
    color: var(--gray-900);
    margin-bottom: 0.5rem;
}

.empty-state-modern p {
    color: var(--gray-600);
}

/* Modal Styling */
.modal-modern .modal-dialog {
    max-width: 880px;
    padding: 1rem;
}

.modal-modern .modal-content {
    background: #f8fbfd;
    border: 1px solid #dbe7ef;
    border-radius: 20px;
    box-shadow: 0 24px 70px rgba(15, 43, 67, 0.22);
    overflow: hidden;
}

.modal-modern .modal-header {
    align-items: center;
    background: linear-gradient(135deg, #1f6392 0%, #0f4a6e 100%) !important;
    border-bottom: 0;
    padding: 1.15rem 1.35rem;
}

.modal-modern .modal-header .modal-title {
    align-items: center;
    color: #ffffff !important;
    display: flex;
    font-size: 1.15rem;
    font-weight: 700;
    gap: 0.75rem;
    margin: 0;
}

.log-modal-title-icon {
    align-items: center;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    color: #ffffff;
    display: inline-flex;
    height: 38px;
    justify-content: center;
    width: 38px;
}

.modal-modern .modal-header .btn-close {
    box-shadow: none;
    filter: brightness(0) invert(1) !important;
    margin: 0;
    opacity: 0.55;
}

.modal-modern .modal-header .btn-close:hover {
    opacity: 0.85;
}

.modal-modern .modal-body {
    background: #f8fbfd;
    max-height: min(72vh, 680px);
    overflow-y: auto;
    padding: 1.35rem;
}

.log-details-layout {
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
}

.log-details-layout.is-single {
    grid-template-columns: minmax(0, 1fr);
}

.log-detail-panel {
    background: #ffffff;
    border: 1px solid #dfe8ef;
    border-radius: 15px;
    box-shadow: 0 4px 14px rgba(30, 74, 104, 0.05);
    min-width: 0;
    padding: 1.15rem;
}

.log-detail-panel__title {
    align-items: center;
    color: #19496b;
    display: flex;
    font-size: 0.9rem;
    font-weight: 700;
    gap: 0.55rem;
    margin: 0 0 1rem;
}

.log-detail-panel__title-icon {
    align-items: center;
    background: #eaf6fc;
    border-radius: 8px;
    color: #17658f;
    display: inline-flex;
    height: 30px;
    justify-content: center;
    width: 30px;
}

.log-detail-grid {
    display: grid;
    gap: 0;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin: 0;
}

.log-detail-item {
    border-bottom: 1px solid #edf2f6;
    min-width: 0;
    padding: 0.85rem 0;
}

.log-detail-item:nth-last-child(-n + 2) {
    border-bottom: 0;
}

.log-detail-item:nth-child(odd) {
    padding-right: 1rem;
}

.log-detail-item:nth-child(even) {
    border-left: 1px solid #edf2f6;
    padding-left: 1rem;
}

.log-detail-item dt {
    color: #718398;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    margin-bottom: 0.3rem;
    text-transform: uppercase;
}

.log-detail-item dd {
    color: #18324b;
    font-size: 0.92rem;
    font-weight: 600;
    line-height: 1.45;
    margin: 0;
    overflow-wrap: anywhere;
}

.log-detail-item code {
    color: #17658f;
    font-size: 0.86rem;
}

.log-change-list {
    display: grid;
    gap: 0.85rem;
}

.log-change-preview {
    border: 1px solid #e2e9ef;
    border-radius: 12px;
    min-width: 0;
    overflow: hidden;
}

.log-change-preview__header {
    align-items: center;
    background: #f5f8fa;
    border-bottom: 1px solid #e2e9ef;
    color: #52677c;
    display: flex;
    font-size: 0.76rem;
    font-weight: 700;
    gap: 0.45rem;
    padding: 0.65rem 0.8rem;
    text-transform: uppercase;
}

.log-change-preview.is-before .log-change-preview__header {
    color: #a45b08;
}

.log-change-preview.is-after .log-change-preview__header {
    color: #087b55;
}

.log-change-preview pre {
    background: #fbfcfd;
    color: #334b61;
    font-size: 0.78rem;
    line-height: 1.55;
    margin: 0;
    max-height: 250px;
    overflow: auto;
    padding: 0.85rem;
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* Responsive */
@media (max-width: 1250px) {
    .filter-group {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .audit-toolbar-actions {
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
    
    .stat-card-modern {
        min-width: auto;
    }
    
    .filter-group {
        grid-template-columns: 1fr;
    }
    
    .filter-input {
        width: 100%;
    }

    .audit-toolbar-actions {
        grid-column: auto;
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .audit-pagination {
        align-items: stretch;
        flex-direction: column;
        padding: 0.9rem 1rem;
    }

    .audit-pagination__meta,
    .audit-pagination__controls {
        justify-content: center;
        flex-wrap: wrap;
    }

    .audit-pagination__pages {
        max-width: 100%;
        overflow-x: auto;
    }

    .audit-pagination__step {
        min-width: 40px;
        padding: 0 0.65rem;
    }
    
    .audit-table th,
    .audit-table td {
        padding: 0.75rem 1rem;
    }

    .modal-modern .modal-dialog {
        margin: 0.5rem auto;
        padding: 0.5rem;
    }

    .modal-modern .modal-body {
        padding: 1rem;
    }

    .log-details-layout,
    .log-detail-grid {
        grid-template-columns: 1fr;
    }

    .log-detail-item,
    .log-detail-item:nth-child(odd),
    .log-detail-item:nth-child(even),
    .log-detail-item:nth-last-child(-n + 2) {
        border-bottom: 1px solid #edf2f6;
        border-left: 0;
        padding: 0.75rem 0;
    }

    .log-detail-item:last-child {
        border-bottom: 0;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content {
    background: #071321 !important;
    border-color: rgba(138, 210, 255, 0.12) !important;
    color: #d8e6f3 !important;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.32) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    box-shadow: 0 16px 38px rgba(0, 0, 0, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-detail-panel {
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-header {
    background: linear-gradient(135deg, #1f6392 0%, #0f4a6e 100%) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-modal-title-icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-detail-panel__title-icon {
    background: rgba(42, 148, 205, 0.16);
    color: #8ad2ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-modal-title-icon {
    background: rgba(255, 255, 255, 0.14);
    border-color: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-detail-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-change-preview,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-change-preview__header {
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-detail-panel__title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-detail-item dd {
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-detail-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-detail-item:nth-child(even) {
    border-color: rgba(138, 210, 255, 0.1);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-detail-item dt {
    color: #90aeca;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-change-preview__header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-change-preview pre {
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .log-change-preview pre {
    color: #bdd0df;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__pages,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__size {
    background: #071321;
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-info,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-title {
    color: #d8e6f3 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .user-username,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-text,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-label {
    color: #90aeca !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-table tr:hover {
    background: rgba(31, 99, 146, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input input,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select {
    background: transparent;
    border-color: rgba(138, 210, 255, 0.18);
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input > i {
    color: #90aeca;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-audit-toolbar.is-secondary {
    border-color: rgba(143, 211, 255, 0.4);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__page,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__size {
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__page:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__page.is-current {
    background: rgba(31, 99, 146, 0.28);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .audit-pagination__step.is-disabled {
    background: #0a1b2c;
    color: #90aeca;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-select {
    background: rgba(10, 24, 38, 0.98);
    border-color: rgba(138, 210, 255, 0.12);
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-action.create {
    background: rgba(5, 150, 105, 0.16);
    color: #94f1c6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-action.update {
    background: rgba(217, 119, 6, 0.16);
    color: #ffd58a;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-action.delete {
    background: rgba(220, 38, 38, 0.15);
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-action.login {
    background: rgba(37, 99, 235, 0.16);
    color: #8fc1ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-record-id {
    background: rgba(31, 99, 146, 0.18);
    color: #bfe6ff;
    border-color: rgba(138, 210, 255, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern i {
    color: #90aeca;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern {
    background: rgba(127, 29, 29, 0.34);
    border: 1px solid rgba(248, 113, 113, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern i {
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-content strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-content p {
    color: #ffd0d0;
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2>
                    <i class="fas fa-history me-2"></i> 
                    Audit Log
                </h2>
                <p>Track system activities, user actions, and data changes with complete audit trail</p>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-database stat-icon"></i>
            <div class="stat-label">Total Activities</div>
            <div class="stat-number"><?php echo $totalLogs; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-calendar-day stat-icon"></i>
            <div class="stat-label">Today's Activity</div>
            <div class="stat-number" style="color: #10b981;"><?php echo $todayLogs; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-trash-alt stat-icon"></i>
            <div class="stat-label">Deleted Items</div>
            <div class="stat-number" style="color: #f59e0b;"><?php echo $deleteActions; ?></div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card" id="auditDirectory">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list me-2" style="color: var(--theme-primary);"></i>
                Activity Logs
            </h5>
            <span class="audit-directory-count">
                <?php echo $totalFilteredLogs; ?> activit<?php echo $totalFilteredLogs === 1 ? 'y' : 'ies'; ?>
            </span>
        </div>
        
        <!-- Filter Section -->
        <form method="GET" action="audit-log.php#auditDirectory" class="filter-section" id="auditFilterForm">
            <input type="hidden" name="per_page" value="<?php echo $auditPageSize; ?>">
            <div class="filter-group">
                <div class="filter-input">
                    <label class="visually-hidden" for="searchInput">Search audit logs</label>
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="searchInput" name="search" placeholder="Search action, table, or user" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                </div>
                <div class="filter-input">
                    <label class="visually-hidden" for="actionFilter">Filter by action</label>
                    <i class="fas fa-bolt" aria-hidden="true"></i>
                    <select id="actionFilter" name="action">
                        <option value="">All actions</option>
                        <?php while($action = $actions->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($action['action']); ?>" <?php echo $actionFilter === $action['action'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $action['action']))); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-input">
                    <label class="visually-hidden" for="userFilter">Filter by user</label>
                    <i class="fas fa-user" aria-hidden="true"></i>
                    <select id="userFilter" name="user_id">
                        <option value="">All users</option>
                        <?php while($filterUser = $users->fetch_assoc()): ?>
                        <option value="<?php echo (int) $filterUser['id']; ?>" <?php echo $userFilter === (int) $filterUser['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($filterUser['full_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-input">
                    <label class="visually-hidden" for="dateFrom">Date from</label>
                    <i class="fas fa-calendar-day" aria-hidden="true"></i>
                    <input type="date" id="dateFrom" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" aria-label="Date from">
                </div>
                <div class="filter-input">
                    <label class="visually-hidden" for="dateTo">Date to</label>
                    <i class="fas fa-calendar-check" aria-hidden="true"></i>
                    <input type="date" id="dateTo" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" aria-label="Date to">
                </div>
                <div class="audit-toolbar-actions">
                    <?php if ($search !== '' || $actionFilter !== '' || $userFilter > 0 || $dateFrom !== '' || $dateTo !== ''): ?>
                    <a class="audit-filter-clear" href="audit-log.php?per_page=<?php echo $auditPageSize; ?>#auditDirectory">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                        <span>Clear</span>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn-audit-toolbar" onclick="exportLogs()">
                        <i class="fas fa-download" aria-hidden="true"></i>
                        <span>Export</span>
                    </button>
                    <button type="button" class="btn-audit-toolbar is-secondary" onclick="printLogs()">
                        <i class="fas fa-print" aria-hidden="true"></i>
                        <span>Print</span>
                    </button>
                </div>
            </div>
            <button type="submit" class="visually-hidden">Apply filters</button>
        </form>
        
        <!-- Logs Table -->
        <div class="table-container">
            <?php if($logs->num_rows > 0): ?>
            <table class="audit-table" id="logsTable">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record ID</th>
                        <th>IP Address</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $displayRecordNumber = $totalFilteredLogs - $auditPageOffset; while($log = $logs->fetch_assoc()): ?>
                    <?php $canRestoreLog = $auditLogCanRestore($log); ?>
                    <tr>
                        <td style="white-space: nowrap;">
                            <i class="far fa-clock me-1 text-muted"></i>
                            <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                        </td>
                        <td>
                            <div class="user-info"><?php echo htmlspecialchars($log['full_name']); ?></div>
                            <div class="user-username">@<?php echo htmlspecialchars($log['username']); ?></div>
                        </td>
                        <td>
                            <span class="badge-action <?php 
                                echo strpos($log['action'], 'delete') !== false ? 'delete' : 
                                    (strpos($log['action'], 'create') !== false ? 'create' : 
                                    (strpos($log['action'], 'update') !== false ? 'update' : 'login'));
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                            </span>
                        </td>
                        <td>
                            <i class="fas fa-table me-1 text-muted"></i>
                            <?php echo ucfirst($log['table_name']); ?>
                        </td>
                        <td>
                            <span class="badge-record-id">#<?php echo $displayRecordNumber--; ?></span>
                        </td>
                        <td>
                            <code><?php echo $log['ip_address']; ?></code>
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <button class="btn-action-info" style="padding: 0.4rem 0.8rem;" onclick="viewDetails(<?php echo $log['id']; ?>)">
                                    <i class="fas fa-eye text-white"></i>
                                </button>
                                <?php if($canRestoreLog): ?>
                                <button type="button" class="btn-action-success" style="padding: 0.4rem 0.8rem;" onclick="restoreItem(<?php echo $log['id']; ?>, '<?php echo $log['table_name']; ?>', <?php echo $log['record_id']; ?>)">
                                    <i class="fas fa-undo text-white"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-clipboard-list"></i>
                <h4>No Audit Logs Found</h4>
                <p>Try adjusting your filters or check back later for system activity</p>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($totalFilteredLogs > 0): ?>
        <div class="audit-pagination" aria-label="Audit log pagination">
            <div class="audit-pagination__meta">
                <span class="audit-pagination__summary">
                    <?php echo $auditPageStart; ?>&ndash;<?php echo $auditPageEnd; ?> of <?php echo $totalFilteredLogs; ?>
                </span>
                <label class="visually-hidden" for="auditPageSize">Audit entries per page</label>
                <select class="audit-pagination__size" id="auditPageSize" aria-label="Audit entries per page">
                    <?php foreach ($allowedAuditPageSizes as $pageSizeOption): ?>
                    <option value="<?php echo $pageSizeOption; ?>" <?php echo $pageSizeOption === $auditPageSize ? 'selected' : ''; ?>>
                        <?php echo $pageSizeOption; ?> items
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <nav class="audit-pagination__controls" aria-label="Audit log pages">
                <?php if ($auditCurrentPage > 1): ?>
                <a class="audit-pagination__step" href="<?php echo htmlspecialchars($buildAuditPageUrl($auditCurrentPage - 1)); ?>">Previous</a>
                <?php else: ?>
                <span class="audit-pagination__step is-disabled" aria-disabled="true">Previous</span>
                <?php endif; ?>
                <span class="audit-pagination__pages">
                    <?php foreach ($auditPaginationPages as $pageNumber): ?>
                    <a
                        class="audit-pagination__page<?php echo $pageNumber === $auditCurrentPage ? ' is-current' : ''; ?>"
                        href="<?php echo htmlspecialchars($buildAuditPageUrl($pageNumber)); ?>"
                        <?php echo $pageNumber === $auditCurrentPage ? 'aria-current="page"' : ''; ?>
                    ><?php echo $pageNumber; ?></a>
                    <?php endforeach; ?>
                </span>
                <?php if ($auditCurrentPage < $auditTotalPages): ?>
                <a class="audit-pagination__step" href="<?php echo htmlspecialchars($buildAuditPageUrl($auditCurrentPage + 1)); ?>">Next</a>
                <?php else: ?>
                <span class="audit-pagination__step is-disabled" aria-disabled="true">Next</span>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Log Details Modal -->
<div class="modal fade modal-modern" id="logDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="log-modal-title-icon"><i class="fas fa-clipboard-list"></i></span>
                    Log Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="logDetailsContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading details...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const auditFilterForm = document.getElementById('auditFilterForm');
const auditSearchInput = document.getElementById('searchInput');
const auditActionFilter = document.getElementById('actionFilter');
const auditUserFilter = document.getElementById('userFilter');
const auditDateFrom = document.getElementById('dateFrom');
const auditDateTo = document.getElementById('dateTo');
const auditPageSize = document.getElementById('auditPageSize');
let auditSearchTimer = null;

function submitAuditFilters() {
    auditFilterForm?.requestSubmit();
}

auditSearchInput?.addEventListener('input', () => {
    window.clearTimeout(auditSearchTimer);
    auditSearchTimer = window.setTimeout(submitAuditFilters, 450);
});

[auditActionFilter, auditUserFilter, auditDateFrom, auditDateTo].forEach((control) => {
    control?.addEventListener('change', submitAuditFilters);
});

auditPageSize?.addEventListener('change', function() {
    const params = new URLSearchParams(window.location.search);
    params.set('per_page', this.value);
    params.delete('page');
    window.location.href = 'audit-log.php?' + params.toString() + '#auditDirectory';
});

function viewDetails(logId) {
    $.ajax({
        url: '../../api/get-audit-details.php',
        method: 'GET',
        data: { id: logId },
        success: function(response) {
            const log = typeof response === 'string' ? JSON.parse(response) : response;
            if (log.error) {
                $('#logDetailsContent').html(`<div class="text-center py-4"><i class="fas fa-exclamation-circle fa-3x text-danger mb-3"></i><p>${log.error}</p></div>`);
                new bootstrap.Modal(document.getElementById('logDetailsModal')).show();
                return;
            }
            const hasChangeData = Boolean(log.old_data || log.new_data);
            let html = `
                <div class="log-details-layout${hasChangeData ? '' : ' is-single'}">
                    <section class="log-detail-panel">
                        <h6 class="log-detail-panel__title">
                            <span class="log-detail-panel__title-icon"><i class="fas fa-info"></i></span>
                            Basic Information
                        </h6>
                        <dl class="log-detail-grid">
                            <div class="log-detail-item">
                                <dt>Date & Time</dt>
                                <dd>${new Date(log.created_at).toLocaleString()}</dd>
                            </div>
                            <div class="log-detail-item">
                                <dt>User</dt>
                                <dd>${log.full_name} (@${log.username})</dd>
                            </div>
                            <div class="log-detail-item">
                                <dt>Action</dt>
                                <dd><span class="badge-action ${log.action.includes('delete') ? 'delete' : (log.action.includes('create') ? 'create' : (log.action.includes('update') ? 'update' : 'login'))}">${log.action}</span></dd>
                            </div>
                            <div class="log-detail-item">
                                <dt>Table</dt>
                                <dd>${log.table_name}</dd>
                            </div>
                            <div class="log-detail-item">
                                <dt>Record ID</dt>
                                <dd>#${log.record_id}</dd>
                            </div>
                            <div class="log-detail-item">
                                <dt>IP Address</dt>
                                <dd><code>${log.ip_address}</code></dd>
                            </div>
                        </dl>
                    </section>
                    ${hasChangeData ? `
                        <section class="log-detail-panel">
                            <h6 class="log-detail-panel__title">
                                <span class="log-detail-panel__title-icon"><i class="fas fa-code-compare"></i></span>
                                Record Changes
                            </h6>
                            <div class="log-change-list">
                                ${log.old_data ? `
                                    <div class="log-change-preview is-before">
                                        <div class="log-change-preview__header"><i class="fas fa-arrow-rotate-left"></i> Before</div>
                                        <pre><code>${formatJsonData(log.old_data)}</code></pre>
                                    </div>
                                ` : ''}
                                ${log.new_data ? `
                                    <div class="log-change-preview is-after">
                                        <div class="log-change-preview__header"><i class="fas fa-check"></i> After</div>
                                        <pre><code>${formatJsonData(log.new_data)}</code></pre>
                                    </div>
                                ` : ''}
                            </div>
                        </section>
                    ` : ''}
                </div>
            `;
            $('#logDetailsContent').html(html);
            new bootstrap.Modal(document.getElementById('logDetailsModal')).show();
        },
        error: function() {
            $('#logDetailsContent').html('<div class="text-center py-4"><i class="fas fa-exclamation-circle fa-3x text-danger mb-3"></i><p>Error loading log details</p></div>');
        }
    });
}

function formatJsonData(value) {
    try {
        return JSON.stringify(typeof value === 'string' ? JSON.parse(value) : value, null, 2);
    } catch (error) {
        return value || '';
    }
}

async function restoreItem(logId, tableName, recordId) {
    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm({
            title: 'Restore Deleted Item',
            titleIcon: 'fas fa-clock-rotate-left',
            subject: 'Restore this deleted item?',
            message: 'The item and its associated data will be recovered.',
            icon: 'fas fa-trash-arrow-up fa-lg',
            confirmIcon: 'fas fa-rotate-left',
            confirmText: 'Restore Item'
        })
        : window.confirm('Restore this deleted item?');

    if (!confirmed) {
        return;
    }

    $.ajax({
        url: '../../api/restore-item.php',
        method: 'POST',
        dataType: 'json',
        data: {
            log_id: logId,
            table_name: tableName,
            record_id: recordId
        },
        success: function(result) {
            if(result.success) {
                window.appToast?.('Item restored', 'The deleted item was restored successfully.');
                setTimeout(() => location.reload(), 1500);
            } else {
                window.appToast?.('Restore failed', result.error || 'The item could not be restored.', 'error');
            }
        }
    }).fail(function(xhr) {
        let message = 'Restore failed';
        try {
            message = JSON.parse(xhr.responseText).error || message;
        } catch (error) {}
        window.appToast?.('Restore failed', message, 'error');
    });
}

function exportLogs() {
    const table = document.getElementById('logsTable');
    if (!table) {
        window.appToast?.('Nothing to export', 'No audit entries match the current filters.', 'info');
        return;
    }
    let csv = [];
    
    // Get headers
    let headers = [];
    table.querySelectorAll('thead th').forEach((th, index) => {
        if (index < 6) headers.push(th.textContent.trim());
    });
    csv.push(headers.join(','));
    
    // Get rows
    table.querySelectorAll('tbody tr').forEach(row => {
        let rowData = [];
        row.querySelectorAll('td').forEach((td, index) => {
            if(index < 6) {
                let text = td.textContent.trim();
                text = text.replace(/@/g, '').replace(/#/g, '');
                rowData.push('"' + text.replace(/"/g, '""') + '"');
            }
        });
        csv.push(rowData.join(','));
    });
    
    // Download
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `audit_log_${new Date().toISOString().slice(0,19)}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

function printLogs() {
    const table = document.getElementById('logsTable');
    if (!table) {
        window.appToast?.('Nothing to print', 'No audit entries match the current filters.', 'info');
        return;
    }
    const printContents = table.outerHTML;
    const originalContents = document.body.innerHTML;
    document.body.innerHTML = `
        <div class="container mt-4">
            <h2>Audit Log Report</h2>
            <p>Generated on: ${new Date().toLocaleString()}</p>
            ${printContents}
        </div>
    `;
    window.print();
    document.body.innerHTML = originalContents;
    location.reload();
}

</script>

<?php include '../../includes/footer.php'; ?>
