<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$logId = (int) ($_GET['id'] ?? 0);

if ($logId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Audit log ID required']);
    exit();
}

$stmt = $conn->prepare(
    'SELECT al.*, u.username, u.full_name
     FROM audit_logs al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE al.id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $logId);
$stmt->execute();
$log = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$log) {
    http_response_code(404);
    echo json_encode(['error' => 'Audit log not found']);
    exit();
}

echo json_encode($log);
?>
