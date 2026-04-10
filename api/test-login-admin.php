<?php
// api/test-login-admin.php
// Verifica configurazione admin di gruppo
// TEMPORANEO — eliminare dopo test

$SECRET = 'testadmin_' . date('Ymd');

if (!isset($_GET['token']) || $_GET['token'] !== $SECRET) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "═══ TEST LOGIN GROUP ADMIN ═══\n\n";

$pdo = getPDO();

$email = $_GET['email'] ?? 'gerti.manaj@porettiatu.it';

$stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
$stmt->execute([$email]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die("❌ Admin non trovato: $email\n");
}

echo "✅ Admin trovato:\n";
echo "   ID:     {$admin['id']}\n";
echo "   Email:  {$admin['email']}\n";
echo "   Nome:   {$admin['name']}\n";
echo "   Attivo: " . ($admin['active'] ? 'SI' : 'NO') . "\n\n";

// Verifica ruolo
$stmt2 = $pdo->prepare("
    SELECT ar.*, g.name AS group_name
    FROM admin_roles ar
    LEFT JOIN `groups` g ON g.id = ar.group_id
    WHERE ar.admin_id = ?
    ORDER BY ar.id ASC
    LIMIT 1
");
$stmt2->execute([$admin['id']]);
$roleData = $stmt2->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    echo "✅ Ruolo trovato:\n";
    echo "   Role:     {$roleData['role']}\n";
    echo "   Group ID: " . ($roleData['group_id'] ?? 'NULL') . "\n";
    echo "   Gruppo:   " . ($roleData['group_name'] ?? 'N/A') . "\n\n";
} else {
    echo "⚠️  Nessun ruolo in admin_roles (potrebbe essere super_admin implicito)\n\n";
    $roleData = [];
}

// Verifica hash password
echo "── Verifica Password ──\n";
$testPwd = $_GET['pwd'] ?? '';
if ($testPwd) {
    if (password_verify($testPwd, $admin['password_hash'])) {
        echo "✅ Password corretta: $testPwd\n";
    } else {
        echo "❌ Password errata\n";
        echo "   Hash attuale: " . substr($admin['password_hash'], 0, 20) . "...\n";
    }
} else {
    echo "  ℹ️  Passa ?pwd=TUA_PASSWORD per testare la password\n";
}

// Verifica SKIP_OTP
echo "\n── Variabili Ambiente ──\n";
echo "   SKIP_OTP_FOR_TESTING: " . (getenv('SKIP_OTP_FOR_TESTING') ?: '(non impostata)') . "\n";
echo "   RESEND_API_KEY:       " . (getenv('RESEND_API_KEY') ? '✅ configurata' : '❌ NON configurata') . "\n";
echo "   JWT_SECRET:           " . (getenv('JWT_SECRET') ? '✅ impostato' : '(default)') . "\n";

echo "\n═══ FINE TEST ═══\n";
echo "\n⚠️  ELIMINA QUESTO FILE DOPO L'USO\n";
