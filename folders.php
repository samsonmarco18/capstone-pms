<?php
require_once 'includes/auth.php';
requireLogin();
$user = getCurrentUser();
$conn = getConnection();
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
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-folder-tree me-2"></i> 
                Project Folders & Files
            </h2>
            <p>Organized by Client > Role > User - Secure file collaboration</p>
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
                        <li class="breadcrumb-item active">All Clients</li>
                    </ol>
                </nav>
            </div>

            <!-- Files and Folders Card -->
            <div class="main-card">
                <div class="card-header">
                    <h6 id="currentFolderTitle">
                        <i class="fas fa-folder-open me-2"></i>Root Folders
                    </h6>
                    <div class="d-flex gap-2">
                        <button class="btn-primary-modern" onclick="createFolderInCurrent()">
                            <i class="fas fa-folder-plus"></i> New Folder
                        </button>
                        <button class="btn-primary-modern" onclick="uploadToCurrentFolder()">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <div class="dropdown">
                            <button class="btn-outline-modern dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="exportCurrentFolder()"><i class="fas fa-download me-2"></i>Export ZIP</a></li>
                                <li><a class="dropdown-item" href="#" onclick="refreshFolder()"><i class="fas fa-sync-alt me-2"></i>Refresh</a></li>
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
                                    <p>Select a folder to view contents</p>
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

<script>
// Global variables
let currentFolderId = 0;
let selectedFiles = [];
let folderStructure = {};
let currentBreadcrumb = [];

// Initialize folders page
$(document).ready(function() {
    loadRootFolders();
    initBulkActions();
});

// Load root folders (clients)
function loadRootFolders() {
    currentFolderId = 0;
    currentBreadcrumb = [];
    renderBreadcrumbs([]);
    $('#currentFolderTitle').html('<i class="fas fa-folder-open me-2"></i>All Clients');
    
    $.ajax({
        url: `<?php echo BASE_URL; ?>api/folders/get-tree.php?folder_id=0&role=<?php echo $user['role']; ?>`,
        dataType: 'json',
        success: function(data) {
            renderFolderTree(data.folders);
            renderEntries(data.folders || [], []);
        }
    });
}

// Load folder tree
function renderFolderTree(folders, parentId = 0, renderToDom = true) {
    folderStructure[parentId] = folders;
    
    let html = '';
    folders.forEach(folder => {
        const icon = folder.folder_type === 'client' ? 'fa-building' : 
                    folder.folder_type === 'role' ? 'fa-users' :
                    folder.folder_type === 'user' ? 'fa-user' : 'fa-folder';
        
        html += `
            <div class="folder-item" data-folder-id="${folder.id}">
                <i class="fas ${icon} folder-icon"></i>
                <span class="folder-name">${escapeHtml(folder.name)}</span>
                <i class="fas fa-chevron-right folder-expand"></i>
            </div>
        `;
        
        if (folder.children && folder.children.length) {
            html += `<div class="folder-children" style="display: none;">${renderFolderTree(folder.children, folder.id, false)}</div>`;
        }
    });
    
    if (renderToDom) {
        $('#folderTree').html(html || '<div class="text-center text-muted py-4"><i class="fas fa-folder-open fa-2x mb-2"></i><p>No folders available</p></div>');
        initTreeEvents();
    }
    return html;
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
        
        loadFolderContents(folderId);
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
            renderEntries(data.folders || [], data.files || []);
            currentBreadcrumb = Array.isArray(data.breadcrumb) ? data.breadcrumb : [];
            renderBreadcrumbs(currentBreadcrumb);
            
            const folder = data.current_folder || findFolderById(folderId);
            $('#currentFolderTitle').html(`<i class="fas fa-folder-open me-2"></i>${folder ? escapeHtml(folder.name) : 'Files'}`);
        },
        error: function() {
            hideLoading();
            alert('Failed to load folder contents');
        }
    });
}

function renderEntries(folders, files) {
    const tbody = $('#fileTableBody');
    tbody.empty();
    
    if (folders.length === 0 && files.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-folder-open fa-2x mb-2"></i><p>No files or folders here yet</p></td></tr>');
        return;
    }
    
    folders.forEach(folder => {
        tbody.append(`
            <tr class="folder-row" data-folder-id="${folder.id}">
                <td></td>
                <td>
                    <div class="d-flex align-items-center">
                        <i class="fas fa-folder text-warning me-2" style="font-size: 18px;"></i>
                        <a href="#" onclick="loadFolderContents(${folder.id}); return false;" class="file-link">${escapeHtml(folder.name)}</a>
                    </div>
                </td>
                <td>—</td>
                <td>${folder.updated_at ? new Date(folder.updated_at).toLocaleDateString() : '-'}</td>
                <td>${escapeHtml(folder.owner_name || 'System')}</td>
                <td><span class="badge-folder">Folder</span></td>
                <td>
                    <button class="btn-outline-modern btn-sm" onclick="loadFolderContents(${folder.id})" style="padding: 0.2rem 0.5rem;">
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

function renderBreadcrumbs(path) {
    const container = $('#folderBreadcrumbs nav ol');
    let html = '<li class="breadcrumb-item"><a href="#" onclick="loadRootFolders(); return false;"><i class="fas fa-home me-1"></i>All Clients</a></li>';
    
    if (!Array.isArray(path) || path.length === 0) {
        html = '<li class="breadcrumb-item active">All Clients</li>';
        container.html(html);
        return;
    }
    
    path.forEach((folder, index) => {
        const isLast = index === path.length - 1;
        if (isLast) {
            html += `<li class="breadcrumb-item active">${escapeHtml(folder.name)}</li>`;
        } else {
            html += `<li class="breadcrumb-item"><a href="#" onclick="loadFolderContents(${folder.id}); return false;">${escapeHtml(folder.name)}</a></li>`;
        }
    });
    
    container.html(html);
}

function findFolderById(id) {
    for (let parentId in folderStructure) {
        const folder = folderStructure[parentId]?.find(f => f.id == id);
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
    
    for (let key in icons) {
        if (type.toLowerCase().includes(key)) return icons[key];
    }
    return 'fas fa-file text-muted';
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

function bulkDelete() {
    if (confirm(`Delete ${selectedFiles.length} file(s)? This cannot be undone.`)) {
        $.ajax({
            url: `<?php echo BASE_URL; ?>api/folders/bulk-delete-files.php`,
            method: 'POST',
            data: { file_ids: selectedFiles },
            success: function() {
                bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal')).hide();
                loadFolderContents(currentFolderId);
                alert(`${selectedFiles.length} files deleted`);
            }
        });
    }
}

function bulkDownload() {
    selectedFiles.forEach(id => {
        window.open(`<?php echo BASE_URL; ?>api/folders/download.php?id=${id}`, '_blank');
    });
    bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal')).hide();
}

function bulkMove() {
    alert('Bulk move functionality coming soon');
}

function deleteFile(fileId) {
    if (confirm('Delete this file?')) {
        $.ajax({
            url: `<?php echo BASE_URL; ?>api/folders/delete-file.php?id=${fileId}`,
            method: 'POST',
            success: () => loadFolderContents(currentFolderId)
        });
    }
}

function createFolderInCurrent() {
    const name = prompt('Folder name:');
    if (name) {
        $.ajax({
            url: `<?php echo BASE_URL; ?>api/folders/create.php`,
            method: 'POST',
            data: { name: name, parent_id: currentFolderId },
            success: () => currentFolderId > 0 ? loadFolderContents(currentFolderId) : loadRootFolders()
        });
    }
}

function uploadToCurrentFolder() {
    if (currentFolderId <= 0) {
        alert('Please open a folder before uploading files.');
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
    alert('ZIP export feature coming soon');
}

function refreshFolder() {
    currentFolderId > 0 ? loadFolderContents(currentFolderId) : loadRootFolders();
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
