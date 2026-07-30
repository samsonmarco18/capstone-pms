#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/version-control.php';

$slug = vcGenerateSlug((string) ($argv[1] ?? ''));
if ($slug === '') {
    exit(1);
}

try {
    $conn = getConnection();
    $stmt = $conn->prepare(
        'SELECT id, slug, webhook_token, repository_status, default_branch_name
         FROM project_repositories
         WHERE slug = ? AND repository_status = ?
         LIMIT 1'
    );
    $status = 'active';
    $stmt->bind_param('ss', $slug, $status);
    $stmt->execute();
    $repository = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$repository || !vcRestoreRepositorySnapshot($conn, $repository)) {
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    error_log('Git repository restore failed: ' . $e->getMessage());
    exit(1);
}
