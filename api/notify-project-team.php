<?php
header('Content-Type: application/json');

require_once '../includes/auth.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();
$projectId = (int) ($_POST['project_id'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));

if ($projectId < 1 || $message === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Choose a project and enter a message.']);
    exit();
}

if (mb_strlen($message) > 500) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'The team message cannot exceed 500 characters.']);
    exit();
}

$projectStmt = $conn->prepare('SELECT id, name, status, architect_id, sul_id FROM projects WHERE id = ? LIMIT 1');
$projectStmt->bind_param('i', $projectId);
$projectStmt->execute();
$project = $projectStmt->get_result()->fetch_assoc();
$projectStmt->close();

if (!$project) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Project not found.']);
    exit();
}

$recipientIds = array_filter([
    (int) ($project['architect_id'] ?? 0),
    (int) ($project['sul_id'] ?? 0),
]);

// Architect is always part of the responsible project team. Until a specific
// architect accepts the project, notify every active architect who can claim it.
if ((int) ($project['architect_id'] ?? 0) < 1) {
    $architects = $conn->query("SELECT id FROM users WHERE role = 'architect' AND status = 'active'");
    if ($architects) {
        while ($architect = $architects->fetch_assoc()) {
            $recipientIds[] = (int) ($architect['id'] ?? 0);
        }
    }
}

$memberQueries = [
    'SELECT DISTINCT assigned_to AS user_id FROM tasks WHERE project_id = ? AND assigned_to IS NOT NULL',
    'SELECT DISTINCT assigned_developer_id AS user_id FROM methodology_tasks WHERE project_id = ? AND assigned_developer_id IS NOT NULL',
    'SELECT DISTINCT daa.developer_id AS user_id FROM developer_allocation_assignments daa INNER JOIN developer_allocations da ON da.id = daa.allocation_id WHERE da.project_id = ?',
];

foreach ($memberQueries as $sql) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        continue;
    }
    $stmt->bind_param('i', $projectId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recipientIds[] = (int) ($row['user_id'] ?? 0);
        }
    }
    $stmt->close();
}

$senderId = (int) ($user['id'] ?? 0);
$recipientIds = array_values(array_unique(array_filter(
    $recipientIds,
    static fn(int $id): bool => $id > 0 && $id !== $senderId
)));

if ($recipientIds === []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'No responsible team members are assigned to this project yet.']);
    exit();
}

$senderName = trim((string) ($user['full_name'] ?? $user['username'] ?? 'Sales'));
$title = 'Project team message: ' . (string) $project['name'];
$notificationMessage = $senderName . ': ' . $message;
$sent = 0;

foreach ($recipientIds as $recipientId) {
    if (createNotification(
        $recipientId,
        $title,
        $notificationMessage,
        'project',
        'projects',
        $projectId,
        BASE_URL . 'modules/roadmap/methodology.php?project_id=' . $projectId,
        false
    )) {
        $sent++;
    }
}

echo json_encode([
    'success' => $sent > 0,
    'sent_count' => $sent,
    'message' => 'Evortix will announce this message to ' . $sent . ' responsible team member' . ($sent === 1 ? '' : 's') . '.',
]);
