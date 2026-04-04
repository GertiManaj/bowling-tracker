<?php
// Test password verification

$password = 'StrikeZone2024!';
$hash = '$2y$12$LKn4Yz8qhKGmP0xVQW3Bv.rK5zN7mJ9hT2sR4wP6aQ8bC1dE3fG5h';

echo "Testing password verification:\n\n";
echo "Password: $password\n";
echo "Hash: $hash\n\n";

if (password_verify($password, $hash)) {
    echo "✅ PASSWORD CORRECT!\n";
} else {
    echo "❌ PASSWORD WRONG!\n";
}

echo "\nGenerating new hash:\n";
$newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
echo $newHash . "\n";
