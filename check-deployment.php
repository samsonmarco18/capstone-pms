<?php
/**
 * ERNI PMS - Deployment Health Check
 * Visit: https://yourdomain.com/erni-pms/check-deployment.php
 */

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>';
echo '<html>';
echo '<head><title>ERNI PMS - Deployment Check</title>';
echo '<style>';
echo 'body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }';
echo '.check { margin: 10px 0; padding: 10px; border-radius: 5px; }';
echo '.pass { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }';
echo '.fail { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }';
echo '.warn { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }';
echo 'h1 { color: #333; }';
echo 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
echo 'th, td { text-align: left; padding: 12px; border-bottom: 1px solid #ddd; }';
echo 'th { background: #f8f9fa; font-weight: bold; }';
echo '</style></head>';
echo '<body>';

echo '<h1>🔍 ERNI PMS Deployment Check</h1>';

$checks = [];

// 1. PHP Version
$checks['PHP Version'] = [
    'status' => version_compare(PHP_VERSION, '8.0') >= 0 ? 'pass' : 'fail',
    'message' => 'PHP ' . PHP_VERSION . ' (Required: 8.0+)',
];

// 2. Required Extensions
$extensions = ['mysqli', 'json', 'curl', 'mbstring', 'filter'];
foreach ($extensions as $ext) {
    $checks['Extension: ' . $ext] = [
        'status' => extension_loaded($ext) ? 'pass' : 'fail',
        'message' => extension_loaded($ext) ? 'Loaded' : 'Missing',
    ];
}

// 3. File Permissions
$dirs = [
    'uploads' => __DIR__ . '/uploads',
    'git' => __DIR__ . '/git',
    'git-workspaces' => __DIR__ . '/git-workspaces',
    'config' => __DIR__ . '/config',
];

foreach ($dirs as $name => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $status = $writable ? 'pass' : ($exists ? 'warn' : 'fail');
    $checks['Directory: ' . $name] = [
        'status' => $status,
        'message' => $writable ? 'Writable' : ($exists ? 'Exists but not writable' : 'Does not exist'),
    ];
}

// 4. Configuration Files
$checks['config/.env.php'] = [
    'status' => file_exists(__DIR__ . '/config/.env.php') ? 'pass' : 'warn',
    'message' => file_exists(__DIR__ . '/config/.env.php') ? 'Present' : 'Not found (using defaults)',
];

$checks['.htaccess'] = [
    'status' => file_exists(__DIR__ . '/.htaccess') ? 'pass' : 'warn',
    'message' => file_exists(__DIR__ . '/.htaccess') ? 'Present' : 'Missing (URL rewriting may not work)',
];

// 5. Database Connection
$db_connected = false;
$db_message = '';
try {
    require_once __DIR__ . '/config/database.php';
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        $db_message = 'Connection failed: ' . $conn->connect_error;
    } else {
        $db_connected = true;
        $db_message = 'Connected successfully';
        $conn->close();
    }
} catch (Exception $e) {
    $db_message = 'Error: ' . $e->getMessage();
}

$checks['Database Connection'] = [
    'status' => $db_connected ? 'pass' : 'fail',
    'message' => $db_message,
];

// 6. Required Tables (if connected)
if ($db_connected) {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $result = $conn->query("SHOW TABLES");
        $tableCount = $result->num_rows;
        $checks['Database Tables'] = [
            'status' => $tableCount > 0 ? 'pass' : 'fail',
            'message' => $tableCount > 0 ? "Found $tableCount tables" : 'No tables (run database.sql)',
        ];
        $conn->close();
    } catch (Exception $e) {
        $checks['Database Tables'] = [
            'status' => 'fail',
            'message' => 'Could not query tables',
        ];
    }
}

// 7. Web Server
$checks['Web Server'] = [
    'status' => 'pass',
    'message' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
];

// 8. HTTPS
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
$checks['HTTPS'] = [
    'status' => $isHttps ? 'pass' : 'warn',
    'message' => $isHttps ? 'Enabled' : 'Disabled (recommended for production)',
];

// Display Results
echo '<table>';
echo '<tr><th>Check</th><th>Status</th><th>Details</th></tr>';

$passCount = 0;
$failCount = 0;
$warnCount = 0;

foreach ($checks as $name => $check) {
    $status = $check['status'];
    $message = $check['message'];
    
    if ($status === 'pass') $passCount++;
    elseif ($status === 'fail') $failCount++;
    else $warnCount++;
    
    $class = $status === 'pass' ? 'pass' : ($status === 'fail' ? 'fail' : 'warn');
    $emoji = $status === 'pass' ? '✅' : ($status === 'fail' ? '❌' : '⚠️');
    
    echo "<tr>";
    echo "<td><strong>$name</strong></td>";
    echo "<td class='$class'>$emoji $status</td>";
    echo "<td>$message</td>";
    echo "</tr>";
}

echo '</table>';

// Summary
echo '<h2>Summary</h2>';
echo '<div style="margin-top: 20px;">';
echo "<p>✅ Passed: <strong>$passCount</strong></p>";
echo "<p>⚠️ Warnings: <strong>$warnCount</strong></p>";
echo "<p>❌ Failed: <strong>$failCount</strong></p>";

if ($failCount === 0) {
    echo '<div class="check pass" style="margin-top: 20px; font-size: 16px;">';
    echo '<strong>✅ Deployment looks good! You can proceed.</strong>';
    echo '</div>';
} else {
    echo '<div class="check fail" style="margin-top: 20px; font-size: 16px;">';
    echo '<strong>❌ Please fix the failed checks before deploying.</strong>';
    echo '</div>';
}

echo '<p style="margin-top: 30px; color: #666; font-size: 12px;">';
echo 'Generated: ' . date('Y-m-d H:i:s');
echo '<br>PHP Version: ' . PHP_VERSION;
echo '<br>Server: ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');
echo '</p>';

echo '</body></html>';
?>
