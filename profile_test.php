<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'api/db.php';
$pdo = getDB();
$id  = intval($_GET['id'] ?? 1);

try {
    $q = $pdo->prepare('SELECT id, name, emoji FROM players WHERE id = ?');
    $q->execute([$id]);
    $player = $q->fetch();
    echo json_encode(['player' => $player, 'ok' => true]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
