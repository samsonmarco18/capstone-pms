<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$user = getCurrentUser();
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    $data = $_POST;
}

$message = $data['message'];
$receiverId = isset($data['receiver_id']) ? $data['receiver_id'] : null;
$groupId = isset($data['group_id']) ? $data['group_id'] : null;

$conn = getConnection();

if ($groupId) {
    // Group message
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, group_id, message) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $user['id'], $groupId, $message);
} else if ($receiverId) {
    // Private message
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $user['id'], $receiverId, $message);
} else {
    http_response_code(400);
    exit('No receiver specified');
}

if ($stmt->execute()) {
    // Create notification for receiver
    if ($receiverId) {
        $title = "New message from " . $user['full_name'];
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'message')");
        $notifStmt->bind_param("iss", $receiverId, $title, $message);
        $notifStmt->execute();
    }
    
    echo json_encode(['success' => true, 'message_id' => $stmt->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message']);
}
?>