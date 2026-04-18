<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'erni_pms');

define('BASE_URL', 'http://localhost/erni-pms/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

function getConnection(): mysqli
{
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    } catch (mysqli_sql_exception $e) {
        error_log('Database connection failed: ' . $e->getMessage());

        $message = 'Database connection error. Please contact the administrator.';
        if (str_contains(strtolower($e->getMessage()), 'unknown database')) {
            $message = "Database '" . DB_NAME . "' was not found. Import database.sql first.";
        }

        die($message);
    }

    $conn->set_charset('utf8mb4');

    return $conn;
}
?>
