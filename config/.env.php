<?php
/**
 * ERNI PMS - Environment Configuration
 */

// Detect if running on InfinityFree
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isInfinityFree = strpos($host, 'infinityfree.com') !== false || 
                  strpos($host, 'ernie.is-great.org') !== false ||
                  strpos($host, 'localhost') === false; // If not localhost, assume remote

// Database Configuration - INFINITYFREE CREDENTIALS
define('DB_HOST', 'sql209.infinityfree.com');
define('DB_USER', 'if0_41295176');
define('DB_PASS', 'Marco01182005');
define('DB_NAME', 'if0_41295176_ernie');
define('DB_PORT', 3306);

// Application Configuration
define('APP_ENV', 'production');
define('DEBUG_MODE', false);
define('APP_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

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
