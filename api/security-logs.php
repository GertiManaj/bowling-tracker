<?php
// ============================================
//  api/security-logs.php
//  GET → lista log di sicurezza con filtri
//  Richiede token JWT valido (solo admin)
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';
$slPayload = requireAuth(['GET']);

// Solo super_admin o chi ha il permesso can_view_security_logs
if (!isSuperAdmin($slPayload) && !checkPermission($slPayload, 'can_view_security_logs')) {
    http_response_code(403);
    echo json_encode(['error' => 'Accesso negato: permesso can_view_security_logs richiesto']);
    exit;
}

$pdo = getPDO();

// ── PARAMETRI ────────────────────────────────
$severity   = $_GET['severity']   ?? null;
$event_type = $_GET['event_type'] ?? null;
$days       = max(1, min(365, intval($_GET['days']  ?? 7)));
$limit      = max(1, min(500, intval($_GET['limit'] ?? 100)));

// Validazione severity
if ($severity && !in_array($severity, ['INFO', 'WARNING', 'CRITICAL'], true)) {
    $severity = null;
}

// ── QUERY PRINCIPALE ─────────────────────────
$where  = ['sl.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
$params = [$days];

if ($severity) {
    $where[]  = 'sl.severity = ?';
    $params[] = $severity;
}
if ($event_type) {
    $where[]  = 'sl.event_type = ?';
    $params[] = preg_replace('/[^a-z_]/', '', $event_type); // solo caratteri sicuri
}

$sql = "
    SELECT
        sl.id, sl.event_type, sl.severity, sl.admin_id,
        sl.ip_address, sl.details, sl.created_at,
        a.email AS admin_email, a.name AS admin_name
    FROM security_logs sl
    LEFT JOIN admins a ON sl.admin_id = a.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY sl.created_at DESC
    LIMIT ?
";
$params[] = $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// ── STATISTICHE PERIODO ───────────────────────
$statsStmt = $pdo->prepare("
    SELECT severity, COUNT(*) AS count
    FROM security_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY severity
");
$statsStmt->execute([$days]);
$stats = ['INFO' => 0, 'WARNING' => 0, 'CRITICAL' => 0];
foreach ($statsStmt->fetchAll() as $r) {
    $stats[$r['severity']] = (int)$r['count'];
}

// ── EVENTI CRITICI ULTIME 24H ─────────────────
$criticalStmt = $pdo->query("
    SELECT COUNT(*) FROM security_logs
    WHERE severity = 'CRITICAL' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$criticalLast24h = (int)$criticalStmt->fetchColumn();

// ── IP UNIVOCI ────────────────────────────────
$ipStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT ip_address) FROM security_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$ipStmt->execute([$days]);
$uniqueIps = (int)$ipStmt->fetchColumn();

// ── TIPI EVENTO DISPONIBILI ───────────────────
$typesStmt = $pdo->prepare("
    SELECT DISTINCT event_type FROM security_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY event_type
");
$typesStmt->execute([$days]);
$eventTypes = array_column($typesStmt->fetchAll(), 'event_type');

echo json_encode([
    'success'          => true,
    'logs'             => $logs,
    'stats'            => $stats,
    'critical_last24h' => $criticalLast24h,
    'unique_ips'       => $uniqueIps,
    'event_types'      => $eventTypes,
    'filters'          => [
        'severity'   => $severity,
        'event_type' => $event_type,
        'days'       => $days,
        'limit'      => $limit,
    ]
]);
