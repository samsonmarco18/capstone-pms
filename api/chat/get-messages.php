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

$receiverId = isset($_GET['receiver_id']) ? (int) $_GET['receiver_id'] : 0;
$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 50;

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

    $stmt = $conn->prepare(
        'SELECT m.id, m.sender_id, m.message, m.created_at, u.full_name AS sender_name
         FROM messages m
         INNER JOIN users u ON u.id = m.sender_id
         WHERE m.group_id = ?
         ORDER BY m.created_at ASC
         LIMIT ?'
    );
    $stmt->bind_param('ii', $groupId, $limit);
} else {
    $stmt = $conn->prepare(
        'SELECT m.id, m.sender_id, m.message, m.created_at, u.full_name AS sender_name
         FROM messages m
         INNER JOIN users u ON u.id = m.sender_id
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.created_at ASC
         LIMIT ?'
    );
    $stmt->bind_param('iiiii', $user['id'], $receiverId, $receiverId, $user['id'], $limit);
}

$stmt->execute();
$result = $stmt->get_result();
$messages = [];

while ($row = $result->fetch_assoc()) {
    $row['is_mine'] = (int) $row['sender_id'] === (int) $user['id'];
    $messages[] = $row;
}

$stmt->close();

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
