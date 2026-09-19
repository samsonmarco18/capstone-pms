<?php
require_once __DIR__ . '/../../includes/folder-api.php';
require_once __DIR__ . '/../../includes/file-storage.php';
folderApiInitialize();

$user = folderApiRequireUser();
$folderId = (int) ($_POST['folder_id'] ?? 0);
$file = $_FILES['file'] ?? null;

if ($folderId <= 0 || !$file || $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] <= 0 || (int) $file['size'] > UPLOAD_MAX_SIZE) {
    folderApiRespond(['success' => false, 'error' => 'Invalid upload request or file size.'], 400);
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
    if (!folderApiCanAccessFolderWithProject($conn, $folderExists, $user)) {
        folderApiRespond(['success' => false, 'error' => 'You do not have access to this folder.'], 403);
    }

    $target = storageBuildUploadTarget((string) $file['name']);
} catch (Throwable $e) {
    folderApiError($e, 'Upload target preparation failed', 'The upload directory is unavailable.');
}

if (!move_uploaded_file($file['tmp_name'], $target['absolute_path'])) {
    folderApiRespond(['success' => false, 'error' => 'Unable to save uploaded file.'], 500);
}

try {
    storagePersistFile($target['relative_path'], $target['absolute_path'], $target['original_name'], (string) ($file['type'] ?? ''));

    $extension = strtolower((string) pathinfo($target['original_name'], PATHINFO_EXTENSION));
    $stmt = folderApiPrepare($conn,
        'INSERT INTO files (folder_id, original_file_name, stored_file_name, file_type, file_size, file_path, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $fileSize = (int) $file['size'];
    $userId = (int) $user['id'];
    $stmt->bind_param('isssisi', $folderId, $target['original_name'], $target['stored_name'], $extension, $fileSize, $target['relative_path'], $userId);

    if (!$stmt->execute()) {
        storageDeleteFile($target['relative_path']);
        throw new RuntimeException('Database operation failed: ' . ($conn->error ?: 'unknown error'));
    }

$fileId = $stmt->insert_id;
$stmt->close();

logActivity((int) $user['id'], 'upload_file', 'files', $fileId, null, ['folder_id' => $folderId, 'file' => $target['original_name']]);

    folderApiRespond(['success' => true, 'file_id' => $fileId]);
} catch (Throwable $e) {
    storageDeleteFile($target['relative_path']);
    folderApiError($e, 'Upload file API failed', 'Unable to save the uploaded file right now.');
}
?>
