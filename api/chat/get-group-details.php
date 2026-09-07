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
$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;

if ($currentUserId <= 0 || $groupId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group.']);
    exit();
}

try {
    $conn = getConnection();

    $membershipStmt = $conn->prepare(
        'SELECT id
         FROM chat_group_members
         WHERE group_id = ? AND user_id = ? AND left_at IS NULL
         LIMIT 1'
    );
    if ($membershipStmt === false) {
        throw new RuntimeException('Failed to prepare membership query: ' . $conn->error);
    }

    $membershipStmt->bind_param('ii', $groupId, $currentUserId);
    $membershipStmt->execute();
    $membershipResult = $membershipStmt->get_result();
    $isMember = $membershipResult && $membershipResult->num_rows > 0;
    $membershipStmt->close();

    if (!$isMember) {
        http_response_code(403);
        echo json_encode(['error' => 'You are not a member of this group.']);
        exit();
    }

    $groupStmt = $conn->prepare(
        'SELECT g.id, g.name, g.description, g.created_at, g.created_by, u.full_name AS creator_name
         FROM chat_groups g
         INNER JOIN users u ON u.id = g.created_by
         WHERE g.id = ?
         LIMIT 1'
    );
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

    $membersStmt = $conn->prepare(
        'SELECT u.id, u.full_name, u.role, u.email, u.profile_pic, gm.role AS group_role, gm.joined_at
         FROM chat_group_members gm
         INNER JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ? AND gm.left_at IS NULL
         ORDER BY
            CASE WHEN u.id = ? THEN 0 WHEN u.id = ? THEN 1 ELSE 2 END,
            u.full_name ASC'
    );
    if ($membersStmt === false) {
        throw new RuntimeException('Failed to prepare members query: ' . $conn->error);
    }

    $creatorId = (int) ($group['created_by'] ?? 0);
    $membersStmt->bind_param('iii', $groupId, $currentUserId, $creatorId);
    $membersStmt->execute();
    $membersResult = $membersStmt->get_result();

    $members = [];
    while ($row = $membersResult->fetch_assoc()) {
        $members[] = [
            'id' => (int) $row['id'],
            'full_name' => (string) ($row['full_name'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'role_label' => getRoleDisplayName((string) ($row['role'] ?? '')),
            'email' => (string) ($row['email'] ?? ''),
            'profile_url' => getUserProfileImageUrl($row['profile_pic'] ?? null),
            'group_role' => (string) ($row['group_role'] ?? 'member'),
            'joined_at' => $row['joined_at'] ?? null,
            'is_you' => (int) $row['id'] === $currentUserId,
            'is_creator' => (int) $row['id'] === $creatorId,
        ];
    }
    $membersStmt->close();

    echo json_encode([
        'success' => true,
        'group' => [
            'id' => (int) $group['id'],
            'name' => (string) ($group['name'] ?? ''),
            'description' => (string) ($group['description'] ?? ''),
            'created_at' => $group['created_at'] ?? null,
            'created_by' => $creatorId,
            'creator_name' => (string) ($group['creator_name'] ?? ''),
            'member_count' => count($members),
        ],
        'members' => $members,
    ]);
} catch (Throwable $e) {
    error_log('Chat group details API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load group details.',
    ]);
}
?>
