<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$notificationId = (int) ($_POST['id'] ?? 0);

if ($notificationId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid notification ID']);
    exit();
}

$conn = getConnection();

$stmt = $conn->prepare('UPDATE notifications SET is_read = TRUE, read_at = NOW() WHERE id = ? AND user_id = ?');
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare notification update']);
    exit();
}

$stmt->bind_param("ii", $notificationId, $user['id']);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'updated' => (int) ($conn->affected_rows ?? 0),
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to mark as read']);
}

$stmt->close();
?>
