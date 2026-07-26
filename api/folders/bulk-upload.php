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

if ($folderId <= 0 || !isset($_FILES['files'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid upload request.']);
    exit();
}

$createdIds = [];
foreach ($_FILES['files']['name'] as $index => $originalName) {
    if ($_FILES['files']['error'][$index] !== UPLOAD_ERR_OK) {
        continue;
    }

    try {
        $target = storageBuildUploadTarget((string) $originalName);
    } catch (Throwable $e) {
        continue;
    }

    if (!move_uploaded_file($_FILES['files']['tmp_name'][$index], $target['absolute_path'])) {
        continue;
    }

    $extension = pathinfo($target['original_name'], PATHINFO_EXTENSION);
    $stmt = $conn->prepare(
        'INSERT INTO files (folder_id, original_file_name, stored_file_name, file_type, file_size, file_path, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $size = (int) $_FILES['files']['size'][$index];
    $stmt->bind_param('isssisi', $folderId, $target['original_name'], $target['stored_name'], $extension, $size, $target['relative_path'], $user['id']);
    $stmt->execute();
    $createdIds[] = $stmt->insert_id;
    $stmt->close();
}

logActivity((int) $user['id'], 'bulk_upload_files', 'files', null, null, ['file_ids' => $createdIds, 'folder_id' => $folderId]);

echo json_encode([
    'success' => true,
    'file_ids' => $createdIds,
]);
?>
