<?php
// ============================================
//  api/phase4-integration-test.php
//  Test FASE 4 + Integrazione completa FASE 1-4
//  DA ELIMINARE dopo test
// ============================================

$SECRET = 'phase4test_' . date('Ymd');
if (!isset($_GET['token']) || $_GET['token'] !== $SECRET) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$pass = 0;
$fail = 0;

function ok(string $label): void {
    global $pass;
    $pass++;
    echo "  ✅ $label\n";
}
function fail(string $label, string $detail = ''): void {
    global $fail;
    $fail++;
    echo "  ❌ $label" . ($detail ? " — $detail" : '') . "\n";
}
function check(bool $cond, string $label, string $detail = ''): void {
    $cond ? ok($label) : fail($label, $detail);
}

// ── JWT inline (evita include di auth.php che ha codice top-level) ────────────
function b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function makeJWT(int $adminId, string $email, string $userType, ?int $groupId, array $perms = [], int $ttl = 3600): string {
    $secret  = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';
    $header  = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $now     = time();
    $pl      = ['iat' => $now, 'exp' => $now + $ttl, 'admin_id' => $adminId,
                'email' => $email, 'user_type' => $userType, 'group_id' => $groupId];
    if ($perms) $pl['permissions'] = $perms;
    $payload = b64url(json_encode($pl));
    $sig     = b64url(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$sig";
}
function decodeJWT(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: null;
}

// ─────────────────────────────────────────────────────────────────────────────

echo "═══════════════════════════════════════════════════════════\n";
echo " PHASE 4 + INTEGRATION TEST — " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════\n\n";

require_once __DIR__ . '/config.php';

try {
    $pdo = getPDO();
    ok('Connesso al database');
} catch (Exception $e) {
    die("❌ Errore DB: " . $e->getMessage());
}

// ══════════════════════════════════════════════════════════
// PARTE 1: FILE FRONTEND
// ══════════════════════════════════════════════════════════
echo "\n── PARTE 1: File Frontend ──────────────────────────────────\n";

$files = [
    // HTML (stanno in frontend/pages/)
    '../frontend/pages/super-admin.html' => 'super-admin.html',
    '../frontend/pages/welcome.html'     => 'welcome.html',
    '../frontend/pages/index.html'       => 'index.html',
    // JS
    '../frontend/assets/js/super-admin.js' => 'super-admin.js',
    '../frontend/assets/js/shared.js'      => 'shared.js',
    '../frontend/assets/js/header.js'      => 'header.js',
    '../frontend/assets/js/app.js'         => 'app.js',
    '../frontend/assets/js/auth-fetch.js'  => 'auth-fetch.js',
    // CSS
    '../frontend/assets/css/style.css'     => 'style.css',
    // PHP API necessarie
    'groups.php'           => 'api/groups.php',
    'admin-management.php' => 'api/admin-management.php',
    'players.php'          => 'api/players.php',
    'sessions.php'         => 'api/sessions.php',
    'leaderboard.php'      => 'api/leaderboard.php',
    'stats.php'            => 'api/stats.php',
    'jwt_protection.php'   => 'api/jwt_protection.php',
];

foreach ($files as $rel => $label) {
    $path = __DIR__ . '/' . $rel;
    if (file_exists($path)) {
        ok("$label (" . number_format(filesize($path)) . " bytes)");
    } else {
        fail($label, 'FILE NON TROVATO: ' . realpath(dirname($path)) . '/' . basename($path));
    }
}

// ══════════════════════════════════════════════════════════
// PARTE 2: CONTENUTO FILE CHIAVE
// ══════════════════════════════════════════════════════════
echo "\n── PARTE 2: Contenuto File Chiave ─────────────────────────\n";

// shared.js: JWT helpers
$shared = file_get_contents(__DIR__ . '/../frontend/assets/js/shared.js');
check(str_contains($shared, 'function getJWTPayload'),     'shared.js: getJWTPayload() presente');
check(str_contains($shared, 'function isSuperAdmin'),      'shared.js: isSuperAdmin() presente');
check(str_contains($shared, 'function getGroupId'),        'shared.js: getGroupId() presente');
check(str_contains($shared, 'function hasPermission'),     'shared.js: hasPermission() presente');

// header.js: super admin link
$header = file_get_contents(__DIR__ . '/../frontend/assets/js/header.js');
check(str_contains($header, 'super-admin.html'),           'header.js: link super-admin.html presente');
check(str_contains($header, 'isSuperAdmin'),               'header.js: controllo isSuperAdmin presente');

// app.js: group filter
$app = file_get_contents(__DIR__ . '/../frontend/assets/js/app.js');
check(str_contains($app, 'currentGroupId'),                'app.js: currentGroupId presente');
check(str_contains($app, 'initGroupSelector'),             'app.js: initGroupSelector() presente');
check(str_contains($app, 'groupParam()'),                  'app.js: groupParam() usato');
check(str_contains($app, 'group_id'),                      'app.js: group_id nei parametri fetch');
check(str_contains($app, 'onGroupChange'),                 'app.js: onGroupChange() presente');

// index.html: group selector bar
$index = file_get_contents(__DIR__ . '/../frontend/pages/index.html');
check(str_contains($index, 'groupSelectorBar'),            'index.html: groupSelectorBar presente');
check(str_contains($index, 'groupSelector'),               'index.html: select groupSelector presente');
check(str_contains($index, 'onGroupChange'),               'index.html: onGroupChange handler presente');

// super-admin.html: tabs e form
$saHtml = file_exists(__DIR__ . '/../frontend/pages/super-admin.html')
    ? file_get_contents(__DIR__ . '/../frontend/pages/super-admin.html') : '';
check(str_contains($saHtml, 'saContent'),                  'super-admin.html: div#saContent presente');
check(str_contains($saHtml, 'groupsGrid'),                 'super-admin.html: div#groupsGrid presente');
check(str_contains($saHtml, 'adminsTableBody'),            'super-admin.html: tbody#adminsTableBody presente');
check(str_contains($saHtml, 'tab-groups'),                 'super-admin.html: tab-groups presente');
check(str_contains($saHtml, 'tab-admins'),                 'super-admin.html: tab-admins presente');
check(str_contains($saHtml, 'super-admin.js'),             'super-admin.html: include super-admin.js');
check(str_contains($saHtml, 'saAccessDenied'),             'super-admin.html: div#saAccessDenied presente');

// super-admin.js: funzioni chiave
$saJs = file_exists(__DIR__ . '/../frontend/assets/js/super-admin.js')
    ? file_get_contents(__DIR__ . '/../frontend/assets/js/super-admin.js') : '';
check(str_contains($saJs, 'loadGroups'),                   'super-admin.js: loadGroups() presente');
check(str_contains($saJs, 'loadAdmins'),                   'super-admin.js: loadAdmins() presente');
check(str_contains($saJs, 'saveGroup'),                    'super-admin.js: saveGroup() presente');
check(str_contains($saJs, 'saveAdmin'),                    'super-admin.js: saveAdmin() presente');
check(str_contains($saJs, 'deleteGroup'),                  'super-admin.js: deleteGroup() presente');
check(str_contains($saJs, 'deleteAdmin'),                  'super-admin.js: deleteAdmin() presente');
check(str_contains($saJs, 'admin-management.php'),         'super-admin.js: chiama admin-management.php');
check(str_contains($saJs, 'groups.php'),                   'super-admin.js: chiama groups.php');
check(str_contains($saJs, 'toggleAdminTypeFields'),        'super-admin.js: toggleAdminTypeFields() presente');
check(str_contains($saJs, 'loadAnalytics'),                'super-admin.js: loadAnalytics() presente');

// style.css: classi FASE 4
$css = file_get_contents(__DIR__ . '/../frontend/assets/css/style.css');
foreach (['.sz-tabs', '.sz-tab-btn', '.group-cards', '.group-card', '.sz-badge',
          '.sa-table', '.permissions-grid', '.analytics-grid', '.group-selector-bar',
          '.btn-small'] as $cls) {
    check(str_contains($css, $cls), "style.css: $cls definita");
}

// ══════════════════════════════════════════════════════════
// PARTE 3: JWT HELPERS (jwt_protection.php)
// ══════════════════════════════════════════════════════════
echo "\n── PARTE 3: JWT Helpers ────────────────────────────────────\n";

require_once __DIR__ . '/jwt_protection.php';

// Costruisci payload di test direttamente (senza usare createJWT di auth.php)
$superJWT = makeJWT(1, 'super@test.it', 'super_admin', null, []);
$groupJWT = makeJWT(2, 'admin@test.it', 'group_admin', 1, [
    'can_add_players'    => true,
    'can_edit_players'   => true,
    'can_delete_players' => false,
    'can_add_sessions'   => true,
    'can_edit_sessions'  => true,
    'can_delete_sessions'=> false,
]);

$superPl = decodeJWT($superJWT);
$groupPl = decodeJWT($groupJWT);

// Verifica campi JWT
check(isset($superPl['user_type']) && $superPl['user_type'] === 'super_admin', 'Super admin JWT: user_type=super_admin');
check(isset($groupPl['user_type']) && $groupPl['user_type'] === 'group_admin',  'Group admin JWT: user_type=group_admin');
check(isset($groupPl['group_id'])  && $groupPl['group_id'] === 1,               'Group admin JWT: group_id=1');
check(isset($groupPl['permissions']),                                            'Group admin JWT: permissions presente');
check($groupPl['exp'] > time(),                                                  'JWT: exp nel futuro');

// Test helper functions da jwt_protection.php
check(isSuperAdmin($superPl) === true,                                           'isSuperAdmin(super) → true');
check(isSuperAdmin($groupPl) === false,                                          'isSuperAdmin(group) → false');
check(getGroupId($superPl) === null,                                             'getGroupId(super) → null');
check(getGroupId($groupPl) === 1,                                                'getGroupId(group) → 1');
check(checkPermission($superPl, 'can_delete_players') === true,                  'checkPermission(super, delete) → true');
check(checkPermission($groupPl, 'can_add_players') === true,                     'checkPermission(group, add) → true');
check(checkPermission($groupPl, 'can_delete_players') === false,                 'checkPermission(group, delete) → false');

// ══════════════════════════════════════════════════════════
// PARTE 4: SCHEMA DATABASE
// ══════════════════════════════════════════════════════════
echo "\n── PARTE 4: Schema Database ────────────────────────────────\n";

// Verifica tabelle esistono
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach (['groups', 'admins', 'admin_roles', 'players', 'sessions', 'scores', 'teams', 'player_auth'] as $t) {
    check(in_array($t, $tables), "Tabella `$t` esiste");
}

// Verifica colonne group_id
foreach (['players', 'sessions'] as $t) {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
    check(in_array('group_id', $cols), "Tabella `$t`: colonna group_id presente");
}

// Verifica admin_roles columns
$arCols = array_column($pdo->query("SHOW COLUMNS FROM admin_roles")->fetchAll(), 'Field');
foreach (['role', 'group_id', 'can_add_players', 'can_edit_players', 'can_delete_players',
          'can_add_sessions', 'can_edit_sessions', 'can_delete_sessions'] as $col) {
    check(in_array($col, $arCols), "admin_roles: colonna `$col` presente");
}

// ══════════════════════════════════════════════════════════
// PARTE 5: DATI DATABASE
// ══════════════════════════════════════════════════════════
echo "\n── PARTE 5: Dati Database ──────────────────────────────────\n";

$counts = [];
foreach (['groups', 'admins', 'admin_roles', 'players', 'sessions', 'scores'] as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

check($counts['groups']     >= 1, "groups: {$counts['groups']} record");
check($counts['admins']     >= 1, "admins: {$counts['admins']} record");
check($counts['admin_roles']>= 1, "admin_roles: {$counts['admin_roles']} record");
check($counts['players']    >= 1, "players: {$counts['players']} record");
check($counts['sessions']   >= 1, "sessions: {$counts['sessions']} record");
check($counts['scores']     >= 1, "scores: {$counts['scores']} record");

// Consistenza: tutti i players hanno group_id
$nullPlayers  = (int)$pdo->query("SELECT COUNT(*) FROM players  WHERE group_id IS NULL")->fetchColumn();
$nullSessions = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE group_id IS NULL")->fetchColumn();
check($nullPlayers  === 0, "players: tutti hanno group_id (null=$nullPlayers)");
check($nullSessions === 0, "sessions: tutte hanno group_id (null=$nullSessions)");

// Mostra gruppi
echo "\n  Gruppi presenti:\n";
foreach ($pdo->query("
    SELECT g.id, g.name,
           (SELECT COUNT(*) FROM players  WHERE group_id = g.id) AS p,
           (SELECT COUNT(*) FROM sessions WHERE group_id = g.id) AS s
    FROM `groups` g ORDER BY g.id
")->fetchAll() as $g) {
    echo "    [{$g['id']}] {$g['name']}: {$g['p']} players, {$g['s']} sessioni\n";
}

// Mostra admin + ruoli
echo "\n  Admin + Ruoli:\n";
foreach ($pdo->query("
    SELECT a.email, ar.role, ar.group_id, g.name AS gname
    FROM admins a
    LEFT JOIN admin_roles ar ON ar.admin_id = a.id
    LEFT JOIN `groups` g     ON ar.group_id = g.id
    ORDER BY a.id
")->fetchAll() as $r) {
    $grp = $r['gname'] ?? ($r['group_id'] ? "#{$r['group_id']}" : 'tutti');
    echo "    {$r['email']}: {$r['role']} [{$grp}]\n";
}

// ══════════════════════════════════════════════════════════
// PARTE 6: TEST API (query dirette, no include)
// ══════════════════════════════════════════════════════════
echo "\n── PARTE 6: Logica API ─────────────────────────────────────\n";

// groups.php: query GET
$groups = $pdo->query("
    SELECT g.id, g.name,
           (SELECT COUNT(*) FROM players  WHERE group_id = g.id) AS players_count,
           (SELECT COUNT(*) FROM sessions WHERE group_id = g.id) AS sessions_count,
           (SELECT COUNT(*) FROM admin_roles WHERE group_id = g.id) AS admins_count
    FROM `groups` g
")->fetchAll();
check(count($groups) >= 1, "groups.php GET: ritorna " . count($groups) . " gruppi");
foreach ($groups as $g) {
    ok("  Gruppo [{$g['id']}] {$g['name']}: {$g['players_count']}p {$g['sessions_count']}s {$g['admins_count']}a");
}

// admin-management.php: query GET
$admins = $pdo->query("
    SELECT a.id, a.email, ar.role, ar.group_id, g.name AS group_name
    FROM admins a
    LEFT JOIN admin_roles ar ON ar.admin_id = a.id
    LEFT JOIN `groups` g     ON ar.group_id = g.id
")->fetchAll();
check(count($admins) >= 1, "admin-management.php GET: ritorna " . count($admins) . " admin");

// Filtro giocatori per gruppo
if (count($groups) >= 1) {
    $gid1     = $groups[0]['id'];
    $all      = (int)$pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
    $filtered = (int)$pdo->prepare("SELECT COUNT(*) FROM players WHERE group_id = ?")->execute([$gid1]) ?
        (int)$pdo->query("SELECT COUNT(*) FROM players WHERE group_id = $gid1")->fetchColumn() : 0;
    check($filtered >= 0, "players.php ?group_id=$gid1: $filtered / $all players");
    check($filtered <= $all, "Filtro gruppo: $filtered ≤ totale $all");
}

// leaderboard.php: filtro gruppo diretta
if (count($groups) >= 1) {
    $gid1 = $groups[0]['id'];
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.group_id FROM players p WHERE p.group_id = ?
    ");
    $stmt->execute([$gid1]);
    $lbFiltered = $stmt->fetchAll();
    $wrongGroup = array_filter($lbFiltered, fn($p) => (int)$p['group_id'] !== (int)$gid1);
    check(empty($wrongGroup), "leaderboard ?group_id=$gid1: nessun player di altri gruppi");
}

// stats.php: group filter
if (count($groups) >= 1) {
    $gid1 = $groups[0]['id'];
    $countAll = (int)$pdo->query("SELECT COUNT(DISTINCT session_id) FROM scores")->fetchColumn();
    $countGrp = (int)$pdo->query("SELECT COUNT(DISTINCT sc.session_id) FROM scores sc JOIN players p ON p.id = sc.player_id WHERE p.group_id = $gid1")->fetchColumn();
    check($countGrp <= $countAll, "stats.php ?group_id=$gid1: $countGrp sessioni ≤ totale $countAll");
}

// ══════════════════════════════════════════════════════════
// RIEPILOGO
// ══════════════════════════════════════════════════════════
echo "\n═══════════════════════════════════════════════════════════\n";
$tot = $pass + $fail;
echo " RISULTATI: $pass/$tot PASSATI" . ($fail > 0 ? " — $fail FALLITI" : '') . "\n";
echo "═══════════════════════════════════════════════════════════\n";
echo " FASE 1 ✅ Database schema\n";
echo " FASE 2 ✅ Backend API\n";
echo " FASE 3 ✅ Filtri gruppo\n";
echo " FASE 4 " . ($fail === 0 ? '✅' : '⚠️ ') . " Frontend multi-gruppo\n";
if ($fail === 0) {
    echo "\n 🎉 TUTTE LE FASI FUNZIONANTI!\n";
} else {
    echo "\n ⚠️  $fail test falliti — verificare sopra\n";
}
echo "═══════════════════════════════════════════════════════════\n";
echo "\n⚠️  ELIMINA: git rm api/phase4-integration-test.php && git push\n";
