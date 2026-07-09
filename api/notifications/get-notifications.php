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
    "SELECT notifications.*,
            TIMESTAMPDIFF(MINUTE, created_at, CURRENT_TIMESTAMP) AS age_minutes
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
    $ageMinutes = max(0, (int) floor((float) ($row['age_minutes'] ?? 0)));
    $row['time_ago'] = formatMinutesAgo($ageMinutes);
    $row['formatted_created_at'] = date('M d, Y h:i A', time() - ($ageMinutes * 60));
    unset($row['age_minutes']);
    $notifications[] = $row;
}

$stmt->close();

echo json_encode([
    'notifications' => $notifications,
    'unread_count' => getUnreadNotificationsCount((int) $user['id']),
]);
?>
