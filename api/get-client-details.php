<?php
header('Content-Type: application/json');
require_once '../includes/auth.php';
requireRole('sales');

$conn = getConnection();
$id = $_GET['id'] ?? 0;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Client ID required']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$client = $result->fetch_assoc();

if (!$client) {
    http_response_code(404);
    echo json_encode(['error' => 'Client not found']);
    exit;
}

echo json_encode($client);
?>

