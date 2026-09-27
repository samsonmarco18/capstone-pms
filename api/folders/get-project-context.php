<?php
require_once __DIR__ . '/../../includes/folder-api.php';
require_once __DIR__ . '/../../includes/project-folder-context.php';
folderApiInitialize();

$user = folderApiRequireUser();
$conn = getConnection();
$projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
$folderId = isset($_GET['folder_id']) ? (int) $_GET['folder_id'] : 0;

try {
    if ($projectId <= 0 && $folderId > 0) {
        $folderStmt = $conn->prepare('SELECT * FROM folders WHERE id = ? LIMIT 1');
        if ($folderStmt === false) {
            throw new RuntimeException('Failed to prepare folder lookup: ' . ($conn->error ?? 'unknown error'));
        }

        $folderStmt->bind_param('i', $folderId);
        $folderStmt->execute();
        $folder = $folderStmt->get_result()->fetch_assoc();
        $folderStmt->close();

        if ($folder) {
            if (!folderApiCanAccessFolderWithProject($conn, $folder, $user)) {
                folderApiRespond(['success' => false, 'error' => 'You do not have access to this project folder.'], 403);
            }
            $projectId = resolveFolderProjectId($conn, $folder);
        }
    }

    $projectContext = null;
    if ($projectId > 0) {
        if (!folderApiCanAccessProject($conn, $projectId, $user)) {
            folderApiRespond(['success' => false, 'error' => 'You do not have access to this project.'], 403);
        }
        $projectContext = fetchProjectContext($conn, $projectId, $user);
    }

    folderApiRespond([
        'success' => true,
        'project_context' => $projectContext,
        'project_details' => $projectContext,
        'project_id' => $projectId > 0 ? $projectId : null,
    ]);
} catch (Throwable $e) {
    folderApiError($e, 'Folder project context API failed', 'Unable to load project details right now.');
}
