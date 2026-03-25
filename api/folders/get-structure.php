<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$conn = getConnection();
$user = getCurrentUser();
$roleFilter = trim((string) ($_GET['role'] ?? $user['role']));

function ensureClientRootFolders(mysqli $conn, int $createdBy): void
{
    $clientResult = $conn->query('SELECT id, name FROM clients ORDER BY name ASC');
    if (!$clientResult) {
        return;
    }

    $checkStmt = $conn->prepare(
        'SELECT id, name
         FROM folders
         WHERE parent_id IS NULL AND client_id = ? AND folder_type = "client"
         LIMIT 1'
    );
    $insertStmt = $conn->prepare(
        'INSERT INTO folders (parent_id, name, folder_type, client_id, project_id, role, user_id, created_by)
         VALUES (NULL, ?, "client", ?, NULL, NULL, NULL, ?)'
    );
    $updateStmt = $conn->prepare('UPDATE folders SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');

    while ($client = $clientResult->fetch_assoc()) {
        $clientId = (int) $client['id'];
        $clientName = trim((string) $client['name']);

        $checkStmt->bind_param('i', $clientId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();

        if ($existing) {
            if ((string) $existing['name'] !== $clientName) {
                $folderId = (int) $existing['id'];
                $updateStmt->bind_param('si', $clientName, $folderId);
                $updateStmt->execute();
            }
            continue;
        }

        $insertStmt->bind_param('sii', $clientName, $clientId, $createdBy);
        $insertStmt->execute();
    }

    $checkStmt->close();
    $insertStmt->close();
    $updateStmt->close();
}

function getFolderTree(mysqli $conn, int $parentId, ?string $roleFilter = null): array
{
    $sql = '
        SELECT f.*,
               u.full_name AS owner_name,
               (SELECT COUNT(*) FROM folders child WHERE child.parent_id = f.id) AS child_count
        FROM folders f
        LEFT JOIN users u ON u.id = f.created_by
        WHERE ' . ($parentId === 0 ? 'f.parent_id IS NULL' : 'f.parent_id = ?');

    if ($roleFilter !== '') {
        $sql .= ' AND (f.role IS NULL OR f.role = ?)';
    }

    $sql .= ' ORDER BY f.name ASC';
    $stmt = $conn->prepare($sql);

    if ($parentId === 0 && $roleFilter !== '') {
        $stmt->bind_param('s', $roleFilter);
    } elseif ($parentId === 0) {
        // no params
    } elseif ($roleFilter !== '') {
        $stmt->bind_param('is', $parentId, $roleFilter);
    } else {
        $stmt->bind_param('i', $parentId);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $folders = [];

    while ($row = $result->fetch_assoc()) {
        $row['children'] = getFolderTree($conn, (int) $row['id'], $roleFilter);
        $folders[] = $row;
    }

    $stmt->close();

    return $folders;
}

ensureClientRootFolders($conn, (int) $user['id']);
$folders = getFolderTree($conn, 0, $roleFilter);

$recentStmt = $conn->prepare(
    'SELECT f.*, u.full_name AS owner_name
     FROM files f
     LEFT JOIN users u ON u.id = f.uploaded_by
     ORDER BY f.created_at DESC
     LIMIT 10'
);
$recentStmt->execute();
$recentFiles = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recentStmt->close();

echo json_encode([
    'folders' => $folders,
    'recent_files' => $recentFiles,
    'user_role' => $user['role'],
]);
?>
