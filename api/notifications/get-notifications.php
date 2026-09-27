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

function notificationApiResolveActionUrl(?string $actionUrl, array $notification = []): string
{
    $url = trim((string) $actionUrl);
    if ($url === '') {
        return BASE_URL . 'notifications.php';
    }

    $resolvedUrl = preg_match('#^https?://#i', $url)
        ? $url
        : BASE_URL . ltrim($url, '/');

    $text = strtolower((string) ($notification['title'] ?? '') . ' ' . (string) ($notification['message'] ?? ''));
    $isSulReviewNotification = str_contains($text, 'for review')
        || str_contains($text, 'sul review')
        || str_contains($text, 'test results submitted')
        || str_contains($text, 'design submitted');

    if ($isSulReviewNotification && str_contains($resolvedUrl, 'modules/sul/task-allocation.php') && !str_contains($resolvedUrl, '#')) {
        $resolvedUrl .= '#developer-review-panel';
    }

    return $resolvedUrl;
}

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
    $row['action_url'] = notificationApiResolveActionUrl($row['action_url'] ?? '', $row);
    unset($row['age_minutes']);
    $notifications[] = $row;
}

$stmt->close();

echo json_encode([
    'notifications' => $notifications,
    'unread_count' => getUnreadNotificationsCount((int) $user['id']),
]);
?>
