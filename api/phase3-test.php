<?php
// ============================================
//  api/phase3-test.php — Test FASE 3
//  Verifica filtri multi-gruppo
//  DA ELIMINARE dopo l'uso
// ============================================
if (($_GET['token'] ?? '') !== 'phase3test_' . date('Ymd')) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

header('Content-Type: text/plain; charset=utf-8');

$pass = 0; $fail = 0;

function ok(string $label, string $note = ''): void {
    global $pass; $pass++;
    echo "  ✅  $label" . ($note ? "  [$note]" : '') . "\n";
}
function ko(string $label, string $note = ''): void {
    global $fail; $fail++;
    echo "  ❌  $label" . ($note ? "  [$note]" : '') . "\n";
}
function section(string $t): void { echo "\n── $t ──\n"; }

$baseUrl = 'https://web-production-e43fd.up.railway.app';

function curl(string $method, string $url, array $body = [], array $headers = []): array {
    $hdrs = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) $hdrs[] = "$k: $v";
    $cmd = 'curl -s -w "\nHTTP_CODE:%{http_code}" -X ' . escapeshellarg($method)
        . ' ' . escapeshellarg($url)
        . ' -H ' . implode(' -H ', array_map('escapeshellarg', $hdrs))
        . (!empty($body) ? ' -d ' . escapeshellarg(json_encode($body)) : '')
        . ' --max-time 10';
    $raw  = shell_exec($cmd) ?? '';
    $code = 0;
    if (preg_match('/\nHTTP_CODE:(\d+)$/', $raw, $m)) {
        $code = (int)$m[1];
        $raw  = substr($raw, 0, strrpos($raw, "\nHTTP_CODE:"));
    }
    return ['code' => $code, 'data' => json_decode($raw, true), 'raw' => $raw];
}

echo "═══════════════════════════════════════\n";
echo " PHASE 3 TEST — " . date('Y-m-d H:i:s') . "\n";
echo " Host: $baseUrl\n";
echo "═══════════════════════════════════════\n";

// ════════════════════════════════════════════
// SETUP
// ════════════════════════════════════════════
section('SETUP: DB + JWT');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';

try {
    $pdo = getPDO();
    ok('Connessione DB');
} catch (Exception $e) {
    echo "❌ DB fallito: " . $e->getMessage() . "\n";
    exit;
}

// Genera JWT super_admin
require_once __DIR__ . '/auth.php';
$admin   = $pdo->query("SELECT * FROM admins ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$role    = fetchAdminRole($pdo, (int)$admin['id']);
$secret  = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';
$jwt     = createJWT((int)$admin['id'], $admin['email'], $secret, 3600, $role);
ok('JWT generato', $admin['email'] . ' / ' . ($role['role'] ?? '?'));

// ════════════════════════════════════════════
// TEST 1: Schema — colonne group_id presenti
// ════════════════════════════════════════════
section('TEST 1: Schema group_id');

$colPlayers  = $pdo->query("SHOW COLUMNS FROM players  LIKE 'group_id'")->fetch();
$colSessions = $pdo->query("SHOW COLUMNS FROM sessions LIKE 'group_id'")->fetch();

$colPlayers  ? ok('players.group_id esiste')  : ko('players.group_id mancante');
$colSessions ? ok('sessions.group_id esiste') : ko('sessions.group_id mancante');

$nullP = $pdo->query("SELECT COUNT(*) FROM players  WHERE group_id IS NULL")->fetchColumn();
$nullS = $pdo->query("SELECT COUNT(*) FROM sessions WHERE group_id IS NULL")->fetchColumn();

(int)$nullP === 0 ? ok('Tutti i players hanno group_id')  : ko("$nullP players senza group_id");
(int)$nullS === 0 ? ok('Tutte le sessions hanno group_id') : ko("$nullS sessions senza group_id");

// Conta per gruppo
$counts = $pdo->query("SELECT group_id, COUNT(*) AS n FROM players GROUP BY group_id")->fetchAll();
foreach ($counts as $c) {
    echo "     players gruppo {$c['group_id']}: {$c['n']}\n";
}
$scounts = $pdo->query("SELECT group_id, COUNT(*) AS n FROM sessions GROUP BY group_id")->fetchAll();
foreach ($scounts as $c) {
    echo "     sessions gruppo {$c['group_id']}: {$c['n']}\n";
}

// ════════════════════════════════════════════
// TEST 2: HTTP GET /api/players.php (senza JWT → tutti)
// ════════════════════════════════════════════
section('TEST 2: GET /api/players.php');

$totalPlayers = (int)$pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
$g1Players    = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE group_id = 1")->fetchColumn();

// Senza JWT
$r = curl('GET', "$baseUrl/api/players.php");
if ($r['code'] === 200 && is_array($r['data'])) {
    $cnt = count($r['data']);
    ok("Senza JWT → HTTP 200");
    $cnt === $totalPlayers
        ? ok("Count corretto  [$cnt players]")
        : ko("Count errato: atteso=$totalPlayers, ricevuto=$cnt");
} else {
    ko("Senza JWT fallito  [HTTP {$r['code']}]", substr($r['raw'], 0, 100));
}

// Con JWT, ?group_id=1
$r = curl('GET', "$baseUrl/api/players.php?group_id=1", [], ['Authorization' => "Bearer $jwt"]);
if ($r['code'] === 200 && is_array($r['data'])) {
    $cnt = count($r['data']);
    ok("Con JWT + group_id=1 → HTTP 200");
    $cnt === $g1Players
        ? ok("Filtro gruppo funziona  [$cnt / $totalPlayers players]")
        : ko("Filtro errato: DB=$g1Players, API=$cnt");
    // Verifica che ogni player abbia group_id=1
    $wrong = array_filter($r['data'], fn($p) => ($p['group_id'] ?? null) != 1);
    count($wrong) === 0 ? ok('Tutti i players sono del gruppo 1') : ko(count($wrong) . ' players con group_id sbagliato');
} else {
    ko("Con JWT + group_id=1 fallito  [HTTP {$r['code']}]", substr($r['raw'], 0, 100));
}

// Con JWT, ?group_id=all → deve restituire tutti
$r = curl('GET', "$baseUrl/api/players.php?group_id=all", [], ['Authorization' => "Bearer $jwt"]);
if ($r['code'] === 200 && is_array($r['data'])) {
    $cnt = count($r['data']);
    $cnt === $totalPlayers
        ? ok("group_id=all → tutti  [$cnt players]")
        : ko("group_id=all errato: atteso=$totalPlayers, ricevuto=$cnt");
} else {
    ko("group_id=all fallito  [HTTP {$r['code']}]");
}

// ════════════════════════════════════════════
// TEST 3: HTTP GET /api/sessions.php (senza JWT → tutte)
// ════════════════════════════════════════════
section('TEST 3: GET /api/sessions.php');

$totalSessions = (int)$pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
$g1Sessions    = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE group_id = 1")->fetchColumn();

// Senza JWT
$r = curl('GET', "$baseUrl/api/sessions.php");
if ($r['code'] === 200 && is_array($r['data'])) {
    $cnt = count($r['data']);
    ok("Senza JWT → HTTP 200  [$cnt sessions]");
    $cnt === $totalSessions
        ? ok('Count corretto')
        : ko("Count errato: atteso=$totalSessions, ricevuto=$cnt");
    // Verifica che group_id sia presente in ogni sessione
    $hasGroupId = isset($r['data'][0]['group_id']);
    $hasGroupId ? ok('group_id presente nelle sessioni') : ko('group_id assente nelle sessioni');
} else {
    ko("Senza JWT fallito  [HTTP {$r['code']}]");
}

// Con JWT + group_id=1
$r = curl('GET', "$baseUrl/api/sessions.php?group_id=1", [], ['Authorization' => "Bearer $jwt"]);
if ($r['code'] === 200 && is_array($r['data'])) {
    $cnt = count($r['data']);
    ok("Con JWT + group_id=1 → HTTP 200");
    $cnt === $g1Sessions
        ? ok("Filtro sessioni funziona  [$cnt / $totalSessions sessioni]")
        : ko("Filtro errato: DB=$g1Sessions, API=$cnt");
} else {
    ko("Con JWT + group_id=1 fallito  [HTTP {$r['code']}]");
}

// ════════════════════════════════════════════
// TEST 4: POST /api/players.php — permessi
// ════════════════════════════════════════════
section('TEST 4: POST /api/players.php (permessi)');

// Senza JWT → 401
$r = curl('POST', "$baseUrl/api/players.php", ['name' => 'TestX', 'emoji' => '🧪']);
$r['code'] === 401 ? ok('POST senza JWT → 401') : ko("POST senza JWT: atteso 401, ottenuto {$r['code']}");

// Con JWT super_admin → 201
$testName = 'Phase3Test_' . time();
$r = curl('POST', "$baseUrl/api/players.php",
    ['name' => $testName, 'emoji' => '🧪', 'group_id' => 1],
    ['Authorization' => "Bearer $jwt"]
);
$testPlayerId = null;
if ($r['code'] === 201 && !empty($r['data']['id'])) {
    $testPlayerId = (int)$r['data']['id'];
    ok("POST super_admin → 201  [ID=$testPlayerId]");
    // Verifica group_id nel DB
    $row = $pdo->prepare("SELECT group_id FROM players WHERE id = ?")->execute([$testPlayerId])
        ? ($s = $pdo->prepare("SELECT group_id FROM players WHERE id = ?")) && $s->execute([$testPlayerId]) && $s->fetch()
        : false;
    $chk = $pdo->prepare("SELECT group_id FROM players WHERE id = ?");
    $chk->execute([$testPlayerId]);
    $dbRow = $chk->fetch();
    $dbRow && (int)$dbRow['group_id'] === 1 ? ok('group_id=1 salvato nel DB') : ko('group_id errato nel DB');
} else {
    ko("POST super_admin fallito  [HTTP {$r['code']}]", substr($r['raw'], 0, 100));
}

// ════════════════════════════════════════════
// TEST 5: DELETE /api/players.php — permessi
// ════════════════════════════════════════════
section('TEST 5: DELETE /api/players.php (permessi)');

// Senza JWT → 401
$r = curl('DELETE', "$baseUrl/api/players.php", ['id' => 999]);
$r['code'] === 401 ? ok('DELETE senza JWT → 401') : ko("DELETE senza JWT: atteso 401, ottenuto {$r['code']}");

// Cleanup player test
if ($testPlayerId) {
    $r = curl('DELETE', "$baseUrl/api/players.php",
        ['id' => $testPlayerId],
        ['Authorization' => "Bearer $jwt"]
    );
    $r['code'] === 200 ? ok("DELETE player test → 200  [ID=$testPlayerId]") : ko("DELETE player test fallito  [HTTP {$r['code']}]");
}

// ════════════════════════════════════════════
// TEST 6: GET /api/leaderboard.php (filtro gruppo)
// ════════════════════════════════════════════
section('TEST 6: GET /api/leaderboard.php');

// Senza filtro → tutti
$r = curl('GET', "$baseUrl/api/leaderboard.php");
if ($r['code'] === 200 && is_array($r['data'])) {
    $cnt = count($r['data']);
    ok("Senza filtro → HTTP 200  [$cnt players]");
    $cnt === $totalPlayers ? ok('Count corretto') : ko("Count errato: atteso=$totalPlayers, ricevuto=$cnt");
} else {
    ko("Leaderboard senza filtro fallito  [HTTP {$r['code']}]", substr($r['raw'], 0, 100));
}

// ?group_id=1
$r = curl('GET', "$baseUrl/api/leaderboard.php?group_id=1");
if ($r['code'] === 200 && is_array($r['data'])) {
    $cnt = count($r['data']);
    ok("group_id=1 → HTTP 200  [$cnt players]");
    $cnt === $g1Players ? ok("Filtro corretto  [$cnt / $totalPlayers players]") : ko("Filtro errato: atteso=$g1Players, ricevuto=$cnt");
    // Verifica group_id nei risultati
    $wrong = array_filter($r['data'], fn($p) => isset($p['group_id']) && (int)$p['group_id'] !== 1);
    count($wrong) === 0 ? ok('Tutti i players hanno group_id=1') : ko(count($wrong) . ' players con group_id sbagliato');
} else {
    ko("Leaderboard group_id=1 fallito  [HTTP {$r['code']}]");
}

// ════════════════════════════════════════════
// TEST 7: GET /api/stats.php (filtro gruppo)
// ════════════════════════════════════════════
section('TEST 7: GET /api/stats.php');

// Senza filtro
$r = curl('GET', "$baseUrl/api/stats.php");
if ($r['code'] === 200 && isset($r['data']['totale_sessioni'])) {
    ok("Senza filtro → HTTP 200");
    ok("totale_sessioni={$r['data']['totale_sessioni']}, record={$r['data']['record_assoluto']}");
    $cnt = count($r['data']['leaderboard'] ?? []);
    $cnt === $totalPlayers ? ok("leaderboard count corretto  [$cnt players]") : ko("leaderboard: atteso=$totalPlayers, ricevuto=$cnt");
} else {
    ko("Stats senza filtro fallito  [HTTP {$r['code']}]", substr($r['raw'], 0, 150));
}

// ?group_id=1
$r = curl('GET', "$baseUrl/api/stats.php?group_id=1");
if ($r['code'] === 200 && isset($r['data']['totale_sessioni'])) {
    ok("group_id=1 → HTTP 200");
    ok("totale_sessioni={$r['data']['totale_sessioni']} (DB: $g1Sessions)");
    (int)$r['data']['totale_sessioni'] === $g1Sessions
        ? ok('Sessions count corretto per gruppo 1')
        : ko("Sessions mismatch: atteso=$g1Sessions, ricevuto={$r['data']['totale_sessioni']}");
    $cnt = count($r['data']['leaderboard'] ?? []);
    $cnt === $g1Players ? ok("leaderboard gruppo 1 corretto  [$cnt players]") : ko("leaderboard: atteso=$g1Players, ricevuto=$cnt");
} else {
    ko("Stats group_id=1 fallito  [HTTP {$r['code']}]", substr($r['raw'], 0, 150));
}

// ════════════════════════════════════════════
// TEST 8: POST /api/sessions.php — group_id assegnato
// ════════════════════════════════════════════
section('TEST 8: POST /api/sessions.php (group_id)');

// Senza JWT → 401
$r = curl('POST', "$baseUrl/api/sessions.php", ['date' => date('Y-m-d')]);
$r['code'] === 401 ? ok('POST senza JWT → 401') : ko("POST senza JWT: atteso 401, ottenuto {$r['code']}");

// Con JWT → sessione creata con group_id corretto
$testDate = '1999-01-01';
$r = curl('POST', "$baseUrl/api/sessions.php",
    ['date' => $testDate, 'location' => 'Test Phase3', 'mode' => 'teams'],
    ['Authorization' => "Bearer $jwt"]
);
$testSessionId = null;
if ($r['code'] === 201 && !empty($r['data']['session_id'])) {
    $testSessionId = (int)$r['data']['session_id'];
    ok("POST sessione → 201  [ID=$testSessionId]");
    $s = $pdo->prepare("SELECT group_id FROM sessions WHERE id = ?");
    $s->execute([$testSessionId]);
    $dbRow = $s->fetch();
    $dbRow && (int)$dbRow['group_id'] === 1 ? ok('group_id=1 salvato correttamente') : ko('group_id errato nel DB');
} else {
    ko("POST sessione fallito  [HTTP {$r['code']}]", substr($r['raw'], 0, 100));
}

// ── CLEANUP ──
section('CLEANUP');

if ($testSessionId) {
    $r = curl('DELETE', "$baseUrl/api/sessions.php",
        ['id' => $testSessionId],
        ['Authorization' => "Bearer $jwt"]
    );
    $r['code'] === 200 ? ok("Sessione test eliminata  [ID=$testSessionId]") : ko("Delete sessione fallita  [HTTP {$r['code']}]");
}

// ════════════════════════════════════════════
// REPORT
// ════════════════════════════════════════════
$tot = $pass + $fail;
echo "\n═══════════════════════════════════════\n";
echo " RISULTATO: $pass/$tot test passati";
echo $fail === 0 ? " — TUTTO OK\n" : " — $fail FALLITI\n";
echo "═══════════════════════════════════════\n";
echo "\n ELIMINA QUESTO FILE: api/phase3-test.php\n";
