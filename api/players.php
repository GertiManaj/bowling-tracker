<?php
// ============================================
//  api/players.php
//  GET    → lista tutti i giocatori + stats
//  POST   → aggiunge un nuovo giocatore
//  PUT    → modifica un giocatore esistente
//  DELETE → elimina un giocatore
// ============================================
require_once __DIR__ . '/config.php';

$pdo    = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────
if ($method === 'GET') {
    $players = $pdo->query('
        SELECT
            p.id, p.name, p.nickname, p.emoji, p.created_at,
            COUNT(s.id)            AS partite,
            ROUND(AVG(s.score), 1) AS media,
            MAX(s.score)           AS record,
            MIN(s.score)           AS minimo
        FROM players p
        LEFT JOIN scores s ON s.player_id = p.id
        GROUP BY p.id
        ORDER BY p.name ASC
    ')->fetchAll();

    echo json_encode($players);
    exit;
}

// ── POST — aggiungi ──────────────────────────
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty(trim($data['name'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'Il nome è obbligatorio']);
        exit;
    }

    $check = $pdo->prepare('SELECT id FROM players WHERE name = ?');
    $check->execute([trim($data['name'])]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Esiste già un giocatore con questo nome']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO players (name, nickname, emoji) VALUES (?, ?, ?)');
    $stmt->execute([trim($data['name']), trim($data['nickname'] ?? ''), $data['emoji'] ?? '🎳']);

    http_response_code(201);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ── PUT — modifica ───────────────────────────
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id || empty(trim($data['name'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'ID e nome sono obbligatori']);
        exit;
    }

    $check = $pdo->prepare('SELECT id FROM players WHERE name = ? AND id != ?');
    $check->execute([trim($data['name']), $id]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Esiste già un giocatore con questo nome']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE players SET name = ?, nickname = ?, emoji = ? WHERE id = ?');
    $stmt->execute([trim($data['name']), trim($data['nickname'] ?? ''), $data['emoji'] ?? '🎳', $id]);

    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE — elimina ─────────────────────────
if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID non valido']);
        exit;
    }

    // CASCADE elimina anche scores collegati
    $stmt = $pdo->prepare('DELETE FROM players WHERE id = ?');
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non consentito']);