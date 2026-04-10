<?php
// ============================================
//  api/public-groups.php
//  GET → lista gruppi (pubblico, solo id + name)
//  Usato da player-register.html
// ============================================
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato']);
    exit;
}

try {
    $pdo  = getPDO();
    $stmt = $pdo->query("SELECT id, name FROM `groups` ORDER BY name ASC");
    echo json_encode(['success' => true, 'groups' => $stmt->fetchAll()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore recupero gruppi']);
}
