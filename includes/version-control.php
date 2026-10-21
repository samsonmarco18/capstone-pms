<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/version-control-policy.php';

function vcIsExecAvailable(): bool
{
    if (function_exists('exec') === false) {
        return false;
    }
    
    $disabled = (string) ini_get('disable_functions');
    if (strpos($disabled, 'exec') !== false) {
        return false;
    }
    
    return true;
}

function vcGetAccessibleProjects(mysqli|PgsqlMysqliCompatConnection $conn, array $user): array
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

    if (in_array($role, ['sul', 'qa'], true)) {
        $sql = "
            SELECT p.id, p.name, p.status, c.name AS client_name
            FROM projects p
            INNER JOIN clients c ON c.id = p.client_id
            WHERE p.status NOT IN ('completed', 'cancelled')
            ORDER BY p.name
        ";
        return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    if ($role === 'developer') {
        $departmentAssignmentJoin = '';
        $departmentAssignmentCondition = '';
        $hasDepartmentAssignmentAccess = false;

        if (
            dbTableExists($conn, 'methodology_task_departments')
            && dbColumnExists($conn, 'methodology_task_departments', 'assigned_developer_id')
        ) {
            $departmentAssignmentJoin = "
             LEFT JOIN methodology_task_departments mtd
                ON mtd.project_id = p.id AND mtd.assigned_developer_id = ?";
            $departmentAssignmentCondition = "
                OR mtd.id IS NOT NULL";
            $hasDepartmentAssignmentAccess = true;
        }

        $stmt = $conn->prepare(
            "SELECT DISTINCT p.id, p.name, p.status, c.name AS client_name
             FROM projects p
             INNER JOIN clients c ON c.id = p.client_id
             LEFT JOIN tasks t ON t.project_id = p.id AND t.assigned_to = ?
             LEFT JOIN methodology_tasks mt
                ON mt.project_id = p.id AND mt.assigned_developer_id = ?
             LEFT JOIN developer_allocations da ON da.project_id = p.id
             LEFT JOIN developer_allocation_assignments daa
                ON daa.allocation_id = da.id AND daa.developer_id = ?"
             . $departmentAssignmentJoin .
            "
             WHERE t.id IS NOT NULL
                OR mt.id IS NOT NULL
                OR daa.id IS NOT NULL"
             . $departmentAssignmentCondition .
            "
             ORDER BY p.name"
        );
        if ($hasDepartmentAssignmentAccess) {
            $stmt->bind_param('iiii', $userId, $userId, $userId, $userId);
        } else {
            $stmt->bind_param('iii', $userId, $userId, $userId);
        }
        $stmt->execute();
        $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $projects;
    }

    return [];
}

function vcCanAccessProject(mysqli|PgsqlMysqliCompatConnection $conn, array $user, int $projectId): bool
{
    foreach (vcGetAccessibleProjects($conn, $user) as $project) {
        if ((int) $project['id'] === $projectId) {
            return true;
        }
    }

    return false;
}

function vcGetRepository(mysqli|PgsqlMysqliCompatConnection $conn, int $repositoryId): ?array
{
    $stmt = $conn->prepare(
        "SELECT pr.*, p.name AS project_name, p.status AS project_status,
                p.created_by AS project_created_by, c.name AS client_name,
                c.created_by AS client_created_by,
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

function vcCanAccessRepository(mysqli|PgsqlMysqliCompatConnection $conn, array $user, int $repositoryId): bool
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

function vcGetGitBinary(): string
{
    $configuredBinary = trim((string) (getenv('GIT_BINARY') ?: ''));

    return $configuredBinary !== '' ? $configuredBinary : 'git';
}

function vcGetRepositoryStorageRoot(): string
{
    $configuredRoot = trim((string) (getenv('GIT_STORAGE_ROOT') ?: ''));
    if ($configuredRoot === '' && (bool) getenv('RENDER')) {
        $configuredRoot = sys_get_temp_dir() . '/erni-pms/git';
    }

    return $configuredRoot !== ''
        ? rtrim($configuredRoot, '/\\')
        : APP_ROOT_PATH . DIRECTORY_SEPARATOR . 'git';
}

function vcGetRepositoryBarePath(string $slug): string
{
    return vcGetRepositoryStorageRoot() . DIRECTORY_SEPARATOR . vcGenerateSlug($slug) . '.git';
}

function vcGetRepositoryWorkspaceRoot(): string
{
    $configuredRoot = trim((string) (getenv('GIT_WORKSPACE_ROOT') ?: ''));
    if ($configuredRoot === '' && (bool) getenv('RENDER')) {
        $configuredRoot = sys_get_temp_dir() . '/erni-pms/git-workspaces';
    }

    return $configuredRoot !== ''
        ? rtrim($configuredRoot, '/\\')
        : APP_ROOT_PATH . DIRECTORY_SEPARATOR . 'git-workspaces';
}

function vcGetRepositoryWorkspacePath(string $slug): string
{
    return vcGetRepositoryWorkspaceRoot() . DIRECTORY_SEPARATOR . vcGenerateSlug($slug);
}

function vcGenerateRepositoryHttpCloneUrl(string $slug): string
{
    return rtrim(BASE_URL, '/') . '/git/' . rawurlencode(vcGenerateSlug($slug)) . '.git';
}

function vcBuildRepositoryBrowserUrl(int $projectId = 0, int $repositoryId = 0, int $branchId = 0, int $commitId = 0): string
{
    $params = [];
    if ($projectId > 0) {
        $params['project_id'] = $projectId;
    }
    if ($repositoryId > 0) {
        $params['repository_id'] = $repositoryId;
    }
    if ($commitId > 0) {
        $params['commit_id'] = $commitId;
    }
    if ($branchId > 0) {
        $params['branch_id'] = $branchId;
    }

    return rtrim(BASE_URL, '/') . '/modules/version-control/repositories.php'
        . ($params ? '?' . http_build_query($params) : '');
}

function vcGetManagedRepositoryPushUsername(): string
{
    $username = trim((string) (getenv('GIT_HTTP_PUSH_USERNAME') ?: 'git'));
    return $username !== '' ? $username : 'git';
}

function vcGenerateRepositoryLocalClonePath(string $slug): string
{
    return vcGetRepositoryBarePath($slug);
}

function vcGenerateRepositoryFileCloneUrl(string $slug): string
{
    $path = str_replace('\\', '/', vcGenerateRepositoryLocalClonePath($slug));
    if (preg_match('/^[A-Za-z]:/', $path) === 1) {
        return 'file:///' . ltrim($path, '/');
    }

    return 'file://' . $path;
}

function vcIsRenderEnvironment(): bool
{
    return (bool) getenv('RENDER');
}

function vcResolvePreferredWritableRemoteUrl(
    string $slug,
    ?string $externalRemoteUrl = null,
    bool $portableClient = false,
    ?string $webhookToken = null,
    ?string $pushUsername = null
): ?string
{
    $externalRemoteUrl = vcNormalizeExternalRemoteUrl($externalRemoteUrl);
    if ($externalRemoteUrl !== null) {
        return $externalRemoteUrl;
    }

    if ($portableClient) {
        if (vcIsRenderEnvironment()) {
            return vcGenerateRepositoryAuthenticatedHttpCloneUrl($slug, (string) $webhookToken, $pushUsername);
        }

        return vcGenerateRepositoryFileCloneUrl($slug);
    }

    if (vcIsRenderEnvironment()) {
        return vcGetRepositoryBarePath($slug);
    }

    return vcGetRepositoryBarePath($slug);
}

function vcDeleteManagedRepositoryStorage(string $slug): void
{
    vcDeleteDirectoryRecursive(vcGetRepositoryBarePath($slug));
    vcDeleteDirectoryRecursive(vcGetRepositoryWorkspacePath($slug));
}

function vcCanDeleteRepository(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['admin', 'architect'], true);
}

function vcCanConfigureRepositorySync(array $user): bool
{
    return !in_array((string) ($user['role'] ?? ''), ['sul'], true);
}

function vcCanManagedRepositoryPushFromPortableClient(): bool
{
    return true;
}

function vcGetManagedRepositoryPortablePushHint(): string
{
    if (vcIsRenderEnvironment()) {
        return 'Downloaded repositories include a personal push credential. Only Architects can update main; developers push feature branches and submit pull requests.';
    }

    return 'Downloaded repositories can push back to the PMS-managed bare repository on this same machine.';
}

function vcGetManagedRepositoryCreateHint(): string
{
    if (vcIsRenderEnvironment()) {
        return 'ERNI PMS will create the managed repository and a ready-to-use working folder. Use your PMS account password or the personal credential in a fresh repository download to push.';
    }

    return 'ERNI PMS will create the managed repository and a ready-to-use working folder. Local downloads can push back to this PMS instance on the same machine.';
}

function vcGetRepositoryManagedCloneLabel(): string
{
    return 'Managed Clone / Push URL';
}

function vcGetRepositoryManagedRemoteDescription(): string
{
    if (vcIsRenderEnvironment()) {
        return 'Use this PMS-managed HTTPS remote for clone, fetch, and push. Authenticate with your PMS password or personal download credential.';
    }

    return 'Use this PMS-managed repository when your app and Git client are on the same machine.';
}

function vcGetRepositoryExternalRemoteLabel(): string
{
    return 'External Remote URL';
}

function vcGetRepositoryExternalRemotePlaceholder(): string
{
    return 'Optional: https://github.com/your-account/your-repo.git';
}

function vcGetRepositoryExternalRemoteHelp(): string
{
    if (vcIsRenderEnvironment()) {
        return 'Leave this blank to use the PMS-managed remote. External Git hosts need their own branch protection settings.';
    }

    return 'Leave this blank to use the PMS-managed repository locally, or set a GitHub/GitLab remote if you prefer.';
}

function vcDeleteRepositoryTracking(mysqli|PgsqlMysqliCompatConnection $conn, int $repositoryId): void
{
    $clearParentStmt = $conn->prepare('UPDATE project_repositories SET parent_repository_id = NULL WHERE parent_repository_id = ?');
    $clearParentStmt->bind_param('i', $repositoryId);
    $clearParentStmt->execute();
    $clearParentStmt->close();

    $deleteMergesStmt = $conn->prepare('DELETE FROM repository_merges WHERE repository_id = ?');
    $deleteMergesStmt->bind_param('i', $repositoryId);
    $deleteMergesStmt->execute();
    $deleteMergesStmt->close();

    $deleteOperationsStmt = $conn->prepare('DELETE FROM repository_operations WHERE repository_id = ?');
    $deleteOperationsStmt->bind_param('i', $repositoryId);
    $deleteOperationsStmt->execute();
    $deleteOperationsStmt->close();

    $deleteRepositoryStmt = $conn->prepare('DELETE FROM project_repositories WHERE id = ?');
    $deleteRepositoryStmt->bind_param('i', $repositoryId);
    $deleteRepositoryStmt->execute();
    $deleteRepositoryStmt->close();
}

function vcFindProjectFallbackRepositoryId(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): int
{
    if ($projectId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare('SELECT id FROM project_repositories WHERE project_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

function vcResolveManagedRepositoryDisplayUrl(string $slug): string
{
    if (!vcIsRenderEnvironment()) {
        return vcGenerateRepositoryFileCloneUrl($slug);
    }

    return vcGenerateRepositoryHttpCloneUrl($slug);
}

function vcResolveManagedRepositoryCreateUrl(string $slug): string
{
    return vcResolveManagedRepositoryDisplayUrl($slug);
}

function vcGenerateRepositoryAuthenticatedHttpCloneUrl(string $slug, string $webhookToken, ?string $pushUsername = null): string
{
    $webhookToken = trim($webhookToken);
    if ($webhookToken === '') {
        return vcGenerateRepositoryHttpCloneUrl($slug);
    }

    $parts = parse_url(vcGenerateRepositoryHttpCloneUrl($slug));
    if (!is_array($parts) || empty($parts['host'])) {
        return vcGenerateRepositoryHttpCloneUrl($slug);
    }

    $scheme = (string) ($parts['scheme'] ?? 'https');
    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '');
    $resolvedUsername = trim((string) $pushUsername);
    if ($resolvedUsername === '') {
        $resolvedUsername = vcGetManagedRepositoryPushUsername();
    }
    $username = rawurlencode($resolvedUsername);
    $password = rawurlencode($webhookToken);

    return $scheme . '://' . $username . ':' . $password . '@' . $host . $port . $path;
}

function vcResolveRepositoryDisplayRemote(?string $externalRemoteUrl, string $slug, ?string $webhookToken = null, ?string $pushUsername = null): string
{
    $externalRemoteUrl = vcNormalizeExternalRemoteUrl($externalRemoteUrl);
    if ($externalRemoteUrl !== null) {
        return $externalRemoteUrl;
    }

    if (vcIsRenderEnvironment()) {
        return vcGenerateRepositoryAuthenticatedHttpCloneUrl($slug, (string) $webhookToken, $pushUsername);
    }

    return vcResolveManagedRepositoryDisplayUrl($slug);
}

function vcGetRepositoryDisplayRemoteKind(?string $externalRemoteUrl): string
{
    return vcNormalizeExternalRemoteUrl($externalRemoteUrl) !== null ? 'external' : 'managed';
}

function vcIsInternalManagedRepositoryUrl(string $url): bool
{
    $normalized = trim($url);
    if ($normalized === '') {
        return false;
    }

    $parts = parse_url($normalized);
    if (is_array($parts) && !empty($parts['host'])) {
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $baseParts = parse_url(BASE_URL);
        $baseHost = strtolower((string) ($baseParts['host'] ?? ''));
        $normalizedHost = strtolower($host);
        $sameHost = $baseHost !== '' && $normalizedHost === $baseHost;
        $isLocalHost = in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true);
        if (($sameHost || $isLocalHost) && preg_match('#/(?:[^/]+/)?git/[^/?#]+\.git(?:/.*)?$#i', $path) === 1) {
            return true;
        }
        $normalized = $scheme . '://' . $host . $port . $path;
    }

    $prefix = rtrim(BASE_URL, '/') . '/git/';

    return str_starts_with($normalized, $prefix) && str_ends_with($normalized, '.git');
}

function vcNormalizeExternalRemoteUrl(?string $externalRemoteUrl): ?string
{
    $externalRemoteUrl = trim((string) $externalRemoteUrl);
    if ($externalRemoteUrl === '' || vcIsInternalManagedRepositoryUrl($externalRemoteUrl)) {
        return null;
    }

    return $externalRemoteUrl;
}

function vcEnsureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
}

function vcDeleteDirectoryRecursive(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($itemPath) && !is_link($itemPath)) {
            vcDeleteDirectoryRecursive($itemPath);
        } else {
            @unlink($itemPath);
        }
    }

    @rmdir($path);
}

function vcRunProcess(array $commandParts, ?string $workingDirectory = null): array
{
    if (!vcIsExecAvailable()) {
        throw new RuntimeException(
            'Git operations are not available on this hosting environment. ' .
            'The server has disabled the exec() function which is required for git commands. ' .
            'This is a common restriction on shared hosting. ' .
            'Contact your hosting provider to enable exec() or consider upgrading to a VPS/dedicated server.'
        );
    }
    
    $command = implode(' ', array_map('escapeshellarg', $commandParts));
    $executionDirectory = $workingDirectory ?: APP_ROOT_PATH;
    $previousDirectory = getcwd() ?: APP_ROOT_PATH;
    $outputLines = [];
    $exitCode = 0;

    chdir($executionDirectory);
    exec($command . ' 2>&1', $outputLines, $exitCode);
    chdir($previousDirectory);

    $combinedOutput = trim(implode(PHP_EOL, $outputLines));

    return [
        'command' => $command,
        'stdout' => $combinedOutput,
        'stderr' => $exitCode === 0 ? '' : $combinedOutput,
        'exit_code' => $exitCode,
    ];
}

function vcRunGitCommand(array $arguments, ?string $workingDirectory = null): array
{
    $result = vcRunProcess(array_merge([vcGetGitBinary()], $arguments), $workingDirectory);
    if ((int) $result['exit_code'] !== 0) {
        throw new RuntimeException($result['stderr'] !== '' ? $result['stderr'] : 'Git command failed.');
    }

    return $result;
}

function vcTryGitCommand(array $arguments, ?string $workingDirectory = null): bool
{
    try {
        vcRunGitCommand($arguments, $workingDirectory);
        return true;
    } catch (Throwable $ignored) {
        return false;
    }
}

function vcEnsureRepositorySnapshotSchema(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $id = DB_DRIVER === 'pgsql'
        ? 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY'
        : 'INT PRIMARY KEY AUTO_INCREMENT';
    $bundleType = DB_DRIVER === 'pgsql' ? 'TEXT' : 'LONGTEXT';
    $sql = "
        CREATE TABLE IF NOT EXISTS repository_git_snapshots (
            id {$id},
            repository_id INT NOT NULL UNIQUE,
            bundle_base64 {$bundleType} NOT NULL,
            bundle_size INT NOT NULL DEFAULT 0,
            head_hash VARCHAR(64) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (repository_id) REFERENCES project_repositories(id) ON DELETE CASCADE
        )
    ";
    if ($conn->query($sql) === false) {
        throw new RuntimeException('Failed to prepare durable Git snapshot storage.');
    }
    $initialized = true;
}

function vcSaveRepositorySnapshot(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $repositoryId,
    string $repositorySlug
): void {
    $barePath = vcGetRepositoryBarePath($repositorySlug);
    if ($repositoryId <= 0 || !is_dir($barePath)) {
        return;
    }

    vcEnsureRepositorySnapshotSchema($conn);
    $bundlePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erni-pms-' . bin2hex(random_bytes(8)) . '.bundle';
    try {
        vcRunGitCommand(['--git-dir=' . $barePath, 'bundle', 'create', $bundlePath, '--all'], APP_ROOT_PATH);
        $bundle = file_get_contents($bundlePath);
        if ($bundle === false) {
            throw new RuntimeException('Failed to read the Git snapshot bundle.');
        }
        $encoded = base64_encode($bundle);
        $size = strlen($bundle);
        $headResult = vcRunProcess([vcGetGitBinary(), '--git-dir=' . $barePath, 'rev-parse', 'HEAD'], APP_ROOT_PATH);
        $headHash = (int) ($headResult['exit_code'] ?? 1) === 0
            ? trim((string) ($headResult['stdout'] ?? ''))
            : null;

        $delete = $conn->prepare('DELETE FROM repository_git_snapshots WHERE repository_id = ?');
        $delete->bind_param('i', $repositoryId);
        $delete->execute();
        $delete->close();

        $insert = $conn->prepare(
            'INSERT INTO repository_git_snapshots (repository_id, bundle_base64, bundle_size, head_hash, updated_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $insert->bind_param('isis', $repositoryId, $encoded, $size, $headHash);
        $insert->execute();
        $insert->close();
    } finally {
        @unlink($bundlePath);
    }
}

function vcRestoreRepositorySnapshot(
    mysqli|PgsqlMysqliCompatConnection $conn,
    array $repository
): bool {
    $repositoryId = (int) ($repository['id'] ?? 0);
    $slug = vcGenerateSlug((string) ($repository['slug'] ?? ''));
    $barePath = vcGetRepositoryBarePath($slug);
    if (is_dir($barePath)) {
        return true;
    }

    vcEnsureRepositorySnapshotSchema($conn);
    $stmt = $conn->prepare(
        'SELECT bundle_base64 FROM repository_git_snapshots WHERE repository_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $repositoryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        /*
         * The repository metadata may have survived in PostgreSQL while an
         * older Render deployment kept the Git objects only on an ephemeral
         * disk. Initialize an empty managed remote so a developer's intact
         * local clone can recover it with `git push --all` and `git push
         * --tags`. The post-receive hook will persist the recovered objects as
         * a database-backed bundle after that first push.
         */
        vcEnsureDirectory(vcGetRepositoryStorageRoot());
        $defaultBranch = trim((string) ($repository['default_branch_name'] ?? 'main'));
        if ($defaultBranch === '') {
            $defaultBranch = 'main';
        }
        try {
            vcRunGitCommand(['init', '--bare', '--initial-branch=' . $defaultBranch, $barePath], APP_ROOT_PATH);
            $token = (string) ($repository['webhook_token'] ?? '');
            vcInstallServerRepositoryHooks(
                $barePath,
                rtrim(BASE_URL, '/') . '/api/version-control/sync.php?token=' . urlencode($token)
            );
            vcRunGitCommand(['--git-dir=' . $barePath, 'config', 'http.receivepack', 'true'], APP_ROOT_PATH);
            vcRunGitCommand(['--git-dir=' . $barePath, 'update-server-info'], APP_ROOT_PATH);
            return true;
        } catch (Throwable $e) {
            vcDeleteDirectoryRecursive($barePath);
            throw $e;
        }
    }

    $bundle = base64_decode((string) $row['bundle_base64'], true);
    if ($bundle === false) {
        throw new RuntimeException('The stored Git snapshot is invalid.');
    }

    vcEnsureDirectory(vcGetRepositoryStorageRoot());
    $bundlePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erni-pms-restore-' . bin2hex(random_bytes(8)) . '.bundle';
    try {
        if (file_put_contents($bundlePath, $bundle) === false) {
            throw new RuntimeException('Failed to stage the Git snapshot for restore.');
        }
        vcRunGitCommand(['clone', '--bare', $bundlePath, $barePath], APP_ROOT_PATH);
        $token = (string) ($repository['webhook_token'] ?? '');
        vcInstallServerRepositoryHooks(
            $barePath,
            rtrim(BASE_URL, '/') . '/api/version-control/sync.php?token=' . urlencode($token)
        );
        vcRunGitCommand(['--git-dir=' . $barePath, 'update-server-info'], APP_ROOT_PATH);
        return true;
    } catch (Throwable $e) {
        vcDeleteDirectoryRecursive($barePath);
        throw $e;
    } finally {
        @unlink($bundlePath);
    }
}

function vcMergeManagedBranches(
    mysqli|PgsqlMysqliCompatConnection $conn,
    array $repository,
    string $repositorySlug,
    string $sourceBranch,
    string $targetBranch,
    string $mergeTitle
): array {
    $bareRepositoryPath = vcGetRepositoryBarePath($repositorySlug);
    if (!is_dir($bareRepositoryPath) && !vcRestoreRepositorySnapshot($conn, $repository)) {
        throw new RuntimeException(
            'The Git repository has no durable snapshot and cannot be merged safely. Push or recreate the repository first.'
        );
    }

    $workingPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erni-pms-merge-' . bin2hex(random_bytes(6));
    try {
        vcRunGitCommand(['clone', $bareRepositoryPath, $workingPath], APP_ROOT_PATH);
        vcRunGitCommand(['config', 'user.name', 'ERNI PMS Architect'], $workingPath);
        vcRunGitCommand(['config', 'user.email', 'noreply@erni-pms.local'], $workingPath);
        vcRunGitCommand(['checkout', $targetBranch], $workingPath);

        $mergeResult = vcRunProcess(
            [vcGetGitBinary(), 'merge', '--no-ff', 'origin/' . $sourceBranch, '-m', $mergeTitle],
            $workingPath
        );
        if ((int) ($mergeResult['exit_code'] ?? 1) !== 0) {
            $conflictResult = vcRunProcess(
                [vcGetGitBinary(), 'diff', '--name-only', '--diff-filter=U'],
                $workingPath
            );
            $conflictedFiles = array_values(array_filter(array_map(
                'trim',
                preg_split('/\R/', (string) ($conflictResult['stdout'] ?? '')) ?: []
            )));
            vcRunProcess([vcGetGitBinary(), 'merge', '--abort'], $workingPath);

            if ($conflictedFiles) {
                return [
                    'status' => 'conflict',
                    'conflicted_files' => $conflictedFiles,
                    'message' => 'Merge conflict in: ' . implode(', ', $conflictedFiles),
                ];
            }
            throw new RuntimeException(
                trim((string) ($mergeResult['stderr'] ?? $mergeResult['stdout'] ?? 'Git merge failed.'))
            );
        }

        $mergeHash = trim((string) vcRunGitCommand(['rev-parse', 'HEAD'], $workingPath)['stdout']);
        $mergeUser = getCurrentUser();
        if (!$mergeUser || $mergeUser['role'] !== 'architect') {
            throw new RuntimeException('Only an Architect can merge branches.');
        }
        vcInstallServerRepositoryHooks($bareRepositoryPath, rtrim(BASE_URL, '/') . '/api/version-control/sync.php?token=' . urlencode((string) $repository['webhook_token']));
        vcRunGitCommand(['push', '-o', 'erni-pms-token=' . vcPersonalPushToken($repository, $mergeUser), 'origin', 'HEAD:refs/heads/' . $targetBranch], $workingPath);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'update-server-info'], APP_ROOT_PATH);
        vcSaveRepositorySnapshot($conn, (int) ($repository['id'] ?? 0), $repositorySlug);

        $nameStatus = vcRunGitCommand(['show', '--pretty=', '--name-status', $mergeHash], $workingPath);
        $numstat = vcRunGitCommand(['show', '--pretty=', '--numstat', $mergeHash], $workingPath);
        $changes = vcParseNameStatusOutput((string) ($nameStatus['stdout'] ?? ''));
        $stats = vcParseNumstatOutput((string) ($numstat['stdout'] ?? ''));
        foreach ($stats as $changeKey => $fileStats) {
            if (isset($changes[$changeKey])) {
                $changes[$changeKey]['additions'] = (int) ($fileStats['additions'] ?? 0);
                $changes[$changeKey]['deletions'] = (int) ($fileStats['deletions'] ?? 0);
            }
        }

        return [
            'status' => 'merged',
            'commit_hash' => $mergeHash,
            'files' => array_values($changes),
        ];
    } finally {
        vcDeleteDirectoryRecursive($workingPath);
    }
}

function vcWriteTextFile(string $path, string $contents): void
{
    vcEnsureDirectory(dirname($path));

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Failed to write file: ' . $path);
    }
}

function vcCopyFile(string $sourcePath, string $targetPath): void
{
    $contents = @file_get_contents($sourcePath);
    if ($contents === false) {
        throw new RuntimeException('Failed to read file: ' . $sourcePath);
    }

    vcWriteTextFile($targetPath, $contents);
}

function vcShellDoubleQuotedValue(string $value): string
{
    return str_replace(
        ['\\', '"', '$', '`'],
        ['\\\\', '\\"', '\\$', '\\`'],
        $value
    );
}

function vcGenerateLocalSyncHookScript(string $syncEndpointUrl, ?string $pmsUsername = null): string
{
    $syncEndpointUrl = vcShellDoubleQuotedValue($syncEndpointUrl);
    $pmsUsername = vcShellDoubleQuotedValue(trim((string) $pmsUsername));

    return "#!/bin/sh\n" .
        "PMS_URL=\"" . $syncEndpointUrl . "\"\n" .
        "PMS_USERNAME=\"" . $pmsUsername . "\"\n" .
        "if [ -z \"\$PMS_USERNAME\" ]; then\n" .
        "  PMS_USERNAME=\$(git config --get erni-pms.username || true)\n" .
        "fi\n" .
        "json_escape() {\n" .
        "  printf '%s' \"\$1\" | sed 's/\\\\/\\\\\\\\/g; s/\"/\\\\\"/g' | tr '\\n' ' '\n" .
        "}\n" .
        "BRANCH=\$(git rev-parse --abbrev-ref HEAD)\n" .
        "COMMIT_HASH=\$(git rev-parse HEAD)\n" .
        "TITLE=\$(git log -1 --pretty=%s)\n" .
        "BODY=\$(git log -1 --pretty=%B)\n" .
        "AUTHOR_NAME=\$(git log -1 --pretty=%an)\n" .
        "AUTHOR_EMAIL=\$(git log -1 --pretty=%ae)\n" .
        "REMOTE_URL=\$(git config --get remote.origin.url)\n" .
        "FILE_CHANGES_B64=\$(git show --pretty=\"\" --name-status \"\$COMMIT_HASH\" | base64 | tr -d '\\n')\n" .
        "FILE_STATS_B64=\$(git show --pretty=\"\" --numstat \"\$COMMIT_HASH\" | base64 | tr -d '\\n')\n" .
        "PAYLOAD=\$(printf '{\"event_type\":\"commit\",\"branch_name\":\"%s\",\"commit_hash\":\"%s\",\"commit_title\":\"%s\",\"commit_message\":\"%s\",\"author_name\":\"%s\",\"author_email\":\"%s\",\"pms_username\":\"%s\",\"remote_url\":\"%s\",\"platform\":\"git-client\",\"file_changes_b64\":\"%s\",\"file_stats_b64\":\"%s\"}' \"\$(json_escape \"\$BRANCH\")\" \"\$(json_escape \"\$COMMIT_HASH\")\" \"\$(json_escape \"\$TITLE\")\" \"\$(json_escape \"\$BODY\")\" \"\$(json_escape \"\$AUTHOR_NAME\")\" \"\$(json_escape \"\$AUTHOR_EMAIL\")\" \"\$(json_escape \"\$PMS_USERNAME\")\" \"\$(json_escape \"\$REMOTE_URL\")\" \"\$(json_escape \"\$FILE_CHANGES_B64\")\" \"\$(json_escape \"\$FILE_STATS_B64\")\")\n" .
        "curl --connect-timeout 3 --max-time 10 -s -X POST \"\$PMS_URL\" -H \"Content-Type: application/json\" -d \"\$PAYLOAD\" >/dev/null 2>&1\n";
}

function vcInstallServerRepositoryPolicy(string $bareRepositoryPath): void
{
    $hooksPath = $bareRepositoryPath . DIRECTORY_SEPARATOR . 'hooks';
    vcEnsureDirectory($hooksPath);
    $shellQuote = static fn(string $value): string => "'" . str_replace("'", "'\\''", str_replace('\\', '/', $value)) . "'";
    $phpName = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
    $php = 'php';
    foreach ([getenv('PHP_CLI_BINARY') ?: '', PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' ? PHP_BINARY : '',
        dirname(php_ini_loaded_file() ?: __FILE__) . DIRECTORY_SEPARATOR . $phpName,
        PHP_BINDIR . DIRECTORY_SEPARATOR . $phpName] as $candidate) {
        if ($candidate !== '' && is_file($candidate)) { $php = $candidate; break; }
    }
    $preReceive = "#!/bin/sh\nRESULT=$(" . $shellQuote($php) . ' '
        . $shellQuote(dirname(__DIR__) . '/bin/git-pre-receive.php') . ' '
        . $shellQuote(basename($bareRepositoryPath, '.git')) . ") || exit 1\n"
        . '[ "$RESULT" = "ERNI_PMS_PUSH_OK" ] || { echo "ERNI PMS could not authorize this push." >&2; exit 1; }' . "\n";
    vcWriteTextFile($hooksPath . DIRECTORY_SEPARATOR . 'pre-receive', $preReceive);
    @chmod($hooksPath . DIRECTORY_SEPARATOR . 'pre-receive', 0755);
    vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'config', 'receive.advertisePushOptions', 'true'], APP_ROOT_PATH);
}

function vcInstallServerRepositoryHooks(string $bareRepositoryPath, string $syncEndpointUrl): void
{
    vcInstallServerRepositoryPolicy($bareRepositoryPath);
    $hooksPath = $bareRepositoryPath . DIRECTORY_SEPARATOR . 'hooks';
    $quoteHookArg = static fn(string $value): string => "'" . str_replace("'", "'\\''", str_replace('\\', '/', $value)) . "'";
    $taskSyncCommand = $quoteHookArg(PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'))
        . ' ' . $quoteHookArg(__DIR__ . '/../bin/git-post-receive.php') . ' ' . $quoteHookArg(basename($bareRepositoryPath, '.git'));

    $postUpdate = "#!/bin/sh\n" .
        "git update-server-info\n";

    $postReceive = "#!/bin/sh\n" .
        "PMS_URL=\"" . $syncEndpointUrl . "\"\n" .
        "json_escape() {\n" .
        "  printf '%s' \"\$1\" | sed 's/\\\\/\\\\\\\\/g; s/\"/\\\\\"/g' | tr '\\n' ' '\n" .
        "}\n" .
        "while read OLD_REV NEW_REV REF_NAME; do\n" .
        "  [ \"\$NEW_REV\" = \"0000000000000000000000000000000000000000\" ] && continue\n" .
        '  ' . $taskSyncCommand . ' "$OLD_REV" "$NEW_REV" "$REF_NAME"' . "\n" .
        "  command -v curl >/dev/null 2>&1 || continue\n" .
        "  BRANCH=\${REF_NAME#refs/heads/}\n" .
        "  TITLE=\$(git log -1 --pretty=%s \"\$NEW_REV\")\n" .
        "  BODY=\$(git log -1 --pretty=%B \"\$NEW_REV\")\n" .
        "  AUTHOR_NAME=\$(git log -1 --pretty=%an \"\$NEW_REV\")\n" .
        "  AUTHOR_EMAIL=\$(git log -1 --pretty=%ae \"\$NEW_REV\")\n" .
        "  REMOTE_URL=\"" . vcGenerateRepositoryHttpCloneUrl(basename($bareRepositoryPath, '.git')) . "\"\n" .
        "  FILE_CHANGES_B64=\$(git show --pretty=\"\" --name-status \"\$NEW_REV\" | base64 | tr -d '\\n')\n" .
        "  FILE_STATS_B64=\$(git show --pretty=\"\" --numstat \"\$NEW_REV\" | base64 | tr -d '\\n')\n" .
        "  PUSH_USERNAME=\${REMOTE_USER:-}\n" .
        "  PAYLOAD=\$(printf '{\"event_type\":\"push\",\"branch_name\":\"%s\",\"commit_hash\":\"%s\",\"commit_title\":\"%s\",\"commit_message\":\"%s\",\"author_name\":\"%s\",\"author_email\":\"%s\",\"pms_username\":\"%s\",\"remote_url\":\"%s\",\"platform\":\"server-git\",\"file_changes_b64\":\"%s\",\"file_stats_b64\":\"%s\"}' \"\$(json_escape \"\$BRANCH\")\" \"\$(json_escape \"\$NEW_REV\")\" \"\$(json_escape \"\$TITLE\")\" \"\$(json_escape \"\$BODY\")\" \"\$(json_escape \"\$AUTHOR_NAME\")\" \"\$(json_escape \"\$AUTHOR_EMAIL\")\" \"\$(json_escape \"\$PUSH_USERNAME\")\" \"\$(json_escape \"\$REMOTE_URL\")\" \"\$(json_escape \"\$FILE_CHANGES_B64\")\" \"\$(json_escape \"\$FILE_STATS_B64\")\")\n" .
        "  curl --connect-timeout 3 --max-time 10 -s -X POST \"\$PMS_URL\" -H \"Content-Type: application/json\" -d \"\$PAYLOAD\" >/dev/null 2>&1\n" .
        "done\n";

    vcWriteTextFile($hooksPath . DIRECTORY_SEPARATOR . 'post-update', $postUpdate);
    vcWriteTextFile($hooksPath . DIRECTORY_SEPARATOR . 'post-receive', $postReceive);
    @chmod($hooksPath . DIRECTORY_SEPARATOR . 'post-update', 0755);
    @chmod($hooksPath . DIRECTORY_SEPARATOR . 'post-receive', 0755);
}

function vcEnsureWorkspaceStorageRoot(): void
{
    $workspaceRoot = vcGetRepositoryWorkspaceRoot();
    vcEnsureDirectory($workspaceRoot);

    $workspaceHtaccess = $workspaceRoot . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($workspaceHtaccess)) {
        vcWriteTextFile(
            $workspaceHtaccess,
            "Options -Indexes\n" .
            "Require all denied\n"
        );
    }
}

function vcInstallWorkspaceSyncHook(string $workspacePath): void
{
    $exampleHookPath = $workspacePath . DIRECTORY_SEPARATOR . '.erni-pms' . DIRECTORY_SEPARATOR . 'post-commit.example.sh';
    if (!is_file($exampleHookPath)) {
        return;
    }

    $hookPath = $workspacePath . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . 'post-commit';
    vcCopyFile($exampleHookPath, $hookPath);
    @chmod($hookPath, 0755);
}

function vcConfigureLocalRepositoryRemote(
    string $repositoryPath,
    string $slug,
    string $defaultBranch,
    ?string $externalRemoteUrl = null,
    bool $portableClient = false,
    ?string $webhookToken = null,
    ?string $pushUsername = null
): void {
    $remoteUrl = vcResolvePreferredWritableRemoteUrl(
        $slug,
        $externalRemoteUrl,
        $portableClient,
        $webhookToken,
        $pushUsername
    );
    $hasOrigin = vcTryGitCommand(['remote', 'get-url', 'origin'], $repositoryPath);

    if ($remoteUrl === null) {
        if ($hasOrigin) {
            vcTryGitCommand(['remote', 'remove', 'origin'], $repositoryPath);
        }
        return;
    }

    if ($hasOrigin) {
        vcRunGitCommand(['remote', 'set-url', 'origin', $remoteUrl], $repositoryPath);
    } else {
        vcRunGitCommand(['remote', 'add', 'origin', $remoteUrl], $repositoryPath);
    }

    $defaultBranch = trim($defaultBranch);
    if ($defaultBranch !== '') {
        vcTryGitCommand(['branch', '--set-upstream-to=origin/' . $defaultBranch, $defaultBranch], $repositoryPath);
    }

    if ($portableClient) {
        vcRunGitCommand(['config', 'push.autoSetupRemote', 'true'], $repositoryPath);
        vcRunGitCommand(['config', 'push.default', 'current'], $repositoryPath);
        vcRunGitCommand(['config', 'remote.pushDefault', 'origin'], $repositoryPath);
        vcRunGitCommand(['config', 'fetch.prune', 'true'], $repositoryPath);
        vcRunGitCommand(['config', 'checkout.defaultRemote', 'origin'], $repositoryPath);
        vcRunGitCommand(['config', 'pull.rebase', 'false'], $repositoryPath);
    }
}

function vcCreateRepositoryWorkspace(string $slug, string $defaultBranch, string $httpCloneUrl, ?string $externalRemoteUrl = null): array
{
    $workspacePath = vcGetRepositoryWorkspacePath($slug);

    if (is_dir($workspacePath)) {
        throw new RuntimeException('A working folder already exists for this repository.');
    }

    vcEnsureWorkspaceStorageRoot();

    try {
        $cloneSource = vcGetRepositoryBarePath($slug);
        vcRunGitCommand(['clone', '--branch=' . $defaultBranch, $cloneSource, $workspacePath], APP_ROOT_PATH);
        vcConfigureLocalRepositoryRemote($workspacePath, $slug, $defaultBranch, $externalRemoteUrl, false);
        vcInstallWorkspaceSyncHook($workspacePath);
    } catch (Throwable $e) {
        vcDeleteDirectoryRecursive($workspacePath);
        throw new RuntimeException('Failed to create the working folder. ' . $e->getMessage());
    }

    return [
        'workspace_path' => $workspacePath,
    ];
}

function vcBuildRepositoryReadme(
    string $projectName,
    string $repositoryName,
    string $httpCloneUrl,
    string $localClonePath,
    string $workspacePath
): string
{
    return "# " . $repositoryName . "\n\n" .
        "Repository created by ERNI PMS for project `" . $projectName . "`.\n\n" .
        "## Ready-To-Use Folder\n\n" .
        "ERNI PMS can create a working folder for you automatically at:\n\n" .
        "```text\n" .
        str_replace('\\', '/', $workspacePath) . "\n" .
        "```\n\n" .
        "Open that folder to start working immediately.\n\n" .
        "## Clone\n\n" .
        "HTTP clone:\n\n" .
        "```bash\n" .
        "git clone " . $httpCloneUrl . "\n" .
        "```\n\n" .
        "Local server path clone:\n\n" .
        "```bash\n" .
        "git clone \"" . str_replace('\\', '/', $localClonePath) . "\"\n" .
        "```\n\n" .
        "## Push Authentication\n\n" .
        "Use your PMS account password or the personal credential from a fresh repository download to push. Only an Architect may update main.\n\n" .
        "## PMS Sync\n\n" .
        "A sample hook is included in `.erni-pms/post-commit.example.sh`.\n" .
        "Copy it into `.git/hooks/post-commit` inside your cloned repository to sync commits back to ERNI PMS automatically.\n";
}

function vcBuildRepositoryGitignore(): string
{
    return ".DS_Store\n" .
        "Thumbs.db\n" .
        ".idea/\n" .
        ".vscode/\n" .
        "node_modules/\n" .
        "vendor/\n" .
        "dist/\n" .
        "build/\n" .
        ".env\n" .
        ".env.*\n";
}

function vcCreateManagedRepository(
    int $projectId,
    string $projectName,
    string $repositoryName,
    string $slug,
    string $defaultBranch,
    string $syncEndpointUrl
): array {
    $storageRoot = vcGetRepositoryStorageRoot();
    $bareRepositoryPath = vcGetRepositoryBarePath($slug);
    $workspacePath = vcGetRepositoryWorkspacePath($slug);
    $httpCloneUrl = vcGenerateRepositoryHttpCloneUrl($slug);
    $localClonePath = vcGenerateRepositoryLocalClonePath($slug);
    $fileCloneUrl = vcGenerateRepositoryFileCloneUrl($slug);
    $tempWorkingPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erni-pms-' . $slug . '-' . bin2hex(random_bytes(4));

    if (is_dir($bareRepositoryPath)) {
        throw new RuntimeException('A repository folder already exists for this slug.');
    }
    if (is_dir($workspacePath)) {
        throw new RuntimeException('A working folder already exists for this slug.');
    }

    vcEnsureDirectory($storageRoot);
    $storageHtaccess = $storageRoot . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($storageHtaccess)) {
        vcWriteTextFile(
            $storageHtaccess,
            "Options -Indexes\n" .
            "<FilesMatch \"\\.(php|phtml|phar)$\">\n" .
            "    Require all denied\n" .
            "</FilesMatch>\n"
        );
    }

    try {
        vcEnsureDirectory($tempWorkingPath);
        vcRunGitCommand(['init', '--bare', '--initial-branch=' . $defaultBranch, $bareRepositoryPath], APP_ROOT_PATH);

        vcWriteTextFile(
            $tempWorkingPath . DIRECTORY_SEPARATOR . 'README.md',
            vcBuildRepositoryReadme($projectName, $repositoryName, $httpCloneUrl, $localClonePath, $workspacePath)
        );
        vcWriteTextFile($tempWorkingPath . DIRECTORY_SEPARATOR . '.gitignore', vcBuildRepositoryGitignore());
        vcWriteTextFile(
            $tempWorkingPath . DIRECTORY_SEPARATOR . '.erni-pms' . DIRECTORY_SEPARATOR . 'post-commit.example.sh',
            vcGenerateLocalSyncHookScript($syncEndpointUrl)
        );
        @chmod($tempWorkingPath . DIRECTORY_SEPARATOR . '.erni-pms' . DIRECTORY_SEPARATOR . 'post-commit.example.sh', 0755);
        vcWriteTextFile(
            $tempWorkingPath . DIRECTORY_SEPARATOR . '.erni-pms' . DIRECTORY_SEPARATOR . 'README.md',
            "Copy `post-commit.example.sh` to `.git/hooks/post-commit` after cloning this repository to sync commits back to ERNI PMS.\n"
        );

        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'config', 'user.name', 'ERNI PMS'], APP_ROOT_PATH);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'config', 'user.email', 'noreply@erni-pms.local'], APP_ROOT_PATH);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'config', 'http.receivepack', 'true'], APP_ROOT_PATH);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'config', 'http.uploadpack', 'true'], APP_ROOT_PATH);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, '--work-tree=' . $tempWorkingPath, 'add', '.'], APP_ROOT_PATH);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, '--work-tree=' . $tempWorkingPath, 'commit', '-m', 'Initial repository scaffold'], APP_ROOT_PATH);
        vcInstallServerRepositoryHooks($bareRepositoryPath, $syncEndpointUrl);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'update-server-info'], APP_ROOT_PATH);
        vcCreateRepositoryWorkspace($slug, $defaultBranch, $httpCloneUrl, null);
    } catch (Throwable $e) {
        vcDeleteDirectoryRecursive($tempWorkingPath);
        vcDeleteDirectoryRecursive($bareRepositoryPath);
        vcDeleteDirectoryRecursive($workspacePath);
        throw $e;
    }

    vcDeleteDirectoryRecursive($tempWorkingPath);

    return [
        'http_clone_url' => $httpCloneUrl,
        'local_clone_path' => $localClonePath,
        'file_clone_url' => $fileCloneUrl,
        'bare_repository_path' => $bareRepositoryPath,
        'workspace_path' => $workspacePath,
    ];
}

function vcCreateRepositoryArchive(
    string $slug,
    string $refName,
    ?string $externalRemoteUrl = null,
    ?string $webhookToken = null,
    ?array $downloadUser = null,
    string $defaultBranch = 'main'
): array
{
    $bareRepositoryPath = vcGetRepositoryBarePath($slug);
    if (!is_dir($bareRepositoryPath)) {
        throw new RuntimeException('Repository storage was not found on the server at: ' . $bareRepositoryPath);
    }
    $personalToken = vcPersonalPushToken(['slug' => $slug, 'webhook_token' => $webhookToken], $downloadUser ?? []);
    vcInstallServerRepositoryHooks($bareRepositoryPath, rtrim(BASE_URL, '/') . '/api/version-control/sync.php?token=' . urlencode((string) $webhookToken));

    error_log('Starting archive creation for: ' . $slug . ' at ' . $bareRepositoryPath);

    $tempDir = sys_get_temp_dir();
    $cloneTempDir = tempnam($tempDir, 'erni-pms-clone-');
    if ($cloneTempDir === false) {
        throw new RuntimeException('Failed to prepare temporary clone directory.');
    }

    @unlink($cloneTempDir);
    if (!mkdir($cloneTempDir, 0755, true)) {
        throw new RuntimeException('Failed to create temporary clone directory at: ' . $cloneTempDir);
    }

    $archiveBase = tempnam($tempDir, 'erni-pms-archive-');
    if ($archiveBase === false) {
        throw new RuntimeException('Failed to prepare temporary archive file.');
    }

    @unlink($archiveBase);
    $archivePath = $archiveBase . '.zip';

    try {
        // Clone the bare repository to create a working directory with .git folder
        // Use local file path instead of URL for better reliability
        try {
            // Normalize path for git (forward slashes and file:// protocol for Windows)
            $repoPathForGit = $bareRepositoryPath;
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // On Windows, convert to forward slashes for git
                $repoPathForGit = str_replace('\\', '/', $repoPathForGit);
                // If it's a local path, git clone should work directly
            }
            
            error_log('Attempting git clone from: ' . $repoPathForGit . ' to ' . $cloneTempDir);
            
            vcRunGitCommand(
                ['clone', '--quiet', $repoPathForGit, $cloneTempDir],
                $tempDir
            );
            error_log('Git clone completed successfully');
        } catch (Throwable $gitError) {
            error_log('Git clone failed: ' . $gitError->getMessage());
            throw new RuntimeException('Failed to clone repository: ' . $gitError->getMessage());
        }

        // Verify .git directory exists after clone
        $gitDir = $cloneTempDir . DIRECTORY_SEPARATOR . '.git';
        if (!is_dir($gitDir)) {
            error_log('ERROR: .git directory not found at: ' . $gitDir);
            throw new RuntimeException('Git directory not found after clone at: ' . $gitDir . '. Clone may have failed.');
        }
        
        error_log('.git directory found successfully at: ' . $gitDir);

        // Checkout the specified branch/reference
        try {
            error_log('Attempting checkout of: ' . $refName);
            vcRunGitCommand(
                ['checkout', $refName],
                $cloneTempDir
            );

            $downloadUsername = trim((string) ($downloadUser['username'] ?? ''));
            $downloadFullName = trim((string) ($downloadUser['full_name'] ?? ''));
            $downloadEmail = trim((string) ($downloadUser['email'] ?? ''));
            if ($downloadUsername !== '') {
                vcRunGitCommand(['config', 'erni-pms.username', $downloadUsername], $cloneTempDir);
            }
            if ($downloadFullName !== '') {
                vcRunGitCommand(['config', 'user.name', $downloadFullName], $cloneTempDir);
            }
            if ($downloadEmail !== '') {
                vcRunGitCommand(['config', 'user.email', $downloadEmail], $cloneTempDir);
            }

            vcConfigureLocalRepositoryRemote(
                $cloneTempDir,
                $slug,
                $refName,
                $externalRemoteUrl,
                true,
                $personalToken,
                $downloadUsername
            );
            // Push to the PMS-managed remote so its server-side policy applies.
            $managedPushUrl = vcIsRenderEnvironment()
                ? vcGenerateRepositoryAuthenticatedHttpCloneUrl($slug, $personalToken, $downloadUsername)
                : vcGenerateRepositoryFileCloneUrl($slug);
            vcRunGitCommand(['remote', 'set-url', '--push', 'origin', $managedPushUrl], $cloneTempDir);
            vcRunGitCommand(['config', '--add', 'push.pushOption', 'erni-pms-token=' . $personalToken], $cloneTempDir);
            error_log('Checkout completed successfully');
        } catch (Throwable $checkoutError) {
            error_log('Checkout failed: ' . $checkoutError->getMessage());
            throw new RuntimeException('Failed to checkout branch: ' . $checkoutError->getMessage());
        }

        // Install git hooks in the cloned repository
        $hooksDir = $gitDir . DIRECTORY_SEPARATOR . 'hooks';
        vcEnsureDirectory($hooksDir);

        // Create post-commit hook with sync functionality
        $syncUrl = getenv('ERNI_PMS_SYNC_ENDPOINT')
            ?: rtrim(BASE_URL, '/') . '/api/version-control/sync.php?token=' . urlencode((string) $webhookToken);
        $postCommitHook = vcGenerateLocalSyncHookScript($syncUrl, $downloadUsername ?? '');
        $postCommitPath = $hooksDir . DIRECTORY_SEPARATOR . 'post-commit';
        vcWriteTextFile($postCommitPath, $postCommitHook);
        @chmod($postCommitPath, 0755);
        foreach (['post-merge', 'post-rewrite'] as $hookName) {
            vcWriteTextFile($hooksDir . DIRECTORY_SEPARATOR . $hookName, $postCommitHook);
            @chmod($hooksDir . DIRECTORY_SEPARATOR . $hookName, 0755);
        }
        foreach (['pre-merge-commit' => false, 'pre-push' => true] as $hookName => $prePush) {
            vcWriteTextFile($hooksDir . DIRECTORY_SEPARATOR . $hookName, vcClientProtectionHook((string) ($downloadUser['role'] ?? ''), $defaultBranch, $prePush));
            @chmod($hooksDir . DIRECTORY_SEPARATOR . $hookName, 0755);
        }
        
        error_log('Post-commit hook installed');

        // Create .erni-pms directory with example hook documentation
        $erniPmsDir = $cloneTempDir . DIRECTORY_SEPARATOR . '.erni-pms';
        vcEnsureDirectory($erniPmsDir);
        vcWriteTextFile($erniPmsDir . DIRECTORY_SEPARATOR . 'BRANCH_POLICY.md',
            "# Branch and merge policy\n\nOnly an Architect may update main, the default branch, or protected branches.\n\n"
            . "Developers: create a feature branch (`git switch -c feature/your-change`), commit and push it, then submit a pull request from the task in ERNI PMS. The Architect performs the final merge.\n\n"
            . "This download includes pre-merge and pre-push checks, plus post-commit, post-merge and post-rewrite reporting. Local hooks provide guidance; the PMS-managed server enforces role and project access.\n\n"
            . "The push remote points to ERNI PMS. Your personal Git credential is stored in .git/config; do not share this download. Download again after a password or role change.\n\n"
            . "Unpushed local merges can be reported to the Architect but do not change the shared main branch. Push authorized changes to publish them.\n\n"
            . "External Git providers require their own branch protection rules if you push to them directly. Local administrators with direct write access to server Git storage must be restricted through operating-system permissions.\n");

        $hookExample = vcGenerateLocalSyncHookScript($syncUrl, $downloadUsername ?? '');
        $hookExamplePath = $erniPmsDir . DIRECTORY_SEPARATOR . 'post-commit.example.sh';
        vcWriteTextFile($hookExamplePath, $hookExample);
        @chmod($hookExamplePath, 0755);

        // Create README for hooks
        $hooksReadme = "# ERNI PMS Git Hooks\n\n" .
            "This directory contains configuration and example files for Git hooks.\n\n" .
            "## post-commit.example.sh\n\n" .
            "This is an example hook that automatically syncs your commits back to ERNI PMS.\n\n" .
            "### Installation:\n" .
            "The hook is already installed at `.git/hooks/post-commit` and will run automatically after each commit.\n\n" .
            "### What it does:\n" .
            "- Captures commit information (hash, message, files changed)\n" .
            "- Sends it to the ERNI PMS system for tracking\n" .
            "- Enables real-time collaboration tracking\n\n" .
            "### Sync Endpoint:\n" .
            "Configured URL: " . $syncUrl . "\n\n" .
            "### Manual Usage:\n" .
            "If you need to disable the hook temporarily, you can:\n" .
            "```bash\n" .
            "# Disable\n" .
            "chmod -x .git/hooks/post-commit\n\n" .
            "# Re-enable\n" .
            "chmod +x .git/hooks/post-commit\n" .
            "```\n";
        vcWriteTextFile($erniPmsDir . DIRECTORY_SEPARATOR . 'HOOKS_README.md', $hooksReadme);

        // Create a .gitignore for the .erni-pms directory (optional)
        $gitignore = ".erni-pms/*.log\n";
        vcWriteTextFile($erniPmsDir . DIRECTORY_SEPARATOR . '.gitignore', $gitignore);

        // Log what will be archived for debugging
        error_log('Creating ZIP archive from: ' . $cloneTempDir);

        // Create a ZIP archive including the .git folder
        vcCreateZipArchive($cloneTempDir, $archivePath);

        // Verify archive was created and has content
        if (!file_exists($archivePath) || filesize($archivePath) === 0) {
            throw new RuntimeException('Archive was created but is empty or inaccessible.');
        }

        error_log('Archive created successfully: ' . $archivePath . ' (' . filesize($archivePath) . ' bytes)');
    } catch (Throwable $e) {
        @unlink($archivePath);
        throw $e;
    } finally {
        // Clean up temporary clone directory
        if (is_dir($cloneTempDir)) {
            vcDeleteDirectoryRecursive($cloneTempDir);
        }
    }

    return [
        'archive_path' => $archivePath,
        'download_name' => vcGenerateSlug($slug) . '-' . vcGenerateSlug($refName) . '.zip',
    ];
}

function vcCreateZipArchive(string $sourceDir, string $zipPath): void
{
    // Try using ZipArchive if available
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($files as $file) {
                $filePath = (string) $file;
                $relativePath = substr($filePath, strlen($sourceDir) + 1);

                if (is_dir($filePath)) {
                    $zip->addEmptyDir($relativePath);
                } else {
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();
            return;
        }
    }

    // Try using system zip command (Windows, Linux, Mac)
    $zipBinary = vcFindZipBinary();
    if ($zipBinary !== null) {
        $result = vcRunProcess(
            [$zipBinary, '-r', '-q', $zipPath, '.'],
            $sourceDir
        );

        if ((int) $result['exit_code'] === 0) {
            return;
        }
    }

    // Fallback: Use pure PHP ZIP creation
    vcCreateZipArchivePurePhp($sourceDir, $zipPath);
}

function vcCreateZipArchivePurePhp(string $sourceDir, string $zipPath): void
{
    $fp = fopen($zipPath, 'w+b');
    if ($fp === false) {
        throw new RuntimeException('Failed to create ZIP file.');
    }

    $files = [];
    $centralDirOffset = 0;

    try {
        // Get all files recursively, including hidden directories
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $filePath = (string) $file;
            $relativePath = str_replace('\\', '/', substr($filePath, strlen($sourceDir) + 1));

            // Skip empty relative paths
            if ($relativePath === '' || $relativePath === '.') {
                continue;
            }

            if (is_dir($filePath)) {
                // Add directory entry with trailing slash
                if (substr($relativePath, -1) !== '/') {
                    $relativePath .= '/';
                }
                $files[] = [
                    'path' => $relativePath,
                    'isDir' => true,
                    'time' => filemtime($filePath) ?: 0,
                ];
            } else {
                // Add file entry
                $files[] = [
                    'path' => $relativePath,
                    'isDir' => false,
                    'size' => filesize($filePath) ?: 0,
                    'time' => filemtime($filePath) ?: 0,
                    'filePath' => $filePath,
                ];
            }
        }

        // Sort files for consistent ordering
        usort($files, static fn($a, $b) => strcmp($a['path'], $b['path']));

        $centralDir = '';
        $fileOffset = 0;

        // Write local file headers and data
        foreach ($files as $file) {
            $path = $file['path'];
            $dosTime = vcToDosTime($file['time']);

            if ($file['isDir']) {
                // Directory entry
                $localHeader = vcCreateZipLocalFileHeader($path, $dosTime, 0, 0);
                fwrite($fp, $localHeader);
                $fileOffset += strlen($localHeader);

                // Central directory record
                $centralRec = vcCreateZipCentralDirRecord($path, $dosTime, 0, 0, $fileOffset - strlen($localHeader));
                $centralDir .= $centralRec;
            } else {
                // File entry
                $fileContent = @file_get_contents($file['filePath']);
                if ($fileContent === false) {
                    continue;
                }

                $crc32 = crc32($fileContent);
                $localHeader = vcCreateZipLocalFileHeader($path, $dosTime, strlen($fileContent), $crc32);

                fwrite($fp, $localHeader);
                fwrite($fp, $fileContent);

                $fileOffset += strlen($localHeader) + strlen($fileContent);

                // Central directory record
                $centralRec = vcCreateZipCentralDirRecord($path, $dosTime, strlen($fileContent), $crc32, $fileOffset - strlen($fileContent) - strlen($localHeader));
                $centralDir .= $centralRec;
            }
        }

        // Write central directory
        $centralDirOffset = $fileOffset;
        fwrite($fp, $centralDir);

        // Write end of central directory record
        $endCdr = vcCreateZipEndCentralDirRecord(count($files), strlen($centralDir), $centralDirOffset);
        fwrite($fp, $endCdr);

        if (count($files) === 0) {
            throw new RuntimeException('No files found to archive. Archive is empty.');
        }
    } finally {
        fclose($fp);
    }
}

function vcToDosTime(int $timestamp): int
{
    $dt = new DateTime('@' . $timestamp, new DateTimeZone('UTC'));

    $dos_date = (($dt->format('Y') - 1980) << 9) | ($dt->format('n') << 5) | $dt->format('j');
    $dos_time = ($dt->format('H') << 11) | ($dt->format('i') << 5) | ($dt->format('s') >> 1);

    return ($dos_date << 16) | $dos_time;
}

function vcCreateZipLocalFileHeader(string $filename, int $dosTime, int $fileSize, int $crc32): string
{
    $header = pack('V', 0x04034b50); // Local file header signature
    $header .= pack('v', 20); // Version needed to extract
    $header .= pack('v', 0); // General purpose bit flag
    $header .= pack('v', 0); // Compression method (0 = stored)
    $header .= pack('v', $dosTime & 0xFFFF); // Last mod file time
    $header .= pack('v', ($dosTime >> 16) & 0xFFFF); // Last mod file date
    $header .= pack('V', $crc32); // CRC-32
    $header .= pack('V', $fileSize); // Compressed size
    $header .= pack('V', $fileSize); // Uncompressed size
    $header .= pack('v', strlen($filename)); // Filename length
    $header .= pack('v', 0); // Extra field length
    $header .= $filename;

    return $header;
}

function vcCreateZipCentralDirRecord(string $filename, int $dosTime, int $fileSize, int $crc32, int $offset): string
{
    $record = pack('V', 0x02014b50); // Central directory file header signature
    $record .= pack('v', 20); // Version made by
    $record .= pack('v', 20); // Version needed to extract
    $record .= pack('v', 0); // General purpose bit flag
    $record .= pack('v', 0); // Compression method
    $record .= pack('v', $dosTime & 0xFFFF); // Last mod file time
    $record .= pack('v', ($dosTime >> 16) & 0xFFFF); // Last mod file date
    $record .= pack('V', $crc32); // CRC-32
    $record .= pack('V', $fileSize); // Compressed size
    $record .= pack('V', $fileSize); // Uncompressed size
    $record .= pack('v', strlen($filename)); // Filename length
    $record .= pack('v', 0); // Extra field length
    $record .= pack('v', 0); // File comment length
    $record .= pack('v', 0); // Disk number start
    $record .= pack('v', 0); // Internal file attributes
    $record .= pack('V', 0); // External file attributes
    $record .= pack('V', $offset); // Relative offset of local header
    $record .= $filename;

    return $record;
}

function vcCreateZipEndCentralDirRecord(int $fileCount, int $centralDirSize, int $centralDirOffset): string
{
    $record = pack('V', 0x06054b50); // End of central directory signature
    $record .= pack('v', 0); // Disk number
    $record .= pack('v', 0); // Disk with central directory
    $record .= pack('v', $fileCount); // Number of central directory records on this disk
    $record .= pack('v', $fileCount); // Total number of central directory records
    $record .= pack('V', $centralDirSize); // Size of central directory
    $record .= pack('V', $centralDirOffset); // Offset of central directory
    $record .= pack('v', 0); // Comment length
    // No comment

    return $record;
}

function vcFindZipBinary(): ?string
{
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    $zipCandidates = [];

    if ($isWindows) {
        $zipCandidates = [
            'zip',
            'zip.exe',
            'C:\\Program Files\\Git\\usr\\bin\\zip.exe',
            'C:\\Program Files (x86)\\Git\\usr\\bin\\zip.exe',
            'C:\\msys64\\usr\\bin\\zip.exe',
            'C:\\msys32\\usr\\bin\\zip.exe',
            getenv('ProgramFiles') . '\\Git\\usr\\bin\\zip.exe',
        ];
    } else {
        $zipCandidates = [
            'zip',
            '/usr/bin/zip',
            '/bin/zip',
            '/usr/local/bin/zip',
        ];
    }

    foreach ($zipCandidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }

        // Try via shell command
        $testCmd = $isWindows ? "where $candidate" : "which $candidate";
        $output = shell_exec($testCmd . ' 2>/dev/null');
        if ($output) {
            $path = trim($output);
            if (is_executable($path)) {
                return $path;
            }
        }
    }

    return null;
}

function vcGenerateCommitHash(int $repositoryId, int $branchId, int $userId, string $title): string
{
    return substr(sha1($repositoryId . '|' . $branchId . '|' . $userId . '|' . $title . '|' . microtime(true) . '|' . random_int(1000, 9999)), 0, 12);
}

function vcTouchRepository(mysqli|PgsqlMysqliCompatConnection $conn, int $repositoryId): void
{
    $stmt = $conn->prepare('UPDATE project_repositories SET last_activity_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $repositoryId);
    $stmt->execute();
    $stmt->close();
}

function vcLogOperation(
    mysqli|PgsqlMysqliCompatConnection $conn,
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

function vcGetDefaultBranch(mysqli|PgsqlMysqliCompatConnection $conn, int $repositoryId): ?array
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

function vcFindRepositoryByWebhookToken(mysqli|PgsqlMysqliCompatConnection $conn, string $token): ?array
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

function vcFindOrCreateBranch(mysqli|PgsqlMysqliCompatConnection $conn, int $repositoryId, string $branchName, int $userId, ?string $commitHash = null): array
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

function vcFindCommitByHash(mysqli|PgsqlMysqliCompatConnection $conn, int $repositoryId, string $commitHash): ?array
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

function vcEnsureCommitFileTrackingSchema(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    if (DB_DRIVER === 'pgsql') {
        $statements = [
            "
                CREATE TABLE IF NOT EXISTS repository_commit_files (
                    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                    commit_id INT NOT NULL,
                    file_path VARCHAR(500) NOT NULL,
                    previous_file_path VARCHAR(500) NULL,
                    change_type VARCHAR(20) DEFAULT 'modified',
                    additions INT DEFAULT 0,
                    deletions INT DEFAULT 0,
                    diff_excerpt TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (commit_id) REFERENCES repository_commits(id) ON DELETE CASCADE
                )
            ",
            'CREATE INDEX IF NOT EXISTS idx_commit_file_commit_id ON repository_commit_files (commit_id)',
            'CREATE INDEX IF NOT EXISTS idx_commit_file_change_type ON repository_commit_files (change_type)',
            'CREATE INDEX IF NOT EXISTS idx_commit_file_path ON repository_commit_files (file_path)',
        ];

        foreach ($statements as $statement) {
            if ($conn->query($statement) === false) {
                throw new RuntimeException('Failed to prepare repository commit file tracking.');
            }
        }
    } else {
        $sql = "
            CREATE TABLE IF NOT EXISTS repository_commit_files (
                id INT PRIMARY KEY AUTO_INCREMENT,
                commit_id INT NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                previous_file_path VARCHAR(500) NULL,
                change_type ENUM('added', 'modified', 'deleted', 'renamed') DEFAULT 'modified',
                additions INT DEFAULT 0,
                deletions INT DEFAULT 0,
                diff_excerpt LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (commit_id) REFERENCES repository_commits(id) ON DELETE CASCADE,
                INDEX idx_commit_file_commit_id (commit_id),
                INDEX idx_commit_file_change_type (change_type),
                INDEX idx_commit_file_path (file_path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if ($conn->query($sql) === false) {
            throw new RuntimeException('Failed to prepare repository commit file tracking.');
        }
    }

    $initialized = true;
}

function vcNormalizeCommitFileChangeType(string $type): string
{
    $normalized = strtolower(trim($type));

    return match ($normalized) {
        'a', 'added', 'add', 'new' => 'added',
        'd', 'deleted', 'delete', 'remove', 'removed' => 'deleted',
        'r', 'renamed', 'rename' => 'renamed',
        default => 'modified',
    };
}

function vcNormalizeCommitFilePath(string $path): string
{
    $normalized = trim($path, " \t\n\r\0\x0B'\"");
    $normalized = str_replace('\\', '/', $normalized);

    return trim($normalized);
}

function vcCommitFileKey(string $path, ?string $previousPath = null): string
{
    return strtolower($path . '|' . ($previousPath ?? ''));
}

function vcParseCommitFilesText(string $text): array
{
    $changes = [];
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        $segments = explode('|', $trimmed, 2);
        $definition = trim($segments[0]);
        $statBlock = trim($segments[1] ?? '');
        $additions = 0;
        $deletions = 0;

        if ($statBlock !== '' && preg_match('/\+?(\d+|-)\s*-\s*(\d+|-)/', $statBlock, $matches)) {
            $additions = ctype_digit($matches[1]) ? (int) $matches[1] : 0;
            $deletions = ctype_digit($matches[2]) ? (int) $matches[2] : 0;
        }

        if (!preg_match('/^([A-Za-z]+)\s+(.+)$/', $definition, $matches)) {
            continue;
        }

        $changeType = vcNormalizeCommitFileChangeType($matches[1]);
        $pathValue = trim($matches[2]);
        $previousPath = null;

        if ($changeType === 'renamed' && strpos($pathValue, '->') !== false) {
            [$oldPath, $newPath] = array_map('trim', explode('->', $pathValue, 2));
            $previousPath = vcNormalizeCommitFilePath($oldPath);
            $pathValue = $newPath;
        }

        $filePath = vcNormalizeCommitFilePath($pathValue);
        if ($filePath === '') {
            continue;
        }

        $changes[] = [
            'path' => $filePath,
            'previous_path' => $previousPath ?: null,
            'change_type' => $changeType,
            'additions' => $additions,
            'deletions' => $deletions,
            'diff_excerpt' => null,
        ];
    }

    return $changes;
}

function vcParseNameStatusOutput(string $text): array
{
    $changes = [];
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        $parts = preg_split('/\t+/', $trimmed) ?: [];
        if (count($parts) < 2) {
            continue;
        }

        $statusToken = strtoupper((string) $parts[0]);
        $changeType = vcNormalizeCommitFileChangeType(substr($statusToken, 0, 1));
        $previousPath = null;
        $path = '';

        if ($changeType === 'renamed' && count($parts) >= 3) {
            $previousPath = vcNormalizeCommitFilePath((string) $parts[1]);
            $path = vcNormalizeCommitFilePath((string) $parts[2]);
        } else {
            $path = vcNormalizeCommitFilePath((string) $parts[1]);
        }

        if ($path === '') {
            continue;
        }

        $changes[vcCommitFileKey($path, $previousPath)] = [
            'path' => $path,
            'previous_path' => $previousPath ?: null,
            'change_type' => $changeType,
            'additions' => 0,
            'deletions' => 0,
            'diff_excerpt' => null,
        ];
    }

    return $changes;
}

function vcParseNumstatOutput(string $text): array
{
    $stats = [];
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        $parts = preg_split('/\t+/', $trimmed) ?: [];
        if (count($parts) < 3) {
            continue;
        }

        $additions = ctype_digit((string) $parts[0]) ? (int) $parts[0] : 0;
        $deletions = ctype_digit((string) $parts[1]) ? (int) $parts[1] : 0;
        $pathInfo = trim(implode("\t", array_slice($parts, 2)));
        $previousPath = null;
        $path = $pathInfo;

        if (strpos($pathInfo, '=>') !== false) {
            if (preg_match('/^(.*?)\s*=>\s*(.*)$/', $pathInfo, $matches)) {
                $previousPath = vcNormalizeCommitFilePath($matches[1]);
                $path = vcNormalizeCommitFilePath($matches[2]);
            }
        } else {
            $path = vcNormalizeCommitFilePath($pathInfo);
        }

        if ($path === '') {
            continue;
        }

        $stats[vcCommitFileKey($path, $previousPath)] = [
            'additions' => $additions,
            'deletions' => $deletions,
        ];
    }

    return $stats;
}

function vcNormalizeCommitFiles(array $changes): array
{
    $normalized = [];

    foreach ($changes as $change) {
        if (!is_array($change)) {
            continue;
        }

        $path = vcNormalizeCommitFilePath((string) ($change['path'] ?? $change['file_path'] ?? ''));
        $previousPath = vcNormalizeCommitFilePath((string) ($change['previous_path'] ?? $change['old_path'] ?? ''));
        $changeType = vcNormalizeCommitFileChangeType((string) ($change['change_type'] ?? $change['status'] ?? 'modified'));
        $diffExcerpt = trim((string) ($change['diff_excerpt'] ?? $change['patch'] ?? ''));

        if ($path === '') {
            continue;
        }

        $normalized[vcCommitFileKey($path, $previousPath)] = [
            'path' => $path,
            'previous_path' => $previousPath !== '' ? $previousPath : null,
            'change_type' => $changeType,
            'additions' => max(0, (int) ($change['additions'] ?? 0)),
            'deletions' => max(0, (int) ($change['deletions'] ?? 0)),
            'diff_excerpt' => $diffExcerpt !== '' ? substr($diffExcerpt, 0, 60000) : null,
        ];
    }

    return array_values($normalized);
}

function vcDecodeSyncTextField(array $payload, string $base64Key, string $textKey): string
{
    $base64Value = trim((string) ($payload[$base64Key] ?? ''));
    if ($base64Value !== '') {
        $decoded = base64_decode($base64Value, true);
        if ($decoded !== false) {
            return $decoded;
        }
    }

    return (string) ($payload[$textKey] ?? '');
}

function vcResolveCommitFilesFromPayload(array $payload): array
{
    $fileChanges = $payload['file_changes'] ?? null;
    if (is_array($fileChanges)) {
        return vcNormalizeCommitFiles($fileChanges);
    }

    $statusText = vcDecodeSyncTextField($payload, 'file_changes_b64', 'file_changes_text');
    $numstatText = vcDecodeSyncTextField($payload, 'file_stats_b64', 'file_stats_text');

    if ($statusText !== '') {
        $changesByKey = vcParseNameStatusOutput($statusText);
        $statsByKey = vcParseNumstatOutput($numstatText);

        foreach ($statsByKey as $key => $stats) {
            if (!isset($changesByKey[$key])) {
                continue;
            }
            $changesByKey[$key]['additions'] = $stats['additions'];
            $changesByKey[$key]['deletions'] = $stats['deletions'];
        }

        return array_values($changesByKey);
    }

    $manualText = trim((string) ($payload['commit_files'] ?? ''));
    if ($manualText !== '') {
        return vcParseCommitFilesText($manualText);
    }

    return [];
}

function vcSaveCommitFiles(mysqli|PgsqlMysqliCompatConnection $conn, int $commitId, array $changes): void
{
    if ($commitId <= 0) {
        return;
    }

    $normalizedChanges = vcNormalizeCommitFiles($changes);
    if ($normalizedChanges === []) {
        return;
    }

    vcEnsureCommitFileTrackingSchema($conn);

    $deleteStmt = $conn->prepare('DELETE FROM repository_commit_files WHERE commit_id = ?');
    $deleteStmt->bind_param('i', $commitId);
    $deleteStmt->execute();
    $deleteStmt->close();

    $insertStmt = $conn->prepare(
        'INSERT INTO repository_commit_files (
            commit_id, file_path, previous_file_path, change_type, additions, deletions, diff_excerpt
         ) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($normalizedChanges as $change) {
        $path = $change['path'];
        $previousPath = $change['previous_path'];
        $changeType = $change['change_type'];
        $additions = (int) $change['additions'];
        $deletions = (int) $change['deletions'];
        $diffExcerpt = $change['diff_excerpt'];

        $insertStmt->bind_param('isssiis', $commitId, $path, $previousPath, $changeType, $additions, $deletions, $diffExcerpt);
        $insertStmt->execute();
    }

    $insertStmt->close();
}

function vcGetCommitFiles(mysqli|PgsqlMysqliCompatConnection $conn, int $commitId): array
{
    if ($commitId <= 0) {
        return [];
    }

    vcEnsureCommitFileTrackingSchema($conn);

    $stmt = $conn->prepare(
        'SELECT *
         FROM repository_commit_files
         WHERE commit_id = ?
         ORDER BY CASE change_type
             WHEN \'added\' THEN 1
             WHEN \'modified\' THEN 2
             WHEN \'renamed\' THEN 3
             WHEN \'deleted\' THEN 4
             ELSE 5
         END, file_path ASC'
    );
    $stmt->bind_param('i', $commitId);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $files;
}

function vcResolveExternalCommitUserId(
    mysqli|PgsqlMysqliCompatConnection $conn,
    array $payload,
    int $fallbackUserId
): int {
    $username = trim((string) ($payload['pms_username'] ?? ''));
    if ($username !== '') {
        $stmt = $conn->prepare(
            "SELECT id FROM users
             WHERE LOWER(username) = LOWER(?) AND status = 'active'
             LIMIT 1"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            return (int) $row['id'];
        }
    }

    // Provider webhooks and older local hooks do not carry a PMS username.
    // Match their Git identity to an active PMS account before using the
    // repository creator as the final legacy fallback.
    $authorEmail = trim((string) ($payload['author_email'] ?? ''));
    if ($authorEmail !== '') {
        $stmt = $conn->prepare(
            "SELECT id FROM users
             WHERE LOWER(email) = LOWER(?) AND status = 'active'
             LIMIT 1"
        );
        $stmt->bind_param('s', $authorEmail);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            return (int) $row['id'];
        }
    }

    $authorName = trim((string) ($payload['author_name'] ?? ''));
    if ($authorName !== '') {
        $stmt = $conn->prepare(
            "SELECT id FROM users
             WHERE LOWER(full_name) = LOWER(?) AND status = 'active'
             LIMIT 1"
        );
        $stmt->bind_param('s', $authorName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            return (int) $row['id'];
        }
    }

    return max(1, $fallbackUserId);
}

function vcNotifyMainUpdate(mysqli|PgsqlMysqliCompatConnection $conn, array $repository, string $branch, string $hash, bool $published): void
{
    $projectId = (int) $repository['project_id'];
    $repositoryId = (int) $repository['id'];
    $message = ($published ? 'Shared branch updated: ' : 'Local or external branch change reported; not published to PMS: ')
        . $repository['name'] . ' / ' . $branch . ' at ' . $hash . '. Review the repository activity.';
    $title = $published ? 'Main branch updated' : 'Main branch change reported';
    $url = rtrim(BASE_URL, '/') . '/modules/version-control/repositories.php?project_id=' . $projectId . '&repository_id=' . $repositoryId;
    $stmt = $conn->prepare("SELECT u.id FROM users u JOIN projects p ON p.id = ? WHERE u.role = 'architect' AND u.status = 'active' AND (p.architect_id = u.id OR p.architect_id IS NULL)");
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $architect) {
        $notification = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_table, related_id, action_url)
            SELECT ?, ?, ?, 'system', 'project_repositories', ?, ?
            WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id = ? AND related_table = 'project_repositories' AND related_id = ? AND message = ?)");
        $notification->bind_param('issisiis', $architect['id'], $title, $message, $repositoryId, $url, $architect['id'], $repositoryId, $message);
        $notification->execute();
        $notification->close();
    }
    $stmt->close();
}

function vcReconcileManagedMain(mysqli|PgsqlMysqliCompatConnection $conn, array $repository): void
{
    $barePath = vcGetRepositoryBarePath((string) $repository['slug']);
    if (!is_dir($barePath)) return;
    vcInstallServerRepositoryHooks($barePath, rtrim(BASE_URL, '/') . '/api/version-control/sync.php?token=' . urlencode((string) $repository['webhook_token']));
    foreach (array_unique(['main', (string) ($repository['default_branch_name'] ?: 'main')]) as $branch) {
        $head = vcRunProcess([vcGetGitBinary(), '--git-dir=' . $barePath, 'rev-parse', '--verify', 'refs/heads/' . $branch], APP_ROOT_PATH);
        if ($head['exit_code'] !== 0) continue;
        $hash = trim($head['stdout']);
        $stmt = $conn->prepare('SELECT last_commit_hash FROM repository_branches WHERE repository_id = ? AND name = ?');
        $stmt->bind_param('is', $repository['id'], $branch);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($stored['last_commit_hash'] ?? '') === $hash) continue;
        $title = trim(vcRunGitCommand(['--git-dir=' . $barePath, 'log', '-1', '--pretty=%s', $hash], APP_ROOT_PATH)['stdout']);
        vcSyncExternalCommit($conn, $repository, ['branch_name' => $branch, 'commit_hash' => $hash,
            'commit_title' => $title, 'operation_type' => 'push', 'platform' => 'managed-git-reconciliation']);
    }
}

function vcSyncExternalCommit(mysqli|PgsqlMysqliCompatConnection $conn, array $repository, array $payload): array
{
    $repositoryId = (int) $repository['id'];
    $systemUserId = vcResolveExternalCommitUserId($conn, $payload, (int) ($repository['created_by'] ?? 1));
    $branchName = trim((string) ($payload['branch_name'] ?? $repository['default_branch_name'] ?? 'main'));
    $commitHash = trim((string) ($payload['commit_hash'] ?? ''));
    $commitTitle = trim((string) ($payload['commit_title'] ?? ''));
    $commitMessage = trim((string) ($payload['commit_message'] ?? ''));
    $filesChanged = max(0, (int) ($payload['files_changed'] ?? 0));
    $linesAdded = max(0, (int) ($payload['lines_added'] ?? 0));
    $linesRemoved = max(0, (int) ($payload['lines_removed'] ?? 0));
    $commitFiles = vcResolveCommitFilesFromPayload($payload);
    $operationType = trim((string) ($payload['operation_type'] ?? 'commit'));
    $allowedOperations = ['commit', 'push', 'pull', 'merge'];

    if ($commitFiles !== []) {
        $filesChanged = max($filesChanged, count($commitFiles));
        if ($linesAdded === 0) {
            $linesAdded = array_sum(array_map(static fn(array $change): int => (int) ($change['additions'] ?? 0), $commitFiles));
        }
        if ($linesRemoved === 0) {
            $linesRemoved = array_sum(array_map(static fn(array $change): int => (int) ($change['deletions'] ?? 0), $commitFiles));
        }
    }

    if (!in_array($operationType, $allowedOperations, true)) {
        $operationType = 'commit';
    }

    if ($commitHash === '') {
        $commitHash = substr(sha1($repositoryId . '|' . $branchName . '|' . microtime(true) . '|external'), 0, 12);
    }

    if ($commitTitle === '') {
        $commitTitle = ucfirst($operationType) . ' sync';
    }

    $mainChanged = false;
    if (in_array($branchName, ['main', (string) ($repository['default_branch_name'] ?: 'main')], true)) {
        // A client-supplied username or commit message is not authority to update shared main.
        $barePath = vcGetRepositoryBarePath((string) $repository['slug']);
        $head = is_dir($barePath)
            ? vcRunProcess([vcGetGitBinary(), '--git-dir=' . $barePath, 'rev-parse', '--verify', 'refs/heads/' . $branchName], APP_ROOT_PATH)
            : ['exit_code' => 1, 'stdout' => ''];
        $published = $head['exit_code'] === 0 && hash_equals(trim($head['stdout']), $commitHash);
        if (!$published) {
            vcNotifyMainUpdate($conn, $repository, $branchName, $commitHash, false);
            return ['repository_id' => $repositoryId, 'branch_id' => 0, 'commit_id' => null, 'commit_hash' => $commitHash, 'observed_only' => true];
        }
        $previous = $conn->prepare('SELECT last_commit_hash FROM repository_branches WHERE repository_id = ? AND name = ?');
        $previous->bind_param('is', $repositoryId, $branchName);
        $previous->execute();
        $mainChanged = ($previous->get_result()->fetch_assoc()['last_commit_hash'] ?? '') !== $commitHash;
        $previous->close();
        $parents = trim(vcRunGitCommand(['--git-dir=' . $barePath, 'rev-list', '--parents', '-n', '1', $commitHash], APP_ROOT_PATH)['stdout']);
        if (count(explode(' ', $parents)) > 2) $operationType = 'merge';
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
        } elseif ($commitFiles !== []) {
            $updateCommitStmt = $conn->prepare(
                'UPDATE repository_commits
                 SET files_changed = ?, lines_added = ?, lines_removed = ?
                 WHERE id = ?'
            );
            $updateCommitStmt->bind_param('iiii', $filesChanged, $linesAdded, $linesRemoved, $commitId);
            $updateCommitStmt->execute();
            $updateCommitStmt->close();
        }

        if ($commitId && $commitFiles !== []) {
            vcSaveCommitFiles($conn, (int) $commitId, $commitFiles);
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

        if ($mainChanged) vcNotifyMainUpdate($conn, $repository, $branchName, $commitHash, true);
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
