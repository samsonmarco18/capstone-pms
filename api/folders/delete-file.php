<?php
require_once __DIR__ . '/../../includes/folder-api.php';
require_once __DIR__ . '/../../includes/file-storage.php';
folderApiInitialize();

$user = folderApiRequireUser();
$fileId = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

if ($fileId <= 0) {
    folderApiRespond(['success' => false, 'error' => 'Invalid file.'], 400);
}

try {
    $conn = getConnection();
    $stmt = folderApiPrepare($conn, 'SELECT f.*, fo.role AS folder_role, fo.user_id AS folder_user_id, fo.project_id AS folder_project_id, fo.client_id AS folder_client_id FROM files f JOIN folders fo ON fo.id = f.folder_id WHERE f.id = ?');
    $stmt->bind_param('i', $fileId);
    folderApiExecute($stmt, $conn);
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$file) {
        folderApiRespond(['success' => false, 'error' => 'File not found.'], 404);
    }
    if (!folderApiCanAccessFolderWithProject($conn, ['role' => $file['folder_role'] ?? null, 'user_id' => $file['folder_user_id'] ?? null, 'project_id' => $file['folder_project_id'] ?? null, 'client_id' => $file['folder_client_id'] ?? null], $user)) {
        folderApiRespond(['success' => false, 'error' => 'You do not have access to this file.'], 403);
    }

    storageDeleteFile((string) $file['file_path']);

    $deleteStmt = folderApiPrepare($conn, 'DELETE FROM files WHERE id = ?');
    $deleteStmt->bind_param('i', $fileId);
    folderApiExecute($deleteStmt, $conn);
    $deleteStmt->close();

    logActivity((int) $user['id'], 'delete_file', 'files', $fileId, $file, null);

    folderApiRespond(['success' => true]);
} catch (Throwable $e) {
    folderApiError($e, 'Delete file API failed', 'Unable to delete this file right now.');
}
?>
