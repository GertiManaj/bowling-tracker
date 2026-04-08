<?php
// ============================================
//  jwt_protection.php
//  Modulo riutilizzabile per proteggere endpoint API
//  Uso: require_once 'jwt_protection.php';
// ============================================

/**
 * Protegge un endpoint richiedendo autenticazione JWT per POST/PUT/DELETE
 * 
 * @param array $protectedMethods Metodi da proteggere (default: POST, PUT, DELETE)
 * @return void (termina con 401 se non autorizzato)
 */
function requireAuth($protectedMethods = ['POST', 'PUT', 'DELETE']) {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Se il metodo non è tra quelli protetti, passa
    if (!in_array($method, $protectedMethods)) {
        return;
    }
    
    // Ottieni header Authorization
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    // Verifica presenza token
    if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato: token mancante']));
    }
    
    $token = $matches[1];
    $secret = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';
    
    // Verifica struttura token (3 parti: header.payload.signature)
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        http_response_code(401);
        die(json_encode(['error' => 'Token non valido: formato errato']));
    }
    
    [$headerB64, $payloadB64, $signatureB64] = $parts;
    
    // Verifica firma
    $signature = base64_decode(strtr($signatureB64, '-_', '+/'));
    $expected = hash_hmac('sha256', "$headerB64.$payloadB64", $secret, true);
    
    if (!hash_equals($signature, $expected)) {
        http_response_code(401);
        die(json_encode(['error' => 'Token non valido: firma non corrisponde']));
    }
    
    // Decodifica payload
    $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
    
    // Verifica scadenza
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
        http_response_code(401);
        die(json_encode(['error' => 'Token scaduto']));
    }
    
    // Token valido - passa l'admin_id al contesto globale se serve
    $GLOBALS['authenticated_admin_id'] = $payload['admin_id'] ?? null;
    $GLOBALS['authenticated_admin_email'] = $payload['email'] ?? null;
}
