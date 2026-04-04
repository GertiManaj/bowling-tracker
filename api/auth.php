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

// ══════════════════════════════════════════
// JWT HELPERS
// ══════════════════════════════════════════

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

function createJWT($adminId, $email, $secret, $expiresIn) {
    $header  = base64url_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = base64url_encode(json_encode([
        'iat'   => time(),
        'exp'   => time() + $expiresIn,
        'admin_id' => $adminId,
        'email' => $email,
        'role'  => 'admin'
    ]));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$sig";
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
        ':code' => $code,
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
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Resend API error: $response");
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
    
    try {
        $pdo = getPDO();
        
        // Trova admin
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = :email AND active = 1");
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            logLogin($pdo, null, $email, false, 'Email non trovata');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Credenziali non valide']);
            exit;
        }
        
        // Verifica password hashata
        if (!password_verify($password, $admin['password_hash'])) {
            logLogin($pdo, $admin['id'], $email, false, 'Password errata');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Credenziali non valide']);
            exit;
        }
        
        // Genera OTP
        $otpLength = (int)(getenv('OTP_LENGTH') ?: 6);
        $code = generateOTP($otpLength);
        
        // Salva OTP
        $expiresAt = saveOTP($pdo, $admin['id'], $code);
        
        // Invia email
        $sent = sendOTPEmail($email, $code, $admin['name']);
        
        if (!$sent) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Errore invio email']);
            exit;
        }
        
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
    
    try {
        $pdo = getPDO();
        
        // Trova admin
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = :email AND active = 1");
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Sessione non valida']);
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
            ':code' => $code
        ]);
        $otp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otp) {
            logLogin($pdo, $admin['id'], $email, false, 'OTP non valido o scaduto');
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
        
        // Genera JWT
        $token = createJWT($admin['id'], $admin['email'], $jwtSecret, $expiresIn);
        
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
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Resend API error: $response");
        return false;
    }
    
    return true;
}
http_response_code(400);
echo json_encode(['error' => 'Azione non valida']);
