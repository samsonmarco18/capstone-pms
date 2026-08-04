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
$conn = getConnection();

if (!ensureChatMessageActions($conn)) {
    http_response_code(500);
    echo json_encode(['error' => 'Message actions are unavailable.']);
    exit();
}

$receiverId = isset($_GET['receiver_id']) ? (int) $_GET['receiver_id'] : 0;
$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 50;

if ($groupId > 0) {
    if (!ensureChatGroupReadTracking($conn)) {
        http_response_code(500);
        echo json_encode(['error' => 'Group message read tracking is unavailable.']);
        exit();
    }

    $memberStmt = $conn->prepare('SELECT id, last_read_message_id FROM chat_group_members WHERE group_id = ? AND user_id = ? AND left_at IS NULL LIMIT 1');
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

    $stmt = $conn->prepare(
        'SELECT m.id, m.sender_id, m.message, m.message_type, m.created_at,
                m.reply_to_message_id, m.forwarded_from_message_id, m.edited_at, m.deleted_at,
                u.full_name AS sender_name, u.profile_pic AS sender_profile_pic,
                rm.message AS reply_message, rm.deleted_at AS reply_deleted_at,
                rm.sender_id AS reply_sender_id, ru.full_name AS reply_sender_name, ru.profile_pic AS reply_sender_profile_pic,
                fm.sender_id AS forwarded_sender_id, fu.full_name AS forwarded_sender_name, fu.profile_pic AS forwarded_sender_profile_pic
         FROM messages m
         INNER JOIN users u ON u.id = m.sender_id
         LEFT JOIN messages rm ON rm.id = m.reply_to_message_id
         LEFT JOIN users ru ON ru.id = rm.sender_id
         LEFT JOIN messages fm ON fm.id = m.forwarded_from_message_id
         LEFT JOIN users fu ON fu.id = fm.sender_id
         WHERE m.group_id = ?
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT ?'
    );
    $stmt->bind_param('ii', $groupId, $limit);
} else {
    $stmt = $conn->prepare(
        'SELECT m.id, m.sender_id, m.message, m.message_type, m.created_at,
                m.reply_to_message_id, m.forwarded_from_message_id, m.edited_at, m.deleted_at,
                u.full_name AS sender_name, u.profile_pic AS sender_profile_pic,
                rm.message AS reply_message, rm.deleted_at AS reply_deleted_at,
                rm.sender_id AS reply_sender_id, ru.full_name AS reply_sender_name, ru.profile_pic AS reply_sender_profile_pic,
                fm.sender_id AS forwarded_sender_id, fu.full_name AS forwarded_sender_name, fu.profile_pic AS forwarded_sender_profile_pic
         FROM messages m
         INNER JOIN users u ON u.id = m.sender_id
         LEFT JOIN messages rm ON rm.id = m.reply_to_message_id
         LEFT JOIN users ru ON ru.id = rm.sender_id
         LEFT JOIN messages fm ON fm.id = m.forwarded_from_message_id
         LEFT JOIN users fu ON fu.id = fm.sender_id
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.created_at ASC
         LIMIT ?'
    );
    $stmt->bind_param('iiiii', $user['id'], $receiverId, $receiverId, $user['id'], $limit);
}

$stmt->execute();
$result = $stmt->get_result();
$messages = [];
$lastVisibleMessageId = 0;

while ($row = $result->fetch_assoc()) {
    $row['is_mine'] = (int) $row['sender_id'] === (int) $user['id'];
    $row['reply_is_mine'] = (int) ($row['reply_sender_id'] ?? 0) === (int) $user['id'];
    $row['forwarded_is_mine'] = (int) ($row['forwarded_sender_id'] ?? 0) === (int) $user['id'];
    $row['sender_profile_url'] = getUserProfileImageUrl($row['sender_profile_pic'] ?? null);
    $row['reply_sender_profile_url'] = getUserProfileImageUrl($row['reply_sender_profile_pic'] ?? null);
    $row['forwarded_sender_profile_url'] = getUserProfileImageUrl($row['forwarded_sender_profile_pic'] ?? null);
    unset($row['sender_profile_pic'], $row['reply_sender_profile_pic'], $row['forwarded_sender_profile_pic']);
    $row['is_deleted'] = !empty($row['deleted_at']);
    if ($row['is_deleted']) {
        $row['message'] = '';
    }
    if (!empty($row['reply_deleted_at'])) {
        $row['reply_message'] = '';
    }
    $lastVisibleMessageId = max($lastVisibleMessageId, (int) ($row['id'] ?? 0));
    $messages[] = $row;
}

$stmt->close();

if ($groupId > 0) {
    $messages = array_reverse($messages);

    if ($lastVisibleMessageId > 0) {
        $markGroupReadStmt = $conn->prepare(
            'UPDATE chat_group_members
             SET last_read_message_id = CASE
                 WHEN last_read_message_id < ? THEN ?
                 ELSE last_read_message_id
             END
             WHERE group_id = ? AND user_id = ? AND left_at IS NULL'
        );

        if ($markGroupReadStmt !== false) {
            $markGroupReadStmt->bind_param('iiii', $lastVisibleMessageId, $lastVisibleMessageId, $groupId, $user['id']);
            $markGroupReadStmt->execute();
            $markGroupReadStmt->close();
        }
    }
}

if ($receiverId > 0 && $groupId === 0) {
    $markReadStmt = $conn->prepare('UPDATE messages SET is_read = 1, read_at = NOW() WHERE sender_id = ? AND receiver_id = ?');
    $markReadStmt->bind_param('ii', $receiverId, $user['id']);
    $markReadStmt->execute();
    $markReadStmt->close();
}

echo json_encode([
    'success' => true,
    'messages' => $messages,
]);
?>
