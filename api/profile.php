<?php
// ============================================
//  api/profile.php
//  GET ?id=X → profilo completo di un giocatore
// ============================================
require_once 'db.php';
$pdo = getDB();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID non valido']);
    exit;
}

// ── INFO BASE ────────────────────────────────
$qInfo = $pdo->prepare('SELECT id, name, nickname, emoji, created_at FROM players WHERE id = ?');
$qInfo->execute([$id]);
$player = $qInfo->fetch();
if (!$player) {
    http_response_code(404);
    echo json_encode(['error' => 'Giocatore non trovato']);
    exit;
}

// ── STATISTICHE PERSONALI ────────────────────
$qStats = $pdo->prepare('
    SELECT
        COUNT(DISTINCT sc.session_id)      AS serate,
        COUNT(sc.id)                       AS game_totali,
        ROUND(AVG(st.totale), 1)           AS media_serata,
        ROUND(AVG(sc.score), 1)            AS media_game,
        MAX(st.totale)                     AS record_serata,
        MIN(st.totale)                     AS minimo_serata,
        MAX(sc.score)                      AS record_game,
        -- Vittorie squadra
        SUM(CASE WHEN (
            SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = sc.team_id
        ) = (
            SELECT MAX(tot) FROM (SELECT SUM(s3.score) AS tot FROM scores s3 WHERE s3.session_id = sc.session_id GROUP BY s3.team_id) mx
        ) THEN 1 ELSE 0 END) AS vittorie_squadra,
        -- Volte miglior score individuale
        SUM(CASE WHEN st.totale = (
            SELECT MAX(st2.totale) FROM (SELECT player_id, SUM(score) AS totale FROM scores WHERE session_id = sc.session_id GROUP BY player_id) st2
        ) THEN 1 ELSE 0 END) AS volte_top_scorer
    FROM scores sc
    JOIN (
        SELECT player_id, session_id, SUM(score) AS totale
        FROM scores GROUP BY player_id, session_id
    ) st ON st.player_id = sc.player_id AND st.session_id = sc.session_id
    WHERE sc.player_id = ?
');
$qStats->execute([$id]);
$stats = $qStats->fetch();

// ── MEDIA GRUPPO (per confronto) ─────────────
$qGroup = $pdo->query('
    SELECT ROUND(AVG(totale), 1) AS media_gruppo
    FROM (SELECT player_id, session_id, SUM(score) AS totale FROM scores GROUP BY player_id, session_id) t
');
$mediaGruppo = $qGroup->fetchColumn();

// ── STORICO SESSIONI ─────────────────────────
$qHistory = $pdo->prepare('
    SELECT
        se.id AS session_id,
        se.date,
        se.location,
        t.name  AS team_name,
        SUM(sc.score) AS totale,
        GROUP_CONCAT(sc.score ORDER BY sc.game_number ASC SEPARATOR \',\') AS game_scores,
        -- Ha vinto la squadra?
        (SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = sc.team_id) AS team_total,
        (SELECT MAX(tot) FROM (SELECT SUM(s3.score) AS tot FROM scores s3 WHERE s3.session_id = se.id GROUP BY s3.team_id) mx) AS max_team_total,
        -- Era il top scorer?
        (SELECT MAX(st2.totale) FROM (SELECT player_id, SUM(score) AS totale FROM scores WHERE session_id = se.id GROUP BY player_id) st2) AS session_max
    FROM scores sc
    JOIN sessions se ON sc.session_id = se.id
    LEFT JOIN teams t ON sc.team_id = t.id
    WHERE sc.player_id = ?
    GROUP BY se.id, t.id
    ORDER BY se.date DESC
');
$qHistory->execute([$id]);
$history = $qHistory->fetchAll();

// Aggiungi flag vittoria e top scorer
foreach ($history as &$h) {
    $h['vittoria']   = (int)$h['team_total'] === (int)$h['max_team_total'];
    $h['top_scorer'] = (int)$h['totale']     === (int)$h['session_max'];
    $h['games']      = array_map('intval', explode(',', $h['game_scores']));
    unset($h['game_scores'], $h['team_total'], $h['max_team_total'], $h['session_max']);
}
unset($h);

// ── TREND (per grafico) ──────────────────────
$trend = array_map(fn($h) => [
    'date'   => $h['date'],
    'totale' => (int)$h['totale'],
    'location' => $h['location'],
], array_reverse($history));

// ── COMPAGNI DI SQUADRA ──────────────────────
$qTeammates = $pdo->prepare('
    SELECT
        p.id, p.name, p.emoji,
        COUNT(DISTINCT a.session_id) AS volte_insieme,
        SUM(CASE WHEN (
            SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = a.team_id
        ) = (
            SELECT MAX(tot) FROM (SELECT SUM(s3.score) AS tot FROM scores s3 WHERE s3.session_id = a.session_id GROUP BY s3.team_id) mx
        ) THEN 1 ELSE 0 END) AS vittorie_insieme
    FROM scores a
    JOIN scores b ON a.session_id = b.session_id AND a.team_id = b.team_id AND b.player_id != a.player_id
    JOIN players p ON b.player_id = p.id
    WHERE a.player_id = ?
    GROUP BY p.id
    ORDER BY volte_insieme DESC
');
$qTeammates->execute([$id]);
$teammates = $qTeammates->fetchAll();

echo json_encode([
    'player'       => $player,
    'stats'        => $stats,
    'media_gruppo' => (float)$mediaGruppo,
    'history'      => $history,
    'trend'        => $trend,
    'teammates'    => $teammates,
]);