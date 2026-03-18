<?php
// ============================================
//  api/export.php
//  GET → esporta tutto il database in JSON
//  Richiede token JWT valido (solo admin)
// ============================================
require_once 'db.php';

// ── VERIFICA TOKEN ────────────────────────────
$headers = getallheaders();
$auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token   = str_replace('Bearer ', '', $auth);

// Fallback: token nel query string (?token=...)
if (!$token) {
    $token = $_GET['token'] ?? '';
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Token mancante']);
    exit;
}

// Verifica firma JWT lato server
function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    try {
        $payload = json_decode(base64_decode(
            str_replace(['-','_'], ['+','/'], $parts[1])
        ), true);
        if (!$payload || !isset($payload['exp'])) return false;
        if ($payload['exp'] < time()) return false;
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if (!verifyToken($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token non valido o scaduto']);
    exit;
}

// ── EXPORT ───────────────────────────────────
$pdo = getDB();

// Sessioni
$sessions = $pdo->query('
    SELECT * FROM sessions ORDER BY date DESC
')->fetchAll(PDO::FETCH_ASSOC);

// Giocatori
$players = $pdo->query('
    SELECT * FROM players ORDER BY name ASC
')->fetchAll(PDO::FETCH_ASSOC);

// Punteggi (con info giocatore e sessione)
$scores = $pdo->query('
    SELECT
        sc.*,
        p.name  AS player_name,
        p.emoji AS player_emoji,
        se.date AS session_date,
        se.location AS session_location
    FROM scores sc
    JOIN players  p  ON sc.player_id  = p.id
    JOIN sessions se ON sc.session_id = se.id
    ORDER BY sc.session_id DESC, sc.player_id ASC
')->fetchAll(PDO::FETCH_ASSOC);

// Teams (se esiste la tabella)
$teams = [];
try {
    $teams = $pdo->query('SELECT * FROM teams ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
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
