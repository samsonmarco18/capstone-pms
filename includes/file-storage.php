<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function storageEnsureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the configured upload directory.');
    }

    if (!is_writable($directory)) {
        error_log('Upload directory is not writable: ' . $directory);
        throw new RuntimeException('The configured upload directory is not writable.');
    }
}

function storageRootPath(): string
{
    return rtrim((string) UPLOAD_PATH, '/\\');
}

function storageBuildUploadTarget(string $originalName, ?string $subdirectory = null): array
{
    $cleanOriginalName = basename($originalName);
    $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]/', '', $cleanOriginalName) ?: 'upload';
    $storedName = uniqid('file_', true) . '_' . $safeOriginalName;
    $relativeSubdirectory = trim(str_replace('\\', '/', $subdirectory ?: date('Y/m/d')), '/');
    if ($relativeSubdirectory === '' || str_contains($relativeSubdirectory, '..')) {
        throw new InvalidArgumentException('Invalid upload directory.');
    }

    $relativeDirectory = 'uploads/' . $relativeSubdirectory;
    $absoluteDirectory = storageRootPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeSubdirectory);

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

    $relativePath = ltrim($relativePath, '/');
    $uploadRelativePath = str_starts_with($relativePath, 'uploads/')
        ? substr($relativePath, strlen('uploads/'))
        : $relativePath;
    $rootPath = storageRootPath();
    $absolutePath = $rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadRelativePath);
    $root = rtrim(str_replace('\\', '/', $rootPath), '/') . '/';
    $normalized = str_replace('\\', '/', $absolutePath);

    if (!str_starts_with($normalized, $root)) {
        return null;
    }

    // Backward compatibility for files created before UPLOAD_STORAGE_ROOT was configurable.
    if (!is_file($absolutePath) && str_starts_with($relativePath, 'uploads/')) {
        $legacyPath = APP_ROOT_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($legacyPath)) {
            return $legacyPath;
        }
    }

    return $absolutePath;
}
?>
