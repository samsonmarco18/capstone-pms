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

$stmt = $conn->prepare("
    SELECT id, full_name, role, email 
    FROM users 
    WHERE id != ? AND status = 'active'
    ORDER BY full_name
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while($row = $result->fetch_assoc()) {
    $users[] = $row;
}

echo json_encode([
    'success' => true,
    'users' => $users,
]);
?>
