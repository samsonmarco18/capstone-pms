<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/chat.php';

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
$replyToMessageId = isset($data['reply_to_message_id']) ? (int) $data['reply_to_message_id'] : 0;

if ($message === '' || ($receiverId <= 0 && $groupId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message and recipient are required.']);
    exit();
}

$conn = getConnection();

if (!ensureChatMessageActions($conn)) {
    http_response_code(500);
    echo json_encode(['error' => 'Message actions are unavailable.']);
    exit();
}

if ($groupId > 0) {
    $memberStmt = $conn->prepare('SELECT id FROM chat_group_members WHERE group_id = ? AND user_id = ? AND left_at IS NULL LIMIT 1');
    $memberStmt->bind_param('ii', $groupId, $user['id']);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();
    $isMember = $memberResult && $memberResult->num_rows > 0;
    $memberStmt->close();

    if (!$isMember) {
        http_response_code(403);
        echo json_encode(['error' => 'You are not a member of this group.']);
        exit();
    }

    if ($replyToMessageId > 0) {
        $replyMessage = getAccessibleChatMessage($conn, $replyToMessageId, (int) $user['id']);
        if (
            !$replyMessage
            || !empty($replyMessage['deleted_at'])
            || (int) ($replyMessage['group_id'] ?? 0) !== $groupId
        ) {
            http_response_code(400);
            echo json_encode(['error' => 'The selected reply message is unavailable.']);
            exit();
        }
    }

    $replyToValue = $replyToMessageId > 0 ? $replyToMessageId : null;
    $stmt = $conn->prepare(
        'INSERT INTO messages (sender_id, group_id, message, reply_to_message_id)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('iisi', $user['id'], $groupId, $message, $replyToValue);
} else {
    if ($replyToMessageId > 0) {
        $replyMessage = getAccessibleChatMessage($conn, $replyToMessageId, (int) $user['id']);
        $replyParticipants = [
            (int) ($replyMessage['sender_id'] ?? 0),
            (int) ($replyMessage['receiver_id'] ?? 0),
        ];
        sort($replyParticipants);
        $currentParticipants = [(int) $user['id'], $receiverId];
        sort($currentParticipants);

        if (
            !$replyMessage
            || !empty($replyMessage['deleted_at'])
            || (int) ($replyMessage['group_id'] ?? 0) > 0
            || $replyParticipants !== $currentParticipants
        ) {
            http_response_code(400);
            echo json_encode(['error' => 'The selected reply message is unavailable.']);
            exit();
        }
    }

    $replyToValue = $replyToMessageId > 0 ? $replyToMessageId : null;
    $stmt = $conn->prepare(
        'INSERT INTO messages (sender_id, receiver_id, message, reply_to_message_id)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('iisi', $user['id'], $receiverId, $message, $replyToValue);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message.']);
    exit();
}

$messageId = $stmt->insert_id;
$stmt->close();

logActivity((int) $user['id'], 'send_message', 'messages', $messageId, null, [
    'receiver_id' => $receiverId,
    'group_id' => $groupId,
]);

echo json_encode([
    'success' => true,
    'message_id' => $messageId,
]);
?>
