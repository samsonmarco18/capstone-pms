<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$conn = getConnection();
$user = getCurrentUser();
$roleFilter = trim((string) ($_GET['role'] ?? $user['role']));

function ensureClientRootFolders(mysqli|PgsqlMysqliCompatConnection $conn, int $createdBy): void
{
    $clientResult = $conn->query('SELECT id, name FROM clients ORDER BY name ASC');
    if (!$clientResult) {
        return;
    }

    $checkStmt = $conn->prepare(
        'SELECT id, name
         FROM folders
         WHERE parent_id IS NULL AND client_id = ? AND folder_type = \'client\'
         LIMIT 1'
    );
    $insertStmt = $conn->prepare(
        'INSERT INTO folders (parent_id, name, folder_type, client_id, project_id, role, user_id, created_by)
         VALUES (NULL, ?, \'client\', ?, NULL, NULL, NULL, ?)'
    );
    $updateStmt = $conn->prepare('UPDATE folders SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    if ($checkStmt === false || $insertStmt === false || $updateStmt === false) {
        error_log('Folder client root auto-create prepare failed: ' . ($conn->error ?? 'unknown error'));
        return;
    }

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

function ensureProjectSharedFolders(mysqli|PgsqlMysqliCompatConnection $conn, int $createdBy): void
{
    $projectResult = $conn->query(
        'SELECT p.id, p.name, p.client_id, p.created_by
         FROM projects p
         ORDER BY p.name ASC'
    );
    if (!$projectResult) {
        return;
    }

    $clientFolderStmt = $conn->prepare(
        'SELECT id
         FROM folders
         WHERE parent_id IS NULL AND client_id = ? AND folder_type = \'client\'
         LIMIT 1'
    );
    $existingSharedStmt = $conn->prepare(
        'SELECT id, name, parent_id, role
         FROM folders
         WHERE project_id = ? AND folder_type = \'project\'
         LIMIT 1'
    );
    $insertSharedStmt = $conn->prepare(
        'INSERT INTO folders (parent_id, name, folder_type, client_id, project_id, role, user_id, created_by)
         VALUES (?, ?, \'project\', ?, ?, NULL, NULL, ?)'
    );
    $updateSharedStmt = $conn->prepare('UPDATE folders SET name = ?, parent_id = ?, role = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    if (
        $clientFolderStmt === false
        || $existingSharedStmt === false
        || $insertSharedStmt === false
        || $updateSharedStmt === false
    ) {
        error_log('Shared project folder auto-create prepare failed: ' . ($conn->error ?? 'unknown error'));
        return;
    }

    while ($project = $projectResult->fetch_assoc()) {
        $projectId = (int) $project['id'];
        $projectName = trim((string) $project['name']);
        $clientId = (int) $project['client_id'];
        $folderName = 'Shared - ' . $projectName;

        $clientFolderStmt->bind_param('i', $clientId);
        $clientFolderStmt->execute();
        $clientFolder = $clientFolderStmt->get_result()->fetch_assoc();
        if (!$clientFolder) {
            continue;
        }

        $clientFolderId = (int) $clientFolder['id'];
        $existingSharedStmt->bind_param('i', $projectId);
        $existingSharedStmt->execute();
        $existingShared = $existingSharedStmt->get_result()->fetch_assoc();

        if ($existingShared) {
            $sharedFolderId = (int) $existingShared['id'];
            if (
                (string) $existingShared['name'] !== $folderName
                || (int) ($existingShared['parent_id'] ?? 0) !== $clientFolderId
                || $existingShared['role'] !== null
            ) {
                $updateSharedStmt->bind_param('sii', $folderName, $clientFolderId, $sharedFolderId);
                $updateSharedStmt->execute();
            }
            continue;
        }

        $ownerId = (int) ($project['created_by'] ?? $createdBy);
        if ($ownerId <= 0) {
            $ownerId = $createdBy;
        }
        $insertSharedStmt->bind_param('isiii', $clientFolderId, $folderName, $clientId, $projectId, $ownerId);
        $insertSharedStmt->execute();
    }

    $clientFolderStmt->close();
    $existingSharedStmt->close();
    $insertSharedStmt->close();
    $updateSharedStmt->close();
}

function getFolderTree(mysqli|PgsqlMysqliCompatConnection $conn, int $parentId, ?string $roleFilter = null): array
{
    $sql = '
        SELECT f.*,
               u.full_name AS owner_name,
               (SELECT COUNT(*) FROM folders child WHERE child.parent_id = f.id) AS child_count
        FROM folders f
        LEFT JOIN users u ON u.id = f.created_by
        WHERE ' . ($parentId === 0 ? 'f.parent_id IS NULL AND f.folder_type = \'client\'' : 'f.parent_id = ?');

    if ($roleFilter !== '') {
        $sql .= ' AND (f.role IS NULL OR f.role = ?)';
    }

    $sql .= ' ORDER BY f.name ASC';
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare folder tree query: ' . ($conn->error ?? 'unknown error'));
    }

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

function getSharedProjectFolders(mysqli|PgsqlMysqliCompatConnection $conn, ?string $roleFilter = null): array
{
    $sql = '
        SELECT f.*,
               u.full_name AS owner_name,
               c.name AS client_name,
               (SELECT COUNT(*) FROM folders child WHERE child.parent_id = f.id) AS child_count
        FROM folders f
        LEFT JOIN users u ON u.id = f.created_by
        LEFT JOIN clients c ON c.id = f.client_id
        WHERE f.folder_type = \'project\' AND f.project_id IS NOT NULL';

    if ($roleFilter !== '') {
        $sql .= ' AND (f.role IS NULL OR f.role = ?)';
    }

    $sql .= ' ORDER BY f.name ASC';

    if ($roleFilter !== '') {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare shared project folder query: ' . ($conn->error ?? 'unknown error'));
        }
        $stmt->bind_param('s', $roleFilter);
    } else {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare shared project folder query: ' . ($conn->error ?? 'unknown error'));
        }
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $folders = [];

    while ($row = $result->fetch_assoc()) {
        $row['children'] = [];
        $folders[] = $row;
    }

    $stmt->close();

    return $folders;
}

try {
    ensureClientRootFolders($conn, (int) $user['id']);
    ensureProjectSharedFolders($conn, (int) $user['id']);
    $folders = getSharedProjectFolders($conn, $roleFilter);

    $recentStmt = $conn->prepare(
        'SELECT f.*, u.full_name AS owner_name
         FROM files f
         LEFT JOIN users u ON u.id = f.uploaded_by
         ORDER BY f.created_at DESC
         LIMIT 10'
    );

    if ($recentStmt === false) {
        throw new RuntimeException('Failed to prepare recent files query: ' . ($conn->error ?? 'unknown error'));
    }

    $recentStmt->execute();
    $recentFiles = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentStmt->close();

    echo json_encode([
        'folders' => $folders,
        'recent_files' => $recentFiles,
        'user_role' => $user['role'],
    ]);
} catch (Throwable $e) {
    error_log('Folder tree API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to load folders right now.',
    ]);
}
?>
