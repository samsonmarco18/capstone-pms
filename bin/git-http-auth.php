#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function gitHttpNormalizeSlug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    return trim($slug, '-');
}

function gitHttpExtractSlug(string $pathInfo): string
{
    if (preg_match('#^/([^/]+)\.git(?:/.*)?$#', $pathInfo, $matches) !== 1) {
        return '';
    }

    return gitHttpNormalizeSlug(rawurldecode((string) $matches[1]));
}

function gitHttpExtractBasicCredentials(string $authorizationHeader): ?array
{
    if (preg_match('/^Basic\s+(.+)$/i', trim($authorizationHeader), $matches) !== 1) {
        return null;
    }

    $decoded = base64_decode((string) $matches[1], true);
    if ($decoded === false || !str_contains($decoded, ':')) {
        return null;
    }

    [$username, $password] = explode(':', $decoded, 2);
    $username = trim($username);
    $password = trim($password);

    if ($username === '' || $password === '') {
        return null;
    }

    return [$username, $password];
}

$pathInfo = (string) ($argv[1] ?? '');
$authorizationHeader = (string) ($argv[2] ?? '');
$slug = gitHttpExtractSlug($pathInfo);
$credentials = gitHttpExtractBasicCredentials($authorizationHeader);

if ($slug === '' || $credentials === null) {
    exit(1);
}

[$username, $password] = $credentials;

try {
    $conn = getConnection();
    $stmt = $conn->prepare(
        'SELECT webhook_token, repository_status
         FROM project_repositories
         WHERE slug = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $repository = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$repository) {
        exit(1);
    }

    $repositoryStatus = (string) ($repository['repository_status'] ?? '');
    $webhookToken = trim((string) ($repository['webhook_token'] ?? ''));
    if ($repositoryStatus !== 'active' || $webhookToken === '' || !hash_equals($webhookToken, $password)) {
        exit(1);
    }

    // The HTTP username must be a real, active PMS account. The repository
    // token remains the per-repository password, while the username identifies
    // the person performing the push for the post-receive audit record.
    $userStmt = $conn->prepare(
        "SELECT username
         FROM users
         WHERE LOWER(username) = LOWER(?) AND status = 'active'
         LIMIT 1"
    );
    $userStmt->bind_param('s', $username);
    $userStmt->execute();
    $pushUser = $userStmt->get_result()->fetch_assoc() ?: null;
    $userStmt->close();
    if (!$pushUser) {
        exit(1);
    }

    fwrite(STDOUT, (string) $pushUser['username']);
    exit(0);
} catch (Throwable $e) {
    error_log('Git HTTP auth failed: ' . $e->getMessage());
    exit(1);
}
