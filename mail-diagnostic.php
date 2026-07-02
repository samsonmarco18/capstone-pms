<?php
require_once 'includes/auth.php';
requireRole('admin');

require_once 'includes/mailer.php';

$diagnostic = getMailConfigDiagnostic();
$config = getMailConfig();
$isConfigured = isMailConfigured();
$testResult = null;

if (($_POST['send_test'] ?? $_GET['send_test'] ?? '') !== '') {
    $user = getCurrentUser();
    $recipient = trim((string) ($_POST['to'] ?? $_GET['to'] ?? ($user['email'] ?? '')));

    $testResult = sendSystemEmail(
        $recipient,
        'ERNI PMS Mail Test',
        '<p>This is a test email from ERNI PMS mail diagnostics.</p>',
        'This is a test email from ERNI PMS mail diagnostics.'
    );
}

header('Content-Type: application/json');
echo json_encode([
    'database_settings' => array_filter($diagnostic, fn($k) => str_starts_with($k, 'db_'), ARRAY_FILTER_USE_KEY),
    'resolved_config' => $diagnostic['resolved'] ?? [],
    'is_configured' => $isConfigured,
    'test_result' => $testResult,
    'diagnostic_keys' => array_keys($diagnostic),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
