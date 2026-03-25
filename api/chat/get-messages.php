<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$user = getCurrentUser();
$conn = getConnection();

$receiverId = isset($_GET['receiver_id']) ? $_GET['receiver_id'] : null;
$groupId = isset($_GET['group_id']) ? $_GET['group_id'] : null;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

if ($groupId) {
    // Get group messages
    $stmt = $conn->prepare("
        SELECT m.*, u.full_name as sender_name 
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.group_id = ?
        ORDER BY m.created_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $groupId, $limit);
} else {
    // Get private messages between current user and receiver
    $stmt = $conn->prepare("
        SELECT m.*, u.full_name as sender_name 
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE (m.sender_id = ? AND m.receiver_id = ?) 
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("iiiii", $user['id'], $receiverId, $receiverId, $user['id'], $limit);
}

$stmt->execute();
$result = $stmt->get_result();
$messages = [];

while($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'sender_id' => $row['sender_id'],
        'sender_name' => $row['sender_name'],
        'message' => $row['message'],
        'is_mine' => $row['sender_id'] == $user['id'],
        'created_at' => $row['created_at']
    ];
}

// Mark messages as read
if ($receiverId && !$groupId) {
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
    $stmt->bind_param("ii", $receiverId, $user['id']);
    $stmt->execute();
}

echo json_encode(array_reverse($messages));
?>