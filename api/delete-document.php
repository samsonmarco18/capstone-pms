<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
requireRole('architect');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$conn = getConnection();
$user = getCurrentUser();
$documentId = (int) ($_POST['id'] ?? 0);

if ($documentId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid document id']);
    exit;
}

$stmt = $conn->prepare("
    SELECT ad.id, ad.project_id, ad.file_path, p.architect_id
    FROM architecture_documents ad
    INNER JOIN projects p ON p.id = ad.project_id
    WHERE ad.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $documentId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$document) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Document not found']);
    exit;
}

if ((int) ($document['architect_id'] ?? 0) !== (int) $user['id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to delete this document']);
    exit;
}

$deleteStmt = $conn->prepare("DELETE FROM architecture_documents WHERE id = ?");
$deleteStmt->bind_param('i', $documentId);
$deleted = $deleteStmt->execute();
$deleteStmt->close();

if (!$deleted) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete document']);
    exit;
}

$relativePath = trim((string) ($document['file_path'] ?? ''));
if ($relativePath !== '') {
    $absolutePath = realpath(__DIR__ . '/../');
    if ($absolutePath !== false) {
        $normalizedPath = $absolutePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($normalizedPath)) {
            @unlink($normalizedPath);
        }
    }
}

logActivity((int) $user['id'], 'delete_architecture_document', 'architecture_documents', $documentId);

echo json_encode(['success' => true]);
?>
