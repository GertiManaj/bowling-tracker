<?php
// ============================================
//  api/player-register.php
//  POST (pubblico) → crea player + credenziali
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Validazione ──────────────────────────────
$inviteCode = strtoupper(trim($body['invite_code'] ?? ''));
$name       = trim($body['name']       ?? '');
$emoji      = trim($body['emoji']      ?? '🎳');
$nickname   = trim($body['nickname']   ?? '');
$email      = trim($body['email']      ?? '');
$pass       = $body['password'] ?? '';

if ($inviteCode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Codice invito obbligatorio']);
    exit;
}
if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nome giocatore obbligatorio']);
    exit;
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email non valida']);
    exit;
}
if (strlen($pass) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Password minimo 8 caratteri']);
    exit;
}

try {
    $pdo = getPDO();

    // Trova gruppo da invite_code
    $stmt = $pdo->prepare("SELECT id FROM `groups` WHERE invite_code = ?");
    $stmt->execute([$inviteCode]);
    $group = $stmt->fetch();
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Codice invito non valido']);
        exit;
    }
    $groupId = (int)$group['id'];

    // Check email già registrata in player_auth
    $stmt = $pdo->prepare("SELECT id FROM player_auth WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Email già registrata']);
        exit;
    }

    // Check nome duplicato nel gruppo
    $stmt = $pdo->prepare("SELECT id FROM players WHERE name = ? AND group_id = ?");
    $stmt->execute([$name, $groupId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Nome già esistente in questo gruppo']);
        exit;
    }

    $pdo->beginTransaction();

    // 1. Crea player
    $stmt = $pdo->prepare("
        INSERT INTO players (name, nickname, emoji, group_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$name, $nickname ?: null, $emoji ?: '🎳', $groupId]);
    $playerId = (int)$pdo->lastInsertId();

    // 2. Crea credenziali (colonna corretta: password_hash)
    $pdo->prepare("
        INSERT INTO player_auth (player_id, email, password_hash)
        VALUES (?, ?, ?)
    ")->execute([$playerId, $email, password_hash($pass, PASSWORD_DEFAULT)]);

    $pdo->commit();

    logSecurityEvent($pdo, 'player_self_registered', 'INFO', null, [
        'player_id' => $playerId,
        'group_id'  => $groupId,
        'email'     => $email,
    ]);

    echo json_encode([
        'success'   => true,
        'player_id' => $playerId,
        'message'   => 'Registrazione completata',
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Errore durante la registrazione']);
}
