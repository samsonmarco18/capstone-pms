<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project-folder-context.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$conn = getConnection();
$user = getCurrentUser();
$folderId = isset($_GET['folder_id']) ? (int) $_GET['folder_id'] : 0;

if ($folderId <= 0) {
    echo json_encode([
        'folders' => [],
        'files' => [],
        'current_folder' => null,
        'breadcrumb' => [],
    ]);
    exit();
}

function getFolderBreadcrumb(mysqli|PgsqlMysqliCompatConnection $conn, int $folderId): array
{
    $path = [];
    $currentId = $folderId;
    $stmt = $conn->prepare('SELECT id, parent_id, name, folder_type FROM folders WHERE id = ? LIMIT 1');

    while ($currentId > 0) {
        $stmt->bind_param('i', $currentId);
        $stmt->execute();
        $folder = $stmt->get_result()->fetch_assoc();

        if (!$folder) {
            break;
        }

        array_unshift($path, [
            'id' => (int) $folder['id'],
            'name' => $folder['name'],
            'folder_type' => $folder['folder_type'],
        ]);

        $currentId = (int) ($folder['parent_id'] ?? 0);
    }

    $stmt->close();

    return $path;
}

try {
    $currentFolderStmt = $conn->prepare(
        'SELECT f.*, u.full_name AS owner_name
         FROM folders f
         LEFT JOIN users u ON u.id = f.created_by
         WHERE f.id = ?
         LIMIT 1'
    );

    if ($currentFolderStmt === false) {
        throw new RuntimeException('Failed to prepare current folder query: ' . ($conn->error ?? 'unknown error'));
    }

    $currentFolderStmt->bind_param('i', $folderId);
    $currentFolderStmt->execute();
    $currentFolder = $currentFolderStmt->get_result()->fetch_assoc();
    $currentFolderStmt->close();

    if (!$currentFolder) {
        http_response_code(404);
        echo json_encode(['error' => 'Folder not found']);
        exit();
    }

    $folderStmt = $conn->prepare(
        'SELECT f.*, u.full_name AS owner_name
         FROM folders f
         LEFT JOIN users u ON u.id = f.created_by
         WHERE f.parent_id = ?
         ORDER BY f.name'
    );

    if ($folderStmt === false) {
        throw new RuntimeException('Failed to prepare child folders query: ' . ($conn->error ?? 'unknown error'));
    }

    $folderStmt->bind_param('i', $folderId);
    $folderStmt->execute();
    $folders = $folderStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $folderStmt->close();

    $fileStmt = $conn->prepare(
        'SELECT f.*, u.full_name AS owner_name
         FROM files f
         LEFT JOIN users u ON u.id = f.uploaded_by
         WHERE f.folder_id = ?
         ORDER BY f.created_at DESC'
    );

    if ($fileStmt === false) {
        throw new RuntimeException('Failed to prepare files query: ' . ($conn->error ?? 'unknown error'));
    }

    $fileStmt->bind_param('i', $folderId);
    $fileStmt->execute();
    $files = $fileStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fileStmt->close();

    $projectContext = null;
    $projectId = resolveFolderProjectId($conn, $currentFolder);
    if ($projectId > 0) {
        $projectContext = fetchProjectContext($conn, $projectId, $user);
    }

    echo json_encode([
        'folders' => $folders,
        'files' => $files,
        'current_folder' => $currentFolder,
        'breadcrumb' => getFolderBreadcrumb($conn, $folderId),
        'project_context' => $projectContext,
        'project_details' => $projectContext,
        'project_id' => $projectId > 0 ? $projectId : null,
    ]);
} catch (Throwable $e) {
    error_log('Folder contents API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to load this folder right now.',
    ]);
}
