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

    $appRoot = str_replace('\\', '/', APP_ROOT_PATH);
    $documentRootInput = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentRoot = $documentRootInput !== ''
        ? str_replace('\\', '/', realpath($documentRootInput) ?: $documentRootInput)
        : '';
    $basePath = '/' . basename($appRoot) . '/';

    if ($documentRoot !== '' && str_starts_with(strtolower($appRoot), strtolower($documentRoot))) {
        $relativePath = trim(substr($appRoot, strlen($documentRoot)), '/');
        $basePath = ($relativePath !== '' ? '/' . $relativePath : '') . '/';
    }

    return $scheme . '://' . $host . $basePath;
}

define('BASE_URL', detectBaseUrl());

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

function getConnection(): mysqli|PgsqlMysqliCompatConnection
{
    static $conn = null;

    if ($conn instanceof mysqli || $conn instanceof PgsqlMysqliCompatConnection) {
        return $conn;
    }

    try {
        if (DB_DRIVER === 'pgsql') {
            $conn = new PgsqlMysqliCompatConnection(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        } else {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        }
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());

        $message = 'Database connection error. Please contact the administrator.';
        if (str_contains(strtolower($e->getMessage()), 'unknown database')) {
            $message = "Database '" . DB_NAME . "' was not found. Import database.sql first.";
        }

        die($message);
    }

    if (DB_DRIVER === 'mysql') {
        $conn->set_charset('utf8mb4');
    }

    return $conn;
}
?>
