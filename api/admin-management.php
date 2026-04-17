<?php
// ============================================
//  api/admin-management.php
//  Gestione admin e permessi (solo super_admin)
//  GET    → lista admin con ruoli
//  POST   → crea admin + assegna ruolo
//  PUT    → modifica permessi admin
//  DELETE → elimina admin
// ============================================
require_once __DIR__ . '/jwt_protection.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

$method  = $_SERVER['REQUEST_METHOD'];
$payload = requireAuth(['GET', 'POST', 'PUT', 'DELETE']);

// Solo super_admin
if (!isSuperAdmin($payload)) {
    http_response_code(403);
    echo json_encode(['error' => 'Solo super_admin può gestire gli admin']);
    exit;
}

$pdo = getPDO();

// ══════════════════════════════════════════
// GET — lista admin con ruoli
// ══════════════════════════════════════════
if ($method === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT
                a.id, a.email, a.name, a.full_name, a.phone,
                a.active, a.last_login, a.created_at,
                ar.id             AS role_id,
                ar.group_id,
                ar.role,
                ar.can_add_players,
                ar.can_edit_players,
                ar.can_delete_players,
                ar.can_add_sessions,
                ar.can_edit_sessions,
                ar.can_delete_sessions,
                ar.can_export_data,
                ar.can_view_security_logs,
                g.name            AS group_name
            FROM admins a
            LEFT JOIN admin_roles ar ON a.id = ar.admin_id
            LEFT JOIN `groups`    g  ON ar.group_id = g.id
            ORDER BY a.created_at ASC
        ");
        echo json_encode(['success' => true, 'admins' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore recupero admin']);
    }
    exit;
}

// ══════════════════════════════════════════
// POST — crea admin + ruolo
// ══════════════════════════════════════════
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $email    = trim($data['email']    ?? '');
    $password = $data['password'] ?? '';

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Email e password obbligatori']);
        exit;
    }

    if (strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password minimo 8 caratteri']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        // `name` è NOT NULL nella tabella admins — usa full_name o prefisso email come fallback
        $displayName  = $data['full_name'] ?? $data['name'] ?? explode('@', $email)[0];

        $stmt = $pdo->prepare("
            INSERT INTO admins (email, password_hash, name, full_name, phone, active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $email,
            $passwordHash,
            $displayName,
            $data['full_name'] ?? null,
            $data['phone']     ?? null,
        ]);
        $adminId = (int)$pdo->lastInsertId();

        // Assegna ruolo
        $role    = in_array($data['role'] ?? '', ['super_admin', 'group_admin']) ? $data['role'] : 'group_admin';
        $groupId = ($role === 'super_admin') ? null : ((int)($data['group_id'] ?? 0) ?: null);

        $pdo->prepare("
            INSERT INTO admin_roles (
                admin_id, group_id, role,
                can_add_players, can_edit_players, can_delete_players,
                can_add_sessions, can_edit_sessions, can_delete_sessions,
                can_export_data, can_view_security_logs
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $adminId, $groupId, $role,
            (int)($data['can_add_players']        ?? 1),
            (int)($data['can_edit_players']        ?? 1),
            (int)($data['can_delete_players']      ?? 0),
            (int)($data['can_add_sessions']        ?? 1),
            (int)($data['can_edit_sessions']       ?? 1),
            (int)($data['can_delete_sessions']     ?? 0),
            (int)($data['can_export_data']         ?? 0),
            (int)($data['can_view_security_logs']  ?? 0),
        ]);

        $pdo->commit();

        logSecurityEvent($pdo, 'admin_created', 'WARNING', $payload['admin_id'] ?? null, [
            'new_admin_id' => $adminId,
            'email'        => $email,
            'role'         => $role,
            'group_id'     => $groupId,
        ]);

        echo json_encode(['success' => true, 'admin_id' => $adminId, 'message' => 'Admin creato con successo']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = stripos($e->getMessage(), 'Duplicate') !== false
            ? "Email '$email' già esistente"
            : 'Errore creazione admin';
        http_response_code(409);
        echo json_encode(['error' => $msg]);
    }
    exit;
}

// ══════════════════════════════════════════
// PUT — modifica permessi / gruppo
// ══════════════════════════════════════════
if ($method === 'PUT') {
    $data    = json_decode(file_get_contents('php://input'), true) ?? [];
    $roleId  = (int)($data['role_id'] ?? 0);

    if (!$roleId) {
        http_response_code(400);
        echo json_encode(['error' => 'role_id obbligatorio']);
        exit;
    }

    $perms  = [
        'can_add_players', 'can_edit_players', 'can_delete_players',
        'can_add_sessions', 'can_edit_sessions', 'can_delete_sessions',
        'can_export_data', 'can_view_security_logs',
    ];
    $fields = ['group_id', 'role', ...$perms];

    $validRoles = ['super_admin', 'group_admin'];
    $updates = [];
    $params  = [];

    foreach ($fields as $f) {
        if (!array_key_exists($f, $data)) continue;
        if ($f === 'role' && !in_array($data[$f], $validRoles, true)) continue; // Ignora ruoli non validi
        $updates[] = "$f = ?";
        $params[]  = in_array($f, $perms)
            ? (int)(bool)$data[$f]
            : ($data[$f] === null || $data[$f] === '' ? null : $data[$f]);
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nessun campo da aggiornare']);
        exit;
    }

    $params[] = $roleId;

    try {
        $pdo->prepare("UPDATE admin_roles SET " . implode(', ', $updates) . " WHERE id = ?")
            ->execute($params);

        logSecurityEvent($pdo, 'permissions_changed', 'WARNING', $payload['admin_id'] ?? null, ['role_id' => $roleId]);
        echo json_encode(['success' => true, 'message' => 'Permessi aggiornati']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore aggiornamento permessi']);
    }
    exit;
}

// ══════════════════════════════════════════
// DELETE — elimina admin
// ══════════════════════════════════════════
if ($method === 'DELETE') {
    $data    = json_decode(file_get_contents('php://input'), true) ?? [];
    $adminId = (int)($data['admin_id'] ?? 0);

    if (!$adminId) {
        http_response_code(400);
        echo json_encode(['error' => 'admin_id obbligatorio']);
        exit;
    }

    // Non può eliminare se stesso
    if ($adminId === (int)($payload['admin_id'] ?? 0)) {
        http_response_code(400);
        echo json_encode(['error' => 'Non puoi eliminare te stesso']);
        exit;
    }

    try {
        $pdo->prepare("DELETE FROM admin_roles WHERE admin_id = ?")->execute([$adminId]);
        $affected = $pdo->prepare("DELETE FROM admins WHERE id = ?");
        $affected->execute([$adminId]);

        if ($affected->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Admin non trovato']);
            exit;
        }

        logSecurityEvent($pdo, 'admin_deleted', 'CRITICAL', $payload['admin_id'] ?? null, [
            'deleted_admin_id' => $adminId,
        ]);
        echo json_encode(['success' => true, 'message' => 'Admin eliminato']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore eliminazione admin']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non supportato']);
