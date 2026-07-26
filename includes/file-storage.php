<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function storageEnsureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create upload directory.');
    }
}

function storageBuildUploadTarget(string $originalName, ?string $subdirectory = null): array
{
    $cleanOriginalName = basename($originalName);
    $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]/', '', $cleanOriginalName) ?: 'upload';
    $storedName = uniqid('file_', true) . '_' . $safeOriginalName;
    $relativeDirectory = 'uploads/' . trim($subdirectory ?: date('Y/m/d'), '/');
    $absoluteDirectory = APP_ROOT_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

    storageEnsureDirectory($absoluteDirectory);

    return [
        'original_name' => $cleanOriginalName,
        'stored_name' => $storedName,
        'absolute_path' => $absoluteDirectory . DIRECTORY_SEPARATOR . $storedName,
        'relative_path' => $relativeDirectory . '/' . $storedName,
    ];
}

function storageResolveRelativePath(string $relativePath): ?string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return null;
    }

    $absolutePath = APP_ROOT_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
    $root = rtrim(str_replace('\\', '/', APP_ROOT_PATH), '/') . '/';
    $normalized = str_replace('\\', '/', $absolutePath);

    if (!str_starts_with($normalized, $root)) {
        return null;
    }

    return $absolutePath;
}
?>
