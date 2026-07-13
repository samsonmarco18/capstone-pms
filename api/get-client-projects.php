<?php
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/project-progress.php';
requireRole('sales');

$conn = getConnection();
$client_id = $_GET['client_id'] ?? 0;

if (!$client_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Client ID required']);
    exit;
}

$featureCondition = projectProgressFeatureCondition('t');
$mergedCondition = projectProgressMergedCondition('t');
$stmt = $conn->prepare("
    SELECT
        p.id,
        p.client_id,
        p.name,
        p.status,
        p.timeline_end,
        p.created_at,
        c.name AS client_name,
        COALESCE(COUNT(t.id), 0) AS total_features,
        COALESCE(SUM(CASE WHEN {$mergedCondition} THEN 1 ELSE 0 END), 0) AS merged_features
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN tasks t ON t.project_id = p.id AND {$featureCondition}
    WHERE p.client_id = ?
    GROUP BY p.id, p.client_id, p.name, p.status, p.timeline_end, p.created_at, c.name
    ORDER BY p.created_at DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$projects = [];
while ($row = $result->fetch_assoc()) {
    $row['progress'] = projectProgressPercent((int) ($row['total_features'] ?? 0), (int) ($row['merged_features'] ?? 0));
    $projects[] = $row;
}

echo json_encode($projects);
?>

