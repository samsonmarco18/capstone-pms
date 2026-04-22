<?php
/**
 * ERNI PMS - Environment Configuration
 * Supports: Local development, InfinityFree, Render, and other platforms
 */

// Optional per-developer overrides. This file should stay untracked.
$localOverrides = [];
$localConfigFile = __DIR__ . '/.env.local.php';
if (is_file($localConfigFile)) {
    $loadedOverrides = require $localConfigFile;
    if (is_array($loadedOverrides)) {
        $localOverrides = $loadedOverrides;
    }
}

// Detect environment - check multiple indicators
$host = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
$isCliMode = php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server';
$isRender = !empty(getenv('RENDER'));
$appRootPath = realpath(dirname(__DIR__)) ?: dirname(__DIR__);

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
    global $localOverrides;

    if (array_key_exists($varName, $localOverrides)) {
        return $localOverrides[$varName];
    }

    $value = getenv($varName);
    return ($value !== false && $value !== '') ? $value : $default;
}

function parseDatabaseUrl(?string $databaseUrl): array
{
    $databaseUrl = trim((string) $databaseUrl);
    if ($databaseUrl === '') {
        return [];
    }

    $parts = parse_url($databaseUrl);
    if (!is_array($parts)) {
        return [];
    }

    return [
        'driver' => match (strtolower((string) ($parts['scheme'] ?? ''))) {
            'postgres', 'postgresql', 'pgsql' => 'pgsql',
            default => 'mysql',
        },
        'host' => (string) ($parts['host'] ?? ''),
        'port' => (int) ($parts['port'] ?? 0),
        'user' => rawurldecode((string) ($parts['user'] ?? '')),
        'pass' => rawurldecode((string) ($parts['pass'] ?? '')),
        'name' => rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/')),
    ];
}

$databaseUrlConfig = parseDatabaseUrl((string) getEnvOrDefault('DATABASE_URL', getEnvOrDefault('DB_URL', '')));
$resolvedDbDriver = strtolower((string) getEnvOrDefault('DB_DRIVER', $databaseUrlConfig['driver'] ?? 'mysql'));
if (!in_array($resolvedDbDriver, ['mysql', 'pgsql'], true)) {
    $resolvedDbDriver = 'mysql';
}

define('DB_DRIVER', $resolvedDbDriver);

if ($isRender) {
    // RENDER CLOUD DEPLOYMENT
    // Environment variables set by render.yaml
    define('DB_HOST', getEnvOrDefault('DB_HOST', $databaseUrlConfig['host'] ?? ''));
    define('DB_USER', getEnvOrDefault('DB_USER', $databaseUrlConfig['user'] ?? ''));
    define('DB_PASS', getEnvOrDefault('DB_PASS', $databaseUrlConfig['pass'] ?? ''));
    define('DB_NAME', getEnvOrDefault('DB_NAME', $databaseUrlConfig['name'] ?? 'erni_pms'));
    define('APP_ENV', 'production');
    define('DEBUG_MODE', false);
} elseif ($isProduction && !$isLocalhost) {
    // PRODUCTION MUST SUPPLY SECRETS VIA ENVIRONMENT VARIABLES
    define('DB_HOST', getEnvOrDefault('DB_HOST', $databaseUrlConfig['host'] ?? ''));
    define('DB_USER', getEnvOrDefault('DB_USER', $databaseUrlConfig['user'] ?? ''));
    define('DB_PASS', getEnvOrDefault('DB_PASS', $databaseUrlConfig['pass'] ?? ''));
    define('DB_NAME', getEnvOrDefault('DB_NAME', $databaseUrlConfig['name'] ?? ''));
    define('APP_ENV', 'production');
    define('DEBUG_MODE', false);
} else {
    // LOCAL DEVELOPMENT
    define('DB_HOST', getEnvOrDefault('DB_HOST', $databaseUrlConfig['host'] ?? 'localhost'));
    define('DB_USER', getEnvOrDefault('DB_USER', $databaseUrlConfig['user'] ?? 'root'));
    define('DB_PASS', getEnvOrDefault('DB_PASS', $databaseUrlConfig['pass'] ?? ''));
    define('DB_NAME', getEnvOrDefault('DB_NAME', $databaseUrlConfig['name'] ?? 'erni_pms'));
    define('APP_ENV', getEnvOrDefault('APP_ENV', 'development'));
    define('DEBUG_MODE', getEnvOrDefault('DEBUG_MODE', '1') !== '0');
}

define('DB_PORT', (int) getEnvOrDefault('DB_PORT', $databaseUrlConfig['port'] ?? (DB_DRIVER === 'pgsql' ? 5432 : 3306)));

// ==============================================
// APPLICATION CONFIGURATION
// ==============================================

function detectAppUrl(string $appRootPath, bool $isRender): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || $isRender;

    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $normalizedAppRoot = str_replace('\\', '/', $appRootPath);
    $documentRootInput = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentRoot = $documentRootInput !== ''
        ? str_replace('\\', '/', realpath($documentRootInput) ?: $documentRootInput)
        : '';

    $basePath = '/' . basename($normalizedAppRoot) . '/';
    if ($documentRoot !== '' && str_starts_with(strtolower($normalizedAppRoot), strtolower($documentRoot))) {
        $relativePath = trim(substr($normalizedAppRoot, strlen($documentRoot)), '/');
        $basePath = ($relativePath !== '' ? '/' . $relativePath : '') . '/';
    }

    return $scheme . '://' . $host . $basePath;
}

// App URL - with fallback detection
$appUrl = getEnvOrDefault('APP_URL', null);
if (!$appUrl) {
    $appUrl = detectAppUrl($appRootPath, $isRender);
}
define('APP_URL', rtrim($appUrl, '/') . '/');

// Security Configuration
define('SECURE_COOKIES', getEnvOrDefault('SECURE_COOKIES', $isProduction ? '1' : '0') === '1');
define('SESSION_TIMEOUT', (int) getEnvOrDefault('SESSION_TIMEOUT', 3600));
define('UPLOAD_MAX_SIZE', (int) getEnvOrDefault('UPLOAD_MAX_SIZE', 52428800)); // 50MB

// ==============================================
// PATH CONFIGURATION
// ==============================================

define('APP_ROOT_PATH', $appRootPath);
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
