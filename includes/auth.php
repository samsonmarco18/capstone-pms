<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/file-storage.php';
require_once __DIR__ . '/app-settings.php';
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

function getUserProfileImageUrl(?string $profilePic): string
{
    $profilePic = trim((string) $profilePic);
    if ($profilePic === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $profilePic)) {
        return $profilePic;
    }

    return storagePublicUrl($profilePic);
}

function getUserInitials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($parts === []) {
        return '?';
    }

    $substring = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    $uppercase = function_exists('mb_strtoupper') ? 'mb_strtoupper' : 'strtoupper';
    $first = $substring((string) $parts[0], 0, 1);
    $last = count($parts) > 1 ? $substring((string) $parts[count($parts) - 1], 0, 1) : '';

    return $uppercase($first . $last);
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

function formatTimeAgo(?string $datetime): string
{
    $timestamp = strtotime((string) $datetime);
    if ($timestamp === false) {
        return 'just now';
    }

    $diff = max(0, time() - $timestamp);

    if ($diff < 60) {
        return 'just now';
    }

    $units = [
        31536000 => 'y',
        2592000 => 'mo',
        604800 => 'w',
        86400 => 'd',
        3600 => 'h',
        60 => 'm',
    ];

    foreach ($units as $seconds => $suffix) {
        if ($diff >= $seconds) {
            $value = (int) floor($diff / $seconds);
            return $value . $suffix . ' ago';
        }
    }

    return 'just now';
}

function formatMinutesAgo(int|float|string|null $minutes): string
{
    $minutes = max(0, (int) floor((float) $minutes));
    if ($minutes < 1) {
        return 'just now';
    }

    $units = [
        525600 => 'y',
        43200 => 'mo',
        10080 => 'w',
        1440 => 'd',
        60 => 'h',
        1 => 'm',
    ];

    foreach ($units as $unitMinutes => $suffix) {
        if ($minutes >= $unitMinutes) {
            return (int) floor($minutes / $unitMinutes) . $suffix . ' ago';
        }
    }

    return 'just now';
}

function getSecuritySettings(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        'session_timeout' => max(5, (int) getAppSetting('session_timeout', '30')),
        'password_expiry' => max(0, (int) getAppSetting('password_expiry', '90')),
        'max_login_attempts' => max(3, (int) getAppSetting('max_login_attempts', '5')),
    ];

    return $cache;
}

function ensureUserPasswordSecurityColumns(): bool
{
    static $ensured = null;

    if ($ensured !== null) {
        return $ensured;
    }

    try {
        $conn = getConnection();

        if (dbColumnExists($conn, 'users', 'password_changed_at')) {
            $ensured = true;
            return true;
        }

        $sql = DB_DRIVER === 'pgsql'
            ? 'ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMP NULL'
            : 'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER designation';

        if ($conn->query($sql) === false) {
            error_log('ensureUserPasswordSecurityColumns failed: ' . ($conn->error ?? 'unknown error'));
            $ensured = false;
            return false;
        }

        $ensured = true;
        return true;
    } catch (Throwable $e) {
        error_log('ensureUserPasswordSecurityColumns error: ' . $e->getMessage());
        $ensured = false;
        return false;
    }
}

function isPasswordExpiredForUser(array $user): bool
{
    $securitySettings = getSecuritySettings();
    $passwordExpiryDays = (int) ($securitySettings['password_expiry'] ?? 0);

    if ($passwordExpiryDays <= 0) {
        return false;
    }

    $referenceValue = trim((string) ($user['password_changed_at'] ?? $user['created_at'] ?? ''));
    if ($referenceValue === '') {
        return false;
    }

    $referenceTimestamp = strtotime($referenceValue);
    if ($referenceTimestamp === false) {
        return false;
    }

    return (time() - $referenceTimestamp) >= ($passwordExpiryDays * 86400);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        error_log('requireLogin redirecting guest from ' . ($_SERVER['REQUEST_URI'] ?? 'unknown-uri'));
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? BASE_URL;
        header('Location: ' . BASE_URL . 'login.php');
        exit();
    }

    $user = getCurrentUser();
    if (!$user) {
        logoutUser();
    }

    $preferredTimezone = trim((string) ($user['timezone'] ?? ''));
    if ($preferredTimezone !== '' && in_array($preferredTimezone, timezone_identifiers_list(), true)) {
        date_default_timezone_set($preferredTimezone);
    }

    $currentSessionVersion = (int) ($user['session_version'] ?? 1);
    if (!isset($_SESSION['session_version'])) {
        $_SESSION['session_version'] = $currentSessionVersion;
    } elseif ((int) $_SESSION['session_version'] !== $currentSessionVersion) {
        logoutUser();
    }

    $sessionsInvalidBefore = strtotime((string) ($user['sessions_invalid_before'] ?? ''));
    $sessionLoginTime = (int) ($_SESSION['login_time'] ?? time());
    if ($sessionsInvalidBefore !== false && $sessionsInvalidBefore > $sessionLoginTime) {
        logoutUser();
    }

    $currentScript = basename((string) ($_SERVER['PHP_SELF'] ?? ''));

    $securitySettings = getSecuritySettings();
    $sessionTimeoutMinutes = (int) ($securitySettings['session_timeout'] ?? 30);
    $lastActivity = (int) ($_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? time());

    if ($sessionTimeoutMinutes > 0 && (time() - $lastActivity) > ($sessionTimeoutMinutes * 60)) {
        logoutUser();
    }

    $_SESSION['last_activity'] = time();

    if (!empty($_SESSION['force_password_change']) && $currentScript !== 'settings.php' && $currentScript !== 'logout.php') {
        header('Location: ' . BASE_URL . 'settings.php');
        exit();
    }

    if (isPasswordExpiredForUser($user)) {
        $_SESSION['force_password_change'] = true;

        if ($currentScript !== 'settings.php' && $currentScript !== 'logout.php') {
            header('Location: ' . BASE_URL . 'settings.php');
            exit();
        }
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
    try {
        $conn = getConnection();
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS count
             FROM notifications
             WHERE user_id = ?
               AND is_read = FALSE
               AND (notification_type IS NULL OR notification_type NOT IN ('message', 'group'))"
        );

        if ($stmt === false) {
            error_log('getUnreadNotificationsCount prepare failed: ' . ($conn->error ?? 'unknown error'));
            return 0;
        }

        $stmt->bind_param('i', $userId);

        if (!$stmt->execute()) {
            error_log('getUnreadNotificationsCount execute failed for user_id=' . $userId . ': ' . ($conn->error ?? 'unknown error'));
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        if ($result === false) {
            error_log('getUnreadNotificationsCount get_result failed for user_id=' . $userId . ': ' . ($conn->error ?? 'unknown error'));
            $stmt->close();
            return 0;
        }

        $row = $result->fetch_assoc() ?: [];
        $stmt->close();

        return (int) ($row['count'] ?? 0);
    } catch (Throwable $e) {
        error_log('getUnreadNotificationsCount error for user_id=' . $userId . ': ' . $e->getMessage());
        return 0;
    }
}

function createNotification(
    int $userId,
    string $title,
    string $message,
    string $type = 'system',
    ?string $relatedTable = null,
    ?int $relatedId = null,
    ?string $actionUrl = null,
    bool $sendEmail = true
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

        if ($result && $sendEmail) {
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
        'qa' => 'QA',
        'expert' => 'Senior / Expert',
        'developer' => 'Developer',
    ];

    return $roleNames[$role] ?? ucfirst($role);
}

function getDepartmentOptions(): array
{
    return [
        'UI/UX',
        'Frontend',
        'Backend',
        'Mobile',
        'Database',
        'DevOps',
        'AI',
        'Security',
        'QA',
        'Expert Department',
        'Other Departments',
        'UI/UX Designer',
        'Frontend Developer',
        'Backend Developer',
        'Full Stack Developer',
        'QA Engineer',
        'DevOps Engineer',
        'Database Administrator',
    ];
}

function isAdmin(): bool { return hasRole('admin'); }
function isSales(): bool { return hasRole('sales'); }
function isArchitect(): bool { return hasRole('architect'); }
function isSUL(): bool { return hasRole('sul'); }
function isQA(): bool { return hasRole('qa'); }
function isExpert(): bool { return hasRole('expert'); }
function isDeveloper(): bool { return hasRole('developer'); }
?>
