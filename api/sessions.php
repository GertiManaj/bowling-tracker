<?php
// ============================================
//  api/sessions.php
//  GET    → lista sessioni con dettagli
//  POST   → salva una nuova sessione
//  PUT    → modifica una sessione esistente
//  DELETE → elimina una sessione
// ============================================
require_once 'db.php';

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────
if ($method === 'GET') {
    $sessions = $pdo->query('
        SELECT id, date, location, notes, cost_per_game, mode FROM sessions
        ORDER BY date DESC
    ')->fetchAll();

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
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'La data è obbligatoria']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO sessions (date, location, notes, cost_per_game, mode) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['date'],
            $data['location'] ?? 'Bowling',
            $data['notes'] ?? null,
            isset($data['cost_per_game']) && $data['cost_per_game'] !== '' ? floatval($data['cost_per_game']) : null,
            $data['mode'] ?? 'teams'
        ]);
        $sessionId = $pdo->lastInsertId();

        // Squadre
        foreach (($data['teams'] ?? []) as $team) {
            $t = $pdo->prepare('INSERT INTO teams (session_id, name) VALUES (?, ?)');
            $t->execute([$sessionId, $team['name']]);
            $teamId = $pdo->lastInsertId();

            foreach ($team['players'] as $player) {
                if (empty($player['player_id']) || !isset($player['score'])) continue;
                $gameNumber = isset($player['game_number']) ? intval($player['game_number']) : 1;
                $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, ?, ?, ?)');
                $sc->execute([$sessionId, $player['player_id'], $teamId, $player['score'], $gameNumber]);
            }
        }

        // Giocatori singoli (senza squadra, team_id = NULL)
        foreach (($data['solo_players'] ?? []) as $player) {
            if (empty($player['player_id']) || !isset($player['score'])) continue;
            $gameNumber = isset($player['game_number']) ? intval($player['game_number']) : 1;
            $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, NULL, ?, ?)');
            $sc->execute([$sessionId, $player['player_id'], $player['score'], $gameNumber]);
        }

        // Giocatori FFA (tutti contro tutti, team_id = NULL come i singoli)
        foreach (($data['ffa_players'] ?? []) as $player) {
            if (empty($player['player_id']) || !isset($player['score'])) continue;
            $gameNumber = isset($player['game_number']) ? intval($player['game_number']) : 1;
            $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, NULL, ?, ?)');
            $sc->execute([$sessionId, $player['player_id'], $player['score'], $gameNumber]);
        }

        $pdo->commit();
        http_response_code(201);
        echo json_encode(['success' => true, 'session_id' => $sessionId]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── PUT — modifica sessione ──────────────────
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id || empty($data['date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ID e data sono obbligatori']);
        exit;
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
                if (empty($player['player_id']) || !isset($player['score'])) continue;
                $gameNumber = isset($player['game_number']) ? intval($player['game_number']) : 1;
                $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, ?, ?, ?)');
                $sc->execute([$id, $player['player_id'], $teamId, $player['score'], $gameNumber]);
            }
        }

        // 4. Ricrea giocatori singoli
        foreach (($data['solo_players'] ?? []) as $player) {
            if (empty($player['player_id']) || !isset($player['score'])) continue;
            $gameNumber = isset($player['game_number']) ? intval($player['game_number']) : 1;
            $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, NULL, ?, ?)');
            $sc->execute([$id, $player['player_id'], $player['score'], $gameNumber]);
        }

        // 5. Ricrea giocatori FFA
        foreach (($data['ffa_players'] ?? []) as $player) {
            if (empty($player['player_id']) || !isset($player['score'])) continue;
            $gameNumber = isset($player['game_number']) ? intval($player['game_number']) : 1;
            $sc = $pdo->prepare('INSERT INTO scores (session_id, player_id, team_id, score, game_number) VALUES (?, ?, NULL, ?, ?)');
            $sc->execute([$id, $player['player_id'], $player['score'], $gameNumber]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── DELETE — elimina sessione ────────────────
if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID non valido']);
        exit;
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