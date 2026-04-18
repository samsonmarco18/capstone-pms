<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function vcGetAccessibleProjects(mysqli $conn, array $user): array
{
    $role = $user['role'] ?? '';
    $userId = (int) ($user['id'] ?? 0);

    if ($role === 'admin') {
        $sql = "
            SELECT p.id, p.name, p.status, c.name AS client_name
            FROM projects p
            INNER JOIN clients c ON c.id = p.client_id
            ORDER BY p.name
        ";
        return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    if ($role === 'architect') {
        $stmt = $conn->prepare(
            "SELECT p.id, p.name, p.status, c.name AS client_name
             FROM projects p
             INNER JOIN clients c ON c.id = p.client_id
             WHERE p.architect_id = ? OR p.architect_id IS NULL
             ORDER BY p.name"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $projects;
    }

    if ($role === 'developer') {
        $stmt = $conn->prepare(
            "SELECT DISTINCT p.id, p.name, p.status, c.name AS client_name
             FROM projects p
             INNER JOIN clients c ON c.id = p.client_id
             LEFT JOIN tasks t ON t.project_id = p.id AND t.assigned_to = ?
             LEFT JOIN developer_allocations da ON da.project_id = p.id
             LEFT JOIN developer_allocation_assignments daa
                ON daa.allocation_id = da.id AND daa.developer_id = ?
             WHERE t.id IS NOT NULL OR daa.id IS NOT NULL
             ORDER BY p.name"
        );
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $projects;
    }

    return [];
}

function vcCanAccessProject(mysqli $conn, array $user, int $projectId): bool
{
    foreach (vcGetAccessibleProjects($conn, $user) as $project) {
        if ((int) $project['id'] === $projectId) {
            return true;
        }
    }

    return false;
}

function vcGetRepository(mysqli $conn, int $repositoryId): ?array
{
    $stmt = $conn->prepare(
        "SELECT pr.*, p.name AS project_name, c.name AS client_name,
                parent_repo.name AS parent_repository_name,
                creator.full_name AS created_by_name
         FROM project_repositories pr
         INNER JOIN projects p ON p.id = pr.project_id
         INNER JOIN clients c ON c.id = p.client_id
         INNER JOIN users creator ON creator.id = pr.created_by
         LEFT JOIN project_repositories parent_repo ON parent_repo.id = pr.parent_repository_id
         WHERE pr.id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $repositoryId);
    $stmt->execute();
    $repository = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $repository;
}

function vcCanAccessRepository(mysqli $conn, array $user, int $repositoryId): bool
{
    $repository = vcGetRepository($conn, $repositoryId);

    if (!$repository) {
        return false;
    }

    return vcCanAccessProject($conn, $user, (int) $repository['project_id']);
}

function vcGenerateSlug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'repository';
}

function vcGenerateWebhookToken(): string
{
    return bin2hex(random_bytes(20));
}

function vcGenerateCommitHash(int $repositoryId, int $branchId, int $userId, string $title): string
{
    return substr(sha1($repositoryId . '|' . $branchId . '|' . $userId . '|' . $title . '|' . microtime(true) . '|' . random_int(1000, 9999)), 0, 12);
}

function vcTouchRepository(mysqli $conn, int $repositoryId): void
{
    $stmt = $conn->prepare('UPDATE project_repositories SET last_activity_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $repositoryId);
    $stmt->execute();
    $stmt->close();
}

function vcLogOperation(
    mysqli $conn,
    int $repositoryId,
    ?int $branchId,
    ?int $commitId,
    int $userId,
    string $operationType,
    ?int $relatedRepositoryId = null,
    ?int $relatedBranchId = null,
    ?array $metadata = null
): bool {
    $metadataJson = $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;

    $stmt = $conn->prepare(
        'INSERT INTO repository_operations (
            repository_id, branch_id, commit_id, user_id, operation_type,
            related_repository_id, related_branch_id, metadata
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iiiisiis',
        $repositoryId,
        $branchId,
        $commitId,
        $userId,
        $operationType,
        $relatedRepositoryId,
        $relatedBranchId,
        $metadataJson
    );
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function vcGetDefaultBranch(mysqli $conn, int $repositoryId): ?array
{
    $stmt = $conn->prepare(
        'SELECT * FROM repository_branches WHERE repository_id = ? ORDER BY is_default DESC, created_at ASC LIMIT 1'
    );
    $stmt->bind_param('i', $repositoryId);
    $stmt->execute();
    $branch = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $branch;
}

function vcFindRepositoryByWebhookToken(mysqli $conn, string $token): ?array
{
    $stmt = $conn->prepare(
        "SELECT pr.*, p.name AS project_name, c.name AS client_name
         FROM project_repositories pr
         INNER JOIN projects p ON p.id = pr.project_id
         INNER JOIN clients c ON c.id = p.client_id
         WHERE pr.webhook_token = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $repository = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $repository;
}

function vcFindOrCreateBranch(mysqli $conn, int $repositoryId, string $branchName, int $userId, ?string $commitHash = null): array
{
    $normalizedBranchName = trim($branchName);
    if ($normalizedBranchName === '') {
        $defaultBranch = vcGetDefaultBranch($conn, $repositoryId);
        if ($defaultBranch) {
            return $defaultBranch;
        }
        $normalizedBranchName = 'main';
    }

    $stmt = $conn->prepare('SELECT * FROM repository_branches WHERE repository_id = ? AND name = ? LIMIT 1');
    $stmt->bind_param('is', $repositoryId, $normalizedBranchName);
    $stmt->execute();
    $branch = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($branch) {
        if ($commitHash !== null && $commitHash !== '' && $branch['last_commit_hash'] !== $commitHash) {
            $updateStmt = $conn->prepare('UPDATE repository_branches SET last_commit_hash = ?, updated_at = NOW() WHERE id = ?');
            $updateStmt->bind_param('si', $commitHash, $branch['id']);
            $updateStmt->execute();
            $updateStmt->close();
            $branch['last_commit_hash'] = $commitHash;
        }

        return $branch;
    }

    $sourceBranchId = null;
    $branchStatus = 'active';
    $isDefault = 0;
    $isProtected = 0;
    $insertStmt = $conn->prepare(
        'INSERT INTO repository_branches (
            repository_id, source_branch_id, name, last_commit_hash,
            branch_status, is_default, is_protected, created_by
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->bind_param(
        'iisssiii',
        $repositoryId,
        $sourceBranchId,
        $normalizedBranchName,
        $commitHash,
        $branchStatus,
        $isDefault,
        $isProtected,
        $userId
    );
    $insertStmt->execute();
    $branchId = (int) $insertStmt->insert_id;
    $insertStmt->close();

    $fetchStmt = $conn->prepare('SELECT * FROM repository_branches WHERE id = ? LIMIT 1');
    $fetchStmt->bind_param('i', $branchId);
    $fetchStmt->execute();
    $branch = $fetchStmt->get_result()->fetch_assoc() ?: null;
    $fetchStmt->close();

    if (!$branch) {
        throw new RuntimeException('Failed to create repository branch.');
    }

    return $branch;
}

function vcFindCommitByHash(mysqli $conn, int $repositoryId, string $commitHash): ?array
{
    if ($commitHash === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT * FROM repository_commits WHERE repository_id = ? AND commit_hash = ? LIMIT 1');
    $stmt->bind_param('is', $repositoryId, $commitHash);
    $stmt->execute();
    $commit = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $commit;
}

function vcSyncExternalCommit(mysqli $conn, array $repository, array $payload): array
{
    $repositoryId = (int) $repository['id'];
    $systemUserId = (int) ($repository['created_by'] ?? 1);
    $branchName = trim((string) ($payload['branch_name'] ?? $repository['default_branch_name'] ?? 'main'));
    $commitHash = trim((string) ($payload['commit_hash'] ?? ''));
    $commitTitle = trim((string) ($payload['commit_title'] ?? ''));
    $commitMessage = trim((string) ($payload['commit_message'] ?? ''));
    $filesChanged = max(0, (int) ($payload['files_changed'] ?? 0));
    $linesAdded = max(0, (int) ($payload['lines_added'] ?? 0));
    $linesRemoved = max(0, (int) ($payload['lines_removed'] ?? 0));
    $operationType = trim((string) ($payload['operation_type'] ?? 'commit'));
    $allowedOperations = ['commit', 'push', 'pull', 'merge'];

    if (!in_array($operationType, $allowedOperations, true)) {
        $operationType = 'commit';
    }

    if ($commitHash === '') {
        $commitHash = substr(sha1($repositoryId . '|' . $branchName . '|' . microtime(true) . '|external'), 0, 12);
    }

    if ($commitTitle === '') {
        $commitTitle = ucfirst($operationType) . ' sync';
    }

    $conn->begin_transaction();
    try {
        $branch = vcFindOrCreateBranch($conn, $repositoryId, $branchName, $systemUserId, $commitHash);
        $existingCommit = vcFindCommitByHash($conn, $repositoryId, $commitHash);
        $commitId = $existingCommit['id'] ?? null;

        if (!$existingCommit) {
            $parentCommitId = null;
            if (!empty($branch['last_commit_hash']) && $branch['last_commit_hash'] !== $commitHash) {
                $parentCommit = vcFindCommitByHash($conn, $repositoryId, (string) $branch['last_commit_hash']);
                $parentCommitId = $parentCommit['id'] ?? null;
            }

            $commitKind = $operationType === 'merge' ? 'merge' : ($operationType === 'pull' ? 'sync' : 'commit');
            $stmt = $conn->prepare(
                'INSERT INTO repository_commits (
                    repository_id, branch_id, parent_commit_id, commit_hash, commit_title,
                    commit_message, files_changed, lines_added, lines_removed, commit_kind, authored_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'iiisssiiisi',
                $repositoryId,
                $branch['id'],
                $parentCommitId,
                $commitHash,
                $commitTitle,
                $commitMessage,
                $filesChanged,
                $linesAdded,
                $linesRemoved,
                $commitKind,
                $systemUserId
            );
            $stmt->execute();
            $commitId = (int) $stmt->insert_id;
            $stmt->close();
        }

        $updateBranchStmt = $conn->prepare('UPDATE repository_branches SET last_commit_hash = ?, updated_at = NOW() WHERE id = ?');
        $updateBranchStmt->bind_param('si', $commitHash, $branch['id']);
        $updateBranchStmt->execute();
        $updateBranchStmt->close();

        $remoteUrl = trim((string) ($payload['remote_url'] ?? ''));
        $platform = trim((string) ($payload['platform'] ?? ''));
        $provider = trim((string) ($payload['provider'] ?? ''));
        if ($remoteUrl !== '' || $provider !== '' || $repository['sync_mode'] === 'manual') {
            $syncMode = $provider !== '' ? 'provider_webhook' : 'git_hook';
            $providerValue = $provider !== '' ? $provider : ($platform !== '' ? $platform : null);
            $updateRepoStmt = $conn->prepare(
                'UPDATE project_repositories
                 SET external_remote_url = COALESCE(NULLIF(?, \'\'), external_remote_url),
                     sync_mode = ?,
                     sync_provider = ?,
                     last_activity_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?'
            );
            $updateRepoStmt->bind_param('sssi', $remoteUrl, $syncMode, $providerValue, $repositoryId);
            $updateRepoStmt->execute();
            $updateRepoStmt->close();
        } else {
            vcTouchRepository($conn, $repositoryId);
        }

        vcLogOperation(
            $conn,
            $repositoryId,
            (int) $branch['id'],
            $commitId ? (int) $commitId : null,
            $systemUserId,
            $operationType,
            null,
            null,
            [
                'source' => 'external_sync',
                'platform' => $platform,
                'provider' => $provider,
                'remote_url' => $remoteUrl,
                'author_name' => (string) ($payload['author_name'] ?? ''),
                'author_email' => (string) ($payload['author_email'] ?? ''),
                'notes' => (string) ($payload['notes'] ?? ''),
            ]
        );

        logActivity(
            $systemUserId,
            'sync_repository_event',
            'project_repositories',
            $repositoryId,
            null,
            [
                'operation_type' => $operationType,
                'branch_name' => $branchName,
                'commit_hash' => $commitHash,
                'platform' => $platform,
            ]
        );

        $conn->commit();

        return [
            'repository_id' => $repositoryId,
            'branch_id' => (int) $branch['id'],
            'commit_id' => $commitId ? (int) $commitId : null,
            'commit_hash' => $commitHash,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
?>
