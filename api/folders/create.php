<?php
require_once __DIR__ . '/../../includes/folder-api.php';
folderApiInitialize();

$user = folderApiRequireUser();
$parentId = (int) ($_POST['parent_id'] ?? 0);

try {
    $name = folderApiValidateFolderName((string) ($_POST['name'] ?? ''));
} catch (InvalidArgumentException $e) {
    folderApiRespond(['success' => false, 'error' => $e->getMessage()], 400);
}

if ($parentId <= 0) {
    folderApiRespond(['success' => false, 'error' => 'Open a client or shared project folder before creating a folder.'], 400);
}

try {
    $conn = getConnection();
    $parentStmt = folderApiPrepare($conn, 'SELECT client_id, project_id, role FROM folders WHERE id = ?');
    $parentStmt->bind_param('i', $parentId);
    folderApiExecute($parentStmt, $conn);
    $parentFolder = $parentStmt->get_result()->fetch_assoc();
    $parentStmt->close();
    if (!$parentFolder) {
        folderApiRespond(['success' => false, 'error' => 'Parent folder not found.'], 404);
    }
    if (!folderApiCanAccessFolder($parentFolder, $user)) {
        folderApiRespond(['success' => false, 'error' => 'You do not have access to this folder.'], 403);
    }

    $clientId = isset($parentFolder['client_id']) ? (int) $parentFolder['client_id'] : null;
    $projectId = isset($parentFolder['project_id']) ? (int) $parentFolder['project_id'] : null;
    $role = $parentFolder['role'] ?? $user['role'];
    $folderType = 'general';
    $userId = (int) $user['id'];
    $stmt = folderApiPrepare($conn,
        'INSERT INTO folders (parent_id, name, folder_type, client_id, project_id, role, user_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issiissi', $parentId, $name, $folderType, $clientId, $projectId, $role, $userId, $userId);
    folderApiExecute($stmt, $conn);
    $folderId = $stmt->insert_id;
    $stmt->close();

    logActivity($userId, 'create_folder', 'folders', $folderId, null, ['name' => $name, 'parent_id' => $parentId]);

    folderApiRespond(['success' => true, 'folder_id' => $folderId]);
} catch (Throwable $e) {
    folderApiError($e, 'Create folder API failed', 'Unable to create the folder right now.');
}
?>
