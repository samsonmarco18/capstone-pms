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

    if ($role === 'sul') {
        $sql = "
            SELECT p.id, p.name, p.status, c.name AS client_name
            FROM projects p
            INNER JOIN clients c ON c.id = p.client_id
            WHERE p.status != 'completed'
            ORDER BY p.name
        ";
        return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
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

function vcGetGitBinary(): string
{
    $configuredBinary = trim((string) (getenv('GIT_BINARY') ?: ''));

    return $configuredBinary !== '' ? $configuredBinary : 'git';
}

function vcGetRepositoryStorageRoot(): string
{
    return APP_ROOT_PATH . DIRECTORY_SEPARATOR . 'git';
}

function vcGetRepositoryBarePath(string $slug): string
{
    return vcGetRepositoryStorageRoot() . DIRECTORY_SEPARATOR . vcGenerateSlug($slug) . '.git';
}

function vcGetRepositoryWorkspaceRoot(): string
{
    return APP_ROOT_PATH . DIRECTORY_SEPARATOR . 'git-workspaces';
}

function vcGetRepositoryWorkspacePath(string $slug): string
{
    return vcGetRepositoryWorkspaceRoot() . DIRECTORY_SEPARATOR . vcGenerateSlug($slug);
}

function vcGenerateRepositoryHttpCloneUrl(string $slug): string
{
    return rtrim(BASE_URL, '/') . '/git/' . rawurlencode(vcGenerateSlug($slug)) . '.git';
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

function vcIsInternalManagedRepositoryUrl(string $url): bool
{
    $normalized = trim($url);
    if ($normalized === '') {
        return false;
    }

    $prefix = rtrim(BASE_URL, '/') . '/git/';

    return str_starts_with($normalized, $prefix) && str_ends_with($normalized, '.git');
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

function vcGenerateLocalSyncHookScript(string $syncEndpointUrl): string
{
    return "#!/bin/sh\n" .
        "PMS_URL=\"" . $syncEndpointUrl . "\"\n" .
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
        "PAYLOAD=\$(printf '{\"event_type\":\"commit\",\"branch_name\":\"%s\",\"commit_hash\":\"%s\",\"commit_title\":\"%s\",\"commit_message\":\"%s\",\"author_name\":\"%s\",\"author_email\":\"%s\",\"remote_url\":\"%s\",\"platform\":\"git-client\",\"file_changes_b64\":\"%s\",\"file_stats_b64\":\"%s\"}' \"\$(json_escape \"\$BRANCH\")\" \"\$(json_escape \"\$COMMIT_HASH\")\" \"\$(json_escape \"\$TITLE\")\" \"\$(json_escape \"\$BODY\")\" \"\$(json_escape \"\$AUTHOR_NAME\")\" \"\$(json_escape \"\$AUTHOR_EMAIL\")\" \"\$(json_escape \"\$REMOTE_URL\")\" \"\$(json_escape \"\$FILE_CHANGES_B64\")\" \"\$(json_escape \"\$FILE_STATS_B64\")\")\n" .
        "curl -s -X POST \"\$PMS_URL\" -H \"Content-Type: application/json\" -d \"\$PAYLOAD\" >/dev/null 2>&1\n";
}

function vcInstallServerRepositoryHooks(string $bareRepositoryPath, string $syncEndpointUrl): void
{
    $hooksPath = $bareRepositoryPath . DIRECTORY_SEPARATOR . 'hooks';
    vcEnsureDirectory($hooksPath);

    $postUpdate = "#!/bin/sh\n" .
        "git update-server-info\n";

    $postReceive = "#!/bin/sh\n" .
        "PMS_URL=\"" . $syncEndpointUrl . "\"\n" .
        "command -v curl >/dev/null 2>&1 || exit 0\n" .
        "json_escape() {\n" .
        "  printf '%s' \"\$1\" | sed 's/\\\\/\\\\\\\\/g; s/\"/\\\\\"/g' | tr '\\n' ' '\n" .
        "}\n" .
        "while read OLD_REV NEW_REV REF_NAME; do\n" .
        "  [ \"\$NEW_REV\" = \"0000000000000000000000000000000000000000\" ] && continue\n" .
        "  BRANCH=\${REF_NAME#refs/heads/}\n" .
        "  TITLE=\$(git log -1 --pretty=%s \"\$NEW_REV\")\n" .
        "  BODY=\$(git log -1 --pretty=%B \"\$NEW_REV\")\n" .
        "  AUTHOR_NAME=\$(git log -1 --pretty=%an \"\$NEW_REV\")\n" .
        "  AUTHOR_EMAIL=\$(git log -1 --pretty=%ae \"\$NEW_REV\")\n" .
        "  REMOTE_URL=\"" . vcGenerateRepositoryHttpCloneUrl(basename($bareRepositoryPath, '.git')) . "\"\n" .
        "  FILE_CHANGES_B64=\$(git show --pretty=\"\" --name-status \"\$NEW_REV\" | base64 | tr -d '\\n')\n" .
        "  FILE_STATS_B64=\$(git show --pretty=\"\" --numstat \"\$NEW_REV\" | base64 | tr -d '\\n')\n" .
        "  PAYLOAD=\$(printf '{\"event_type\":\"push\",\"branch_name\":\"%s\",\"commit_hash\":\"%s\",\"commit_title\":\"%s\",\"commit_message\":\"%s\",\"author_name\":\"%s\",\"author_email\":\"%s\",\"remote_url\":\"%s\",\"platform\":\"server-git\",\"file_changes_b64\":\"%s\",\"file_stats_b64\":\"%s\"}' \"\$(json_escape \"\$BRANCH\")\" \"\$(json_escape \"\$NEW_REV\")\" \"\$(json_escape \"\$TITLE\")\" \"\$(json_escape \"\$BODY\")\" \"\$(json_escape \"\$AUTHOR_NAME\")\" \"\$(json_escape \"\$AUTHOR_EMAIL\")\" \"\$(json_escape \"\$REMOTE_URL\")\" \"\$(json_escape \"\$FILE_CHANGES_B64\")\" \"\$(json_escape \"\$FILE_STATS_B64\")\")\n" .
        "  curl -s -X POST \"\$PMS_URL\" -H \"Content-Type: application/json\" -d \"\$PAYLOAD\" >/dev/null 2>&1\n" .
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

function vcCreateRepositoryWorkspace(string $slug, string $defaultBranch, string $httpCloneUrl): array
{
    $workspacePath = vcGetRepositoryWorkspacePath($slug);

    if (is_dir($workspacePath)) {
        throw new RuntimeException('A working folder already exists for this repository.');
    }

    vcEnsureWorkspaceStorageRoot();

    try {
        vcRunGitCommand(['clone', '--branch=' . $defaultBranch, $httpCloneUrl, $workspacePath], APP_ROOT_PATH);
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
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, '--work-tree=' . $tempWorkingPath, 'add', '.'], APP_ROOT_PATH);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, '--work-tree=' . $tempWorkingPath, 'commit', '-m', 'Initial repository scaffold'], APP_ROOT_PATH);
        vcInstallServerRepositoryHooks($bareRepositoryPath, $syncEndpointUrl);
        vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'update-server-info'], APP_ROOT_PATH);
        vcCreateRepositoryWorkspace($slug, $defaultBranch, $httpCloneUrl);
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

function vcCreateRepositoryArchive(string $slug, string $refName): array
{
    $bareRepositoryPath = vcGetRepositoryBarePath($slug);
    if (!is_dir($bareRepositoryPath)) {
        throw new RuntimeException('Repository storage was not found on the server at: ' . $bareRepositoryPath);
    }

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
            error_log('Clone directory contents: ' . print_r(scandir($cloneTempDir), true));
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
            error_log('Checkout completed successfully');
        } catch (Throwable $checkoutError) {
            error_log('Checkout failed: ' . $checkoutError->getMessage());
            throw new RuntimeException('Failed to checkout branch: ' . $checkoutError->getMessage());
        }

        // Install git hooks in the cloned repository
        $hooksDir = $gitDir . DIRECTORY_SEPARATOR . 'hooks';
        vcEnsureDirectory($hooksDir);

        // Create post-commit hook with sync functionality
        $syncUrl = getenv('ERNI_PMS_SYNC_ENDPOINT') ?: 'http://localhost/erni-pms/api/version-control/sync.php';
        $postCommitHook = vcGenerateLocalSyncHookScript($syncUrl);
        $postCommitPath = $hooksDir . DIRECTORY_SEPARATOR . 'post-commit';
        vcWriteTextFile($postCommitPath, $postCommitHook);
        @chmod($postCommitPath, 0755);
        
        error_log('Post-commit hook installed');

        // Create .erni-pms directory with example hook documentation
        $erniPmsDir = $cloneTempDir . DIRECTORY_SEPARATOR . '.erni-pms';
        vcEnsureDirectory($erniPmsDir);

        $hookExample = vcGenerateLocalSyncHookScript($syncUrl);
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

function vcEnsureCommitFileTrackingSchema(mysqli $conn): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

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

function vcSaveCommitFiles(mysqli $conn, int $commitId, array $changes): void
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

function vcGetCommitFiles(mysqli $conn, int $commitId): array
{
    if ($commitId <= 0) {
        return [];
    }

    vcEnsureCommitFileTrackingSchema($conn);

    $stmt = $conn->prepare(
        'SELECT *
         FROM repository_commit_files
         WHERE commit_id = ?
         ORDER BY FIELD(change_type, \'added\', \'modified\', \'renamed\', \'deleted\'), file_path ASC'
    );
    $stmt->bind_param('i', $commitId);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $files;
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
