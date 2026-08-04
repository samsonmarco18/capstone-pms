<?php
declare(strict_types=1);

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
$userId = (int) ($user['id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$messageId = (int) ($data['message_id'] ?? 0);
$action = strtolower(trim((string) ($data['action'] ?? '')));

if ($userId <= 0 || $messageId <= 0 || !in_array($action, ['edit', 'unsend', 'forward'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid message action.']);
    exit();
}

$conn = getConnection();
if (!ensureChatMessageActions($conn)) {
    http_response_code(500);
    echo json_encode(['error' => 'Message actions are unavailable.']);
    exit();
}

$message = getAccessibleChatMessage($conn, $messageId, $userId);
if (!$message || (string) ($message['message_type'] ?? 'text') === 'notification') {
    http_response_code(404);
    echo json_encode(['error' => 'Message not found.']);
    exit();
}

if ($action === 'edit') {
    $updatedMessage = trim((string) ($data['message'] ?? ''));
    if ((int) ($message['sender_id'] ?? 0) !== $userId || !empty($message['deleted_at'])) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only edit your own active messages.']);
        exit();
    }
    if ((int) ($message['forwarded_from_message_id'] ?? 0) > 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Forwarded messages cannot be edited.']);
        exit();
    }
    if ($updatedMessage === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Message cannot be empty.']);
        exit();
    }

    $stmt = $conn->prepare(
        'UPDATE messages
         SET message = ?, edited_at = CURRENT_TIMESTAMP
         WHERE id = ? AND sender_id = ? AND deleted_at IS NULL
           AND forwarded_from_message_id IS NULL'
    );
    $stmt->bind_param('sii', $updatedMessage, $messageId, $userId);
    $success = $stmt->execute();
    $stmt->close();

    if (!$success) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to edit the message.']);
        exit();
    }

    logActivity($userId, 'edit_message', 'messages', $messageId);
    echo json_encode(['success' => true]);
    exit();
}

if ($action === 'unsend') {
    if ((int) ($message['sender_id'] ?? 0) !== $userId || !empty($message['deleted_at'])) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only unsend your own active messages.']);
        exit();
    }

    $emptyMessage = '';
    $stmt = $conn->prepare(
        'UPDATE messages
         SET message = ?, deleted_at = CURRENT_TIMESTAMP
         WHERE id = ? AND sender_id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('sii', $emptyMessage, $messageId, $userId);
    $success = $stmt->execute();
    $stmt->close();

    if (!$success) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to unsend the message.']);
        exit();
    }

    logActivity($userId, 'unsend_message', 'messages', $messageId);
    echo json_encode(['success' => true]);
    exit();
}

if (!empty($message['deleted_at'])) {
    http_response_code(400);
    echo json_encode(['error' => 'This message is no longer available.']);
    exit();
}

$targetType = strtolower(trim((string) ($data['target_type'] ?? '')));
$targetId = (int) ($data['target_id'] ?? 0);
if (!in_array($targetType, ['user', 'group'], true) || $targetId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a conversation to forward this message.']);
    exit();
}

$messageText = (string) ($message['message'] ?? '');
$forwardedFromMessageId = $messageId;

if ($targetType === 'group') {
    $memberStmt = $conn->prepare(
        'SELECT id
         FROM chat_group_members
         WHERE group_id = ? AND user_id = ? AND left_at IS NULL
         LIMIT 1'
    );
    $memberStmt->bind_param('ii', $targetId, $userId);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();
    $isMember = $memberResult && $memberResult->num_rows > 0;
    $memberStmt->close();

    if (!$isMember) {
        http_response_code(403);
        echo json_encode(['error' => 'You are not a member of that group.']);
        exit();
    }

    $stmt = $conn->prepare(
        'INSERT INTO messages (sender_id, group_id, message, forwarded_from_message_id)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('iisi', $userId, $targetId, $messageText, $forwardedFromMessageId);
} else {
    if ($targetId === $userId) {
        http_response_code(400);
        echo json_encode(['error' => 'Select another user.']);
        exit();
    }

    $activeStatus = 'active';
    $userStmt = $conn->prepare('SELECT id FROM users WHERE id = ? AND status = ? LIMIT 1');
    $userStmt->bind_param('is', $targetId, $activeStatus);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $targetExists = $userResult && $userResult->num_rows > 0;
    $userStmt->close();

    if (!$targetExists) {
        http_response_code(404);
        echo json_encode(['error' => 'The selected user is unavailable.']);
        exit();
    }

    $stmt = $conn->prepare(
        'INSERT INTO messages (sender_id, receiver_id, message, forwarded_from_message_id)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('iisi', $userId, $targetId, $messageText, $forwardedFromMessageId);
}

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['error' => 'Unable to forward the message.']);
    exit();
}

$newMessageId = (int) $stmt->insert_id;
$stmt->close();

logActivity($userId, 'forward_message', 'messages', $newMessageId, null, [
    'source_message_id' => $messageId,
    'target_type' => $targetType,
    'target_id' => $targetId,
]);

echo json_encode([
    'success' => true,
    'message_id' => $newMessageId,
]);
?>
