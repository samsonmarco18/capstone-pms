<?php
declare(strict_types=1);

require_once '../includes/password-reset.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit();
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);
$email = trim((string) ($data['email'] ?? ''));

$result = requestPasswordResetForEmail($email);

echo json_encode([
    'success' => (bool) ($result['success'] ?? true),
    'message' => (string) ($result['message'] ?? 'If the account exists, your password reset request has been sent to the administrator.'),
]);
