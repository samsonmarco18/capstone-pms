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

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        error_log('Database connection failed: ' . $conn->connect_error);
        die('Database connection error. Please contact the administrator.');
    }

    $conn->set_charset('utf8mb4');

    return $conn;
}
?>
