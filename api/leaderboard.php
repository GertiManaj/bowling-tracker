<?php
// ============================================
//  api/leaderboard.php
//  GET → restituisce la classifica generale
// ============================================
require_once 'db.php';

$pdo = getDB();

// Classifica con media, record, partite
$stmt = $pdo->query('SELECT * FROM leaderboard');
$players = $stmt->fetchAll();

// Per ogni giocatore recupera anche gli ultimi 6 punteggi (per il trend)
foreach ($players as &$player) {
    $s = $pdo->prepare('
        SELECT score FROM scores
        WHERE player_id = ?
        ORDER BY session_id DESC
        LIMIT 6
    ');
    $s->execute([$player['id']]);
    $recent = array_reverse($s->fetchAll(PDO::FETCH_COLUMN));
    $player['trend'] = $recent;
}

echo json_encode($players);