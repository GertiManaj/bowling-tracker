<?php
// ============================================
//  api/export.php
//  GET → esporta tutto il database in JSON
//  Richiede token JWT valido (solo admin)
// ============================================
require_once __DIR__ . '/config.php';

// ── VERIFICA TOKEN (JWT con firma HMAC-SHA256) ─
require_once __DIR__ . '/jwt_protection.php';
$payload = requireAuth(['GET']);
require_once __DIR__ . '/logger.php';

// ── DETERMINA SCOPE: group_admin → solo il proprio gruppo ──
$exportGroupId = null;
if ($payload && !isSuperAdmin($payload)) {
    $exportGroupId = getGroupId($payload);
}

// ── EXPORT ───────────────────────────────────
$pdo = getPDO();
logSecurityEvent($pdo, 'database_export', 'WARNING', $GLOBALS['authenticated_admin_id'] ?? null, [
    'exported_at' => date('Y-m-d H:i:s'),
    'group_id'    => $exportGroupId,
]);

// Sessioni
if ($exportGroupId !== null) {
    $stmtS = $pdo->prepare('SELECT * FROM sessions WHERE group_id = ? ORDER BY date DESC');
    $stmtS->execute([$exportGroupId]);
    $sessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sessions = $pdo->query('SELECT * FROM sessions ORDER BY date DESC')->fetchAll(PDO::FETCH_ASSOC);
}

// Giocatori
if ($exportGroupId !== null) {
    $stmtP = $pdo->prepare('SELECT * FROM players WHERE group_id = ? ORDER BY name ASC');
    $stmtP->execute([$exportGroupId]);
    $players = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} else {
    $players = $pdo->query('SELECT * FROM players ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

// Punteggi
if ($exportGroupId !== null) {
    $stmtSc = $pdo->prepare('
        SELECT sc.*, p.name AS player_name, p.emoji AS player_emoji,
               se.date AS session_date, se.location AS session_location
        FROM scores sc
        JOIN players  p  ON sc.player_id  = p.id
        JOIN sessions se ON sc.session_id = se.id
        WHERE p.group_id = ?
        ORDER BY sc.session_id DESC, sc.player_id ASC
    ');
    $stmtSc->execute([$exportGroupId]);
    $scores = $stmtSc->fetchAll(PDO::FETCH_ASSOC);
} else {
    $scores = $pdo->query('
        SELECT sc.*, p.name AS player_name, p.emoji AS player_emoji,
               se.date AS session_date, se.location AS session_location
        FROM scores sc
        JOIN players  p  ON sc.player_id  = p.id
        JOIN sessions se ON sc.session_id = se.id
        ORDER BY sc.session_id DESC, sc.player_id ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
}

// Teams
$teams = [];
try {
    if ($exportGroupId !== null) {
        $stmtT = $pdo->prepare('SELECT t.* FROM teams t JOIN sessions s ON t.session_id = s.id WHERE s.group_id = ? ORDER BY t.id ASC');
        $stmtT->execute([$exportGroupId]);
        $teams = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $teams = $pdo->query('SELECT * FROM teams ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // tabella teams potrebbe non esistere separatamente
}

// Meta
$meta = [
    'exported_at'    => date('Y-m-d H:i:s'),
    'total_sessions' => count($sessions),
    'total_players'  => count($players),
    'total_scores'   => count($scores),
    'version'        => '1.0',
    'app'            => 'Strike Zone Bowling Tracker',
];

// ── OUTPUT ────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="strikezone-backup-' . date('Y-m-d') . '.json"');
header('Cache-Control: no-cache');

echo json_encode([
    'meta'     => $meta,
    'players'  => $players,
    'sessions' => $sessions,
    'scores'   => $scores,
    'teams'    => $teams,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
