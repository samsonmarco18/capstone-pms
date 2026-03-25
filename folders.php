<?php
require_once 'includes/auth.php';
requireLogin();
$user = getCurrentUser();
$conn = getConnection();
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>
<?php include 'includes/navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex align-items-center mb-4">
                <i class="fas fa-folder-tree fa-2x text-primary me-3"></i>
                <div>
                    <h2>Project Folders & Files</h2>
                    <p class="text-muted mb-0">Organized by Client > Role > User - Secure file collaboration</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Folders Tree -->
    <div class="row">
        <div class="col-md-4">
            <div class="card sticky-top" style="top: 20px;">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="fas fa-sitemap me-2"></i>Folder Structure
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="folder-tree-container p-3" style="height: 70vh; overflow-y: auto;" id="folderTree">
                        <!-- Tree loaded via AJAX -->
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <!-- Breadcrumbs -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-light rounded p-3 mb-4" id="folderBreadcrumbs">
                    <li class="breadcrumb-item active" aria-current="page">All Clients</li>
                </ol>
            </nav>

            <!-- File List -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0" id="currentFolderTitle">Root Folders</h6>
                    <div>
                        <button class="btn btn-primary btn-sm me-2" onclick="createFolderInCurrent()">
                            <i class="fas fa-folder-plus"></i> New Folder
                        </button>
                        <button class="btn btn-outline-success btn-sm me-2" onclick="uploadToCurrentFolder()">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="exportCurrentFolder()"><i class="fas fa-download me-2"></i>Export ZIP</a></li>
                                <li><a class="dropdown-item" href="#" onclick="refreshFolder()"><i class="fas fa-sync-alt me-2"></i>Refresh</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="height: 60vh;">
                        <table class="table table-hover mb-0" id="fileTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th><input type="checkbox" id="selectAllFiles" class="form-check-input"></th>
                                    <th>Name</th>
                                    <th>Size</th>
                                    <th>Modified</th>
                                    <th>Owner</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="fileTableBody">
                                <!-- Files loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Actions Modal -->
<div class="modal fade" id="bulkActionsModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Actions</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Selected: <span id="selectedCount">0</span> items</label>
                </div>
                <button class="btn btn-danger w-100 mb-2" onclick="bulkDelete()">
                    <i class="fas fa-trash me-2"></i>Delete Selected
                </button>
                <button class="btn btn-success w-100 mb-2" onclick="bulkDownload()">
                    <i class="fas fa-download me-2"></i>Download Selected
                </button>
                <button class="btn btn-primary w-100" onclick="bulkMove()">
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
    $('#currentFolderTitle').text('All Clients');
    
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
            <div class="folder-item d-flex align-items-center p-2 border-bottom folder-node" data-folder-id="${folder.id}">
                <i class="fas ${icon} text-primary me-2 folder-icon" style="width: 18px; font-size: 14px;"></i>
                <span class="folder-name flex-grow-1">${escapeHtml(folder.name)}</span>
                <div class="folder-actions">
                    <i class="fas fa-chevron-right folder-expand ms-2 text-muted" style="font-size: 12px;"></i>
                </div>
            </div>
        `;
        
        // Render children recursively
        if (folder.children && folder.children.length) {
            html += `<div class="folder-children ms-4" style="display: none;">${renderFolderTree(folder.children, folder.id, false)}</div>`;
        }
    });
    
    if (renderToDom) {
        $('#folderTree').html(html);
        initTreeEvents();
    }

    return html;
}

function initTreeEvents() {
    $('#folderTree .folder-item').off('click').on('click', function(e) {
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
            $('#currentFolderTitle').text(folder ? folder.name : 'Files');
        },
        error: function() {
            hideLoading();
            showToast('Error', 'Failed to load folder contents');
        }
    });
}

function renderEntries(folders, files) {
    const tbody = $('#fileTableBody');
    tbody.empty();
    
    if (folders.length === 0 && files.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-5">No files or folders here yet</td></tr>');
        return;
    }

    folders.forEach(folder => {
        tbody.append(`
            <tr class="folder-row" data-folder-id="${folder.id}">
                <td></td>
                <td>
                    <div class="d-flex align-items-center">
                        <i class="fas fa-folder text-warning me-2" style="font-size: 18px; width: 24px;"></i>
                        <a href="#" onclick="loadFolderContents(${folder.id}); return false;" class="file-link">${escapeHtml(folder.name)}</a>
                    </div>
                </td>
                <td>-</td>
                <td>${folder.updated_at ? new Date(folder.updated_at).toLocaleDateString() : '-'}</td>
                <td>${escapeHtml(folder.owner_name || 'System')}</td>
                <td><span class="badge bg-info text-dark">Folder</span></td>
                <td>
                    <button class="btn btn-outline-primary btn-sm" onclick="loadFolderContents(${folder.id})">
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
        const ownerName = file.owner_name || 'Unknown';
        
        tbody.append(`
            <tr class="file-row" data-file-id="${file.id}">
                <td><input type="checkbox" class="file-checkbox form-check-input"></td>
                <td>
                    <div class="d-flex align-items-center">
                        <i class="${icon} text-muted me-2" style="font-size: 18px; width: 24px;"></i>
                        <a href="<?php echo BASE_URL; ?>${file.file_path}" target="_blank" class="file-link">${escapeHtml(file.original_file_name)}</a>
                    </div>
                </td>
                <td>${size}</td>
                <td>${modified}</td>
                <td>${escapeHtml(ownerName)}</td>
                <td><span class="badge bg-secondary">${file.file_type || 'Unknown'}</span></td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <a href="<?php echo BASE_URL; ?>${file.file_path}" class="btn btn-outline-primary" download title="Download">
                            <i class="fas fa-download"></i>
                        </a>
                        <button class="btn btn-outline-secondary share-btn" title="Share">
                            <i class="fas fa-share-alt"></i>
                        </button>
                        <button class="btn btn-outline-danger delete-btn" title="Delete">
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
    const container = $('#folderBreadcrumbs');
    let html = '<li class="breadcrumb-item"><a href="#" onclick="loadRootFolders(); return false;"><i class="fas fa-home me-1"></i>All Clients</a></li>';

    if (!Array.isArray(path) || path.length === 0) {
        html = '<li class="breadcrumb-item active" aria-current="page"><i class="fas fa-home me-1"></i>All Clients</li>';
        container.html(html);
        return;
    }

    path.forEach((folder, index) => {
        const isLast = index === path.length - 1;
        if (isLast) {
            html += `<li class="breadcrumb-item active" aria-current="page">${escapeHtml(folder.name)}</li>`;
        } else {
            html += `<li class="breadcrumb-item"><a href="#" onclick="loadFolderContents(${folder.id}); return false;">${escapeHtml(folder.name)}</a></li>`;
        }
    });

    container.html(html);
}

// Helper functions
function findFolderById(id) {
    for (let parentId in folderStructure) {
        const folder = folderStructure[parentId].find(f => f.id == id);
        if (folder) return folder;
    }
    return null;
}

function getFileIcon(type) {
    if (!type) {
        return 'fas fa-file text-muted';
    }

    const icons = {
        'pdf': 'fas fa-file-pdf text-danger',
        'doc': 'fas fa-file-word text-blue',
        'xls': 'fas fa-file-excel text-success',
        'ppt': 'fas fa-file-powerpoint text-orange',
        'jpg,jpeg,png,gif': 'fas fa-file-image text-info',
        'zip,rar': 'fas fa-file-archive text-purple',
        'js,css,html,php': 'fas fa-file-code text-secondary'
    };
    
    for (let key in icons) {
        if (key.includes(type.toLowerCase()) || type.toLowerCase().includes(key)) {
            return icons[key];
        }
    }
    return 'fas fa-file text-muted';
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
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
    
    $('.bulk-action-btn').on('click', function() {
        if (selectedFiles.length === 0) {
            showToast('No Selection', 'Please select files first');
            return;
        }
        $('#bulkActionsModal').modal('show');
        $('#selectedCount').text(selectedFiles.length);
    });
}

function updateBulkSelection() {
    selectedFiles = $('.file-checkbox:checked').map(function() {
        return $(this).closest('tr').data('file-id');
    }).get();
    
    $('#bulkActionsBtn').toggleClass('d-none', selectedFiles.length === 0);
}

function bulkDelete() {
    if (confirm(`Delete ${selectedFiles.length} file(s)? This cannot be undone.`)) {
        $.ajax({
            url: `<?php echo BASE_URL; ?>api/folders/bulk-delete-files.php`,
            method: 'POST',
            data: { file_ids: selectedFiles },
            success: function() {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkActionsModal')).hide();
                currentFolderId > 0 ? loadFolderContents(currentFolderId) : loadRootFolders();
                showToast('Success', `${selectedFiles.length} files deleted`);
            }
        });
    }
}

function bulkDownload() {
    selectedFiles.forEach(id => {
        window.open(`<?php echo BASE_URL; ?>api/folders/download.php?id=${id}`, '_blank');
    });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkActionsModal')).hide();
}

function bulkMove() {
    const targetFolder = prompt('Enter target folder ID:');
    if (targetFolder) {
        // Implementation for move
        showToast('Coming Soon', 'Bulk move functionality in progress');
    }
}

function exportCurrentFolder() {
    showToast('Folders', 'ZIP export can be added next. The current folder browser, uploads, and downloads are now active.', 'info');
}

function refreshFolder() {
    if (currentFolderId > 0) {
        loadFolderContents(currentFolderId);
        return;
    }

    loadRootFolders();
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
        showToast('Folder', 'Please open a folder before uploading files.', 'warning');
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

function showLoading() {
    $('#globalLoader').remove();
    $('body').append(`
        <div id="globalLoader" class="spinner-overlay fade show">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `);
}

function hideLoading() {
    $('#globalLoader').fadeOut(300, function() { $(this).remove(); });
}
</script>

<style>
/* Folders Page Specific Styles */
.folder-tree-container {
    background: #f8f9fa;
    border-radius: 8px;
}

.folder-item {
    cursor: pointer;
    transition: background 0.2s;
    border-radius: 6px;
}

.folder-item:hover {
    background: rgba(13, 85, 107, 0.05);
}

.folder-children {
    border-left: 2px solid var(--primary-color);
    margin-left: 20px;
}

.file-row:hover {
    background: rgba(13, 85, 107, 0.05);
}

.file-link {
    color: var(--primary-color);
    text-decoration: none;
}

.file-link:hover {
    text-decoration: underline;
}

#fileTable th {
    font-weight: 600;
    color: var(--primary-color);
    font-size: 0.9rem;
}

.breadcrumb a {
    color: var(--primary-color);
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}
</style>

<?php include 'includes/footer.php'; ?>

