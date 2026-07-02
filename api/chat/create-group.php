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
$name = trim((string) ($data['name'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));
$members = array_values(array_unique(array_map('intval', $data['members'] ?? [])));

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Group name is required.']);
    exit();
}

$conn = getConnection();
if (!ensureChatGroupReadTracking($conn)) {
    http_response_code(500);
    echo json_encode(['error' => 'Group message read tracking is unavailable.']);
    exit();
}

$stmt = $conn->prepare('INSERT INTO chat_groups (name, description, created_by) VALUES (?, ?, ?)');
$stmt->bind_param('ssi', $name, $description, $user['id']);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create group.']);
    exit();
}

$groupId = $stmt->insert_id;
$stmt->close();

$memberIds = array_values(array_unique(array_merge([$user['id']], $members)));
$existingMemberStmt = $conn->prepare('SELECT id FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
$insertMemberStmt = $conn->prepare('INSERT INTO chat_group_members (group_id, user_id) VALUES (?, ?)');

if ($existingMemberStmt === false || $insertMemberStmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare group members.']);
    exit();
}

foreach ($memberIds as $memberId) {
    $existingMemberStmt->bind_param('ii', $groupId, $memberId);
    $existingMemberStmt->execute();
    $existingMember = $existingMemberStmt->get_result()->fetch_assoc();
    if ($existingMember) {
        continue;
    }

    $insertMemberStmt->bind_param('ii', $groupId, $memberId);
    if (!$insertMemberStmt->execute()) {
        continue;
    }

}

$existingMemberStmt->close();
$insertMemberStmt->close();

createChatGroupSystemMessage(
    $conn,
    (int) $user['id'],
    $groupId,
    (string) $user['full_name'] . ' created the group.'
);

logActivity((int) $user['id'], 'create_chat_group', 'chat_groups', $groupId, null, $data);

echo json_encode([
    'success' => true,
    'group_id' => $groupId,
]);
?>
