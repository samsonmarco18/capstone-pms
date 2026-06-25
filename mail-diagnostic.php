<?php
require_once 'includes/auth.php';
requireRole('admin');

require_once 'includes/mailer.php';

$diagnostic = getMailConfigDiagnostic();
$config = getMailConfig();
$isConfigured = isMailConfigured();

header('Content-Type: application/json');
echo json_encode([
    'database_settings' => array_filter($diagnostic, fn($k) => str_starts_with($k, 'db_'), ARRAY_FILTER_USE_KEY),
    'resolved_config' => $diagnostic['resolved'] ?? [],
    'is_configured' => $isConfigured,
    'diagnostic_keys' => array_keys($diagnostic),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
