<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

// Get connection
$conn = getConnection();

// Password to set for all users. Keep aligned with the seeded demo data.
$password = 'password';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
$hasPasswordChangedAt = ensureUserPasswordSecurityColumns();

echo "Hashing password: " . $hashed_password . "\n\n";

// Update all users with the correct hash
$stmt = $conn->prepare($hasPasswordChangedAt
    ? "UPDATE users SET password = ?, password_changed_at = CURRENT_TIMESTAMP"
    : "UPDATE users SET password = ?");
$stmt->bind_param("s", $hashed_password);

if ($stmt->execute()) {
    echo "✅ All user passwords updated successfully!\n\n";
} else {
    echo "❌ Error updating passwords: " . $conn->error . "\n\n";
}

// Verify the users
$result = $conn->query("SELECT id, username, email, role FROM users");
echo "Users in database:\n";
echo str_repeat("-", 60) . "\n";
while($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']} | Email: {$row['email']} | Role: {$row['role']}\n";
}
echo str_repeat("-", 60) . "\n";

// Test password verification for first user
$testUser = $conn->query("SELECT * FROM users LIMIT 1")->fetch_assoc();
if(password_verify($password, $testUser['password'])) {
    echo "✅ Password verification successful!\n";
    echo "You can now login with: " . $testUser['email'] . " / $password\n";
} else {
    echo "❌ Password verification failed!\n";
}

$conn->close();
?>
