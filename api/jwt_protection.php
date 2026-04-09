<?php
// ============================================
//  jwt_protection.php
//  Modulo riutilizzabile per proteggere endpoint API
// ============================================

/**
 * Protegge un endpoint richiedendo autenticazione JWT.
 *
 * @param array $protectedMethods Metodi da proteggere
 * @return array|null  Payload JWT decodificato, oppure null se il metodo non è protetto
 */
function requireAuth($protectedMethods = ['POST', 'PUT', 'DELETE']) {
    $method = $_SERVER['REQUEST_METHOD'];

    if (!in_array($method, $protectedMethods)) {
        return null;
    }

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato: token mancante']));
    }

    $token = $matches[1];
    $secret = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        http_response_code(401);
        die(json_encode(['error' => 'Token non valido: formato errato']));
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;

    // Verifica firma HMAC-SHA256
    $signature = base64_decode(strtr($signatureB64, '-_', '+/'));
    $expected  = hash_hmac('sha256', "$headerB64.$payloadB64", $secret, true);
    if (!hash_equals($signature, $expected)) {
        http_response_code(401);
        die(json_encode(['error' => 'Token non valido: firma non corrisponde']));
    }

    // Decodifica payload
    $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);

    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
        http_response_code(401);
        die(json_encode(['error' => 'Token scaduto']));
    }

    // ── Backward compat: JWT emessi prima dei ruoli non hanno user_type ──
    // Se assente ma admin_id esiste → è il vecchio admin Gerti = super_admin
    if (!isset($payload['user_type'])) {
        $payload['user_type'] = isset($payload['admin_id']) ? 'super_admin' : null;
    }

    // Esponi ai file che usano ancora i GLOBALS (export.php, security-logs.php)
    $GLOBALS['authenticated_admin_id']    = $payload['admin_id'] ?? null;
    $GLOBALS['authenticated_admin_email'] = $payload['email']    ?? null;
    $GLOBALS['authenticated_user_type']   = $payload['user_type'] ?? null;

    return $payload;
}

// ── HELPERS RUOLI ─────────────────────────────

/** true se il payload appartiene a un super_admin */
function isSuperAdmin(array $payload): bool {
    return ($payload['user_type'] ?? '') === 'super_admin';
}

/** Ritorna group_id dal payload (null per super_admin) */
function getGroupId(array $payload): ?int {
    $gid = $payload['group_id'] ?? null;
    return $gid !== null ? (int)$gid : null;
}

/**
 * Verifica un permesso granulare.
 * Super admin → sempre true.
 * Player       → sempre false (read-only via altro meccanismo).
 * Group admin  → controlla permissions[].
 */
function checkPermission(array $payload, string $permission): bool {
    if (isSuperAdmin($payload)) return true;
    if (($payload['user_type'] ?? '') === 'player') return false;
    return !empty($payload['permissions'][$permission]);
}
