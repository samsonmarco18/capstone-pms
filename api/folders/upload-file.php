<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$user = getCurrentUser();
$conn = getConnection();

$folderId = $_POST['folder_id'];
$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('Upload failed');
}

// Create upload directory if not exists
$uploadDir = '../../uploads/' . date('Y/m/d');
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
$filePath = $uploadDir . '/' . $fileName;
$relativePath = str_replace('../../', '', $filePath);

if (move_uploaded_file($file['tmp_name'], $filePath)) {
    $stmt = $conn->prepare("
        INSERT INTO files (folder_id, name, file_path, file_type, file_size, uploaded_by) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssii", $folderId, $file['name'], $relativePath, $file['type'], $file['size'], $user['id']);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'file_id' => $stmt->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
} else {
    http_response_code(500);
    echo json_encode(['error' => 'File move failed']);
}
?>