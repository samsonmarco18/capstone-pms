<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/config/database.php';

echo '<!DOCTYPE html>';
echo '<html>';
echo '<head><title>Database Connection Test</title>';
echo '<style>';
echo 'body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }';
echo 'h1 { color: #333; }';
echo '.success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }';
echo '.error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }';
echo '.info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; }';
echo 'code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }';
echo '</style></head>';
echo '<body>';

echo '<h1>Database Connection Test</h1>';

echo '<div class="info">';
echo '<strong>Connection Details:</strong><br>';
echo 'Driver: <code>' . htmlspecialchars(DB_DRIVER) . '</code><br>';
echo 'Host: <code>' . htmlspecialchars(DB_HOST) . '</code><br>';
echo 'User: <code>' . htmlspecialchars(DB_USER) . '</code><br>';
echo 'Database: <code>' . htmlspecialchars(DB_NAME) . '</code><br>';
echo 'Port: <code>' . DB_PORT . '</code>';
echo '</div>';

echo '<h2>Testing Connection...</h2>';

try {
    $conn = getConnection();

    echo '<div class="success"><strong>Connection successful.</strong></div>';

    echo '<h2>Checking Database...</h2>';
    $result = $conn->query('SHOW TABLES');

    if ($result && is_object($result) && property_exists($result, 'num_rows')) {
        $tableCount = (int) $result->num_rows;
        if ($tableCount > 0) {
            echo '<div class="success">';
            echo '<strong>Database has ' . $tableCount . ' tables.</strong><br>';
            echo 'Tables: ';
            while ($row = $result->fetch_row()) {
                echo '<code>' . htmlspecialchars((string) ($row[0] ?? '')) . '</code> ';
            }
            echo '</div>';
        } else {
            echo '<div class="info">';
            echo '<strong>Database is empty.</strong><br>';
            echo 'Import <code>' . (DB_DRIVER === 'pgsql' ? 'database.postgres.sql' : 'database.sql') . '</code>.';
            echo '</div>';
        }
    } else {
        echo '<div class="error"><strong>Query error:</strong> ' . htmlspecialchars($conn->error ?? 'Unknown database error') . '</div>';
    }

    echo '<h2>Checking Permissions...</h2>';
    $versionResult = $conn->query('SELECT VERSION()');
    if ($versionResult && is_object($versionResult)) {
        $version = $versionResult->fetch_row()[0] ?? '';
        echo '<div class="success">';
        echo '<strong>Query permissions OK.</strong><br>';
        echo (DB_DRIVER === 'pgsql' ? 'PostgreSQL Version: ' : 'Database Version: ');
        echo '<code>' . htmlspecialchars((string) $version) . '</code>';
        echo '</div>';
    }
} catch (Throwable $e) {
    echo '<div class="error"><strong>Connection error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '<p style="margin-top: 30px; color: #666; font-size: 12px;">';
echo 'Test completed at: ' . date('Y-m-d H:i:s') . ' (UTC+8)';
echo '<br><a href="check-deployment.php">Back to Deployment Check</a>';
echo '</p>';

echo '</body></html>';
