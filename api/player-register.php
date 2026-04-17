<?php
// ============================================
//  api/player-register.php
//  POST (pubblico) → crea player + credenziali
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/mailer.php';

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
$rawEmoji   = trim($body['emoji']      ?? '🎳');
$nickname   = trim($body['nickname']   ?? '');
$email      = trim($body['email']      ?? '');
$pass       = $body['password'] ?? '';

// Sanifica emoji: accetta solo caratteri che non contengono HTML (strip tags + limita lunghezza)
$emoji = strip_tags($rawEmoji) ?: '🎳';
if (mb_strlen($emoji) > 10) $emoji = '🎳'; // emoji valide sono max 1-2 char

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

    // Rate limiting: max 10 registrazioni per IP in 1 ora
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmtRL = $pdo->prepare("
        SELECT COUNT(*) FROM security_logs
        WHERE event_type = 'player_self_registered' AND ip_address = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmtRL->execute([$clientIp]);
    if ((int)$stmtRL->fetchColumn() >= 10) {
        http_response_code(429);
        echo json_encode(['error' => 'Troppi tentativi di registrazione. Riprova tra 1 ora.']);
        exit;
    }

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

    // Recupera dati gruppo/admin per le email
    $gInfo = $pdo->prepare("
        SELECT g.name AS group_name, a.email AS admin_email, a.name AS admin_name
        FROM `groups` g
        LEFT JOIN admin_roles ar ON ar.group_id = g.id AND ar.role = 'group_admin'
        LEFT JOIN admins a ON a.id = ar.admin_id
        WHERE g.id = ?
        LIMIT 1
    ");
    $gInfo->execute([$groupId]);
    $gRow = $gInfo->fetch() ?: [];

    logSecurityEvent($pdo, 'player_self_registered', 'INFO', null, [
        'player_id' => $playerId,
        'group_id'  => $groupId,
        'email'     => $email,
    ]);

    // Invia risposta al client PRIMA delle email (evita timeout)
    $responseJson = json_encode([
        'success'   => true,
        'player_id' => $playerId,
        'message'   => 'Registrazione completata',
    ]);
    header('Content-Length: ' . strlen($responseJson));
    echo $responseJson;

    if (ob_get_level()) { ob_end_flush(); }
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    // Email dopo la risposta
    try {
        sendWelcomePlayer($email, $name, $gRow['group_name'] ?? '');
        if (!empty($gRow['admin_email'])) {
            sendNewPlayerNotify($gRow['admin_email'], $gRow['admin_name'] ?? 'Admin', $name, $gRow['group_name'] ?? '');
        }
    } catch (\Throwable $ignored) {}

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[player-register] ' . $e->getMessage());
    error_log('[player-register] Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Errore durante la registrazione. Riprova.']);
}
