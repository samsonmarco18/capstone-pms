<?php
require_once __DIR__ . '/../../includes/folder-api.php';
require_once __DIR__ . '/../../includes/file-storage.php';
folderApiInitialize();

$user = folderApiRequireUser();
$fileIds = array_map('intval', $_POST['file_ids'] ?? []);
$fileIds = array_values(array_unique(array_filter($fileIds, static fn (int $id): bool => $id > 0)));

if (!$fileIds) {
    folderApiRespond(['success' => false, 'error' => 'No files selected.'], 400);
}

try {
    $conn = getConnection();
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $types = str_repeat('i', count($fileIds));
    $stmt = folderApiPrepare($conn, "SELECT f.*, fo.role AS folder_role, fo.user_id AS folder_user_id, fo.project_id AS folder_project_id, fo.client_id AS folder_client_id FROM files f JOIN folders fo ON fo.id = f.folder_id WHERE f.id IN ($placeholders)");
    $stmt->bind_param($types, ...$fileIds);
    folderApiExecute($stmt, $conn);
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($files) !== count($fileIds)) {
        folderApiRespond(['success' => false, 'error' => 'One or more selected files were not found.'], 404);
    }
    foreach ($files as $file) {
        if (!folderApiCanAccessFolderWithProject($conn, ['role' => $file['folder_role'] ?? null, 'user_id' => $file['folder_user_id'] ?? null, 'project_id' => $file['folder_project_id'] ?? null, 'client_id' => $file['folder_client_id'] ?? null], $user)) {
            folderApiRespond(['success' => false, 'error' => 'You do not have access to one or more selected files.'], 403);
        }
    }

foreach ($files as $file) {
    storageDeleteFile((string) $file['file_path']);
}

    $deleteStmt = folderApiPrepare($conn, "DELETE FROM files WHERE id IN ($placeholders)");
    $deleteStmt->bind_param($types, ...$fileIds);
    folderApiExecute($deleteStmt, $conn);
    $deleteStmt->close();

logActivity((int) $user['id'], 'bulk_delete_files', 'files', null, $files, ['file_ids' => $fileIds]);

    folderApiRespond(['success' => true, 'deleted_count' => count($files)]);
} catch (Throwable $e) {
    folderApiError($e, 'Bulk delete files API failed', 'Unable to delete the selected files right now.');
}
?>
