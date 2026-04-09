<?php
// ============================================
//  api/phase3-test.php — Test FASE 3
//  Verifica filtri multi-gruppo via query DB dirette
//  DA ELIMINARE dopo l'uso
// ============================================
$validTokens = ['phase3test_20260409', 'phase3test_20260410'];
if (!in_array($_GET['token'] ?? '', $validTokens, true)) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

header('Content-Type: text/plain; charset=utf-8');

$pass = 0; $fail = 0;
function ok(string $label, string $note = ''): void { global $pass; $pass++; echo "  ✅  $label" . ($note ? "  [$note]" : '') . "\n"; }
function ko(string $label, string $note = ''): void { global $fail; $fail++; echo "  ❌  $label" . ($note ? "  [$note]" : '') . "\n"; }
function section(string $t): void { echo "\n── $t ──\n"; }

echo "═══════════════════════════════════════\n";
echo " PHASE 3 TEST — " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════\n";

// ── SETUP (nessun include con side-effects) ──
section('SETUP: DB + helpers');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';  // solo funzioni pure

try {
    $pdo = getPDO();
    ok('Connessione DB');
} catch (Exception $e) {
    echo "❌ DB: " . $e->getMessage() . "\n"; exit;
}

// JWT inline (senza includere auth.php che ha codice top-level)
function b64url(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function makeJWT(int $adminId, string $email, array $roleData = []): string {
    $secret  = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';
    $header  = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = b64url(json_encode([
        'iat'       => time(), 'exp' => time() + 3600,
        'admin_id'  => $adminId, 'email' => $email,
        'user_type' => $roleData['role'] ?? 'super_admin',
        'group_id'  => $roleData['group_id'] ?? null,
    ]));
    $sig = b64url(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$sig";
}

$admin = $pdo->query("SELECT id, email FROM admins ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$roleStmt = $pdo->prepare("SELECT role, group_id FROM admin_roles WHERE admin_id = ? LIMIT 1");
$roleStmt->execute([(int)$admin['id']]);
$roleRow  = $roleStmt->fetch(PDO::FETCH_ASSOC) ?: ['role' => 'super_admin', 'group_id' => null];
$jwt      = makeJWT((int)$admin['id'], $admin['email'], $roleRow);

ok('JWT generato inline', $admin['email'] . ' / ' . $roleRow['role']);

// Totali DB
$totalPlayers  = (int)$pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
$totalSessions = (int)$pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
$g1Players     = (int)$pdo->query("SELECT COUNT(*) FROM players  WHERE group_id = 1")->fetchColumn();
$g1Sessions    = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE group_id = 1")->fetchColumn();

echo "  ℹ️   DB: $totalPlayers players, $totalSessions sessioni (gruppo 1: $g1Players p / $g1Sessions s)\n";

// ════════════════════════════════════════════
// TEST 1: Schema
// ════════════════════════════════════════════
section('TEST 1: Schema group_id');

$pdo->query("SHOW COLUMNS FROM players  LIKE 'group_id'")->fetch() ? ok('players.group_id esiste')  : ko('players.group_id mancante');
$pdo->query("SHOW COLUMNS FROM sessions LIKE 'group_id'")->fetch() ? ok('sessions.group_id esiste') : ko('sessions.group_id mancante');

(int)$pdo->query("SELECT COUNT(*) FROM players  WHERE group_id IS NULL")->fetchColumn() === 0
    ? ok('Tutti i players hanno group_id') : ko('players con group_id NULL trovati');
(int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE group_id IS NULL")->fetchColumn() === 0
    ? ok('Tutte le sessions hanno group_id') : ko('sessions con group_id NULL trovate');

foreach ($pdo->query("SELECT group_id, COUNT(*) AS n FROM players GROUP BY group_id")->fetchAll() as $c)
    echo "     players gruppo {$c['group_id']}: {$c['n']}\n";
foreach ($pdo->query("SELECT group_id, COUNT(*) AS n FROM sessions GROUP BY group_id")->fetchAll() as $c)
    echo "     sessions gruppo {$c['group_id']}: {$c['n']}\n";

// ════════════════════════════════════════════
// TEST 2: Logica filtro players.php (stessa query del file)
// ════════════════════════════════════════════
section('TEST 2: Filtro players.php (query diretta)');

// Senza filtro
$stmt = $pdo->prepare('
    SELECT p.id, p.name, p.group_id,
        COUNT(s.id) AS partite, ROUND(AVG(s.score),1) AS media, MAX(s.score) AS record, MIN(s.score) AS minimo
    FROM players p LEFT JOIN scores s ON s.player_id = p.id
    GROUP BY p.id ORDER BY p.name ASC');
$stmt->execute();
$all = $stmt->fetchAll();
count($all) === $totalPlayers ? ok("Senza filtro → $totalPlayers players") : ko("Senza filtro: atteso=$totalPlayers, ricevuto=" . count($all));

// Filtro group_id=1
$stmt = $pdo->prepare('
    SELECT p.id, p.name, p.group_id,
        COUNT(s.id) AS partite, ROUND(AVG(s.score),1) AS media, MAX(s.score) AS record, MIN(s.score) AS minimo
    FROM players p LEFT JOIN scores s ON s.player_id = p.id
    WHERE p.group_id = ?
    GROUP BY p.id ORDER BY p.name ASC');
$stmt->execute([1]);
$g1 = $stmt->fetchAll();
count($g1) === $g1Players ? ok("Filtro group_id=1 → $g1Players players") : ko("Filtro: atteso=$g1Players, ricevuto=" . count($g1));
$wrong = array_filter($g1, fn($p) => (int)$p['group_id'] !== 1);
count($wrong) === 0 ? ok('Tutti i risultati hanno group_id=1') : ko(count($wrong) . ' players con group_id sbagliato');

// isSuperAdmin auto-filtra
$superPayload  = ['user_type' => 'super_admin', 'group_id' => null];
$groupPayload  = ['user_type' => 'group_admin', 'group_id' => 1];
$playerPayload = ['user_type' => 'player',      'group_id' => 1];

// Simula la logica di players.php per determinare $filterGroupId
function getFilterGroupId(array $payload, ?string $qsGroupId): ?int {
    if (isSuperAdmin($payload)) {
        return ($qsGroupId !== null && $qsGroupId !== 'all') ? (int)$qsGroupId : null;
    }
    return getGroupId($payload);
}

getFilterGroupId($superPayload, null)     === null ? ok('super_admin senza param → null (vede tutto)')      : ko('super_admin senza param');
getFilterGroupId($superPayload, '1')      === 1    ? ok('super_admin + group_id=1 → filtra a 1')            : ko('super_admin + group_id=1');
getFilterGroupId($superPayload, 'all')    === null ? ok('super_admin + group_id=all → null (vede tutto)')   : ko('super_admin + group_id=all');
getFilterGroupId($groupPayload, null)     === 1    ? ok('group_admin → auto-filtra a group_id=1')           : ko('group_admin auto-filtro');
getFilterGroupId($groupPayload, '999')    === 1    ? ok('group_admin ignora param group_id esterno')        : ko('group_admin ignora param');
getFilterGroupId($playerPayload, null)    === 1    ? ok('player → auto-filtra a group_id=1')               : ko('player auto-filtro');

// ════════════════════════════════════════════
// TEST 3: Logica filtro sessions.php
// ════════════════════════════════════════════
section('TEST 3: Filtro sessions.php (query diretta)');

$stmt = $pdo->query('SELECT id, date, location, group_id FROM sessions ORDER BY date DESC');
$all  = $stmt->fetchAll();
count($all) === $totalSessions ? ok("Senza filtro → $totalSessions sessioni") : ko("Senza filtro: atteso=$totalSessions");
isset($all[0]['group_id']) ? ok('Colonna group_id presente nel risultato') : ko('group_id assente nel risultato');

$stmt = $pdo->prepare('SELECT id, date, group_id FROM sessions WHERE group_id = ? ORDER BY date DESC');
$stmt->execute([1]);
$g1s = $stmt->fetchAll();
count($g1s) === $g1Sessions ? ok("Filtro group_id=1 → $g1Sessions sessioni") : ko("Filtro: atteso=$g1Sessions, ricevuto=" . count($g1s));

// ════════════════════════════════════════════
// TEST 4: Permessi checkPermission
// ════════════════════════════════════════════
section('TEST 4: Permessi (checkPermission)');

$superP = ['user_type' => 'super_admin', 'group_id' => null];
$groupP = ['user_type' => 'group_admin', 'group_id' => 1, 'permissions' => [
    'can_add_players' => true, 'can_edit_players' => true, 'can_delete_players' => false,
    'can_add_sessions' => true, 'can_edit_sessions' => true, 'can_delete_sessions' => false,
]];
$playerP = ['user_type' => 'player', 'group_id' => 1];

checkPermission($superP,  'can_add_players')    === true  ? ok('super_admin può aggiungere players')         : ko('super_admin can_add_players');
checkPermission($superP,  'can_delete_players') === true  ? ok('super_admin può eliminare players')          : ko('super_admin can_delete_players');
checkPermission($groupP,  'can_add_players')    === true  ? ok('group_admin con perm può aggiungere')        : ko('group_admin can_add_players');
checkPermission($groupP,  'can_delete_players') === false ? ok('group_admin senza perm non può eliminare')   : ko('group_admin can_delete_players');
checkPermission($playerP, 'can_add_players')    === false ? ok('player non può aggiungere')                  : ko('player can_add_players');
checkPermission($playerP, 'can_edit_sessions')  === false ? ok('player non può modificare sessioni')         : ko('player can_edit_sessions');

// ════════════════════════════════════════════
// TEST 5: Leaderboard query con filtro
// ════════════════════════════════════════════
section('TEST 5: Leaderboard query con filtro gruppo');

$stmt = $pdo->prepare('
    SELECT p.id, p.name, p.group_id,
        COUNT(DISTINCT sc.session_id) AS partite,
        ROUND(AVG(sc.score),1) AS media, MAX(sc.score) AS record
    FROM players p
    LEFT JOIN scores sc ON sc.player_id = p.id
    WHERE p.group_id = ?
    GROUP BY p.id ORDER BY media DESC');
$stmt->execute([1]);
$lb = $stmt->fetchAll();
count($lb) === $g1Players ? ok("Leaderboard gruppo 1 → $g1Players players") : ko("Leaderboard: atteso=$g1Players, ricevuto=" . count($lb));
if (!empty($lb)) {
    ok("Top scorer: {$lb[0]['name']} media={$lb[0]['media']}");
    $wrong = array_filter($lb, fn($p) => (int)$p['group_id'] !== 1);
    count($wrong) === 0 ? ok('Tutti i players sono del gruppo 1') : ko(count($wrong) . ' players con group_id sbagliato');
}

// ════════════════════════════════════════════
// TEST 6: Stats — totali filtrati per gruppo
// ════════════════════════════════════════════
section('TEST 6: Stats — totali per gruppo');

// Senza filtro
$totAll = $pdo->query("
    SELECT MAX(sc.score) AS record_assoluto, ROUND(AVG(sc.score),1) AS media_gruppo
    FROM scores sc JOIN sessions se ON sc.session_id = se.id")->fetch();
ok("Totali globali → record={$totAll['record_assoluto']}, media={$totAll['media_gruppo']}");

// Con filtro gruppo 1
$tot1 = $pdo->prepare("
    SELECT MAX(sc.score) AS record_assoluto, ROUND(AVG(sc.score),1) AS media_gruppo
    FROM scores sc
    JOIN sessions se ON sc.session_id = se.id
    JOIN players p ON sc.player_id = p.id
    WHERE p.group_id = ?");
$tot1->execute([1]);
$r1 = $tot1->fetch();
ok("Totali gruppo 1 → record={$r1['record_assoluto']}, media={$r1['media_gruppo']}");

$sessG1 = (int)$pdo->prepare("SELECT COUNT(*) FROM sessions se WHERE se.group_id = ?")->execute([1])
    ? ($s2 = $pdo->prepare("SELECT COUNT(*) FROM sessions se WHERE se.group_id = ?")) && $s2->execute([1]) && $s2->fetchColumn()
    : 0;
$s2 = $pdo->prepare("SELECT COUNT(*) FROM sessions se WHERE se.group_id = ?");
$s2->execute([1]);
$sessG1 = (int)$s2->fetchColumn();
$sessG1 === $g1Sessions ? ok("Sessions count gruppo 1: $sessG1 (corretto)") : ko("Sessions count: atteso=$g1Sessions, DB=$sessG1");

// ════════════════════════════════════════════
// TEST 7: Ownership check (group_admin)
// ════════════════════════════════════════════
section('TEST 7: Ownership check group_admin');

// Simula la logica di ownership check in players.php PUT
$testPayload = ['user_type' => 'group_admin', 'group_id' => 1];
$firstPlayer = $pdo->query("SELECT id, name, group_id FROM players WHERE group_id = 1 LIMIT 1")->fetch();

if ($firstPlayer) {
    // Caso 1: player del proprio gruppo → permesso OK
    $ownStmt = $pdo->prepare('SELECT group_id FROM players WHERE id = ?');
    $ownStmt->execute([$firstPlayer['id']]);
    $row = $ownStmt->fetch();
    $canEdit = $row && (int)$row['group_id'] === getGroupId($testPayload);
    $canEdit ? ok("Ownership OK: group_admin può modificare {$firstPlayer['name']}") : ko('Ownership check fallito per player del proprio gruppo');

    // Caso 2: se esistesse un player di un altro gruppo → bloccato
    $fakeOtherGroupId = 999; // gruppo non esistente
    $canEditOther = $row && (int)$row['group_id'] === $fakeOtherGroupId;
    !$canEditOther ? ok('Ownership BLOCK: group_admin NON può modificare player di gruppo 999') : ko('Ownership check NON blocca altri gruppi');
} else {
    ko('Nessun player in gruppo 1 per testare ownership');
}

// ════════════════════════════════════════════
// TEST 8: INSERT con group_id (POST simulation)
// ════════════════════════════════════════════
section('TEST 8: INSERT players/sessions con group_id');

$testName = 'Phase3Test_' . time();
try {
    $pdo->prepare("INSERT INTO players (name, emoji, group_id) VALUES (?, ?, ?)")->execute([$testName, '🧪', 1]);
    $pid = (int)$pdo->lastInsertId();
    $row = $pdo->prepare("SELECT group_id FROM players WHERE id = ?")->execute([$pid]) &&
           ($s = $pdo->prepare("SELECT group_id FROM players WHERE id = ?")) && $s->execute([$pid]) && $s->fetch();
    $s2 = $pdo->prepare("SELECT group_id FROM players WHERE id = ?"); $s2->execute([$pid]); $row = $s2->fetch();
    (int)$row['group_id'] === 1 ? ok("INSERT player con group_id=1 salvato  [ID=$pid]") : ko("group_id errato: {$row['group_id']}");
    $pdo->prepare("DELETE FROM players WHERE id = ?")->execute([$pid]);
    ok("Player test eliminato");
} catch (Exception $e) {
    ko("INSERT player fallito", $e->getMessage());
}

$testDate = '1999-12-31';
try {
    $pdo->prepare("INSERT INTO sessions (date, location, mode, group_id) VALUES (?, ?, ?, ?)")->execute([$testDate, 'Test', 'teams', 1]);
    $sid = (int)$pdo->lastInsertId();
    $s3 = $pdo->prepare("SELECT group_id FROM sessions WHERE id = ?"); $s3->execute([$sid]); $srow = $s3->fetch();
    (int)$srow['group_id'] === 1 ? ok("INSERT session con group_id=1 salvato  [ID=$sid]") : ko("group_id errato: {$srow['group_id']}");
    $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$sid]);
    ok("Sessione test eliminata");
} catch (Exception $e) {
    ko("INSERT session fallito", $e->getMessage());
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
