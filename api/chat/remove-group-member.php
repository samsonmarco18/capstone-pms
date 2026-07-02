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
$currentUserId = (int) ($user['id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$groupId = isset($data['group_id']) ? (int) $data['group_id'] : 0;
$memberId = isset($data['user_id']) ? (int) $data['user_id'] : 0;

if ($currentUserId <= 0 || $groupId <= 0 || $memberId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group member.']);
    exit();
}

if ($memberId === $currentUserId) {
    http_response_code(400);
    echo json_encode(['error' => 'Use Leave Group to remove yourself.']);
    exit();
}

try {
    $conn = getConnection();

    $groupStmt = $conn->prepare(
        'SELECT g.created_by, u.full_name AS member_name
         FROM chat_groups g
         LEFT JOIN users u ON u.id = ?
         WHERE g.id = ?
         LIMIT 1'
    );
    $groupStmt->bind_param('ii', $memberId, $groupId);
    $groupStmt->execute();
    $groupResult = $groupStmt->get_result();
    $group = $groupResult ? $groupResult->fetch_assoc() : null;
    $groupStmt->close();

    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found.']);
        exit();
    }

    if ((int) $group['created_by'] !== $currentUserId) {
        http_response_code(403);
        echo json_encode(['error' => 'Only the group creator can remove members.']);
        exit();
    }

    if ((int) $group['created_by'] === $memberId) {
        http_response_code(400);
        echo json_encode(['error' => 'The group creator cannot be removed.']);
        exit();
    }

    $deleteStmt = $conn->prepare('DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?');
    $deleteStmt->bind_param('ii', $groupId, $memberId);
    $deleteStmt->execute();
    $affectedRows = $deleteStmt->affected_rows;
    $deleteStmt->close();

    if ($affectedRows <= 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Member not found in this group.']);
        exit();
    }

    $removedMemberName = trim((string) ($group['member_name'] ?? '')) ?: 'a member';
    createChatGroupSystemMessage(
        $conn,
        $currentUserId,
        $groupId,
        (string) $user['full_name'] . ' removed ' . $removedMemberName . ' from the group.'
    );

    logActivity($currentUserId, 'remove_chat_group_member', 'chat_group_members', $groupId, null, [
        'removed_user_id' => $memberId,
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Remove chat group member failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to remove member.']);
}
?>
