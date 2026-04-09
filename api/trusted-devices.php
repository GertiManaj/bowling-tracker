<?php
// ============================================
//  api/trusted-devices.php
//  GET  ?action=list       → elenca dispositivi
//  POST ?action=revoke     → revoca dispositivo (body: {id})
//  POST ?action=revoke-all → revoca tutti
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── JWT INLINE (evita dipendenza circolare da auth.php) ──
function td_base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}
function td_verifyJWT($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$header, $payload, $sig] = $parts;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sig)) return false;
    $data = json_decode(td_base64url_decode($payload), true);
    if (!$data || $data['exp'] < time()) return false;
    return $data;
}

$jwtSecret = getenv('JWT_SECRET') ?: 'strikezone_jwt_secret_2024';

// ── AUTENTICAZIONE ──
$bearerToken = null;
$authHeader  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/', $authHeader, $m)) $bearerToken = $m[1];
if (!$bearerToken) {
    $body = json_decode(file_get_contents('php://input'), true);
    $bearerToken = $body['token'] ?? null;
}
if (!$bearerToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}
$jwtPayload = td_verifyJWT($bearerToken, $jwtSecret);
if (!$jwtPayload) {
    http_response_code(401);
    echo json_encode(['error' => 'Token non valido o scaduto']);
    exit;
}
$adminId = (int)$jwtPayload['admin_id'];

$pdo    = getPDO();
$action = $_GET['action'] ?? '';

// ════════════════════════════════════════════
// LIST
// ════════════════════════════════════════════
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("
        SELECT id, device_identifier, ip_address, created_at, expires_at, last_used_at, token_hash
        FROM trusted_devices
        WHERE admin_id = ?
        ORDER BY last_used_at DESC, created_at DESC
    ");
    $stmt->execute([$adminId]);
    $devices = $stmt->fetchAll();

    // Determina dispositivo corrente tramite cookie
    $currentDeviceId = null;
    $tdCookie = $_COOKIE['trusted_device'] ?? null;
    if ($tdCookie && strlen($tdCookie) === 64 && ctype_xdigit($tdCookie)) {
        $cookieHash = hash('sha256', $tdCookie);
        foreach ($devices as $d) {
            if ($d['token_hash'] === $cookieHash) {
                $currentDeviceId = (int)$d['id'];
                break;
            }
        }
    }

    $result = array_map(function($d) use ($currentDeviceId) {
        return [
            'id'         => (int)$d['id'],
            'name'       => parseDeviceName($d['device_identifier'] ?? ''),
            'ip'         => $d['ip_address'],
            'created_at' => $d['created_at'],
            'expires_at' => $d['expires_at'],
            'last_used'  => $d['last_used_at'],
            'is_current' => (int)$d['id'] === $currentDeviceId
        ];
    }, $devices);

    echo json_encode(['success' => true, 'devices' => $result]);
    exit;
}

// ════════════════════════════════════════════
// REVOKE (singolo)
// ════════════════════════════════════════════
if ($action === 'revoke' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $id     = (int)($body['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID dispositivo richiesto']);
        exit;
    }

    // Verifica ownership
    $stmt = $pdo->prepare("SELECT token_hash FROM trusted_devices WHERE id = ? AND admin_id = ?");
    $stmt->execute([$id, $adminId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        http_response_code(404);
        echo json_encode(['error' => 'Dispositivo non trovato']);
        exit;
    }

    $pdo->prepare("DELETE FROM trusted_devices WHERE id = ? AND admin_id = ?")->execute([$id, $adminId]);
    logSecurityEvent($pdo, 'trusted_device_revoked', 'WARNING', $adminId, ['device_id' => $id]);

    // Se è il dispositivo corrente: cancella cookie
    $tdCookie = $_COOKIE['trusted_device'] ?? null;
    if ($tdCookie && hash('sha256', $tdCookie) === $device['token_hash']) {
        setcookie('trusted_device', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }

    echo json_encode(['success' => true, 'message' => 'Dispositivo revocato']);
    exit;
}

// ════════════════════════════════════════════
// REVOKE ALL
// ════════════════════════════════════════════
if ($action === 'revoke-all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("DELETE FROM trusted_devices WHERE admin_id = ?")->execute([$adminId]);
    setcookie('trusted_device', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    logSecurityEvent($pdo, 'trusted_device_revoked', 'WARNING', $adminId, ['all_devices' => true]);
    echo json_encode(['success' => true, 'message' => 'Tutti i dispositivi revocati']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Azione non trovata']);

// ── HELPER: parsing user agent semplificato ──
function parseDeviceName($ua) {
    if (empty($ua)) return 'Dispositivo sconosciuto';

    $browser = 'Browser';
    $os      = '';

    if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false && stripos($ua, 'OPR') === false) $browser = 'Chrome';
    elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) $browser = 'Safari';
    elseif (stripos($ua, 'Edg') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'OPR') !== false || stripos($ua, 'Opera') !== false) $browser = 'Opera';

    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) $os = 'Mac';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    return $os ? "$browser su $os" : $browser;
}
