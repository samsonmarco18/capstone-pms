<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/version-control.php';

requireRole(['admin', 'architect', 'developer', 'sul', 'qa', 'sales']);

$repositoryId = (int) ($_GET['repository_id'] ?? 0);
$projectId = (int) ($_GET['project_id'] ?? 0);
if ($repositoryId <= 0) {
    if ($projectId <= 0) {
        http_response_code(400);
        exit('Missing repository or project id.');
    }

    $conn = getConnection();
    $user = getCurrentUser();
    if (!$user || !vcCanAccessProject($conn, $user, $projectId)) {
        http_response_code(403);
        exit('You do not have access to this project.');
    }

    $projectStmt = $conn->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
    $projectStmt->bind_param('i', $projectId);
    $projectStmt->execute();
    $project = $projectStmt->get_result()->fetch_assoc();
    $projectStmt->close();
    if (!$project) {
        http_response_code(404);
        exit('Project not found.');
    }

    $historyStmt = $conn->prepare(
        "SELECT t.id AS task_id, t.title AS task_title, tgu.branch_name,
                tgu.commit_hash, tgu.commit_message, tgu.pull_request_url,
                tgu.git_action, tgu.status, tgu.notes, tgu.repository_url,
                tgu.created_at, u.full_name AS recorded_by
         FROM task_git_updates tgu
         INNER JOIN tasks t ON t.id = tgu.task_id
         INNER JOIN users u ON u.id = tgu.user_id
         WHERE t.project_id = ?
         ORDER BY tgu.created_at ASC, tgu.id ASC"
    );
    $historyStmt->bind_param('i', $projectId);
    $historyStmt->execute();
    $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyStmt->close();

    $safeProjectName = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $project['name']) ?: 'project';
    $downloadName = strtolower(trim($safeProjectName, '-')) . '-stored-git-history.json';
    $payload = [
        'project_id' => $projectId,
        'project_name' => (string) $project['name'],
        'export_type' => 'stored_branch_history',
        'notice' => 'Managed Git files were unavailable or no repository record was registered. This export contains the Git history stored by ERNI PMS.',
        'exported_at' => date(DATE_ATOM),
        'updates' => $history,
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$conn = getConnection();
$user = getCurrentUser();
$repository = vcGetRepository($conn, $repositoryId);
if (!$repository) {
    http_response_code(404);
    exit('Repository not found.');
}

if (!$user) {
    http_response_code(403);
    exit('You do not have access to this repository.');
}

$userRole = (string) ($user['role'] ?? '');
if ($userRole === 'sales') {
    $userId = (int) ($user['id'] ?? 0);
    $isCompletedProject = (string) ($repository['project_status'] ?? '') === 'completed';
    $isSalesOwner = $userId > 0
        && (
            (int) ($repository['project_created_by'] ?? 0) === $userId
            || (int) ($repository['client_created_by'] ?? 0) === $userId
        );

    if (!$isCompletedProject || !$isSalesOwner) {
        http_response_code(403);
        exit('Sales can download repository files only for completed projects assigned to them.');
    }
} elseif (!vcCanAccessRepository($conn, $user, $repositoryId)) {
    http_response_code(403);
    exit('You do not have access to this repository.');
}

$requestedRef = trim((string) ($_GET['ref'] ?? ''));
$archiveRef = (string) ($repository['default_branch_name'] ?: 'main');
if ($requestedRef !== '') {
    $branchStmt = $conn->prepare(
        "SELECT name
         FROM repository_branches
         WHERE repository_id = ? AND name = ? AND branch_status != 'deleted'
         LIMIT 1"
    );
    $branchStmt->bind_param('is', $repositoryId, $requestedRef);
    $branchStmt->execute();
    $branch = $branchStmt->get_result()->fetch_assoc();
    $branchStmt->close();

    if (!$branch) {
        http_response_code(400);
        exit('The selected branch is unavailable.');
    }
    $archiveRef = (string) $branch['name'];
}

$archive = null;

try {
    $archive = vcCreateRepositoryArchive(
        (string) $repository['slug'],
        $archiveRef,
        (string) ($repository['external_remote_url'] ?? ''),
        (string) ($repository['webhook_token'] ?? '')
    );

    $archivePath = (string) $archive['archive_path'];
    $downloadName = (string) $archive['download_name'];
    $fileSize = filesize($archivePath);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    if ($fileSize !== false) {
        header('Content-Length: ' . $fileSize);
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');

    readfile($archivePath);
} catch (Throwable $e) {
    error_log('Repository export failed for slug: ' . $repository['slug'] . ' - ' . $e->getMessage());
    $historyStmt = $conn->prepare(
        "SELECT rc.commit_hash, rc.commit_title, rc.commit_message,
                rc.files_changed, rc.lines_added, rc.lines_removed,
                rc.commit_kind, rc.created_at, rb.name AS branch_name,
                u.full_name AS author_name
         FROM repository_commits rc
         INNER JOIN repository_branches rb ON rb.id = rc.branch_id
         INNER JOIN users u ON u.id = rc.authored_by
         WHERE rc.repository_id = ? AND rb.name = ?
         ORDER BY rc.created_at ASC, rc.id ASC"
    );
    $historyStmt->bind_param('is', $repositoryId, $archiveRef);
    $historyStmt->execute();
    $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyStmt->close();

    $safeSlug = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $repository['slug']) ?: 'repository';
    $safeRef = preg_replace('/[^a-z0-9_-]+/i', '-', $archiveRef) ?: 'branch';
    $downloadName = strtolower(trim($safeSlug, '-')) . '-' . strtolower(trim($safeRef, '-')) . '-stored-history.json';
    $payload = [
        'repository_id' => $repositoryId,
        'repository_name' => (string) $repository['name'],
        'branch' => $archiveRef,
        'export_type' => 'stored_branch_history',
        'notice' => 'The managed Git files were unavailable. This download contains the repository history retained by ERNI PMS.',
        'exported_at' => date(DATE_ATOM),
        'commits' => $history,
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    if (is_array($archive) && !empty($archive['archive_path']) && file_exists((string) $archive['archive_path'])) {
        @unlink((string) $archive['archive_path']);
    }
}
?>
