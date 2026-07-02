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

$stmt = $conn->prepare(
    "SELECT *
     FROM notifications
     WHERE user_id = ?
       AND (notification_type IS NULL OR notification_type NOT IN ('message', 'group'))
     ORDER BY is_read ASC, created_at DESC
     LIMIT 20"
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $createdAt = (string) ($row['created_at'] ?? '');
    $createdTimestamp = strtotime($createdAt);
    $row['time_ago'] = formatTimeAgo($createdAt);
    $row['formatted_created_at'] = $createdTimestamp !== false
        ? date('M d, Y h:i A', $createdTimestamp)
        : $createdAt;
    $notifications[] = $row;
}

$stmt->close();

echo json_encode([
    'notifications' => $notifications,
    'unread_count' => getUnreadNotificationsCount((int) $user['id']),
]);
?>
