<?php
// ============================================
//  api/auth.php — Login / Logout / Check
// ============================================

// IMPORTANTE: session_start() PRIMA di tutto
session_start();

// Carica .env se esiste (solo in locale)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key); $value = trim($value);
        if (!empty($key) && !isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Headers JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_GET['action'] ?? 'check';

// ── CHECK ────────────────────────────────────
if ($action === 'check') {
    echo json_encode([
        'logged_in' => isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true
    ]);
    exit;
}

// ── LOGIN ────────────────────────────────────
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true);
    $password = $data['password'] ?? '';

    $correctPassword = getenv('APP_PASSWORD') ?: 'strikezone2024';

    if ($password === $correctPassword) {
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time']    = time();
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Password errata']);
    }
    exit;
}

// ── LOGOUT ───────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Azione non valida']);