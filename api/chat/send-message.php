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
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$message = trim((string) ($data['message'] ?? ''));
$receiverId = isset($data['receiver_id']) ? (int) $data['receiver_id'] : 0;
$groupId = isset($data['group_id']) ? (int) $data['group_id'] : 0;

if ($message === '' || ($receiverId <= 0 && $groupId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message and recipient are required.']);
    exit();
}

$conn = getConnection();

if ($groupId > 0) {
    $stmt = $conn->prepare('INSERT INTO messages (sender_id, group_id, message) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $user['id'], $groupId, $message);
} else {
    $stmt = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $user['id'], $receiverId, $message);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message.']);
    exit();
}

$messageId = $stmt->insert_id;
$stmt->close();

if ($receiverId > 0) {
    createNotification(
        $receiverId,
        'New message from ' . $user['full_name'],
        $message,
        'message',
        'messages',
        $messageId,
        BASE_URL . 'profile.php'
    );
}

logActivity((int) $user['id'], 'send_message', 'messages', $messageId, null, [
    'receiver_id' => $receiverId,
    'group_id' => $groupId,
]);

echo json_encode([
    'success' => true,
    'message_id' => $messageId,
]);
?>
