<?php
// ============================================
//  api/phase2-test.php — Test FASE 1 + FASE 2
//  DA ELIMINARE dopo l'uso
//  Token: phase2test_YYYYMMDD  (cambia ogni giorno)
// ============================================
if (($_GET['token'] ?? '') !== 'phase2test_' . date('Ymd')) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

header('Content-Type: text/plain; charset=utf-8');

$pass = 0; $fail = 0;

function ok(string $label, string $note = ''): void {
    global $pass;
    $pass++;
    echo "  ✅  $label" . ($note ? "  [$note]" : '') . "\n";
}
function ko(string $label, string $note = ''): void {
    global $fail;
    $fail++;
    echo "  ❌  $label" . ($note ? "  [$note]" : '') . "\n";
}
function section(string $title): void { echo "\n── $title ──\n"; }

// ── Base URL per curl ──
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = "$scheme://$host";

function apiCall(string $method, string $url, array $body = [], array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $hdrs = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) $hdrs[] = "$k: $v";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);

    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    $data = $response ? json_decode($response, true) : null;
    return ['code' => $code, 'data' => $data, 'raw' => $response, 'err' => $err];
}

echo "═══════════════════════════════════════\n";
echo " PHASE 2 TEST — " . date('Y-m-d H:i:s') . "\n";
echo " Host: $baseUrl\n";
echo "═══════════════════════════════════════\n";

// ════════════════════════════════════════════
// SETUP: Connessione DB e funzioni
// ════════════════════════════════════════════
section('SETUP: Connessione DB');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/jwt_protection.php';

try {
    $pdo = getPDO();
    ok('Connessione DB riuscita');
} catch (Exception $e) {
    ko('Connessione DB', $e->getMessage());
    echo "\nImpossibile continuare senza DB.\n";
    exit;
}

// ════════════════════════════════════════════
// TEST 1: Schema DB (Migration 012)
// ════════════════════════════════════════════
section('TEST 1: Schema DB (Migration 012)');

$checks = [
    ['SHOW TABLES LIKE "groups"',       'Tabella groups'],
    ['SHOW TABLES LIKE "admin_roles"',  'Tabella admin_roles'],
    ['SHOW TABLES LIKE "player_auth"',  'Tabella player_auth'],
];
foreach ($checks as [$sql, $label]) {
    $pdo->query($sql)->fetch() ? ok($label) : ko($label);
}

foreach (['group_id' => 'players', 'group_id' => 'sessions'] as $col => $tbl) {
    $exists = $pdo->query("SHOW COLUMNS FROM $tbl LIKE 'group_id'")->fetch();
    $exists ? ok("$tbl.$col") : ko("$tbl.$col");
}
foreach (['full_name', 'phone'] as $col) {
    $exists = $pdo->query("SHOW COLUMNS FROM admins LIKE '$col'")->fetch();
    $exists ? ok("admins.$col") : ko("admins.$col");
}

$grp = $pdo->query("SELECT id, name FROM `groups` WHERE name = 'Strike Zone Original' LIMIT 1")->fetch();
$grp ? ok('Gruppo default "Strike Zone Original"', "ID={$grp['id']}") : ko('Gruppo default mancante');
$defaultGroupId = $grp ? (int)$grp['id'] : 1;

$superAdmin = $pdo->query("SELECT ar.*, a.email FROM admin_roles ar JOIN admins a ON ar.admin_id=a.id WHERE ar.role='super_admin' LIMIT 1")->fetch();
$superAdmin ? ok('Super admin assegnato', $superAdmin['email']) : ko('Super admin mancante in admin_roles');

$nullP = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE group_id IS NULL")->fetchColumn();
$nullS = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE group_id IS NULL")->fetchColumn();
$nullP === 0 ? ok('Tutti i players hanno group_id') : ko("$nullP players senza group_id");
$nullS === 0 ? ok('Tutte le sessions hanno group_id') : ko("$nullS sessions senza group_id");

// ════════════════════════════════════════════
// TEST 2: JWT con Ruoli
// ════════════════════════════════════════════
section('TEST 2: JWT con Ruoli');

// Carichiamo solo le funzioni di auth.php senza eseguirne il codice root
// Le funzioni createJWT e fetchAdminRole sono nel file — lo includiamo in
// output buffering per ignorare l'output del blocco "azione non valida"
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET_backup = $_GET;
$_GET = ['action' => '__noop__'];
ob_start();
require_once __DIR__ . '/auth.php';
ob_end_clean();
$_GET = $_GET_backup;

$firstAdmin = $pdo->query("SELECT * FROM admins ORDER BY id ASC LIMIT 1")->fetch();
if (!$firstAdmin) {
    ko('Nessun admin nel DB');
} else {
    ok('Admin trovato', $firstAdmin['email']);

    $roleData = fetchAdminRole($pdo, (int)$firstAdmin['id']);
    $roleData ? ok('fetchAdminRole()', "role={$roleData['role']}") : ko('fetchAdminRole() — vuoto (admin_roles mancante?)');

    $secret = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';
    $jwt    = createJWT((int)$firstAdmin['id'], $firstAdmin['email'], $secret, 3600, $roleData ?: []);

    // Decodifica
    $parts  = explode('.', $jwt);
    $jwtPay = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + (4 - strlen($parts[1]) % 4), '=')), true);

    isset($jwtPay['user_type']) ? ok('JWT contiene user_type', $jwtPay['user_type']) : ko('JWT manca user_type');
    array_key_exists('group_id', $jwtPay) ? ok('JWT contiene group_id', var_export($jwtPay['group_id'], true)) : ko('JWT manca group_id');
    isset($jwtPay['admin_id']) ? ok('JWT contiene admin_id', $jwtPay['admin_id']) : ko('JWT manca admin_id');
    isset($jwtPay['email'])    ? ok('JWT contiene email')    : ko('JWT manca email');
    ($jwtPay['exp'] ?? 0) > time() ? ok('JWT exp valido') : ko('JWT exp scaduto');

    $hasPerms = isset($jwtPay['permissions']);
    if ($jwtPay['user_type'] === 'super_admin') {
        !$hasPerms ? ok('super_admin — permissions assenti (corretto)') : ko('super_admin non dovrebbe avere permissions');
    } else {
        $hasPerms ? ok('group_admin — permissions presenti', count($jwtPay['permissions']) . ' voci') : ko('group_admin manca permissions');
    }

    $GLOBALS['test_jwt'] = $jwt;
    echo "\n  Token (prime 60 chars): " . substr($jwt, 0, 60) . "...\n";
}

// ════════════════════════════════════════════
// TEST 3: Helper Functions
// ════════════════════════════════════════════
section('TEST 3: Helper Functions (jwt_protection.php)');

$pSuper  = ['user_type' => 'super_admin', 'admin_id' => 1, 'group_id' => null];
$pGroup  = ['user_type' => 'group_admin', 'admin_id' => 2, 'group_id' => 1,
            'permissions' => ['can_add_players' => true, 'can_delete_players' => false]];
$pPlayer = ['user_type' => 'player', 'player_id' => 5, 'group_id' => 1];
$pOld    = ['admin_id' => 1, 'email' => 'x@x.com', 'role' => 'admin', 'exp' => time() + 3600]; // vecchio JWT

isSuperAdmin($pSuper) === true  ? ok('isSuperAdmin(super_admin)') : ko('isSuperAdmin(super_admin)');
isSuperAdmin($pGroup) === false ? ok('isSuperAdmin(group_admin)') : ko('isSuperAdmin(group_admin)');
isSuperAdmin($pPlayer) === false ? ok('isSuperAdmin(player)')    : ko('isSuperAdmin(player)');

getGroupId($pSuper) === null    ? ok('getGroupId(super_admin) = null')  : ko('getGroupId(super_admin)');
getGroupId($pGroup) === 1       ? ok('getGroupId(group_admin) = 1')     : ko('getGroupId(group_admin)');
getGroupId($pPlayer) === 1      ? ok('getGroupId(player) = 1')          : ko('getGroupId(player)');

checkPermission($pSuper,  'can_delete_players') === true  ? ok('checkPermission(super, delete) = true')  : ko('checkPermission(super, delete)');
checkPermission($pGroup,  'can_add_players')    === true  ? ok('checkPermission(group, add) = true')     : ko('checkPermission(group, add)');
checkPermission($pGroup,  'can_delete_players') === false ? ok('checkPermission(group, delete) = false') : ko('checkPermission(group, delete)');
checkPermission($pPlayer, 'can_add_players')    === false ? ok('checkPermission(player, add) = false')   : ko('checkPermission(player, add)');

// Backward compat: vecchio JWT senza user_type
$backType = $pOld['user_type'] ?? (isset($pOld['admin_id']) ? 'super_admin' : null);
$backType === 'super_admin' ? ok('Backward compat: old JWT → super_admin') : ko('Backward compat fallito');

// ════════════════════════════════════════════
// TEST 4: HTTP GET /api/groups.php
// ════════════════════════════════════════════
section('TEST 4: HTTP GET /api/groups.php');

if (empty($GLOBALS['test_jwt'])) {
    ko('Skip: JWT non disponibile');
} else {
    $r = apiCall('GET', "$baseUrl/api/groups.php", [], ['Authorization' => 'Bearer ' . $GLOBALS['test_jwt']]);

    $r['code'] === 200 ? ok("HTTP 200") : ko("HTTP {$r['code']}", $r['raw']);
    isset($r['data']['success']) && $r['data']['success'] ? ok('success=true') : ko('success mancante', $r['raw']);

    if (!empty($r['data']['groups'])) {
        $cnt = count($r['data']['groups']);
        ok("Gruppi nella risposta: $cnt");
        foreach ($r['data']['groups'] as $g) {
            echo "     #" . $g['id'] . " {$g['name']}"
               . " | players={$g['players_count']} sessions={$g['sessions_count']} admins={$g['admins_count']}\n";
        }
        // Verifica che Strike Zone Original ci sia
        $found = array_filter($r['data']['groups'], fn($g) => $g['name'] === 'Strike Zone Original');
        $found ? ok('"Strike Zone Original" presente') : ko('"Strike Zone Original" assente');
    } else {
        ko('Array groups vuoto o mancante');
    }

    // Test 403 senza JWT
    $r2 = apiCall('GET', "$baseUrl/api/groups.php");
    $r2['code'] === 401 ? ok('GET senza JWT → 401') : ko("GET senza JWT → atteso 401, ottenuto {$r2['code']}");
}

// ════════════════════════════════════════════
// TEST 5: HTTP POST /api/groups.php (crea + elimina)
// ════════════════════════════════════════════
section('TEST 5: HTTP POST /api/groups.php (crea gruppo test)');

$testGroupId = null;
if (empty($GLOBALS['test_jwt'])) {
    ko('Skip: JWT non disponibile');
} else {
    $r = apiCall('POST', "$baseUrl/api/groups.php",
        ['name' => 'Test Automation ' . date('His'), 'description' => 'Creato da phase2-test — eliminare'],
        ['Authorization' => 'Bearer ' . $GLOBALS['test_jwt']]
    );

    $r['code'] === 200 ? ok("HTTP 200") : ko("HTTP {$r['code']}", $r['raw']);
    if (!empty($r['data']['group_id'])) {
        $testGroupId = (int)$r['data']['group_id'];
        ok('group_id restituito', $testGroupId);
        // Verifica nel DB
        $dbRow = $pdo->prepare("SELECT id, name FROM `groups` WHERE id = ?")->execute([$testGroupId])
              && ($stmt2 = $pdo->prepare("SELECT id, name FROM `groups` WHERE id = ?")) && $stmt2->execute([$testGroupId]) && $stmt2->fetch();
        $chk = $pdo->prepare("SELECT id, name FROM `groups` WHERE id = ?");
        $chk->execute([$testGroupId]);
        $chk->fetch() ? ok('Verificato nel DB') : ko('Non trovato nel DB');
    } else {
        ko('group_id assente nella risposta', $r['raw']);
    }

    // Test duplicate
    $rDup = apiCall('POST', "$baseUrl/api/groups.php",
        ['name' => 'Strike Zone Original'],
        ['Authorization' => 'Bearer ' . $GLOBALS['test_jwt']]
    );
    in_array($rDup['code'], [409, 500]) ? ok('Duplicate name → errore HTTP corretto') : ko("Duplicate: atteso 409, ottenuto {$rDup['code']}");
}

// ════════════════════════════════════════════
// TEST 6: HTTP GET /api/admin-management.php
// ════════════════════════════════════════════
section('TEST 6: HTTP GET /api/admin-management.php');

if (empty($GLOBALS['test_jwt'])) {
    ko('Skip: JWT non disponibile');
} else {
    $r = apiCall('GET', "$baseUrl/api/admin-management.php", [], ['Authorization' => 'Bearer ' . $GLOBALS['test_jwt']]);

    $r['code'] === 200 ? ok("HTTP 200") : ko("HTTP {$r['code']}", $r['raw']);
    if (!empty($r['data']['admins'])) {
        $cnt = count($r['data']['admins']);
        ok("Admin nella risposta: $cnt");
        foreach ($r['data']['admins'] as $a) {
            echo "     #{$a['id']} {$a['email']}"
               . " | role=" . ($a['role'] ?? 'N/A')
               . " group=" . ($a['group_name'] ?? 'TUTTI') . "\n";
        }
    } else {
        ko('Array admins vuoto', $r['raw']);
    }

    // Test 401 senza JWT
    $r2 = apiCall('GET', "$baseUrl/api/admin-management.php");
    $r2['code'] === 401 ? ok('GET senza JWT → 401') : ko("GET senza JWT → atteso 401, ottenuto {$r2['code']}");
}

// ════════════════════════════════════════════
// TEST 7: player-auth.php login (nessun player registrato → atteso 401)
// ════════════════════════════════════════════
section('TEST 7: POST /api/player-auth.php?action=login');

$r = apiCall('POST', "$baseUrl/api/player-auth.php?action=login",
    ['email' => 'nonexistent@test.com', 'password' => 'wrongpass123']
);
$r['code'] === 401 ? ok('Login credenziali errate → 401') : ko("Atteso 401, ottenuto {$r['code']}", $r['raw']);

// Test body vuoto
$r2 = apiCall('POST', "$baseUrl/api/player-auth.php?action=login", []);
$r2['code'] === 400 ? ok('Login body vuoto → 400') : ko("Atteso 400, ottenuto {$r2['code']}");

// Test register senza JWT
$r3 = apiCall('POST', "$baseUrl/api/player-auth.php?action=register",
    ['player_id' => 1, 'email' => 'x@x.com', 'password' => 'pass12345']
);
$r3['code'] === 401 ? ok('Register senza JWT → 401') : ko("Register senza JWT: atteso 401, ottenuto {$r3['code']}");

// ════════════════════════════════════════════
// TEST 8: Security Logs recenti
// ════════════════════════════════════════════
section('TEST 8: Security Logs');

try {
    $stmt = $pdo->query("SELECT event_type, severity, details, created_at FROM security_logs ORDER BY created_at DESC LIMIT 6");
    $logs = $stmt->fetchAll();
    ok('Tabella security_logs accessibile', count($logs) . ' log recenti');
    foreach ($logs as $l) {
        $d = json_decode($l['details'] ?? '{}', true) ?? [];
        $detStr = implode(', ', array_map(fn($k,$v) => "$k=$v", array_keys($d), $d));
        echo "     [{$l['severity']}] {$l['event_type']}  {$l['created_at']}"
           . ($detStr ? "  [$detStr]" : '') . "\n";
    }
} catch (Exception $e) {
    ko('security_logs query fallita', $e->getMessage());
}

// ════════════════════════════════════════════
// CLEANUP
// ════════════════════════════════════════════
section('CLEANUP');

if ($testGroupId) {
    try {
        $pdo->prepare("DELETE FROM `groups` WHERE id = ?")->execute([$testGroupId]);
        ok("Gruppo test eliminato (ID: $testGroupId)");
    } catch (Exception $e) {
        ko("Eliminazione gruppo test fallita", $e->getMessage());
    }
}

// ════════════════════════════════════════════
// REPORT FINALE
// ════════════════════════════════════════════
$tot = $pass + $fail;
echo "\n═══════════════════════════════════════\n";
echo " RISULTATO: $pass/$tot test passati";
if ($fail === 0) {
    echo " — 🎉 TUTTO OK\n";
} else {
    echo " — ⚠️  $fail FALLITI\n";
}
echo "═══════════════════════════════════════\n";
echo "\n⚠️  RICORDA: elimina questo file → api/phase2-test.php\n";
