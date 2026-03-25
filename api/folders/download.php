<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$conn = getConnection();
$fileId = (int) ($_GET['id'] ?? 0);

if ($fileId <= 0) {
    http_response_code(400);
    exit('Invalid file.');
}

$stmt = $conn->prepare('SELECT * FROM files WHERE id = ?');
$stmt->bind_param('i', $fileId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

$absolutePath = dirname(__DIR__, 2) . '/' . ltrim($file['file_path'], '/');
if (!is_file($absolutePath)) {
    http_response_code(404);
    exit('Stored file not found.');
}

$incrementStmt = $conn->prepare('UPDATE files SET downloaded_count = downloaded_count + 1 WHERE id = ?');
$incrementStmt->bind_param('i', $fileId);
$incrementStmt->execute();
$incrementStmt->close();

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($file['original_file_name']) . '"');
header('Content-Length: ' . filesize($absolutePath));
readfile($absolutePath);
exit();
?>
