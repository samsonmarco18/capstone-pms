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
$fileId = (int) ($_POST['id'] ?? 0);

if ($fileId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file.']);
    exit();
}

$stmt = $conn->prepare('SELECT * FROM files WHERE id = ?');
$stmt->bind_param('i', $fileId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found.']);
    exit();
}

$absolutePath = dirname(__DIR__, 2) . '/' . ltrim($file['file_path'], '/');
if (is_file($absolutePath)) {
    @unlink($absolutePath);
}

$deleteStmt = $conn->prepare('DELETE FROM files WHERE id = ?');
$deleteStmt->bind_param('i', $fileId);
$deleteStmt->execute();
$deleteStmt->close();

logActivity((int) $user['id'], 'delete_file', 'files', $fileId, $file, null);

echo json_encode(['success' => true]);
?>
