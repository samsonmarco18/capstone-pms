<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project-folder-context.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
$folderId = isset($_GET['folder_id']) ? (int) $_GET['folder_id'] : 0;

if ($projectId <= 0 && $folderId > 0) {
    $folderStmt = $conn->prepare('SELECT * FROM folders WHERE id = ? LIMIT 1');
    $folderStmt->bind_param('i', $folderId);
    $folderStmt->execute();
    $folder = $folderStmt->get_result()->fetch_assoc();
    $folderStmt->close();

    if ($folder) {
        $projectId = resolveFolderProjectId($conn, $folder);
    }
}

$projectContext = null;
if ($projectId > 0) {
    $projectContext = fetchProjectContext($conn, $projectId, $user);
}

echo json_encode([
    'project_context' => $projectContext,
    'project_details' => $projectContext,
    'project_id' => $projectId > 0 ? $projectId : null,
]);
