<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

function folderApiInitialize(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
}

function folderApiRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function folderApiRequireUser(): array
{
    if (!isLoggedIn()) {
        folderApiRespond([
            'success' => false,
            'error' => 'Your session has expired. Please sign in again.',
            'login_url' => BASE_URL . 'login.php',
        ], 401);
    }

    $user = getCurrentUser();
    if (!$user) {
        folderApiRespond([
            'success' => false,
            'error' => 'Your account is no longer available. Please sign in again.',
            'login_url' => BASE_URL . 'login.php',
        ], 401);
    }

    return $user;
}

function folderApiError(Throwable $error, string $context, string $publicMessage): never
{
    error_log($context . ': ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString());
    folderApiRespond([
        'success' => false,
        'error' => $publicMessage,
    ], 500);
}

function folderApiPrepare(mysqli|PgsqlMysqliCompatConnection $conn, string $sql): mysqli_stmt|PgsqlMysqliCompatStatement
{
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Database prepare failed: ' . ($conn->error ?: 'unknown error'));
    }

    return $stmt;
}

function folderApiExecute(mysqli_stmt|PgsqlMysqliCompatStatement $stmt, mysqli|PgsqlMysqliCompatConnection $conn): void
{
    if (!$stmt->execute()) {
        throw new RuntimeException('Database operation failed: ' . ($conn->error ?: 'unknown error'));
    }
}

function folderApiValidateFolderName(string $name): string
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 255) {
        throw new InvalidArgumentException('Folder name must contain between 1 and 255 characters.');
    }
    if (preg_match('/[\\x00-\\x1F\\x7F\\\\\/]/u', $name) === 1 || $name === '.' || $name === '..') {
        throw new InvalidArgumentException('Folder name contains unsupported characters.');
    }

    return $name;
}

function folderApiCanAccessFolder(array $folder, array $user): bool
{
    if ((string) ($user['role'] ?? '') === 'admin') {
        return true;
    }

    $folderRole = trim((string) ($folder['role'] ?? ''));
    $folderUserId = (int) ($folder['user_id'] ?? 0);

    return $folderRole === ''
        || $folderRole === (string) ($user['role'] ?? '')
        || ($folderUserId > 0 && $folderUserId === (int) ($user['id'] ?? 0));
}
?>
