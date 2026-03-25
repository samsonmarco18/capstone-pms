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
$currentUserId = (int) $user['id'];

$directStmt = $conn->prepare(
    "SELECT
        u.id,
        u.full_name,
        u.role,
        u.email,
        (
            SELECT m.message
            FROM messages m
            WHERE ((m.sender_id = ? AND m.receiver_id = u.id) OR (m.sender_id = u.id AND m.receiver_id = ?))
            ORDER BY m.created_at DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT m.created_at
            FROM messages m
            WHERE ((m.sender_id = ? AND m.receiver_id = u.id) OR (m.sender_id = u.id AND m.receiver_id = ?))
            ORDER BY m.created_at DESC
            LIMIT 1
        ) AS last_message_at,
        (
            SELECT COUNT(*)
            FROM messages m
            WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.is_read = 0
        ) AS unread_count
    FROM users u
    WHERE u.id != ? AND u.status = 'active'
    ORDER BY COALESCE(last_message_at, '1970-01-01 00:00:00') DESC, u.full_name ASC"
);
$directStmt->bind_param(
    'iiiiii',
    $currentUserId,
    $currentUserId,
    $currentUserId,
    $currentUserId,
    $currentUserId,
    $currentUserId
);
$directStmt->execute();
$directResult = $directStmt->get_result();

$conversations = [];
while ($row = $directResult->fetch_assoc()) {
    $conversations[] = [
        'id' => (int) $row['id'],
        'type' => 'user',
        'name' => $row['full_name'],
        'subtitle' => getRoleDisplayName($row['role']),
        'role' => $row['role'],
        'email' => $row['email'],
        'last_message' => $row['last_message'] ?: 'No messages yet.',
        'last_message_at' => $row['last_message_at'],
        'unread_count' => (int) $row['unread_count'],
    ];
}
$directStmt->close();

$groupStmt = $conn->prepare(
    "SELECT
        g.id,
        g.name,
        g.description,
        (
            SELECT m.message
            FROM messages m
            WHERE m.group_id = g.id
            ORDER BY m.created_at DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT m.created_at
            FROM messages m
            WHERE m.group_id = g.id
            ORDER BY m.created_at DESC
            LIMIT 1
        ) AS last_message_at,
        (
            SELECT COUNT(*)
            FROM chat_group_members gm2
            WHERE gm2.group_id = g.id
        ) AS member_count
    FROM chat_groups g
    INNER JOIN chat_group_members gm ON gm.group_id = g.id
    WHERE gm.user_id = ?
    ORDER BY COALESCE(last_message_at, '1970-01-01 00:00:00') DESC, g.name ASC"
);
$groupStmt->bind_param('i', $currentUserId);
$groupStmt->execute();
$groupResult = $groupStmt->get_result();

$groups = [];
while ($row = $groupResult->fetch_assoc()) {
    $groups[] = [
        'id' => (int) $row['id'],
        'type' => 'group',
        'name' => $row['name'],
        'subtitle' => ((int) $row['member_count']) . ' members',
        'description' => $row['description'],
        'last_message' => $row['last_message'] ?: 'No group messages yet.',
        'last_message_at' => $row['last_message_at'],
        'member_count' => (int) $row['member_count'],
        'unread_count' => 0,
    ];
}
$groupStmt->close();

echo json_encode([
    'success' => true,
    'conversations' => $conversations,
    'groups' => $groups,
]);
?>
