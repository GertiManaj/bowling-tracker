<?php
// ============================================
//  api/players.php
//  GET    → lista tutti i giocatori + stats (pubblico)
//  POST   → aggiunge un nuovo giocatore (PROTETTO)
//  PUT    → modifica un giocatore esistente (PROTETTO)
//  DELETE → elimina un giocatore (PROTETTO)
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';
require_once __DIR__ . '/mailer.php';

$method  = $_SERVER['REQUEST_METHOD'];
$payload = null;

if ($method !== 'GET') {
    // POST/PUT/DELETE: JWT obbligatorio
    $payload = requireAuth(['POST', 'PUT', 'DELETE']);
} else {
    // GET pubblico: leggi JWT opzionale per group filter
    $ah = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)$/i', $ah, $m)) {
        $parts = explode('.', $m[1]);
        if (count($parts) === 3) {
            $pd = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if ($pd && isset($pd['exp']) && $pd['exp'] > time()) $payload = $pd;
        }
    }
}

// Determina filtro gruppo
$filterGroupId = null;
if ($payload) {
    if (isSuperAdmin($payload)) {
        $filterGroupId = isset($_GET['group_id']) && $_GET['group_id'] !== 'all'
            ? (int)$_GET['group_id'] : null;
    } else {
        // group_admin e player vedono solo il loro gruppo
        $filterGroupId = getGroupId($payload);
    }
}

$pdo = getPDO();

// Auto-migration: aggiunge colonna email a players se non esiste
try { $pdo->exec("ALTER TABLE players ADD COLUMN email VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) {}

// ── GET ──────────────────────────────────────
if ($method === 'GET') {
    $sql = '
        SELECT
            p.id, p.name, p.nickname, p.emoji, p.email, p.group_id, p.created_at,
            MAX(pa.id IS NOT NULL) AS has_account,
            MAX(pa.email)          AS account_email,
            COUNT(s.id)            AS partite,
            ROUND(AVG(s.score), 1) AS media,
            MAX(s.score)           AS record,
            MIN(s.score)           AS minimo
        FROM players p
        LEFT JOIN player_auth pa ON pa.player_id = p.id
        LEFT JOIN scores s ON s.player_id = p.id';

    $params = [];
    if ($filterGroupId !== null) {
        $sql .= ' WHERE p.group_id = ?';
        $params[] = $filterGroupId;
    }
    $sql .= ' GROUP BY p.id ORDER BY p.name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── POST — aggiungi ──────────────────────────
if ($method === 'POST') {
    if (!checkPermission($payload, 'can_add_players')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_add_players richiesto']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty(trim($data['name'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'Il nome è obbligatorio']);
        exit;
    }

    // Determina group_id: super_admin può specificarlo, group_admin usa il suo
    $groupId = isSuperAdmin($payload)
        ? (int)($data['group_id'] ?? 1)
        : (int)getGroupId($payload);

    $check = $pdo->prepare('SELECT id FROM players WHERE name = ? AND group_id = ?');
    $check->execute([trim($data['name']), $groupId]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Esiste già un giocatore con questo nome']);
        exit;
    }

    $playerEmail = trim($data['email'] ?? '') ?: null;
    $playerName  = trim($data['name']);
    $stmt = $pdo->prepare('INSERT INTO players (name, nickname, emoji, group_id, email) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$playerName, trim($data['nickname'] ?? ''), $data['emoji'] ?? '🎳', $groupId, $playerEmail]);
    $newId = $pdo->lastInsertId();

    // Se email valida: crea account player_auth + invia credenziali temporanee
    $accountCreated = false;
    $emailSent      = false;

    if ($playerEmail && filter_var($playerEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            // Fetch nome gruppo
            $gStmt = $pdo->prepare('SELECT name FROM `groups` WHERE id = ?');
            $gStmt->execute([$groupId]);
            $group = $gStmt->fetch();

            if ($group) {
                // Genera password temporanea
                $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%';
                $tempPass = '';
                for ($i = 0; $i < 12; $i++) {
                    $tempPass .= $chars[random_int(0, strlen($chars) - 1)];
                }

                $pdo->prepare("
                    INSERT INTO player_auth (player_id, email, password_hash, must_change_password)
                    VALUES (?, ?, ?, 1)
                ")->execute([$newId, $playerEmail, password_hash($tempPass, PASSWORD_DEFAULT)]);
                $accountCreated = true;

                // Invia email con credenziali (template con avviso cambio obbligatorio)
                $emailSent = sendPlayerActivation($playerEmail, $playerName, $group['name'], $tempPass);
                error_log($emailSent
                    ? "[players] ✅ Email attivazione inviata a $playerEmail"
                    : "[players] ❌ Email attivazione fallita per $playerEmail");
            }
        } catch (\Throwable $ex) {
            // Non blocca la creazione del giocatore: logga e prosegui
            error_log("[players] Errore account/email per player_id=$newId ($playerEmail): " . $ex->getMessage());
        }
    }

    http_response_code(201);
    echo json_encode(['success' => true, 'id' => $newId, 'account_created' => $accountCreated, 'email_sent' => $emailSent]);
    exit;
}

// ── PUT — modifica ───────────────────────────
if ($method === 'PUT') {
    if (!checkPermission($payload, 'can_edit_players')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_edit_players richiesto']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id || empty(trim($data['name'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'ID e nome sono obbligatori']);
        exit;
    }

    // Fetch player row (ownership check + email corrente per rilevare cambio)
    $own = $pdo->prepare('SELECT group_id, name, email AS old_email FROM players WHERE id = ?');
    $own->execute([$id]);
    $playerRow = $own->fetch();

    if (!$playerRow) {
        http_response_code(404);
        echo json_encode(['error' => 'Giocatore non trovato']);
        exit;
    }

    // Verifica ownership per group_admin
    if (!isSuperAdmin($payload)) {
        if ((int)$playerRow['group_id'] !== getGroupId($payload)) {
            http_response_code(403);
            echo json_encode(['error' => 'Non puoi modificare giocatori di altri gruppi']);
            exit;
        }
    }

    // Duplicate check: esclude il player corrente (AND id != ?) e limita al suo gruppo
    $check = $pdo->prepare('SELECT id FROM players WHERE name = ? AND group_id = ? AND id != ?');
    $check->execute([trim($data['name']), (int)$playerRow['group_id'], $id]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Esiste già un giocatore con questo nome']);
        exit;
    }

    $newEmail = trim($data['email'] ?? '') ?: null;

    $stmt = $pdo->prepare('UPDATE players SET name = ?, nickname = ?, emoji = ?, email = ? WHERE id = ?');
    $stmt->execute([trim($data['name']), trim($data['nickname'] ?? ''), $data['emoji'] ?? '🎳', $newEmail, $id]);

    // ── Notifiche cambio email ────────────────────
    $oldEmail = $playerRow['old_email'];
    $pName    = htmlspecialchars($playerRow['name']);

    if ($oldEmail !== $newEmail) {
        // Notifica vecchia email
        if ($oldEmail && filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
            $body = "
<p>Ciao <strong>$pName</strong>,</p>
<p>L'amministratore ha modificato l'email associata al tuo account Strike Zone.</p>
<div class='info-box' style='border-left-color:#ff3cac;background:#1a0d15'>
  <strong style='color:#ff3cac'>⚠️ Email account modificata</strong><br>
  La tua email precedente (<strong>" . htmlspecialchars($oldEmail) . "</strong>) non è più associata al tuo account.
</div>
<p style='font-size:13px;color:#555570;margin-top:24px'>
  Se non hai autorizzato questa modifica, contatta l'amministratore del tuo gruppo.
</p>";
            $sent = sendEmail($oldEmail, '⚠️ Email account Strike Zone modificata', mailWrap($body));
            error_log($sent
                ? "[players] ✅ Notifica vecchia email inviata a $oldEmail"
                : "[players] ❌ Notifica vecchia email fallita per $oldEmail");
        }

        // Notifica nuova email + aggiorna player_auth se esiste account
        if ($newEmail && filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $body = "
<p>Ciao <strong>$pName</strong>,</p>
<p>L'amministratore ha associato questa email al tuo account Strike Zone.</p>
<div class='info-box' style='border-color:#00e5ff'>
  <strong style='color:#00e5ff'>✅ Email account aggiornata</strong><br>
  Da ora puoi accedere con questa email: <strong>" . htmlspecialchars($newEmail) . "</strong>
</div>
<p>Accedi a Strike Zone:</p>
<a href='" . rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/') . "/frontend/pages/welcome.html' class='btn'>🎳 Accedi a Strike Zone</a>";
            $sent = sendEmail($newEmail, '✅ Email account Strike Zone aggiornata', mailWrap($body));
            error_log($sent
                ? "[players] ✅ Notifica nuova email inviata a $newEmail"
                : "[players] ❌ Notifica nuova email fallita per $newEmail");

            // Aggiorna email in player_auth se l'account esiste
            $pdo->prepare('UPDATE player_auth SET email = ? WHERE player_id = ?')->execute([$newEmail, $id]);
        } elseif (!$newEmail && $oldEmail) {
            // Email rimossa: rimuove anche player_auth per coerenza? No, mantieni l'account ma logga
            error_log("[players] Email rimossa per player_id=$id (vecchia: $oldEmail)");
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE — elimina ─────────────────────────
if ($method === 'DELETE') {
    if (!checkPermission($payload, 'can_delete_players')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_delete_players richiesto']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID non valido']);
        exit;
    }

    // Verifica ownership per group_admin
    if (!isSuperAdmin($payload)) {
        $own = $pdo->prepare('SELECT group_id FROM players WHERE id = ?');
        $own->execute([$id]);
        $row = $own->fetch();
        if (!$row || (int)$row['group_id'] !== getGroupId($payload)) {
            http_response_code(403);
            echo json_encode(['error' => 'Non puoi eliminare giocatori di altri gruppi']);
            exit;
        }
    }

    // CASCADE elimina anche scores e credenziali collegati
    $pdo->prepare('DELETE FROM player_auth WHERE player_id = ?')->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM players WHERE id = ?');
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non consentito']);
