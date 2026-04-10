<?php
// ============================================
//  api/auth.php — Autenticazione Sicura con OTP
//  POST ?action=request-otp  → Verifica password, invia OTP
//  POST ?action=verify-otp   → Verifica OTP, rilascia JWT
//  POST ?action=check        → Verifica JWT token
// ============================================

// Carica .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (!empty($k) && !isset($_ENV[$k])) { $_ENV[$k] = $v; putenv("$k=$v"); }
    }
}

// Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Config
$jwtSecret = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';
$expiresIn = 24 * 60 * 60; // 24 ore

// Database connection
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

// ══════════════════════════════════════════
// JWT HELPERS
// ══════════════════════════════════════════

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

function createJWT($adminId, $email, $secret, $expiresIn, array $roleData = []) {
    $userType = $roleData['role'] ?? 'super_admin'; // default safe per admin esistenti
    $groupId  = isset($roleData['group_id']) ? (int)$roleData['group_id'] : null;

    $payloadData = [
        'iat'       => time(),
        'exp'       => time() + $expiresIn,
        'admin_id'  => $adminId,
        'email'     => $email,
        'user_type' => $userType,
        'group_id'  => $groupId,
    ];

    if ($userType === 'group_admin' && !empty($roleData)) {
        $payloadData['permissions'] = [
            'can_add_players'        => (bool)($roleData['can_add_players']        ?? true),
            'can_edit_players'       => (bool)($roleData['can_edit_players']       ?? true),
            'can_delete_players'     => (bool)($roleData['can_delete_players']     ?? false),
            'can_add_sessions'       => (bool)($roleData['can_add_sessions']       ?? true),
            'can_edit_sessions'      => (bool)($roleData['can_edit_sessions']      ?? true),
            'can_delete_sessions'    => (bool)($roleData['can_delete_sessions']    ?? false),
            'can_export_data'        => (bool)($roleData['can_export_data']        ?? false),
            'can_view_security_logs' => (bool)($roleData['can_view_security_logs'] ?? false),
        ];
    }

    $header  = base64url_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = base64url_encode(json_encode($payloadData));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$sig";
}

/** Recupera il record admin_roles per un admin. Ritorna [] se non trovato/tabella assente. */
function fetchAdminRole(PDO $pdo, int $adminId): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM admin_roles WHERE admin_id = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$adminId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return []; // tabella non ancora esistente (pre-migration 012)
    }
}

function verifyJWT($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$header, $payload, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    if (!hash_equals($expected, $sig)) return false;
    $data = json_decode(base64url_decode($payload), true);
    if (!$data || $data['exp'] < time()) return false;
    return $data;
}

// ══════════════════════════════════════════
// OTP HELPERS
// ══════════════════════════════════════════

function generateOTP($length = 6) {
    $digits = '';
    for ($i = 0; $i < $length; $i++) {
        $digits .= random_int(0, 9);
    }
    return $digits;
}

function saveOTP($pdo, $adminId, $code) {
    $expiryMinutes = (int)(getenv('OTP_EXPIRY_MINUTES') ?: 5);
    $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));
    
    $stmt = $pdo->prepare("
        INSERT INTO otp_codes (admin_id, code, expires_at, ip_address, user_agent)
        VALUES (:admin_id, :code, :expires_at, :ip, :ua)
    ");
    
    $stmt->execute([
        ':admin_id' => $adminId,
        ':code' => hash('sha256', $code),
        ':expires_at' => $expiresAt,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    return $expiresAt;
}

function sendOTPEmail($email, $code, $name = 'Admin') {
    $apiKey = getenv('RESEND_API_KEY');
    if (!$apiKey) {
        error_log('RESEND_API_KEY not configured');
        return false;
    }
    
    $fromEmail = getenv('EMAIL_FROM') ?: 'noreply@resend.dev';
    $fromName = getenv('EMAIL_FROM_NAME') ?: 'Strike Zone';
    $appName = getenv('APP_NAME') ?: 'Strike Zone';
    
    $subject = "🎳 Il tuo codice di accesso - $appName";
    
    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #e8ff00 0%, #00f5ff 100%); padding: 30px; text-align: center; }
            .header h1 { margin: 0; color: #0a0a0f; font-size: 28px; }
            .body { padding: 40px 30px; }
            .otp-code { background: #0a0a0f; color: #e8ff00; font-size: 36px; font-weight: bold; letter-spacing: 8px; text-align: center; padding: 20px; border-radius: 8px; margin: 30px 0; font-family: 'Courier New', monospace; }
            .info { color: #666; font-size: 14px; line-height: 1.6; }
            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; color: #856404; }
            .footer { background: #f8f8f8; padding: 20px; text-align: center; font-size: 12px; color: #999; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎳 $appName</h1>
            </div>
            <div class='body'>
                <p style='font-size: 16px; color: #333;'>Ciao $name,</p>
                <p class='info'>Ecco il tuo codice di accesso temporaneo:</p>
                
                <div class='otp-code'>$code</div>
                
                <p class='info'>Questo codice scadrà tra <strong>5 minuti</strong>.</p>
                
                <div class='warning'>
                    <strong>⚠️ Attenzione:</strong> Non condividere questo codice con nessuno. Il nostro staff non ti chiederà mai il codice OTP.
                </div>
                
                <p class='info'>Se non hai richiesto questo accesso, ignora questa email.</p>
            </div>
            <div class='footer'>
                <p>$appName - Sistema di Autenticazione Sicuro</p>
                <p>Questa è un'email automatica, non rispondere.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $data = [
        'from' => "$fromName <$fromEmail>",
        'to' => [$email],
        'subject' => $subject,
        'html' => $html
    ];
    
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        error_log("[OTP] Resend curl error: $curlError");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[OTP] Resend HTTP $httpCode: $response");
        return false;
    }

    return true;
}

function logLogin($pdo, $adminId, $email, $success, $reason = null) {
    $stmt = $pdo->prepare("
        INSERT INTO login_logs (admin_id, email, success, failure_reason, ip_address, user_agent)
        VALUES (:admin_id, :email, :success, :reason, :ip, :ua)
    ");
    
    $stmt->execute([
        ':admin_id' => $adminId,
        ':email' => $email,
        ':success' => $success ? 1 : 0,
        ':reason' => $reason,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

// ══════════════════════════════════════════
// ACTION: REQUEST OTP
// ══════════════════════════════════════════

if ($_GET['action'] === 'request-otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email e password richiesti']);
        exit;
    }

    error_log("[OTP-REQ] Email: $email | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
    error_log("[OTP-REQ] SKIP_OTP: " . (getenv('SKIP_OTP_FOR_TESTING') ?: 'false') . " | RESEND_KEY: " . (getenv('RESEND_API_KEY') ? 'SI' : 'NO'));

    try {
        $pdo = getPDO();

        // ── RATE LIMITING: max 10 tentativi falliti per IP negli ultimi 15 minuti ──
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmtRL = $pdo->prepare("
            SELECT COUNT(*) FROM login_logs
            WHERE ip_address = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmtRL->execute([$clientIp]);
        if ((int)$stmtRL->fetchColumn() >= 10) {
            logSecurityEvent($pdo, 'login_blocked_rate_limit', 'CRITICAL', null, ['ip' => $clientIp, 'email' => $email]);
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Troppi tentativi. Riprova tra 15 minuti.']);
            exit;
        }

        // Trova admin
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = :email AND active = 1");
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            error_log("[OTP-REQ] Admin NON trovato per: $email");
            logLogin($pdo, null, $email, false, 'Email non trovata');
            logSecurityEvent($pdo, 'login_failed', 'WARNING', null, ['email' => $email, 'reason' => 'email_not_found']);
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Credenziali non valide']);
            exit;
        }

        error_log("[OTP-REQ] Admin trovato: ID {$admin['id']} | Nome: {$admin['name']}");

        // Verifica password hashata
        if (!password_verify($password, $admin['password_hash'])) {
            error_log("[OTP-REQ] Password NON valida per: $email");
            logLogin($pdo, $admin['id'], $email, false, 'Password errata');
            logSecurityEvent($pdo, 'login_failed', 'WARNING', $admin['id'], ['email' => $email, 'reason' => 'wrong_password']);
            // Rileva tentativi multipli (>3 fallimenti per IP negli ultimi 5 minuti)
            $stmtMFL = $pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE ip_address = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
            $stmtMFL->execute([$clientIp]);
            if ((int)$stmtMFL->fetchColumn() > 3) {
                logSecurityEvent($pdo, 'multiple_failed_logins', 'CRITICAL', null, ['ip' => $clientIp, 'email' => $email]);
            }
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Credenziali non valide']);
            exit;
        }
        
        // ── TRUSTED DEVICE: salta OTP se dispositivo fidato ──
        $tdCookie = $_COOKIE['trusted_device'] ?? null;
        if ($tdCookie && strlen($tdCookie) === 64 && ctype_xdigit($tdCookie)) {
            $tdHash = hash('sha256', $tdCookie);
            $stmtTD = $pdo->prepare("
                SELECT id FROM trusted_devices
                WHERE token_hash = ? AND admin_id = ? AND expires_at > NOW()
                LIMIT 1
            ");
            $stmtTD->execute([$tdHash, $admin['id']]);
            $td = $stmtTD->fetch(PDO::FETCH_ASSOC);
            if ($td) {
                $pdo->prepare("UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?")->execute([$td['id']]);
                $pdo->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
                logLogin($pdo, $admin['id'], $email, true);
                logSecurityEvent($pdo, 'trusted_device_used', 'INFO', $admin['id'], ['email' => $email]);
                logSecurityEvent($pdo, 'login_success', 'INFO', $admin['id'], ['email' => $email, 'via' => 'trusted_device']);
                $roleData   = fetchAdminRole($pdo, $admin['id']);
                $jwtTrusted = createJWT($admin['id'], $admin['email'], $jwtSecret, $expiresIn, $roleData);
                echo json_encode([
                    'success'        => true,
                    'trusted_device' => true,
                    'token'          => $jwtTrusted,
                    'expires_in'     => $expiresIn,
                    'admin'          => ['id' => $admin['id'], 'email' => $admin['email'], 'name' => $admin['name']]
                ]);
                exit;
            }
        }

        error_log("[OTP-REQ] Password valida per: $email — procedo con OTP");

        // ── SKIP OTP per testing (SKIP_OTP_FOR_TESTING=true in Railway) ──
        if (getenv('SKIP_OTP_FOR_TESTING') === 'true') {
            error_log("[OTP-SKIP] SKIP_OTP_FOR_TESTING attivo — JWT diretto per: $email");
            $pdo->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
            logLogin($pdo, $admin['id'], $email, true);
            logSecurityEvent($pdo, 'login_success', 'INFO', $admin['id'], ['email' => $email, 'via' => 'skip_otp_testing']);
            $roleData  = fetchAdminRole($pdo, $admin['id']);
            $tokenSkip = createJWT($admin['id'], $admin['email'], $jwtSecret, $expiresIn, $roleData);
            echo json_encode([
                'success'     => true,
                'otp_skipped' => true,
                'token'       => $tokenSkip,
                'expires_in'  => $expiresIn,
                'admin'       => ['id' => $admin['id'], 'email' => $admin['email'], 'name' => $admin['name']]
            ]);
            exit;
        }

        // Genera OTP
        $otpLength = (int)(getenv('OTP_LENGTH') ?: 6);
        $code = generateOTP($otpLength);
        
        // Salva OTP
        $expiresAt = saveOTP($pdo, $admin['id'], $code);
        
        // Invia email
        error_log("[OTP-REQ] Invio OTP a: $email | RESEND_KEY: " . (getenv('RESEND_API_KEY') ? 'SI' : 'NO'));
        $sent = sendOTPEmail($email, $code, $admin['name']);

        if (!$sent) {
            $apiKey = getenv('RESEND_API_KEY');
            $hint = !$apiKey
                ? 'RESEND_API_KEY non configurata nelle variabili Railway'
                : 'Verifica dominio EMAIL_FROM su resend.com e variabili Railway';
            error_log("[OTP] Invio email fallito per $email. $hint");
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Errore invio email OTP', 'hint' => $hint]);
            exit;
        }
        
        logSecurityEvent($pdo, 'otp_requested', 'INFO', $admin['id'], ['email' => $email]);
        echo json_encode([
            'success' => true,
            'message' => 'Codice OTP inviato via email',
            'expires_at' => $expiresAt
        ]);
        
    } catch (Exception $e) {
        error_log("Auth error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore del server']);
    }
    
    exit;
}

// ══════════════════════════════════════════
// ACTION: VERIFY OTP
// ══════════════════════════════════════════

if ($_GET['action'] === 'verify-otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $email = trim($body['email'] ?? '');
    $code = trim($body['code'] ?? '');

    if (empty($email) || empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email e codice richiesti']);
        exit;
    }

    error_log("[OTP-VER] Email: $email | Codice fornito: $code | SKIP_OTP: " . (getenv('SKIP_OTP_FOR_TESTING') ?: 'false'));

    try {
        $pdo = getPDO();

        // Trova admin
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = :email AND active = 1");
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            error_log("[OTP-VER] Admin NON trovato per: $email");
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Sessione non valida']);
            exit;
        }

        error_log("[OTP-VER] Admin trovato: ID {$admin['id']}");

        // ── SKIP OTP: accetta '123456' come codice fisso se in modalità test ──
        if (getenv('SKIP_OTP_FOR_TESTING') === 'true' && $code === '123456') {
            error_log("[OTP-SKIP] Codice 123456 accettato per: $email");
            $pdo->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
            logLogin($pdo, $admin['id'], $email, true);
            logSecurityEvent($pdo, 'login_success', 'INFO', $admin['id'], ['email' => $email, 'via' => 'skip_otp_123456']);
            $roleData  = fetchAdminRole($pdo, $admin['id']);
            $tokenSkip = createJWT($admin['id'], $admin['email'], $jwtSecret, $expiresIn, $roleData);
            echo json_encode([
                'success'    => true,
                'token'      => $tokenSkip,
                'expires_in' => $expiresIn,
                'admin'      => ['id' => $admin['id'], 'email' => $admin['email'], 'name' => $admin['name']]
            ]);
            exit;
        }

        // Trova OTP valido
        $stmt = $pdo->prepare("
            SELECT * FROM otp_codes 
            WHERE admin_id = :admin_id 
            AND code = :code 
            AND used = 0 
            AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':code' => hash('sha256', $code)
        ]);
        $otp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otp) {
            // Determina il motivo specifico del fallimento OTP
            $stmtOtpCheck = $pdo->prepare("
                SELECT used, expires_at FROM otp_codes
                WHERE admin_id = ? AND code = ?
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmtOtpCheck->execute([$admin['id'], hash('sha256', $code)]);
            $otpCheck = $stmtOtpCheck->fetch(PDO::FETCH_ASSOC);

            if ($otpCheck && $otpCheck['used']) {
                $otpFailReason = 'already_used';
            } elseif ($otpCheck && strtotime($otpCheck['expires_at']) < time()) {
                $otpFailReason = 'expired';
            } elseif ($otpCheck) {
                $otpFailReason = 'wrong_code'; // non dovrebbe accadere ma per sicurezza
            } else {
                $otpFailReason = 'wrong_code'; // hash non trovato = codice sbagliato
            }

            logLogin($pdo, $admin['id'], $email, false, 'OTP non valido o scaduto');
            logSecurityEvent($pdo, 'otp_failed', 'WARNING', $admin['id'], ['email' => $email, 'reason' => $otpFailReason]);
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Codice non valido o scaduto']);
            exit;
        }
        
        // Marca OTP come usato
        $stmt = $pdo->prepare("UPDATE otp_codes SET used = 1, used_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $otp['id']]);
        
        // Aggiorna last_login
        $stmt = $pdo->prepare("UPDATE admins SET last_login = NOW() WHERE id = :id");
        $stmt->execute([':id' => $admin['id']]);
        
        // Log successo
        logLogin($pdo, $admin['id'], $email, true);
        logSecurityEvent($pdo, 'otp_verified', 'INFO', $admin['id'], ['email' => $email]);
        logSecurityEvent($pdo, 'login_success', 'INFO', $admin['id'], ['email' => $email, 'via' => 'otp']);

        // Genera JWT con ruolo
        $roleData = fetchAdminRole($pdo, $admin['id']);
        $token    = createJWT($admin['id'], $admin['email'], $jwtSecret, $expiresIn, $roleData);

        // ── TRUSTED DEVICE: imposta cookie se richiesto ──
        if (!empty($body['remember_device'])) {
            $rawToken  = bin2hex(random_bytes(32));
            $tdHash    = hash('sha256', $rawToken);
            $tdExpires = date('Y-m-d H:i:s', time() + (7 * 24 * 60 * 60));
            $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $ip        = $_SERVER['REMOTE_ADDR'] ?? null;

            // Max 5 dispositivi per admin: elimina il più vecchio se necessario
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM trusted_devices WHERE admin_id = ?");
            $countStmt->execute([$admin['id']]);
            if ((int)$countStmt->fetchColumn() >= 5) {
                $pdo->prepare("
                    DELETE FROM trusted_devices WHERE id = (
                        SELECT id FROM (SELECT id FROM trusted_devices WHERE admin_id = ? ORDER BY created_at ASC LIMIT 1) sub
                    )
                ")->execute([$admin['id']]);
            }

            $pdo->prepare("
                INSERT INTO trusted_devices (admin_id, token_hash, device_identifier, user_agent, ip_address, expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$admin['id'], $tdHash, $ua, $ua, $ip, $tdExpires]);
            logSecurityEvent($pdo, 'trusted_device_created', 'INFO', $admin['id'], ['email' => $email, 'expires_at' => $tdExpires]);

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie('trusted_device', $rawToken, [
                'expires'  => time() + (7 * 24 * 60 * 60),
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        echo json_encode([
            'success' => true,
            'token' => $token,
            'expires_in' => $expiresIn,
            'admin' => [
                'id' => $admin['id'],
                'email' => $admin['email'],
                'name' => $admin['name']
            ]
        ]);

    } catch (Exception $e) {
        error_log("OTP verify error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore del server']);
    }
    
    exit;
}

// ══════════════════════════════════════════
// ACTION: CHECK TOKEN
// ══════════════════════════════════════════

if ($_GET['action'] === 'check') {
    $token = null;
    
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $authHeader, $m)) $token = $m[1];
    
    if (!$token) {
        $body = json_decode(file_get_contents('php://input'), true);
        $token = $body['token'] ?? null;
    }
    
    if (!$token) {
        echo json_encode(['logged_in' => false]);
        exit;
    }
    
    $data = verifyJWT($token, $jwtSecret);
    echo json_encode([
        'logged_in' => (bool)$data,
        'expires_at' => $data ? $data['exp'] : null,
        'admin' => $data ? [
            'id' => $data['admin_id'] ?? null,
            'email' => $data['email'] ?? null
        ] : null
    ]);
    
    exit;
}

// ══════════════════════════════════════════
// ACTION: CHANGE PASSWORD (utente loggato)
// ══════════════════════════════════════════

if ($_GET['action'] === 'change-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = null;
    
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $authHeader, $m)) $token = $m[1];
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Non autenticato']);
        exit;
    }
    
    $data = verifyJWT($token, $jwtSecret);
    if (!$data) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token non valido']);
        exit;
    }
    
    $body = json_decode(file_get_contents('php://input'), true);
    $currentPassword = $body['current_password'] ?? '';
    $newPassword = $body['new_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Password richieste']);
        exit;
    }
    
    if (strlen($newPassword) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La nuova password deve essere di almeno 8 caratteri']);
        exit;
    }
    
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ? AND active = 1");
        $stmt->execute([$data['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Admin non trovato']);
            exit;
        }
        
        if (!password_verify($currentPassword, $admin['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Password attuale errata']);
            exit;
        }
        
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newHash, $admin['id']]);

        // Revoca tutti i dispositivi fidati
        $pdo->prepare("DELETE FROM trusted_devices WHERE admin_id = ?")->execute([$admin['id']]);
        setcookie('trusted_device', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        logSecurityEvent($pdo, 'password_changed', 'WARNING', $admin['id'], ['email' => $admin['email']]);

        echo json_encode(['success' => true, 'message' => 'Password aggiornata con successo']);
        
    } catch (Exception $e) {
        error_log("Change password error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore del server']);
    }
    
    exit;
}

// ══════════════════════════════════════════
// ACTION: REQUEST PASSWORD RESET
// ══════════════════════════════════════════

if ($_GET['action'] === 'request-reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $email = trim($body['email'] ?? '');
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email richiesta']);
        exit;
    }
    
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? AND active = 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Sempre successo per sicurezza (non rivelare se email esiste)
        if (!$admin) {
            echo json_encode(['success' => true, 'message' => 'Se l\'email esiste, riceverai le istruzioni']);
            exit;
        }
        
        // Genera token sicuro
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (60 * 60)); // 1 ora
        
        $stmt = $pdo->prepare("
            INSERT INTO password_resets (admin_id, token, expires_at, ip_address)
            VALUES (:admin_id, :token, :expires_at, :ip)
        ");
        
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':token' => $token,
            ':expires_at' => $expiresAt,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        // Invia email
        $resetLink = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                     $_SERVER['HTTP_HOST'] . 
                     '/frontend/pages/reset-password.html?token=' . $token;
        
        $sent = sendPasswordResetEmail($admin['email'], $resetLink, $admin['name']);

        if (!$sent) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Errore invio email']);
            exit;
        }

        logSecurityEvent($pdo, 'password_reset_requested', 'WARNING', $admin['id'], ['email' => $email]);
        echo json_encode(['success' => true, 'message' => 'Se l\'email esiste, riceverai le istruzioni']);
        
    } catch (Exception $e) {
        error_log("Request reset error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore del server']);
    }
    
    exit;
}

// ══════════════════════════════════════════
// ACTION: RESET PASSWORD
// ══════════════════════════════════════════

if ($_GET['action'] === 'reset-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $token = trim($body['token'] ?? '');
    $newPassword = $body['password'] ?? '';
    
    if (empty($token) || empty($newPassword)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Token e password richiesti']);
        exit;
    }
    
    if (strlen($newPassword) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La password deve essere di almeno 8 caratteri']);
        exit;
    }
    
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->prepare("
            SELECT * FROM password_resets 
            WHERE token = :token 
            AND used = 0 
            AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reset) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Token non valido o scaduto']);
            exit;
        }
        
        // Aggiorna password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newHash, $reset['admin_id']]);

        // Marca token come usato
        $stmt = $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE id = ?");
        $stmt->execute([$reset['id']]);

        // Revoca tutti i dispositivi fidati
        $pdo->prepare("DELETE FROM trusted_devices WHERE admin_id = ?")->execute([$reset['admin_id']]);
        setcookie('trusted_device', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        logSecurityEvent($pdo, 'password_reset_completed', 'WARNING', $reset['admin_id'], []);

        echo json_encode(['success' => true, 'message' => 'Password reimpostata con successo']);
        
    } catch (Exception $e) {
        error_log("Reset password error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore del server']);
    }
    
    exit;
}

function sendPasswordResetEmail($email, $resetLink, $name = 'Admin') {
    $apiKey = getenv('RESEND_API_KEY');
    if (!$apiKey) {
        error_log('RESEND_API_KEY not configured');
        return false;
    }
    
    $fromEmail = getenv('EMAIL_FROM') ?: 'noreply@resend.dev';
    $fromName = getenv('EMAIL_FROM_NAME') ?: 'Strike Zone';
    $appName = getenv('APP_NAME') ?: 'Strike Zone';
    
    $subject = "🔐 Reset della password - $appName";
    
    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #e8ff00 0%, #00f5ff 100%); padding: 30px; text-align: center; }
            .header h1 { margin: 0; color: #0a0a0f; font-size: 28px; }
            .body { padding: 40px 30px; }
            .info { color: #666; font-size: 14px; line-height: 1.6; margin-bottom: 20px; }
            .btn { display: inline-block; background: #e8ff00; color: #0a0a0f; text-decoration: none; padding: 15px 40px; border-radius: 8px; font-weight: bold; margin: 20px 0; }
            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; color: #856404; }
            .footer { background: #f8f8f8; padding: 20px; text-align: center; font-size: 12px; color: #999; }
            .link { color: #666; word-break: break-all; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎳 $appName</h1>
            </div>
            <div class='body'>
                <p style='font-size: 16px; color: #333;'>Ciao $name,</p>
                <p class='info'>Hai richiesto il reset della tua password. Clicca sul pulsante qui sotto per reimpostarla:</p>
                
                <div style='text-align: center;'>
                    <a href='$resetLink' class='btn'>Reimposta Password</a>
                </div>
                
                <p class='info'>Oppure copia e incolla questo link nel browser:</p>
                <p class='link'>$resetLink</p>
                
                <div class='warning'>
                    <strong>⚠️ Attenzione:</strong> Questo link scadrà tra <strong>1 ora</strong>. Se non hai richiesto questo reset, ignora questa email.
                </div>
            </div>
            <div class='footer'>
                <p>$appName - Sistema di Autenticazione Sicuro</p>
                <p>Questa è un'email automatica, non rispondere.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $data = [
        'from' => "$fromName <$fromEmail>",
        'to' => [$email],
        'subject' => $subject,
        'html' => $html
    ];
    
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        error_log("[RESET] Resend curl error: $curlError");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[RESET] Resend HTTP $httpCode: $response");
        return false;
    }

    return true;
}
http_response_code(400);
echo json_encode(['error' => 'Azione non valida']);
