<?php
// ============================================
//  api/group-register.php
//  POST (pubblico) → crea gruppo + group_admin
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
$groupName   = trim($body['group_name']    ?? '');
$adminEmail  = trim($body['admin_email']   ?? '');
$adminPass   = $body['admin_password'] ?? '';
$groupDesc   = trim($body['group_description'] ?? '');
$groupType   = in_array($body['group_type'] ?? '', ['challenge', 'casual']) ? $body['group_type'] : 'challenge';
$fullName    = trim($body['admin_full_name'] ?? '');
$phone       = trim($body['admin_phone']    ?? '');

if ($groupName === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nome gruppo obbligatorio']);
    exit;
}
if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email non valida']);
    exit;
}
if (strlen($adminPass) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Password minimo 8 caratteri']);
    exit;
}

try {
    $pdo = getPDO();

    // Check email già registrata
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$adminEmail]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Email già registrata']);
        exit;
    }

    // Check nome gruppo già esistente
    $stmt = $pdo->prepare("SELECT id FROM `groups` WHERE name = ?");
    $stmt->execute([$groupName]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Nome gruppo già esistente']);
        exit;
    }

    $pdo->beginTransaction();

    // 1. Crea gruppo (created_by sarà aggiornato dopo)
    $stmt = $pdo->prepare("
        INSERT INTO `groups` (name, description, group_type, created_by)
        VALUES (?, ?, ?, NULL)
    ");
    $stmt->execute([$groupName, $groupDesc ?: null, $groupType]);
    $groupId = (int)$pdo->lastInsertId();

    // Genera invite_code univoco
    do {
        $inviteCode = strtoupper(bin2hex(random_bytes(4)));
        $chk = $pdo->prepare("SELECT id FROM `groups` WHERE invite_code = ?");
        $chk->execute([$inviteCode]);
    } while ($chk->fetch());
    $pdo->prepare("UPDATE `groups` SET invite_code = ? WHERE id = ?")->execute([$inviteCode, $groupId]);

    // 2. Crea admin
    $displayName  = $fullName ?: explode('@', $adminEmail)[0];
    $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO admins (email, password_hash, name, full_name, phone, active)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([$adminEmail, $passwordHash, $displayName, $fullName ?: null, $phone ?: null]);
    $adminId = (int)$pdo->lastInsertId();

    // 3. Aggiorna created_by nel gruppo
    $pdo->prepare("UPDATE `groups` SET created_by = ? WHERE id = ?")
        ->execute([$adminId, $groupId]);

    // 4. Assegna ruolo group_admin con tutti i permessi operativi
    $pdo->prepare("
        INSERT INTO admin_roles (
            admin_id, group_id, role,
            can_add_players, can_edit_players, can_delete_players,
            can_add_sessions, can_edit_sessions, can_delete_sessions,
            can_export_data, can_view_security_logs
        ) VALUES (?, ?, 'group_admin', 1, 1, 1, 1, 1, 1, 1, 0)
    ")->execute([$adminId, $groupId]);

    $pdo->commit();

    logSecurityEvent($pdo, 'group_self_registered', 'INFO', $adminId, [
        'group_id'   => $groupId,
        'group_name' => $groupName,
        'email'      => $adminEmail,
    ]);

    // Log prima del flush — Railway cattura questi log
    error_log("[group-register] ✅ Gruppo creato: id=$groupId name='$groupName' admin='$adminEmail' code='$inviteCode'");
    error_log("[group-register] ➤ Invio email benvenuto a $adminEmail (displayName='$displayName')");

    // Invia risposta al client PRIMA di inviare le email (evita timeout)
    $responseJson = json_encode([
        'success'     => true,
        'group_id'    => $groupId,
        'admin_id'    => $adminId,
        'invite_code' => $inviteCode,
        'message'     => 'Gruppo creato con successo',
    ]);
    header('Content-Length: ' . strlen($responseJson));
    echo $responseJson;

    // Flush risposta al client
    if (ob_get_level()) { ob_end_flush(); }
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    // Email di benvenuto (dopo aver risposto al client)
    try {
        $sent = sendWelcomeAdmin($adminEmail, $displayName, $groupName, $inviteCode);
        error_log($sent
            ? "[group-register] ✅ Email benvenuto inviata a $adminEmail"
            : "[group-register] ❌ Email benvenuto fallita per $adminEmail"
        );
    } catch (\Throwable $e) {
        error_log("[group-register] ❌ Eccezione email: " . $e->getMessage());
    }

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[group-register] ' . $e->getMessage());
    error_log('[group-register] Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Errore durante la registrazione. Riprova.']);
}
