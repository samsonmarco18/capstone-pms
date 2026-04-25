<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/version-control.php';

requireRole(['admin', 'architect', 'developer', 'sul']);

$repositoryId = (int) ($_GET['repository_id'] ?? 0);
if ($repositoryId <= 0) {
    http_response_code(400);
    exit('Missing repository id.');
}

$conn = getConnection();
$user = getCurrentUser();
if (!$user || !vcCanAccessRepository($conn, $user, $repositoryId)) {
    http_response_code(403);
    exit('You do not have access to this repository.');
}

$repository = vcGetRepository($conn, $repositoryId);
if (!$repository) {
    http_response_code(404);
    exit('Repository not found.');
}

$archive = null;

try {
    $archive = vcCreateRepositoryArchive(
        (string) $repository['slug'],
        (string) ($repository['default_branch_name'] ?: 'main'),
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
