<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/version-control.php';

requireRole(['admin', 'architect', 'developer', 'sul', 'sales']);

$repositoryId = (int) ($_GET['repository_id'] ?? 0);
if ($repositoryId <= 0) {
    http_response_code(400);
    exit('Missing repository id.');
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
        (string) ($repository['external_remote_url'] ?? '')
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
    http_response_code(500);
    echo 'Failed to export repository files: ' . htmlspecialchars($e->getMessage());
    error_log('Repository export failed for slug: ' . $repository['slug'] . ' - ' . $e->getMessage());
} finally {
    if (is_array($archive) && !empty($archive['archive_path']) && file_exists((string) $archive['archive_path'])) {
        @unlink((string) $archive['archive_path']);
    }
}
?>
