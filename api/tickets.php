<?php
// ============================================
//  api/tickets.php
//  GET    → lista ticket (admin) o singolo
//  POST   → crea nuovo ticket (chiunque)
//  PUT    → aggiorna stato/risposta (admin)
// ============================================
require_once __DIR__ . '/config.php';
$pdo    = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────
if ($method === 'GET') {
    $id = intval($_GET['id'] ?? 0);
    if ($id) {
        $q = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
        $q->execute([$id]);
        echo json_encode($q->fetch() ?: ['error' => 'Non trovato']);
    } else {
        $status = $_GET['status'] ?? null;
        if ($status && $status !== 'all') {
            $q = $pdo->prepare('SELECT * FROM tickets WHERE status = ? ORDER BY created_at DESC');
            $q->execute([$status]);
        } else {
            $q = $pdo->query('SELECT * FROM tickets ORDER BY created_at DESC');
        }
        $tickets = $q->fetchAll();
        $unread  = count(array_filter($tickets, fn($t) => $t['status'] === 'open'));
        echo json_encode(['tickets' => $tickets, 'unread' => $unread]);
    }
    exit;
}

// ── POST — crea ticket ───────────────────────
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty(trim($data['description'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'La descrizione è obbligatoria']);
        exit;
    }
    $type  = in_array($data['type'] ?? '', ['bug','feature','correction']) ? $data['type'] : 'bug';
    $name  = trim($data['name'] ?? '');
    $desc  = trim($data['description']);
    $stmt  = $pdo->prepare('INSERT INTO tickets (type, name, description, status, created_at) VALUES (?, ?, ?, \'open\', NOW())');
    $stmt->execute([$type, $name ?: null, $desc]);
    http_response_code(201);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ── PUT — aggiorna ticket (admin) ────────────
if ($method === 'PUT') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $id     = intval($data['id'] ?? 0);
    $status = $data['status'] ?? null;
    $reply  = trim($data['reply'] ?? '');
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID mancante']); exit; }
    $fields = []; $params = [];
    if ($status && in_array($status, ['open','in_progress','resolved','rejected'])) {
        $fields[] = 'status = ?'; $params[] = $status;
    }
    if ($reply !== '') {
        $fields[] = 'reply = ?'; $params[] = $reply;
        $fields[] = 'replied_at = NOW()';
    }
    if (!$fields) { http_response_code(400); echo json_encode(['error' => 'Nulla da aggiornare']); exit; }
    $params[] = $id;
    $pdo->prepare('UPDATE tickets SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non consentito']);
