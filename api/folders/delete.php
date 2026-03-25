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
$folderId = (int) ($_POST['id'] ?? 0);

if ($folderId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid folder.']);
    exit();
}

$stmt = $conn->prepare('SELECT * FROM folders WHERE id = ?');
$stmt->bind_param('i', $folderId);
$stmt->execute();
$folder = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$folder) {
    http_response_code(404);
    echo json_encode(['error' => 'Folder not found.']);
    exit();
}

$deleteStmt = $conn->prepare('DELETE FROM folders WHERE id = ?');
$deleteStmt->bind_param('i', $folderId);
$deleteStmt->execute();
$deleteStmt->close();

logActivity((int) $user['id'], 'delete_folder', 'folders', $folderId, $folder, null);

echo json_encode(['success' => true]);
?>
