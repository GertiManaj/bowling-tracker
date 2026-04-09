<?php
// ============================================
//  api/players.php
//  GET    → lista tutti i giocatori + stats (pubblico)
//  POST   → aggiunge un nuovo giocatore (PROTETTO)
//  PUT    → modifica un giocatore esistente (PROTETTO)
//  DELETE → elimina un giocatore (PROTETTO)
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';

$method  = $_SERVER['REQUEST_METHOD'];
$payload = null;

if ($method !== 'GET') {
    // POST/PUT/DELETE: JWT obbligatorio
    $payload = requireAuth(['POST', 'PUT', 'DELETE']);
} else {
    // GET pubblico: leggi JWT opzionale per group filter
    $ah = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)$/i', $ah, $m)) {
        $parts = explode('.', $m[1]);
        if (count($parts) === 3) {
            $pd = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if ($pd && isset($pd['exp']) && $pd['exp'] > time()) $payload = $pd;
        }
    }
}

// Determina filtro gruppo
$filterGroupId = null;
if ($payload) {
    if (isSuperAdmin($payload)) {
        $filterGroupId = isset($_GET['group_id']) && $_GET['group_id'] !== 'all'
            ? (int)$_GET['group_id'] : null;
    } else {
        // group_admin e player vedono solo il loro gruppo
        $filterGroupId = getGroupId($payload);
    }
}

$pdo = getPDO();

// ── GET ──────────────────────────────────────
if ($method === 'GET') {
    $sql = '
        SELECT
            p.id, p.name, p.nickname, p.emoji, p.group_id, p.created_at,
            COUNT(s.id)            AS partite,
            ROUND(AVG(s.score), 1) AS media,
            MAX(s.score)           AS record,
            MIN(s.score)           AS minimo
        FROM players p
        LEFT JOIN scores s ON s.player_id = p.id';

    $params = [];
    if ($filterGroupId !== null) {
        $sql .= ' WHERE p.group_id = ?';
        $params[] = $filterGroupId;
    }
    $sql .= ' GROUP BY p.id ORDER BY p.name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── POST — aggiungi ──────────────────────────
if ($method === 'POST') {
    if (!checkPermission($payload, 'can_add_players')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_add_players richiesto']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty(trim($data['name'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'Il nome è obbligatorio']);
        exit;
    }

    // Determina group_id: super_admin può specificarlo, group_admin usa il suo
    $groupId = isSuperAdmin($payload)
        ? (int)($data['group_id'] ?? 1)
        : (int)getGroupId($payload);

    $check = $pdo->prepare('SELECT id FROM players WHERE name = ? AND group_id = ?');
    $check->execute([trim($data['name']), $groupId]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Esiste già un giocatore con questo nome']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO players (name, nickname, emoji, group_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([trim($data['name']), trim($data['nickname'] ?? ''), $data['emoji'] ?? '🎳', $groupId]);

    http_response_code(201);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ── PUT — modifica ───────────────────────────
if ($method === 'PUT') {
    if (!checkPermission($payload, 'can_edit_players')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_edit_players richiesto']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id || empty(trim($data['name'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'ID e nome sono obbligatori']);
        exit;
    }

    // Verifica ownership per group_admin
    $playerRow = null;
    if (!isSuperAdmin($payload)) {
        $own = $pdo->prepare('SELECT group_id FROM players WHERE id = ?');
        $own->execute([$id]);
        $playerRow = $own->fetch();
        if (!$playerRow || (int)$playerRow['group_id'] !== getGroupId($payload)) {
            http_response_code(403);
            echo json_encode(['error' => 'Non puoi modificare giocatori di altri gruppi']);
            exit;
        }
    }

    // Duplicate check con scope gruppo
    $groupForCheck = $playerRow ? (int)$playerRow['group_id'] : null;
    if ($groupForCheck !== null) {
        $check = $pdo->prepare('SELECT id FROM players WHERE name = ? AND group_id = ? AND id != ?');
        $check->execute([trim($data['name']), $groupForCheck, $id]);
    } else {
        $check = $pdo->prepare('SELECT id FROM players WHERE name = ? AND id != ?');
        $check->execute([trim($data['name']), $id]);
    }
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
    if (!checkPermission($payload, 'can_delete_players')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_delete_players richiesto']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID non valido']);
        exit;
    }

    // Verifica ownership per group_admin
    if (!isSuperAdmin($payload)) {
        $own = $pdo->prepare('SELECT group_id FROM players WHERE id = ?');
        $own->execute([$id]);
        $row = $own->fetch();
        if (!$row || (int)$row['group_id'] !== getGroupId($payload)) {
            http_response_code(403);
            echo json_encode(['error' => 'Non puoi eliminare giocatori di altri gruppi']);
            exit;
        }
    }

    // CASCADE elimina anche scores collegati
    $stmt = $pdo->prepare('DELETE FROM players WHERE id = ?');
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non consentito']);
