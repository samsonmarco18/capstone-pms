<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = getCurrentUser();
$conn = getConnection();

$stmt = $conn->prepare("
    SELECT g.*, 
           (SELECT COUNT(*) FROM chat_group_members WHERE group_id = g.id) as member_count
    FROM chat_groups g
    JOIN chat_group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = ?
    ORDER BY g.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();

$groups = [];
while($row = $result->fetch_assoc()) {
    $groups[] = $row;
}

echo json_encode($groups);
?>