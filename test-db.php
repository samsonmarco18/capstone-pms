<?php
/**
 * Database Connection Test
 * Visit: https://yourdomain.ernie.is-great.org/test-db.php
 */

header('Content-Type: text/html; charset=utf-8');
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

echo '<h1>🔧 Database Connection Test</h1>';

// Load config
require_once __DIR__ . '/config/.env.php';

echo '<div class="info">';
echo '<strong>Connection Details:</strong><br>';
echo 'Host: <code>' . DB_HOST . '</code><br>';
echo 'User: <code>' . DB_USER . '</code><br>';
echo 'Database: <code>' . DB_NAME . '</code><br>';
echo 'Port: <code>3306</code> (default)';
echo '</div>';

// Test connection
echo '<h2>Testing Connection...</h2>';

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        echo '<div class="error">';
        echo '<strong>❌ Connection Failed</strong><br>';
        echo 'Error: ' . htmlspecialchars($conn->connect_error) . '<br><br>';
        echo '<strong>Troubleshooting:</strong><br>';
        echo '1. Verify credentials are correct<br>';
        echo '2. Check if database exists in InfinityFree panel<br>';
        echo '3. Ensure password has no trailing spaces<br>';
        echo '4. Try connecting via phpMyAdmin first<br>';
        echo '</div>';
    } else {
        echo '<div class="success">';
        echo '<strong>✅ Connection Successful!</strong>';
        echo '</div>';
        
        // Test database
        echo '<h2>Checking Database...</h2>';
        $result = $conn->query("SHOW TABLES");
        
        if ($result) {
            $tableCount = $result->num_rows;
            if ($tableCount > 0) {
                echo '<div class="success">';
                echo '<strong>✅ Database has ' . $tableCount . ' tables</strong><br>';
                echo 'Tables: ';
                while ($row = $result->fetch_row()) {
                    echo '<code>' . htmlspecialchars($row[0]) . '</code> ';
                }
                echo '</div>';
            } else {
                echo '<div class="info">';
                echo '<strong>⚠️ Database is empty</strong><br>';
                echo 'You need to import <code>database.sql</code> using phpMyAdmin';
                echo '</div>';
            }
        } else {
            echo '<div class="error">';
            echo '<strong>❌ Query Error:</strong> ' . htmlspecialchars($conn->error);
            echo '</div>';
        }
        
        // Test user permissions
        echo '<h2>Checking Permissions...</h2>';
        $permTest = $conn->query("SELECT VERSION()");
        if ($permTest) {
            $version = $permTest->fetch_row()[0];
            echo '<div class="success">';
            echo '<strong>✅ Query permissions OK</strong><br>';
            echo 'MySQL Version: <code>' . htmlspecialchars($version) . '</code>';
            echo '</div>';
        }
        
        $conn->close();
    }
} catch (Exception $e) {
    echo '<div class="error">';
    echo '<strong>❌ Connection Error</strong><br>';
    echo 'Exception: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}

echo '<p style="margin-top: 30px; color: #666; font-size: 12px;">';
echo 'Test completed at: ' . date('Y-m-d H:i:s') . ' (UTC+8)';
echo '<br><a href="check-deployment.php">← Back to Deployment Check</a>';
echo '</p>';

echo '</body></html>';
?>
