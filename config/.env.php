<?php
/**
 * ERNI PMS - Environment Configuration
 * Supports: Local development, InfinityFree, Render, and other platforms
 */

// Detect environment - check multiple indicators
$host = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
$isCliMode = php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server';
$isRender = !empty(getenv('RENDER'));

// Determine if running locally
$isLocalhost = $isCliMode || 
               strpos($host, 'localhost') !== false || 
               strpos($host, '127.0.0.1') !== false ||
               strpos($host, '::1') !== false;

// Determine deployment platform
$isProduction = !$isCliMode && 
                (strpos($host, 'infinityfree.com') !== false || 
                 strpos($host, 'ernie.is-great.org') !== false ||
                 strpos($host, 'onrender.com') !== false ||
                 $isRender);

// ==============================================
// DATABASE CONFIGURATION
// Priority: Environment Variables > Hardcoded > Defaults
// ==============================================

// Helper function to get env variable with fallback
function getEnvOrDefault($varName, $default = null) {
    $value = getenv($varName);
    return ($value !== false) ? $value : $default;
}

if ($isRender) {
    // RENDER CLOUD DEPLOYMENT
    // Environment variables set by render.yaml
    define('DB_HOST', getEnvOrDefault('DB_HOST', ''));
    define('DB_USER', getEnvOrDefault('DB_USER', ''));
    define('DB_PASS', getEnvOrDefault('DB_PASS', ''));
    define('DB_NAME', getEnvOrDefault('DB_NAME', 'erni_pms'));
    define('APP_ENV', 'production');
    define('DEBUG_MODE', false);
} elseif ($isProduction && !$isLocalhost) {
    // INFINITYFREE OR OTHER PRODUCTION
    define('DB_HOST', getEnvOrDefault('DB_HOST', 'sql209.infinityfree.com'));
    define('DB_USER', getEnvOrDefault('DB_USER', 'if0_41295176'));
    define('DB_PASS', getEnvOrDefault('DB_PASS', 'Marco01182005'));
    define('DB_NAME', getEnvOrDefault('DB_NAME', 'if0_41295176_ernie'));
    define('APP_ENV', 'production');
    define('DEBUG_MODE', false);
} else {
    // LOCAL DEVELOPMENT
    define('DB_HOST', getEnvOrDefault('DB_HOST', 'localhost'));
    define('DB_USER', getEnvOrDefault('DB_USER', 'root'));
    define('DB_PASS', getEnvOrDefault('DB_PASS', ''));
    define('DB_NAME', getEnvOrDefault('DB_NAME', 'erni_pms'));
    define('APP_ENV', getEnvOrDefault('APP_ENV', 'development'));
    define('DEBUG_MODE', getEnvOrDefault('DEBUG_MODE', '1') !== '0');
}

define('DB_PORT', (int) getEnvOrDefault('DB_PORT', 3306));

// ==============================================
// APPLICATION CONFIGURATION
// ==============================================

// App URL - with fallback detection
$appUrl = getEnvOrDefault('APP_URL', null);
if (!$appUrl) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || $isRender; // Render uses HTTPS by default
    
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $appUrl = $scheme . '://' . $host;
}
define('APP_URL', rtrim($appUrl, '/') . '/');

// Security Configuration
define('SECURE_COOKIES', getEnvOrDefault('SECURE_COOKIES', $isProduction ? '1' : '0') === '1');
define('SESSION_TIMEOUT', (int) getEnvOrDefault('SESSION_TIMEOUT', 3600));
define('UPLOAD_MAX_SIZE', (int) getEnvOrDefault('UPLOAD_MAX_SIZE', 52428800)); // 50MB

// ==============================================
// PATH CONFIGURATION
// ==============================================

define('APP_ROOT_PATH', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
define('UPLOAD_PATH', APP_ROOT_PATH . '/uploads/');
define('GIT_PATH', APP_ROOT_PATH . '/git/');
define('GIT_WORKSPACES_PATH', APP_ROOT_PATH . '/git-workspaces/');

// ==============================================
// GIT CONFIGURATION
// ==============================================

define('GIT_BINARY', getEnvOrDefault('GIT_BINARY', 'git'));
define('GIT_USER_NAME', getEnvOrDefault('GIT_USER_NAME', 'ERNI PMS'));
define('GIT_USER_EMAIL', getEnvOrDefault('GIT_USER_EMAIL', 'noreply@erni-pms.local'));

// ==============================================
// CACHING CONFIGURATION
// ==============================================

define('CACHE_ENABLED', getEnvOrDefault('CACHE_ENABLED', $isProduction ? '1' : '0') === '1');
define('CACHE_DURATION', (int) getEnvOrDefault('CACHE_DURATION', 3600));

// ==============================================
// LOGGING CONFIGURATION
// ==============================================

define('LOG_ERRORS', true);
define('LOG_PATH', APP_ROOT_PATH . '/logs/');

// Ensure log directory exists
@mkdir(LOG_PATH, 0755, true);


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
