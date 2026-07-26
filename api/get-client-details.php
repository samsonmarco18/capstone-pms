<?php
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/project-progress.php';
requireRole('sales');

$conn = getConnection();
$id = $_GET['id'] ?? 0;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Client ID required']);
    exit;
}

$featureCondition = projectProgressFeatureCondition('t');
$mergedCondition = projectProgressMergedCondition('t');
$stmt = $conn->prepare("
    SELECT
        c.*,
        COALESCE(cp.total_features, 0) AS total_features,
        COALESCE(cp.merged_features, 0) AS merged_features
    FROM clients c
    LEFT JOIN (
        SELECT
            p.client_id,
            COUNT(t.id) AS total_features,
            SUM(CASE WHEN {$mergedCondition} THEN 1 ELSE 0 END) AS merged_features
        FROM projects p
        LEFT JOIN tasks t ON t.project_id = p.id AND {$featureCondition}
        GROUP BY p.client_id
    ) cp ON cp.client_id = c.id
    WHERE c.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$client = $result->fetch_assoc();

if (!$client) {
    http_response_code(404);
    echo json_encode(['error' => 'Client not found']);
    exit;
}

$client['progress'] = projectProgressPercent((int) ($client['total_features'] ?? 0), (int) ($client['merged_features'] ?? 0));
echo json_encode($client);
?>

