<?php
// ============================================
//  api/migration-check.php
//  Script temporaneo di verifica/fix Migration 012
//  DA ELIMINARE dopo l'uso.
//  Protezione: token segreto via query string
// ============================================

// Protezione con token — cambia questo valore prima del deploy
$SECRET = 'mig012check_' . date('Ymd');

if (($_GET['token'] ?? '') !== $SECRET) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== MIGRATION 012 CHECK — " . date('Y-m-d H:i:s') . " ===\n\n";

// ── CONNESSIONE ──────────────────────────────
$host   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$port   = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$dbname = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'railway';
$user   = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$pass   = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '';

echo "Host:   $host:$port\n";
echo "DB:     $dbname\n";
echo "User:   $user\n\n";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "✅ Connessione DB riuscita\n\n";
} catch (PDOException $e) {
    die("❌ Connessione fallita: " . $e->getMessage() . "\n");
}

// ── HELPERS ──────────────────────────────────
function tableExists(PDO $pdo, string $table): bool {
    return (bool) $pdo->query("SHOW TABLES LIKE '$table'")->fetchAll();
}
function columnExists(PDO $pdo, string $table, string $col): bool {
    return (bool) $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetchAll();
}
function tryExec(PDO $pdo, string $sql, string $label): void {
    try {
        $pdo->exec($sql);
        echo "  ✅ $label\n";
    } catch (Exception $e) {
        echo "  ⚠️  $label — " . $e->getMessage() . "\n";
    }
}

$ops  = [];  // operazioni eseguite
$skip = [];  // già esistenti

// ════════════════════════════════════════════
// STEP 1: Tabella `groups`
// ════════════════════════════════════════════
echo "--- STEP 1: tabella `groups` ---\n";
if (!tableExists($pdo, 'groups')) {
    tryExec($pdo, "
        CREATE TABLE `groups` (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            logo_url    VARCHAR(255),
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by  INT NULL,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
            INDEX idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", "CREATE TABLE groups");
    $ops[] = "Tabella groups creata";
} else {
    echo "  ✅ Già esiste\n";
    $skip[] = "Tabella groups";
}

// ════════════════════════════════════════════
// STEP 2: Gruppo default
// ════════════════════════════════════════════
echo "\n--- STEP 2: gruppo default ---\n";
$firstAdmin = $pdo->query("SELECT id FROM admins ORDER BY id ASC LIMIT 1")->fetch();
$createdBy  = $firstAdmin ? (int)$firstAdmin['id'] : null;

$existing = $pdo->prepare("SELECT id FROM `groups` WHERE name = 'Strike Zone Original' LIMIT 1");
$existing->execute();
$grpRow = $existing->fetch();

if (!$grpRow) {
    try {
        $pdo->prepare("INSERT INTO `groups` (name, description, created_by) VALUES (?, ?, ?)")
            ->execute(['Strike Zone Original', 'Gruppo originale - Migrato da sistema single-group', $createdBy]);
        $defaultGroupId = (int)$pdo->lastInsertId();
        echo "  ✅ Creato (ID: $defaultGroupId)\n";
        $ops[] = "Gruppo 'Strike Zone Original' creato (ID: $defaultGroupId)";
    } catch (Exception $e) {
        echo "  ❌ " . $e->getMessage() . "\n";
        $defaultGroupId = 1;
    }
} else {
    $defaultGroupId = (int)$grpRow['id'];
    echo "  ✅ Già esiste (ID: $defaultGroupId)\n";
    $skip[] = "Gruppo default";
}

// ════════════════════════════════════════════
// STEP 3: players.group_id
// ════════════════════════════════════════════
echo "\n--- STEP 3: players.group_id ---\n";
if (!columnExists($pdo, 'players', 'group_id')) {
    tryExec($pdo, "ALTER TABLE players ADD COLUMN group_id INT NOT NULL DEFAULT $defaultGroupId AFTER id", "ADD COLUMN players.group_id");
    tryExec($pdo, "ALTER TABLE players ADD CONSTRAINT fk_players_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE RESTRICT", "FK fk_players_group");
    tryExec($pdo, "CREATE INDEX idx_players_group ON players(group_id)", "INDEX idx_players_group");
    $ops[] = "players.group_id aggiunta";
} else {
    echo "  ✅ Già esiste\n";
    $skip[] = "players.group_id";
}

// ════════════════════════════════════════════
// STEP 4: sessions.group_id
// ════════════════════════════════════════════
echo "\n--- STEP 4: sessions.group_id ---\n";
if (!columnExists($pdo, 'sessions', 'group_id')) {
    tryExec($pdo, "ALTER TABLE sessions ADD COLUMN group_id INT NOT NULL DEFAULT $defaultGroupId AFTER id", "ADD COLUMN sessions.group_id");
    tryExec($pdo, "ALTER TABLE sessions ADD CONSTRAINT fk_sessions_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE RESTRICT", "FK fk_sessions_group");
    tryExec($pdo, "CREATE INDEX idx_sessions_group ON sessions(group_id)", "INDEX idx_sessions_group");
    $ops[] = "sessions.group_id aggiunta";
} else {
    echo "  ✅ Già esiste\n";
    $skip[] = "sessions.group_id";
}

// ════════════════════════════════════════════
// STEP 5: admins.full_name / phone
// ════════════════════════════════════════════
echo "\n--- STEP 5: admins.full_name / phone ---\n";
if (!columnExists($pdo, 'admins', 'full_name')) {
    tryExec($pdo, "ALTER TABLE admins ADD COLUMN full_name VARCHAR(100) NULL AFTER email", "ADD COLUMN admins.full_name");
    $ops[] = "admins.full_name aggiunta";
} else {
    echo "  ✅ full_name già esiste\n";
    $skip[] = "admins.full_name";
}
if (!columnExists($pdo, 'admins', 'phone')) {
    tryExec($pdo, "ALTER TABLE admins ADD COLUMN phone VARCHAR(20) NULL AFTER full_name", "ADD COLUMN admins.phone");
    $ops[] = "admins.phone aggiunta";
} else {
    echo "  ✅ phone già esiste\n";
    $skip[] = "admins.phone";
}

// ════════════════════════════════════════════
// STEP 6: Tabella admin_roles
// ════════════════════════════════════════════
echo "\n--- STEP 6: tabella admin_roles ---\n";
if (!tableExists($pdo, 'admin_roles')) {
    tryExec($pdo, "
        CREATE TABLE admin_roles (
            id                     INT AUTO_INCREMENT PRIMARY KEY,
            admin_id               INT NOT NULL,
            group_id               INT NULL,
            role                   ENUM('super_admin','group_admin') NOT NULL DEFAULT 'group_admin',
            can_add_players        TINYINT(1) NOT NULL DEFAULT 1,
            can_edit_players       TINYINT(1) NOT NULL DEFAULT 1,
            can_delete_players     TINYINT(1) NOT NULL DEFAULT 0,
            can_add_sessions       TINYINT(1) NOT NULL DEFAULT 1,
            can_edit_sessions      TINYINT(1) NOT NULL DEFAULT 1,
            can_delete_sessions    TINYINT(1) NOT NULL DEFAULT 0,
            can_export_data        TINYINT(1) NOT NULL DEFAULT 0,
            can_view_security_logs TINYINT(1) NOT NULL DEFAULT 0,
            created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
            INDEX idx_admin (admin_id),
            INDEX idx_group (group_id),
            INDEX idx_role  (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", "CREATE TABLE admin_roles");
    $ops[] = "Tabella admin_roles creata";
} else {
    echo "  ✅ Già esiste\n";
    $skip[] = "Tabella admin_roles";
}

// ════════════════════════════════════════════
// STEP 7: super_admin assignment
// ════════════════════════════════════════════
echo "\n--- STEP 7: super_admin assignment ---\n";
if ($firstAdmin) {
    $stmtRole = $pdo->prepare("SELECT id FROM admin_roles WHERE admin_id = ? AND role = 'super_admin' LIMIT 1");
    $stmtRole->execute([$firstAdmin['id']]);
    if (!$stmtRole->fetch()) {
        tryExec($pdo,
            "INSERT INTO admin_roles (admin_id, group_id, role) VALUES ({$firstAdmin['id']}, NULL, 'super_admin')",
            "INSERT super_admin (admin_id={$firstAdmin['id']})"
        );
        $ops[] = "super_admin assegnato (admin_id={$firstAdmin['id']})";
    } else {
        echo "  ✅ Già assegnato\n";
        $skip[] = "super_admin";
    }
} else {
    echo "  ⚠️  Nessun admin trovato\n";
}

// ════════════════════════════════════════════
// STEP 8: Tabella player_auth
// ════════════════════════════════════════════
echo "\n--- STEP 8: tabella player_auth ---\n";
if (!tableExists($pdo, 'player_auth')) {
    tryExec($pdo, "
        CREATE TABLE player_auth (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            player_id     INT NOT NULL UNIQUE,
            email         VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            active        TINYINT(1) NOT NULL DEFAULT 1,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login    TIMESTAMP NULL,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            INDEX idx_email  (email),
            INDEX idx_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", "CREATE TABLE player_auth");
    $ops[] = "Tabella player_auth creata";
} else {
    echo "  ✅ Già esiste\n";
    $skip[] = "Tabella player_auth";
}

// ════════════════════════════════════════════
// VERIFICA INTEGRITÀ DATI
// ════════════════════════════════════════════
echo "\n\n=== VERIFICA INTEGRITÀ ===\n";

$grpCount   = $pdo->query("SELECT COUNT(*) FROM `groups`")->fetchColumn();
$rolesCount = $pdo->query("SELECT COUNT(*) FROM admin_roles")->fetchColumn();
$paCount    = $pdo->query("SELECT COUNT(*) FROM player_auth")->fetchColumn();
$nullP      = columnExists($pdo, 'players', 'group_id')
                ? $pdo->query("SELECT COUNT(*) FROM players WHERE group_id IS NULL")->fetchColumn()
                : 'N/A (colonna assente)';
$nullS      = columnExists($pdo, 'sessions', 'group_id')
                ? $pdo->query("SELECT COUNT(*) FROM sessions WHERE group_id IS NULL")->fetchColumn()
                : 'N/A (colonna assente)';

$grpRow2 = $pdo->query("SELECT * FROM `groups` LIMIT 3")->fetchAll();
$roles   = $pdo->query("SELECT ar.role, ar.group_id, a.email FROM admin_roles ar LEFT JOIN admins a ON ar.admin_id=a.id")->fetchAll();

echo "groups: $grpCount record\n";
foreach ($grpRow2 as $g) echo "  #" . $g['id'] . " " . $g['name'] . "\n";

echo "admin_roles: $rolesCount record\n";
foreach ($roles as $r) echo "  " . $r['email'] . " → role=" . $r['role'] . " group_id=" . var_export($r['group_id'], true) . "\n";

echo "player_auth: $paCount record\n";
echo "players con group_id NULL: $nullP\n";
echo "sessions con group_id NULL: $nullS\n";

// ════════════════════════════════════════════
// REPORT FINALE
// ════════════════════════════════════════════
echo "\n\n=== REPORT FINALE ===\n";

$checklist = [
    ['Tabella groups',             tableExists($pdo, 'groups')],
    ['Gruppo default',             (bool)$pdo->query("SELECT id FROM `groups` WHERE name='Strike Zone Original'")->fetch()],
    ['players.group_id',           columnExists($pdo, 'players', 'group_id')],
    ['sessions.group_id',          columnExists($pdo, 'sessions', 'group_id')],
    ['admins.full_name',           columnExists($pdo, 'admins', 'full_name')],
    ['admins.phone',               columnExists($pdo, 'admins', 'phone')],
    ['Tabella admin_roles',        tableExists($pdo, 'admin_roles')],
    ['Super admin assegnato',      (bool)$pdo->query("SELECT id FROM admin_roles WHERE role='super_admin'")->fetch()],
    ['Tabella player_auth',        tableExists($pdo, 'player_auth')],
    ['players NULL group_id = 0',  $nullP === '0' || $nullP === 0],
    ['sessions NULL group_id = 0', $nullS === '0' || $nullS === 0],
];

$allOk = true;
foreach ($checklist as [$label, $ok]) {
    $icon = $ok ? '✅' : '❌';
    if (!$ok) $allOk = false;
    printf("  %s %-30s %s\n", $icon, $label, $ok ? 'OK' : 'MANCANTE/ERRORE');
}

echo "\n";
if ($allOk) {
    echo "🎉 MIGRATION 012 COMPLETA — tutti i check passati.\n";
} else {
    echo "⚠️  Alcuni elementi mancanti — rilancia lo script.\n";
}

echo "\nOperazioni eseguite: " . count($ops) . "\n";
foreach ($ops as $o) echo "  + $o\n";
echo "Già esistenti: " . count($skip) . "\n";
foreach ($skip as $s) echo "  = $s\n";

echo "\n⚠️  ELIMINA QUESTO FILE DOPO L'USO: api/migration-check.php\n";
