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
?>
