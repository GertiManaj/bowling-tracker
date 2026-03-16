<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['error' => 'ID mancante']); exit; }

try {
    // Test 1: info base
    $q = $pdo->prepare('SELECT id, name, nickname, emoji, created_at FROM players WHERE id = ?');
    $q->execute([$id]);
    $player = $q->fetch();
    if (!$player) { echo json_encode(['error' => 'Giocatore non trovato']); exit; }

    // Test 2: stats semplici
    $q2 = $pdo->prepare('SELECT COUNT(DISTINCT session_id) AS serate, COUNT(id) AS game_totali, MAX(score) AS record_game FROM scores WHERE player_id = ?');
    $q2->execute([$id]);
    $stats = $q2->fetch();

    echo json_encode(['player' => $player, 'stats' => $stats, 'ok' => true]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
