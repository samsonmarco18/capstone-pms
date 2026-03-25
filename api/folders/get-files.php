<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$user = getCurrentUser();
$conn = getConnection();

$folderId = isset($_GET['folder_id']) ? $_GET['folder_id'] : null;
$clientId = isset($_GET['client_id']) ? $_GET['client_id'] : null;

if ($folderId) {
    // Get folder contents
    $stmt = $conn->prepare("
        SELECT * FROM folders 
        WHERE parent_id = ? 
        ORDER BY name
    ");
    $stmt->bind_param("i", $folderId);
    $stmt->execute();
    $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get files in folder
    $stmt = $conn->prepare("
        SELECT * FROM files 
        WHERE folder_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $folderId);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'folders' => $folders,
        'files' => $files
    ]);
} elseif ($clientId) {
    // Get client folders structure
    $stmt = $conn->prepare("
        SELECT * FROM folders 
        WHERE client_id = ? AND parent_id IS NULL
        ORDER BY name
    ");
    $stmt->bind_param("i", $clientId);
    $stmt->execute();
    $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['folders' => $folders]);
}
?>