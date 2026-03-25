<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = getCurrentUser();
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['name']) || !isset($data['members'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit();
}

$conn = getConnection();

// Create group
$stmt = $conn->prepare("INSERT INTO chat_groups (name, created_by) VALUES (?, ?)");
$stmt->bind_param("si", $data['name'], $user['id']);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create group']);
    exit();
}

$groupId = $stmt->insert_id;

// Add creator to group
$stmt = $conn->prepare("INSERT INTO chat_group_members (group_id, user_id) VALUES (?, ?)");
$stmt->bind_param("ii", $groupId, $user['id']);
$stmt->execute();

// Add members to group
foreach ($data['members'] as $memberId) {
    $stmt = $conn->prepare("INSERT INTO chat_group_members (group_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $groupId, $memberId);
    $stmt->execute();
    
    // Notify member
    $title = "Added to group: " . $data['name'];
    $message = $user['full_name'] . " added you to group: " . $data['name'];
    $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'group')");
    $notifStmt->bind_param("iss", $memberId, $title, $message);
    $notifStmt->execute();
}

echo json_encode(['success' => true, 'group_id' => $groupId]);
?>