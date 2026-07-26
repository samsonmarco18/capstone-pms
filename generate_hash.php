<?php
$password = 'password';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo "Password: $password\n";
echo "Hash: $hash\n\n";
echo "Copy this hash and use it in your INSERT statements:\n";
echo "UPDATE users SET password = '$hash';\n";
?>
