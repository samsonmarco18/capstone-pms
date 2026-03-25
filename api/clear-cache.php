<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

$cacheDirectories = [
    dirname(__DIR__) . '/uploads/tmp',
    dirname(__DIR__) . '/backups/tmp',
];

foreach ($cacheDirectories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }

    foreach (glob($directory . '/*') ?: [] as $entry) {
        if (is_file($entry)) {
            @unlink($entry);
        }
    }
}

logActivity((int) (getCurrentUser()['id'] ?? 0), 'clear_cache', 'system');

echo json_encode(['success' => true]);
?>
