<?php
// ============================================
//  api/logger.php — Security Event Logger
//  Uso: logSecurityEvent($pdo, 'login_failed', 'WARNING', $adminId, ['key' => 'val'])
//  Non fa require di altri file — riceve il PDO dall'esterno.
//  Non blocca mai l'applicazione in caso di errore.
// ============================================

function logSecurityEvent(PDO $pdo, string $event_type, string $severity, ?int $admin_id = null, array $details = []): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO security_logs (event_type, severity, admin_id, ip_address, user_agent, details)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $event_type,
            $severity,
            $admin_id,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500),
            json_encode($details, JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $e) {
        error_log("Security logging failed [$event_type]: " . $e->getMessage());
        return false;
    }
}
