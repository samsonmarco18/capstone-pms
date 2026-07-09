<?php
require_once '../../includes/auth.php';
require_once '../../includes/app-settings.php';
require_once '../../includes/mailer.php';
requireRole('admin');

$conn = getConnection();
$user = getCurrentUser();
$generalSettings = getAppSettings(['company_name', 'notification_email', 'timezone']);

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

function backupSqlIdentifier(string $identifier, string $driver): string
{
    $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier) ?? '';
    return $driver === 'pgsql'
        ? '"' . str_replace('"', '""', $identifier) . '"'
        : '`' . str_replace('`', '``', $identifier) . '`';
}

function backupSqlValue(mixed $value, string $type, string $driver): string
{
    if ($value === null) {
        return 'NULL';
    }

    $normalizedType = strtolower($type);
    if (in_array($normalizedType, [
        'int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint',
        'decimal', 'numeric', 'float', 'double', 'real',
    ], true) && is_numeric($value)) {
        return (string) $value;
    }

    if (in_array($normalizedType, ['boolean', 'bool'], true)) {
        $truthy = in_array(strtolower((string) $value), ['1', 't', 'true', 'yes'], true);
        return $driver === 'pgsql' ? ($truthy ? 'TRUE' : 'FALSE') : ($truthy ? '1' : '0');
    }

    $stringValue = (string) $value;
    if ($driver === 'pgsql') {
        return "'" . str_replace("'", "''", $stringValue) . "'";
    }

    return "'" . strtr($stringValue, [
        "\\" => "\\\\",
        "'" => "\\'",
        "\0" => "\\0",
        "\n" => "\\n",
        "\r" => "\\r",
        "\x1a" => "\\Z",
    ]) . "'";
}

function backupFetchRows(mysqli|PgsqlMysqliCompatConnection $conn, string $sql): array
{
    $result = $conn->query($sql);
    if ($result instanceof mysqli_result || $result instanceof PgsqlMysqliCompatResult) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    return [];
}

function backupGetTables(mysqli|PgsqlMysqliCompatConnection $conn): array
{
    $sql = DB_DRIVER === 'pgsql'
        ? "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name"
        : "SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";

    return array_values(array_filter(array_map(
        static fn(array $row): string => (string) ($row['table_name'] ?? ''),
        backupFetchRows($conn, $sql)
    )));
}

function backupGetColumns(mysqli|PgsqlMysqliCompatConnection $conn, string $table): array
{
    $escapedTable = $conn->real_escape_string($table);
    $sql = DB_DRIVER === 'pgsql'
        ? "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{$escapedTable}' ORDER BY ordinal_position"
        : "SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$escapedTable}' ORDER BY ORDINAL_POSITION";

    return backupFetchRows($conn, $sql);
}

function backupBuildDataDump(mysqli|PgsqlMysqliCompatConnection $conn, string $targetDriver): string
{
    $timestamp = date('Y-m-d H:i:s P');
    $tables = backupGetTables($conn);
    $lines = [
        '-- ERNI PMS database data backup',
        '-- Source driver: ' . DB_DRIVER,
        '-- Target format: ' . ($targetDriver === 'pgsql' ? 'PostgreSQL' : 'MySQL'),
        '-- Generated at: ' . $timestamp,
        '',
    ];

    if ($targetDriver === 'pgsql') {
        $lines[] = 'BEGIN;';
        $lines[] = 'SET standard_conforming_strings = on;';
    } else {
        $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $lines[] = 'START TRANSACTION;';
    }
    $lines[] = '';

    foreach ($tables as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            continue;
        }

        $columns = backupGetColumns($conn, $table);
        if ($columns === []) {
            continue;
        }

        $columnNames = array_map(static fn(array $column): string => (string) $column['column_name'], $columns);
        $columnTypes = [];
        foreach ($columns as $column) {
            $columnTypes[(string) $column['column_name']] = (string) $column['data_type'];
        }

        $sourceTable = backupSqlIdentifier($table, DB_DRIVER);
        $rows = backupFetchRows($conn, 'SELECT * FROM ' . $sourceTable);

        $lines[] = '-- Table: ' . $table;
        if ($rows === []) {
            $lines[] = '-- No rows';
            $lines[] = '';
            continue;
        }

        $targetTable = backupSqlIdentifier($table, $targetDriver);
        $targetColumns = implode(', ', array_map(
            static fn(string $column): string => backupSqlIdentifier($column, $targetDriver),
            $columnNames
        ));

        foreach ($rows as $row) {
            $values = [];
            foreach ($columnNames as $columnName) {
                $values[] = backupSqlValue($row[$columnName] ?? null, $columnTypes[$columnName] ?? '', $targetDriver);
            }

            $lines[] = 'INSERT INTO ' . $targetTable . ' (' . $targetColumns . ') VALUES (' . implode(', ', $values) . ');';
        }
        $lines[] = '';
    }

    if ($targetDriver === 'pgsql') {
        $lines[] = 'COMMIT;';
    } else {
        $lines[] = 'COMMIT;';
        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
    }

    return implode("\n", $lines) . "\n";
}

function backupSendDualFormatDownload(mysqli|PgsqlMysqliCompatConnection $conn, array $user): void
{
    if (!class_exists('ZipArchive')) {
        $_SESSION['error'] = 'Backup failed because the PHP zip extension is not enabled.';
        $_SESSION['settings_active_tab'] = 'maintenance';
        header('Location: settings.php');
        exit();
    }

    $timestamp = date('Y-m-d_H-i-s');
    $baseName = 'erni_pms_backup_' . $timestamp;
    $zipPath = tempnam(sys_get_temp_dir(), 'erni_pms_backup_');
    if ($zipPath === false) {
        throw new RuntimeException('Unable to create a temporary backup file.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to open the backup archive.');
    }

    $zip->addFromString($baseName . '_mysql_data.sql', backupBuildDataDump($conn, 'mysql'));
    $zip->addFromString($baseName . '_postgresql_data.sql', backupBuildDataDump($conn, 'pgsql'));

    $mysqlSchema = realpath(__DIR__ . '/../../database.sql');
    if ($mysqlSchema !== false && is_file($mysqlSchema)) {
        $zip->addFile($mysqlSchema, 'schema_mysql_database.sql');
    }

    $postgresSchema = realpath(__DIR__ . '/../../database.postgres.sql');
    if ($postgresSchema !== false && is_file($postgresSchema)) {
        $zip->addFile($postgresSchema, 'schema_postgresql_database.sql');
    }

    $zip->addFromString('README.txt', "This archive contains live ERNI PMS database data in both MySQL and PostgreSQL INSERT formats.\nSchema files are included when available from the project root.\nGenerated at {$timestamp} from source driver " . DB_DRIVER . ".\n");
    $zip->close();

    logActivity((int) $user['id'], 'database_backup_download', 'system', 0, null, [
        'formats' => ['mysql', 'postgresql'],
        'source_driver' => DB_DRIVER,
    ]);

    if (function_exists('session_write_close')) {
        session_write_close();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $baseName . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($zipPath);
    @unlink($zipPath);
    exit();
}

// Handle settings updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_general'])) {
        $siteName = $_POST['site_name'];
        $timezone = $_POST['timezone'];
        
        $_SESSION['site_name'] = $siteName;
        
        $siteName = trim((string) ($_POST['site_name'] ?? 'ERNI PMS'));
        $siteEmail = trim((string) ($_POST['site_email'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Asia/Manila'));
        
        $_SESSION['site_name'] = $siteName;
        $_SESSION['site_email'] = $siteEmail;

        setAppSetting('company_name', $siteName, 'Company official name', 'system');
        setAppSetting('notification_email', $siteEmail, 'System notification email', 'system');
        setAppSetting('timezone', $timezone, 'Default system timezone', 'system');
        date_default_timezone_set($timezone);
        saveSettingValue($conn, 'company_name', $siteName, 'Company official name');
        saveSettingValue($conn, 'timezone', $timezone, 'Default system timezone');
        
        logActivity($user['id'], 'update_general_settings', 'settings', 0, null, $_POST);
        $_SESSION['success'] = "General settings updated successfully";
        $_SESSION['settings_active_tab'] = 'general';
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
        $_SESSION['settings_active_tab'] = 'security';
    }
    
    if (isset($_POST['update_notifications'])) {
        $taskReminders = isset($_POST['task_reminders']) ? 1 : 0;
        $projectUpdates = isset($_POST['project_updates']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE users SET task_reminders=?, project_updates=? WHERE id=?");
        $stmt->bind_param("iii", $taskReminders, $projectUpdates, $user['id']);
        $stmt->execute();
        
        logActivity($user['id'], 'update_notification_settings', 'users', $user['id'], null, $_POST);
        $_SESSION['success'] = "Notification settings updated successfully";
        $_SESSION['settings_active_tab'] = 'notifications';
    }

    if (isset($_POST['update_mail_settings'])) {
        $mailEnabled = isset($_POST['mail_enabled']) ? '1' : '0';
        $mailDeliveryMethod = strtolower(trim((string) ($_POST['mail_delivery_method'] ?? 'smtp')));
        $mailHost = trim((string) ($_POST['mail_host'] ?? 'smtp.gmail.com'));
        $mailPort = trim((string) ($_POST['mail_port'] ?? '587'));
        $mailEncryption = trim((string) ($_POST['mail_encryption'] ?? 'tls'));
        $mailUsername = trim((string) ($_POST['mail_username'] ?? ''));
        $mailPassword = trim((string) ($_POST['mail_password'] ?? ''));
        $mailFromEmail = trim((string) ($_POST['mail_from_email'] ?? $mailUsername));
        $mailFromName = trim((string) ($_POST['mail_from_name'] ?? 'ERNI PMS'));
        $gmailClientId = trim((string) ($_POST['gmail_client_id'] ?? ''));
        $gmailClientSecret = trim((string) ($_POST['gmail_client_secret'] ?? ''));
        $gmailRefreshToken = trim((string) ($_POST['gmail_refresh_token'] ?? ''));
        $gmailUser = trim((string) ($_POST['gmail_user'] ?? $mailUsername));

        if (!in_array($mailDeliveryMethod, ['smtp', 'gmail_api'], true)) {
            $mailDeliveryMethod = 'smtp';
        }

        $normalizedMailHost = strtolower($mailHost);
        if (in_array($normalizedMailHost, ['smtp.gmail.com', 'gmail-smtp.l.google.com'], true) && filter_var($mailUsername, FILTER_VALIDATE_EMAIL)) {
            $mailFromEmail = $mailUsername;
        }

        if ($gmailUser === '') {
            $gmailUser = $mailUsername;
        }

        $saved = setAppSetting('mail_enabled', $mailEnabled, 'Enable email delivery', 'system')
            && setAppSetting('mail_delivery_method', $mailDeliveryMethod, 'Mail delivery method', 'system')
            && setAppSetting('mail_host', $mailHost, 'SMTP server host', 'system')
            && setAppSetting('mail_port', $mailPort, 'SMTP server port', 'system')
            && setAppSetting('mail_encryption', $mailEncryption, 'SMTP transport security', 'system')
            && setAppSetting('mail_username', $mailUsername, 'SMTP username', 'system')
            && setAppSetting('mail_from_email', $mailFromEmail, 'Mail sender address', 'system')
            && setAppSetting('mail_from_name', $mailFromName, 'Mail sender name', 'system')
            && setAppSetting('gmail_client_id', $gmailClientId, 'Gmail API OAuth client ID', 'system')
            && setAppSetting('gmail_user', $gmailUser, 'Gmail API sender user', 'system');

        if ($mailPassword !== '') {
            $saved = $saved && setAppSetting('mail_password', $mailPassword, 'SMTP app password', 'system');
        }

        if ($gmailClientSecret !== '') {
            $saved = $saved && setAppSetting('gmail_client_secret', $gmailClientSecret, 'Gmail API OAuth client secret', 'system');
        }

        if ($gmailRefreshToken !== '') {
            $saved = $saved && setAppSetting('gmail_refresh_token', $gmailRefreshToken, 'Gmail API OAuth refresh token', 'system');
        }

        logActivity($user['id'], 'update_mail_settings', 'settings', 0, null, [
            'mail_enabled' => $mailEnabled,
            'mail_delivery_method' => $mailDeliveryMethod,
            'mail_host' => $mailHost,
            'mail_port' => $mailPort,
            'mail_encryption' => $mailEncryption,
            'mail_username' => $mailUsername,
            'mail_from_email' => $mailFromEmail,
            'mail_from_name' => $mailFromName,
            'mail_password_updated' => $mailPassword !== '',
            'gmail_client_id_updated' => $gmailClientId !== '',
            'gmail_client_secret_updated' => $gmailClientSecret !== '',
            'gmail_refresh_token_updated' => $gmailRefreshToken !== '',
            'gmail_user' => $gmailUser,
        ]);

        $_SESSION[$saved ? 'success' : 'error'] = $saved
            ? 'Gmail mail settings saved successfully'
            : 'Failed to save Gmail mail settings';
        $_SESSION['settings_active_tab'] = 'mail';
    }

    if (isset($_POST['send_test_email'])) {
        $testEmail = trim((string) ($_POST['test_email'] ?? (string) ($user['email'] ?? '')));
        $result = sendSystemEmail(
            $testEmail,
            'ERNI PMS Gmail Integration Test',
            '<div style="font-family:Arial,sans-serif;padding:24px;"><h2 style="color:#1f6392;">ERNI PMS Gmail Test</h2><p>Your Gmail SMTP integration is working.</p><p>This message confirms the system can now send email through your configured Google account.</p></div>',
            "ERNI PMS Gmail Test\n\nYour Gmail SMTP integration is working."
        );
        $errorDetail = trim((string) ($result['error'] ?? ''));
        if (!$result['success'] && $errorDetail === '') {
            $mailIssues = getMailConfigIssues();
            $errorDetail = $mailIssues !== []
                ? 'Mail settings are incomplete: ' . implode(', ', $mailIssues) . '.'
                : 'SMTP failed without a detailed server message. Check Render Logs and verify MAIL_USERNAME, MAIL_PASSWORD, Gmail App Password access, and outbound SMTP connectivity.';
        }

        logActivity($user['id'], 'send_test_email', 'settings', 0, null, [
            'test_email' => $testEmail,
            'success' => $result['success'],
            'error' => $errorDetail,
        ], $result['success'] ? 'success' : 'failed');

        $_SESSION[$result['success'] ? 'success' : 'error'] = $result['success']
            ? 'Test email sent successfully to ' . $testEmail
            : 'Test email failed: ' . $errorDetail;
        $_SESSION['settings_active_tab'] = 'mail';
    }
    
    if (isset($_POST['backup_database'])) {
        try {
            backupSendDualFormatDownload($conn, $user);
        } catch (Throwable $e) {
            error_log('Database backup failed: ' . $e->getMessage());
            $_SESSION['error'] = 'Backup failed. Please check the server logs and try again.';
            $_SESSION['settings_active_tab'] = 'maintenance';
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
$currentTimezone = $generalSettings['timezone'] ?? date_default_timezone_get();
$emailNotifications = $conn->query("SELECT email_notifications, task_reminders, project_updates FROM users WHERE id = {$user['id']}")->fetch_assoc();
$activeMailConfig = getMailConfig();
$mailConfigured = isMailConfigured();
$settingsActiveTab = (string) ($_SESSION['settings_active_tab'] ?? 'general');
$settingsFlashSuccess = (string) ($_SESSION['success'] ?? '');
$settingsFlashError = (string) ($_SESSION['error'] ?? '');
unset($_SESSION['settings_active_tab'], $_SESSION['success'], $_SESSION['error']);
if (!in_array($settingsActiveTab, ['general', 'mail', 'notifications', 'security', 'maintenance'], true)) {
    $settingsActiveTab = 'general';
}
$databaseVersion = 'Unavailable';
$databaseVersionResult = $conn->query(DB_DRIVER === 'pgsql' ? 'SELECT version() AS version' : 'SELECT VERSION() AS version');
if ($databaseVersionResult instanceof mysqli_result || $databaseVersionResult instanceof PgsqlMysqliCompatResult) {
    $databaseVersionRow = $databaseVersionResult->fetch_assoc();
    $databaseVersion = (string) ($databaseVersionRow['version'] ?? $databaseVersion);
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

/* Settings Layout */
.settings-layout {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    gap: 1.5rem;
    align-items: start;
}

.settings-nav-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 20px;
    box-shadow: var(--shadow-sm);
    padding: 0.75rem;
    position: sticky;
    top: 88px;
}

.settings-nav-card .nav-link {
    width: 100%;
    border: 0;
    border-radius: 14px;
    color: var(--gray-600);
    background: transparent;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    font-weight: 600;
    text-align: left;
    transition: all 0.18s ease;
}

.settings-nav-card .nav-link span:not(.nav-dot) {
    flex: 1;
}

.settings-nav-card .nav-link i {
    width: 18px;
    color: var(--theme-primary);
    text-align: center;
}

.settings-nav-card .nav-link:hover {
    background: var(--gray-50);
    color: var(--theme-primary);
}

.settings-nav-card .nav-link.active {
    background: linear-gradient(135deg, #e9f7ff 0%, #f3fbff 100%);
    color: var(--theme-primary);
    box-shadow: inset 0 0 0 1px rgba(31, 99, 146, 0.12);
}

.settings-content {
    min-width: 0;
}

.settings-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.card-header-modern {
    padding: 1.35rem 1.5rem;
    background: #ffffff;
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

.settings-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem 1.25rem;
}

.settings-form-grid .form-group {
    margin-bottom: 0;
}

.settings-form-grid .full-span {
    grid-column: 1 / -1;
}

.settings-actions {
    margin-top: 1.5rem;
    display: flex;
    justify-content: flex-end;
}

.settings-status {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.8rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
}

.settings-status.ready {
    background: #dcfce7;
    color: #15803d;
}

.settings-status.incomplete {
    background: #fee2e2;
    color: #b91c1c;
}

.nav-dot {
    width: 8px;
    height: 8px;
    flex: 0 0 8px;
    border-radius: 50%;
    background: var(--gray-300);
    margin-left: auto;
}

.nav-dot.ready {
    background: #16a34a;
}

.nav-dot.warning {
    background: #dc2626;
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
    border-radius: 12px;
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
    border-radius: 12px;
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
    border-radius: 12px;
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
    .settings-layout {
        grid-template-columns: 1fr;
    }

    .settings-nav-card {
        position: static;
    }

    .settings-nav-card .nav {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .card-body-modern {
        padding: 1.25rem;
    }

    .settings-form-grid {
        grid-template-columns: 1fr;
    }

    .settings-nav-card .nav {
        grid-template-columns: 1fr;
    }
    
    .switch-wrapper {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-nav-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-card {
    background: #071321 !important;
    border-color: rgba(138, 210, 255, 0.12) !important;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.32) !important;
    color: #d8e6f3 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    box-shadow: 0 16px 38px rgba(0, 0, 0, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-nav-card .nav-link,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .system-info-list,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content {
    background: #071321 !important;
    border-color: rgba(138, 210, 255, 0.12) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-nav-card .nav-link:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-nav-card .nav-link.active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .switch-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer {
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-nav-card .nav-link,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-text,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .switch-info small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-status,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-content p {
    color: #90aeca !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .switch-info label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-title {
    color: #d8e6f3 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-select {
    background: rgba(10, 24, 38, 0.98);
    border-color: rgba(138, 210, 255, 0.12);
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-modern:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select-modern:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-select:focus {
    background: rgba(10, 24, 38, 0.98);
    border-color: var(--theme-primary);
    color: #d8e6f3;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-nav-card .nav-link.active {
    box-shadow: inset 0 0 0 1px rgba(31, 99, 146, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-status.ready {
    background: rgba(21, 128, 61, 0.18);
    color: #94f1c6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .settings-status.incomplete {
    background: rgba(220, 38, 38, 0.15);
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .switch-slider {
    background-color: rgba(144, 174, 202, 0.32);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .switch-slider:before {
    background-color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .system-info-list {
    border: 1px solid rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern {
    background: linear-gradient(135deg, rgba(127, 29, 29, 0.34) 0%, rgba(69, 10, 10, 0.34) 100%);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern i {
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-content strong {
    color: #ffd0d0;
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

    <?php if ($settingsFlashSuccess !== '' || $settingsFlashError !== ''): ?>
        <div class="alert <?php echo $settingsFlashError !== '' ? 'alert-danger' : 'alert-success'; ?> shadow-sm" role="alert">
            <i class="fas <?php echo $settingsFlashError !== '' ? 'fa-circle-exclamation' : 'fa-check-circle'; ?> me-2"></i>
            <?php echo htmlspecialchars($settingsFlashError !== '' ? $settingsFlashError : $settingsFlashSuccess); ?>
        </div>
    <?php endif; ?>

    <div class="settings-layout">
        <aside class="settings-nav-card">
            <div class="nav flex-column nav-pills" id="settingsTabs" role="tablist" aria-orientation="vertical">
                <button class="nav-link <?php echo $settingsActiveTab === 'general' ? 'active' : ''; ?>" id="general-tab" data-bs-toggle="pill" data-bs-target="#general-panel" type="button" role="tab" aria-controls="general-panel" aria-selected="<?php echo $settingsActiveTab === 'general' ? 'true' : 'false'; ?>">
                    <i class="fas fa-globe"></i> <span>General</span>
                </button>
                <button class="nav-link <?php echo $settingsActiveTab === 'mail' ? 'active' : ''; ?>" id="mail-tab" data-bs-toggle="pill" data-bs-target="#mail-panel" type="button" role="tab" aria-controls="mail-panel" aria-selected="<?php echo $settingsActiveTab === 'mail' ? 'true' : 'false'; ?>">
                    <i class="fas fa-envelope-open-text"></i> <span>Gmail Mailer</span>
                    <span class="nav-dot <?php echo $mailConfigured ? 'ready' : 'warning'; ?>"></span>
                </button>
                <button class="nav-link <?php echo $settingsActiveTab === 'notifications' ? 'active' : ''; ?>" id="notifications-tab" data-bs-toggle="pill" data-bs-target="#notifications-panel" type="button" role="tab" aria-controls="notifications-panel" aria-selected="<?php echo $settingsActiveTab === 'notifications' ? 'true' : 'false'; ?>">
                    <i class="fas fa-bell"></i> <span>Notifications</span>
                </button>
                <button class="nav-link <?php echo $settingsActiveTab === 'security' ? 'active' : ''; ?>" id="security-tab" data-bs-toggle="pill" data-bs-target="#security-panel" type="button" role="tab" aria-controls="security-panel" aria-selected="<?php echo $settingsActiveTab === 'security' ? 'true' : 'false'; ?>">
                    <i class="fas fa-shield-alt"></i> <span>Security</span>
                </button>
                <button class="nav-link <?php echo $settingsActiveTab === 'maintenance' ? 'active' : ''; ?>" id="maintenance-tab" data-bs-toggle="pill" data-bs-target="#maintenance-panel" type="button" role="tab" aria-controls="maintenance-panel" aria-selected="<?php echo $settingsActiveTab === 'maintenance' ? 'true' : 'false'; ?>">
                    <i class="fas fa-tools"></i> <span>Maintenance</span>
                </button>
            </div>
        </aside>

        <div class="settings-content tab-content" id="settingsTabContent">
        <!-- General Settings -->
        <div class="tab-pane fade <?php echo $settingsActiveTab === 'general' ? 'show active' : ''; ?>" id="general-panel" role="tabpanel" aria-labelledby="general-tab">
        <div class="settings-card">
            <div class="card-header-modern">
                <i class="fas fa-globe"></i>
                <h5>General Settings</h5>
            </div>
            <div class="card-body-modern">
                <form method="POST">
                    <div class="settings-form-grid">
                    <div class="form-group">
                        <label class="form-label">Site Name</label>
                        <input type="text" name="site_name" class="form-control-modern" value="<?php echo htmlspecialchars($siteName); ?>" required>
                        <input type="text" name="site_name" class="form-control-modern" value="<?php echo htmlspecialchars($generalSettings['company_name'] ?? 'ERNI PMS'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Site Email</label>
                        <input type="email" name="site_email" class="form-control-modern" value="<?php echo htmlspecialchars($generalSettings['notification_email'] ?? 'admin@erni.com'); ?>" required>
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
                    </div>
                    <div class="settings-actions">
                    <button type="submit" name="update_general" class="btn-primary-modern">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    </div>
                </form>
            </div>
        </div>
        </div>

        <!-- Security Settings -->
        <div class="tab-pane fade <?php echo $settingsActiveTab === 'security' ? 'show active' : ''; ?>" id="security-panel" role="tabpanel" aria-labelledby="security-tab">
        <div class="settings-card">
            <div class="card-header-modern">
                <i class="fas fa-shield-alt"></i>
                <h5>Security Settings</h5>
            </div>
            <div class="card-body-modern">
                <form method="POST">
                    <div class="settings-form-grid">
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
                    </div>
                    <div class="settings-actions">
                    <button type="submit" name="update_security" class="btn-primary-modern">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    </div>
                </form>
            </div>
        </div>
        </div>

        <div class="tab-pane fade <?php echo $settingsActiveTab === 'mail' ? 'show active' : ''; ?>" id="mail-panel" role="tabpanel" aria-labelledby="mail-tab">
        <div class="settings-card">
            <div class="card-header-modern">
                <i class="fas fa-envelope-open-text"></i>
                <h5>Gmail Mailer</h5>
            </div>
            <div class="card-body-modern">
                <form method="POST">
                    <div class="switch-wrapper">
                        <div class="switch-info">
                            <label>Enable Email Sending</label>
                            <small>Turn on automatic Gmail delivery for system notifications</small>
                        </div>
                        <label class="form-switch-modern">
                            <input type="checkbox" name="mail_enabled" <?php echo ($activeMailConfig['enabled'] ?? false) ? 'checked' : ''; ?>>
                            <span class="switch-slider"></span>
                        </label>
                    </div>

                    <div class="settings-form-grid" style="margin-top: 1.25rem;">
                    <div class="form-group full-span">
                        <label class="form-label">Delivery Method</label>
                        <select name="mail_delivery_method" class="form-select-modern">
                            <option value="smtp" <?php echo ($activeMailConfig['delivery_method'] ?? 'smtp') === 'smtp' ? 'selected' : ''; ?>>Gmail SMTP</option>
                            <option value="gmail_api" <?php echo ($activeMailConfig['delivery_method'] ?? '') === 'gmail_api' ? 'selected' : ''; ?>>Gmail API over HTTPS</option>
                        </select>
                        <span class="form-text">Use Gmail API when the server blocks SMTP ports 587 and 465.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" name="mail_host" class="form-control-modern" value="<?php echo htmlspecialchars($activeMailConfig['host'] ?? 'smtp.gmail.com'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SMTP Port</label>
                        <input type="number" name="mail_port" class="form-control-modern" value="<?php echo htmlspecialchars((string) ($activeMailConfig['port'] ?? 587)); ?>" min="1" max="65535" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Encryption</label>
                        <select name="mail_encryption" class="form-select-modern">
                            <option value="tls" <?php echo ($activeMailConfig['encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                            <option value="ssl" <?php echo ($activeMailConfig['encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            <option value="none" <?php echo ($activeMailConfig['encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gmail Address</label>
                        <input type="email" name="mail_username" class="form-control-modern" value="<?php echo htmlspecialchars($activeMailConfig['username'] ?? ''); ?>" required>
                        <span class="form-text">Use the Gmail or Google Workspace address that will send the messages.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Google App Password</label>
                        <input type="password" name="mail_password" class="form-control-modern" placeholder="Leave blank to keep current saved password">
                        <span class="form-text">Use a Google App Password, not your normal Gmail password.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">From Email</label>
                        <input type="email" name="mail_from_email" class="form-control-modern" value="<?php echo htmlspecialchars($activeMailConfig['from_email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">From Name</label>
                        <input type="text" name="mail_from_name" class="form-control-modern" value="<?php echo htmlspecialchars($activeMailConfig['from_name'] ?? 'ERNI PMS'); ?>" required>
                    </div>
                    <div class="form-group full-span">
                        <hr style="margin: 0.25rem 0;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gmail API Client ID</label>
                        <input type="text" name="gmail_client_id" class="form-control-modern" value="<?php echo htmlspecialchars((string) ($activeMailConfig['gmail_api']['client_id'] ?? '')); ?>" placeholder="Google OAuth client ID">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gmail API Client Secret</label>
                        <input type="password" name="gmail_client_secret" class="form-control-modern" placeholder="Leave blank to keep current saved secret">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gmail API Refresh Token</label>
                        <input type="password" name="gmail_refresh_token" class="form-control-modern" placeholder="Leave blank to keep current saved token">
                        <span class="form-text">Must be generated with the Gmail send scope.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gmail API User</label>
                        <input type="email" name="gmail_user" class="form-control-modern" value="<?php echo htmlspecialchars((string) ($activeMailConfig['gmail_api']['user'] ?? ($activeMailConfig['username'] ?? ''))); ?>" placeholder="sender@gmail.com">
                    </div>
                    <div class="form-group full-span">
                        <span class="settings-status <?php echo $mailConfigured ? 'ready' : 'incomplete'; ?>">
                            <i class="fas <?php echo $mailConfigured ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                            <?php echo $mailConfigured ? 'Ready to send' : 'Incomplete configuration'; ?>
                        </span>
                    </div>
                    </div>
                    <div class="settings-actions">
                    <button type="submit" name="update_mail_settings" class="btn-primary-modern">
                        <i class="fas fa-save"></i> Save Mail Settings
                    </button>
                    </div>
                </form>

                <hr style="margin: 1.5rem 0;">

                <form method="POST">
                    <div class="settings-form-grid">
                    <div class="form-group">
                        <label class="form-label">Send Test Email To</label>
                        <input type="email" name="test_email" class="form-control-modern" value="<?php echo htmlspecialchars((string) ($user['email'] ?? '')); ?>" required>
                        <span class="form-text">Send yourself a quick test before relying on automatic notifications.</span>
                    </div>
                    <div class="form-group" style="align-self: end;">
                    <button type="submit" name="send_test_email" class="btn-warning-modern w-100">
                        <i class="fas fa-paper-plane"></i> Send Test Email
                    </button>
                    </div>
                    </div>
                </form>
            </div>
        </div>
        </div>

        <!-- Notification Settings -->
        <div class="tab-pane fade <?php echo $settingsActiveTab === 'notifications' ? 'show active' : ''; ?>" id="notifications-panel" role="tabpanel" aria-labelledby="notifications-tab">
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
                    
                    <div class="settings-actions">
                    <button type="submit" name="update_notifications" class="btn-primary-modern">
                        <i class="fas fa-save"></i> Save Preferences
                    </button>
                    </div>
                </form>
            </div>
        </div>
        </div>

        <!-- System Maintenance -->
        <div class="tab-pane fade <?php echo $settingsActiveTab === 'maintenance' ? 'show active' : ''; ?>" id="maintenance-panel" role="tabpanel" aria-labelledby="maintenance-tab">
        <div class="settings-card">
            <div class="card-header-modern">
                <i class="fas fa-tools"></i>
                <h5>System Maintenance</h5>
            </div>
            <div class="card-body-modern">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Database Backup</label>
                        <p class="form-text" style="margin-bottom: 1rem;">Download a ZIP backup with live database data in MySQL and PostgreSQL SQL formats.</p>
                        <button type="submit" name="backup_database" class="btn-warning-modern w-100" onclick="return confirm('Download database backup in MySQL and PostgreSQL formats? This may take a moment.')">
                            <i class="fas fa-database"></i> Download Backup
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
                            <span class="info-label">Database Version</span>
                            <span class="info-value"><?php echo htmlspecialchars($databaseVersion); ?></span>
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
