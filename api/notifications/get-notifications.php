<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$user = getCurrentUser();
$conn = getConnection();

$stmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

echo json_encode($notifications);
?>