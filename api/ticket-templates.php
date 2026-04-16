<?php
// ============================================
//  api/ticket-templates.php
//  GET    → lista template (solo super_admin)
//  POST   → crea template  (solo super_admin)
//  DELETE → elimina template (solo super_admin)
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';
require_once __DIR__ . '/helpers.php';

$payload = requireAuth(['GET', 'POST', 'DELETE']);

if (!isSuperAdmin($payload)) {
    http_response_code(403);
    echo json_encode(['error' => 'Permesso negato: solo super admin']);
    exit;
}

$pdo    = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────
if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM ticket_templates ORDER BY title');
    echo json_encode(['templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── POST ─────────────────────────────────────
if ($method === 'POST') {
    $data    = json_decode(file_get_contents('php://input'), true) ?? [];
    $title   = trim($data['title']   ?? '');
    $content = trim($data['content'] ?? '');

    if (!$title || !$content) {
        http_response_code(400);
        echo json_encode(['error' => 'Titolo e contenuto obbligatori']);
        exit;
    }
    if (strlen($title) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Titolo troppo lungo (max 100 caratteri)']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO ticket_templates (title, content) VALUES (?, ?)');
    $stmt->execute([$title, $content]);
    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

// ── DELETE ────────────────────────────────────
if ($method === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID mancante']);
        exit;
    }
    $pdo->prepare('DELETE FROM ticket_templates WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non consentito']);
