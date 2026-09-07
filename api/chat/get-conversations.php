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

try {
    $user = getCurrentUser();
    $conn = getConnection();
    $currentUserId = (int) ($user['id'] ?? 0);

    if ($currentUserId <= 0) {
        throw new RuntimeException('Current user session is invalid.');
    }

    if (!ensureChatGroupReadTracking($conn)) {
        throw new RuntimeException('Group message read tracking is unavailable.');
    }
    if (!ensureChatMessageActions($conn)) {
        throw new RuntimeException('Message actions are unavailable.');
    }

    $usersStmt = $conn->prepare(
        'SELECT id, full_name, role, email, profile_pic
         FROM users
         WHERE id != ? AND status = ?
         ORDER BY full_name ASC'
    );
    if ($usersStmt === false) {
        throw new RuntimeException('Failed to prepare users query: ' . $conn->error);
    }

    $activeStatus = 'active';
    $usersStmt->bind_param('is', $currentUserId, $activeStatus);
    if (!$usersStmt->execute()) {
        throw new RuntimeException('Failed to execute users query: ' . $conn->error);
    }

    $usersResult = $usersStmt->get_result();
    if ($usersResult === false) {
        throw new RuntimeException('Failed to fetch users query result: ' . $conn->error);
    }

    $lastDirectMessageStmt = $conn->prepare(
        'SELECT m.message, m.created_at, m.deleted_at
         FROM messages m
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.created_at DESC
         LIMIT 1'
    );
    if ($lastDirectMessageStmt === false) {
        throw new RuntimeException('Failed to prepare last direct message query: ' . $conn->error);
    }

    $unreadDirectStmt = $conn->prepare(
        'SELECT COUNT(*) AS unread_count
         FROM messages
         WHERE sender_id = ? AND receiver_id = ? AND is_read = 0'
    );
    if ($unreadDirectStmt === false) {
        throw new RuntimeException('Failed to prepare unread direct message query: ' . $conn->error);
    }

    $conversations = [];
    while ($row = $usersResult->fetch_assoc()) {
        $otherUserId = (int) $row['id'];

        $lastDirectMessageStmt->bind_param('iiii', $currentUserId, $otherUserId, $otherUserId, $currentUserId);
        if (!$lastDirectMessageStmt->execute()) {
            throw new RuntimeException('Failed to execute last direct message query: ' . $conn->error);
        }
        $lastMessageResult = $lastDirectMessageStmt->get_result();
        $lastMessageRow = $lastMessageResult ? $lastMessageResult->fetch_assoc() : null;

        $unreadDirectStmt->bind_param('ii', $otherUserId, $currentUserId);
        if (!$unreadDirectStmt->execute()) {
            throw new RuntimeException('Failed to execute unread direct message query: ' . $conn->error);
        }
        $unreadResult = $unreadDirectStmt->get_result();
        $unreadRow = $unreadResult ? $unreadResult->fetch_assoc() : null;

        $conversations[] = [
            'id' => $otherUserId,
            'type' => 'user',
            'name' => (string) ($row['full_name'] ?? ''),
            'subtitle' => getRoleDisplayName((string) ($row['role'] ?? '')),
            'role' => (string) ($row['role'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'profile_url' => getUserProfileImageUrl($row['profile_pic'] ?? null),
            'last_message' => !empty($lastMessageRow['deleted_at'])
                ? 'Message unsent'
                : (string) ($lastMessageRow['message'] ?? 'No messages yet.'),
            'last_message_at' => $lastMessageRow['created_at'] ?? null,
            'unread_count' => (int) ($unreadRow['unread_count'] ?? 0),
        ];
    }

    $lastDirectMessageStmt->close();
    $unreadDirectStmt->close();
    $usersStmt->close();

    $groupStmt = $conn->prepare(
        'SELECT g.id, g.name, g.description, gm.last_read_message_id
         FROM chat_groups g
         INNER JOIN chat_group_members gm ON gm.group_id = g.id
         WHERE gm.user_id = ? AND gm.left_at IS NULL
         ORDER BY g.name ASC'
    );
    if ($groupStmt === false) {
        throw new RuntimeException('Failed to prepare groups query: ' . $conn->error);
    }

    $groupStmt->bind_param('i', $currentUserId);
    if (!$groupStmt->execute()) {
        throw new RuntimeException('Failed to execute groups query: ' . $conn->error);
    }
    $groupResult = $groupStmt->get_result();
    if ($groupResult === false) {
        throw new RuntimeException('Failed to fetch groups query result: ' . $conn->error);
    }

    $lastGroupMessageStmt = $conn->prepare(
        'SELECT message, created_at, deleted_at
         FROM messages
         WHERE group_id = ?
         ORDER BY created_at DESC
         LIMIT 1'
    );
    if ($lastGroupMessageStmt === false) {
        throw new RuntimeException('Failed to prepare last group message query: ' . $conn->error);
    }

    $groupMemberCountStmt = $conn->prepare(
        'SELECT COUNT(*) AS member_count
         FROM chat_group_members
         WHERE group_id = ? AND left_at IS NULL'
    );
    if ($groupMemberCountStmt === false) {
        throw new RuntimeException('Failed to prepare group member count query: ' . $conn->error);
    }

    $unreadGroupStmt = $conn->prepare(
        'SELECT COUNT(*) AS unread_count
         FROM messages
         WHERE group_id = ? AND sender_id != ? AND id > ?'
    );
    if ($unreadGroupStmt === false) {
        throw new RuntimeException('Failed to prepare unread group message query: ' . $conn->error);
    }

    $groups = [];
    while ($row = $groupResult->fetch_assoc()) {
        $groupId = (int) $row['id'];

        $lastGroupMessageStmt->bind_param('i', $groupId);
        if (!$lastGroupMessageStmt->execute()) {
            throw new RuntimeException('Failed to execute last group message query: ' . $conn->error);
        }
        $lastGroupMessageResult = $lastGroupMessageStmt->get_result();
        $lastGroupMessageRow = $lastGroupMessageResult ? $lastGroupMessageResult->fetch_assoc() : null;

        $groupMemberCountStmt->bind_param('i', $groupId);
        if (!$groupMemberCountStmt->execute()) {
            throw new RuntimeException('Failed to execute group member count query: ' . $conn->error);
        }
        $groupMemberCountResult = $groupMemberCountStmt->get_result();
        $groupMemberCountRow = $groupMemberCountResult ? $groupMemberCountResult->fetch_assoc() : null;

        $lastReadMessageId = (int) ($row['last_read_message_id'] ?? 0);
        $unreadGroupStmt->bind_param('iii', $groupId, $currentUserId, $lastReadMessageId);
        if (!$unreadGroupStmt->execute()) {
            throw new RuntimeException('Failed to execute unread group message query: ' . $conn->error);
        }
        $unreadGroupResult = $unreadGroupStmt->get_result();
        $unreadGroupRow = $unreadGroupResult ? $unreadGroupResult->fetch_assoc() : null;

        $groups[] = [
            'id' => $groupId,
            'type' => 'group',
            'name' => (string) ($row['name'] ?? ''),
            'subtitle' => ((int) ($groupMemberCountRow['member_count'] ?? 0)) . ' members',
            'description' => (string) ($row['description'] ?? ''),
            'last_message' => !empty($lastGroupMessageRow['deleted_at'])
                ? 'Message unsent'
                : (string) ($lastGroupMessageRow['message'] ?? 'No group messages yet.'),
            'last_message_at' => $lastGroupMessageRow['created_at'] ?? null,
            'member_count' => (int) ($groupMemberCountRow['member_count'] ?? 0),
            'unread_count' => (int) ($unreadGroupRow['unread_count'] ?? 0),
        ];
    }

    $lastGroupMessageStmt->close();
    $groupMemberCountStmt->close();
    $unreadGroupStmt->close();
    $groupStmt->close();

    $sortByLatest = static function (array $left, array $right): int {
        $leftTime = !empty($left['last_message_at']) ? strtotime((string) $left['last_message_at']) : 0;
        $rightTime = !empty($right['last_message_at']) ? strtotime((string) $right['last_message_at']) : 0;

        if ($leftTime === $rightTime) {
            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        }

        return $rightTime <=> $leftTime;
    };

    usort($conversations, $sortByLatest);
    usort($groups, $sortByLatest);

    echo json_encode([
        'success' => true,
        'conversations' => $conversations,
        'groups' => $groups,
    ]);
} catch (Throwable $e) {
    error_log('Chat conversations API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load conversations.',
    ]);
}
?>
