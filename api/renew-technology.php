<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

$user = getCurrentUser();
$technologyId = (int) ($_POST['id'] ?? 0);
$renewalDate = trim((string) ($_POST['expiry_date'] ?? ''));
$monthlyCostIncrease = (float) ($_POST['price_increase'] ?? -1);

if ($technologyId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid technology ID']);
    exit();
}

$date = DateTime::createFromFormat('Y-m-d', $renewalDate);
if (!$date || $date->format('Y-m-d') !== $renewalDate) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid renewal date']);
    exit();
}

if ($monthlyCostIncrease < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid monthly cost increase']);
    exit();
}

$conn = getConnection();
$oldStmt = $conn->prepare('SELECT * FROM technologies WHERE id = ?');
$oldStmt->bind_param('i', $technologyId);
$oldStmt->execute();
$oldData = $oldStmt->get_result()->fetch_assoc();
$oldStmt->close();

if (!$oldData) {
    http_response_code(404);
    echo json_encode(['error' => 'Technology not found']);
    exit();
}

$updateStmt = $conn->prepare(
    "UPDATE technologies
     SET expiry_date = ?,
         price = COALESCE(price, 0) + ?,
         renewal_cost = COALESCE(renewal_cost, 0) + ?,
         status = CASE
             WHEN ? < CURDATE() THEN 'expired'
             WHEN ? <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring'
             ELSE 'active'
         END,
         updated_at = NOW()
     WHERE id = ?"
);
$updateStmt->bind_param('sddssi', $renewalDate, $monthlyCostIncrease, $monthlyCostIncrease, $renewalDate, $renewalDate, $technologyId);
$updateStmt->execute();
$updateStmt->close();

$newData = $oldData;
$newData['expiry_date'] = $renewalDate;
$newData['price'] = (float) ($oldData['price'] ?? 0) + $monthlyCostIncrease;
$newData['renewal_cost'] = (float) ($oldData['renewal_cost'] ?? 0) + $monthlyCostIncrease;
$newData['status'] = $renewalDate < date('Y-m-d')
    ? 'expired'
    : ($renewalDate <= date('Y-m-d', strtotime('+30 days')) ? 'expiring' : 'active');

logActivity((int) $user['id'], 'renew_technology', 'technologies', $technologyId, $oldData, $newData);

echo json_encode([
    'success' => true,
    'expiry_date' => $renewalDate,
    'price' => $newData['price'],
    'price_increase' => $monthlyCostIncrease,
    'status' => $newData['status']
]);
?>
