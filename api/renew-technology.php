<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

$user = getCurrentUser();
$technologyId = (int) ($_POST['id'] ?? 0);

if ($technologyId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid technology ID']);
    exit();
}

$conn = getConnection();
$oldStmt = $conn->prepare('SELECT * FROM technologies WHERE id = ?');
$oldStmt->bind_param('i', $technologyId);
$oldStmt->execute();
$oldData = $oldStmt->get_result()->fetch_assoc();
$oldStmt->close();

if (!$oldData) {
    http_response_code(404);
    echo json_encode(['error' => 'Technology not found']);
    exit();
}

$updateStmt = $conn->prepare(
    "UPDATE technologies
     SET expiry_date = DATE_ADD(COALESCE(expiry_date, CURDATE()), INTERVAL 1 YEAR),
         status = 'active',
         updated_at = NOW()
     WHERE id = ?"
);
$updateStmt->bind_param('i', $technologyId);
$updateStmt->execute();
$updateStmt->close();

logActivity((int) $user['id'], 'renew_technology', 'technologies', $technologyId, $oldData, ['status' => 'active']);

echo json_encode(['success' => true]);
?>
