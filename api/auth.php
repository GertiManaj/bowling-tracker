<?php
// ============================================
//  api/auth.php — Autenticazione con JWT
//  GET  ?action=check  → verifica token
//  POST ?action=login  → login, restituisce token
//  POST ?action=logout → (gestito lato client)
// ============================================

// Carica .env se esiste
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (!empty($k) && !isset($_ENV[$k])) { $_ENV[$k] = $v; putenv("$k=$v"); }
    }
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action          = $_GET['action'] ?? 'check';
$correctPassword = getenv('APP_PASSWORD') ?: 'strikezone2024';
$jwtSecret       = getenv('JWT_SECRET')   ?: 'strikezone_jwt_secret_2024';
$expiresIn       = 24 * 60 * 60; // 24 ore in secondi

// ── JWT helpers ──────────────────────────────

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

function createJWT($secret, $expiresIn) {
    $header  = base64url_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = base64url_encode(json_encode([
        'iat'  => time(),
        'exp'  => time() + $expiresIn,
        'role' => 'admin'
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

// ── CHECK ────────────────────────────────────
if ($action === 'check') {
    $token = null;

    // Cerca token nell'header Authorization
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $authHeader, $m)) $token = $m[1];

    // Oppure nel body
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
    ]);
    exit;
}

// ── LOGIN ────────────────────────────────────
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $password = $body['password'] ?? '';

    if ($password === $correctPassword) {
        $token = createJWT($jwtSecret, $expiresIn);
        echo json_encode([
            'success'    => true,
            'token'      => $token,
            'expires_in' => $expiresIn,
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Password errata']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Azione non valida']);