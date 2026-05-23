<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$user = getCurrentUser();

function getSettingValue($conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string) ($row['setting_value'] ?? $default);
}

function saveSettingValue($conn, string $key, string $value, string $description): void
{
    $sql = DB_DRIVER === 'pgsql'
        ? "INSERT INTO settings (setting_key, setting_value, description, setting_type)
           VALUES (?, ?, ?, 'system')
           ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, description = EXCLUDED.description, updated_at = NOW()"
        : 'INSERT INTO settings (setting_key, setting_value, description, setting_type)
           VALUES (?, ?, ?, \'system\')
           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_at = NOW()';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $key, $value, $description);
    $stmt->execute();
    $stmt->close();
}

// Handle settings updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_general'])) {
        $siteName = $_POST['site_name'];
        $timezone = $_POST['timezone'];
        
        $_SESSION['site_name'] = $siteName;
        
        date_default_timezone_set($timezone);
        saveSettingValue($conn, 'company_name', $siteName, 'Company official name');
        saveSettingValue($conn, 'timezone', $timezone, 'Default system timezone');
        
        logActivity($user['id'], 'update_general_settings', 'settings', 0, null, $_POST);
        $_SESSION['success'] = "General settings updated successfully";
    }
    
    if (isset($_POST['update_security'])) {
        $sessionTimeout = $_POST['session_timeout'];
        $passwordExpiry = $_POST['password_expiry'];
        $maxLoginAttempts = $_POST['max_login_attempts'];
        
        $_SESSION['session_timeout'] = $sessionTimeout;
        $_SESSION['password_expiry'] = $passwordExpiry;
        $_SESSION['max_login_attempts'] = $maxLoginAttempts;

        saveSettingValue($conn, 'session_timeout', (string) $sessionTimeout, 'Minutes before inactive users are logged out');
        saveSettingValue($conn, 'password_expiry', (string) $passwordExpiry, 'Days before passwords expire; 0 means never');
        saveSettingValue($conn, 'max_login_attempts', (string) $maxLoginAttempts, 'Failed attempts before account lockout');
        
        logActivity($user['id'], 'update_security_settings', 'settings', 0, null, $_POST);
        $_SESSION['success'] = "Security settings updated successfully";
    }
    
    if (isset($_POST['update_notifications'])) {
        $taskReminders = isset($_POST['task_reminders']) ? 1 : 0;
        $projectUpdates = isset($_POST['project_updates']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE users SET task_reminders=?, project_updates=? WHERE id=?");
        $stmt->bind_param("iii", $taskReminders, $projectUpdates, $user['id']);
        $stmt->execute();
        
        logActivity($user['id'], 'update_notification_settings', 'users', $user['id'], null, $_POST);
        $_SESSION['success'] = "Notification settings updated successfully";
    }
    
    if (isset($_POST['backup_database'])) {
        $backupFile = '../../backups/backup_' . date('Y-m-d_H-i-s') . '.sql';
        
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
$siteName = getSettingValue($conn, 'company_name', 'ERNI PMS');
$currentTimezone = getSettingValue($conn, 'timezone', date_default_timezone_get());
$sessionTimeout = getSettingValue($conn, 'session_timeout', '30');
$passwordExpiry = getSettingValue($conn, 'password_expiry', '90');
$maxLoginAttempts = getSettingValue($conn, 'max_login_attempts', '5');
$notificationSettings = $conn->query("SELECT task_reminders, project_updates FROM users WHERE id = {$user['id']}")->fetch_assoc();

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

/* Settings Cards */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.settings-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: var(--shadow-sm);
    height: fit-content;
}

.settings-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.card-header-modern {
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.05) 0%, rgba(31, 99, 146, 0.02) 100%);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.card-header-modern i {
    font-size: 1.25rem;
    color: var(--theme-primary);
}

.card-header-modern h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
}

.card-body-modern {
    padding: 1.5rem;
}

/* Form Elements */
.form-group {
    margin-bottom: 1.25rem;
}

.form-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    margin-bottom: 0.5rem;
    display: block;
}

.form-control-modern,
.form-select-modern {
    width: 100%;
    padding: 0.7rem 1rem;
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

.form-text {
    font-size: 0.7rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
    display: block;
}

/* Switch Toggle */
.switch-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--gray-200);
}

.switch-wrapper:last-child {
    border-bottom: none;
}

.switch-info {
    flex: 1;
}

.switch-info label {
    font-weight: 500;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
    display: block;
}

.switch-info small {
    font-size: 0.7rem;
    color: var(--gray-600);
}

.form-switch-modern {
    width: 48px;
    height: 24px;
    position: relative;
    display: inline-block;
}

.form-switch-modern input {
    opacity: 0;
    width: 0;
    height: 0;
}

.switch-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--gray-300);
    transition: 0.3s;
    border-radius: 34px;
}

.switch-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
}

input:checked + .switch-slider {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
}

input:checked + .switch-slider:before {
    transform: translateX(24px);
}

/* Buttons */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-warning-modern {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-warning-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    color: white;
}

.btn-danger-modern {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-danger-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    color: white;
}

/* System Info List */
.system-info-list {
    background: var(--gray-50);
    border-radius: 16px;
    overflow: hidden;
    margin-top: 1rem;
}

.info-item {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--gray-200);
}

.info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--gray-600);
}

.info-value {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--gray-900);
}

/* Alert Box */
.alert-modern {
    background: linear-gradient(135deg, #fee2e2 0%, #fff5f5 100%);
    border-radius: 16px;
    padding: 1rem;
    margin-top: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    border-left: 0;
}

.alert-modern i {
    font-size: 1.5rem;
    color: #ef4444;
}

.alert-content {
    flex: 1;
}

.alert-content strong {
    display: block;
    color: #991b1b;
    margin-bottom: 0.25rem;
}

.alert-content p {
    font-size: 0.8rem;
    color: #7f1a1a;
    margin: 0;
}

.alert-modern .btn-danger-modern {
    justify-content: center;
    text-align: center;
}

/* Responsive */
@media (max-width: 992px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .card-body-modern {
        padding: 1.25rem;
    }
    
    .switch-wrapper {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2>
                    <i class="fas fa-cog me-2"></i> 
                    System Settings
                </h2>
                <p>Configure system-wide settings, security preferences, and maintenance options</p>
            </div>
        </div>
    </div>

    <div class="settings-grid">
        <!-- General Settings -->
        <div class="settings-card">
            <div class="card-header-modern">
                <i class="fas fa-globe"></i>
                <h5>General Settings</h5>
            </div>
            <div class="card-body-modern">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Site Name</label>
                        <input type="text" name="site_name" class="form-control-modern" value="<?php echo htmlspecialchars($siteName); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default Timezone</label>
                        <select name="timezone" class="form-select-modern">
                            <option value="UTC" <?php echo $currentTimezone == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                            <option value="Asia/Manila" <?php echo $currentTimezone == 'Asia/Manila' ? 'selected' : ''; ?>>Asia/Manila</option>
                            <option value="America/New_York" <?php echo $currentTimezone == 'America/New_York' ? 'selected' : ''; ?>>America/New York</option>
                            <option value="Europe/London" <?php echo $currentTimezone == 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                            <option value="Asia/Tokyo" <?php echo $currentTimezone == 'Asia/Tokyo' ? 'selected' : ''; ?>>Asia/Tokyo</option>
                        </select>
                    </div>
                    <button type="submit" name="update_general" class="btn-primary-modern w-100">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- Security Settings -->
        <div class="settings-card">
            <div class="card-header-modern">
                <i class="fas fa-shield-alt"></i>
                <h5>Security Settings</h5>
            </div>
            <div class="card-body-modern">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Session Timeout (minutes)</label>
                        <input type="number" name="session_timeout" class="form-control-modern" value="<?php echo htmlspecialchars($sessionTimeout); ?>" min="5" max="480">
                        <span class="form-text">Time before inactive users are logged out</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password Expiry (days)</label>
                        <input type="number" name="password_expiry" class="form-control-modern" value="<?php echo htmlspecialchars($passwordExpiry); ?>" min="0" max="365">
                        <span class="form-text">0 = never expire</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Login Attempts</label>
                        <input type="number" name="max_login_attempts" class="form-control-modern" value="<?php echo htmlspecialchars($maxLoginAttempts); ?>" min="3" max="10">
                        <span class="form-text">Failed attempts before account lockout</span>
                    </div>
                    <button type="submit" name="update_security" class="btn-primary-modern w-100">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- Notification Settings -->
        <div class="settings-card">
            <div class="card-header-modern">
                <i class="fas fa-bell"></i>
                <h5>Notification Settings</h5>
            </div>
            <div class="card-body-modern">
                <form method="POST">
                    <div class="switch-wrapper">
                        <div class="switch-info">
                            <label>Task Reminders</label>
                            <small>Receive reminders for upcoming tasks</small>
                        </div>
                        <label class="form-switch-modern">
                            <input type="checkbox" name="task_reminders" <?php echo ($notificationSettings['task_reminders'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                    
                    <div class="switch-wrapper">
                        <div class="switch-info">
                            <label>Project Updates</label>
                            <small>Receive notifications for project status changes</small>
                        </div>
                        <label class="form-switch-modern">
                            <input type="checkbox" name="project_updates" <?php echo ($notificationSettings['project_updates'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                    
                    <button type="submit" name="update_notifications" class="btn-primary-modern w-100 mt-3">
                        <i class="fas fa-save"></i> Save Preferences
                    </button>
                </form>
            </div>
        </div>

        <!-- System Maintenance -->
        <div class="settings-card">
            <div class="card-header-modern">
                <i class="fas fa-tools"></i>
                <h5>System Maintenance</h5>
            </div>
            <div class="card-body-modern">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Database Backup</label>
                        <p class="form-text" style="margin-bottom: 1rem;">Create a full backup of the database. This may take a few moments.</p>
                        <button type="submit" name="backup_database" class="btn-warning-modern w-100" onclick="return confirm('Create database backup? This may take a moment.')">
                            <i class="fas fa-database"></i> Create Backup
                        </button>
                    </div>
                </form>
                
                <hr style="margin: 1.5rem 0;">
                
                <div class="form-group">
                    <label class="form-label">System Information</label>
                    <div class="system-info-list">
                        <div class="info-item">
                            <span class="info-label">PHP Version</span>
                            <span class="info-value"><?php echo phpversion(); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">MySQL Version</span>
                            <span class="info-value"><?php echo $conn->server_info; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Server OS</span>
                            <span class="info-value"><?php echo php_uname(); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Upload Max Size</span>
                            <span class="info-value"><?php echo ini_get('upload_max_filesize'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Memory Limit</span>
                            <span class="info-value"><?php echo ini_get('memory_limit'); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="alert-modern">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div class="alert-content">
                        <strong>Clear System Cache</strong>
                        <p>Clear all temporary files and cached data</p>
                        <button class="btn-danger-modern mt-2" style="padding: 0.4rem 1rem; font-size: 0.8rem;" onclick="clearCache()">
                            Clear All Cache
                        </button>
                    </div>
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
                    alert('✓ Cache cleared successfully');
                } else {
                    alert('✗ Error: ' + result.error);
                }
            },
            error: function() {
                alert('✗ Error clearing cache');
            }
        });
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
