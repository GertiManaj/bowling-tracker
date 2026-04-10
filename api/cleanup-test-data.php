<?php
// api/cleanup-test-data.php
// Elimina admin e gruppi di test
// TEMPORANEO - ELIMINARE DOPO USO

$SECRET = 'cleanup_' . date('Ymd');

if (!isset($_GET['token']) || $_GET['token'] !== $SECRET) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "═══════════════════════════════════════\n";
echo " CLEANUP TEST DATA\n";
echo "═══════════════════════════════════════\n\n";

try {
    $pdo = getPDO();
    echo "✅ Database connesso\n\n";
} catch (Exception $e) {
    die("❌ Errore DB: " . $e->getMessage());
}

// ── STEP 1: Identifica Admin da Tenere ──
echo "── STEP 1: Admin Esistenti ──\n";

$stmt = $pdo->query("
    SELECT
        a.id,
        a.email,
        a.name,
        ar.role,
        ar.group_id
    FROM admins a
    LEFT JOIN admin_roles ar ON ar.admin_id = a.id
    ORDER BY a.id
");

$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Admin trovati:\n";
foreach ($admins as $a) {
    $keep = ($a['email'] === 'manajgerti2002@gmail.com') ? '✅ MANTIENI' : '❌ ELIMINA';
    echo "  [{$a['id']}] {$a['email']} - {$a['role']} - $keep\n";
}

// ── STEP 2: Elimina Admin di Test ──
echo "\n── STEP 2: Elimina Admin di Test ──\n";

$stmt = $pdo->prepare("
    DELETE FROM admins
    WHERE email != 'manajgerti2002@gmail.com'
");
$stmt->execute();
$deletedAdmins = $stmt->rowCount();

echo "  ✅ Admin eliminati: $deletedAdmins\n";

// ── STEP 3: Elimina Admin Roles Orfani ──
echo "\n── STEP 3: Cleanup Admin Roles ──\n";

$stmt = $pdo->prepare("
    DELETE FROM admin_roles
    WHERE admin_id NOT IN (SELECT id FROM admins)
");
$stmt->execute();
$deletedRoles = $stmt->rowCount();

echo "  ✅ Ruoli orfani eliminati: $deletedRoles\n";

// ── STEP 4: Identifica Gruppi ──
echo "\n── STEP 4: Gruppi Esistenti ──\n";

$stmt = $pdo->query("
    SELECT
        g.id,
        g.name,
        g.invite_code,
        COUNT(DISTINCT p.id) as player_count,
        COUNT(DISTINCT s.id) as session_count
    FROM `groups` g
    LEFT JOIN players p ON p.group_id = g.id
    LEFT JOIN sessions s ON s.group_id = g.id
    GROUP BY g.id
    ORDER BY g.id
");

$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Gruppi trovati:\n";
foreach ($groups as $g) {
    $keep = ($g['id'] == 1) ? '✅ MANTIENI' :
            (($g['player_count'] > 0 || $g['session_count'] > 0) ? '⚠️  HA DATI' : '❌ ELIMINA (vuoto)');

    echo "  [{$g['id']}] {$g['name']}\n";
    echo "      Codice: {$g['invite_code']}\n";
    echo "      Players: {$g['player_count']}, Sessions: {$g['session_count']}\n";
    echo "      Azione: $keep\n\n";
}

// ── STEP 5: Elimina Gruppi Vuoti (tranne gruppo 1) ──
echo "── STEP 5: Elimina Gruppi Vuoti ──\n";

// Prima elimina admin_roles che puntano a questi gruppi
$pdo->query("
    DELETE FROM admin_roles
    WHERE group_id IN (
        SELECT id FROM `groups`
        WHERE id != 1
        AND id NOT IN (SELECT DISTINCT group_id FROM players WHERE group_id IS NOT NULL)
        AND id NOT IN (SELECT DISTINCT group_id FROM sessions WHERE group_id IS NOT NULL)
    )
");
$deletedGroupRoles = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
echo "  ✅ Admin roles gruppi vuoti eliminati: $deletedGroupRoles\n";

$pdo->query("
    DELETE FROM `groups`
    WHERE id != 1
    AND id NOT IN (SELECT DISTINCT group_id FROM players WHERE group_id IS NOT NULL)
    AND id NOT IN (SELECT DISTINCT group_id FROM sessions WHERE group_id IS NOT NULL)
");
$deletedGroups = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

echo "  ✅ Gruppi vuoti eliminati: $deletedGroups\n";

// ── STEP 6: Rinomina Gruppo Principale ──
echo "\n── STEP 6: Rinomina Gruppo Principale ──\n";

$pdo->prepare("
    UPDATE `groups`
    SET name = 'Strike Zone Original',
        description = 'Gruppo bowling principale'
    WHERE id = 1
")->execute();

echo "  ✅ Gruppo 1 rinominato in 'Strike Zone Original'\n";

// ── STEP 7: Verifica Stato Finale ──
echo "\n── STEP 7: Stato Finale ──\n";

$adminCount   = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
$roleCount    = $pdo->query("SELECT COUNT(*) FROM admin_roles")->fetchColumn();
$groupCount   = $pdo->query("SELECT COUNT(*) FROM `groups`")->fetchColumn();
$playerCount  = $pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
$sessionCount = $pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();

echo "  Admin:        $adminCount\n";
echo "  Admin Roles:  $roleCount\n";
echo "  Gruppi:       $groupCount\n";
echo "  Players:      $playerCount\n";
echo "  Sessions:     $sessionCount\n";

echo "\n═══════════════════════════════════════\n";
echo " CLEANUP COMPLETATO\n";
echo "═══════════════════════════════════════\n\n";

echo "✅ Database pulito:\n";
echo "  - 1 Super Admin (manajgerti2002@gmail.com)\n";
echo "  - 1 Gruppo (Strike Zone Original)\n";
echo "  - $playerCount Players\n";
echo "  - $sessionCount Sessions\n\n";

echo "🚀 Pronto per test registrazione nuovo gruppo!\n\n";

echo "⚠️  ELIMINA QUESTO FILE DOPO L'USO\n";
