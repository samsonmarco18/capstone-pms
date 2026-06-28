<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logoutUser();
}

header('Location: ' . BASE_URL . 'index.php');
exit();
