<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
requireRole('expert');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$conn = getConnection();
$user = getCurrentUser();

$hasRecommendationFeedbackTable = $conn->query("SHOW TABLES LIKE 'feature_estimation_recommendation_feedback'")->num_rows > 0;
if (!$hasRecommendationFeedbackTable) {
    try {
        $conn->query("
            CREATE TABLE feature_estimation_recommendation_feedback (
                id INT PRIMARY KEY AUTO_INCREMENT,
                task_id INT NOT NULL,
                source_type ENUM('history', 'manual') NOT NULL,
                source_id INT NOT NULL,
                action ENUM('accepted', 'rejected') NOT NULL,
                acted_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_task_recommendation_feedback (task_id, source_type, source_id),
                KEY idx_feedback_task (task_id),
                CONSTRAINT fk_recommendation_feedback_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                CONSTRAINT fk_recommendation_feedback_actor FOREIGN KEY (acted_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $hasRecommendationFeedbackTable = $conn->query("SHOW TABLES LIKE 'feature_estimation_recommendation_feedback'")->num_rows > 0;
    } catch (Throwable $e) {
        $hasRecommendationFeedbackTable = false;
    }
}

if (!$hasRecommendationFeedbackTable) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Recommendation feedback table is unavailable']);
    exit;
}

$taskId = (int) ($_POST['task_id'] ?? 0);
$sourceType = trim((string) ($_POST['source_type'] ?? ''));
$sourceId = (int) ($_POST['source_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

if ($taskId <= 0 || $sourceId <= 0 || !in_array($sourceType, ['history', 'manual'], true) || !in_array($action, ['accepted', 'rejected'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid recommendation feedback payload']);
    exit;
}

$ownershipStmt = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND assigned_to = ? LIMIT 1");
$ownershipStmt->bind_param('ii', $taskId, $user['id']);
$ownershipStmt->execute();
$ownsTask = $ownershipStmt->get_result()->fetch_assoc();
$ownershipStmt->close();

if (!$ownsTask) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This feature is not assigned to you']);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO feature_estimation_recommendation_feedback (task_id, source_type, source_id, action, acted_by)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE action = VALUES(action), acted_by = VALUES(acted_by), updated_at = CURRENT_TIMESTAMP
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to prepare feedback save']);
    exit;
}

$stmt->bind_param('isisi', $taskId, $sourceType, $sourceId, $action, $user['id']);
$saved = $stmt->execute();
$stmt->close();

if (!$saved) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save recommendation feedback']);
    exit;
}

logActivity((int) $user['id'], 'feature_recommendation_feedback', 'tasks', $taskId, null, [
    'source_type' => $sourceType,
    'source_id' => $sourceId,
    'action' => $action,
]);

echo json_encode(['success' => true]);
?>
