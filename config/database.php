<?php
declare(strict_types=1);

// Load environment configuration
require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/database-compat.php';

// Verify database constants are defined
if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_NAME')) {
    die('Database configuration not loaded. Check config/.env.php');
}

if (!defined('APP_ROOT_PATH')) {
    define('APP_ROOT_PATH', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
}

function detectBaseUrl(): string
{
    $configuredUrl = defined('APP_URL') ? trim((string) APP_URL) : '';
    if ($configuredUrl === '') {
        $configuredUrl = trim((string) (getenv('APP_URL') ?: ''));
    }
    if ($configuredUrl !== '') {
        return rtrim($configuredUrl, '/') . '/';
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    if (function_exists('getEnvOrDefault')) {
        $basePathOverride = trim((string) getEnvOrDefault('APP_BASE_PATH', ''));
        if ($basePathOverride !== '') {
            return $scheme . '://' . $host . '/' . trim($basePathOverride, '/') . '/';
        }
    }

    $appRoot = str_replace('\\', '/', APP_ROOT_PATH);
    $documentRootInput = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentRoot = $documentRootInput !== ''
        ? str_replace('\\', '/', realpath($documentRootInput) ?: $documentRootInput)
        : '';
    $basePath = '/';

    if ($documentRoot !== '' && str_starts_with(strtolower($appRoot), strtolower($documentRoot))) {
        $relativePath = trim(substr($appRoot, strlen($documentRoot)), '/');
        $basePath = ($relativePath !== '' ? '/' . $relativePath : '') . '/';
    } elseif (str_contains(strtolower($host), 'localhost') || str_contains(strtolower($host), '127.0.0.1') || str_contains(strtolower($host), '::1')) {
        $basePath = '/' . basename($appRoot) . '/';
    }

    return $scheme . '://' . $host . $basePath;
}

define('BASE_URL', detectBaseUrl());

function startConfiguredSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $baseUrlParts = parse_url(BASE_URL);
    $isHttps = (($baseUrlParts['scheme'] ?? '') === 'https')
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => SECURE_COOKIES || $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(
            0,
            '/; samesite=Lax',
            '',
            SECURE_COOKIES || $isHttps,
            true
        );
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', (SECURE_COOKIES || $isHttps) ? '1' : '0');
    session_start();
}

if (session_status() === PHP_SESSION_NONE) {
    startConfiguredSession();
}

date_default_timezone_set('Asia/Manila');

function getConnection(): mysqli|PgsqlMysqliCompatConnection
{
    static $conn = null;

    if ($conn instanceof mysqli || $conn instanceof PgsqlMysqliCompatConnection) {
        return $conn;
    }

    if (DB_DRIVER === 'pgsql' && (DB_HOST === '' || DB_USER === '' || DB_NAME === '')) {
        error_log('Postgres configuration is incomplete. Check Render environment variables.');
        die('Database configuration is incomplete. Ensure Render DATABASE_URL is configured.');
    }

    try {
        if (DB_DRIVER === 'pgsql') {
            $conn = new PgsqlMysqliCompatConnection(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, DB_SSLMODE);
        } else {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        error_log('Database connection failed: ' . $errorMessage);

        $message = 'Database connection error. Please contact the administrator.';
        $normalized = strtolower($errorMessage);

        if (str_contains($normalized, 'unknown database')) {
            $message = "Database '" . DB_NAME . "' was not found. Import database.sql first.";
        } elseif (str_contains($normalized, 'database "') && str_contains($normalized, '" does not exist')) {
            $message = "Database '" . DB_NAME . "' does not exist on Render yet. Check the Postgres resource and DATABASE_URL.";
        } elseif (str_contains($normalized, 'could not translate host name')) {
            $message = 'Database host could not be resolved. Check Render DATABASE_URL.';
        } elseif (str_contains($normalized, 'connection refused') || str_contains($normalized, 'no route to host')) {
            $message = 'Database server is unreachable. Check Render Postgres status and region.';
        } elseif (str_contains($normalized, 'could not find driver')) {
            $message = 'PostgreSQL PHP driver is missing in the container image.';
        }

        die($message);
    }

    if (DB_DRIVER === 'mysql') {
        $conn->set_charset('utf8mb4');
    }

    return $conn;
}

function dbColumnExists(mysqli|PgsqlMysqliCompatConnection $conn, string $table, string $column): bool
{
    $tableName = strtolower(trim($table));
    $columnName = strtolower(trim($column));

    if ($tableName === '' || $columnName === '') {
        return false;
    }

    $escapedTable = $conn->real_escape_string($tableName);
    $escapedColumn = $conn->real_escape_string($columnName);

    if (DB_DRIVER === 'pgsql') {
        $sql = "
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = '{$escapedTable}'
              AND column_name = '{$escapedColumn}'
            LIMIT 1
        ";
    } else {
        $sql = "
            SELECT COLUMN_NAME AS column_name
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$escapedTable}'
              AND COLUMN_NAME = '{$escapedColumn}'
            LIMIT 1
        ";
    }

    $result = $conn->query($sql);

    return $result instanceof mysqli_result
        ? $result->num_rows > 0
        : ($result instanceof PgsqlMysqliCompatResult && $result->num_rows > 0);
}

function dbTableExists(mysqli|PgsqlMysqliCompatConnection $conn, string $table): bool
{
    $tableName = strtolower(trim($table));

    if ($tableName === '') {
        return false;
    }

    $escapedTable = $conn->real_escape_string($tableName);

    if (DB_DRIVER === 'pgsql') {
        $sql = "
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = current_schema()
              AND table_name = '{$escapedTable}'
            LIMIT 1
        ";
    } else {
        $sql = "
            SELECT TABLE_NAME AS table_name
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$escapedTable}'
            LIMIT 1
        ";
    }

    $result = $conn->query($sql);

    return $result instanceof mysqli_result
        ? $result->num_rows > 0
        : ($result instanceof PgsqlMysqliCompatResult && $result->num_rows > 0);
}
?>
