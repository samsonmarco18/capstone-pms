<?php
require_once 'includes/auth.php';
requireLogin();
$user = getCurrentUser();
$conn = getConnection();
$projectDetailsPanelAsset = __DIR__ . '/js/project-details-panel.js';
$projectDetailsPanelVersion = is_file($projectDetailsPanelAsset) ? (int) filemtime($projectDetailsPanelAsset) : time();
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>
<?php include 'includes/navbar.php'; ?>

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
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
}

body {
    background: linear-gradient(180deg, #f7fbff 0%, #eef5fb 100%);
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

/* Folder Tree Card */
.folder-tree-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 20px;
}

.folder-tree-card .card-header {
    padding: 1rem 1.25rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.folder-tree-card .card-header h6 {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--gray-700);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.folder-tree-card .card-header h6 i {
    color: var(--theme-primary);
}

.folder-tree-container {
    background: var(--gray-50);
    height: 70vh;
    overflow-y: auto;
    padding: 0.75rem;
}

/* Folder Items */
.folder-item {
    cursor: pointer;
    transition: all 0.2s;
    border-radius: 8px;
    padding: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.folder-item:hover {
    background: white;
    box-shadow: var(--shadow-sm);
}

.folder-icon {
    width: 28px;
    color: var(--theme-primary);
}

.folder-name {
    flex: 1;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--gray-700);
}

.folder-expand {
    cursor: pointer;
    color: var(--gray-400);
    transition: all 0.2s;
}

.folder-expand:hover {
    color: var(--theme-primary);
}

.folder-children {
    margin-left: 1.5rem;
    border-left: 2px solid var(--gray-200);
}

/* Breadcrumb */
.breadcrumb-modern {
    background: white;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--gray-200);
}

.breadcrumb-modern .breadcrumb {
    margin: 0;
    padding: 0;
    background: transparent;
}

.breadcrumb-modern .breadcrumb-item a {
    color: var(--theme-primary);
    text-decoration: none;
}

.breadcrumb-modern .breadcrumb-item a:hover {
    text-decoration: underline;
}

.breadcrumb-modern .breadcrumb-item.active {
    color: var(--gray-600);
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

#folderBrowserCard {
    overflow: visible;
}

#folderBrowserCard .table-container {
    border-radius: 0 0 20px 20px;
}

.folder-actions-dropdown .dropdown-menu {
    z-index: 1070;
    min-width: 150px;
}

.main-card .card-header {
    padding: 1rem 1.25rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.main-card .card-header h6 {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--gray-700);
    margin: 0;
}

.project-view-tabs {
    display: flex;
    gap: 0.5rem;
    padding: 0.75rem 0 1rem;
    flex-wrap: wrap;
}

.project-view-tab {
    appearance: none;
    border: 1px solid var(--gray-200);
    background: var(--gray-100);
    color: var(--gray-600);
    border-radius: 999px;
    padding: 0.65rem 1rem;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s ease;
}

.project-view-tab:hover {
    color: var(--theme-primary);
    border-color: rgba(39, 119, 236, 0.25);
    background: #fff;
}

.project-view-tab.active {
    border-color: transparent;
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: #fff;
    box-shadow: 0 10px 24px rgba(39, 119, 236, 0.18);
}

/* Buttons */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-outline-modern {
    background: transparent;
    border: 1px solid var(--gray-300);
    color: var(--gray-700);
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-outline-modern:hover {
    background: var(--gray-50);
    border-color: var(--theme-primary);
    color: var(--theme-primary);
}

/* Table */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--gray-50);
}

.data-table th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-500);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
    font-size: 0.85rem;
}

.data-table tr:hover {
    background: var(--gray-50);
}

.folder-row {
    cursor: pointer;
}

.file-link {
    color: var(--theme-primary);
    text-decoration: none;
}

.file-link:hover {
    text-decoration: underline;
}

/* Badges */
.badge-folder {
    background: #fef3c7;
    color: #d97706;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
}

.badge-file {
    background: var(--gray-200);
    color: var(--gray-600);
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
}

/* Project Details Panel */
.project-details-panel {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    color: var(--gray-800);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-md);
}

.project-context-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.project-context-header h5,
.project-context-section h6 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.project-context-header p {
    color: var(--gray-600);
    margin: 0.35rem 0 0;
    font-size: 0.85rem;
}

.project-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.project-detail-item {
    background: var(--gray-50);
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid var(--gray-200);
}

.project-detail-item label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-500);
    display: block;
    margin-bottom: 0.5rem;
}

.project-detail-item .value {
    font-size: 0.95rem;
    color: var(--gray-800);
    word-wrap: break-word;
}

.project-detail-full {
    grid-column: 1 / -1;
}

.project-detail-full .value {
    background: white;
    padding: 0.75rem;
    border-radius: 6px;
    max-height: 150px;
    overflow-y: auto;
    font-size: 0.85rem;
    line-height: 1.5;
}

.status-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--theme-dark);
    background: rgba(31, 99, 146, 0.1);
    border: 1px solid rgba(31, 99, 146, 0.18);
    white-space: nowrap;
}

.project-context-section {
    border-top: 1px solid var(--gray-200);
    margin-top: 1.25rem;
    padding-top: 1.25rem;
}

.project-context-section h6 {
    font-size: 0.95rem;
    margin-bottom: 1rem;
}

.project-context-text {
    white-space: pre-line;
}

.project-context-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.project-context-metric {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 0.75rem;
}

.project-context-metric span,
.project-context-list label {
    display: block;
    color: var(--gray-500);
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 0.35rem;
}

.project-context-metric strong {
    color: var(--gray-900);
    font-size: 1.1rem;
}

.project-context-list {
    margin-top: 1rem;
}

.project-context-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.project-context-pills span,
.project-context-row,
.project-context-empty {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    color: var(--gray-700);
    font-size: 0.85rem;
}

.project-context-pills span {
    padding: 0.4rem 0.65rem;
}

.project-context-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.65rem 0.75rem;
    margin-bottom: 0.5rem;
}

.project-context-row small {
    color: var(--gray-500);
    text-align: right;
}

.project-context-empty {
    padding: 0.75rem;
}

.project-info-card {
    display: none;
}

.project-info-card.is-visible {
    display: block;
}

.project-info-body {
    max-height: 65vh;
    overflow-y: auto;
    padding: 1.25rem;
}

.project-info-body .project-details-panel {
    margin-bottom: 0;
}

.project-info-card .project-info-body {
    max-height: 65vh;
    overflow-y: auto;
    padding: 1.25rem;
}

.project-info-card.is-visible.d-none {
    display: none !important;
}

.project-info-card.is-visible {
    display: block;
}

.project-view-tab-target {
    display: none;
}

.project-view-tab-target.is-active {
    display: block;
}

.project-details-summary {
    margin-bottom: 1rem;
}

.project-gantt-chart {
    display: grid;
    gap: 0.75rem;
    margin-top: 1rem;
}

.project-gantt-range {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--gray-500);
}

.project-gantt-row {
    display: grid;
    grid-template-columns: minmax(160px, 220px) 1fr;
    gap: 0.75rem;
    align-items: center;
}

.project-gantt-label {
    display: grid;
    gap: 0.15rem;
    font-size: 0.8rem;
}

.project-gantt-label small {
    color: var(--gray-500);
}

.project-gantt-track {
    position: relative;
    height: 28px;
    background: var(--gray-100);
    border-radius: 999px;
    overflow: hidden;
}

.project-gantt-bar {
    position: absolute;
    top: 4px;
    bottom: 4px;
    border-radius: 999px;
}

.project-gantt-bar-phase {
    background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
}

.project-gantt-bar-task {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
}

.folder-item.is-active {
    background: white;
    box-shadow: var(--shadow-sm);
    border-left: 3px solid var(--theme-primary);
}

.folder-client-label {
    display: block;
    font-size: 0.7rem;
    color: var(--gray-500);
    margin-top: 0.1rem;
}

/* Modal */
.modal-modern .modal-content {
    border-radius: 20px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    padding: 1rem 1.25rem;
    border: none;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

/* Table Container */
.table-container {
    height: 60vh;
    overflow-y: auto;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .page-header-modern h2 {
        font-size: 1.5rem;
    }
    
    .main-card .card-header {
        flex-direction: column;
        align-items: stretch;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-tree-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-details-panel,
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-tree-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-detail-full .value {
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-tree-container,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-details-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-detail-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-metric,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-empty,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern {
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-tree-card .card-header h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card .card-header h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-section h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-detail-item .value {
    color: #d8e6f3 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-header p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-metric span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-list label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-row small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern .breadcrumb-item.active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-client-label {
    color: #90aeca !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-detail-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-metric,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-context-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-item.is-active {
    border-color: rgba(138, 210, 255, 0.12) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-item:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-item.is-active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr:hover {
    background: rgba(31, 99, 146, 0.12) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-expand,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .file-link,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern .breadcrumb-item a {
    color: #bfe6ff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-folder {
    background: rgba(217, 119, 6, 0.16);
    color: #ffd58a;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .badge-file {
    background: rgba(144, 174, 202, 0.12);
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .status-badge {
    background: rgba(31, 99, 146, 0.16);
    color: #bfe6ff;
    border-color: rgba(138, 210, 255, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-item.is-active .folder-icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-tree-card .card-header h6 i {
    color: #8fd3ff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .folder-children {
    border-left-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .btn-close {
    filter: brightness(0) invert(1);
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-folder-tree me-2"></i> 
                Project Folders & Files
            </h2>
            <p>Browse shared project folders and role-specific project details in one place</p>
        </div>
    </div>

    <div class="row g-4">
        <!-- Folder Tree Sidebar -->
        <div class="col-lg-4">
            <div class="folder-tree-card">
                <div class="card-header">
                    <h6>
                        <i class="fas fa-sitemap"></i>
                        Folder Structure
                    </h6>
                </div>
                <div class="folder-tree-container" id="folderTree">
                    <!-- Tree loaded via AJAX -->
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary spinner-border-sm" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="text-muted small mt-2">Loading folders...</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Breadcrumbs -->
            <div class="breadcrumb-modern" id="folderBreadcrumbs">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item active">Shared Projects</li>
                    </ol>
                </nav>
            </div>

            <div class="project-view-tabs" role="tablist" aria-label="Folder views">
                <button type="button" class="project-view-tab" id="projectInfoTabButton" data-view-target="project" aria-selected="false">
                    <i class="fas fa-info-circle me-1"></i>Project Info
                </button>
                <button type="button" class="project-view-tab active" id="sharedTabButton" data-view-target="shared" aria-selected="true">
                    <i class="fas fa-folder-open me-1"></i>Shared
                </button>
            </div>

            <!-- Project Information -->
            <div class="main-card project-info-card mb-3" id="projectDetailsCard">
                <div class="card-header">
                    <h6><i class="fas fa-info-circle me-2"></i>Project Information</h6>
                </div>
                <div class="project-info-body" id="projectDetailsPanel">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-mouse-pointer fa-2x mb-2"></i>
                        <p class="mb-0">Select a shared project below to view all role submissions.</p>
                    </div>
                </div>
            </div>

            <!-- Files and Folders Card -->
            <div class="main-card" id="folderBrowserCard">
                <div class="card-header">
                    <h6 id="currentFolderTitle">
                        <i class="fas fa-folder-open me-2"></i>Shared Projects
                    </h6>
                    <div class="d-flex gap-2">
                        <button class="btn-primary-modern" onclick="createFolderInCurrent()">
                            <i class="fas fa-folder-plus"></i> New Folder
                        </button>
                        <button class="btn-primary-modern" onclick="uploadToCurrentFolder()">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <div class="dropdown folder-actions-dropdown">
                            <button class="btn-outline-modern dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="exportCurrentFolder(); return false;"><i class="fas fa-download me-2"></i>Export ZIP</a></li>
                                <li><a class="dropdown-item" href="#" onclick="refreshFolder(); return false;"><i class="fas fa-sync-alt me-2"></i>Refresh</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 40px"><input type="checkbox" id="selectAllFiles" class="form-check-input"></th>
                                <th>Name</th>
                                <th style="width: 80px">Size</th>
                                <th style="width: 100px">Modified</th>
                                <th style="width: 120px">Owner</th>
                                <th style="width: 80px">Type</th>
                                <th style="width: 100px">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fileTableBody">
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="fas fa-folder-open fa-2x mb-2"></i>
                                    <p>Click a shared project to open it directly</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Actions Modal -->
<div class="modal fade modal-modern" id="bulkActionsModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-tasks me-2"></i>Bulk Actions</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Selected: <span id="selectedCount">0</span> items</label>
                </div>
                <button class="btn-primary-modern w-100 mb-2" onclick="bulkDelete()" style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);">
                    <i class="fas fa-trash me-2"></i>Delete Selected
                </button>
                <button class="btn-primary-modern w-100 mb-2" onclick="bulkDownload()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    <i class="fas fa-download me-2"></i>Download Selected
                </button>
                <button class="btn-primary-modern w-100" onclick="bulkMove()">
                    <i class="fas fa-exchange-alt me-2"></i>Move to Folder
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>js/project-details-panel.js?v=<?php echo $projectDetailsPanelVersion; ?>"></script>
<script>
// Global variables
let currentFolderId = 0;
let selectedFiles = [];
let folderStructure = {};
let currentBreadcrumb = [];
let sharedFolderRoots = [];
const SHARED_FOLDERS_ID = 'shared-folders';
const CURRENT_USER_ROLE = '<?php echo htmlspecialchars($user['role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>';

// Initialize folders page
$(document).ready(function() {
    loadSharedFolders();
    initBulkActions();
    initEntryEvents();
    initViewTabs();

    const params = new URLSearchParams(window.location.search);
    const folderParam = parseInt(params.get('folder_id') || '0', 10);
    if (folderParam > 0) {
        openFolderById(folderParam);
    }
});

// Load shared projects list
function loadRootFolders() {
    loadSharedFolders();
}

function setActiveViewTab(view) {
    const showProjectInfo = view === 'project';

    $('#projectInfoTabButton').toggleClass('active', showProjectInfo)
        .attr('aria-selected', showProjectInfo ? 'true' : 'false');
    $('#sharedTabButton').toggleClass('active', !showProjectInfo)
        .attr('aria-selected', showProjectInfo ? 'false' : 'true');

    if (showProjectInfo) {
        $('#projectDetailsCard').removeClass('d-none');
        if (!$('#projectDetailsCard').hasClass('is-visible')) {
            $('#projectDetailsCard').addClass('is-visible');
        }
        $('#folderBrowserCard').addClass('d-none');
    } else {
        $('#folderBrowserCard').removeClass('d-none');
        $('#projectDetailsCard').addClass('d-none');
    }
}

function initViewTabs() {
    $(document).off('click.folderViewTabs').on('click.folderViewTabs', '.project-view-tab', function() {
        setActiveViewTab($(this).data('view-target'));
    });
    setActiveViewTab('shared');
}

function hideProjectPanel() {
    $('#projectDetailsCard').removeClass('is-visible');
    $('#projectDetailsPanel').html(
        '<div class="text-center text-muted py-4"><i class="fas fa-mouse-pointer fa-2x mb-2"></i><p class="mb-0">Select a shared project below to view all role submissions.</p></div>'
    );
}

function showFolderBrowser() {
    $('#folderBrowserCard').removeClass('d-none');
}

function hideFolderBrowser() {
    $('#folderBrowserCard').addClass('d-none');
}

function showProjectPanel(projectContext, folderId, projectId) {
    const renderPanel = function(context) {
        if (!context) {
            hideProjectPanel();
            return;
        }

        if (typeof renderProjectDetailsPanel === 'function') {
            renderProjectDetailsPanel(context, { showSharedFolderFiles: false });
        } else {
            $('#projectDetailsPanel').html('<div class="alert alert-warning mb-0">Unable to load project details view.</div>');
        }

        $('#projectDetailsCard').addClass('is-visible');
    };

    if (projectContext) {
        renderPanel(projectContext);
        return;
    }

    const query = projectId > 0
        ? `project_id=${projectId}`
        : `folder_id=${folderId}`;

    $.ajax({
        url: `<?php echo BASE_URL; ?>api/folders/get-project-context.php?${query}`,
        dataType: 'json',
        success: function(data) {
            if (data && data.error) {
                $('#projectDetailsPanel').html(`<div class="alert alert-warning mb-0">${escapeHtml(data.error)}</div>`);
                $('#projectDetailsCard').addClass('is-visible');
                return;
            }

            renderPanel(data.project_context || data.project_details || null);
        },
        error: function(xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.error
                ? xhr.responseJSON.error
                : 'Unable to load project details right now.';
            $('#projectDetailsPanel').html(`<div class="alert alert-warning mb-0">${escapeHtml(message)}</div>`);
            $('#projectDetailsCard').addClass('is-visible');
        }
    });
}

function loadSharedFolders() {
    currentFolderId = 0;
    currentBreadcrumb = [{
        id: SHARED_FOLDERS_ID,
        name: 'Shared Projects',
        virtual: true
    }];
    hideProjectPanel();
    showFolderBrowser();
    setActiveViewTab('shared');
    $('#currentFolderTitle').html('<i class="fas fa-folder-open me-2"></i>Shared Projects');

    $.ajax({
        url: `<?php echo BASE_URL; ?>api/folders/get-tree.php?folder_id=0&role=${encodeURIComponent(CURRENT_USER_ROLE)}`,
        dataType: 'json',
        success: function(data) {
            if (data && data.error) {
                showFolderLoadError(data.error);
                return;
            }

            sharedFolderRoots = data.folders || [];
            renderBreadcrumbs(currentBreadcrumb);
            renderFullSharedFolderTree();
            renderProjectEntries(sharedFolderRoots, []);

            const params = new URLSearchParams(window.location.search);
            if (!params.get('folder_id') && sharedFolderRoots.length === 1) {
                openFolderById(sharedFolderRoots[0].id);
            }
        },
        error: function(xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.error
                ? xhr.responseJSON.error
                : 'Failed to load shared projects.';
            showFolderLoadError(message);
        }
    });
}

function openFolderById(folderId) {
    if (String(folderId) === SHARED_FOLDERS_ID) {
        loadSharedFolders();
        return;
    }

    const numericFolderId = parseInt(folderId, 10);
    if (numericFolderId > 0) {
        loadFolderContents(numericFolderId);
    }
}

// Load folder tree
function renderFolderTree(folders, parentId = 0, renderToDom = true) {
    folderStructure[parentId] = folders;
    
    let html = '';
    folders.forEach(folder => {
        const hasChildren = Boolean(folder.children && folder.children.length);
        const childrenDisplay = folder.expanded ? 'block' : 'none';
        const expandIcon = folder.expanded ? 'fa-chevron-down' : 'fa-chevron-right';
        const icon = folder.folder_type === 'shared_root' ? 'fa-folder-tree' :
                    folder.folder_type === 'client' ? 'fa-building' :
                    folder.folder_type === 'project' ? 'fa-diagram-project' :
                    folder.folder_type === 'role' ? 'fa-users' :
                    folder.folder_type === 'user' ? 'fa-user' : 'fa-folder';
        const clientLabel = folder.client_name && folder.folder_type === 'project'
            ? `<span class="folder-client-label">${escapeHtml(folder.client_name)}</span>`
            : '';
        
        html += `
            <div class="folder-item${Number(folder.id) === Number(currentFolderId) ? ' is-active' : ''}" data-folder-id="${escapeHtml(String(folder.id))}">
                <i class="fas ${icon} folder-icon"></i>
                <span class="folder-name">${escapeHtml(folder.name)}${clientLabel}</span>
                ${hasChildren ? `<i class="fas ${expandIcon} folder-expand"></i>` : '<span class="folder-expand"></span>'}
            </div>
        `;
        
        if (hasChildren) {
            html += `<div class="folder-children" style="display: ${childrenDisplay};">${renderFolderTree(folder.children, folder.id, false)}</div>`;
        }
    });
    
    if (renderToDom) {
        $('#folderTree').html(html || '<div class="text-center text-muted py-4"><i class="fas fa-folder-open fa-2x mb-2"></i><p>No folders available</p></div>');
        initTreeEvents();
    }
    return html;
}

function renderFullSharedFolderTree() {
    renderFolderTree([{
        id: SHARED_FOLDERS_ID,
        name: 'Shared Projects',
        folder_type: 'shared_root',
        expanded: true,
        children: sharedFolderRoots.map(folder => ({
            id: folder.id,
            name: folder.name,
            folder_type: folder.folder_type,
            client_name: folder.client_name,
            owner_name: folder.owner_name,
            project_id: folder.project_id,
            role: folder.role,
            updated_at: folder.updated_at,
            created_at: folder.created_at,
            expanded: Number(folder.id) === Number(currentFolderId),
            children: Number(folder.id) === Number(currentFolderId) ? (folderStructure[folder.id] || []) : []
        }))
    }]);
}

function renderScopedProjectTree(folderBreadcrumb, childFolders) {
    const projectFolder = (folderBreadcrumb || []).slice().reverse().find(folder => folder.folder_type === 'project')
        || (folderBreadcrumb || [])[folderBreadcrumb.length - 1];

    if (!projectFolder) {
        renderFullSharedFolderTree();
        return;
    }

    renderFolderTree([{
        id: SHARED_FOLDERS_ID,
        name: 'Shared Projects',
        folder_type: 'shared_root',
        expanded: true,
        children: sharedFolderRoots.map(folder => ({
            id: folder.id,
            name: folder.name,
            folder_type: folder.folder_type,
            client_name: folder.client_name,
            owner_name: folder.owner_name,
            project_id: folder.project_id,
            role: folder.role,
            updated_at: folder.updated_at,
            created_at: folder.created_at,
            expanded: Number(folder.id) === Number(projectFolder.id),
            children: Number(folder.id) === Number(projectFolder.id) ? (childFolders || []) : []
        }))
    }]);
}

function initTreeEvents() {
    $('.folder-item').off('click').on('click', function(e) {
        e.stopPropagation();
        const folderId = $(this).data('folder-id');
        const children = $(this).next('.folder-children');

        if (children.length) {
            children.slideToggle();
            $(this).find('.folder-expand').toggleClass('fa-chevron-down fa-chevron-right');
        }

        if (String(folderId) === SHARED_FOLDERS_ID) {
            return;
        }

        openFolderById(folderId);
    });
}

// Load folder contents
function loadFolderContents(folderId) {
    showLoading();
    currentFolderId = folderId;
    
    $.ajax({
        url: `<?php echo BASE_URL; ?>api/folders/get-files.php?folder_id=${folderId}`,
        dataType: 'json',
        success: function(data) {
            hideLoading();
            if (data && data.error) {
                renderProjectEntries([], []);
                $('#fileTableBody').html(`<tr><td colspan="7" class="text-center text-muted py-5">${escapeHtml(data.error)}</td></tr>`);
                return;
            }

            const folderBreadcrumb = Array.isArray(data.breadcrumb)
                ? data.breadcrumb.filter(folder => folder.folder_type !== 'client')
                : [];
            currentBreadcrumb = [{
                id: SHARED_FOLDERS_ID,
                name: 'Shared Projects',
                virtual: true
            }].concat(folderBreadcrumb);
            renderBreadcrumbs(currentBreadcrumb);

            const folder = data.current_folder || findFolderById(folderId);
            $('#currentFolderTitle').html(`<i class="fas fa-folder-open me-2"></i>${folder ? escapeHtml(folder.name) : 'Files'}`);

            const resolvedProjectId = Number(data.project_id || (folder ? folder.project_id : 0) || 0);
            const childFolders = resolvedProjectId > 0
                ? (data.folders || []).filter(child => child.folder_type !== 'project')
                : (data.folders || []);
            setActiveViewTab('shared');
            showFolderBrowser();
            renderProjectEntries(childFolders, data.files || []);

            if (resolvedProjectId > 0 && folder) {
                folderStructure[folder.id] = childFolders;
                renderScopedProjectTree(folderBreadcrumb, childFolders);
            } else {
                renderFullSharedFolderTree();
            }
            
            const projectContext = data.project_context || data.project_details;
            showProjectPanel(projectContext, folderId, resolvedProjectId);
        },
        error: function(xhr) {
            hideLoading();
            const message = xhr.responseJSON && xhr.responseJSON.error
                ? xhr.responseJSON.error
                : 'Failed to load folder contents.';
            $('#fileTableBody').html(`<tr><td colspan="7" class="text-center text-muted py-5">${escapeHtml(message)}</td></tr>`);
        }
    });
}

function showFolderLoadError(message) {
    $('#folderTree').html(`<div class="text-center text-muted py-4">${escapeHtml(message)}</div>`);
    renderBreadcrumbs(currentBreadcrumb);
    $('#fileTableBody').html(`<tr><td colspan="7" class="text-center text-muted py-5">${escapeHtml(message)}</td></tr>`);
}

function renderProjectEntries(folders, files) {
    const tbody = $('#fileTableBody');
    tbody.empty();

    if (folders.length === 0 && files.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-folder-open fa-2x mb-2"></i><p>No files or folders here yet</p></td></tr>');
        return;
    }

    folders.forEach(folder => {
        const folderId = escapeHtml(String(folder.id));
        const clientLabel = folder.client_name
            ? `<small class="folder-client-label">${escapeHtml(folder.client_name)}</small>`
            : '';
        const projectIdAttr = folder.project_id ? ` data-project-id="${escapeHtml(String(folder.project_id))}"` : '';
        tbody.append(`
            <tr class="folder-row" data-folder-id="${folderId}"${projectIdAttr}>
                <td></td>
                <td>
                    <div class="d-flex align-items-center">
                        <i class="fas fa-folder text-warning me-2" style="font-size: 18px;"></i>
                        <div>
                            <a href="#" class="file-link folder-open-link" data-folder-id="${folderId}">${escapeHtml(folder.name)}</a>
                            ${clientLabel}
                        </div>
                    </div>
                </td>
                <td>—</td>
                <td>${folder.updated_at ? new Date(folder.updated_at).toLocaleDateString() : '-'}</td>
                <td>${escapeHtml(folder.owner_name || 'System')}</td>
                <td><span class="badge-folder">${folder.folder_type === 'project' ? 'Project' : 'Folder'}</span></td>
                <td>
                    <button class="btn-outline-modern btn-sm folder-open-button" data-folder-id="${folderId}" style="padding: 0.2rem 0.5rem;">
                        <i class="fas fa-folder-open"></i>
                    </button>
                </td>
            </tr>
        `);
    });

    files.forEach(file => {
        const icon = getFileIcon(file.file_type);
        const size = formatFileSize(file.file_size);
        const modified = new Date(file.updated_at).toLocaleDateString();

        tbody.append(`
            <tr class="file-row" data-file-id="${file.id}">
                <td><input type="checkbox" class="file-checkbox form-check-input"></td>
                <td>
                    <div class="d-flex align-items-center">
                        <i class="${icon} me-2" style="font-size: 18px;"></i>
                        <a href="<?php echo BASE_URL; ?>${file.file_path}" target="_blank" class="file-link">${escapeHtml(file.original_file_name)}</a>
                    </div>
                </td>
                <td>${size}</td>
                <td>${modified}</td>
                <td>${escapeHtml(file.owner_name || 'Unknown')}</td>
                <td><span class="badge-file">${file.file_type || 'File'}</span></td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <a href="<?php echo BASE_URL; ?>${file.file_path}" class="btn-outline-modern" download title="Download" style="padding: 0.2rem 0.5rem;">
                            <i class="fas fa-download"></i>
                        </a>
                        <button class="btn-outline-modern" onclick="deleteFile(${file.id})" title="Delete" style="padding: 0.2rem 0.5rem;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `);
    });

    selectedFiles = [];
    updateBulkSelection();
}

function renderEntries(folders, files) {
    renderProjectEntries(folders, files);
}

function initEntryEvents() {
    $(document).off('click', '.folder-open-link, .folder-open-button, .folder-row').on('click', '.folder-open-link, .folder-open-button, .folder-row', function(e) {
        if ($(e.target).closest('input, button, a[download]').length) {
            return;
        }

        e.preventDefault();
        const row = $(this).closest('.folder-row').length ? $(this).closest('.folder-row') : $(this);
        openFolderById(row.data('folder-id'));
    });
}

function renderBreadcrumbs(path) {
    const container = $('#folderBreadcrumbs nav ol');
    let html = '<li class="breadcrumb-item"><a href="#" onclick="loadSharedFolders(); return false;"><i class="fas fa-home me-1"></i>Shared Projects</a></li>';

    if (!Array.isArray(path) || path.length === 0) {
        html = '<li class="breadcrumb-item active">Shared Projects</li>';
        container.html(html);
        return;
    }

    path.forEach((folder, index) => {
        const isLast = index === path.length - 1;
        if (isLast) {
            html += `<li class="breadcrumb-item active">${escapeHtml(folder.name)}</li>`;
        } else if (folder.virtual) {
            html += `<li class="breadcrumb-item"><a href="#" onclick="openFolderById('${SHARED_FOLDERS_ID}'); return false;">${escapeHtml(folder.name)}</a></li>`;
        } else {
            html += `<li class="breadcrumb-item"><a href="#" onclick="loadFolderContents(${folder.id}); return false;">${escapeHtml(folder.name)}</a></li>`;
        }
    });

    container.html(html);
}

function findFolderById(id) {
    for (let parentId in folderStructure) {
        const children = folderStructure[parentId] || [];
        const folder = children.find(f => f.id == id);
        if (folder) return folder;
    }
    return null;
}

function getFileIcon(type) {
    if (!type) return 'fas fa-file text-muted';
    
    const icons = {
        'pdf': 'fas fa-file-pdf text-danger',
        'doc': 'fas fa-file-word text-primary',
        'xls': 'fas fa-file-excel text-success',
        'jpg': 'fas fa-file-image text-info',
        'png': 'fas fa-file-image text-info',
        'zip': 'fas fa-file-archive text-warning',
        'js': 'fas fa-file-code text-secondary'
    };

    return icons[type.toLowerCase()] || 'fas fa-file text-muted';
}

function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function initBulkActions() {
    $('#selectAllFiles').on('change', function() {
        $('.file-checkbox').prop('checked', this.checked);
        updateBulkSelection();
    });
    
    $(document).on('change', '.file-checkbox', updateBulkSelection);
}

function updateBulkSelection() {
    selectedFiles = $('.file-checkbox:checked').map(function() {
        return $(this).closest('tr').data('file-id');
    }).get();
    
    if (selectedFiles.length > 0) {
        $('#bulkActionsModal').modal('show');
    }
}

function showFolderNotice(title, message, type = 'success') {
    if (typeof window.appToast === 'function') {
        window.appToast(title, message, type);
        return;
    }

    if (typeof window.showToast === 'function') {
        window.showToast(title, message, type);
        return;
    }

    console[type === 'error' ? 'error' : 'log'](`${title}: ${message}`);
}

async function bulkDelete() {
    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm({
            title: 'Delete Files',
            subject: `Delete ${selectedFiles.length} file(s)?`,
            message: 'This cannot be undone.',
            icon: 'fas fa-trash-alt fa-lg',
            confirmIcon: 'fas fa-trash-alt',
            confirmText: 'Delete Files',
            variant: 'danger'
        })
        : false;

    if (!confirmed) {
        return;
    }

    $.ajax({
        url: `<?php echo BASE_URL; ?>api/folders/bulk-delete-files.php`,
        method: 'POST',
        data: { file_ids: selectedFiles },
        success: function() {
            bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal'))?.hide();
            loadFolderContents(currentFolderId);
            showFolderNotice('Folders', `${selectedFiles.length} files deleted`);
        },
        error: function() {
            showFolderNotice('Folders', 'Unable to delete selected files.', 'error');
        }
    });
}

function bulkDownload() {
    selectedFiles.forEach(id => {
        window.open(`<?php echo BASE_URL; ?>api/folders/download.php?id=${id}`, '_blank');
    });
    bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal')).hide();
}

function bulkMove() {
    showFolderNotice('Folders', 'Bulk move functionality coming soon', 'info');
}

async function deleteFile(fileId) {
    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm({
            title: 'Delete File',
            subject: 'Delete this file?',
            message: 'This file will be removed from the selected folder.',
            icon: 'fas fa-file-circle-xmark fa-lg',
            confirmIcon: 'fas fa-trash-alt',
            confirmText: 'Delete File',
            variant: 'danger'
        })
        : false;

    if (!confirmed) {
        return;
    }

    $.ajax({
        url: `<?php echo BASE_URL; ?>api/folders/delete-file.php?id=${fileId}`,
        method: 'POST',
        success: () => loadFolderContents(currentFolderId),
        error: () => showFolderNotice('Folders', 'Unable to delete this file.', 'error')
    });
}

async function createFolderInCurrent() {
    if (currentFolderId <= 0) {
        showFolderNotice('Folders', 'Please open a client or shared project folder before creating a folder.', 'error');
        return;
    }

    const name = typeof window.appPrompt === 'function'
        ? await window.appPrompt({
            title: 'Create Folder',
            label: 'Folder name',
            placeholder: 'Enter folder name',
            confirmIcon: 'fas fa-folder-plus',
            confirmText: 'Create'
        })
        : null;

    if (!name || !name.trim()) {
        return;
    }

    $.ajax({
        url: `<?php echo BASE_URL; ?>api/folders/create.php`,
        method: 'POST',
        data: { name: name.trim(), parent_id: currentFolderId },
        success: () => currentFolderId > 0 ? loadFolderContents(currentFolderId) : loadRootFolders(),
        error: () => showFolderNotice('Folders', 'Unable to create this folder.', 'error')
    });
}

function uploadToCurrentFolder() {
    if (currentFolderId <= 0) {
        showFolderNotice('Folders', 'Please open a folder before uploading files.', 'error');
        return;
    }
    
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.onchange = e => {
        const formData = new FormData();
        Array.from(e.target.files).forEach(file => formData.append('files[]', file));
        formData.append('folder_id', currentFolderId);
        
        $.ajax({
            url: `<?php echo BASE_URL; ?>api/folders/bulk-upload.php`,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: () => loadFolderContents(currentFolderId)
        });
    };
    input.click();
}

function exportCurrentFolder() {
    showFolderNotice('Folders', 'ZIP export feature coming soon', 'info');
}

function refreshFolder() {
    currentFolderId > 0 ? loadFolderContents(currentFolderId) : loadSharedFolders();
}

function showLoading() {
    $('#globalLoader').remove();
    $('body').append(`<div id="globalLoader" class="position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-25 d-flex align-items-center justify-content-center" style="z-index: 9999;"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>`);
}

function hideLoading() {
    $('#globalLoader').remove();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

</script>
<?php include 'includes/footer.php'; ?>
