<?php
// ============================================
//  api/player-auth.php
//  POST ?action=login     → login giocatore (pubblico, no OTP)
//  POST ?action=register  → crea credenziali giocatore (admin only)
//  DELETE                 → elimina credenziali (admin only)
// ============================================
ini_set('display_errors', 0); // Mai outputtare HTML di errore in un'API JSON
ob_start();                    // Buffer per catturare eventuali output inattesi

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/mailer.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$pdo    = getPDO();

// ── JWT helper locale (non include auth.php per evitare dipendenze circolari) ──
function pauth_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function generatePlayerJWT(array $pa): string {
    $secret = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';

    $header  = pauth_base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = pauth_base64url_encode(json_encode([
        'iat'        => time(),
        'exp'        => time() + (24 * 60 * 60),
        'user_type'  => 'player',
        'player_id'  => (int)$pa['player_id'],
        'group_id'   => (int)$pa['group_id'],
        'name'       => $pa['player_name'] ?? '',
        'emoji'      => $pa['emoji']       ?? '🎳',
        'group_name' => $pa['group_name']  ?? '',
        'group_type' => $pa['group_type']  ?? 'challenge',
    ]));
    $sig = pauth_base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$sig";
}

// ══════════════════════════════════════════
// POST action=login — Login giocatore (pubblico)
// ══════════════════════════════════════════
if ($method === 'POST' && $action === 'login') {
    $data  = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim($data['email'] ?? '');
    $pass  = $data['password'] ?? '';

    if ($email === '' || $pass === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Email e password obbligatori']);
        exit;
    }

    // Rate limiting: max 10 tentativi falliti per IP in 15 minuti
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmtRL   = $pdo->prepare("
        SELECT COUNT(*) FROM login_logs
        WHERE ip_address = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmtRL->execute([$clientIp]);
    if ((int)$stmtRL->fetchColumn() >= 10) {
        logSecurityEvent($pdo, 'login_blocked_rate_limit', 'CRITICAL', null, ['ip' => $clientIp, 'email' => $email]);
        http_response_code(429);
        echo json_encode(['error' => 'Troppi tentativi. Riprova tra 15 minuti.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT pa.*, p.name AS player_name, p.emoji, p.group_id,
                   g.name AS group_name, g.group_type
            FROM player_auth pa
            JOIN players p ON pa.player_id = p.id
            JOIN `groups` g ON p.group_id  = g.id
            WHERE pa.email = ? AND pa.active = 1
        ");
        $stmt->execute([$email]);
        $pa = $stmt->fetch();

        if (!$pa || !password_verify($pass, $pa['password_hash'])) {
            // Log fallimento
            $pdo->prepare("
                INSERT INTO login_logs (admin_id, email, success, failure_reason, ip_address, user_agent)
                VALUES (NULL, ?, 0, 'player_wrong_credentials', ?, ?)
            ")->execute([$email, $clientIp, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
            logSecurityEvent($pdo, 'login_failed', 'WARNING', null, ['email' => $email, 'type' => 'player']);
            http_response_code(401);
            echo json_encode(['error' => 'Credenziali non valide']);
            exit;
        }

        // Blocca se deve cambiare password (account appena attivato dall'admin)
        if (!empty($pa['must_change_password'])) {
            http_response_code(403);
            echo json_encode([
                'must_change_password' => true,
                'message'              => 'Devi impostare una nuova password prima di accedere',
            ]);
            exit;
        }

        // Aggiorna last_login
        $pdo->prepare("UPDATE player_auth SET last_login = NOW() WHERE id = ?")->execute([$pa['id']]);

        $jwt = generatePlayerJWT($pa);

        logSecurityEvent($pdo, 'player_login_success', 'INFO', null, [
            'player_id' => (int)$pa['player_id'],
            'email'     => $email,
        ]);

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'token'   => $jwt,
            'player'  => [
                'id'         => (int)$pa['player_id'],
                'name'       => $pa['player_name'],
                'group_id'   => (int)$pa['group_id'],
                'group_name' => $pa['group_name'],
            ],
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore login']);
    }
    exit;
}

// ══════════════════════════════════════════
// POST action=register — Crea credenziali (admin only)
// ══════════════════════════════════════════
if ($method === 'POST' && $action === 'register') {
    require_once __DIR__ . '/jwt_protection.php';
    $authPayload = requireAuth(['POST']);

    $userType = $authPayload['user_type'] ?? '';
    if (!in_array($userType, ['super_admin', 'group_admin'], true)) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['error' => 'Solo admin possono creare credenziali giocatori']);
        exit;
    }

    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $playerId = (int)($data['player_id'] ?? 0);
    $email    = trim($data['email']     ?? '');

    if (!$playerId || $email === '') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'player_id e email obbligatori']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Email non valida']);
        exit;
    }

    // group_admin può creare credenziali solo per giocatori del proprio gruppo
    if ($userType === 'group_admin') {
        $groupId = getGroupId($authPayload);
        $check   = $pdo->prepare("SELECT group_id FROM players WHERE id = ?");
        $check->execute([$playerId]);
        $playerRow = $check->fetch();
        if (!$playerRow || (int)$playerRow['group_id'] !== $groupId) {
            http_response_code(403);
            echo json_encode(['error' => 'Giocatore non appartiene al tuo gruppo']);
            exit;
        }
    }

    try {
        // Recupera nome player e gruppo per l'email
        $infoStmt = $pdo->prepare("
            SELECT p.name AS player_name, g.name AS group_name
            FROM players p
            JOIN `groups` g ON p.group_id = g.id
            WHERE p.id = ?
        ");
        $infoStmt->execute([$playerId]);
        $playerInfo = $infoStmt->fetch();

        // Genera password temporanea casuale (admin non la conosce)
        $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%';
        $tempPass = '';
        for ($i = 0; $i < 12; $i++) {
            $tempPass .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $pdo->prepare("
            INSERT INTO player_auth (player_id, email, password_hash, must_change_password)
            VALUES (?, ?, ?, 1)
        ")->execute([$playerId, $email, password_hash($tempPass, PASSWORD_DEFAULT)]);

        // Invia email con password temporanea e avviso cambio obbligatorio
        $emailSent = false;
        $responseJson = json_encode(['success' => true, 'message' => 'Credenziali create', 'email_sent' => false]);

        if ($playerInfo) {
            if (function_exists('fastcgi_finish_request')) {
                ob_end_clean();
                echo json_encode(['success' => true, 'message' => 'Credenziali create', 'email_sent' => true]);
                fastcgi_finish_request();
            }
            try {
                $emailSent = sendPlayerActivation($email, $playerInfo['player_name'], $playerInfo['group_name'], $tempPass);
                error_log($emailSent
                    ? "[player-auth] ✅ Email attivazione inviata a $email"
                    : "[player-auth] ❌ Email attivazione fallita per $email");
            } catch (\Throwable $ex) {
                $emailSent = false;
                error_log("[player-auth] ❌ Eccezione email: " . $ex->getMessage());
            }
            if (!function_exists('fastcgi_finish_request')) {
                ob_end_clean();
                echo json_encode(['success' => true, 'message' => 'Credenziali create', 'email_sent' => $emailSent]);
            }
        } else {
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Credenziali create', 'email_sent' => false]);
        }
    } catch (Exception $e) {
        $msg = stripos($e->getMessage(), 'Duplicate') !== false
            ? 'Email o player_id già registrato'
            : 'Errore creazione credenziali';
        ob_end_clean();
        http_response_code(409);
        echo json_encode(['error' => $msg]);
    }
    exit;
}

// ══════════════════════════════════════════
// DELETE — Elimina credenziali (admin only)
// ══════════════════════════════════════════
if ($method === 'DELETE') {
    require_once __DIR__ . '/jwt_protection.php';
    $authPayload = requireAuth(['DELETE']);

    $userType = $authPayload['user_type'] ?? '';
    if (!in_array($userType, ['super_admin', 'group_admin'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Non autorizzato']);
        exit;
    }

    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $playerId = (int)($data['player_id'] ?? 0);

    if (!$playerId) {
        http_response_code(400);
        echo json_encode(['error' => 'player_id obbligatorio']);
        exit;
    }

    try {
        $affected = $pdo->prepare("DELETE FROM player_auth WHERE player_id = ?");
        $affected->execute([$playerId]);

        if ($affected->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Credenziali non trovate']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Credenziali eliminate']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore eliminazione credenziali']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non supportato']);
