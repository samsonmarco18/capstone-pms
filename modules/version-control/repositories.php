<?php
require_once '../../includes/auth.php';
require_once '../../includes/version-control.php';
require_once '../../includes/version-control-actions.php';

requireRole(['admin', 'architect', 'developer']);

$conn = getConnection();
$user = getCurrentUser();
$accessibleProjects = vcGetAccessibleProjects($conn, $user);

function vcPageUrl(int $projectId = 0, int $repositoryId = 0): string
{
    $params = [];
    if ($projectId > 0) {
        $params['project_id'] = $projectId;
    }
    if ($repositoryId > 0) {
        $params['repository_id'] = $repositoryId;
    }

    return 'repositories.php' . ($params ? '?' . http_build_query($params) : '');
}

$selectedProjectId = (int) ($_GET['project_id'] ?? 0);
$selectedRepositoryId = (int) ($_GET['repository_id'] ?? 0);

if ($selectedProjectId <= 0 && $accessibleProjects) {
    $selectedProjectId = (int) $accessibleProjects[0]['id'];
}

if ($selectedProjectId > 0 && !vcCanAccessProject($conn, $user, $selectedProjectId)) {
    $selectedProjectId = $accessibleProjects ? (int) $accessibleProjects[0]['id'] : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = vcHandlePostAction($conn, $user, $_POST);
        $_SESSION['success'] = $result['message'] ?? 'Version control action completed.';
        header('Location: ' . vcPageUrl((int) ($result['project_id'] ?? 0), (int) ($result['repository_id'] ?? 0)));
        exit();
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . vcPageUrl((int) ($_POST['project_id'] ?? $selectedProjectId), (int) ($_POST['repository_id'] ?? 0)));
        exit();
    }
}

$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$repositories = [];
if ($selectedProjectId > 0) {
    $repoStmt = $conn->prepare(
        "SELECT pr.*, parent_repo.name AS parent_repository_name,
                COUNT(DISTINCT rb.id) AS branch_count, COUNT(DISTINCT rc.id) AS commit_count
         FROM project_repositories pr
         LEFT JOIN project_repositories parent_repo ON parent_repo.id = pr.parent_repository_id
         LEFT JOIN repository_branches rb ON rb.repository_id = pr.id AND rb.branch_status != 'deleted'
         LEFT JOIN repository_commits rc ON rc.repository_id = pr.id
         WHERE pr.project_id = ?
         GROUP BY pr.id
         ORDER BY COALESCE(pr.last_activity_at, pr.updated_at) DESC, pr.name ASC"
    );
    $repoStmt->bind_param('i', $selectedProjectId);
    $repoStmt->execute();
    $repositories = $repoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $repoStmt->close();
}

if ($selectedRepositoryId <= 0 && $repositories) {
    $selectedRepositoryId = (int) $repositories[0]['id'];
}

$selectedRepository = $selectedRepositoryId > 0 ? vcGetRepository($conn, $selectedRepositoryId) : null;
if ($selectedRepository && ((int) $selectedRepository['project_id'] !== $selectedProjectId || !vcCanAccessRepository($conn, $user, $selectedRepositoryId))) {
    $selectedRepositoryId = 0;
    $selectedRepository = null;
}

if (!$selectedRepository && $repositories) {
    $selectedRepositoryId = (int) $repositories[0]['id'];
    $selectedRepository = vcGetRepository($conn, $selectedRepositoryId);
}

$branches = [];
$commits = [];
$operations = [];
$merges = [];
$syncEndpointUrl = '';
$hookConfigUrl = '';
$gitHookScript = '';

if ($selectedRepository) {
    $branchStmt = $conn->prepare(
        "SELECT rb.*, source_branch.name AS source_branch_name, COUNT(DISTINCT rc.id) AS commit_count
         FROM repository_branches rb
         LEFT JOIN repository_branches source_branch ON source_branch.id = rb.source_branch_id
         LEFT JOIN repository_commits rc ON rc.branch_id = rb.id
         WHERE rb.repository_id = ?
         GROUP BY rb.id
         ORDER BY rb.is_default DESC, rb.created_at ASC"
    );
    $branchStmt->bind_param('i', $selectedRepositoryId);
    $branchStmt->execute();
    $branches = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $branchStmt->close();

    $commitStmt = $conn->prepare(
        "SELECT rc.*, rb.name AS branch_name, u.full_name AS author_name
         FROM repository_commits rc
         INNER JOIN repository_branches rb ON rb.id = rc.branch_id
         INNER JOIN users u ON u.id = rc.authored_by
         WHERE rc.repository_id = ?
         ORDER BY rc.created_at DESC, rc.id DESC
         LIMIT 10"
    );
    $commitStmt->bind_param('i', $selectedRepositoryId);
    $commitStmt->execute();
    $commits = $commitStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $commitStmt->close();

    $operationStmt = $conn->prepare(
        "SELECT ro.*, u.full_name AS user_name, rb.name AS branch_name, related_rb.name AS related_branch_name,
                related_repo.name AS related_repository_name
         FROM repository_operations ro
         INNER JOIN users u ON u.id = ro.user_id
         LEFT JOIN repository_branches rb ON rb.id = ro.branch_id
         LEFT JOIN repository_branches related_rb ON related_rb.id = ro.related_branch_id
         LEFT JOIN project_repositories related_repo ON related_repo.id = ro.related_repository_id
         WHERE ro.repository_id = ?
         ORDER BY ro.created_at DESC, ro.id DESC
         LIMIT 12"
    );
    $operationStmt->bind_param('i', $selectedRepositoryId);
    $operationStmt->execute();
    $operations = $operationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $operationStmt->close();

    $mergeStmt = $conn->prepare(
        "SELECT rm.*, source_branch.name AS source_branch_name, target_branch.name AS target_branch_name, u.full_name AS merged_by_name
         FROM repository_merges rm
         INNER JOIN repository_branches source_branch ON source_branch.id = rm.source_branch_id
         INNER JOIN repository_branches target_branch ON target_branch.id = rm.target_branch_id
         INNER JOIN users u ON u.id = rm.merged_by
         WHERE rm.repository_id = ?
         ORDER BY rm.created_at DESC, rm.id DESC
         LIMIT 8"
    );
    $mergeStmt->bind_param('i', $selectedRepositoryId);
    $mergeStmt->execute();
    $merges = $mergeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mergeStmt->close();

    $syncEndpointUrl = BASE_URL . 'api/version-control/sync.php?token=' . urlencode((string) $selectedRepository['webhook_token']);
    $hookConfigUrl = preg_replace('#/api/version-control/sync\.php\?token=.*$#', '', $syncEndpointUrl) ?: BASE_URL;
    $gitHookScript = "#!/bin/sh\n" .
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
        "PAYLOAD=\$(printf '{\"event_type\":\"commit\",\"branch_name\":\"%s\",\"commit_hash\":\"%s\",\"commit_title\":\"%s\",\"commit_message\":\"%s\",\"author_name\":\"%s\",\"author_email\":\"%s\",\"remote_url\":\"%s\",\"platform\":\"git-client\"}' \"\$(json_escape \"\$BRANCH\")\" \"\$(json_escape \"\$COMMIT_HASH\")\" \"\$(json_escape \"\$TITLE\")\" \"\$(json_escape \"\$BODY\")\" \"\$(json_escape \"\$AUTHOR_NAME\")\" \"\$(json_escape \"\$AUTHOR_EMAIL\")\" \"\$(json_escape \"\$REMOTE_URL\")\")\n" .
        "curl -s -X POST \"\$PMS_URL\" -H \"Content-Type: application/json\" -d \"\$PAYLOAD\" >/dev/null 2>&1\n";
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root{--theme-primary:#1f6392;--theme-dark:#184f73;--gray-50:#f8f9fa;--gray-200:#e9ecef;--gray-600:#6c757d;--gray-900:#212529;}
.vc-header,.vc-card{background:#fff;border:1px solid var(--gray-200);border-radius:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.vc-header{padding:2rem;margin-bottom:1.5rem;background:linear-gradient(135deg,var(--theme-primary),var(--theme-dark));color:#fff;}
.vc-card+.vc-card{margin-top:1.5rem;}
.vc-card .card-head{padding:1rem 1.25rem;border-bottom:1px solid var(--gray-200);font-weight:600;}
.repo-tile{display:block;text-decoration:none;color:inherit;border:1px solid var(--gray-200);border-radius:18px;padding:1rem;background:#fff;height:100%;}
.repo-tile.active,.repo-tile:hover{border-color:rgba(31,99,146,.35);box-shadow:0 8px 18px rgba(31,99,146,.08);transform:translateY(-1px);}
.pill{display:inline-block;padding:.25rem .65rem;border-radius:999px;font-size:.72rem;font-weight:600;}
.pill.blue{background:#dbeafe;color:#1d4ed8;}.pill.green{background:#d1fae5;color:#047857;}.pill.gray{background:#e5e7eb;color:#4b5563;}
.timeline-item{position:relative;padding-left:1.25rem;padding-bottom:1rem;border-left:2px solid var(--gray-200);}
.timeline-item:last-child{border-left-color:transparent;padding-bottom:0;}
.timeline-item:before{content:'';position:absolute;left:-6px;top:2px;width:10px;height:10px;border-radius:50%;background:var(--theme-primary);}
.empty-box{border:1px dashed var(--gray-200);border-radius:18px;padding:2rem;text-align:center;color:var(--gray-600);}
.repo-hero{border:1px solid var(--gray-200);border-radius:18px;padding:1rem 1.1rem;background:linear-gradient(180deg,#fff 0%,#f8fbfd 100%);margin-bottom:1rem;}
.repo-meta{font-size:.85rem;color:var(--gray-600);}
.repo-meta code,.hook-box code{font-size:.8rem;}
.hook-box{background:#0d1117;color:#c9d1d9;border-radius:16px;padding:1rem;overflow:auto;font-size:.78rem;line-height:1.45;}
.setup-note{font-size:.8rem;color:var(--gray-600);}
.setup-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:999px;border:1px solid var(--gray-200);background:#fff;font-size:.75rem;margin:.15rem .2rem .15rem 0;}
</style>

<div class="container-fluid px-4 py-4">
    <div class="vc-header">
        <h2 class="mb-2"><i class="fas fa-code-branch me-2"></i>Project Version Control</h2>
        <p class="mb-0">Manage repositories, branches, commits, pushes, pulls, merges, and forks for each project.</p>
    </div>

    <?php if ($flashSuccess): ?><div class="alert alert-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

    <?php if (!$accessibleProjects): ?>
    <div class="vc-card p-4"><div class="empty-box"><i class="fas fa-folder-open d-block mb-2"></i>No accessible projects yet.</div></div>
    <?php else: ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="vc-card">
                <div class="card-head">Repositories</div>
                <div class="p-4">
                    <form method="GET" class="row g-3 align-items-end mb-4">
                        <div class="col-md-8">
                            <label class="form-label">Project</label>
                            <select name="project_id" class="form-select">
                                <?php foreach ($accessibleProjects as $project): ?>
                                <option value="<?php echo (int) $project['id']; ?>" <?php echo (int) $project['id'] === $selectedProjectId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['name'] . ' - ' . $project['client_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><button class="btn btn-primary w-100" type="submit">Load Project</button></div>
                    </form>

                    <?php if (!$repositories): ?>
                    <div class="empty-box">No repositories yet for this project.</div>
                    <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($repositories as $repo): ?>
                        <div class="col-md-6">
                            <a href="<?php echo htmlspecialchars(vcPageUrl($selectedProjectId, (int) $repo['id'])); ?>" class="repo-tile <?php echo (int) $repo['id'] === $selectedRepositoryId ? 'active' : ''; ?>">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($repo['name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($repo['slug']); ?></div>
                                    </div>
                                    <span class="pill <?php echo $repo['repository_status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($repo['repository_status']); ?></span>
                                </div>
                                <div class="small text-muted mt-2"><?php echo htmlspecialchars($repo['description'] ?: 'No description'); ?></div>
                                <div class="small mt-3">
                                    <strong><?php echo (int) $repo['branch_count']; ?></strong> branches |
                                    <strong><?php echo (int) $repo['commit_count']; ?></strong> commits |
                                    <strong><?php echo htmlspecialchars($repo['default_branch_name']); ?></strong> default
                                </div>
                                <?php if (!empty($repo['parent_repository_name'])): ?>
                                <div class="small text-muted mt-2">Forked from <?php echo htmlspecialchars($repo['parent_repository_name']); ?></div>
                                <?php endif; ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selectedRepository): ?>
            <div class="vc-card">
                <div class="card-head"><?php echo htmlspecialchars($selectedRepository['name']); ?> Snapshot</div>
                <div class="p-4">
                    <div class="repo-hero">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div>
                                <div class="h5 mb-1"><?php echo htmlspecialchars($selectedRepository['project_name']); ?> / <span class="text-primary"><?php echo htmlspecialchars($selectedRepository['name']); ?></span></div>
                                <div class="repo-meta">
                                    <?php echo htmlspecialchars($selectedRepository['description'] ?: 'No repository description yet.'); ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <div><span class="pill <?php echo $selectedRepository['repository_status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($selectedRepository['repository_status']); ?></span></div>
                                <div class="repo-meta mt-2"><?php echo strtoupper(htmlspecialchars((string) $selectedRepository['visibility'])); ?> | <?php echo htmlspecialchars((string) $selectedRepository['sync_mode']); ?></div>
                            </div>
                        </div>
                        <div class="repo-meta mt-3">
                            Default branch: <code><?php echo htmlspecialchars((string) $selectedRepository['default_branch_name']); ?></code>
                            <?php if (!empty($selectedRepository['external_remote_url'])): ?>
                            | Remote: <code><?php echo htmlspecialchars((string) $selectedRepository['external_remote_url']); ?></code>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <h6 class="fw-semibold mb-3">Branches</h6>
                            <?php if (!$branches): ?><div class="empty-box">No branches yet.</div><?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead><tr><th>Branch</th><th>Commits</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($branches as $branch): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($branch['name']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($branch['source_branch_name'] ?: 'Root branch'); ?></div>
                                                <div class="mt-1">
                                                    <?php if ((int) $branch['is_default'] === 1): ?><span class="pill blue">Default</span><?php endif; ?>
                                                    <?php if ((int) $branch['is_protected'] === 1): ?><span class="pill gray">Protected</span><?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo (int) $branch['commit_count']; ?></td>
                                            <td><span class="pill <?php echo $branch['branch_status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($branch['branch_status']); ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-6">
                            <h6 class="fw-semibold mb-3">Recent Commits</h6>
                            <?php if (!$commits): ?><div class="empty-box">No commits yet.</div><?php else: ?>
                            <?php foreach ($commits as $commit): ?>
                            <div class="timeline-item">
                                <div class="fw-semibold"><?php echo htmlspecialchars($commit['commit_title']); ?></div>
                                <div class="small text-muted"><code><?php echo htmlspecialchars(substr($commit['commit_hash'], 0, 7)); ?></code> on <?php echo htmlspecialchars($commit['branch_name']); ?></div>
                                <div class="small text-muted mt-1"><?php echo htmlspecialchars($commit['author_name']); ?> | <?php echo date('M d, Y h:i A', strtotime($commit['created_at'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="vc-card">
                <div class="card-head">Operations and Merges</div>
                <div class="p-4">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <h6 class="fw-semibold mb-3">Recent Operations</h6>
                            <?php if (!$operations): ?><div class="empty-box">No operations recorded yet.</div><?php else: ?>
                            <?php foreach ($operations as $operation): $metadata = $operation['metadata'] ? json_decode($operation['metadata'], true) : []; ?>
                            <div class="timeline-item">
                                <div class="fw-semibold"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $operation['operation_type']))); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($operation['user_name']); ?><?php if ($operation['branch_name']): ?> | <?php echo htmlspecialchars($operation['branch_name']); ?><?php endif; ?></div>
                                <?php if (!empty($metadata['notes'])): ?><div class="small text-muted mt-1"><?php echo htmlspecialchars($metadata['notes']); ?></div><?php endif; ?>
                                <div class="small text-muted mt-1"><?php echo date('M d, Y h:i A', strtotime($operation['created_at'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-5">
                            <h6 class="fw-semibold mb-3">Merge History</h6>
                            <?php if (!$merges): ?><div class="empty-box">No merge history yet.</div><?php else: ?>
                            <?php foreach ($merges as $merge): ?>
                            <div class="border rounded-4 p-3 mb-3">
                                <div class="fw-semibold"><?php echo htmlspecialchars($merge['summary'] ?: ($merge['source_branch_name'] . ' -> ' . $merge['target_branch_name'])); ?></div>
                                <div class="small text-muted mt-1"><?php echo htmlspecialchars($merge['source_branch_name']); ?> -> <?php echo htmlspecialchars($merge['target_branch_name']); ?></div>
                                <div class="small text-muted mt-1"><?php echo htmlspecialchars($merge['merged_by_name']); ?> | <?php echo date('M d, Y h:i A', strtotime($merge['created_at'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-xl-4">
            <div class="vc-card">
                <div class="card-head">Actions</div>
                <div class="p-4">
                    <?php if ($selectedRepository): ?>
                    <div class="mb-4">
                        <h6 class="fw-semibold mb-3">Sync Setup</h6>
                        <div class="setup-chip"><i class="fab fa-github"></i> GitHub webhook</div>
                        <div class="setup-chip"><i class="fas fa-code"></i> VS Code Git</div>
                        <div class="setup-chip"><i class="fab fa-android"></i> Android / Termux</div>
                        <div class="setup-chip"><i class="fas fa-terminal"></i> Git Bash / CLI</div>
                        <div class="mt-3 mb-2">
                            <label class="form-label">Webhook / Hook Sync URL</label>
                            <input class="form-control" readonly value="<?php echo htmlspecialchars($syncEndpointUrl); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Repository Token</label>
                            <input class="form-control" readonly value="<?php echo htmlspecialchars((string) $selectedRepository['webhook_token']); ?>">
                        </div>
                        <div class="setup-note mb-3">
                            For devices other than this XAMPP host, replace `localhost` in the URL with your server IP or domain so VS Code, Android, GitHub, GitLab, or Bitbucket can reach it.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Universal Git Hook Script</label>
                            <div class="hook-box"><code><?php echo htmlspecialchars($gitHookScript); ?></code></div>
                            <div class="setup-note mt-2">
                                Save this as `.git/hooks/post-commit`. Duplicate it for `pre-push` and change `event_type` to `push`. For pull sync, use `post-merge` and change `event_type` to `pull`.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Provider Webhook Notes</label>
                            <div class="setup-note">
                                GitHub, GitLab, and Bitbucket push webhooks are supported. Point the webhook to the sync URL above.
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="accordion" id="vcAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#repoAction">Create Repository</button></h2>
                            <div id="repoAction" class="accordion-collapse collapse show" data-bs-parent="#vcAccordion">
                                <div class="accordion-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="create_repository">
                                        <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                        <div class="mb-2"><input class="form-control" name="repository_name" placeholder="Repository name" required></div>
                                        <div class="mb-2"><input class="form-control" name="repository_slug" placeholder="Slug"></div>
                                        <div class="mb-2"><input class="form-control" name="external_remote_url" placeholder="Remote URL (GitHub/GitLab/Bitbucket/origin URL)"></div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6"><select class="form-select" name="repository_visibility"><option value="internal">Internal</option><option value="private">Private</option><option value="public">Public</option></select></div>
                                            <div class="col-6"><input class="form-control" name="default_branch_name" value="main"></div>
                                        </div>
                                        <div class="mb-2"><textarea class="form-control" name="repository_description" rows="2" placeholder="Description"></textarea></div>
                                        <button class="btn btn-primary w-100">Create</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <?php if ($selectedRepository): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#settingsAction">Sync Settings</button></h2>
                            <div id="settingsAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion"><div class="accordion-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="save_sync_settings">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <div class="mb-2"><input class="form-control" name="external_remote_url" value="<?php echo htmlspecialchars((string) ($selectedRepository['external_remote_url'] ?? '')); ?>" placeholder="https://github.com/org/repo.git"></div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-6">
                                            <select class="form-select" name="sync_mode">
                                                <option value="manual" <?php echo ($selectedRepository['sync_mode'] ?? '') === 'manual' ? 'selected' : ''; ?>>Manual</option>
                                                <option value="git_hook" <?php echo ($selectedRepository['sync_mode'] ?? '') === 'git_hook' ? 'selected' : ''; ?>>Git Hook</option>
                                                <option value="provider_webhook" <?php echo ($selectedRepository['sync_mode'] ?? '') === 'provider_webhook' ? 'selected' : ''; ?>>Provider Webhook</option>
                                            </select>
                                        </div>
                                        <div class="col-6"><input class="form-control" name="sync_provider" value="<?php echo htmlspecialchars((string) ($selectedRepository['sync_provider'] ?? '')); ?>" placeholder="github / gitlab / bitbucket"></div>
                                    </div>
                                    <button class="btn btn-primary w-100">Save Sync Settings</button>
                                </form>
                            </div></div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#branchAction">Create Branch</button></h2>
                            <div id="branchAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion"><div class="accordion-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="create_branch">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <div class="mb-2"><input class="form-control" name="branch_name" placeholder="feature-auth" required></div>
                                    <div class="mb-2"><select class="form-select" name="source_branch_id"><option value="0">Create from scratch</option><?php foreach ($branches as $branch): ?><option value="<?php echo (int) $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_protected" id="protectBranch"><label class="form-check-label" for="protectBranch">Protected</label></div>
                                    <button class="btn btn-primary w-100">Create Branch</button>
                                </form>
                            </div></div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#commitAction">Commit</button></h2>
                            <div id="commitAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion"><div class="accordion-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="create_commit">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <div class="mb-2"><select class="form-select" name="branch_id" required><?php foreach ($branches as $branch): ?><option value="<?php echo (int) $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="mb-2"><input class="form-control" name="commit_title" placeholder="Commit title" required></div>
                                    <div class="mb-2"><textarea class="form-control" name="commit_message" rows="2" placeholder="Commit message"></textarea></div>
                                    <div class="row g-2 mb-2"><div class="col-4"><input class="form-control" type="number" min="0" name="files_changed" value="1"></div><div class="col-4"><input class="form-control" type="number" min="0" name="lines_added" value="0"></div><div class="col-4"><input class="form-control" type="number" min="0" name="lines_removed" value="0"></div></div>
                                    <button class="btn btn-primary w-100">Record Commit</button>
                                </form>
                            </div></div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#syncAction">Push / Pull</button></h2>
                            <div id="syncAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion"><div class="accordion-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="log_sync">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <div class="mb-2"><select class="form-select" name="operation_type"><option value="push">Push</option><option value="pull">Pull</option></select></div>
                                    <div class="mb-2"><select class="form-select" name="branch_id"><option value="0">Repository level</option><?php foreach ($branches as $branch): ?><option value="<?php echo (int) $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="mb-2"><textarea class="form-control" name="operation_notes" rows="2" placeholder="Notes"></textarea></div>
                                    <button class="btn btn-primary w-100">Save Activity</button>
                                </form>
                            </div></div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#mergeAction">Merge</button></h2>
                            <div id="mergeAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion"><div class="accordion-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="merge_branches">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <div class="mb-2"><select class="form-select" name="source_branch_id" required><?php foreach ($branches as $branch): ?><option value="<?php echo (int) $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="mb-2"><select class="form-select" name="target_branch_id" required><?php foreach ($branches as $branch): ?><option value="<?php echo (int) $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="mb-2"><input class="form-control" name="merge_summary" placeholder="Merge summary"></div>
                                    <div class="mb-2"><textarea class="form-control" name="merge_notes" rows="2" placeholder="Notes"></textarea></div>
                                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="close_source_branch" id="closeSource"><label class="form-check-label" for="closeSource">Mark source as merged</label></div>
                                    <button class="btn btn-primary w-100">Merge Branches</button>
                                </form>
                            </div></div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#forkAction">Fork</button></h2>
                            <div id="forkAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion"><div class="accordion-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="fork_repository">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <div class="mb-2"><select class="form-select" name="target_project_id"><?php foreach ($accessibleProjects as $project): ?><option value="<?php echo (int) $project['id']; ?>" <?php echo (int) $project['id'] === $selectedProjectId ? 'selected' : ''; ?>><?php echo htmlspecialchars($project['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="mb-2"><input class="form-control" name="fork_name" value="<?php echo htmlspecialchars($selectedRepository['name'] . ' Fork'); ?>" required></div>
                                    <div class="mb-2"><input class="form-control" name="fork_slug" value="<?php echo htmlspecialchars(vcGenerateSlug($selectedRepository['name'] . '-fork')); ?>"></div>
                                    <div class="mb-2"><textarea class="form-control" name="fork_description" rows="2"><?php echo htmlspecialchars('Forked from ' . $selectedRepository['name']); ?></textarea></div>
                                    <button class="btn btn-primary w-100">Create Fork</button>
                                </form>
                            </div></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
