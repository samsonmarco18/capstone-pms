<?php
// ==============================
// DATABASE CONFIGURATION
// ==============================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // change if you have password
define('DB_NAME', 'erni_pms');

// ==============================
// CREATE DATABASE CONNECTION
// ==============================
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    // ❌ Old: die()
//  if ($conn->connect_error) {
//      die("Connection failed: " . $conn->connect_error);
//  }
if ($conn) {
    $success = "✅ Database connected successfully";
} else {
    $error = "❌ Database connection failed";
}

    // ✅ Better error handling
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Database connection error. Please contact administrator.");
    }

    // ✅ Fix charset (IMPORTANT)
    $conn->set_charset("utf8mb4");

    return $conn;
}

// ==============================
// SESSION HANDLING
// ==============================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==============================
// BASE URL
// ==============================
// ⚠️ Make sure this matches your project folder
define('BASE_URL', 'http://localhost/erni-pms/');
?>

