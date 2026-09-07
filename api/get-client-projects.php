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

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.client_id,
        p.name,
        p.status,
        p.timeline_end,
        p.created_at,
        c.name AS client_name
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    WHERE p.client_id = ?
    ORDER BY p.created_at DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$projects = [];
while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}

$roadmapProgress = projectRoadmapProgressByProject($conn, array_column($projects, 'id'));
foreach ($projects as &$project) {
    $project['progress'] = strtolower((string) ($project['status'] ?? '')) === 'completed'
        ? 100
        : ($roadmapProgress[(int) ($project['id'] ?? 0)] ?? 0);
}
unset($project);

echo json_encode($projects);
?>

