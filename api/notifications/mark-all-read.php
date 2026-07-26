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

$conn = getConnection();
$stmt = $conn->prepare(
    "UPDATE notifications
     SET is_read = TRUE,
         read_at = NOW()
     WHERE user_id = ?
       AND is_read = FALSE
       AND (notification_type IS NULL OR notification_type NOT IN ('message', 'group'))"
);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare notification update']);
    exit();
}

$stmt->bind_param('i', $user['id']);

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to mark notifications as read']);
    exit();
}

$updated = (int) ($conn->affected_rows ?? 0);
$stmt->close();

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'unread_count' => getUnreadNotificationsCount((int) $user['id']),
]);
?>
