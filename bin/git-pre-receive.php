#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/version-control.php';

try {
    $conn = getConnection();
    $slug = (string) ($argv[1] ?? '');
    $stmt = $conn->prepare("SELECT * FROM project_repositories WHERE slug = ? AND repository_status = 'active'");
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $repository = $stmt->get_result()->fetch_assoc();
    if (!$repository) throw new RuntimeException('Repository is unavailable.');
    $token = (string) getenv('ERNI_PMS_PUSH_TOKEN');
    for ($i = 0; $i < (int) getenv('GIT_PUSH_OPTION_COUNT'); $i++) {
        $option = (string) getenv('GIT_PUSH_OPTION_' . $i);
        if (str_starts_with($option, 'erni-pms-token=')) $token = substr($option, 15);
    }
    if (!preg_match('/^v1:([1-9][0-9]*):[a-f0-9]{64}$/D', $token, $parts)) {
        throw new RuntimeException('Git authentication required. Download a fresh repository from ERNI PMS.');
    }
    $userId = (int) $parts[1];
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || !vcValidPersonalPushToken($repository, $user, $token)
        || !vcCanAccessProject($conn, $user, (int) $repository['project_id'])) {
        throw new RuntimeException('Git credentials are invalid or project access was revoked. Download a fresh repository.');
    }
    while (($line = fgets(STDIN)) !== false) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) !== 3) throw new RuntimeException('Invalid Git reference update.');
        [$old, $new, $ref] = $parts;
        $name = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref;
        $stmt = $conn->prepare('SELECT is_protected FROM repository_branches WHERE repository_id = ? AND name = ?');
        $stmt->bind_param('is', $repository['id'], $name);
        $stmt->execute();
        $protected = (bool) ($stmt->get_result()->fetch_assoc()['is_protected'] ?? false);
        vcAssertPushAllowed($user, $ref, (string) ($repository['default_branch_name'] ?: 'main'), $protected);
    }
    fwrite(STDOUT, 'ERNI_PMS_PUSH_OK');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERNI PMS: ' . $e->getMessage() . "\n");
    exit(1);
}
