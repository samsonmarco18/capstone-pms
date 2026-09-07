<?php
require_once __DIR__ . '/../../includes/folder-api.php';
require_once __DIR__ . '/../../includes/project-folder-context.php';
folderApiInitialize();

$user = folderApiRequireUser();
$conn = getConnection();
$folderId = isset($_GET['folder_id']) ? (int) $_GET['folder_id'] : 0;

if ($folderId <= 0) {
    folderApiRespond([
        'success' => true,
        'folders' => [],
        'files' => [],
        'current_folder' => null,
        'breadcrumb' => [],
    ]);
}

function getFolderBreadcrumb(mysqli|PgsqlMysqliCompatConnection $conn, int $folderId): array
{
    $path = [];
    $currentId = $folderId;
    $stmt = $conn->prepare('SELECT id, parent_id, name, folder_type FROM folders WHERE id = ? LIMIT 1');
    if ($stmt === false) {
        error_log('Folder breadcrumb prepare failed: ' . ($conn->error ?? 'unknown error'));
        return [];
    }

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
        folderApiRespond(['success' => false, 'error' => 'Folder not found'], 404);
    }
    if (!folderApiCanAccessFolderWithProject($conn, $currentFolder, $user)) {
        folderApiRespond(['success' => false, 'error' => 'You do not have access to this folder.'], 403);
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
    $folders = array_values(array_filter(
        $folderStmt->get_result()->fetch_all(MYSQLI_ASSOC),
        static fn (array $folder): bool => folderApiCanAccessFolderWithProject($conn, $folder, $user)
    ));
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

    folderApiRespond([
        'success' => true,
        'folders' => $folders,
        'files' => $files,
        'current_folder' => $currentFolder,
        'breadcrumb' => getFolderBreadcrumb($conn, $folderId),
        'project_context' => $projectContext,
        'project_details' => $projectContext,
        'project_id' => $projectId > 0 ? $projectId : null,
    ]);
} catch (Throwable $e) {
    folderApiError($e, 'Folder contents API failed', 'Unable to load this folder right now.');
}
