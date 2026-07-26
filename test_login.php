<?php
require_once 'config/database.php';

$email = 'admin@erni-pms.com';
$password = 'password';

echo "Testing login for: $email\n\n";

// Get connection
$conn = getConnection();

// Get user by email
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    echo "✅ User found in database!\n";
    echo "User details:\n";
    echo "ID: " . $user['id'] . "\n";
    echo "Name: " . $user['full_name'] . "\n";
    echo "Role: " . $user['role'] . "\n";
    echo "Status: " . $user['status'] . "\n";
    echo "Password hash: " . $user['password'] . "\n\n";
    
    // Verify password
    if (password_verify($password, $user['password'])) {
        echo "✅ Password verified successfully!\n";
        echo "You can now login!\n";
    } else {
        echo "❌ Password verification failed!\n";
        echo "The password hash in database doesn't match the seeded default password.\n";
        echo "If you intentionally changed passwords, use the updated password instead.\n";
    }
} else {
    echo "❌ User not found with email: $email\n";
    echo "Please check if the user exists in the database.\n";
}

$conn->close();
?>
