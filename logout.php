<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

requireLogin();

// Log the logout action
logActivity($_SESSION['user_id'], 'logout', 'users', $_SESSION['user_id']);

// Destroy session
session_destroy();

// Redirect to login
header('Location: ' . BASE_URL . '/login.php');
exit();
