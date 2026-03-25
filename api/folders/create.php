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
$name = trim((string) ($_POST['name'] ?? ''));
$parentId = (int) ($_POST['parent_id'] ?? 0);

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Folder name is required.']);
    exit();
}

$parentFolder = null;
if ($parentId > 0) {
    $parentStmt = $conn->prepare('SELECT client_id, project_id, role FROM folders WHERE id = ?');
    $parentStmt->bind_param('i', $parentId);
    $parentStmt->execute();
    $parentFolder = $parentStmt->get_result()->fetch_assoc();
    $parentStmt->close();
}

$clientId = $parentFolder['client_id'] ?? null;
$projectId = $parentFolder['project_id'] ?? null;
$role = $parentFolder['role'] ?? $user['role'];
$folderType = $parentId > 0 ? 'general' : 'project';
$parentIdValue = $parentId > 0 ? $parentId : null;
if ($parentIdValue === null) {
    $stmt = $conn->prepare(
        'INSERT INTO folders (parent_id, name, folder_type, client_id, project_id, role, user_id, created_by)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssiissi', $name, $folderType, $clientId, $projectId, $role, $user['id'], $user['id']);
} else {
    $stmt = $conn->prepare(
        'INSERT INTO folders (parent_id, name, folder_type, client_id, project_id, role, user_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issiissi', $parentIdValue, $name, $folderType, $clientId, $projectId, $role, $user['id'], $user['id']);
}
$stmt->execute();
$folderId = $stmt->insert_id;
$stmt->close();

logActivity((int) $user['id'], 'create_folder', 'folders', $folderId, null, ['name' => $name, 'parent_id' => $parentId]);

echo json_encode([
    'success' => true,
    'folder_id' => $folderId,
]);
?>
