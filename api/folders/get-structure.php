<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();
$user = getCurrentUser();
$conn = getConnection();

$folderId = intval($_GET['folder_id'] ?? 0);
$roleFilter = $_GET['role'] ?? $user['role'];

// Build hierarchical tree structure
function getFolderTree($conn, $folderId, $userId, $role = null, $maxDepth = 5, $currentDepth = 0) {
    if ($currentDepth > $maxDepth) return [];
    
    $sql = "
        SELECT f.*, u.full_name as owner_name,
               (SELECT COUNT(*) FROM files WHERE folder_id = f.id) as file_count,
               (SELECT COUNT(*) FROM folders WHERE parent_id = f.id) as subfolder_count
        FROM folders f
        LEFT JOIN users u ON f.created_by = u.id
        WHERE f.parent_id = ? AND f.folder_type != 'deleted'
    ";
    
    if ($role) {
        $sql .= " AND (f.role = ? OR f.role IS NULL)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $folderId, $role);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $folderId);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $folders = [];
    while ($row = $result->fetch_assoc()) {
        $row['children'] = [];
        
        // Recursively get children
        if ($row['subfolder_count'] > 0 && $currentDepth < $maxDepth) {
            $row['children'] = getFolderTree($conn, $row['id'], $userId, $role, $maxDepth, $currentDepth + 1);
        }
        
        $folders[] = $row;
    }
    
    return $folders;
}

// Get root folders (clients)
$rootFolders = getFolderTree($conn, 0, $user['id'], $roleFilter, 3);

// Also get recent files for quick access
$recentFilesSql = "
    SELECT f.*, folder.name as folder_name, u.full_name as owner_name
    FROM files f
    JOIN folders folder ON f.folder_id = folder.id
    LEFT JOIN users u ON f.uploaded_by = u.id
    WHERE f.uploaded_by = ? OR folder.created_by = ?
    ORDER BY f.created_at DESC 
    LIMIT 10
";
$stmt = $conn->prepare($recentFilesSql);
$stmt->bind_param("ii", $user['id'], $user['id']);
$stmt->execute();
$recentFilesResult = $stmt->get_result();

echo json_encode([
    'folders' => $rootFolders,
    'recent_files' => $recentFilesResult->fetch_all(MYSQLI_ASSOC),
    'user_role' => $user['role']
]);
?>

