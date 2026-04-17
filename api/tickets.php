<?php
// ============================================
//  api/tickets.php
//  GET         → lista ticket / singolo / stats (admin)
//  POST        → crea ticket (pubblico, multipart o JSON)
//  PUT         → aggiorna stato/priorità/risposta (solo super_admin)
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/helpers.php';

$method  = $_SERVER['REQUEST_METHOD'];
$payload = null;

if ($method === 'PUT') {
    $payload = requireAuth(['PUT']);
} else {
    // JWT opzionale con verifica firma completa (per stats admin in GET)
    $payload = tryParseJWT();
}

$pdo = getPDO();

// ── Auto-migration ───────────────────────────
$migs = [
    "ALTER TABLE tickets ADD COLUMN title VARCHAR(200) NULL",
    "ALTER TABLE tickets ADD COLUMN category VARCHAR(50) DEFAULT 'altro'",
    "ALTER TABLE tickets ADD COLUMN priority VARCHAR(10) DEFAULT 'media'",
    "ALTER TABLE tickets ADD COLUMN attachment_url VARCHAR(500) NULL",
    "ALTER TABLE tickets ADD COLUMN attachment_name VARCHAR(255) NULL",
    "ALTER TABLE tickets ADD COLUMN user_email VARCHAR(255) NULL",
    "ALTER TABLE tickets ADD COLUMN admin_reply TEXT NULL",
    "ALTER TABLE tickets ADD COLUMN ticket_number VARCHAR(10) NULL",
    "ALTER TABLE tickets ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    "CREATE TABLE IF NOT EXISTS ticket_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(100) NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
];
foreach ($migs as $sql) { try { $pdo->exec($sql); } catch (\PDOException $e) {} }

// Migra status legacy → nuovi (idempotente)
try {
    $pdo->exec("UPDATE tickets SET status='nuovo'          WHERE status='open'");
    $pdo->exec("UPDATE tickets SET status='in_lavorazione' WHERE status='in_progress'");
    $pdo->exec("UPDATE tickets SET status='risolto'        WHERE status='resolved'");
    $pdo->exec("UPDATE tickets SET status='chiuso'         WHERE status='rejected'");
    $pdo->exec("UPDATE tickets SET ticket_number=LPAD(id,4,'0') WHERE ticket_number IS NULL OR ticket_number=''");
    $pdo->exec("UPDATE tickets SET category=type WHERE (category IS NULL OR category='') AND type IS NOT NULL AND type!=''");
} catch (\PDOException $e) {}

// ── GET — statistiche (solo admin) ──────────
if ($method === 'GET' && isset($_GET['stats'])) {
    if (!$payload || !isSuperAdmin($payload)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $total = (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
    $open  = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('nuovo','in_lavorazione')")->fetchColumn();
    $today = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    $byStatus   = $pdo->query("SELECT status, COUNT(*) FROM tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $byCategory = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(category),''), NULLIF(TRIM(type),''), 'altro') cat, COUNT(*) FROM tickets GROUP BY cat"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $byPriority = $pdo->query("SELECT priority, COUNT(*) FROM tickets GROUP BY priority")->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'total'       => $total,
        'open'        => $open,
        'today'       => $today,
        'by_status'   => $byStatus,
        'by_category' => $byCategory,
        'by_priority' => $byPriority,
    ]);
    exit;
}

// ── GET ──────────────────────────────────────
if ($method === 'GET') {
    $id = intval($_GET['id'] ?? 0);
    if ($id) {
        $q = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
        $q->execute([$id]);
        echo json_encode($q->fetch(PDO::FETCH_ASSOC) ?: ['error' => 'Non trovato']);
        exit;
    }

    $status   = $_GET['status']   ?? null;
    $category = $_GET['category'] ?? null;
    $priority = $_GET['priority'] ?? null;
    $search   = trim($_GET['search'] ?? '');

    $sql    = 'SELECT * FROM tickets WHERE 1=1';
    $params = [];

    if ($status && $status !== 'all') {
        $sql .= ' AND status = ?'; $params[] = $status;
    }
    if ($category && $category !== 'all') {
        $sql .= ' AND (category = ? OR (COALESCE(TRIM(category),\'\') = \'\' AND type = ?))';
        $params[] = $category; $params[] = $category;
    }
    if ($priority && $priority !== 'all') {
        $sql .= ' AND priority = ?'; $params[] = $priority;
    }
    if ($search) {
        $sql .= ' AND (title LIKE ? OR description LIKE ? OR ticket_number LIKE ? OR name LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $sql .= " ORDER BY
        CASE priority WHEN 'alta' THEN 1 WHEN 'media' THEN 2 WHEN 'bassa' THEN 3 ELSE 4 END,
        CASE status WHEN 'nuovo' THEN 1 WHEN 'in_lavorazione' THEN 2 WHEN 'risolto' THEN 3 WHEN 'chiuso' THEN 4 ELSE 5 END,
        created_at DESC";

    $q = $pdo->prepare($sql);
    $q->execute($params);
    $tickets = $q->fetchAll(PDO::FETCH_ASSOC);
    $unread  = count(array_filter($tickets, fn($t) => in_array($t['status'], ['nuovo', 'in_lavorazione'])));

    // Rimuovi user_email dalle risposte non autenticate (GDPR)
    if (!$payload || !isSuperAdmin($payload)) {
        $tickets = array_map(function ($t) {
            unset($t['user_email']);
            return $t;
        }, $tickets);
    }

    echo json_encode(['tickets' => $tickets, 'unread' => $unread]);
    exit;
}

// ── POST — crea ticket ───────────────────────
if ($method === 'POST') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($ct, 'multipart/form-data')) {
        $title    = trim($_POST['title']       ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $category = trim($_POST['category']    ?? 'altro');
        $name     = trim($_POST['name']        ?? '');
        $email    = trim($_POST['email']       ?? '');
    } else {
        $d        = json_decode(file_get_contents('php://input'), true) ?? [];
        $title    = trim($d['title']       ?? '');
        $desc     = trim($d['description'] ?? '');
        $category = trim($d['category']    ?? ($d['type'] ?? 'altro'));
        $name     = trim($d['name']        ?? '');
        $email    = trim($d['email']       ?? '');
    }

    if (!$desc) {
        http_response_code(400);
        echo json_encode(['error' => 'La descrizione è obbligatoria']);
        exit;
    }

    $validCats = ['bug', 'suggerimento', 'domanda', 'funzionalita', 'altro', 'feature', 'correction'];
    if (!in_array($category, $validCats)) $category = 'altro';

    // Numero ticket
    $maxNum = (int)$pdo->query("SELECT MAX(CAST(ticket_number AS UNSIGNED)) FROM tickets")->fetchColumn();
    $ticketNumber = str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);

    // Upload allegato
    $attachmentUrl = $attachmentName = null;

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['attachment'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        // Verifica MIME reale dal contenuto del file (non dal client-supplied type)
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowed)) {
            http_response_code(400);
            echo json_encode(['error' => 'Tipo file non permesso. Solo immagini (JPEG, PNG, GIF, WebP).']);
            exit;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'File troppo grande (max 5 MB).']);
            exit;
        }

        $uploadDir = realpath(__DIR__ . '/..') . '/uploads/tickets/';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = 'ticket_' . $ticketNumber . '_' . time() . '.' . $ext;
        if (@move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
            $attachmentUrl  = '/uploads/tickets/' . $safeName;
            $attachmentName = htmlspecialchars(basename($file['name']), ENT_QUOTES, 'UTF-8');
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO tickets
            (ticket_number, title, type, category, name, description,
             status, priority, attachment_url, attachment_name, user_email, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'nuovo', 'media', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $ticketNumber,
        $title ?: null,
        $category, $category,
        $name ?: null,
        $desc,
        $attachmentUrl, $attachmentName,
        ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
    ]);
    $newId = $pdo->lastInsertId();

    $displayTitle = $title ?: mb_strimwidth($desc, 0, 60, '…');

    // Conferma creazione a utente (se ha fornito email)
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        notifyUserTicketCreated($email, $ticketNumber, $displayTitle);
    }

    // Notifica admin
    notifyAdminNewTicket($ticketNumber, $displayTitle, $category, $name ?: 'Anonimo', $email, $desc);

    http_response_code(201);
    echo json_encode(['success' => true, 'id' => $newId, 'ticket_number' => $ticketNumber]);
    exit;
}

// ── PUT — aggiorna ticket ────────────────────
if ($method === 'PUT') {
    if (!$payload || !isSuperAdmin($payload)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: solo super admin']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = intval($data['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID mancante']);
        exit;
    }

    $curr = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
    $curr->execute([$id]);
    $ticket = $curr->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket non trovato']);
        exit;
    }

    $fields = []; $params = [];
    $validStatuses   = ['nuovo', 'in_lavorazione', 'risolto', 'chiuso'];
    $validPriorities = ['alta', 'media', 'bassa'];

    if (isset($data['status']) && in_array($data['status'], $validStatuses)) {
        $fields[] = 'status = ?'; $params[] = $data['status'];
    }
    if (isset($data['priority']) && in_array($data['priority'], $validPriorities)) {
        $fields[] = 'priority = ?'; $params[] = $data['priority'];
    }

    $sendReply = false;
    $replyText = '';

    if (array_key_exists('admin_reply', $data)) {
        $replyText = trim($data['admin_reply'] ?? '');
        $fields[]  = 'reply = ?';       $params[] = $replyText ?: null;
        $fields[]  = 'admin_reply = ?'; $params[] = $replyText ?: null;

        if ($replyText) {
            $fields[]  = 'replied_at = NOW()';
            $sendReply = true;

            // Auto-avanza stato se era nuovo e non impostato manualmente
            if ($ticket['status'] === 'nuovo' && !isset($data['status'])) {
                $fields[] = "status = 'in_lavorazione'";
            }
        }
    }

    if (!$fields) {
        http_response_code(400);
        echo json_encode(['error' => 'Nulla da aggiornare']);
        exit;
    }

    $params[] = $id;
    $pdo->prepare('UPDATE tickets SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    if ($sendReply && !empty($ticket['user_email']) && filter_var($ticket['user_email'], FILTER_VALIDATE_EMAIL)) {
        notifyUserTicketReply(
            $ticket['user_email'],
            $ticket['ticket_number'] ?? '#' . $ticket['id'],
            $ticket['title']         ?? $ticket['description'],
            $replyText
        );
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non consentito']);
