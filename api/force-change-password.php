<?php
// ============================================
//  api/force-change-password.php
//  POST — Cambio password obbligatorio (primo accesso player)
//  Non richiede JWT: autenticato con vecchia password
// ============================================
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non permesso']);
    exit;
}

$data        = json_decode(file_get_contents('php://input'), true) ?? [];
$email       = trim($data['email']        ?? '');
$oldPassword = $data['old_password']      ?? '';
$newPassword = $data['new_password']      ?? '';

if (!$email || !$oldPassword || !$newPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'email, old_password e new_password obbligatori']);
    exit;
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'La nuova password deve essere di almeno 8 caratteri']);
    exit;
}

try {
    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        SELECT id, password_hash, must_change_password, active
        FROM player_auth
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $pa = $stmt->fetch();

    if (!$pa || !$pa['active']) {
        http_response_code(401);
        echo json_encode(['error' => 'Credenziali non valide']);
        exit;
    }

    if (!password_verify($oldPassword, $pa['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Password temporanea errata']);
        exit;
    }

    // Il flag deve essere attivo — blocca richieste non necessarie
    if (empty($pa['must_change_password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Nessun cambio password richiesto per questo account']);
        exit;
    }

    // La nuova password deve essere diversa dalla temporanea
    if (password_verify($newPassword, $pa['password_hash'])) {
        http_response_code(400);
        echo json_encode(['error' => 'La nuova password deve essere diversa da quella temporanea']);
        exit;
    }

    $pdo->prepare("
        UPDATE player_auth
        SET password_hash = ?, must_change_password = 0
        WHERE id = ?
    ")->execute([password_hash($newPassword, PASSWORD_DEFAULT), $pa['id']]);

    echo json_encode(['success' => true, 'message' => 'Password aggiornata']);
} catch (Exception $e) {
    error_log('[force-change-password] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore server']);
}
