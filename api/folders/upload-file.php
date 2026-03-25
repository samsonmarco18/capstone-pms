<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

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

$uploadRoot = dirname(__DIR__, 2) . '/uploads';
$dailyDirectory = $uploadRoot . '/' . date('Y/m/d');
if (!is_dir($dailyDirectory)) {
    mkdir($dailyDirectory, 0777, true);
}

$originalName = basename($file['name']);
$storedName = uniqid('file_', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $originalName);
$absolutePath = $dailyDirectory . '/' . $storedName;
$relativePath = 'uploads/' . date('Y/m/d') . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to save uploaded file.']);
    exit();
}

$extension = pathinfo($originalName, PATHINFO_EXTENSION);
$stmt = $conn->prepare(
    'INSERT INTO files (folder_id, original_file_name, stored_file_name, file_type, file_size, file_path, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('isssisi', $folderId, $originalName, $storedName, $extension, $file['size'], $relativePath, $user['id']);

if (!$stmt->execute()) {
    @unlink($absolutePath);
    http_response_code(500);
    echo json_encode(['error' => 'Database error while saving file metadata.']);
    exit();
}

$fileId = $stmt->insert_id;
$stmt->close();

logActivity((int) $user['id'], 'upload_file', 'files', $fileId, null, ['folder_id' => $folderId, 'file' => $originalName]);

echo json_encode([
    'success' => true,
    'file_id' => $fileId,
]);
?>
