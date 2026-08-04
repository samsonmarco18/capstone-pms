<?php
require_once __DIR__ . '/../../includes/folder-api.php';
folderApiInitialize();

$user = folderApiRequireUser();
$folderId = (int) ($_POST['id'] ?? 0);

if ($folderId <= 0) {
    folderApiRespond(['success' => false, 'error' => 'Invalid folder.'], 400);
}

try {
    $conn = getConnection();
    $stmt = folderApiPrepare($conn, 'SELECT * FROM folders WHERE id = ?');
    $stmt->bind_param('i', $folderId);
    folderApiExecute($stmt, $conn);
    $folder = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$folder) {
        folderApiRespond(['success' => false, 'error' => 'Folder not found.'], 404);
    }
    if (!folderApiCanAccessFolder($folder, $user)) {
        folderApiRespond(['success' => false, 'error' => 'You do not have access to this folder.'], 403);
    }

    if (in_array((string) ($folder['folder_type'] ?? ''), ['client', 'project'], true)) {
        folderApiRespond(['success' => false, 'error' => 'Client and shared project root folders cannot be deleted here.'], 403);
    }

    $deleteStmt = folderApiPrepare($conn, 'DELETE FROM folders WHERE id = ?');
    $deleteStmt->bind_param('i', $folderId);
    folderApiExecute($deleteStmt, $conn);
    $deleteStmt->close();

    logActivity((int) $user['id'], 'delete_folder', 'folders', $folderId, $folder, null);

    folderApiRespond(['success' => true]);
} catch (Throwable $e) {
    folderApiError($e, 'Delete folder API failed', 'Unable to delete this folder right now.');
}
?>
