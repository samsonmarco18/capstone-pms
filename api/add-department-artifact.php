<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/file-storage.php';
require_once __DIR__ . '/../includes/department-artifacts.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = getCurrentUser();
if (($user['role'] ?? '') !== 'developer') {
    http_response_code(403);
    echo json_encode(['error' => 'Only assigned department users can save task handoffs.']);
    exit;
}

$conn = getConnection();
departmentArtifactEnsureSchema($conn);

$taskId = (int) ($_POST['task_id'] ?? 0);
$artifactType = trim((string) ($_POST['artifact_type'] ?? 'department_handoff'));
$title = trim((string) ($_POST['title'] ?? ''));
$externalUrl = trim((string) ($_POST['external_url'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$department = trim((string) ($user['department'] ?? ''));
$file = $_FILES['artifact_file'] ?? null;

if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid task.']);
    exit;
}

$allowedTypes = [
    'design_handoff',
    'frontend_handoff',
    'backend_handoff',
    'fullstack_handoff',
    'qa_note',
    'devops_handoff',
    'database_handoff',
    'department_handoff',
];
if (!in_array($artifactType, $allowedTypes, true)) {
    $artifactType = 'department_handoff';
}

if ($externalUrl !== '' && filter_var($externalUrl, FILTER_VALIDATE_URL) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid link.']);
    exit;
}

$taskStmt = $conn->prepare(
    'SELECT t.id, t.project_id, t.title, p.name AS project_name
     FROM tasks t
     LEFT JOIN projects p ON p.id = t.project_id
     WHERE t.id = ? AND t.assigned_to = ?
     LIMIT 1'
);
if ($taskStmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to validate task.']);
    exit;
}

$taskStmt->bind_param('ii', $taskId, $user['id']);
$taskStmt->execute();
$task = $taskStmt->get_result()->fetch_assoc();
$taskStmt->close();

if (!$task) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only save handoffs for tasks assigned to you.']);
    exit;
}

$target = [
    'relative_path' => '',
    'original_name' => '',
];
$fileType = '';
$fileSize = 0;

if ($file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Unable to read uploaded file.']);
        exit;
    }

    $fileSize = (int) ($file['size'] ?? 0);
    if ($fileSize > 10 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Design upload must be 10MB or smaller.']);
        exit;
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowedImageExtensions, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please upload a design image: JPG, PNG, GIF, or WEBP.']);
        exit;
    }

    try {
        $target = storageBuildUploadTarget((string) $file['name'], 'department-artifacts/' . date('Y/m/d'));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }

    if (!move_uploaded_file((string) $file['tmp_name'], $target['absolute_path'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to save uploaded design.']);
        exit;
    }

    $fileType = $extension;
}

if (($target['relative_path'] ?? '') === '' && $externalUrl === '' && $notes === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Add a file, link, or note before saving.']);
    exit;
}

if ($title === '') {
    $title = $artifactType === 'design_handoff' ? 'Design handoff' : 'Department handoff';
}

$stmt = $conn->prepare(
    'INSERT INTO task_department_artifacts
        (task_id, project_id, user_id, department, artifact_type, title, file_path, original_file_name, file_type, file_size, external_url, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    if (($target['relative_path'] ?? '') !== '') {
        @unlink((string) ($target['absolute_path'] ?? ''));
    }
    http_response_code(500);
    echo json_encode(['error' => 'Unable to prepare handoff save.']);
    exit;
}

$relativePath = (string) ($target['relative_path'] ?? '');
$originalName = (string) ($target['original_name'] ?? '');
$projectId = (int) ($task['project_id'] ?? 0);
$userId = (int) $user['id'];
$stmt->bind_param(
    'iiissssssiss',
    $taskId,
    $projectId,
    $userId,
    $department,
    $artifactType,
    $title,
    $relativePath,
    $originalName,
    $fileType,
    $fileSize,
    $externalUrl,
    $notes
);

if (!$stmt->execute()) {
    if ($relativePath !== '') {
        @unlink((string) ($target['absolute_path'] ?? ''));
    }
    http_response_code(500);
    echo json_encode(['error' => 'Unable to save department handoff.']);
    exit;
}

$artifactId = (int) $stmt->insert_id;
$stmt->close();

logActivity($userId, 'save_department_artifact', 'task_department_artifacts', $artifactId, null, [
    'task_id' => $taskId,
    'project_id' => $projectId,
    'department' => $department,
    'artifact_type' => $artifactType,
]);

echo json_encode([
    'success' => true,
    'artifact_id' => $artifactId,
    'message' => 'Department handoff saved.',
]);
?>
