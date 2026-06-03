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

if (!$data || !isset($data['password'], $data['current_password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit();
}

$newPassword = trim((string) $data['password']);
$currentPassword = (string) $data['current_password'];

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 6 characters']);
    exit();
}

$conn = getConnection();

$checkStmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$checkStmt->bind_param("i", $user['id']);
$checkStmt->execute();
$currentUserRow = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$currentUserRow || !password_verify($currentPassword, (string) $currentUserRow['password'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Please verify your current password before changing it']);
    exit();
}

$password = password_hash($newPassword, PASSWORD_DEFAULT);

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
