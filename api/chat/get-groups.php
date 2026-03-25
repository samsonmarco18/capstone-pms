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

$stmt = $conn->prepare(
    'SELECT g.*,
            (SELECT COUNT(*) FROM chat_group_members WHERE group_id = g.id) AS member_count
     FROM chat_groups g
     INNER JOIN chat_group_members gm ON gm.group_id = g.id
     WHERE gm.user_id = ?
     ORDER BY g.name'
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$result = $stmt->get_result();

$groups = [];
while ($row = $result->fetch_assoc()) {
    $groups[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'groups' => $groups,
]);
?>
