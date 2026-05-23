<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    $conn = getConnection();
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    if ($stmt === false) {
        error_log('getCurrentUser prepare failed: ' . ($conn->error ?? 'unknown error'));
        return null;
    }

    $stmt->bind_param('i', $_SESSION['user_id']);
    if (!$stmt->execute()) {
        error_log('getCurrentUser execute failed for user_id=' . (int) $_SESSION['user_id'] . ': ' . ($conn->error ?? 'unknown error'));
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    if ($result === false) {
        error_log('getCurrentUser get_result failed for user_id=' . (int) $_SESSION['user_id'] . ': ' . ($conn->error ?? 'unknown error'));
        $stmt->close();
        return null;
    }

    $user = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $user;
}

function hasRole(array|string $roles): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }

    if (is_array($roles)) {
        return in_array($user['role'], $roles, true);
    }

    return $user['role'] === $roles;
}

function hasAllRoles(array $roles): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }

    return count(array_unique($roles)) === 1 && $user['role'] === $roles[0];
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        error_log('requireLogin redirecting guest from ' . ($_SERVER['REQUEST_URI'] ?? 'unknown-uri'));
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? BASE_URL;
        header('Location: ' . BASE_URL . 'login.php');
        exit();
    }
}

function requireRole(array|string $roles): void
{
    requireLogin();

    if (!hasRole($roles)) {
        $user = getCurrentUser();
        error_log(
            'requireRole denied access to ' . ($_SERVER['REQUEST_URI'] ?? 'unknown-uri')
            . ' for user_id=' . (int) ($user['id'] ?? 0)
            . ' role=' . (string) ($user['role'] ?? 'unknown')
        );
        http_response_code(403);
        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }
}

function logActivity(
    ?int $userId,
    string $action,
    ?string $tableName = null,
    ?int $recordId = null,
    mixed $oldData = null,
    mixed $newData = null,
    string $status = 'success',
    ?string $description = null
): bool {
    try {
        $conn = getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $oldDataJson = $oldData !== null ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null;
        $newDataJson = $newData !== null ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;

        if ($userId === null) {
            $stmt = $conn->prepare(
                'INSERT INTO audit_logs (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent, status, description)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssissssss',
                $action,
                $tableName,
                $recordId,
                $oldDataJson,
                $newDataJson,
                $ip,
                $userAgent,
                $status,
                $description
            );
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO audit_logs (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent, status, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ississssss',
                $userId,
                $action,
                $tableName,
                $recordId,
                $oldDataJson,
                $newDataJson,
                $ip,
                $userAgent,
                $status,
                $description
            );
        }

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    } catch (Throwable $e) {
        error_log('Activity logging error: ' . $e->getMessage());
        return false;
    }
}

function getUnreadNotificationsCount(int $userId): int
{
    $conn = getConnection();
    $stmt = $conn->prepare('SELECT COUNT(*) AS count FROM notifications WHERE user_id = ? AND is_read = FALSE');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return (int) ($row['count'] ?? 0);
}

function createNotification(
    int $userId,
    string $title,
    string $message,
    string $type = 'system',
    ?string $relatedTable = null,
    ?int $relatedId = null,
    ?string $actionUrl = null
): bool {
    try {
        $conn = getConnection();
        $stmt = $conn->prepare(
            'INSERT INTO notifications (user_id, title, message, notification_type, related_table, related_id, action_url)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issssis', $userId, $title, $message, $type, $relatedTable, $relatedId, $actionUrl);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            sendNotificationEmailToUser($userId, $title, $message, $type, $actionUrl);
        }

        return $result;
    } catch (Throwable $e) {
        error_log('Notification creation error: ' . $e->getMessage());
        return false;
    }
}

function markNotificationAsRead(int $notificationId): bool
{
    try {
        $conn = getConnection();
        $stmt = $conn->prepare('UPDATE notifications SET is_read = TRUE, read_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $notificationId);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    } catch (Throwable $e) {
        error_log('Notification update error: ' . $e->getMessage());
        return false;
    }
}

function logoutUser(): void
{
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        logActivity((int) $userId, 'logout', 'users', (int) $userId);
    }

    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . 'login.php');
    exit();
}

function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function getRoleDisplayName(string $role): string
{
    $roleNames = [
        'admin' => 'Administrator',
        'sales' => 'Sales',
        'architect' => 'Architect',
        'sul' => 'Service Unit Leader',
        'expert' => 'Senior / Expert',
        'developer' => 'Developer',
    ];

    return $roleNames[$role] ?? ucfirst($role);
}

function isAdmin(): bool { return hasRole('admin'); }
function isSales(): bool { return hasRole('sales'); }
function isArchitect(): bool { return hasRole('architect'); }
function isSUL(): bool { return hasRole('sul'); }
function isExpert(): bool { return hasRole('expert'); }
function isDeveloper(): bool { return hasRole('developer'); }
?>
