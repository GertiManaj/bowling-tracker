<?php
// api/system-integration-test.php
// Test completo sistema multi-gruppo
// TEMPORANEO - eliminare dopo test

$SECRET = 'systemtest_' . date('Ymd');

if (!isset($_GET['token']) || $_GET['token'] !== $SECRET) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

echo "═══════════════════════════════════════════════════════\n";
echo " SYSTEM INTEGRATION TEST — " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════\n\n";

require_once __DIR__ . '/config.php';

try {
    $pdo = getPDO();
    echo "✅ Database connesso\n\n";
} catch (Exception $e) {
    die("❌ Errore DB: " . $e->getMessage());
}

// ── TEST 1: VERIFICA TABELLE E COLONNE ──
echo "── TEST 1: Schema Database ──\n";

$tables = [
    'groups' => ['id', 'name', 'description', 'invite_code', 'group_type', 'created_at'],
    'admins' => ['id', 'email', 'password_hash', 'name', 'full_name', 'phone'],
    'admin_roles' => ['id', 'admin_id', 'role', 'group_id', 'can_add_players', 'can_edit_players'],
    'players' => ['id', 'name', 'emoji', 'group_id'],
    'sessions' => ['id', 'session_date', 'group_id'],
    'player_auth' => ['id', 'player_id', 'email', 'password_hash']
];

$allColumnsOk = true;

foreach ($tables as $table => $requiredColumns) {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "  Tabella $table:\n";

    foreach ($requiredColumns as $col) {
        if (in_array($col, $existingColumns)) {
            echo "    ✅ $col\n";
        } else {
            echo "    ❌ $col MANCANTE\n";
            $allColumnsOk = false;
        }
    }
}

if ($allColumnsOk) {
    echo "\n  ✅ Schema database OK\n";
} else {
    echo "\n  ❌ Schema database INCOMPLETO\n";
}

// ── TEST 2: DATI GRUPPI ──
echo "\n── TEST 2: Gruppi ──\n";

$stmt = $pdo->query("
    SELECT
        g.id,
        g.name,
        g.invite_code,
        g.group_type,
        COUNT(DISTINCT p.id) as player_count,
        COUNT(DISTINCT s.id) as session_count,
        COUNT(DISTINCT ar.id) as admin_count
    FROM `groups` g
    LEFT JOIN players p ON p.group_id = g.id
    LEFT JOIN sessions s ON s.group_id = g.id
    LEFT JOIN admin_roles ar ON ar.group_id = g.id
    GROUP BY g.id
");

$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($groups)) {
    echo "  ⚠️  Nessun gruppo trovato\n";
} else {
    foreach ($groups as $g) {
        echo "  Gruppo [{$g['id']}] {$g['name']}\n";
        echo "    Tipo: {$g['group_type']}\n";
        echo "    Codice Invito: " . ($g['invite_code'] ?: 'MANCANTE ❌') . "\n";
        echo "    Players: {$g['player_count']}\n";
        echo "    Sessions: {$g['session_count']}\n";
        echo "    Admin: {$g['admin_count']}\n\n";
    }
}

// ── TEST 3: ADMIN E RUOLI ──
echo "── TEST 3: Admin e Ruoli ──\n";

$stmt = $pdo->query("
    SELECT
        a.id,
        a.email,
        a.name,
        ar.role,
        ar.group_id,
        g.name as group_name
    FROM admins a
    LEFT JOIN admin_roles ar ON ar.admin_id = a.id
    LEFT JOIN `groups` g ON g.id = ar.group_id
");

$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($admins)) {
    echo "  ⚠️  Nessun admin trovato\n";
} else {
    foreach ($admins as $a) {
        echo "  Admin: {$a['email']}\n";
        echo "    ID: {$a['id']}\n";
        echo "    Nome: " . ($a['name'] ?: 'N/A') . "\n";
        echo "    Ruolo: " . ($a['role'] ?: 'NESSUNO ❌') . "\n";
        echo "    Gruppo: " . ($a['group_name'] ?: 'TUTTI (super_admin)') . "\n\n";
    }
}

// ── TEST 4: PLAYERS SENZA GRUPPO ──
echo "── TEST 4: Data Integrity ──\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM players WHERE group_id IS NULL");
$nullPlayers = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM sessions WHERE group_id IS NULL");
$nullSessions = $stmt->fetchColumn();

if ($nullPlayers === 0) {
    echo "  ✅ Tutti i players hanno group_id\n";
} else {
    echo "  ❌ $nullPlayers players senza group_id\n";
}

if ($nullSessions === 0) {
    echo "  ✅ Tutte le sessions hanno group_id\n";
} else {
    echo "  ❌ $nullSessions sessions senza group_id\n";
}

// ── TEST 5: INVITE CODES ──
echo "\n── TEST 5: Invite Codes ──\n";

$stmt = $pdo->query("SELECT id, name FROM `groups` WHERE invite_code IS NULL");
$groupsNoCode = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($groupsNoCode)) {
    echo "  ✅ Tutti i gruppi hanno invite_code\n";
} else {
    echo "  ❌ Gruppi senza invite_code:\n";
    foreach ($groupsNoCode as $g) {
        echo "    - [{$g['id']}] {$g['name']}\n";
    }
}

// ── TEST 6: PLAYER AUTH ──
echo "\n── TEST 6: Player Authentication ──\n";

$stmt = $pdo->query("
    SELECT
        pa.id,
        pa.email,
        p.name as player_name,
        p.group_id
    FROM player_auth pa
    JOIN players p ON p.id = pa.player_id
");

$playerAuths = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($playerAuths)) {
    echo "  ⚠️  Nessun player con login\n";
} else {
    echo "  Player con login:\n";
    foreach ($playerAuths as $pa) {
        echo "    {$pa['email']} → {$pa['player_name']} (gruppo {$pa['group_id']})\n";
    }
}

// ── TEST 7: API ENDPOINTS ──
echo "\n── TEST 7: API Endpoints ──\n";

$endpoints = [
    'groups.php' => 'GET',
    'group-register.php' => 'POST',
    'player-register.php' => 'POST',
    'public-groups.php' => 'GET',
    'auth.php' => 'POST'
];

foreach ($endpoints as $file => $method) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $size = filesize($path);
        echo "  ✅ $file (" . number_format($size) . " bytes)\n";
    } else {
        echo "  ❌ $file MANCANTE\n";
    }
}

// ── TEST 8: FRONTEND FILES ──
echo "\n── TEST 8: Frontend Files ──\n";

$frontendFiles = [
    '../frontend/pages/welcome.html',
    '../frontend/pages/index.html',
    '../frontend/pages/group-register.html',
    '../frontend/pages/player-register.html',
    '../frontend/pages/super-admin.html',
    '../frontend/assets/js/app.js',
    '../frontend/assets/js/auth.js',
    '../frontend/assets/js/shared.js'
];

$allFrontendOk = true;

foreach ($frontendFiles as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        echo "  ✅ $file\n";
    } else {
        echo "  ❌ $file MANCANTE\n";
        $allFrontendOk = false;
    }
}

// ── RIEPILOGO ──
echo "\n═══════════════════════════════════════════════════════\n";
echo " RIEPILOGO\n";
echo "═══════════════════════════════════════════════════════\n";

echo "\n📊 Dati:\n";
echo "  Gruppi: " . count($groups) . "\n";
echo "  Admin: " . count($admins) . "\n";
echo "  Player Auth: " . count($playerAuths) . "\n";

echo "\n✅ Funzionalità:\n";
echo "  - Schema DB: " . ($allColumnsOk ? 'OK' : 'INCOMPLETO') . "\n";
echo "  - Invite Codes: " . (empty($groupsNoCode) ? 'OK' : 'MANCANTI') . "\n";
echo "  - Data Integrity: " . ($nullPlayers === 0 && $nullSessions === 0 ? 'OK' : 'PROBLEMI') . "\n";
echo "  - Frontend Files: " . ($allFrontendOk ? 'OK' : 'MANCANTI') . "\n";

echo "\n⚠️  PROSSIMI STEP:\n";
echo "  1. Fix OTP (verificare logs Railway)\n";
echo "  2. Test login admin gruppo\n";
echo "  3. Test registrazione player con codice invito\n";
echo "  4. Test modalità ospite\n";
echo "  5. Test isolamento gruppi\n";

echo "\n═══════════════════════════════════════════════════════\n";
echo " ELIMINA QUESTO FILE DOPO IL TEST\n";
echo "═══════════════════════════════════════════════════════\n";
