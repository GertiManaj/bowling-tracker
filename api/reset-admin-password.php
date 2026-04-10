<?php
// api/reset-admin-password.php
// Reset password admin di gruppo
// TEMPORANEO — ELIMINARE IMMEDIATAMENTE DOPO L'USO

$SECRET = 'resetpwd_' . date('Ymd');

if (!isset($_GET['token']) || $_GET['token'] !== $SECRET) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

$adminEmail  = $_GET['email'] ?? 'gerti.manaj@porettiatu.it';
$newPassword = $_GET['newpwd'] ?? 'Test123!';

if (strlen($newPassword) < 8) {
    die("❌ Password troppo corta (min 8 caratteri)\n");
}

$pdo = getPDO();

$stmt = $pdo->prepare("SELECT id, email, name FROM admins WHERE email = ?");
$stmt->execute([$adminEmail]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die("❌ Admin non trovato: $adminEmail\n");
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);

$pdo->prepare("UPDATE admins SET password_hash = ? WHERE email = ?")
    ->execute([$hash, $adminEmail]);

echo "✅ Password aggiornata per: {$admin['email']}\n";
echo "   Nome:          {$admin['name']}\n";
echo "   Nuova password: $newPassword\n\n";
echo "⚠️  CAMBIA PASSWORD dopo il primo login!\n";
echo "⚠️  ELIMINA QUESTO FILE ORA!\n";
