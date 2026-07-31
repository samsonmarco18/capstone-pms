<?php
require_once '../../includes/auth.php';
require_once '../../includes/version-control.php';
require_once '../../includes/version-control-actions.php';

requireRole(['admin', 'architect', 'developer', 'sul', 'qa']);

$conn = getConnection();
$user = getCurrentUser();
$accessibleProjects = vcGetAccessibleProjects($conn, $user);
$execAvailable = vcIsExecAvailable();

foreach ($accessibleProjects as &$accessibleProject) {
    $accessibleProjectId = (int) ($accessibleProject['id'] ?? 0);
    $accessibleProject['repository_count'] = 0;
    if ($accessibleProjectId <= 0) {
        continue;
    }
    $repositoryCountStmt = $conn->prepare(
        'SELECT COUNT(*) AS repository_count FROM project_repositories WHERE project_id = ?'
    );
    $repositoryCountStmt->bind_param('i', $accessibleProjectId);
    $repositoryCountStmt->execute();
    $repositoryCountRow = $repositoryCountStmt->get_result()->fetch_assoc();
    $repositoryCountStmt->close();
    $accessibleProject['repository_count'] = (int) ($repositoryCountRow['repository_count'] ?? 0);
}
unset($accessibleProject);

function vcPageUrl(int $projectId = 0, int $repositoryId = 0, int $commitId = 0, int $branchId = 0): string
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

    return 'repositories.php' . ($params ? '?' . http_build_query($params) : '');
}

$selectedProjectId = (int) ($_GET['project_id'] ?? 0);
$selectedRepositoryId = (int) ($_GET['repository_id'] ?? 0);
$selectedCommitId = (int) ($_GET['commit_id'] ?? 0);
$selectedBranchId = (int) ($_GET['branch_id'] ?? 0);

if ($selectedProjectId <= 0 && $accessibleProjects) {
    $selectedProjectId = (int) $accessibleProjects[0]['id'];
}

if ($selectedProjectId > 0 && !vcCanAccessProject($conn, $user, $selectedProjectId)) {
    $selectedProjectId = $accessibleProjects ? (int) $accessibleProjects[0]['id'] : 0;
}

$createRepositoryPreviewUrl = vcResolveManagedRepositoryCreateUrl('repository');

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
$otherAccessibleRepositories = [];
if ($selectedProjectId > 0) {
    $repoStmt = $conn->prepare(
        "SELECT pr.*, parent_repo.name AS parent_repository_name,
                COALESCE(branch_counts.branch_count, 0) AS branch_count,
                COALESCE(commit_counts.commit_count, 0) AS commit_count
         FROM project_repositories pr
         LEFT JOIN project_repositories parent_repo ON parent_repo.id = pr.parent_repository_id
         LEFT JOIN (
             SELECT repository_id, COUNT(*) AS branch_count
             FROM repository_branches
             WHERE branch_status != 'deleted'
             GROUP BY repository_id
         ) branch_counts ON branch_counts.repository_id = pr.id
         LEFT JOIN (
             SELECT repository_id, COUNT(*) AS commit_count
             FROM repository_commits
             GROUP BY repository_id
         ) commit_counts ON commit_counts.repository_id = pr.id
         WHERE pr.project_id = ?
         ORDER BY COALESCE(pr.last_activity_at, pr.updated_at) DESC, pr.name ASC"
    );
    $repoStmt->bind_param('i', $selectedProjectId);
    $repoStmt->execute();
    $repositories = $repoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $repoStmt->close();

    if (!$repositories) {
        $accessibleProjectIds = array_fill_keys(
            array_map(static fn(array $project): int => (int) ($project['id'] ?? 0), $accessibleProjects),
            true
        );
        $otherRepoResult = $conn->query(
            "SELECT pr.id, pr.project_id, pr.name, pr.slug, pr.repository_status,
                    p.name AS project_name, c.name AS client_name
             FROM project_repositories pr
             INNER JOIN projects p ON p.id = pr.project_id
             INNER JOIN clients c ON c.id = p.client_id
             ORDER BY COALESCE(pr.last_activity_at, pr.updated_at) DESC, pr.id DESC"
        );
        if ($otherRepoResult) {
            while ($otherRepository = $otherRepoResult->fetch_assoc()) {
                $otherProjectId = (int) ($otherRepository['project_id'] ?? 0);
                if ($otherProjectId === $selectedProjectId || !isset($accessibleProjectIds[$otherProjectId])) {
                    continue;
                }
                $otherAccessibleRepositories[] = $otherRepository;
                if (count($otherAccessibleRepositories) >= 6) {
                    break;
                }
            }
        }

        $selectedProjectRecord = null;
        foreach ($accessibleProjects as $accessibleProject) {
            if ((int) ($accessibleProject['id'] ?? 0) === $selectedProjectId) {
                $selectedProjectRecord = $accessibleProject;
                break;
            }
        }

        if ($selectedProjectRecord && $otherAccessibleRepositories) {
            $selectedProjectName = strtolower(trim((string) ($selectedProjectRecord['name'] ?? '')));
            $selectedClientName = strtolower(trim((string) ($selectedProjectRecord['client_name'] ?? '')));
            $matchingRepositories = array_values(array_filter(
                $otherAccessibleRepositories,
                static function (array $repository) use ($selectedProjectName, $selectedClientName): bool {
                    return strtolower(trim((string) ($repository['project_name'] ?? ''))) === $selectedProjectName
                        && strtolower(trim((string) ($repository['client_name'] ?? ''))) === $selectedClientName;
                }
            ));

            $matchingRepository = $matchingRepositories[0] ?? $otherAccessibleRepositories[0] ?? null;
            if ($matchingRepository) {
                header('Location: ' . vcPageUrl(
                    (int) $matchingRepository['project_id'],
                    (int) $matchingRepository['id']
                ));
                exit();
            }
        }
    }
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
$selectedCommit = null;
$selectedCommitFiles = [];
$selectedCommitSummary = [
    'files' => 0,
    'added' => 0,
    'modified' => 0,
    'deleted' => 0,
    'renamed' => 0,
    'lines_added' => 0,
    'lines_removed' => 0,
];
$syncEndpointUrl = '';
$hookConfigUrl = '';
$gitHookScript = '';
$selectedRepositoryHttpCloneUrl = '';
$selectedRepositoryLocalClonePath = '';
$selectedRepositoryFileCloneUrl = '';
$selectedRepositoryWorkspacePath = '';
$selectedRepositoryHasWorkspace = false;
$selectedRepositoryDisplayRemoteUrl = '';
$selectedRepositoryDisplayRemoteKind = 'managed';
$selectedRepositoryExternalRemoteUrl = '';
$selectedBranch = null;

if ($selectedRepository) {
    $branchStmt = $conn->prepare(
        "SELECT rb.*, source_branch.name AS source_branch_name,
                COALESCE(commit_counts.commit_count, 0) AS commit_count
         FROM repository_branches rb
         LEFT JOIN repository_branches source_branch ON source_branch.id = rb.source_branch_id
         LEFT JOIN (
             SELECT branch_id, COUNT(*) AS commit_count
             FROM repository_commits
             GROUP BY branch_id
         ) commit_counts ON commit_counts.branch_id = rb.id
         WHERE rb.repository_id = ?
         ORDER BY rb.is_default DESC, rb.created_at ASC"
    );
    $branchStmt->bind_param('i', $selectedRepositoryId);
    $branchStmt->execute();
    $branches = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $branchStmt->close();

    foreach ($branches as $branch) {
        if ((int) $branch['id'] === $selectedBranchId && (string) $branch['branch_status'] !== 'deleted') {
            $selectedBranch = $branch;
            break;
        }
    }
    if (!$selectedBranch) {
        foreach ($branches as $branch) {
            if ((int) ($branch['is_default'] ?? 0) === 1 && (string) $branch['branch_status'] !== 'deleted') {
                $selectedBranch = $branch;
                $selectedBranchId = (int) $branch['id'];
                break;
            }
        }
    }

    $commitSql =
        "SELECT rc.*, rb.name AS branch_name, u.full_name AS author_name
         FROM repository_commits rc
         INNER JOIN repository_branches rb ON rb.id = rc.branch_id
         INNER JOIN users u ON u.id = rc.authored_by
         WHERE rc.repository_id = ?";
    if ($selectedBranchId > 0) {
        $commitSql .= ' AND rc.branch_id = ?';
    }
    $commitSql .= ' ORDER BY rc.created_at DESC, rc.id DESC LIMIT 15';
    $commitStmt = $conn->prepare($commitSql);
    if ($selectedBranchId > 0) {
        $commitStmt->bind_param('ii', $selectedRepositoryId, $selectedBranchId);
    } else {
        $commitStmt->bind_param('i', $selectedRepositoryId);
    }
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
    $gitHookScript = vcGenerateLocalSyncHookScript($syncEndpointUrl);
    $selectedRepositoryHttpCloneUrl = vcGenerateRepositoryHttpCloneUrl((string) $selectedRepository['slug']);
    $selectedRepositoryLocalClonePath = vcGenerateRepositoryLocalClonePath((string) $selectedRepository['slug']);
    $selectedRepositoryFileCloneUrl = vcGenerateRepositoryFileCloneUrl((string) $selectedRepository['slug']);
    $selectedRepositoryWorkspacePath = vcGetRepositoryWorkspacePath((string) $selectedRepository['slug']);
    $selectedRepositoryHasWorkspace = is_dir($selectedRepositoryWorkspacePath);
    $selectedRepositoryExternalRemoteUrl = (string) (vcNormalizeExternalRemoteUrl((string) ($selectedRepository['external_remote_url'] ?? '')) ?? '');
    $selectedRepositoryDisplayRemoteUrl = vcResolveRepositoryDisplayRemote(
        $selectedRepositoryExternalRemoteUrl,
        (string) $selectedRepository['slug'],
        (string) ($selectedRepository['webhook_token'] ?? '')
    );
    $selectedRepositoryDisplayRemoteKind = vcGetRepositoryDisplayRemoteKind($selectedRepositoryExternalRemoteUrl);

    if ($selectedCommitId > 0) {
        $selectedCommitStmt = $conn->prepare(
            "SELECT rc.*, rb.name AS branch_name, u.full_name AS author_name
             FROM repository_commits rc
             INNER JOIN repository_branches rb ON rb.id = rc.branch_id
             INNER JOIN users u ON u.id = rc.authored_by
             WHERE rc.repository_id = ? AND rc.id = ?
             LIMIT 1"
        );
        $selectedCommitStmt->bind_param('ii', $selectedRepositoryId, $selectedCommitId);
        $selectedCommitStmt->execute();
        $selectedCommit = $selectedCommitStmt->get_result()->fetch_assoc() ?: null;
        $selectedCommitStmt->close();
    }

    if (!$selectedCommit && $commits) {
        $selectedCommit = $commits[0];
        $selectedCommitId = (int) $selectedCommit['id'];
    }

    if ($selectedCommit) {
        $selectedCommitFiles = vcGetCommitFiles($conn, (int) $selectedCommit['id']);
        foreach ($selectedCommitFiles as $fileChange) {
            $selectedCommitSummary['files']++;
            $selectedCommitSummary['lines_added'] += (int) ($fileChange['additions'] ?? 0);
            $selectedCommitSummary['lines_removed'] += (int) ($fileChange['deletions'] ?? 0);
            $changeType = (string) ($fileChange['change_type'] ?? 'modified');
            if (isset($selectedCommitSummary[$changeType])) {
                $selectedCommitSummary[$changeType]++;
            }
        }
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root{--theme-primary:#1f6392;--theme-dark:#184f73;--theme-soft:#eef6fb;--theme-line:rgba(31,99,146,.16);--gray-25:#fcfdff;--gray-50:#f8f9fa;--gray-100:#f1f5f9;--gray-200:#e9ecef;--gray-300:#d9e2ec;--gray-600:#6c757d;--gray-700:#475569;--gray-900:#212529;}
body{background:linear-gradient(180deg,#f5f8fb 0%,#edf3f7 100%);}
.vc-shell{max-width:1440px;}
.vc-header,.vc-card{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:20px;box-shadow:0 10px 26px rgba(15,23,42,.05);}
.vc-header{padding:2rem;margin-bottom:1.5rem;background:linear-gradient(135deg,#19567f 0%,#1f6392 55%,#2f83b9 100%);color:#fff;position:relative;overflow:hidden;}
.vc-header:before,.vc-header:after{content:'';position:absolute;border-radius:999px;background:rgba(255,255,255,.09);}
.vc-header:before{width:220px;height:220px;top:-90px;right:-40px;}
.vc-header:after{width:160px;height:160px;bottom:-90px;right:160px;}
.vc-header-content{position:relative;z-index:1;display:flex;justify-content:space-between;gap:1.5rem;align-items:flex-end;flex-wrap:wrap;}
.vc-header-copy{max-width:760px;}
.vc-header-title{font-size:2rem;font-weight:700;letter-spacing:-.02em;}
.vc-header-subtitle{max-width:620px;color:rgba(255,255,255,.82);}
.vc-header-badge{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem .9rem;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);backdrop-filter:blur(4px);font-size:.85rem;}
.vc-card+.vc-card{margin-top:1.25rem;}
.vc-card .card-head{padding:1.1rem 1.35rem;border-bottom:1px solid var(--gray-200);font-weight:700;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;}
.section-kicker{font-size:.74rem;letter-spacing:.08em;text-transform:uppercase;color:var(--gray-600);}
.project-picker{padding:0 0 1.15rem;border:0;border-bottom:1px solid var(--gray-200);background:transparent;border-radius:0;margin-bottom:1.15rem;}
.project-picker>div{min-width:0;}
.vc-card .project-picker .form-select{width:100%;max-width:100%;min-height:48px;border:1px solid var(--gray-300);border-radius:12px;padding:.7rem 2.75rem .7rem .85rem;background-color:#fff;background-position:right .85rem center;box-shadow:none;}
.vc-card .project-picker .form-select:focus{border-color:var(--theme-primary);box-shadow:0 0 0 .18rem rgba(31,99,146,.1);}
.repo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1rem;}
.repo-tile{display:flex;flex-direction:column;gap:.8rem;text-decoration:none;color:inherit;border:1px solid var(--gray-200);border-radius:14px;padding:1rem 1.05rem;background:#fff;height:100%;transition:box-shadow .18s ease,border-color .18s ease,background .18s ease;}
.repo-tile.active,.repo-tile:hover{border-color:rgba(31,99,146,.38);background:#fbfdff;box-shadow:0 8px 20px rgba(31,99,146,.09);}
.branch-row-selected td{background:#eef7fc!important;}
.branch-row-selected .fw-semibold{color:var(--theme-primary);}
#selectedRepositorySnapshot{scroll-margin-top:7rem;}
.repo-tile-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;}
.repo-title{font-weight:700;font-size:1rem;line-height:1.3;}
.repo-slug{font-size:.8rem;color:var(--gray-600);margin-top:.15rem;word-break:break-word;}
.repo-description{font-size:.84rem;color:var(--gray-700);}
.repo-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem;}
.repo-stat{background:transparent;border:0;border-right:1px solid var(--gray-200);border-radius:0;padding:.25rem .75rem .25rem 0;}
.repo-stat:last-child{border-right:0;}
.repo-stat strong{display:block;font-size:1rem;color:var(--gray-900);line-height:1.1;}
.repo-stat span{display:block;font-size:.72rem;color:var(--gray-600);margin-top:.2rem;}
.repo-footnote{font-size:.78rem;color:var(--gray-600);padding-top:.2rem;border-top:1px dashed var(--gray-200);}
.pill{display:inline-flex;align-items:center;padding:.3rem .7rem;border-radius:999px;font-size:.72rem;font-weight:700;line-height:1;}
.pill.blue{background:#dbeafe;color:#1d4ed8;}.pill.green{background:#d1fae5;color:#047857;}.pill.gray{background:#e5e7eb;color:#4b5563;}
.repo-hero{border:0;border-bottom:1px solid var(--gray-200);border-radius:0;padding:0 0 1.15rem;background:transparent;margin-bottom:1.15rem;}
.repo-hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;}
.repo-hero-title{font-size:1.35rem;font-weight:700;line-height:1.25;}
.repo-meta{font-size:.88rem;color:var(--gray-600);}
.repo-meta code,.hook-box code{font-size:.8rem;}
.repo-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.8rem;margin-top:1rem;}
.summary-tile{padding:.35rem .85rem .35rem 0;border-radius:0;background:transparent;border:0;border-right:1px solid var(--gray-200);}
.summary-tile:last-child{border-right:0;}
.summary-tile strong{display:block;font-size:1rem;color:var(--gray-900);}
.summary-tile span{display:block;font-size:.74rem;color:var(--gray-600);margin-top:.2rem;}
.content-panel{border:0;border-radius:0;padding:0;background:transparent;height:100%;}
.content-panel h6{margin-bottom:.8rem;font-size:.9rem;}
.table-shell{border:1px solid var(--gray-200);border-radius:12px;overflow:auto;background:#fff;max-height:360px;}
.table-shell .table{margin-bottom:0;}
.table-shell thead th{background:#f8fbfd;color:var(--gray-700);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--gray-200);}
.table-shell tbody tr:last-child td{border-bottom:none;}
.timeline-list{display:flex;flex-direction:column;gap:.8rem;}
.vc-scroll-region{max-height:360px;overflow-y:auto;overflow-x:hidden;padding:.2rem .65rem .2rem .65rem;scrollbar-width:thin;scrollbar-color:rgba(31,99,146,.35) transparent;}
.vc-scroll-region::-webkit-scrollbar{width:6px;}
.vc-scroll-region::-webkit-scrollbar-thumb{background:rgba(31,99,146,.32);border-radius:999px;}
.timeline-item{position:relative;min-width:0;padding:0 0 .2rem 1.35rem;border-left:2px solid var(--gray-200);}
.timeline-item:before{content:'';position:absolute;left:-6px;top:4px;width:10px;height:10px;border-radius:50%;background:var(--theme-primary);box-shadow:0 0 0 4px rgba(31,99,146,.10);}
.timeline-item.active{border-left-color:rgba(31,99,146,.4);}
.timeline-item.active:before{background:#0f9d58;box-shadow:0 0 0 4px rgba(15,157,88,.12);}
.timeline-item:last-child{padding-bottom:0;border-left-color:transparent;}
.timeline-title{font-weight:700;color:var(--gray-900);overflow-wrap:anywhere;}
.commit-link{display:block;min-width:0;color:inherit;text-decoration:none;padding-right:.25rem;}
.commit-link .small{overflow-wrap:anywhere;}
.commit-link:hover .timeline-title{color:var(--theme-primary);}
.merge-card{border:0;border-bottom:1px solid var(--gray-200);border-radius:0;padding:.85rem 0;background:transparent;}
.merge-card+.merge-card{margin-top:.9rem;}
.empty-box{border:1px dashed var(--gray-300);border-radius:18px;padding:2rem;text-align:center;color:var(--gray-600);background:linear-gradient(180deg,#fff 0%,#fbfcfe 100%);}
.setup-stack{display:flex;flex-direction:column;gap:.85rem;}
.setup-panel{border:1px solid var(--gray-200);border-radius:14px;padding:1rem;background:#fff;}
.setup-note{font-size:.82rem;color:var(--gray-600);line-height:1.5;}
.setup-chip-row{display:flex;flex-wrap:wrap;gap:.45rem;}
.setup-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.38rem .8rem;border-radius:999px;border:1px solid var(--gray-200);background:#fff;font-size:.76rem;font-weight:600;}
.workspace-tools-card{border-radius:20px;overflow:hidden;}
.workspace-tools-card .card-head{padding:1rem 1.15rem;background:#fff;}
.workspace-tools-card .card-head>div{color:var(--gray-900);font-size:1rem;font-weight:700;}
.workspace-tools-body{padding:0;}
.workspace-tools-body .setup-stack.mb-4{margin-bottom:0!important;gap:0;}
.repository-access-panel{border:0;border-bottom:1px solid var(--gray-200);border-radius:0;padding:1.05rem 1.15rem;background:#fff;isolation:isolate;position:relative;transform:none!important;transform-style:flat!important;}
.repository-access-panel::before,.repository-access-panel::after{pointer-events:none!important;}
.repository-access-panel :is(.repository-branch-picker,.input-group,.clone-action-row,.repository-folder-action){position:relative;z-index:3;transform:none!important;transform-style:flat!important;}
.repository-access-panel :is(a,button,select){position:relative;z-index:4;pointer-events:auto!important;touch-action:manipulation;cursor:pointer;}
.vc-sidebar,.workspace-tools-card,.workspace-tools-body,.setup-stack{transform:none!important;transform-style:flat!important;}
.repository-access-heading{display:flex;align-items:center;gap:.7rem;margin-bottom:1rem;}
.repository-access-heading-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 34px;width:34px;height:34px;border-radius:9px;background:var(--theme-soft);color:var(--theme-primary);}
.repository-access-heading h6{margin:0;color:var(--gray-900);font-size:.92rem;font-weight:700;}
.repository-access-heading p{margin:.15rem 0 0;color:var(--gray-600);font-size:.72rem;}
.clone-action-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:center;gap:.6rem;margin-bottom:1rem;}
.clone-action-row form{margin:0;}
.clone-action-row .btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;width:100%;min-height:42px;border-radius:10px;padding:.58rem .7rem;font-size:.76rem;font-weight:700;line-height:1.2;white-space:nowrap;box-shadow:none;}
.clone-action-row .btn i{margin:0!important;}
.repository-branch-picker{margin-bottom:.75rem;}
.repository-branch-picker .form-label{margin-bottom:.3rem;font-size:.68rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
.repository-branch-picker .input-group{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.55rem;}
.repository-branch-picker .input-group>.form-select{width:100%;border-radius:10px!important;}
.repository-branch-picker .input-group>.btn{border-radius:10px!important;white-space:nowrap;}
.repository-folder-action{margin:0 0 1rem;}
.repository-folder-action .btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;width:100%;min-height:42px;border-radius:10px;font-size:.76rem;font-weight:700;}
.repository-access-fields{display:grid;gap:.75rem;}
.repository-access-field{min-width:0;}
.repository-access-panel .repository-access-field .form-label{margin:0 0 .22rem;color:var(--gray-600);font-size:.64rem;letter-spacing:.055em;}
.repository-access-panel .repository-access-field .form-control[readonly]{min-height:36px;padding:.42rem 0;border-width:0 0 1px;border-style:solid;border-color:var(--gray-300);border-radius:0;background:transparent;color:var(--gray-700);box-shadow:none;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.72rem;text-overflow:ellipsis;}
.repository-access-panel .setup-note{margin-top:1rem;padding:.65rem .75rem;border-left:3px solid var(--theme-primary);border-radius:8px;background:#eef6fb;color:#526678;}
.repository-details-toggle{display:flex;align-items:center;justify-content:space-between;width:100%;margin:.15rem 0 0;padding:.65rem 0;border:0;border-top:1px solid var(--gray-200);background:transparent;color:var(--gray-700);font-size:.75rem;font-weight:700;text-align:left;}
.repository-details-toggle i{transition:transform .18s ease;}
.repository-details-toggle[aria-expanded="true"] i{transform:rotate(180deg);}
.repository-access-details{padding-top:.2rem;}
.clone-feedback{display:none;padding:.5rem .65rem;border-radius:8px;background:#ecfdf5;font-size:.76rem;color:#047857;margin-bottom:.75rem;}
.clone-feedback.show{display:block;}
.hook-box{background:#0d1117;color:#c9d1d9;border-radius:16px;padding:1rem;overflow:auto;font-size:.78rem;line-height:1.45;max-height:320px;}
.vc-sidebar{position:sticky;top:1.25rem;}
.workspace-details{border:0;border-bottom:1px solid var(--gray-200);border-radius:0;background:#fff;overflow:hidden;}
.workspace-details>summary{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.95rem 1.15rem;cursor:pointer;color:var(--gray-900);font-size:.78rem;font-weight:700;list-style:none;}
.workspace-details>summary::-webkit-details-marker{display:none;}
.workspace-details>summary::after{content:'\f078';font-family:'Font Awesome 5 Free';font-weight:900;color:var(--gray-600);font-size:.72rem;transition:transform .18s ease;}
.workspace-details[open]>summary::after{transform:rotate(180deg);}
.workspace-details-body{display:grid;grid-template-columns:minmax(0,1fr);gap:.75rem;min-width:0;max-width:100%;padding:0 1.15rem 1rem;overflow:hidden;}
.workspace-details-body .setup-panel{min-width:0;max-width:100%;border-radius:10px;background:var(--gray-25);overflow:hidden;}
.workspace-details-body .form-control{display:block;width:100%;max-width:100%;min-width:0;text-overflow:ellipsis;}
.workspace-details-body .setup-chip-row{min-width:0;max-width:100%;}
.workspace-details-body .setup-chip{min-width:0;max-width:100%;white-space:normal;}
.workspace-details-body .hook-box{width:100%;max-width:100%;min-width:0;overflow-x:auto;}
.workspace-action-heading{display:flex;align-items:center;gap:.65rem;margin:0;padding:.95rem 1.15rem .7rem;}
.workspace-action-heading-icon{display:none;}
.workspace-action-heading strong{display:block;color:var(--gray-900);font-size:.84rem;}
.workspace-action-heading span{display:block;color:var(--gray-600);font-size:.7rem;margin-top:.05rem;}
.vc-card .workspace-tools-body>.accordion{border:0;border-top:1px solid var(--gray-200);border-radius:0;overflow:hidden;}
.vc-card .accordion-item{border:0;border-bottom:1px solid var(--gray-200);border-radius:0;overflow:hidden;background:#fff;}
.vc-card .accordion-item:last-child{border-bottom:0;}
.vc-card .accordion-item+.accordion-item{margin-top:0;}
.vc-card .accordion-button{padding:.78rem .9rem;font-size:.8rem;font-weight:700;background:#fff;box-shadow:none;}
.vc-card .accordion-button:not(.collapsed){background:var(--theme-soft);color:var(--theme-dark);}
.vc-card .accordion-button:focus{box-shadow:none;border-color:transparent;}
.vc-card .accordion-body{padding:.9rem;background:#fbfdff;}
.vc-card .form-label{font-size:.78rem;font-weight:700;letter-spacing:.02em;color:var(--gray-700);text-transform:uppercase;}
.vc-card .form-control,.vc-card .form-select{border-radius:14px;padding:.7rem .85rem;border-color:var(--gray-300);}
.vc-card .form-control:focus,.vc-card .form-select:focus{border-color:rgba(31,99,146,.4);box-shadow:0 0 0 .2rem rgba(31,99,146,.12);}
.vc-card .btn.btn-primary{border-radius:14px;padding:.72rem .95rem;font-weight:700;background:linear-gradient(135deg,var(--theme-primary),var(--theme-dark));border:none;}
.vc-card .btn.btn-primary:hover{background:linear-gradient(135deg,var(--theme-dark),var(--theme-primary));}
.vc-card .btn.btn-outline-primary{border-radius:14px;padding:.72rem .95rem;font-weight:700;border-color:rgba(31,99,146,.28);color:var(--theme-primary);}
.vc-card .btn.btn-outline-primary:hover{background:var(--theme-primary);border-color:var(--theme-primary);color:#fff;}
#loadRepositoryProject{display:inline-flex;align-items:center;justify-content:center;min-height:48px;color:#fff!important;background:linear-gradient(135deg,var(--theme-primary),var(--theme-dark))!important;border-color:transparent!important;box-shadow:0 5px 12px rgba(31,99,146,.16);cursor:pointer;transition:transform .18s ease,box-shadow .18s ease,background .18s ease;}
#loadRepositoryProject:hover,#loadRepositoryProject:focus-visible{color:#fff!important;background:linear-gradient(135deg,#2f83b9,var(--theme-primary))!important;box-shadow:0 9px 18px rgba(31,99,146,.28);transform:translateY(-2px);}
#loadRepositoryProject:active{box-shadow:0 3px 8px rgba(31,99,146,.2);transform:translateY(0);}
.vc-repository-workspace{margin-top:1.15rem;padding-top:1.15rem;border-top:1px solid var(--gray-200);}
.vc-repository-nav{display:flex;align-items:center;gap:.25rem;margin:0 0 .75rem;padding:.35rem;border:1px solid var(--gray-200);border-radius:12px;background:var(--gray-25);box-shadow:none;overflow-x:auto;}
.vc-repository-nav .nav-link{display:inline-flex;align-items:center;gap:.45rem;min-height:40px;padding:.55rem .85rem;border:0;border-radius:10px;color:var(--gray-600);font-size:.82rem;font-weight:700;white-space:nowrap;}
.vc-repository-nav .nav-link:hover{background:var(--gray-50);color:var(--theme-primary);}
.vc-repository-nav .nav-link.active{background:var(--theme-soft);color:var(--theme-dark);}
.vc-repository-nav .badge{min-width:1.45rem;padding:.28rem .4rem;border-radius:999px;background:#e7edf3;color:var(--gray-700);font-size:.65rem;}
.vc-repository-nav .nav-link.active .badge{background:#fff;color:var(--theme-primary);}
.vc-repository-section{margin:0;background:transparent;}
.vc-repository-section .card-head{padding:.95rem 0;border-bottom:1px solid var(--gray-200);}
.vc-repository-section>.p-4{padding:1.15rem 0 0!important;}
.commit-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:.8rem;margin-top:1rem;}
.file-change-list{display:flex;flex-direction:column;gap:.8rem;}
.file-change-row{border:1px solid var(--gray-200);border-radius:18px;padding:1rem;background:linear-gradient(180deg,#fff 0%,#fbfdff 100%);}
.file-change-header{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;}
.file-change-path{font-weight:700;color:var(--gray-900);word-break:break-word;}
.file-change-old{font-size:.82rem;color:var(--gray-600);margin-top:.18rem;}
.file-change-meta{display:flex;gap:.55rem;align-items:center;flex-wrap:wrap;}
.change-badge{display:inline-flex;align-items:center;padding:.32rem .72rem;border-radius:999px;font-size:.72rem;font-weight:700;line-height:1;}
.change-badge.added{background:#d1fae5;color:#047857;}
.change-badge.modified{background:#dbeafe;color:#1d4ed8;}
.change-badge.deleted{background:#fee2e2;color:#b91c1c;}
.change-badge.renamed{background:#fef3c7;color:#b45309;}
.change-lines{font-size:.8rem;color:var(--gray-600);}
.diff-bar{display:flex;height:8px;border-radius:999px;overflow:hidden;background:#e5e7eb;margin-top:.8rem;}
.diff-bar span{display:block;height:100%;}
.diff-bar .plus{background:#16a34a;}
.diff-bar .minus{background:#dc2626;}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode),
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-nav,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-tile,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-hero,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .content-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-shell,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .merge-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-box,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .setup-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workspace-details,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .accordion-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .file-change-row {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-picker,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-stat,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-tile,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .setup-chip,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .card-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .accordion-button,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .accordion-button:not(.collapsed),
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .accordion-body,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-shell thead th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table tbody tr:hover {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-nav .nav-link {
    color:#9db6cf;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-nav .nav-link:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-nav .nav-link.active {
    background:#0a1b2c;
    color:#8fd3ff;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-nav .badge {
    background:rgba(157,182,207,.14);
    color:#b9ccdc;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-header-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-stat strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-tile strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-hero-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .file-change-path,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table td {
    color: #d7e4f2 !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-kicker,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-slug,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-description,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-stat span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-footnote,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repo-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-tile span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .setup-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .file-change-old,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .change-lines,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table th {
    color: #9db6cf !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) textarea {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .form-control:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .form-select:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) textarea:focus {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.45) !important;
    color: #d7e4f2 !important;
    box-shadow: 0 0 0 .2rem rgba(31, 99, 146, 0.12) !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-item {
    border-left-color: rgba(31, 99, 146, 0.24);
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .diff-bar {
    background: rgba(157, 182, 207, 0.18);
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workspace-tools-card .card-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repository-access-panel {
    background:#071321 !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workspace-tools-card .repository-access-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workspace-tools-card .workspace-details {
    border-width:0 0 1px !important;
    border-color:rgba(31,99,146,.24) !important;
    border-radius:0 !important;
    box-shadow:none !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repository-details-toggle,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workspace-details>summary {
    border-color:rgba(31,99,146,.24);
    color:#d7e4f2;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workspace-details-body .setup-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-card .accordion-body {
    background:#081827 !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workspace-action-heading strong {
    color:#d7e4f2;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-view .repo-hero,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-view .content-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-view .summary-tile {
    background:transparent !important;
    box-shadow:none !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .vc-repository-section .card-head {
    background:transparent !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repository-access-heading-icon {
    background:rgba(31,99,146,.2);
    color:#8fd3ff;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repository-access-heading h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workspace-tools-card .card-head>div {
    color:#d7e4f2;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repository-access-panel .repository-access-field .form-control[readonly] {
    background:transparent !important;
    border-color:rgba(138,210,255,.18) !important;
    color:#b9ccdc !important;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .repository-access-panel .setup-note {
    background:rgba(31,99,146,.14);
    color:#9db6cf;
}
@media (max-width: 1199.98px){.vc-sidebar{position:static;}.vc-repository-workspace{margin-top:1rem;}}
@media (max-width: 575.98px){.clone-action-row{grid-template-columns:1fr;}}
@media (max-width: 767.98px){.vc-header{padding:1.5rem;}.vc-header-title{font-size:1.6rem;}.repo-stats,.repo-summary-grid{grid-template-columns:1fr;}.repo-stat,.summary-tile{border-right:0;border-bottom:1px solid var(--gray-200);padding:.45rem 0;}.repo-stat:last-child,.summary-tile:last-child{border-bottom:0;}.vc-repository-section>.p-4{padding-top:1rem!important;}}
</style>

<div class="container-fluid px-4 py-4 vc-shell">
    <div class="vc-header">
        <div class="vc-header-content">
            <div class="vc-header-copy">
                <div class="vc-header-title mb-2"><i class="fas fa-code-branch me-2"></i>Project Version Control</div>
                <p class="vc-header-subtitle mb-0">Manage repositories, branches, commits, pushes, pulls, merges, and forks for each project in a cleaner workspace.</p>
            </div>
            <div class="vc-header-badge"><i class="fas fa-diagram-project"></i> Repository workspace</div>
        </div>
    </div>

    <?php if ($flashSuccess): ?><div class="alert alert-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger border-0"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>
    
    <?php if (!$execAvailable): ?>
    <div class="alert alert-warning border-0">
        <strong>⚠️ Git Operations Not Available</strong><br>
        Your hosting provider has disabled the exec() function required for git operations. 
        Repository creation, cloning, and management features are not available. 
        To use git features, contact your hosting provider to enable exec() or upgrade to a VPS/dedicated server.
    </div>
    <?php endif; ?>

    <?php if (!$accessibleProjects): ?>
    <div class="vc-card p-4"><div class="empty-box"><i class="fas fa-folder-open d-block mb-2"></i>No accessible projects yet.</div></div>
    <?php else: ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="vc-card">
                <div class="card-head">
                    <div>
                        <div class="section-kicker">Overview</div>
                        <div>Repositories</div>
                    </div>
                </div>
                <div class="p-4">
                    <form method="GET" action="<?php echo htmlspecialchars(BASE_URL . 'modules/version-control/repositories.php'); ?>" class="row g-3 align-items-end project-picker" id="repositoryProjectPicker" data-generic-draft-disabled="1">
                        <div class="col-md-8">
                            <label class="form-label" for="repositoryProjectId">Project</label>
                            <select name="project_id" class="form-select" id="repositoryProjectId">
                                <?php foreach ($accessibleProjects as $project): ?>
                                <option value="<?php echo (int) $project['id']; ?>" <?php echo (int) $project['id'] === $selectedProjectId ? 'selected' : ''; ?>>
                                    <?php
                                    $repositoryCount = (int) ($project['repository_count'] ?? 0);
                                    echo htmlspecialchars(
                                        $project['name'] . ' - ' . $project['client_name']
                                        . ' (' . $repositoryCount . ' '
                                        . ($repositoryCount === 1 ? 'repository' : 'repositories') . ')'
                                        . ' [Project #' . (int) $project['id'] . ']'
                                    );
                                    ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                                id="loadRepositoryProject"
                            >Load Project</button>
                        </div>
                    </form>

                    <?php if (!$repositories): ?>
                    <div class="empty-box">
                        <i class="fas fa-code-branch d-block mb-2" aria-hidden="true"></i>
                        <strong>No repositories yet for this project.</strong>
                        <div class="mt-1">
                            The project loaded successfully, but no repository record is registered to this project.
                            Repositories created for another project stay with that project.
                        </div>
                        <?php if ($otherAccessibleRepositories): ?>
                        <div class="mt-4 text-start">
                            <div class="section-kicker mb-2">Repositories in your other accessible projects</div>
                            <div class="d-grid gap-2">
                                <?php foreach ($otherAccessibleRepositories as $otherRepository): ?>
                                <a
                                    class="btn btn-outline-primary d-flex align-items-center justify-content-between gap-3 text-start vc-navigation-link"
                                    href="<?php echo htmlspecialchars(vcPageUrl((int) $otherRepository['project_id'], (int) $otherRepository['id'])); ?>"
                                >
                                    <span>
                                        <strong class="d-block"><?php echo htmlspecialchars((string) $otherRepository['name']); ?></strong>
                                        <small><?php echo htmlspecialchars((string) $otherRepository['project_name'] . ' - ' . (string) $otherRepository['client_name']); ?></small>
                                    </span>
                                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <a
                            class="btn btn-outline-primary mt-3"
                            href="<?php echo htmlspecialchars(BASE_URL . 'api/version-control/download-repository.php?project_id=' . $selectedProjectId); ?>"
                            download
                        >
                            <i class="fas fa-download me-1"></i>Download Stored Git History
                        </a>
                        <?php if (in_array((string) ($user['role'] ?? ''), ['admin', 'architect'], true) && $execAvailable): ?>
                        <button class="btn btn-outline-primary mt-3" type="button" data-bs-toggle="collapse" data-bs-target="#repoAction" aria-controls="repoAction">
                            <i class="fas fa-plus me-1"></i>Create Repository
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="repo-grid">
                        <?php foreach ($repositories as $repo): ?>
                        <a href="<?php echo htmlspecialchars(vcPageUrl($selectedProjectId, (int) $repo['id'])); ?>" class="repo-tile vc-navigation-link <?php echo (int) $repo['id'] === $selectedRepositoryId ? 'active' : ''; ?>">
                            <div class="repo-tile-top">
                                <div>
                                    <div class="repo-title"><?php echo htmlspecialchars($repo['name']); ?></div>
                                    <div class="repo-slug"><?php echo htmlspecialchars($repo['slug']); ?></div>
                                </div>
                                <span class="pill <?php echo $repo['repository_status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($repo['repository_status']); ?></span>
                            </div>
                            <div class="repo-description"><?php echo htmlspecialchars($repo['description'] ?: 'No description provided for this repository yet.'); ?></div>
                            <div class="repo-stats">
                                <div class="repo-stat">
                                    <strong><?php echo (int) $repo['branch_count']; ?></strong>
                                    <span>Branches</span>
                                </div>
                                <div class="repo-stat">
                                    <strong><?php echo (int) $repo['commit_count']; ?></strong>
                                    <span>Commits</span>
                                </div>
                                <div class="repo-stat">
                                    <strong><?php echo htmlspecialchars($repo['default_branch_name']); ?></strong>
                                    <span>Default</span>
                                </div>
                            </div>
                            <?php if (!empty($repo['parent_repository_name'])): ?>
                            <div class="repo-footnote">Forked from <?php echo htmlspecialchars($repo['parent_repository_name']); ?></div>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

            <?php if ($selectedRepository): ?>
            <div class="vc-repository-workspace">
                <nav class="nav vc-repository-nav" role="tablist" aria-label="Repository sections">
                    <button class="nav-link active" id="repositoryOverviewTab" data-bs-toggle="tab" data-bs-target="#repositoryOverview" type="button" role="tab" aria-controls="repositoryOverview" aria-selected="true">
                        <i class="fas fa-code-branch" aria-hidden="true"></i>
                        Overview
                        <span class="badge"><?php echo count($branches) + count($commits); ?></span>
                    </button>
                    <button class="nav-link" id="repositoryActivityTab" data-bs-toggle="tab" data-bs-target="#repositoryActivity" type="button" role="tab" aria-controls="repositoryActivity" aria-selected="false">
                        <i class="fas fa-wave-square" aria-hidden="true"></i>
                        Activity
                        <span class="badge"><?php echo count($operations) + count($merges); ?></span>
                    </button>
                    <button class="nav-link" id="repositoryChangesTab" data-bs-toggle="tab" data-bs-target="#repositoryChanges" type="button" role="tab" aria-controls="repositoryChanges" aria-selected="false">
                        <i class="fas fa-file-code" aria-hidden="true"></i>
                        Changes
                        <span class="badge"><?php echo count($selectedCommitFiles); ?></span>
                    </button>
                </nav>
                <div class="tab-content vc-repository-view">
                    <div class="tab-pane fade show active" id="repositoryOverview" role="tabpanel" aria-labelledby="repositoryOverviewTab" tabindex="0">
            <div class="vc-repository-section" id="selectedRepositorySnapshot">
                <div class="card-head">
                    <div>
                        <div class="section-kicker">Selected Repository</div>
                        <div>
                            <?php echo htmlspecialchars($selectedRepository['name']); ?> Snapshot
                            <?php if ($selectedBranch): ?>
                            <span class="pill blue ms-2"><?php echo htmlspecialchars((string) $selectedBranch['name']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="p-4">
                    <div class="repo-hero">
                        <div class="repo-hero-top">
                            <div>
                                <div class="repo-hero-title mb-1"><?php echo htmlspecialchars($selectedRepository['project_name']); ?> / <span class="text-primary"><?php echo htmlspecialchars($selectedRepository['name']); ?></span></div>
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
                            | <?php echo $selectedRepositoryDisplayRemoteKind === 'external' ? 'External remote' : 'Managed remote'; ?>:
                            <code><?php echo htmlspecialchars($selectedRepositoryDisplayRemoteUrl); ?></code>
                        </div>
                        <div class="repo-summary-grid">
                            <div class="summary-tile">
                                <strong><?php echo count($branches); ?></strong>
                                <span>Tracked branches</span>
                            </div>
                            <div class="summary-tile">
                                <strong><?php echo count($commits); ?></strong>
                                <span>Recent commits shown</span>
                            </div>
                            <div class="summary-tile">
                                <strong><?php echo count($operations); ?></strong>
                                <span>Recent operations shown</span>
                            </div>
                            <div class="summary-tile">
                                <strong><?php echo count($merges); ?></strong>
                                <span>Recent merges shown</span>
                            </div>
                        </div>
                    </div>
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="content-panel">
                                <h6 class="fw-semibold">Branches</h6>
                                <?php if (!$branches): ?><div class="empty-box">No branches yet.</div><?php else: ?>
                                <div class="table-shell table-responsive">
                                    <table class="table align-middle">
                                        <thead><tr><th>Branch</th><th>Commits</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($branches as $branch): ?>
                                            <tr class="<?php echo (int) $branch['id'] === $selectedBranchId ? 'branch-row-selected' : ''; ?>">
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($branch['name']); ?></div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($branch['source_branch_name'] ?: 'Root branch'); ?></div>
                                                    <div class="mt-2 d-flex flex-wrap gap-2">
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
                        </div>
                        <div class="col-lg-6">
                            <div class="content-panel">
                                <h6 class="fw-semibold">
                                    Recent Commits
                                    <?php if ($selectedBranch): ?>
                                    <span class="text-muted fw-normal">— <?php echo htmlspecialchars((string) $selectedBranch['name']); ?></span>
                                    <?php endif; ?>
                                </h6>
                                <?php if (!$commits): ?><div class="empty-box">No commits yet.</div><?php else: ?>
                                <div class="timeline-list vc-scroll-region">
                                    <?php foreach ($commits as $commit): ?>
                                    <div class="timeline-item <?php echo (int) $commit['id'] === $selectedCommitId ? 'active' : ''; ?>">
                                        <a class="commit-link" href="<?php echo htmlspecialchars(vcPageUrl($selectedProjectId, $selectedRepositoryId, (int) $commit['id'], $selectedBranchId) . '#repositoryChanges'); ?>">
                                            <div class="timeline-title"><?php echo htmlspecialchars($commit['commit_title']); ?></div>
                                            <div class="small text-muted"><code><?php echo htmlspecialchars(substr($commit['commit_hash'], 0, 7)); ?></code> on <?php echo htmlspecialchars($commit['branch_name']); ?></div>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars($commit['author_name']); ?> | <?php echo date('M d, Y h:i A', strtotime($commit['created_at'])); ?></div>
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                    </div>

                    <div class="tab-pane fade" id="repositoryActivity" role="tabpanel" aria-labelledby="repositoryActivityTab" tabindex="0">
            <div class="vc-repository-section">
                <div class="card-head">
                    <div>
                        <div class="section-kicker">Activity</div>
                        <div>Operations and Merges</div>
                    </div>
                </div>
                <div class="p-4">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="content-panel">
                                <h6 class="fw-semibold">Recent Operations</h6>
                                <?php if (!$operations): ?><div class="empty-box">No operations recorded yet.</div><?php else: ?>
                                <div class="timeline-list vc-scroll-region">
                                    <?php foreach ($operations as $operation): $metadata = $operation['metadata'] ? json_decode($operation['metadata'], true) : []; ?>
                                    <div class="timeline-item">
                                        <div class="timeline-title"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $operation['operation_type']))); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($operation['user_name']); ?><?php if ($operation['branch_name']): ?> | <?php echo htmlspecialchars($operation['branch_name']); ?><?php endif; ?></div>
                                        <?php if (!empty($metadata['notes'])): ?><div class="small text-muted mt-1"><?php echo htmlspecialchars($metadata['notes']); ?></div><?php endif; ?>
                                        <div class="small text-muted mt-1"><?php echo date('M d, Y h:i A', strtotime($operation['created_at'])); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="content-panel">
                                <h6 class="fw-semibold">Merge History</h6>
                                <?php if (!$merges): ?><div class="empty-box">No merge history yet.</div><?php else: ?>
                                <div class="vc-scroll-region">
                                <?php foreach ($merges as $merge): ?>
                                <div class="merge-card">
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($merge['summary'] ?: ($merge['source_branch_name'] . ' -> ' . $merge['target_branch_name'])); ?></div>
                                        <span class="badge <?php echo $merge['merge_status'] === 'merged' ? 'text-bg-success' : ($merge['merge_status'] === 'pending' ? 'text-bg-warning' : 'text-bg-secondary'); ?>">
                                            <?php echo $merge['merge_status'] === 'pending' ? 'Conflict' : htmlspecialchars(ucfirst((string) $merge['merge_status'])); ?>
                                        </span>
                                    </div>
                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($merge['source_branch_name']); ?> -> <?php echo htmlspecialchars($merge['target_branch_name']); ?></div>
                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($merge['merged_by_name']); ?> | <?php echo date('M d, Y h:i A', strtotime($merge['created_at'])); ?></div>
                                    <?php if ($merge['merge_status'] === 'pending' && trim((string) $merge['notes']) !== ''): ?>
                                        <div class="alert alert-warning py-2 px-3 mt-2 mb-0 small">
                                            <strong>Resolve on the source branch, push the fix, then retry.</strong><br>
                                            <?php echo nl2br(htmlspecialchars((string) $merge['notes'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                    </div>

                    <div class="tab-pane fade" id="repositoryChanges" role="tabpanel" aria-labelledby="repositoryChangesTab" tabindex="0">
            <div class="vc-repository-section">
                <div class="card-head">
                    <div>
                        <div class="section-kicker">Commit Explorer</div>
                        <div>Files Changed</div>
                    </div>
                </div>
                <div class="p-4">
                    <?php if (!$selectedCommit): ?>
                    <div class="empty-box">Select a repository commit to inspect changed project files.</div>
                    <?php else: ?>
                    <div class="repo-hero">
                        <div class="repo-hero-top">
                            <div>
                                <div class="repo-hero-title mb-1"><?php echo htmlspecialchars($selectedCommit['commit_title']); ?></div>
                                <div class="repo-meta">
                                    <code><?php echo htmlspecialchars($selectedCommit['commit_hash']); ?></code>
                                    | <?php echo htmlspecialchars($selectedCommit['branch_name']); ?>
                                    | <?php echo htmlspecialchars($selectedCommit['author_name']); ?>
                                    | <?php echo date('M d, Y h:i A', strtotime($selectedCommit['created_at'])); ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <div><span class="pill blue"><?php echo ucfirst(htmlspecialchars((string) $selectedCommit['commit_kind'])); ?></span></div>
                                <div class="repo-meta mt-2"><?php echo (int) $selectedCommit['files_changed']; ?> file(s) | +<?php echo (int) $selectedCommit['lines_added']; ?> / -<?php echo (int) $selectedCommit['lines_removed']; ?></div>
                            </div>
                        </div>
                        <div class="repo-meta mt-3">
                            <?php echo htmlspecialchars($selectedCommit['commit_message'] ?: 'No detailed commit message recorded for this commit.'); ?>
                        </div>
                        <div class="commit-detail-grid">
                            <div class="summary-tile">
                                <strong><?php echo (int) $selectedCommitSummary['files']; ?></strong>
                                <span>Tracked files</span>
                            </div>
                            <div class="summary-tile">
                                <strong><?php echo (int) $selectedCommitSummary['added']; ?></strong>
                                <span>Added</span>
                            </div>
                            <div class="summary-tile">
                                <strong><?php echo (int) $selectedCommitSummary['modified']; ?></strong>
                                <span>Modified</span>
                            </div>
                            <div class="summary-tile">
                                <strong><?php echo (int) $selectedCommitSummary['deleted']; ?></strong>
                                <span>Deleted</span>
                            </div>
                            <div class="summary-tile">
                                <strong><?php echo (int) $selectedCommitSummary['renamed']; ?></strong>
                                <span>Renamed</span>
                            </div>
                            <div class="summary-tile">
                                <strong>+<?php echo (int) $selectedCommitSummary['lines_added']; ?> / -<?php echo (int) $selectedCommitSummary['lines_removed']; ?></strong>
                                <span>Line summary</span>
                            </div>
                        </div>
                    </div>

                    <?php if (!$selectedCommitFiles): ?>
                    <div class="empty-box">No file-level details were stored for this commit yet. New manual commits, git hooks, and supported webhooks will populate this view automatically.</div>
                    <?php else: ?>
                    <div class="file-change-list">
                        <?php foreach ($selectedCommitFiles as $fileChange): ?>
                        <?php
                            $totalLines = max(1, (int) $fileChange['additions'] + (int) $fileChange['deletions']);
                            $plusWidth = ((int) $fileChange['additions'] / $totalLines) * 100;
                            $minusWidth = ((int) $fileChange['deletions'] / $totalLines) * 100;
                        ?>
                        <div class="file-change-row">
                            <div class="file-change-header">
                                <div>
                                    <div class="file-change-path"><?php echo htmlspecialchars((string) $fileChange['file_path']); ?></div>
                                    <?php if (!empty($fileChange['previous_file_path'])): ?>
                                    <div class="file-change-old">Previously: <?php echo htmlspecialchars((string) $fileChange['previous_file_path']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="file-change-meta">
                                    <span class="change-badge <?php echo htmlspecialchars((string) $fileChange['change_type']); ?>"><?php echo ucfirst(htmlspecialchars((string) $fileChange['change_type'])); ?></span>
                                    <span class="change-lines">+<?php echo (int) $fileChange['additions']; ?> / -<?php echo (int) $fileChange['deletions']; ?></span>
                                </div>
                            </div>
                            <div class="diff-bar">
                                <span class="plus" style="width: <?php echo $plusWidth; ?>%"></span>
                                <span class="minus" style="width: <?php echo $minusWidth; ?>%"></span>
                            </div>
                            <?php if (!empty($fileChange['diff_excerpt'])): ?>
                            <pre class="hook-box mt-3 mb-0"><code><?php echo htmlspecialchars((string) $fileChange['diff_excerpt']); ?></code></pre>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="vc-sidebar">
            <div class="vc-card workspace-tools-card">
                <div class="card-head">
                    <div>Repository Tools</div>
                </div>
                <div class="workspace-tools-body">
                    <?php if ($selectedRepository): ?>
                    <div class="setup-stack mb-4">
                        <div class="setup-panel repository-access-panel">
                            <div class="repository-access-heading">
                                <span class="repository-access-heading-icon" aria-hidden="true"><i class="fas fa-code-branch"></i></span>
                                <div>
                                    <h6>Repository Access</h6>
                                    <p>Clone and workspace details</p>
                                </div>
                            </div>
                            <div id="cloneFeedback" class="clone-feedback">Clone URL copied.</div>
                            <div class="repository-branch-picker" id="repositoryBranchPicker">
                                <label class="form-label" for="repositoryAccessBranch">Branch</label>
                                <div class="input-group">
                                <select class="form-select" id="repositoryAccessBranch" name="branch_id">
                                    <?php foreach ($branches as $branch): ?>
                                    <?php if ((string) ($branch['branch_status'] ?? '') !== 'deleted'): ?>
                                    <option
                                        value="<?php echo (int) $branch['id']; ?>"
                                        data-branch-name="<?php echo htmlspecialchars((string) $branch['name']); ?>"
                                        <?php echo (int) $branch['id'] === $selectedBranchId ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars((string) $branch['name']); ?>
                                        <?php echo (int) ($branch['is_default'] ?? 0) === 1 ? ' (default)' : ''; ?>
                                    </option>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <a
                                    class="btn btn-outline-primary"
                                    id="openRepositoryBranch"
                                    data-open-base="<?php echo htmlspecialchars(BASE_URL . 'modules/version-control/repositories.php?project_id=' . $selectedProjectId . '&repository_id=' . $selectedRepositoryId); ?>"
                                    href="<?php echo htmlspecialchars(BASE_URL . 'modules/version-control/repositories.php?project_id=' . $selectedProjectId . '&repository_id=' . $selectedRepositoryId . '&branch_id=' . $selectedBranchId . '#selectedRepositorySnapshot'); ?>"
                                >
                                    <i class="fas fa-arrow-right me-1"></i>Open Branch
                                </a>
                                </div>
                            </div>
                            <div class="clone-action-row">
                                <button type="button" class="btn btn-primary" id="copyRepositoryCloneUrl" data-clone-url="<?php echo htmlspecialchars($selectedRepositoryDisplayRemoteUrl, ENT_QUOTES); ?>">
                                    <i class="fas fa-code-branch me-2"></i>Copy Clone URL
                                </button>
                                <a
                                    class="btn btn-outline-primary"
                                    id="downloadRepositoryBranch"
                                    data-download-base="<?php echo htmlspecialchars(BASE_URL . 'api/version-control/download-repository.php?repository_id=' . $selectedRepositoryId); ?>"
                                    href="<?php echo htmlspecialchars(BASE_URL . 'api/version-control/download-repository.php?repository_id=' . $selectedRepositoryId . '&ref=' . rawurlencode((string) ($selectedRepository['default_branch_name'] ?: 'main'))); ?>"
                                >
                                    <i class="fas fa-download me-2"></i>Download Branch
                                </a>
                            </div>
                                <?php if (!$selectedRepositoryHasWorkspace && !in_array($user['role'], ['sul', 'qa'], true)): ?>
                                <?php if ($execAvailable): ?>
                                <form method="POST" class="repository-folder-action" data-generic-draft-disabled="1" data-confirm-skip="1">
                                    <input type="hidden" name="action" value="create_working_folder">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-folder-plus me-2"></i>Create Folder
                                    </button>
                                </form>
                                <?php else: ?>
                                <button type="button" class="btn btn-outline-primary w-100" disabled title="Git operations not available on this server">
                                    <i class="fas fa-folder-plus me-2"></i>Folder Unavailable
                                </button>
                                <?php endif; ?>
                                <?php endif; ?>
                            <button
                                class="repository-details-toggle collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#repositoryAccessDetails"
                                aria-expanded="false"
                                aria-controls="repositoryAccessDetails"
                            >
                                Connection details
                                <i class="fas fa-chevron-down" aria-hidden="true"></i>
                            </button>
                            <div class="collapse repository-access-details" id="repositoryAccessDetails">
                            <div class="repository-access-fields">
                            <div class="repository-access-field">
                                <label class="form-label">Working Folder</label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($selectedRepositoryWorkspacePath); ?>">
                            </div>
                            <div class="repository-access-field">
                                <label class="form-label"><?php echo htmlspecialchars(vcGetRepositoryManagedCloneLabel()); ?></label>
                                <input class="form-control" id="httpCloneUrlField" readonly value="<?php echo htmlspecialchars(vcResolveManagedRepositoryDisplayUrl((string) $selectedRepository['slug'])); ?>">
                            </div>
                            <div class="repository-access-field">
                                <label class="form-label">Server Repository Path</label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($selectedRepositoryLocalClonePath); ?>">
                            </div>
                            <div class="repository-access-field">
                                <label class="form-label"><?php echo htmlspecialchars(vcGetRepositoryExternalRemoteLabel()); ?></label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($selectedRepositoryExternalRemoteUrl); ?>" placeholder="<?php echo htmlspecialchars(vcGetRepositoryExternalRemotePlaceholder()); ?>">
                            </div>
                            <div class="repository-access-field">
                                <label class="form-label">File Clone URL</label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($selectedRepositoryFileCloneUrl); ?>">
                            </div>
                            </div>
                            <div class="setup-note">
                                <?php if ($selectedRepositoryHasWorkspace): ?>
                                The working folder is already ready. Open that folder and you can commit, push, and merge right away.
                                <?php else: ?>
                                The working folder has not been created yet for this repository.
                                <?php endif; ?>
                                <br><?php echo htmlspecialchars(vcGetManagedRepositoryPortablePushHint()); ?>
                            </div>
                            </div>
                        </div>
                        <details class="workspace-details">
                            <summary>Integration and hook setup</summary>
                            <div class="workspace-details-body">
                        <div class="setup-panel">
                            <h6 class="fw-semibold mb-3">Sync Setup</h6>
                            <div class="setup-chip-row">
                                <div class="setup-chip"><i class="fab fa-github"></i> GitHub webhook</div>
                                <div class="setup-chip"><i class="fas fa-code"></i> VS Code Git</div>
                                <div class="setup-chip"><i class="fab fa-android"></i> Android / Termux</div>
                                <div class="setup-chip"><i class="fas fa-terminal"></i> Git Bash / CLI</div>
                            </div>
                            <div class="mt-3 mb-2">
                                <label class="form-label">Webhook / Hook Sync URL</label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($syncEndpointUrl); ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Repository Token</label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars((string) $selectedRepository['webhook_token']); ?>">
                            </div>
                            <div class="setup-note">
                                On Render, the copy button uses the managed Git username and this repository token so `git push` can authenticate over HTTPS.
                                <br>
                                The URLs above are generated from the current host, so they keep working after deployment as long as your web server is reachable from the client machine.
                            </div>
                        </div>
                            <div class="setup-panel">
                            <label class="form-label">Universal Git Hook Script</label>
                            <div class="hook-box"><code><?php echo htmlspecialchars($gitHookScript); ?></code></div>
                            <div class="setup-note mt-2">
                                Save this as `.git/hooks/post-commit`. It now sends the changed, added, renamed, and deleted files for the latest commit. Duplicate it for `pre-push` and change `event_type` to `push`. For pull sync, use `post-merge` and change `event_type` to `pull`.
                            </div>
                        </div>
                        <div class="setup-panel">
                            <label class="form-label">Provider Webhook Notes</label>
                            <div class="setup-note">
                                GitHub, GitLab, and Bitbucket push webhooks are supported. Point the webhook to the sync URL above.
                            </div>
                        </div>
                            </div>
                        </details>
                    </div>
                    <?php endif; ?>
                    <div class="workspace-action-heading <?php echo $selectedRepository && in_array($user['role'], ['sul', 'qa'], true) ? 'd-none' : ''; ?>">
                        <span class="workspace-action-heading-icon" aria-hidden="true"><i class="fas fa-bolt"></i></span>
                        <div>
                            <strong>Repository actions</strong>
                            <span>Open one tool at a time</span>
                        </div>
                    </div>
                    <div class="accordion <?php echo $selectedRepository && in_array($user['role'], ['sul', 'qa'], true) ? 'd-none' : ''; ?>" id="vcAccordion">
                    <?php if (in_array($user['role'], ['admin', 'architect'], true)): ?>
                        <?php if (!$execAvailable): ?>
                        <div class="accordion-item" style="opacity: 0.5;">
                            <h2 class="accordion-header"><button class="accordion-button disabled" type="button" disabled>Create Repository (Unavailable)</button></h2>
                            <div class="accordion-collapse collapse" data-bs-parent="#vcAccordion">
                                <div class="accordion-body text-muted">
                                    <p>Git operations are not available on this server. The exec() function has been disabled by your hosting provider.</p>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#repoAction">Create Repository</button></h2>
                            <div id="repoAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion">
                                <div class="accordion-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="create_repository">
                                        <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                        <div class="mb-2"><input class="form-control" name="repository_name" placeholder="Repository name" required></div>
                                        <div class="mb-2"><input class="form-control" name="repository_slug" placeholder="Slug"></div>
                                        <div class="mb-2">
                                            <label class="form-label"><?php echo htmlspecialchars(vcGetRepositoryManagedCloneLabel()); ?></label>
                                            <input class="form-control" id="createRepositoryManagedUrl" data-template="<?php echo htmlspecialchars(vcResolveManagedRepositoryCreateUrl('__slug__')); ?>" value="<?php echo htmlspecialchars($createRepositoryPreviewUrl); ?>" readonly>
                                        </div>
                                        <div class="setup-note mb-2">
                                            <?php echo htmlspecialchars(vcGetManagedRepositoryCreateHint()); ?>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label"><?php echo htmlspecialchars(vcGetRepositoryExternalRemoteLabel()); ?></label>
                                            <input class="form-control" name="external_remote_url" placeholder="<?php echo htmlspecialchars(vcGetRepositoryExternalRemotePlaceholder()); ?>">
                                        </div>
                                        <div class="setup-note mb-2">
                                            <?php echo htmlspecialchars(vcGetRepositoryExternalRemoteHelp()); ?>
                                        </div>
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
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($selectedRepository): ?>
                        <?php if (!in_array($user['role'], ['sul', 'qa'], true)): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#settingsAction">Sync Settings</button></h2>
                            <div id="settingsAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion"><div class="accordion-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="save_sync_settings">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <div class="mb-2"><input class="form-control" name="external_remote_url" value="<?php echo htmlspecialchars($selectedRepositoryExternalRemoteUrl); ?>" placeholder="<?php echo htmlspecialchars(vcGetRepositoryExternalRemotePlaceholder()); ?>"></div>
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

                        <?php endif; ?>
                        <?php if (!in_array($user['role'], ['sul', 'qa'], true)): ?>
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
                        <?php endif; ?>

                        <?php if (!in_array($user['role'], ['sul', 'qa'], true)): ?>
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
                                    <div class="mb-2">
                                        <textarea class="form-control" name="commit_files" rows="4" placeholder="M modules/version-control/repositories.php | +24 -3&#10;D legacy/old-file.php | +0 -18&#10;A assets/js/repo-viewer.js | +66 -0"></textarea>
                                    </div>
                                    <div class="setup-note mb-2">
                                        Optional file list format: `M path/to/file.php | +12 -4`, `D path/to/file.php | +0 -9`, `A path/to/file.php | +40 -0`, or `R old.php -> new.php | +3 -1`.
                                    </div>
                                    <button class="btn btn-primary w-100">Record Commit</button>
                                </form>
                            </div></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!in_array($user['role'], ['sul', 'qa'], true)): ?>
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
                        <?php endif; ?>

                        <?php if (in_array($user['role'], ['admin', 'architect'], true)): ?>
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
                        <?php endif; ?>

                        <?php if (!in_array($user['role'], ['sul', 'qa'], true)): ?>
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

                        <?php if (vcCanDeleteRepository($user) && $selectedRepository): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#deleteRepositoryAction">Delete Repository</button></h2>
                            <div id="deleteRepositoryAction" class="accordion-collapse collapse" data-bs-parent="#vcAccordion"><div class="accordion-body">
                                <div class="setup-note mb-3">
                                    This removes the repository from ERNI PMS and deletes its managed storage and working folder. Existing downloaded copies on your machine are not deleted.
                                </div>
                                <form method="POST" class="js-delete-repository-form" data-confirm-skip="1" data-repository-name="<?php echo htmlspecialchars((string) $selectedRepository['name']); ?>">
                                    <input type="hidden" name="action" value="delete_repository">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <button class="btn btn-danger w-100">Delete Repository</button>
                                </form>
                            </div></div>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                    <div class="setup-panel repository-access-panel">
                        <div class="repository-access-heading">
                            <span class="repository-access-heading-icon" aria-hidden="true"><i class="fas fa-code-branch"></i></span>
                            <div>
                                <h6>No registered repository</h6>
                                <p>This project has no repository record to clone or archive.</p>
                            </div>
                        </div>
                        <a
                            class="btn btn-outline-primary w-100"
                            href="<?php echo htmlspecialchars(BASE_URL . 'api/version-control/download-repository.php?project_id=' . $selectedProjectId); ?>"
                            download
                        >
                            <i class="fas fa-download me-2"></i>Download Stored Git History
                        </a>
                        <div class="setup-note mt-3">
                            This downloads the branch, commit, pull-request, and merge records retained by ERNI PMS.
                            A source-code ZIP becomes available after an Architect or Admin registers the managed repository.
                        </div>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            </div>
                            </div>
                        </div>
                </div>
    <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const nameInput = document.querySelector('input[name="repository_name"]');
    const slugInput = document.querySelector('input[name="repository_slug"]');
    const remoteUrlInput = document.getElementById('createRepositoryManagedUrl');

    if (!nameInput || !slugInput || !remoteUrlInput) {
        return;
    }

    function slugify(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'repository';
    }

    function buildAutoUrl() {
        const repoSlug = slugify(slugInput.value || nameInput.value);
        const template = remoteUrlInput.dataset.template || '';
        if (template.indexOf('__slug__') !== -1) {
            return template.replace('__slug__', encodeURIComponent(repoSlug));
        }
        return `${window.BASE_URL}git/${encodeURIComponent(repoSlug)}.git`;
    }

    function syncAutoUrl() {
        remoteUrlInput.value = buildAutoUrl();
    }

    nameInput.addEventListener('input', syncAutoUrl);
    slugInput.addEventListener('input', syncAutoUrl);
    syncAutoUrl();
});

function copyCloneUrl(url) {
    const feedback = document.getElementById('cloneFeedback');
    const showFeedback = function (message) {
        if (!feedback) {
            return;
        }
        feedback.textContent = message;
        feedback.classList.add('show');
        window.setTimeout(function () {
            feedback.classList.remove('show');
        }, 2200);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url)
            .then(function () {
                showFeedback('Clone URL copied.');
            })
            .catch(function () {
                fallbackCopy(url, showFeedback);
            });
        return;
    }

    fallbackCopy(url, showFeedback);
}

function fallbackCopy(url, showFeedback) {
    const field = document.getElementById('httpCloneUrlField');
    if (field) {
        field.focus();
        field.select();
        field.setSelectionRange(0, field.value.length);
        try {
            document.execCommand('copy');
            showFeedback('Clone URL copied.');
            return;
        } catch (error) {
        }
    }

    if (field) {
        showFeedback('Copy the selected clone URL manually.');
        return;
    }

    showFeedback(url);
}

const repositoryAccessBranch = document.getElementById('repositoryAccessBranch');
const downloadRepositoryBranch = document.getElementById('downloadRepositoryBranch');
const openRepositoryBranch = document.getElementById('openRepositoryBranch');
const copyRepositoryCloneUrl = document.getElementById('copyRepositoryCloneUrl');

if (window.location.hash === '#repositoryActivity' || window.location.hash === '#repositoryChanges') {
    const targetTab = document.querySelector(`[data-bs-target="${window.location.hash}"]`);
    if (targetTab && window.bootstrap?.Tab) {
        window.bootstrap.Tab.getOrCreateInstance(targetTab).show();
    }
}

function updateRepositoryBranchActions() {
    if (!repositoryAccessBranch) {
        return;
    }

    const selectedOption = repositoryAccessBranch.options[repositoryAccessBranch.selectedIndex];
    const branchId = selectedOption?.value || '';
    const branchName = selectedOption?.dataset.branchName || '';
    if (downloadRepositoryBranch) {
        const downloadBase = downloadRepositoryBranch.dataset.downloadBase || '';
        downloadRepositoryBranch.href = downloadBase + '&ref=' + encodeURIComponent(branchName);
    }
    if (openRepositoryBranch) {
        const openBase = openRepositoryBranch.dataset.openBase || '';
        openRepositoryBranch.href = openBase + '&branch_id=' + encodeURIComponent(branchId) + '#selectedRepositorySnapshot';
    }
}

if (repositoryAccessBranch) {
    repositoryAccessBranch.addEventListener('change', function () {
        updateRepositoryBranchActions();
    });
    updateRepositoryBranchActions();
}

if (copyRepositoryCloneUrl) {
    copyRepositoryCloneUrl.addEventListener('click', function () {
        copyCloneUrl(copyRepositoryCloneUrl.dataset.cloneUrl || '');
    });
}

document.querySelectorAll('.js-delete-repository-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        if (form.dataset.confirmedSubmit === '1') {
            form.dataset.confirmedSubmit = '0';
            return;
        }

        event.preventDefault();

        const repositoryName = form.dataset.repositoryName || 'this repository';
        const confirmed = typeof window.appConfirm === 'function'
            ? await window.appConfirm({
                title: 'Delete Repository',
                subject: `Delete repository ${repositoryName}?`,
                message: 'This cannot be undone and will remove its managed storage and working folder.',
                icon: 'fas fa-code-branch fa-lg',
                confirmIcon: 'fas fa-trash-alt',
                confirmText: 'Delete Repository',
                variant: 'danger'
            })
            : false;

        if (!confirmed) {
            return;
        }

        form.dataset.confirmedSubmit = '1';
        form.requestSubmit();
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
