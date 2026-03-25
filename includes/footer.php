<?php
// Close main content containers
?>
        </div> <!-- Close main content -->
    </div> <!-- Close page-content-wrapper -->
</div> <!-- Close wrapper -->

<!-- Chat Widget -->
<?php include __DIR__ . '/chat-widget.php'; ?>

<!-- Create Group Modal -->
<div class="modal fade" id="createGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Chat Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Group Name</label>
                    <input type="text" id="groupName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea id="groupDescription" class="form-control" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Select Members</label>
                    <select id="groupMembers" class="form-select" multiple size="5">
                        <!-- Members will be loaded via AJAX -->
                    </select>
                    <small class="text-muted">Hold Ctrl/Cmd to select multiple members</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createGroup()">Create Group</button>
            </div>
        </div>
    </div>
</div>

<!-- Group Chat Modal -->
<div class="modal fade" id="groupChatModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="groupChatTitle">Group Chat</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="chat-messages" id="groupChatMessages" style="height: 400px; overflow-y: auto;"></div>
                <div class="mt-3">
                    <div class="input-group">
                        <input type="text" id="groupChatInput" class="form-control" placeholder="Type your message...">
                        <button class="btn btn-primary" onclick="sendGroupMessage()">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">My Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="profileContent">
                <!-- Profile content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Folders Modal -->
<div class="modal fade" id="foldersModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">My Folders</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="foldersContent">
                <!-- Folders content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Scripts loaded in header -->
<script>
// Page load complete - finalize UI
$(window).on('load', function() {
    // Fade out loading screen if present
    $('.preloader').fadeOut();
    
    // Re-init tooltips after dynamic content
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Auto-hide success/error messages
    $('.alert').each(function() {
        const alert = $(this);
        setTimeout(() => alert.fadeOut(), 5000);
    });
});
</script>

<!-- Sidebar Overlay for mobile -->
<div class="sidebar-overlay"></div>

</body>
</html>
<script>
// Global variables
let currentChatGroup = null;
let currentChatUser = null;
let chatInterval = null;

$(document).ready(function() {
    // Load notifications on page load
    loadNotifications();
    
    // Auto-refresh notifications every 30 seconds
    setInterval(loadNotifications, 30000);
    
    // Load users for group creation
    loadUsersForGroup();
});

// Notification functions
function loadNotifications() {
    $.ajax({
        url: BASE_URL + '/api/notifications/get-notifications.php',
        method: 'GET',
        success: function(response) {
            const notifications = JSON.parse(response);
            const unreadCount = notifications.filter(n => !n.is_read).length;
            $('#notificationCount').text(unreadCount);
            
            const notificationsList = $('#notificationsList');
            notificationsList.empty();
            notificationsList.append('<h6 class="dropdown-header">Notifications</h6>');
            notificationsList.append('<div class="dropdown-divider"></div>');
            
            if (notifications.length === 0) {
                notificationsList.append('<p class="text-center text-muted">No notifications</p>');
            } else {
                notifications.forEach(notification => {
                    notificationsList.append(`
                        <a class="dropdown-item ${!notification.is_read ? 'bg-light' : ''}" href="#" onclick="markNotificationRead(${notification.id})">
                            <div>
                                <strong>${notification.title}</strong>
                                <br>
                                <small>${notification.message}</small>
                                <br>
                                <small class="text-muted">${new Date(notification.created_at).toLocaleString()}</small>
                            </div>
                        </a>
                        <div class="dropdown-divider"></div>
                    `);
                });
            }
        }
    });
}

function markNotificationRead(id) {
    $.ajax({
        url: BASE_URL + '/api/notifications/mark-read.php',
        method: 'POST',
        data: { id: id },
        success: function() {
            loadNotifications();
        }
    });
}

// Profile functions
function viewProfile() {
    $.ajax({
        url: BASE_URL + '/api/get-profile.php',
        method: 'GET',
        success: function(response) {
            const user = JSON.parse(response);
            $('#profileContent').html(`
                <div class="text-center mb-3">
                    <i class="fas fa-user-circle fa-4x text-primary"></i>
                </div>
                <table class="table table-borderless">
                    <tr>
                        <th>Full Name:</th>
                        <td>${user.full_name}</td>
                    </tr>
                    <tr>
                        <th>Username:</th>
                        <td>${user.username}</td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td>${user.email}</td>
                    </tr>
                    <tr>
                        <th>Department:</th>
                        <td>${user.department || 'Not specified'}</td>
                    </tr>
                    <tr>
                        <th>Role:</th>
                        <td><span class="badge bg-primary">${user.role.toUpperCase()}</span></td>
                    </tr>
                    <tr>
                        <th>Member Since:</th>
                        <td>${new Date(user.created_at).toLocaleDateString()}</td>
                    </tr>
                </table>
                <button class="btn btn-primary w-100" onclick="changePassword()">
                    <i class="fas fa-key"></i> Change Password
                </button>
            `);
            new bootstrap.Modal(document.getElementById('profileModal')).show();
        }
    });
}

function changePassword() {
    const newPassword = prompt('Enter new password:');
    if (newPassword && newPassword.length >= 6) {
        $.ajax({
            url: BASE_URL + '/api/change-password.php',
            method: 'POST',
            data: { password: newPassword },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showToast('Success', 'Password changed successfully');
                } else {
                    showToast('Error', result.error);
                }
            }
        });
    } else if (newPassword) {
        alert('Password must be at least 6 characters');
    }
}

// Folder functions
function openFolders() {
    $.ajax({
        url: BASE_URL + '/api/folders/get-structure.php',
        method: 'GET',
        success: function(response) {
            const data = JSON.parse(response);
            let html = `
                <div class="mb-3">
                    <button class="btn btn-primary btn-sm" onclick="createFolder()">
                        <i class="fas fa-folder-plus"></i> New Folder
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="uploadFile()">
                        <i class="fas fa-upload"></i> Upload File
                    </button>
                </div>
                <div class="folder-tree">
            `;
            
            function renderFolders(folders, level = 0) {
                let result = '';
                folders.forEach(folder => {
                    const indent = '&nbsp;'.repeat(level * 4);
                    result += `
                        <div class="folder-item mb-2">
                            <div class="d-flex align-items-center">
                                <span class="me-2">${indent}</span>
                                <i class="fas fa-folder text-warning me-2"></i>
                                <a href="#" onclick="openFolder(${folder.id})">${folder.name}</a>
                                <button class="btn btn-sm btn-link text-danger ms-2" onclick="deleteFolder(${folder.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                    `;
                    if (folder.children && folder.children.length > 0) {
                        result += renderFolders(folder.children, level + 1);
                    }
                    result += `</div>`;
                });
                return result;
            }
            
            html += renderFolders(data.folders);
            html += `</div>`;
            
            if (data.files && data.files.length > 0) {
                html += `<hr><h6>Files in this folder:</h6><div class="file-list">`;
                data.files.forEach(file => {
                    html += `
                        <div class="file-item d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
                            <div>
                                <i class="fas fa-file me-2"></i>
                                ${file.name}
                                <small class="text-muted ms-2">${(file.file_size / 1024).toFixed(2)} KB</small>
                            </div>
                            <div>
                                <a href="${BASE_URL}/${file.file_path}" class="btn btn-sm btn-primary" download>
                                    <i class="fas fa-download"></i>
                                </a>
                                <button class="btn btn-sm btn-danger" onclick="deleteFile(${file.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }
            
            $('#foldersContent').html(html);
            new bootstrap.Modal(document.getElementById('foldersModal')).show();
        }
    });
}

function openSharedFolders() {
    $.ajax({
        url: BASE_URL + '/api/folders/get-shared.php',
        method: 'GET',
        success: function(response) {
            const folders = JSON.parse(response);
            let html = `<h6>Shared Folders</h6><div class="folder-tree">`;
            folders.forEach(folder => {
                html += `
                    <div class="folder-item mb-2">
                        <i class="fas fa-folder-open text-info me-2"></i>
                        <a href="#" onclick="openFolder(${folder.id})">${folder.name}</a>
                        <small class="text-muted ms-2">(Shared by: ${folder.owner_name})</small>
                    </div>
                `;
            });
            html += `</div>`;
            $('#foldersContent').html(html);
            new bootstrap.Modal(document.getElementById('foldersModal')).show();
        }
    });
}

function openFolder(folderId) {
    window.location.href = BASE_URL + '/modules/folders/view.php?id=' + folderId;
}

function createFolder() {
    const name = prompt('Enter folder name:');
    if (name) {
        $.ajax({
            url: BASE_URL + '/api/folders/create.php',
            method: 'POST',
            data: { name: name, parent_id: 0 },
            success: function() {
                openFolders();
                showToast('Success', 'Folder created successfully');
            }
        });
    }
}

function deleteFolder(folderId) {
    if (confirm('Are you sure you want to delete this folder and all its contents?')) {
        $.ajax({
            url: BASE_URL + '/api/folders/delete.php',
            method: 'POST',
            data: { id: folderId },
            success: function() {
                openFolders();
                showToast('Success', 'Folder deleted successfully');
            }
        });
    }
}

function uploadFile() {
    const input = document.createElement('input');
    input.type = 'file';
    input.onchange = function(e) {
        const file = e.target.files[0];
        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder_id', 0);
        
        $.ajax({
            url: BASE_URL + '/api/folders/upload-file.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function() {
                openFolders();
                showToast('Success', 'File uploaded successfully');
            }
        });
    };
    input.click();
}

function deleteFile(fileId) {
    if (confirm('Are you sure you want to delete this file?')) {
        $.ajax({
            url: BASE_URL + '/api/folders/delete-file.php',
            method: 'POST',
            data: { id: fileId },
            success: function() {
                openFolders();
                showToast('Success', 'File deleted successfully');
            }
        });
    }
}

// Toast notification
function showToast(title, message, type = 'success') {
    const toastHTML = `
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
            <div class="toast align-items-center text-white bg-${type} border-0" role="alert" data-bs-autohide="true" data-bs-delay="3000">
                <div class="d-flex">
                    <div class="toast-body">
                        <strong>${title}</strong><br>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        </div>
    `;
    
    $('body').append(toastHTML);
    const toast = new bootstrap.Toast($('.toast').last());
    toast.show();
    
    $('.toast').last().on('hidden.bs.toast', function() {
        $(this).parent().remove();
    });
}
</script>

</body>
</html>