<?php
// ============================================
//  api/player-profile.php
//  GET — Profilo e statistiche personali giocatore
//  Richiede JWT con user_type=player
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato']);
    exit;
}

$payload  = requireAuth(['GET']);
$userType = $payload['user_type'] ?? '';

// Consenti accesso a player (proprio profilo) o admin (qualsiasi player)
if ($userType === 'player') {
    $playerId = (int)($payload['player_id'] ?? 0);
} elseif (in_array($userType, ['super_admin', 'group_admin'], true)) {
    $playerId = (int)($_GET['player_id'] ?? 0);
    if (!$playerId) {
        http_response_code(400);
        echo json_encode(['error' => 'player_id obbligatorio']);
        exit;
    }
} else {
    http_response_code(403);
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

try {
    $pdo = getPDO();

    // ── Player info ──
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.emoji, p.group_id,
               g.name AS group_name
        FROM players p
        JOIN `groups` g ON g.id = p.group_id
        WHERE p.id = ?
    ");
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();

    if (!$player) {
        http_response_code(404);
        echo json_encode(['error' => 'Giocatore non trovato']);
        exit;
    }

    // group_admin può vedere solo i giocatori del proprio gruppo
    if ($userType === 'group_admin') {
        $groupId = (int)($payload['group_id'] ?? 0);
        if ((int)$player['group_id'] !== $groupId) {
            http_response_code(403);
            echo json_encode(['error' => 'Giocatore non appartiene al tuo gruppo']);
            exit;
        }
    }

    // ── Scores con info sessione ──
    $stmt = $pdo->prepare("
        SELECT
            sc.id, sc.score, sc.game_number,
            se.id       AS session_id,
            se.date,
            se.location,
            se.mode
        FROM scores sc
        JOIN sessions se ON se.id = sc.session_id
        WHERE sc.player_id = ?
        ORDER BY se.date DESC, sc.game_number ASC
    ");
    $stmt->execute([$playerId]);
    $rows = $stmt->fetchAll();

    // Raggruppa per sessione
    $sessionsMap = [];
    foreach ($rows as $sc) {
        $sid = $sc['session_id'];
        if (!isset($sessionsMap[$sid])) {
            $sessionsMap[$sid] = [
                'id'       => (int)$sid,
                'date'     => $sc['date'],
                'location' => $sc['location'] ?: '—',
                'mode'     => $sc['mode']     ?: '—',
                'scores'   => [],
            ];
        }
        $sessionsMap[$sid]['scores'][] = (int)$sc['score'];
    }

    // Calcola totale/media/best per sessione
    $sessions = [];
    foreach ($sessionsMap as $s) {
        $s['games'] = count($s['scores']);
        $s['total'] = array_sum($s['scores']);
        $s['media'] = $s['games'] > 0 ? round($s['total'] / $s['games'], 1) : 0;
        $s['best']  = $s['games'] > 0 ? max($s['scores']) : 0;
        $sessions[] = $s;
    }

    // ── Statistiche globali ──
    $allScores = array_column($rows, 'score');
    $allScores = array_map('intval', $allScores);

    $totalGames  = count($allScores);
    $totalSerate = count($sessions);
    $media       = $totalGames > 0 ? round(array_sum($allScores) / $totalGames, 2) : 0;
    $record      = $totalGames > 0 ? max($allScores) : 0;

    $sortedScores = $allScores;
    rsort($sortedScores);
    $best5avg = count($sortedScores) >= 5
        ? round(array_sum(array_slice($sortedScores, 0, 5)) / 5, 1)
        : null;

    // Trend ultime 5 sessioni (media)
    $trend = array_map(fn($s) => ['date' => $s['date'], 'media' => $s['media']], array_slice($sessions, 0, 5));

    echo json_encode([
        'success'  => true,
        'player'   => $player,
        'stats'    => [
            'media'        => $media,
            'record'       => $record,
            'total_games'  => $totalGames,
            'total_serate' => $totalSerate,
            'best5_avg'    => $best5avg,
        ],
        'sessions' => $sessions,
        'trend'    => array_reverse($trend),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore caricamento profilo']);
}
