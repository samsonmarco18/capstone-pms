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
$fileIds = array_map('intval', $_POST['file_ids'] ?? []);

if (!$fileIds) {
    http_response_code(400);
    echo json_encode(['error' => 'No files selected.']);
    exit();
}

$placeholders = implode(',', array_fill(0, count($fileIds), '?'));
$types = str_repeat('i', count($fileIds));
$stmt = $conn->prepare("SELECT * FROM files WHERE id IN ($placeholders)");
$stmt->bind_param($types, ...$fileIds);
$stmt->execute();
$files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($files as $file) {
    $absolutePath = storageResolveRelativePath((string) $file['file_path']);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

$deleteStmt = $conn->prepare("DELETE FROM files WHERE id IN ($placeholders)");
$deleteStmt->bind_param($types, ...$fileIds);
$deleteStmt->execute();
$deleteStmt->close();

logActivity((int) $user['id'], 'bulk_delete_files', 'files', null, $files, ['file_ids' => $fileIds]);

echo json_encode(['success' => true]);
?>
