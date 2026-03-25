<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$user = getCurrentUser();

// Handle settings updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_general'])) {
        $siteName = $_POST['site_name'];
        $siteEmail = $_POST['site_email'];
        $timezone = $_POST['timezone'];
        
        // Update settings in database (you may want to create a settings table)
        $_SESSION['site_name'] = $siteName;
        $_SESSION['site_email'] = $siteEmail;
        
        date_default_timezone_set($timezone);
        
        logActivity($user['id'], 'update_general_settings', 'settings', 0, null, $_POST);
        $_SESSION['success'] = "General settings updated successfully";
    }
    
    if (isset($_POST['update_security'])) {
        $sessionTimeout = $_POST['session_timeout'];
        $passwordExpiry = $_POST['password_expiry'];
        $maxLoginAttempts = $_POST['max_login_attempts'];
        
        // Update security settings
        $_SESSION['session_timeout'] = $sessionTimeout;
        $_SESSION['password_expiry'] = $passwordExpiry;
        
        logActivity($user['id'], 'update_security_settings', 'settings', 0, null, $_POST);
        $_SESSION['success'] = "Security settings updated successfully";
    }
    
    if (isset($_POST['update_notifications'])) {
        $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
        $taskReminders = isset($_POST['task_reminders']) ? 1 : 0;
        $projectUpdates = isset($_POST['project_updates']) ? 1 : 0;
        
        // Update notification settings in user preferences
        $stmt = $conn->prepare("UPDATE users SET email_notifications=?, task_reminders=?, project_updates=? WHERE id=?");
        $stmt->bind_param("iiii", $emailNotifications, $taskReminders, $projectUpdates, $user['id']);
        $stmt->execute();
        
        logActivity($user['id'], 'update_notification_settings', 'users', $user['id'], null, $_POST);
        $_SESSION['success'] = "Notification settings updated successfully";
    }
    
    if (isset($_POST['backup_database'])) {
        // Create database backup
        $backupFile = '../../backups/backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // Execute mysqldump command (ensure mysqldump is available)
        $command = "mysqldump --user=" . DB_USER . " --password=" . DB_PASS . " " . DB_NAME . " > " . $backupFile;
        system($command, $output);
        
        if(file_exists($backupFile)) {
            $_SESSION['success'] = "Database backup created successfully";
            logActivity($user['id'], 'database_backup', 'system', 0);
        } else {
            $_SESSION['error'] = "Backup failed. Please check permissions.";
        }
    }
    
    header('Location: settings.php');
    exit();
}

// Get current settings
$currentTimezone = date_default_timezone_get();
$emailNotifications = $conn->query("SELECT email_notifications, task_reminders, project_updates FROM users WHERE id = {$user['id']}")->fetch_assoc();

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>System Settings</h2>
        <p class="text-muted">Configure system-wide settings and preferences</p>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>General Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Site Name</label>
                        <input type="text" name="site_name" class="form-control" value="ERNI PMS" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Site Email</label>
                        <input type="email" name="site_email" class="form-control" value="admin@erni.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default Timezone</label>
                        <select name="timezone" class="form-select">
                            <option value="UTC" <?php echo $currentTimezone == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                            <option value="Asia/Manila" <?php echo $currentTimezone == 'Asia/Manila' ? 'selected' : ''; ?>>Asia/Manila</option>
                            <option value="America/New_York" <?php echo $currentTimezone == 'America/New_York' ? 'selected' : ''; ?>>America/New York</option>
                            <option value="Europe/London" <?php echo $currentTimezone == 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                            <option value="Asia/Tokyo" <?php echo $currentTimezone == 'Asia/Tokyo' ? 'selected' : ''; ?>>Asia/Tokyo</option>
                        </select>
                    </div>
                    <button type="submit" name="update_general" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>Security Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Session Timeout (minutes)</label>
                        <input type="number" name="session_timeout" class="form-control" value="30" min="5" max="480">
                        <small class="text-muted">Time before inactive users are logged out</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password Expiry (days)</label>
                        <input type="number" name="password_expiry" class="form-control" value="90" min="0" max="365">
                        <small class="text-muted">0 = never expire</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max Login Attempts</label>
                        <input type="number" name="max_login_attempts" class="form-control" value="5" min="3" max="10">
                        <small class="text-muted">Failed attempts before account lockout</small>
                    </div>
                    <button type="submit" name="update_security" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Notification Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="email_notifications" id="emailNotifications" 
                                <?php echo ($emailNotifications['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="emailNotifications">
                                <i class="fas fa-envelope me-2"></i> Email Notifications
                            </label>
                        </div>
                        <small class="text-muted">Receive email notifications for important updates</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="task_reminders" id="taskReminders" 
                                <?php echo ($emailNotifications['task_reminders'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="taskReminders">
                                <i class="fas fa-tasks me-2"></i> Task Reminders
                            </label>
                        </div>
                        <small class="text-muted">Receive reminders for upcoming tasks</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="project_updates" id="projectUpdates" 
                                <?php echo ($emailNotifications['project_updates'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="projectUpdates">
                                <i class="fas fa-project-diagram me-2"></i> Project Updates
                            </label>
                        </div>
                        <small class="text-muted">Receive notifications for project status changes</small>
                    </div>
                    
                    <button type="submit" name="update_notifications" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Preferences
                    </button>
                </form>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>System Maintenance</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Database Backup</label>
                        <p class="text-muted">Create a full backup of the database. This may take a few moments.</p>
                        <button type="submit" name="backup_database" class="btn btn-warning" onclick="return confirm('Create database backup? This may take a moment.')">
                            <i class="fas fa-database"></i> Create Backup
                        </button>
                    </div>
                </form>
                
                <hr>
                
                <div class="mb-3">
                    <label class="form-label">System Information</label>
                    <div class="list-group">
                        <div class="list-group-item">
                            <strong>PHP Version:</strong> <?php echo phpversion(); ?>
                        </div>
                        <div class="list-group-item">
                            <strong>MySQL Version:</strong> <?php echo $conn->server_info; ?>
                        </div>
                        <div class="list-group-item">
                            <strong>Server OS:</strong> <?php echo php_uname(); ?>
                        </div>
                        <div class="list-group-item">
                            <strong>Upload Max Size:</strong> <?php echo ini_get('upload_max_filesize'); ?>
                        </div>
                        <div class="list-group-item">
                            <strong>Memory Limit:</strong> <?php echo ini_get('memory_limit'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Clear System Cache</strong><br>
                    <button class="btn btn-danger btn-sm mt-2" onclick="clearCache()">
                        <i class="fas fa-trash"></i> Clear All Cache
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function clearCache() {
    if(confirm('Clear all system cache? This will clear temporary files and cached data.')) {
        $.ajax({
            url: '../../api/clear-cache.php',
            method: 'POST',
            success: function(response) {
                const result = JSON.parse(response);
                if(result.success) {
                    showToast('Success', 'Cache cleared successfully');
                } else {
                    showToast('Error', result.error, 'danger');
                }
            }
        });
    }
}
</script>

<?php include '../../includes/footer.php'; ?>