<?php
require_once 'includes/auth.php';

// Redirect to appropriate dashboard based on role
if (isLoggedIn()) {
    $user = getCurrentUser();
    $role = $user['role'];
    
    switch($role) {
        case 'admin':
            header('Location: modules/admin/dashboard.php');
            break;
        case 'sales':
            header('Location: modules/sales/dashboard.php');
            break;
        case 'architect':
            header('Location: modules/architect/dashboard.php');
            break;
        case 'sul':
            header('Location: modules/sul/dashboard.php');
            break;
        case 'expert':
            header('Location: modules/expert/dashboard.php');
            break;
        case 'developer':
            header('Location: modules/developer/dashboard.php');
            break;
        default:
            header('Location: login.php');
    }
    exit();
} else {
    header('Location: login.php');
}
?>