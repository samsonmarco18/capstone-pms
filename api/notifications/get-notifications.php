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
$conn = getConnection();

$stmt = $conn->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
$unreadCount = 0;
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
    if (!(bool) $row['is_read']) {
        $unreadCount++;
    }
}

$stmt->close();

echo json_encode([
    'notifications' => $notifications,
    'unread_count' => $unreadCount,
]);
?>
