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

if ($folderId <= 0 || !isset($_FILES['files'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid upload request.']);
    exit();
}

$uploadRoot = dirname(__DIR__, 2) . '/uploads';
$dailyDirectory = $uploadRoot . '/' . date('Y/m/d');
if (!is_dir($dailyDirectory)) {
    mkdir($dailyDirectory, 0777, true);
}

$createdIds = [];
foreach ($_FILES['files']['name'] as $index => $originalName) {
    if ($_FILES['files']['error'][$index] !== UPLOAD_ERR_OK) {
        continue;
    }

    $cleanOriginalName = basename($originalName);
    $storedName = uniqid('file_', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $cleanOriginalName);
    $absolutePath = $dailyDirectory . '/' . $storedName;
    $relativePath = 'uploads/' . date('Y/m/d') . '/' . $storedName;

    if (!move_uploaded_file($_FILES['files']['tmp_name'][$index], $absolutePath)) {
        continue;
    }

    $extension = pathinfo($cleanOriginalName, PATHINFO_EXTENSION);
    $stmt = $conn->prepare(
        'INSERT INTO files (folder_id, original_file_name, stored_file_name, file_type, file_size, file_path, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $size = (int) $_FILES['files']['size'][$index];
    $stmt->bind_param('isssisi', $folderId, $cleanOriginalName, $storedName, $extension, $size, $relativePath, $user['id']);
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
