<?php
// ============================================
//  api/groups.php
//  Gestione gruppi bowling (solo super_admin)
//  GET    → lista gruppi con statistiche
//  POST   → crea gruppo
//  PUT    → modifica gruppo
//  DELETE → elimina gruppo
// ============================================
require_once __DIR__ . '/jwt_protection.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

$method   = $_SERVER['REQUEST_METHOD'];
$payload  = requireAuth(['GET', 'POST', 'PUT', 'DELETE']);
$userType = $payload['user_type'] ?? '';

// group_admin: solo GET del proprio gruppo
if ($userType === 'group_admin' && $method !== 'GET') {
    http_response_code(403);
    echo json_encode(['error' => 'Solo super_admin può modificare i gruppi']);
    exit;
}

// Tutti gli altri ruoli (player, ecc.) → negato
if (!in_array($userType, ['super_admin', 'group_admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Accesso non autorizzato']);
    exit;
}

$pdo = getPDO();

// ══════════════════════════════════════════
// GET — lista gruppi con statistiche
// ══════════════════════════════════════════
if ($method === 'GET') {
    try {
        $sql = "
            SELECT
                g.id, g.name, g.description, g.logo_url,
                g.invite_code, g.group_type,
                g.created_at, g.created_by,
                a.email AS created_by_email,
                (SELECT COUNT(*) FROM players  WHERE group_id = g.id) AS players_count,
                (SELECT COUNT(*) FROM sessions WHERE group_id = g.id) AS sessions_count,
                (SELECT COUNT(*) FROM admin_roles WHERE group_id = g.id) AS admins_count
            FROM `groups` g
            LEFT JOIN admins a ON g.created_by = a.id";

        $params = [];
        if ($userType === 'group_admin') {
            // group_admin vede solo il proprio gruppo
            $sql .= " WHERE g.id = ?";
            $params[] = getGroupId($payload);
        } else {
            $sql .= " ORDER BY g.created_at DESC";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'groups' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore recupero gruppi']);
    }
    exit;
}

// ══════════════════════════════════════════
// POST — crea gruppo
// ══════════════════════════════════════════
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $name = trim($data['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nome gruppo obbligatorio']);
        exit;
    }

    try {
        $groupType = in_array($data['group_type'] ?? '', ['challenge', 'casual'])
            ? $data['group_type'] : 'challenge';

        $stmt = $pdo->prepare("
            INSERT INTO `groups` (name, description, logo_url, group_type, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            $data['description'] ?? null,
            $data['logo_url']    ?? null,
            $groupType,
            $payload['admin_id'] ?? null,
        ]);
        $groupId = (int)$pdo->lastInsertId();

        // Genera invite_code univoco
        do {
            $inviteCode = strtoupper(substr(md5(uniqid($groupId . mt_rand(), true)), 0, 8));
            $chk = $pdo->prepare("SELECT id FROM `groups` WHERE invite_code = ?");
            $chk->execute([$inviteCode]);
        } while ($chk->fetch());
        $pdo->prepare("UPDATE `groups` SET invite_code = ? WHERE id = ?")->execute([$inviteCode, $groupId]);

        logSecurityEvent($pdo, 'group_created', 'WARNING', $payload['admin_id'] ?? null, [
            'group_id'   => $groupId,
            'group_name' => $name,
        ]);

        echo json_encode(['success' => true, 'group_id' => $groupId, 'invite_code' => $inviteCode, 'message' => 'Gruppo creato con successo']);
    } catch (Exception $e) {
        $msg = stripos($e->getMessage(), 'Duplicate') !== false
            ? "Nome '$name' già esistente"
            : 'Errore creazione gruppo';
        http_response_code(409);
        echo json_encode(['error' => $msg]);
    }
    exit;
}

// ══════════════════════════════════════════
// PUT — modifica gruppo
// ══════════════════════════════════════════
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($data['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID gruppo obbligatorio']);
        exit;
    }

    $allowed = ['name', 'description', 'logo_url'];
    $updates = [];
    $params  = [];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $updates[] = "$field = ?";
            $params[]  = $data[$field];
        }
    }

    // group_type: validazione esplicita
    if (isset($data['group_type'])) {
        if (!in_array($data['group_type'], ['challenge', 'casual'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'group_type deve essere challenge o casual']);
            exit;
        }
        $updates[] = "group_type = ?";
        $params[]  = $data['group_type'];
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nessun campo da aggiornare']);
        exit;
    }

    $params[] = $id;

    try {
        $pdo->prepare("UPDATE `groups` SET " . implode(', ', $updates) . " WHERE id = ?")
            ->execute($params);

        logSecurityEvent($pdo, 'group_updated', 'WARNING', $payload['admin_id'] ?? null, ['group_id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Gruppo aggiornato']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore aggiornamento gruppo']);
    }
    exit;
}

// ══════════════════════════════════════════
// DELETE — elimina gruppo
// ══════════════════════════════════════════
if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($data['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID gruppo obbligatorio']);
        exit;
    }

    try {
        // Blocca se gruppo ha dati
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM players  WHERE group_id = ?) AS players,
                (SELECT COUNT(*) FROM sessions WHERE group_id = ?) AS sessions
        ");
        $stmt->execute([$id, $id]);
        $counts = $stmt->fetch();

        if ($counts['players'] > 0 || $counts['sessions'] > 0) {
            http_response_code(409);
            echo json_encode([
                'error'    => 'Impossibile eliminare: gruppo contiene dati',
                'players'  => (int)$counts['players'],
                'sessions' => (int)$counts['sessions'],
            ]);
            exit;
        }

        // Elimina admin_roles collegati prima del gruppo (FK)
        $pdo->prepare("DELETE FROM admin_roles WHERE group_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM `groups` WHERE id = ?")->execute([$id]);

        logSecurityEvent($pdo, 'group_deleted', 'CRITICAL', $payload['admin_id'] ?? null, ['group_id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Gruppo eliminato']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore eliminazione gruppo']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non supportato']);
