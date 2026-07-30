<?php
declare(strict_types=1);

require_once __DIR__ . '/version-control.php';

function vcProvisionProjectPlanningRepository(
    mysqli|PgsqlMysqliCompatConnection $conn,
    array $user,
    int $projectId,
    string $projectName
): array {
    $repositoryStmt = $conn->prepare(
        "SELECT id, slug
         FROM project_repositories
         WHERE project_id = ? AND repository_status = 'active'
         ORDER BY id ASC
         LIMIT 1"
    );
    $repositoryStmt->bind_param('i', $projectId);
    $repositoryStmt->execute();
    $repository = $repositoryStmt->get_result()->fetch_assoc() ?: null;
    $repositoryStmt->close();

    if (!$repository) {
        $created = vcHandlePostAction($conn, $user, [
            'action' => 'create_repository',
            'project_id' => $projectId,
            'repository_name' => $projectName,
            'repository_slug' => $projectName . '-' . $projectId,
            'repository_description' => 'Managed repository generated from the approved Architect plan.',
            'repository_visibility' => 'internal',
            'default_branch_name' => 'main',
        ]);
        $repositoryId = (int) ($created['repository_id'] ?? 0);
    } else {
        $repositoryId = (int) $repository['id'];
    }

    if ($repositoryId <= 0) {
        throw new RuntimeException('The project repository could not be prepared.');
    }

    $defaultBranch = vcGetDefaultBranch($conn, $repositoryId);
    if (!$defaultBranch) {
        throw new RuntimeException('The main repository branch could not be found.');
    }
    $repositoryDetails = vcGetRepository($conn, $repositoryId);
    $repositoryUrl = vcGenerateRepositoryHttpCloneUrl((string) ($repositoryDetails['slug'] ?? ''));

    $featureStmt = $conn->prepare(
        "SELECT id, title
         FROM tasks
         WHERE project_id = ? AND task_type = 'feature'
         ORDER BY id ASC"
    );
    $featureStmt->bind_param('i', $projectId);
    $featureStmt->execute();
    $features = $featureStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $featureStmt->close();

    $createdBranches = [];
    $usedNames = ['main' => true];
    foreach ($features as $feature) {
        $baseName = vcGenerateSlug((string) ($feature['title'] ?? 'feature'));
        $branchName = $baseName;
        $suffix = 2;
        while (isset($usedNames[$branchName])) {
            $branchName = $baseName . '-' . $suffix;
            $suffix++;
        }
        $usedNames[$branchName] = true;

        $branchCheck = $conn->prepare(
            'SELECT id FROM repository_branches WHERE repository_id = ? AND name = ? LIMIT 1'
        );
        $branchCheck->bind_param('is', $repositoryId, $branchName);
        $branchCheck->execute();
        $existingBranch = $branchCheck->get_result()->fetch_assoc();
        $branchCheck->close();
        if (!$existingBranch) {
            vcHandlePostAction($conn, $user, [
                'action' => 'create_branch',
                'project_id' => $projectId,
                'repository_id' => $repositoryId,
                'branch_name' => $branchName,
                'source_branch_id' => (int) $defaultBranch['id'],
            ]);
            $createdBranches[] = $branchName;
        }

        $taskId = (int) ($feature['id'] ?? 0);
        $gitUpdateCheck = $conn->prepare(
            "SELECT id
             FROM task_git_updates
             WHERE task_id = ? AND branch_name = ? AND git_action = 'branch_created'
             LIMIT 1"
        );
        $gitUpdateCheck->bind_param('is', $taskId, $branchName);
        $gitUpdateCheck->execute();
        $hasGitUpdate = (bool) $gitUpdateCheck->get_result()->fetch_assoc();
        $gitUpdateCheck->close();
        if (!$hasGitUpdate) {
            $gitAction = 'branch_created';
            $gitStatus = 'active';
            $notes = 'Feature branch generated automatically from the completed Architect plan.';
            $gitUpdateStmt = $conn->prepare(
                'INSERT INTO task_git_updates (
                    task_id, user_id, repository_url, branch_name, git_action, status, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $gitUpdateStmt->bind_param(
                'iisssss',
                $taskId,
                $user['id'],
                $repositoryUrl,
                $branchName,
                $gitAction,
                $gitStatus,
                $notes
            );
            $gitUpdateStmt->execute();
            $gitUpdateStmt->close();
        }
    }

    return [
        'repository_id' => $repositoryId,
        'feature_count' => count($features),
        'created_branches' => $createdBranches,
    ];
}

function vcHandlePostAction(mysqli|PgsqlMysqliCompatConnection $conn, array $user, array $post): array
{
    $action = trim((string) ($post['action'] ?? ''));
    $projectId = (int) ($post['project_id'] ?? 0);
    $repositoryId = (int) ($post['repository_id'] ?? 0);

    if ($action === '') {
        return ['project_id' => $projectId, 'repository_id' => $repositoryId];
    }

    if ($user['role'] === 'sul') {
        throw new RuntimeException('SUL can review Pull Requests only. Final merge is restricted to Architect.');
    }

    if ($user['role'] === 'qa') {
        throw new RuntimeException('QA can view repositories, branches, commits, and changed files only.');
    }

    if ($user['role'] === 'architect' && !in_array($action, ['merge_branches', 'create_repository', 'fork_repository', 'create_branch', 'delete_branch', 'protect_branch', 'log_sync', 'create_working_folder', 'delete_repository'], true)) {
        throw new RuntimeException('Architects can only perform merge, repository creation, branching, and sync operations in version control.');
    }

    if ($action === 'create_repository') {
        if (!vcCanAccessProject($conn, $user, $projectId)) {
            throw new RuntimeException('You do not have access to that project.');
        }

        if (!in_array($user['role'], ['admin', 'architect'], true)) {
            throw new RuntimeException('Only Architects and Admins can create repositories.');
        }

        $name = trim((string) ($post['repository_name'] ?? ''));
        $slug = vcGenerateSlug((string) ($post['repository_slug'] ?? $name));
        $description = trim((string) ($post['repository_description'] ?? ''));
        $externalRemoteUrl = vcNormalizeExternalRemoteUrl((string) ($post['external_remote_url'] ?? ''));
        $visibility = trim((string) ($post['repository_visibility'] ?? 'internal'));
        $defaultBranch = vcGenerateSlug((string) ($post['default_branch_name'] ?? 'main'));
        $allowedVisibility = ['private', 'internal', 'public'];

        if ($name === '') {
            throw new RuntimeException('Repository name is required.');
        }
        if (!in_array($visibility, $allowedVisibility, true)) {
            throw new RuntimeException('Invalid repository visibility.');
        }
        if ($defaultBranch === '') {
            $defaultBranch = 'main';
        }

        $projectStmt = $conn->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
        $projectStmt->bind_param('i', $projectId);
        $projectStmt->execute();
        $projectRow = $projectStmt->get_result()->fetch_assoc();
        $projectStmt->close();

        if (!$projectRow) {
            throw new RuntimeException('Project not found.');
        }

        vcEnsureRepositorySnapshotSchema($conn);
        $conn->begin_transaction();
        $managedRepository = null;
        try {
            $parentRepositoryId = null;
            $repositoryStatus = 'active';
            $lastActivityAt = date('Y-m-d H:i:s');
            $syncMode = 'manual';
            $syncProvider = null;
            $initialRemoteUrl = $externalRemoteUrl;
            $webhookToken = vcGenerateWebhookToken();
            $stmt = $conn->prepare(
                'INSERT INTO project_repositories (
                    project_id, parent_repository_id, name, slug, description,
                    external_remote_url, sync_mode, sync_provider, webhook_token,
                    visibility, default_branch_name, repository_status, last_activity_at, created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'iisssssssssssi',
                $projectId,
                $parentRepositoryId,
                $name,
                $slug,
                $description,
                $initialRemoteUrl,
                $syncMode,
                $syncProvider,
                $webhookToken,
                $visibility,
                $defaultBranch,
                $repositoryStatus,
                $lastActivityAt,
                $user['id']
            );
            $stmt->execute();
            $repositoryId = (int) $stmt->insert_id;
            $stmt->close();

            $syncEndpointUrl = rtrim(BASE_URL, '/') . '/api/version-control/sync.php?token=' . urlencode($webhookToken);
            $managedRepository = vcCreateManagedRepository(
                $projectId,
                (string) $projectRow['name'],
                $name,
                $slug,
                $defaultBranch,
                $syncEndpointUrl
            );

            $sourceBranchId = null;
            $lastCommitHash = null;
            try {
                $branchProbe = vcRunGitCommand(
                    ['--git-dir=' . vcGetRepositoryBarePath($slug), 'rev-parse', $defaultBranch],
                    APP_ROOT_PATH
                );
                $lastCommitHash = trim((string) ($branchProbe['stdout'] ?? ''));
            } catch (Throwable $ignored) {
                $lastCommitHash = null;
            }
            $branchStatus = 'active';
            $isDefault = 1;
            $isProtected = 1;
            $branchStmt = $conn->prepare(
                'INSERT INTO repository_branches (
                    repository_id, source_branch_id, name, last_commit_hash,
                    branch_status, is_default, is_protected, created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $branchStmt->bind_param(
                'iisssiii',
                $repositoryId,
                $sourceBranchId,
                $defaultBranch,
                $lastCommitHash,
                $branchStatus,
                $isDefault,
                $isProtected,
                $user['id']
            );
            $branchStmt->execute();
            $branchId = (int) $branchStmt->insert_id;
            $branchStmt->close();

            if ($lastCommitHash !== null && $lastCommitHash !== '') {
                $nameStatusOutput = vcRunGitCommand(
                    ['--git-dir=' . vcGetRepositoryBarePath($slug), 'show', '--pretty=', '--name-status', $lastCommitHash],
                    APP_ROOT_PATH
                );
                $numstatOutput = vcRunGitCommand(
                    ['--git-dir=' . vcGetRepositoryBarePath($slug), 'show', '--pretty=', '--numstat', $lastCommitHash],
                    APP_ROOT_PATH
                );

                $changesByKey = vcParseNameStatusOutput((string) ($nameStatusOutput['stdout'] ?? ''));
                $statsByKey = vcParseNumstatOutput((string) ($numstatOutput['stdout'] ?? ''));
                foreach ($statsByKey as $changeKey => $stats) {
                    if (isset($changesByKey[$changeKey])) {
                        $changesByKey[$changeKey]['additions'] = (int) $stats['additions'];
                        $changesByKey[$changeKey]['deletions'] = (int) $stats['deletions'];
                    }
                }
                $initialCommitFiles = array_values($changesByKey);
                $initialLinesAdded = array_sum(array_map(static fn(array $change): int => (int) ($change['additions'] ?? 0), $initialCommitFiles));
                $initialLinesRemoved = array_sum(array_map(static fn(array $change): int => (int) ($change['deletions'] ?? 0), $initialCommitFiles));

                $commitKind = 'commit';
                $initialCommitStmt = $conn->prepare(
                    'INSERT INTO repository_commits (
                        repository_id, branch_id, parent_commit_id, commit_hash, commit_title,
                        commit_message, files_changed, lines_added, lines_removed, commit_kind, authored_by
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $parentCommitId = null;
                $commitTitle = 'Initial repository scaffold';
                $commitMessage = 'Initial repository scaffold';
                $filesChanged = count($initialCommitFiles);
                $initialCommitStmt->bind_param(
                    'iiisssiiisi',
                    $repositoryId,
                    $branchId,
                    $parentCommitId,
                    $lastCommitHash,
                    $commitTitle,
                    $commitMessage,
                    $filesChanged,
                    $initialLinesAdded,
                    $initialLinesRemoved,
                    $commitKind,
                    $user['id']
                );
                $initialCommitStmt->execute();
                $initialCommitId = (int) $initialCommitStmt->insert_id;
                $initialCommitStmt->close();

                if ($initialCommitFiles !== []) {
                    vcSaveCommitFiles($conn, $initialCommitId, $initialCommitFiles);
                }
            }

            vcLogOperation($conn, $repositoryId, $branchId, null, (int) $user['id'], 'create_repository', null, null, ['project_id' => $projectId]);
            logActivity((int) $user['id'], 'create_repository', 'project_repositories', $repositoryId, null, ['project_id' => $projectId, 'name' => $name]);
            vcSaveRepositorySnapshot($conn, $repositoryId, $slug);
            $conn->commit();

            return ['project_id' => $projectId, 'repository_id' => $repositoryId, 'message' => 'Repository and working folder created successfully.'];
        } catch (Throwable $e) {
            $conn->rollback();
            if ($managedRepository && !empty($managedRepository['bare_repository_path'])) {
                vcDeleteDirectoryRecursive((string) $managedRepository['bare_repository_path']);
            } else {
                vcDeleteDirectoryRecursive(vcGetRepositoryBarePath($slug));
            }
            if ($managedRepository && !empty($managedRepository['workspace_path'])) {
                vcDeleteDirectoryRecursive((string) $managedRepository['workspace_path']);
            } else {
                vcDeleteDirectoryRecursive(vcGetRepositoryWorkspacePath($slug));
            }
            throw $e;
        }
    }

    if ($action === 'save_sync_settings') {
        $externalRemoteUrl = vcNormalizeExternalRemoteUrl((string) ($post['external_remote_url'] ?? ''));
        $syncMode = trim((string) ($post['sync_mode'] ?? 'manual'));
        $syncProvider = trim((string) ($post['sync_provider'] ?? ''));
        $allowedModes = ['manual', 'git_hook', 'provider_webhook'];

        if (!in_array($syncMode, $allowedModes, true)) {
            throw new RuntimeException('Invalid sync mode.');
        }

        $providerValue = $syncProvider !== '' ? $syncProvider : null;
        $remoteValue = $externalRemoteUrl;

        $stmt = $conn->prepare(
            'UPDATE project_repositories
             SET external_remote_url = ?, sync_mode = ?, sync_provider = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('sssi', $remoteValue, $syncMode, $providerValue, $repositoryId);
        $stmt->execute();
        $stmt->close();

        logActivity((int) $user['id'], 'update_repository_sync', 'project_repositories', $repositoryId, null, ['sync_mode' => $syncMode, 'sync_provider' => $providerValue]);

        return ['project_id' => $projectId, 'repository_id' => $repositoryId, 'message' => 'Repository sync settings updated.'];
    }

    if (!vcCanAccessRepository($conn, $user, $repositoryId)) {
        throw new RuntimeException('You do not have access to that repository.');
    }

    $repository = vcGetRepository($conn, $repositoryId);
    if (!$repository) {
        throw new RuntimeException('Repository not found.');
    }
    $projectId = (int) $repository['project_id'];

    if ($action === 'delete_repository') {
        if (!vcCanDeleteRepository($user)) {
            throw new RuntimeException('Only Admins and Architects can delete repositories.');
        }

        $repositoryName = (string) ($repository['name'] ?? 'repository');
        $repositorySlug = (string) ($repository['slug'] ?? '');
        $projectId = (int) ($repository['project_id'] ?? 0);
        $oldData = $repository;

        $conn->begin_transaction();
        try {
            vcDeleteRepositoryTracking($conn, $repositoryId);
            $fallbackRepositoryId = vcFindProjectFallbackRepositoryId($conn, $projectId);
            logActivity((int) $user['id'], 'delete_repository', 'project_repositories', $repositoryId, $oldData, ['deleted' => true]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        try {
            vcDeleteManagedRepositoryStorage($repositorySlug);
        } catch (Throwable $storageError) {
            error_log('Repository storage cleanup failed for slug ' . $repositorySlug . ': ' . $storageError->getMessage());
        }

        return [
            'project_id' => $projectId,
            'repository_id' => $fallbackRepositoryId,
            'message' => 'Repository "' . $repositoryName . '" deleted successfully.',
        ];
    }

    if ($action === 'create_working_folder') {
        if (in_array($user['role'], ['sul'], true)) {
            throw new RuntimeException('SUL cannot create repository working folders.');
        }

        $workspace = vcCreateRepositoryWorkspace(
            (string) $repository['slug'],
            (string) ($repository['default_branch_name'] ?? 'main'),
            vcGenerateRepositoryHttpCloneUrl((string) $repository['slug']),
            (string) ($repository['external_remote_url'] ?? '')
        );
        vcLogOperation(
            $conn,
            $repositoryId,
            null,
            null,
            (int) $user['id'],
            'create_repository',
            null,
            null,
            ['workspace_path' => $workspace['workspace_path'] ?? null]
        );
        logActivity(
            (int) $user['id'],
            'create_repository_working_folder',
            'project_repositories',
            $repositoryId,
            null,
            ['workspace_path' => $workspace['workspace_path'] ?? null]
        );

        return [
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'message' => 'Working folder created successfully.',
        ];
    }

    if ($action === 'create_branch') {
        $branchName = vcGenerateSlug((string) ($post['branch_name'] ?? ''));
        $sourceBranchId = (int) ($post['source_branch_id'] ?? 0);
        $isProtected = isset($post['is_protected']) ? 1 : 0;

        if ($branchName === '') {
            throw new RuntimeException('Branch name is required.');
        }

        $sourceBranchValue = $sourceBranchId > 0 ? $sourceBranchId : null;
        $lastCommitHash = null;
        if ($sourceBranchId > 0) {
            $sourceStmt = $conn->prepare('SELECT last_commit_hash FROM repository_branches WHERE id = ? AND repository_id = ? LIMIT 1');
            $sourceStmt->bind_param('ii', $sourceBranchId, $repositoryId);
            $sourceStmt->execute();
            $sourceBranch = $sourceStmt->get_result()->fetch_assoc();
            $sourceStmt->close();

            if (!$sourceBranch) {
                throw new RuntimeException('Source branch was not found.');
            }
            $lastCommitHash = $sourceBranch['last_commit_hash'];
        }

        $conn->begin_transaction();
        $createdGitRef = false;
        try {
            $bareRepositoryPath = vcGetRepositoryBarePath((string) ($repository['slug'] ?? ''));
            if (is_dir($bareRepositoryPath) && $lastCommitHash) {
                vcRunGitCommand(
                    ['--git-dir=' . $bareRepositoryPath, 'branch', $branchName, $lastCommitHash],
                    APP_ROOT_PATH
                );
                vcRunGitCommand(['--git-dir=' . $bareRepositoryPath, 'update-server-info'], APP_ROOT_PATH);
                $createdGitRef = true;
            }

            $branchStatus = 'active';
            $isDefault = 0;
            $stmt = $conn->prepare(
                'INSERT INTO repository_branches (
                    repository_id, source_branch_id, name, last_commit_hash,
                    branch_status, is_default, is_protected, created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'iisssiii',
                $repositoryId,
                $sourceBranchValue,
                $branchName,
                $lastCommitHash,
                $branchStatus,
                $isDefault,
                $isProtected,
                $user['id']
            );
            $stmt->execute();
            $branchId = (int) $stmt->insert_id;
            $stmt->close();

            vcTouchRepository($conn, $repositoryId);
            vcLogOperation($conn, $repositoryId, $branchId, null, (int) $user['id'], 'create_branch', null, $sourceBranchValue, ['branch_name' => $branchName]);
            logActivity((int) $user['id'], 'create_branch', 'repository_branches', $branchId, null, ['repository_id' => $repositoryId, 'branch_name' => $branchName]);
            $conn->commit();

            return ['project_id' => $projectId, 'repository_id' => $repositoryId, 'message' => 'Branch created successfully.'];
        } catch (Throwable $e) {
            $conn->rollback();
            if ($createdGitRef) {
                try {
                    vcRunGitCommand(
                        ['--git-dir=' . vcGetRepositoryBarePath((string) ($repository['slug'] ?? '')), 'branch', '-D', $branchName],
                        APP_ROOT_PATH
                    );
                } catch (Throwable $ignored) {
                    // Preserve the original branch-creation error.
                }
            }
            throw $e;
        }
    }

    if ($action === 'create_commit') {
        $branchId = (int) ($post['branch_id'] ?? 0);
        $title = trim((string) ($post['commit_title'] ?? ''));
        $message = trim((string) ($post['commit_message'] ?? ''));
        $filesChanged = max(0, (int) ($post['files_changed'] ?? 0));
        $linesAdded = max(0, (int) ($post['lines_added'] ?? 0));
        $linesRemoved = max(0, (int) ($post['lines_removed'] ?? 0));
        $commitFiles = vcParseCommitFilesText((string) ($post['commit_files'] ?? ''));

        if ($commitFiles !== []) {
            $filesChanged = max($filesChanged, count($commitFiles));
            if ($linesAdded === 0) {
                $linesAdded = array_sum(array_map(static fn(array $change): int => (int) ($change['additions'] ?? 0), $commitFiles));
            }
            if ($linesRemoved === 0) {
                $linesRemoved = array_sum(array_map(static fn(array $change): int => (int) ($change['deletions'] ?? 0), $commitFiles));
            }
        }

        if ($branchId <= 0 || $title === '') {
            throw new RuntimeException('Branch and commit title are required.');
        }

        $branchStmt = $conn->prepare('SELECT * FROM repository_branches WHERE id = ? AND repository_id = ? LIMIT 1');
        $branchStmt->bind_param('ii', $branchId, $repositoryId);
        $branchStmt->execute();
        $branch = $branchStmt->get_result()->fetch_assoc();
        $branchStmt->close();

        if (!$branch) {
            throw new RuntimeException('Branch not found.');
        }

        $parentCommitId = null;
        if (!empty($branch['last_commit_hash'])) {
            $parentStmt = $conn->prepare('SELECT id FROM repository_commits WHERE commit_hash = ? LIMIT 1');
            $parentStmt->bind_param('s', $branch['last_commit_hash']);
            $parentStmt->execute();
            $parentCommitId = $parentStmt->get_result()->fetch_assoc()['id'] ?? null;
            $parentStmt->close();
        }

        $conn->begin_transaction();
        try {
            $commitHash = vcGenerateCommitHash($repositoryId, $branchId, (int) $user['id'], $title);
            $commitKind = 'commit';
            $stmt = $conn->prepare(
                'INSERT INTO repository_commits (
                    repository_id, branch_id, parent_commit_id, commit_hash, commit_title,
                    commit_message, files_changed, lines_added, lines_removed, commit_kind, authored_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'iiisssiiisi',
                $repositoryId,
                $branchId,
                $parentCommitId,
                $commitHash,
                $title,
                $message,
                $filesChanged,
                $linesAdded,
                $linesRemoved,
                $commitKind,
                $user['id']
            );
            $stmt->execute();
            $commitId = (int) $stmt->insert_id;
            $stmt->close();

            if ($commitFiles !== []) {
                vcSaveCommitFiles($conn, $commitId, $commitFiles);
            }

            $updateStmt = $conn->prepare('UPDATE repository_branches SET last_commit_hash = ?, updated_at = NOW() WHERE id = ?');
            $updateStmt->bind_param('si', $commitHash, $branchId);
            $updateStmt->execute();
            $updateStmt->close();

            vcTouchRepository($conn, $repositoryId);
            vcLogOperation($conn, $repositoryId, $branchId, $commitId, (int) $user['id'], 'commit', null, null, ['commit_hash' => $commitHash]);
            logActivity((int) $user['id'], 'create_commit', 'repository_commits', $commitId, null, ['repository_id' => $repositoryId, 'branch_id' => $branchId]);
            $conn->commit();

            return ['project_id' => $projectId, 'repository_id' => $repositoryId, 'message' => 'Commit recorded successfully.'];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    if ($action === 'log_sync') {
        $branchId = (int) ($post['branch_id'] ?? 0);
        $operationType = trim((string) ($post['operation_type'] ?? ''));
        $notes = trim((string) ($post['operation_notes'] ?? ''));

        if (!in_array($operationType, ['push', 'pull'], true)) {
            throw new RuntimeException('Only push and pull are allowed here.');
        }

        $commitId = null;
        if ($branchId > 0) {
            $branchStmt = $conn->prepare(
                'SELECT rc.id
                 FROM repository_branches rb
                 LEFT JOIN repository_commits rc ON rc.commit_hash = rb.last_commit_hash
                 WHERE rb.id = ? AND rb.repository_id = ?
                 LIMIT 1'
            );
            $branchStmt->bind_param('ii', $branchId, $repositoryId);
            $branchStmt->execute();
            $commitId = $branchStmt->get_result()->fetch_assoc()['id'] ?? null;
            $branchStmt->close();
        }

        vcTouchRepository($conn, $repositoryId);
        vcLogOperation($conn, $repositoryId, $branchId > 0 ? $branchId : null, $commitId ? (int) $commitId : null, (int) $user['id'], $operationType, null, null, ['notes' => $notes]);
        logActivity((int) $user['id'], $operationType . '_repository', 'project_repositories', $repositoryId, null, ['branch_id' => $branchId, 'notes' => $notes]);

        return ['project_id' => $projectId, 'repository_id' => $repositoryId, 'message' => ucfirst($operationType) . ' activity recorded.'];
    }

    if ($action === 'merge_branches') {
        $sourceBranchId = (int) ($post['source_branch_id'] ?? 0);
        $targetBranchId = (int) ($post['target_branch_id'] ?? 0);
        $summary = trim((string) ($post['merge_summary'] ?? ''));
        $notes = trim((string) ($post['merge_notes'] ?? ''));
        $closeSourceBranch = isset($post['close_source_branch']);

        if ($sourceBranchId <= 0 || $targetBranchId <= 0 || $sourceBranchId === $targetBranchId) {
            throw new RuntimeException('Choose different source and target branches.');
        }

        $branchStmt = $conn->prepare('SELECT * FROM repository_branches WHERE repository_id = ? AND id IN (?, ?)');
        $branchStmt->bind_param('iii', $repositoryId, $sourceBranchId, $targetBranchId);
        $branchStmt->execute();
        $branchRows = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $branchStmt->close();

        if (count($branchRows) !== 2) {
            throw new RuntimeException('Branch selection is invalid.');
        }

        $branchesById = [];
        foreach ($branchRows as $branchRow) {
            $branchesById[(int) $branchRow['id']] = $branchRow;
        }

        $sourceBranch = $branchesById[$sourceBranchId] ?? null;
        $targetBranch = $branchesById[$targetBranchId] ?? null;
        if (!$sourceBranch || !$targetBranch) {
            throw new RuntimeException('Source or target branch was not found.');
        }

        $mergeTitle = $summary !== '' ? $summary : 'Merge ' . $sourceBranch['name'] . ' into ' . $targetBranch['name'];
        $gitMerge = vcMergeManagedBranches(
            $conn,
            $repository,
            (string) ($repository['slug'] ?? ''),
            (string) $sourceBranch['name'],
            (string) $targetBranch['name'],
            $mergeTitle
        );
        if (($gitMerge['status'] ?? '') === 'conflict') {
            $conflictFiles = array_values($gitMerge['conflicted_files'] ?? []);
            $conflictNotes = trim($notes . PHP_EOL . 'Conflicted files: ' . implode(', ', $conflictFiles));
            $mergeStatus = 'pending';
            $mergeCommitId = null;
            $conflictStmt = $conn->prepare(
                'INSERT INTO repository_merges (
                    repository_id, source_branch_id, target_branch_id, merge_commit_id,
                    merged_by, merge_status, summary, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $conflictStmt->bind_param(
                'iiiiisss',
                $repositoryId,
                $sourceBranchId,
                $targetBranchId,
                $mergeCommitId,
                $user['id'],
                $mergeStatus,
                $mergeTitle,
                $conflictNotes
            );
            $conflictStmt->execute();
            $conflictStmt->close();
            throw new RuntimeException(
                'Merge blocked by conflicts. Resolve these files on ' . $sourceBranch['name']
                . ' and try again: ' . implode(', ', $conflictFiles)
            );
        }

        $parentCommitId = null;
        if (!empty($targetBranch['last_commit_hash'])) {
            $parentStmt = $conn->prepare('SELECT id FROM repository_commits WHERE commit_hash = ? LIMIT 1');
            $parentStmt->bind_param('s', $targetBranch['last_commit_hash']);
            $parentStmt->execute();
            $parentCommitId = $parentStmt->get_result()->fetch_assoc()['id'] ?? null;
            $parentStmt->close();
        }

        $conn->begin_transaction();
        try {
            $mergeHash = (string) ($gitMerge['commit_hash'] ?? '');
            $mergeFiles = array_values($gitMerge['files'] ?? []);
            $commitKind = 'merge';
            $filesChanged = count($mergeFiles);
            $linesAdded = array_sum(array_column($mergeFiles, 'additions'));
            $linesRemoved = array_sum(array_column($mergeFiles, 'deletions'));
            $existingCommit = vcFindCommitByHash($conn, $repositoryId, $mergeHash);
            if ($existingCommit) {
                $mergeCommitId = (int) $existingCommit['id'];
            } else {
                $commitStmt = $conn->prepare(
                    'INSERT INTO repository_commits (
                        repository_id, branch_id, parent_commit_id, commit_hash, commit_title,
                        commit_message, files_changed, lines_added, lines_removed, commit_kind, authored_by
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $commitStmt->bind_param(
                    'iiisssiiisi',
                    $repositoryId,
                    $targetBranchId,
                    $parentCommitId,
                    $mergeHash,
                    $mergeTitle,
                    $notes,
                    $filesChanged,
                    $linesAdded,
                    $linesRemoved,
                    $commitKind,
                    $user['id']
                );
                $commitStmt->execute();
                $mergeCommitId = (int) $commitStmt->insert_id;
                $commitStmt->close();
                if ($mergeFiles) {
                    vcSaveCommitFiles($conn, $mergeCommitId, $mergeFiles);
                }
            }

            $updateTargetStmt = $conn->prepare('UPDATE repository_branches SET last_commit_hash = ?, updated_at = NOW() WHERE id = ?');
            $updateTargetStmt->bind_param('si', $mergeHash, $targetBranchId);
            $updateTargetStmt->execute();
            $updateTargetStmt->close();

            if ($closeSourceBranch) {
                $status = 'merged';
                $sourceUpdateStmt = $conn->prepare('UPDATE repository_branches SET branch_status = ?, updated_at = NOW() WHERE id = ?');
                $sourceUpdateStmt->bind_param('si', $status, $sourceBranchId);
                $sourceUpdateStmt->execute();
                $sourceUpdateStmt->close();
            }

            $mergeStatus = 'merged';
            $mergeStmt = $conn->prepare(
                'INSERT INTO repository_merges (
                    repository_id, source_branch_id, target_branch_id, merge_commit_id,
                    merged_by, merge_status, summary, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $mergeStmt->bind_param('iiiiisss', $repositoryId, $sourceBranchId, $targetBranchId, $mergeCommitId, $user['id'], $mergeStatus, $mergeTitle, $notes);
            $mergeStmt->execute();
            $mergeId = (int) $mergeStmt->insert_id;
            $mergeStmt->close();

            vcTouchRepository($conn, $repositoryId);
            vcLogOperation($conn, $repositoryId, $targetBranchId, $mergeCommitId, (int) $user['id'], 'merge', null, $sourceBranchId, ['merge_id' => $mergeId]);
            logActivity((int) $user['id'], 'merge_branches', 'repository_merges', $mergeId, null, ['repository_id' => $repositoryId, 'source_branch_id' => $sourceBranchId, 'target_branch_id' => $targetBranchId]);
            $conn->commit();

            return [
                'project_id' => $projectId,
                'repository_id' => $repositoryId,
                'merge_id' => $mergeId,
                'commit_hash' => $mergeHash,
                'merge_mode' => (string) ($gitMerge['merge_mode'] ?? 'git'),
                'message' => 'Branches merged successfully.',
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    if ($action === 'fork_repository') {
        $targetProjectId = (int) ($post['target_project_id'] ?? 0);
        if (!vcCanAccessProject($conn, $user, $targetProjectId)) {
            throw new RuntimeException('You do not have access to the target project.');
        }

        $forkName = trim((string) ($post['fork_name'] ?? ($repository['name'] . ' Fork')));
        $forkSlug = vcGenerateSlug((string) ($post['fork_slug'] ?? $forkName));
        $forkDescription = trim((string) ($post['fork_description'] ?? ('Forked from ' . $repository['name'])));
        $defaultBranch = $repository['default_branch_name'] ?: 'main';

        if ($forkName === '') {
            throw new RuntimeException('Fork repository name is required.');
        }

        $sourceDefaultBranch = vcGetDefaultBranch($conn, $repositoryId);

        $conn->begin_transaction();
        try {
            $visibility = $repository['visibility'];
            $repositoryStatus = 'active';
            $lastActivityAt = date('Y-m-d H:i:s');
            $forkExternalRemoteUrl = vcNormalizeExternalRemoteUrl((string) ($repository['external_remote_url'] ?? ''));
            $syncMode = $forkExternalRemoteUrl !== null ? 'git_hook' : 'manual';
            $syncProvider = $repository['sync_provider'] ?? null;
            $webhookToken = vcGenerateWebhookToken();
            $forkStmt = $conn->prepare(
                'INSERT INTO project_repositories (
                    project_id, parent_repository_id, name, slug, description,
                    external_remote_url, sync_mode, sync_provider, webhook_token,
                    visibility, default_branch_name, repository_status, last_activity_at, created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $forkStmt->bind_param(
                'iisssssssssssi',
                $targetProjectId,
                $repositoryId,
                $forkName,
                $forkSlug,
                $forkDescription,
                $forkExternalRemoteUrl,
                $syncMode,
                $syncProvider,
                $webhookToken,
                $visibility,
                $defaultBranch,
                $repositoryStatus,
                $lastActivityAt,
                $user['id']
            );
            $forkStmt->execute();
            $forkRepositoryId = (int) $forkStmt->insert_id;
            $forkStmt->close();

            $sourceBranchRef = $sourceDefaultBranch['id'] ?? null;
            $lastCommitHash = $sourceDefaultBranch['last_commit_hash'] ?? null;
            $branchStatus = 'active';
            $isDefault = 1;
            $isProtected = 1;
            $branchStmt = $conn->prepare(
                'INSERT INTO repository_branches (
                    repository_id, source_branch_id, name, last_commit_hash,
                    branch_status, is_default, is_protected, created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $branchStmt->bind_param(
                'iisssiii',
                $forkRepositoryId,
                $sourceBranchRef,
                $defaultBranch,
                $lastCommitHash,
                $branchStatus,
                $isDefault,
                $isProtected,
                $user['id']
            );
            $branchStmt->execute();
            $forkBranchId = (int) $branchStmt->insert_id;
            $branchStmt->close();

            vcLogOperation($conn, $forkRepositoryId, $forkBranchId, null, (int) $user['id'], 'fork_repository', $repositoryId, $sourceBranchRef ? (int) $sourceBranchRef : null, ['target_project_id' => $targetProjectId]);
            logActivity((int) $user['id'], 'fork_repository', 'project_repositories', $forkRepositoryId, null, ['source_repository_id' => $repositoryId, 'target_project_id' => $targetProjectId]);
            $conn->commit();

            return ['project_id' => $targetProjectId, 'repository_id' => $forkRepositoryId, 'message' => 'Repository fork created successfully.'];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    throw new RuntimeException('Unsupported version control action.');
}
?>
