<?php
// ============================================
//  api/sessions.php
//  GET    → lista sessioni con dettagli (pubblico)
//  POST   → salva una nuova sessione (PROTETTO)
//  PUT    → modifica una sessione esistente (PROTETTO)
//  DELETE → elimina una sessione (PROTETTO)
// ============================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt_protection.php';

$method  = $_SERVER['REQUEST_METHOD'];
$payload = null;

if ($method !== 'GET') {
    // POST/PUT/DELETE: JWT obbligatorio
    $payload = requireAuth(['POST', 'PUT', 'DELETE']);
} else {
    // GET pubblico: JWT opzionale con verifica firma completa (per group filter)
    $payload = tryParseJWT();
}

// Determina filtro gruppo
$filterGroupId = null;
if ($payload) {
    if (isSuperAdmin($payload)) {
        $filterGroupId = isset($_GET['group_id']) && $_GET['group_id'] !== 'all'
            ? (int)$_GET['group_id'] : null;
    } else {
        $filterGroupId = getGroupId($payload);
    }
}

$pdo = getPDO();

// Helper: valida e normalizza un singolo record score (usato in POST e PUT)
function validateScoreRecord(array $player): ?array {
    $pid   = (int)($player['player_id'] ?? 0);
    $score = isset($player['score']) ? intval($player['score']) : null;
    $game  = max(1, min(100, (int)($player['game_number'] ?? 1)));
    if ($pid <= 0 || $score === null) return null;
    if ($score < 0 || $score > 300) return null; // range bowling valido
    return ['player_id' => $pid, 'score' => $score, 'game_number' => $game];
}

// ── GET ──────────────────────────────────────
if ($method === 'GET') {
    $sql    = 'SELECT id, date, location, notes, cost_per_game, mode, group_id FROM sessions';
    $params = [];
    if ($filterGroupId !== null) {
        $sql .= ' WHERE group_id = ?';
        $params[] = $filterGroupId;
    }
    $sql .= ' ORDER BY date DESC';

    $stmt     = $pdo->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();

    foreach ($sessions as &$session) {
        // Carica punteggi per session_id (non per date, altrimenti sessioni stesso giorno si sovrappongono)
        $s = $pdo->prepare('
            SELECT
                sc.id, sc.session_id, sc.player_id, sc.team_id, sc.score, sc.game_number,
                p.name AS player_name, p.emoji,
                t.name AS team_name,
                se.date, se.location
            FROM scores sc
            JOIN players p  ON sc.player_id  = p.id
            JOIN sessions se ON sc.session_id = se.id
            LEFT JOIN teams t ON sc.team_id = t.id
            WHERE sc.session_id = ?
            ORDER BY sc.team_id, sc.game_number, sc.id
        ');
        $s->execute([$session['id']]);
        $session['scores'] = $s->fetchAll();

        $t = $pdo->prepare('SELECT id, name FROM teams WHERE session_id = ?');
        $t->execute([$session['id']]);
        $session['teams'] = $t->fetchAll();
    }

    echo json_encode($sessions);
    exit;
}

// ── POST — nuova sessione ────────────────────
if ($method === 'POST') {
    if (!checkPermission($payload, 'can_add_sessions')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_add_sessions richiesto']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'La data è obbligatoria']);
        exit;
    }

    // Determina group_id per la sessione
    $sessionGroupId = isSuperAdmin($payload)
        ? (int)($data['group_id'] ?? 1)
        : (int)getGroupId($payload);

    // Limite anti-DoS: max 200 righe score totali
    $allPlayers = array_merge(
        array_merge(...array_map(fn($t) => $t['players'] ?? [], $data['teams'] ?? [])),
        $data['solo_players'] ?? [],
        $data['ffa_players']  ?? []
    );
    if (count($allPlayers) > 200) {
        http_response_code(400);
        echo json_encode(['error' => 'Troppi giocatori nella sessione (max 200)']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO sessions (date, location, notes, cost_per_game, mode, group_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['date'],
            $data['location'] ?? 'Bowling',
            $data['notes'] ?? null,
            isset($data['cost_per_game']) && $data['cost_per_game'] !== '' ? floatval($data['cost_per_game']) : null,
            $data['mode'] ?? 'teams',
            $sessionGroupId,
        ]);
        $sessionId = $pdo->lastInsertId();

        // Squadre
        foreach (($data['teams'] ?? []) as $team) {
            $t = $pdo->prepare('INSERT INTO teams (session_id, name) VALUES (?, ?)');
            $t->execute([$sessionId, $team['name']]);
            $teamId = $pdo->lastInsertId();

            foreach ($team['players'] as $player) {
                $p = validateScoreRecord($player);
                if (!$p) continue;
                $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, ?, ?, ?)');
                $sc->execute([$sessionId, $p['player_id'], $teamId, $p['score'], $p['game_number']]);
            }
        }

        // Giocatori singoli (senza squadra, team_id = NULL)
        foreach (($data['solo_players'] ?? []) as $player) {
            $p = validateScoreRecord($player);
            if (!$p) continue;
            $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, NULL, ?, ?)');
            $sc->execute([$sessionId, $p['player_id'], $p['score'], $p['game_number']]);
        }

        // Giocatori FFA — salvati sotto team __FFA__ per distinguerli dai singoli
        if (!empty($data['ffa_players'])) {
            $tFFA = $pdo->prepare('INSERT INTO teams (session_id, name) VALUES (?, ?)');
            $tFFA->execute([$sessionId, '__FFA__']);
            $ffaTeamId = $pdo->lastInsertId();
            foreach ($data['ffa_players'] as $player) {
                $p = validateScoreRecord($player);
                if (!$p) continue;
                $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, ?, ?, ?)');
                $sc->execute([$sessionId, $p['player_id'], $ffaTeamId, $p['score'], $p['game_number']]);
            }
        }

        $pdo->commit();
        http_response_code(201);
        echo json_encode(['success' => true, 'session_id' => $sessionId]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Errore salvataggio sessione']);
    }
    exit;
}

// ── PUT — modifica sessione ──────────────────
if ($method === 'PUT') {
    if (!checkPermission($payload, 'can_edit_sessions')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_edit_sessions richiesto']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id || empty($data['date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ID e data sono obbligatori']);
        exit;
    }

    // Verifica ownership per group_admin
    if (!isSuperAdmin($payload)) {
        $own = $pdo->prepare('SELECT group_id FROM sessions WHERE id = ?');
        $own->execute([$id]);
        $row = $own->fetch();
        if (!$row || (int)$row['group_id'] !== getGroupId($payload)) {
            http_response_code(403);
            echo json_encode(['error' => 'Non puoi modificare sessioni di altri gruppi']);
            exit;
        }
    }

    $pdo->beginTransaction();
    try {
        // 1. Aggiorna dati sessione
        $pdo->prepare('UPDATE sessions SET date = ?, location = ?, notes = ?, cost_per_game = ?, mode = ? WHERE id = ?')
            ->execute([
                $data['date'],
                $data['location'] ?? 'Bowling',
                $data['notes'] ?? null,
                isset($data['cost_per_game']) && $data['cost_per_game'] !== '' ? floatval($data['cost_per_game']) : null,
                $data['mode'] ?? 'teams',
                $id
            ]);

        // 2. Elimina vecchi scores e teams
        $pdo->prepare('DELETE FROM scores WHERE session_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM teams  WHERE session_id = ?')->execute([$id]);

        // 3. Ricrea squadre e punteggi
        foreach (($data['teams'] ?? []) as $team) {
            $t = $pdo->prepare('INSERT INTO teams (session_id, name) VALUES (?, ?)');
            $t->execute([$id, $team['name']]);
            $teamId = $pdo->lastInsertId();

            foreach ($team['players'] as $player) {
                $p = validateScoreRecord($player);
                if (!$p) continue;
                $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, ?, ?, ?)');
                $sc->execute([$id, $p['player_id'], $teamId, $p['score'], $p['game_number']]);
            }
        }

        // 4. Ricrea giocatori singoli
        foreach (($data['solo_players'] ?? []) as $player) {
            $p = validateScoreRecord($player);
            if (!$p) continue;
            $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, NULL, ?, ?)');
            $sc->execute([$id, $p['player_id'], $p['score'], $p['game_number']]);
        }

        // 5. Ricrea giocatori FFA sotto team __FFA__
        if (!empty($data['ffa_players'])) {
            $tFFA = $pdo->prepare('INSERT INTO teams (session_id, name) VALUES (?, ?)');
            $tFFA->execute([$id, '__FFA__']);
            $ffaTeamId = $pdo->lastInsertId();
            foreach ($data['ffa_players'] as $player) {
                $p = validateScoreRecord($player);
                if (!$p) continue;
                $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, ?, ?, ?)');
                $sc->execute([$id, $p['player_id'], $ffaTeamId, $p['score'], $p['game_number']]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Errore aggiornamento sessione']);
    }
    exit;
}

// ── DELETE — elimina sessione ────────────────
if ($method === 'DELETE') {
    if (!checkPermission($payload, 'can_delete_sessions')) {
        http_response_code(403);
        echo json_encode(['error' => 'Permesso negato: can_delete_sessions richiesto']);
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
        $own = $pdo->prepare('SELECT group_id FROM sessions WHERE id = ?');
        $own->execute([$id]);
        $row = $own->fetch();
        if (!$row || (int)$row['group_id'] !== getGroupId($payload)) {
            http_response_code(403);
            echo json_encode(['error' => 'Non puoi eliminare sessioni di altri gruppi']);
            exit;
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM scores  WHERE session_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM teams   WHERE session_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);

        $pdo->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metodo non consentito']);