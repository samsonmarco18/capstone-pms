<?php
/**
 * ERNI PMS - Environment Configuration
 */

// Detect environment - check multiple indicators
$host = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
$isCliMode = php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server';

// Determine if running locally
$isLocalhost = $isCliMode || 
               strpos($host, 'localhost') !== false || 
               strpos($host, '127.0.0.1') !== false ||
               strpos($host, '::1') !== false;

// Determine if running on production
$isProduction = !$isCliMode && 
                (strpos($host, 'infinityfree.com') !== false || 
                 strpos($host, 'ernie.is-great.org') !== false);

// Database Configuration - Conditional based on environment
if ($isLocalhost && !$isProduction) {
    // LOCAL DEVELOPMENT
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'erni_pms');
    define('APP_ENV', 'development');
    define('DEBUG_MODE', true);
} else {
    // PRODUCTION (InfinityFree)
    define('DB_HOST', 'sql209.infinityfree.com');
    define('DB_USER', 'if0_41295176');
    define('DB_PASS', 'Marco01182005');
    define('DB_NAME', 'if0_41295176_ernie');
    define('APP_ENV', 'production');
    define('DEBUG_MODE', false);
}

define('DB_PORT', 3306);

// Application Configuration
define('APP_URL', (strpos($host, 'localhost') !== false ? 'http' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Security
define('SECURE_COOKIES', true);
define('SESSION_TIMEOUT', 3600);
define('UPLOAD_MAX_SIZE', 52428800); // 50MB

// Paths
define('APP_ROOT_PATH', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
define('UPLOAD_PATH', APP_ROOT_PATH . '/uploads/');
define('GIT_PATH', APP_ROOT_PATH . '/git/');
define('GIT_WORKSPACES_PATH', APP_ROOT_PATH . '/git-workspaces/');

// Git Configuration
define('GIT_BINARY', 'git');
define('GIT_USER_NAME', 'ERNI PMS');
define('GIT_USER_EMAIL', 'noreply@erni-pms.local');

// Error Logging
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT_PATH . '/logs/error.log');

// Timezone
date_default_timezone_set('Asia/Manila');

// Ensure directories exist
@mkdir(UPLOAD_PATH, 0755, true);
@mkdir(GIT_PATH, 0755, true);
@mkdir(GIT_WORKSPACES_PATH, 0755, true);
@mkdir(dirname(ini_get('error_log')), 0755, true);
?>
