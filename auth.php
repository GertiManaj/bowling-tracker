<?php
// ============================================
//  api/auth.php
//  POST /api/auth.php?action=login   → login
//  POST /api/auth.php?action=logout  → logout
//  GET  /api/auth.php?action=check   → verifica sessione
// ============================================
require_once 'db.php';

// Avvia sessione
session_start();

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

    // Password corretta — cambiala come vuoi!
    // Per cambiarla in futuro modifica la variabile d'ambiente APP_PASSWORD su Railway
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