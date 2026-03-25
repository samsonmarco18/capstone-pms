<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = getCurrentUser();
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit();
}

$password = password_hash($data['password'], PASSWORD_DEFAULT);
$conn = getConnection();

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $password, $user['id']);

if ($stmt->execute()) {
    logActivity($user['id'], 'change_password', 'users', $user['id']);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to change password']);
}
?>