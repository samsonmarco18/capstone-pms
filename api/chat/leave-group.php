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

if ($currentUserId <= 0 || $groupId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group.']);
    exit();
}

try {
    $conn = getConnection();
    $conn->begin_transaction();

    $groupStmt = $conn->prepare('SELECT created_by FROM chat_groups WHERE id = ? LIMIT 1');
    $groupStmt->bind_param('i', $groupId);
    $groupStmt->execute();
    $groupResult = $groupStmt->get_result();
    $group = $groupResult ? $groupResult->fetch_assoc() : null;
    $groupStmt->close();

    if (!$group) {
        $conn->rollback();
        http_response_code(404);
        echo json_encode(['error' => 'Group not found.']);
        exit();
    }

    $memberStmt = $conn->prepare('SELECT id FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $memberStmt->bind_param('ii', $groupId, $currentUserId);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();
    $isMember = $memberResult && $memberResult->num_rows > 0;
    $memberStmt->close();

    if (!$isMember) {
        $conn->rollback();
        http_response_code(404);
        echo json_encode(['error' => 'You are not a member of this group.']);
        exit();
    }

    $deleteStmt = $conn->prepare('DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?');
    $deleteStmt->bind_param('ii', $groupId, $currentUserId);
    $deleteStmt->execute();
    $deleteStmt->close();

    $countStmt = $conn->prepare('SELECT COUNT(*) AS member_count FROM chat_group_members WHERE group_id = ?');
    $countStmt->bind_param('i', $groupId);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $countRow = $countResult ? $countResult->fetch_assoc() : null;
    $memberCount = (int) ($countRow['member_count'] ?? 0);
    $countStmt->close();

    if ($memberCount === 0) {
        $deleteGroupStmt = $conn->prepare('DELETE FROM chat_groups WHERE id = ?');
        $deleteGroupStmt->bind_param('i', $groupId);
        $deleteGroupStmt->execute();
        $deleteGroupStmt->close();
    } elseif ((int) $group['created_by'] === $currentUserId) {
        $nextCreatorStmt = $conn->prepare(
            'SELECT user_id
             FROM chat_group_members
             WHERE group_id = ?
             ORDER BY joined_at ASC
             LIMIT 1'
        );
        $nextCreatorStmt->bind_param('i', $groupId);
        $nextCreatorStmt->execute();
        $nextCreatorResult = $nextCreatorStmt->get_result();
        $nextCreator = $nextCreatorResult ? $nextCreatorResult->fetch_assoc() : null;
        $nextCreatorStmt->close();

        if ($nextCreator) {
            $newCreatorId = (int) $nextCreator['user_id'];
            $updateGroupStmt = $conn->prepare('UPDATE chat_groups SET created_by = ? WHERE id = ?');
            $updateGroupStmt->bind_param('ii', $newCreatorId, $groupId);
            $updateGroupStmt->execute();
            $updateGroupStmt->close();
        }
    }

    if ($memberCount > 0) {
        createChatGroupSystemMessage(
            $conn,
            $currentUserId,
            $groupId,
            (string) $user['full_name'] . ' left the group.'
        );
    }

    $conn->commit();

    logActivity($currentUserId, 'leave_chat_group', 'chat_groups', $groupId, null, []);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }

    error_log('Leave chat group failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to leave group.']);
}
?>
