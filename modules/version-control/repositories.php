<?php
require_once '../../includes/auth.php';
require_once '../../includes/version-control.php';
require_once '../../includes/version-control-actions.php';

requireRole(['admin', 'architect', 'developer', 'sul']);

$conn = getConnection();
$user = getCurrentUser();
$accessibleProjects = vcGetAccessibleProjects($conn, $user);
$execAvailable = vcIsExecAvailable();

function vcPageUrl(int $projectId = 0, int $repositoryId = 0, int $commitId = 0): string
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

    return 'repositories.php' . ($params ? '?' . http_build_query($params) : '');
}

$selectedProjectId = (int) ($_GET['project_id'] ?? 0);
$selectedRepositoryId = (int) ($_GET['repository_id'] ?? 0);
$selectedCommitId = (int) ($_GET['commit_id'] ?? 0);

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

    $commitStmt = $conn->prepare(
        "SELECT rc.*, rb.name AS branch_name, u.full_name AS author_name
         FROM repository_commits rc
         INNER JOIN repository_branches rb ON rb.id = rc.branch_id
         INNER JOIN users u ON u.id = rc.authored_by
         WHERE rc.repository_id = ?
         ORDER BY rc.created_at DESC, rc.id DESC
         LIMIT 15"
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
.vc-header,.vc-card{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:24px;box-shadow:0 14px 34px rgba(15,23,42,.05);}
.vc-header{padding:2rem;margin-bottom:1.5rem;background:linear-gradient(135deg,#19567f 0%,#1f6392 55%,#2f83b9 100%);color:#fff;position:relative;overflow:hidden;}
.vc-header:before,.vc-header:after{content:'';position:absolute;border-radius:999px;background:rgba(255,255,255,.09);}
.vc-header:before{width:220px;height:220px;top:-90px;right:-40px;}
.vc-header:after{width:160px;height:160px;bottom:-90px;right:160px;}
.vc-header-content{position:relative;z-index:1;display:flex;justify-content:space-between;gap:1.5rem;align-items:flex-end;flex-wrap:wrap;}
.vc-header-copy{max-width:760px;}
.vc-header-title{font-size:2rem;font-weight:700;letter-spacing:-.02em;}
.vc-header-subtitle{max-width:620px;color:rgba(255,255,255,.82);}
.vc-header-badge{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem .9rem;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);backdrop-filter:blur(4px);font-size:.85rem;}
.vc-card+.vc-card{margin-top:1.5rem;}
.vc-card .card-head{padding:1.1rem 1.35rem;border-bottom:1px solid var(--gray-200);font-weight:700;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;}
.section-kicker{font-size:.74rem;letter-spacing:.08em;text-transform:uppercase;color:var(--gray-600);}
.project-picker{padding:1.1rem 1.15rem;border:1px solid var(--gray-200);background:linear-gradient(180deg,#fff 0%,#f8fbfd 100%);border-radius:20px;margin-bottom:1.25rem;}
.repo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1rem;}
.repo-tile{display:flex;flex-direction:column;gap:.9rem;text-decoration:none;color:inherit;border:1px solid var(--gray-200);border-radius:20px;padding:1.1rem;background:linear-gradient(180deg,#fff 0%,#fbfdff 100%);height:100%;transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;}
.repo-tile.active,.repo-tile:hover{border-color:rgba(31,99,146,.35);box-shadow:0 14px 24px rgba(31,99,146,.10);transform:translateY(-2px);}
.repo-tile-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;}
.repo-title{font-weight:700;font-size:1rem;line-height:1.3;}
.repo-slug{font-size:.8rem;color:var(--gray-600);margin-top:.15rem;word-break:break-word;}
.repo-description{font-size:.86rem;color:var(--gray-700);min-height:2.5rem;}
.repo-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem;}
.repo-stat{background:var(--gray-25);border:1px solid var(--gray-200);border-radius:16px;padding:.7rem .8rem;}
.repo-stat strong{display:block;font-size:1rem;color:var(--gray-900);line-height:1.1;}
.repo-stat span{display:block;font-size:.72rem;color:var(--gray-600);margin-top:.2rem;}
.repo-footnote{font-size:.78rem;color:var(--gray-600);padding-top:.2rem;border-top:1px dashed var(--gray-200);}
.pill{display:inline-flex;align-items:center;padding:.3rem .7rem;border-radius:999px;font-size:.72rem;font-weight:700;line-height:1;}
.pill.blue{background:#dbeafe;color:#1d4ed8;}.pill.green{background:#d1fae5;color:#047857;}.pill.gray{background:#e5e7eb;color:#4b5563;}
.repo-hero{border:1px solid var(--theme-line);border-radius:22px;padding:1.2rem;background:linear-gradient(180deg,#ffffff 0%,#f3f9fd 100%);margin-bottom:1.25rem;}
.repo-hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;}
.repo-hero-title{font-size:1.35rem;font-weight:700;line-height:1.25;}
.repo-meta{font-size:.88rem;color:var(--gray-600);}
.repo-meta code,.hook-box code{font-size:.8rem;}
.repo-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.8rem;margin-top:1rem;}
.summary-tile{padding:.85rem .95rem;border-radius:18px;background:#fff;border:1px solid var(--gray-200);}
.summary-tile strong{display:block;font-size:1rem;color:var(--gray-900);}
.summary-tile span{display:block;font-size:.74rem;color:var(--gray-600);margin-top:.2rem;}
.content-panel{border:1px solid var(--gray-200);border-radius:20px;padding:1rem;background:#fff;height:100%;}
.content-panel h6{margin-bottom:1rem;}
.table-shell{border:1px solid var(--gray-200);border-radius:18px;overflow:hidden;background:#fff;}
.table-shell .table{margin-bottom:0;}
.table-shell thead th{background:#f8fbfd;color:var(--gray-700);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--gray-200);}
.table-shell tbody tr:last-child td{border-bottom:none;}
.timeline-list{display:flex;flex-direction:column;gap:.9rem;}
.timeline-item{position:relative;padding:0 0 .2rem 1.35rem;border-left:2px solid var(--gray-200);}
.timeline-item:last-child{padding-bottom:0;border-left-color:transparent;}
.timeline-item:before{content:'';position:absolute;left:-6px;top:4px;width:10px;height:10px;border-radius:50%;background:var(--theme-primary);box-shadow:0 0 0 4px rgba(31,99,146,.10);}
.timeline-item.active{border-left-color:rgba(31,99,146,.4);}
.timeline-item.active:before{background:#0f9d58;box-shadow:0 0 0 4px rgba(15,157,88,.12);}
.timeline-title{font-weight:700;color:var(--gray-900);}
.commit-link{display:block;color:inherit;text-decoration:none;padding-right:.25rem;}
.commit-link:hover .timeline-title{color:var(--theme-primary);}
.merge-card{border:1px solid var(--gray-200);border-radius:18px;padding:1rem;background:linear-gradient(180deg,#fff 0%,#fbfdff 100%);}
.merge-card+.merge-card{margin-top:.9rem;}
.empty-box{border:1px dashed var(--gray-300);border-radius:18px;padding:2rem;text-align:center;color:var(--gray-600);background:linear-gradient(180deg,#fff 0%,#fbfcfe 100%);}
.setup-stack{display:flex;flex-direction:column;gap:1rem;}
.setup-panel{border:1px solid var(--gray-200);border-radius:20px;padding:1rem;background:linear-gradient(180deg,#fff 0%,#f8fbfd 100%);}
.setup-note{font-size:.82rem;color:var(--gray-600);line-height:1.5;}
.setup-chip-row{display:flex;flex-wrap:wrap;gap:.45rem;}
.setup-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.38rem .8rem;border-radius:999px;border:1px solid var(--gray-200);background:#fff;font-size:.76rem;font-weight:600;}
.clone-action-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin-bottom:1rem;}
.clone-action-row .btn{border-radius:14px;padding:.72rem .95rem;font-weight:700;}
.clone-feedback{display:none;font-size:.8rem;color:#047857;margin-bottom:.75rem;}
.clone-feedback.show{display:block;}
.hook-box{background:#0d1117;color:#c9d1d9;border-radius:16px;padding:1rem;overflow:auto;font-size:.78rem;line-height:1.45;max-height:320px;}
.vc-sidebar{position:sticky;top:1.25rem;}
.vc-card .accordion-item{border:1px solid var(--gray-200);border-radius:18px;overflow:hidden;background:#fff;}
.vc-card .accordion-item+.accordion-item{margin-top:.85rem;}
.vc-card .accordion-button{font-weight:700;background:#fff;box-shadow:none;}
.vc-card .accordion-button:not(.collapsed){background:var(--theme-soft);color:var(--theme-dark);}
.vc-card .accordion-button:focus{box-shadow:none;border-color:transparent;}
.vc-card .accordion-body{padding:1rem;}
.vc-card .form-label{font-size:.78rem;font-weight:700;letter-spacing:.02em;color:var(--gray-700);text-transform:uppercase;}
.vc-card .form-control,.vc-card .form-select{border-radius:14px;padding:.7rem .85rem;border-color:var(--gray-300);}
.vc-card .form-control:focus,.vc-card .form-select:focus{border-color:rgba(31,99,146,.4);box-shadow:0 0 0 .2rem rgba(31,99,146,.12);}
.vc-card .btn.btn-primary{border-radius:14px;padding:.72rem .95rem;font-weight:700;background:linear-gradient(135deg,var(--theme-primary),var(--theme-dark));border:none;}
.vc-card .btn.btn-primary:hover{background:linear-gradient(135deg,var(--theme-dark),var(--theme-primary));}
.vc-card .btn.btn-outline-primary{border-radius:14px;padding:.72rem .95rem;font-weight:700;border-color:rgba(31,99,146,.28);color:var(--theme-primary);}
.vc-card .btn.btn-outline-primary:hover{background:var(--theme-primary);border-color:var(--theme-primary);color:#fff;}
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
@media (max-width: 1199.98px){.vc-sidebar{position:static;}}
@media (max-width: 575.98px){.clone-action-row{grid-template-columns:1fr;}}
@media (max-width: 767.98px){.vc-header{padding:1.5rem;}.vc-header-title{font-size:1.6rem;}.repo-stats,.repo-summary-grid{grid-template-columns:1fr;}}
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
                    <form method="GET" class="row g-3 align-items-end project-picker">
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
                    <div class="repo-grid">
                        <?php foreach ($repositories as $repo): ?>
                        <a href="<?php echo htmlspecialchars(vcPageUrl($selectedProjectId, (int) $repo['id'])); ?>" class="repo-tile <?php echo (int) $repo['id'] === $selectedRepositoryId ? 'active' : ''; ?>">
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
                </div>
            </div>

            <?php if ($selectedRepository): ?>
            <div class="vc-card">
                <div class="card-head">
                    <div>
                        <div class="section-kicker">Selected Repository</div>
                        <div><?php echo htmlspecialchars($selectedRepository['name']); ?> Snapshot</div>
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
                                            <tr>
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
                                <h6 class="fw-semibold">Recent Commits</h6>
                                <?php if (!$commits): ?><div class="empty-box">No commits yet.</div><?php else: ?>
                                <div class="timeline-list">
                                    <?php foreach ($commits as $commit): ?>
                                    <div class="timeline-item <?php echo (int) $commit['id'] === $selectedCommitId ? 'active' : ''; ?>">
                                        <a class="commit-link" href="<?php echo htmlspecialchars(vcPageUrl($selectedProjectId, $selectedRepositoryId, (int) $commit['id'])); ?>">
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

            <div class="vc-card">
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
                                <div class="timeline-list">
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
                                <?php foreach ($merges as $merge): ?>
                                <div class="merge-card">
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
            </div>

            <div class="vc-card">
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
            <?php endif; ?>
        </div>

        <div class="col-xl-4">
            <div class="vc-sidebar">
            <div class="vc-card">
                <div class="card-head">
                    <div>
                        <div class="section-kicker">Workspace Tools</div>
                        <div>Actions</div>
                    </div>
                </div>
                <div class="p-4">
                    <?php if ($selectedRepository): ?>
                    <div class="setup-stack mb-4">
                        <div class="setup-panel">
                            <h6 class="fw-semibold mb-3">Repository Access</h6>
                            <div id="cloneFeedback" class="clone-feedback">Clone URL copied.</div>
                            <div class="clone-action-row">
                                <button type="button" class="btn btn-primary" onclick="copyCloneUrl('<?php echo htmlspecialchars($selectedRepositoryDisplayRemoteUrl, ENT_QUOTES); ?>')">
                                    <i class="fas fa-code-branch me-2"></i>Copy Clone URL
                                </button>
                                <?php if (!$selectedRepositoryHasWorkspace && !in_array($user['role'], ['sul'], true)): ?>
                                <?php if ($execAvailable): ?>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="action" value="create_working_folder">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-folder-plus me-2"></i>Create Working Folder
                                    </button>
                                </form>
                                <?php else: ?>
                                <button type="button" class="btn btn-outline-primary w-100" disabled title="Git operations not available on this server">
                                    <i class="fas fa-folder-plus me-2"></i>Create Working Folder (Unavailable)
                                </button>
                                <?php endif; ?>
                                <?php else: ?>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars(BASE_URL . 'api/version-control/download-repository.php?repository_id=' . $selectedRepositoryId); ?>">
                                    <i class="fas fa-download me-2"></i>Download Files
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Working Folder</label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($selectedRepositoryWorkspacePath); ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label"><?php echo htmlspecialchars(vcGetRepositoryManagedCloneLabel()); ?></label>
                                <input class="form-control" id="httpCloneUrlField" readonly value="<?php echo htmlspecialchars(vcResolveManagedRepositoryDisplayUrl((string) $selectedRepository['slug'])); ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Server Repository Path</label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($selectedRepositoryLocalClonePath); ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label"><?php echo htmlspecialchars(vcGetRepositoryExternalRemoteLabel()); ?></label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($selectedRepositoryExternalRemoteUrl); ?>" placeholder="<?php echo htmlspecialchars(vcGetRepositoryExternalRemotePlaceholder()); ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">File Clone URL</label>
                                <input class="form-control" readonly value="<?php echo htmlspecialchars($selectedRepositoryFileCloneUrl); ?>">
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
                    <?php endif; ?>
                    <div class="accordion" id="vcAccordion">
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
                            <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#repoAction">Create Repository</button></h2>
                            <div id="repoAction" class="accordion-collapse collapse show" data-bs-parent="#vcAccordion">
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
                        <?php if (!in_array($user['role'], ['sul'], true)): ?>
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
                        <?php if (!in_array($user['role'], ['sul'], true)): ?>
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

                        <?php if (!in_array($user['role'], ['sul'], true)): ?>
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

                        <?php if (!in_array($user['role'], ['sul'], true)): ?>
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

                        <?php if (in_array($user['role'], ['sul', 'architect'], true)): ?>
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

                        <?php if (!in_array($user['role'], ['sul'], true)): ?>
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
                                <form method="POST" onsubmit="return confirm('Delete repository <?php echo htmlspecialchars(addslashes((string) $selectedRepository['name'])); ?>? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete_repository">
                                    <input type="hidden" name="project_id" value="<?php echo $selectedProjectId; ?>">
                                    <input type="hidden" name="repository_id" value="<?php echo $selectedRepositoryId; ?>">
                                    <button class="btn btn-danger w-100">Delete Repository</button>
                                </form>
                            </div></div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

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

    window.prompt('Copy this clone URL:', url);
}
</script>

<?php include '../../includes/footer.php'; ?>
