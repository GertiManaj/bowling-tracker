<?php
require_once __DIR__ . '/config.php';
$pdo = getPDO();
$id  = intval($_GET['id'] ?? 1);

// Test query semplice
try {
    $q = $pdo->prepare('SELECT id, name, emoji FROM players WHERE id = ?');
    $q->execute([$id]);
    echo json_encode(['player' => $q->fetch(), 'ok' => true]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
