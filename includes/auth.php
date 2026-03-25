<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current logged-in user
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    return $user;
}

/**
 * Check if user has specific role(s)
 */
function hasRole($roles) {
    if (!isLoggedIn()) return false;
    
    $user = getCurrentUser();
    if (!$user) return false;
    
    if (is_array($roles)) {
        return in_array($user['role'], $roles);
    }
    return $user['role'] === $roles;
}

/**
 * Check if user has multiple roles at once (AND condition)
 */
function hasAllRoles($roles) {
    if (!isLoggedIn()) return false;
    
    $user = getCurrentUser();
    if (!$user) return false;
    
    foreach ($roles as $role) {
        if ($user['role'] !== $role) return false;
    }
    return true;
}

/**
 * Require user to be logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . BASE_URL . '/login.php');
        exit();
    }
}

/**
 * Require user to have specific role(s)
 */
function requireRole($roles) {
    requireLogin();
    if (!hasRole($roles)) {
        http_response_code(403);
        header('Location: ' . BASE_URL . '/index.php');
        exit();
    }
}

/**
 * Log user activity in audit trail
 */
function logActivity($userId, $action, $tableName = null, $recordId = null, $oldData = null, $newData = null) {
    if (!$userId) return false;
    
    try {
        $conn = getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'success')
        ");
        
        if (!$stmt) {
            return false;
        }
        
        $oldDataJson = $oldData ? json_encode($oldData) : null;
        $newDataJson = $newData ? json_encode($newData) : null;
        
        $stmt->bind_param("issiisss", $userId, $action, $tableName, $recordId, $oldDataJson, $newDataJson, $ip, $userAgent);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notifications count
 */
function getUnreadNotificationsCount($userId) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] ?? 0;
}

/**
 * Create notification
 */
function createNotification($userId, $title, $message, $type = 'system', $relatedTable = null, $relatedId = null, $actionUrl = null) {
    try {
        $conn = getConnection();
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, notification_type, related_table, related_id, action_url) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("issssss", $userId, $title, $message, $type, $relatedTable, $relatedId, $actionUrl);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Exception $e) {
        error_log("Notification creation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark notification as read
 */
function markNotificationAsRead($notificationId) {
    try {
        $conn = getConnection();
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE, read_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $notificationId);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Logout user
 */
function logoutUser() {
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        logActivity($userId, 'logout', 'users', $userId);
    }
    
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit();
}

/**
 * Password hashing
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Get role display name
 */
function getRoleDisplayName($role) {
    $roleNames = [
        'admin' => 'Administrator',
        'sales' => 'Sales Manager',
        'architect' => 'System Architect',
        'sul' => 'Service Unit Leader',
        'expert' => 'Expert/Senior',
        'developer' => 'Developer'
    ];
    
    return $roleNames[$role] ?? ucfirst($role);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return hasRole('admin');
}

/**
 * Check if user is sales
 */
function isSales() {
    return hasRole('sales');
}

/**
 * Check if user is architect
 */
function isArchitect() {
    return hasRole('architect');
}

/**
 * Check if user is SUL
 */
function isSUL() {
    return hasRole('sul');
}

/**
 * Check if user is expert
 */
function isExpert() {
    return hasRole('expert');
}

/**
 * Check if user is developer
 */
function isDeveloper() {
    return hasRole('developer');
}
?>