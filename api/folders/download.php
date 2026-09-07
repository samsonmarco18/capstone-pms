<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/folder-api.php';
require_once __DIR__ . '/../../includes/file-storage.php';

requireLogin();

$conn = getConnection();
$user = getCurrentUser();
$fileId = (int) ($_GET['id'] ?? 0);

if ($fileId <= 0) {
    http_response_code(400);
    exit('Invalid file.');
}

$stmt = $conn->prepare('SELECT f.*, fo.role AS folder_role, fo.user_id AS folder_user_id, fo.project_id AS folder_project_id FROM files f JOIN folders fo ON fo.id = f.folder_id WHERE f.id = ?');
$stmt->bind_param('i', $fileId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

$absolutePath = storageResolveRelativePath((string) $file['file_path']);
if ($absolutePath === null || !is_file($absolutePath)) {
    http_response_code(404);
    exit('Stored file not found.');
}

if (!$user || !folderApiCanAccessFolderWithProject($conn, ['role' => $file['folder_role'] ?? null, 'user_id' => $file['folder_user_id'] ?? null, 'project_id' => $file['folder_project_id'] ?? null], $user)) {
    http_response_code(403);
    exit('Access denied.');
}

$incrementStmt = $conn->prepare('UPDATE files SET downloaded_count = downloaded_count + 1 WHERE id = ?');
$incrementStmt->bind_param('i', $fileId);
$incrementStmt->execute();
$incrementStmt->close();

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
$downloadName = str_replace(["\r", "\n", '"'], '', basename((string) $file['original_file_name']));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($absolutePath));
readfile($absolutePath);
exit();
?>
