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
$currentUserId = (int) ($user['id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$groupId = isset($data['group_id']) ? (int) $data['group_id'] : 0;
$memberIds = $data['user_ids'] ?? ($data['user_id'] ?? []);

if (!is_array($memberIds)) {
    $memberIds = [$memberIds];
}

$memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds))));

if ($currentUserId <= 0 || $groupId <= 0 || !$memberIds) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group member request.']);
    exit();
}

try {
    $conn = getConnection();

    $groupStmt = $conn->prepare('SELECT id, name, created_by FROM chat_groups WHERE id = ? LIMIT 1');
    if ($groupStmt === false) {
        throw new RuntimeException('Failed to prepare group query: ' . $conn->error);
    }

    $groupStmt->bind_param('i', $groupId);
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
        echo json_encode(['error' => 'Only the group creator can add members.']);
        exit();
    }

    $userCheckStmt = $conn->prepare('SELECT id, full_name, status FROM users WHERE id = ? LIMIT 1');
    $membershipStmt = $conn->prepare('SELECT id, left_at FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $insertStmt = $conn->prepare('INSERT INTO chat_group_members (group_id, user_id, role) VALUES (?, ?, ?)');
    $restoreStmt = $conn->prepare('UPDATE chat_group_members SET role = ?, left_at = NULL, joined_at = NOW() WHERE id = ?');

    if ($userCheckStmt === false || $membershipStmt === false || $insertStmt === false || $restoreStmt === false) {
        throw new RuntimeException('Failed to prepare member queries: ' . $conn->error);
    }

    $addedCount = 0;
    $addedNames = [];

    foreach ($memberIds as $memberId) {
        if ($memberId <= 0 || $memberId === $currentUserId) {
            continue;
        }

        $userCheckStmt->bind_param('i', $memberId);
        $userCheckStmt->execute();
        $memberUser = $userCheckStmt->get_result()->fetch_assoc();

        if (!$memberUser || (string) ($memberUser['status'] ?? '') !== 'active') {
            continue;
        }

        $membershipStmt->bind_param('ii', $groupId, $memberId);
        $membershipStmt->execute();
        $membership = $membershipStmt->get_result()->fetch_assoc();

        if ($membership && empty($membership['left_at'])) {
            continue;
        }

        if ($membership) {
            $role = 'member';
            $recordId = (int) $membership['id'];
            $restoreStmt->bind_param('si', $role, $recordId);
            if (!$restoreStmt->execute()) {
                continue;
            }
        } else {
            $role = 'member';
            $insertStmt->bind_param('iis', $groupId, $memberId, $role);
            if (!$insertStmt->execute()) {
                continue;
            }
        }

        $addedCount++;
        $addedNames[] = (string) ($memberUser['full_name'] ?? 'Member');

        createNotification(
            $memberId,
            'Added to chat group',
            $user['full_name'] . ' added you to "' . $group['name'] . '".',
            'group',
            'chat_groups',
            $groupId,
            BASE_URL . 'profile.php'
        );
    }

    $userCheckStmt->close();
    $membershipStmt->close();
    $insertStmt->close();
    $restoreStmt->close();

    if ($addedCount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No new members were added.']);
        exit();
    }

    logActivity($currentUserId, 'add_chat_group_member', 'chat_group_members', $groupId, null, [
        'added_user_ids' => $memberIds,
    ]);

    echo json_encode([
        'success' => true,
        'added_count' => $addedCount,
        'message' => $addedCount === 1
            ? 'Member added successfully.'
            : $addedCount . ' members added successfully.',
    ]);
} catch (Throwable $e) {
    error_log('Add chat group member failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to add member.']);
}
?>
