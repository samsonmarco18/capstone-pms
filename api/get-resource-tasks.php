<?php
require_once '../includes/auth.php';
requireRole('architect');

$conn = getConnection();

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit();
}

$userId = (int) $_GET['user_id'];

// Verify the user exists and is a developer or expert
$stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE id = ? AND role IN ('developer', 'expert') AND status = 'active'");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit();
}

// Get tasks for this user
$stmt = $conn->prepare("
    SELECT t.*, p.name as project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = ?
    ORDER BY t.created_at DESC
    LIMIT 20
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

header('Content-Type: application/json');
echo json_encode($tasks);
?>