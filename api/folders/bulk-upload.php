<?php
require_once __DIR__ . '/../../includes/folder-api.php';
require_once __DIR__ . '/../../includes/file-storage.php';
folderApiInitialize();

$user = folderApiRequireUser();
$folderId = (int) ($_POST['folder_id'] ?? 0);

if ($folderId <= 0 || !isset($_FILES['files'])) {
    folderApiRespond(['success' => false, 'error' => 'Invalid upload request.'], 400);
}

try {
    $conn = getConnection();
    $folderStmt = folderApiPrepare($conn, 'SELECT * FROM folders WHERE id = ? LIMIT 1');
    $folderStmt->bind_param('i', $folderId);
    folderApiExecute($folderStmt, $conn);
    $folderExists = $folderStmt->get_result()->fetch_assoc();
    $folderStmt->close();
    if (!$folderExists) {
        folderApiRespond(['success' => false, 'error' => 'Upload folder not found.'], 404);
    }
    if (!folderApiCanAccessFolder($folderExists, $user)) {
        folderApiRespond(['success' => false, 'error' => 'You do not have access to this folder.'], 403);
    }

    $createdIds = [];
    $failures = [];
    foreach ($_FILES['files']['name'] as $index => $originalName) {
        $uploadError = (int) ($_FILES['files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($_FILES['files']['size'][$index] ?? 0);
        if ($uploadError !== UPLOAD_ERR_OK || $size <= 0 || $size > UPLOAD_MAX_SIZE) {
            $failures[] = (string) $originalName;
            continue;
        }

        $target = storageBuildUploadTarget((string) $originalName);

        if (!move_uploaded_file($_FILES['files']['tmp_name'][$index], $target['absolute_path'])) {
            $failures[] = (string) $originalName;
            continue;
        }

        $extension = strtolower((string) pathinfo($target['original_name'], PATHINFO_EXTENSION));
        $stmt = folderApiPrepare($conn,
            'INSERT INTO files (folder_id, original_file_name, stored_file_name, file_type, file_size, file_path, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $userId = (int) $user['id'];
        $stmt->bind_param('isssisi', $folderId, $target['original_name'], $target['stored_name'], $extension, $size, $target['relative_path'], $userId);
        if (!$stmt->execute()) {
            @unlink($target['absolute_path']);
            throw new RuntimeException('Database operation failed: ' . ($conn->error ?: 'unknown error'));
        }
        $createdIds[] = $stmt->insert_id;
        $stmt->close();
    }

    if (!$createdIds) {
        folderApiRespond(['success' => false, 'error' => 'No files could be uploaded. Check file size and directory permissions.'], 400);
    }

    logActivity((int) $user['id'], 'bulk_upload_files', 'files', null, null, ['file_ids' => $createdIds, 'folder_id' => $folderId]);
    folderApiRespond(['success' => true, 'file_ids' => $createdIds, 'failed_files' => $failures]);
} catch (Throwable $e) {
    folderApiError($e, 'Bulk upload API failed', 'Unable to upload files right now.');
}
?>
