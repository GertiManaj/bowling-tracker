<?php
// ============================================
//  api/leaderboard.php
//  GET → classifica generale con tutte le stat
// ============================================
require_once 'db.php';

$pdo = getDB();

// Classifica dalla vista aggiornata
$stmt    = $pdo->query('SELECT * FROM leaderboard');
$players = $stmt->fetchAll();

foreach ($players as &$player) {
    // Trend: ultimi 6 TOTALI per sessione (somma tutti i game della serata)
    $s = $pdo->prepare('
        SELECT SUM(score) AS totale
        FROM scores
        WHERE player_id = ?
        GROUP BY session_id
        ORDER BY session_id DESC
        LIMIT 6
    ');
    $s->execute([$player['id']]);
    $recent = array_reverse($s->fetchAll(PDO::FETCH_COLUMN));
    $player['trend'] = array_map('intval', $recent);
}

echo json_encode($players);