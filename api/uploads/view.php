<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/file-storage.php';

requireLogin();

$relativePath = trim((string) ($_GET['path'] ?? ''));
if ($relativePath === '' || str_contains(str_replace('\\', '/', $relativePath), '..')) {
    http_response_code(400);
    exit('Invalid file path.');
}

$relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
if (!str_starts_with($relativePath, 'uploads/')) {
    http_response_code(400);
    exit('Invalid upload path.');
}

$absolutePath = storageResolveRelativePath($relativePath);
if ($absolutePath === null || !is_file($absolutePath)) {
    http_response_code(404);
    exit('File not found.');
}

$mimeType = '';
if (class_exists('finfo')) {
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $fileInfo->file($absolutePath);
} elseif (function_exists('mime_content_type')) {
    $mimeType = (string) mime_content_type($absolutePath);
}

$allowedImageTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/svg+xml',
];

if (!in_array($mimeType, $allowedImageTypes, true)) {
    http_response_code(415);
    exit('Unsupported file type.');
}

$lastModified = filemtime($absolutePath) ?: time();
$etag = '"' . sha1($relativePath . '|' . $lastModified . '|' . filesize($absolutePath)) . '"';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Cache-Control: private, max-age=3600');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if ((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

readfile($absolutePath);
exit;
?>
