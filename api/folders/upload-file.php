<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/file-storage.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = getCurrentUser();
$conn = getConnection();
$folderId = (int) ($_POST['folder_id'] ?? 0);
$file = $_FILES['file'] ?? null;

if ($folderId <= 0 || !$file || $file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid upload request.']);
    exit();
}

try {
    $target = storageBuildUploadTarget((string) $file['name']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}

if (!move_uploaded_file($file['tmp_name'], $target['absolute_path'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to save uploaded file.']);
    exit();
}

$extension = pathinfo($target['original_name'], PATHINFO_EXTENSION);
$stmt = $conn->prepare(
    'INSERT INTO files (folder_id, original_file_name, stored_file_name, file_type, file_size, file_path, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('isssisi', $folderId, $target['original_name'], $target['stored_name'], $extension, $file['size'], $target['relative_path'], $user['id']);

if (!$stmt->execute()) {
    @unlink($target['absolute_path']);
    http_response_code(500);
    echo json_encode(['error' => 'Database error while saving file metadata.']);
    exit();
}

$fileId = $stmt->insert_id;
$stmt->close();

logActivity((int) $user['id'], 'upload_file', 'files', $fileId, null, ['folder_id' => $folderId, 'file' => $target['original_name']]);

echo json_encode([
    'success' => true,
    'file_id' => $fileId,
]);
?>
