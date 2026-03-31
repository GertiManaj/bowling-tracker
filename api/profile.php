<?php
require_once 'db.php';
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['error' => 'ID non valido']); exit; }

// Info base
$q = $pdo->prepare('SELECT id, name, nickname, emoji, created_at FROM players WHERE id = ?');
$q->execute([$id]);
$player = $q->fetch();
if (!$player) { echo json_encode(['error' => 'Giocatore non trovato']); exit; }

// Totali per sessione (somma game)
$qTot = $pdo->prepare('SELECT session_id, SUM(score) AS totale FROM scores WHERE player_id = ? GROUP BY session_id');
$qTot->execute([$id]);
$sessionTotals = $qTot->fetchAll();
$totali = array_column($sessionTotals, 'totale');
$serate = count($totali);
$mediaSerata = $serate > 0 ? round(array_sum($totali) / $serate, 1) : null;
$recordSerata = $serate > 0 ? max($totali) : null;
$minimoSerata = $serate > 0 ? min($totali) : null;

// Game totali, media game e record singolo game
$qGame = $pdo->prepare('SELECT COUNT(id) AS game_totali, ROUND(AVG(score),1) AS media_game, MAX(score) AS record_game FROM scores WHERE player_id = ?');
$qGame->execute([$id]);
$gameStats = $qGame->fetch();

// Vittorie squadra — conta sessioni dove il team del giocatore ha vinto
// Vittorie teams normali
$qVit = $pdo->prepare("
    SELECT COUNT(DISTINCT sc.session_id) AS vittorie
    FROM scores sc
    JOIN teams t ON sc.team_id = t.id
    JOIN sessions se ON sc.session_id = se.id
    WHERE sc.player_id = ? AND t.name != '__FFA__' AND se.mode = 'teams'
    AND (SELECT SUM(s2.score) FROM scores s2 JOIN teams t2 ON s2.team_id=t2.id
         WHERE s2.team_id=sc.team_id AND s2.session_id=sc.session_id) =
        (SELECT MAX(tt) FROM (SELECT SUM(s3.score) AS tt FROM scores s3 JOIN teams t3 ON s3.team_id=t3.id
         WHERE s3.session_id=sc.session_id AND t3.name!='__FFA__' GROUP BY s3.team_id) mx)
    AND 1=(SELECT COUNT(*) FROM (SELECT SUM(s4.score) AS tt FROM scores s4 JOIN teams t4 ON s4.team_id=t4.id
           WHERE s4.session_id=sc.session_id AND t4.name!='__FFA__' GROUP BY s4.team_id
           HAVING SUM(s4.score)=(SELECT MAX(tt2) FROM (SELECT SUM(s5.score) AS tt2 FROM scores s5 JOIN teams t5 ON s5.team_id=t5.id
           WHERE s5.session_id=sc.session_id AND t5.name!='__FFA__' GROUP BY s5.team_id) mx2)) w)
");
$qVit->execute([$id]);
$vittorie_teams = (int)$qVit->fetchColumn();

// Vittorie FFA
$qVitFFA = $pdo->prepare("
    SELECT COUNT(DISTINCT sc.session_id)
    FROM scores sc JOIN teams t ON sc.team_id=t.id
    WHERE sc.player_id=? AND t.name='__FFA__'
    AND (SELECT SUM(s2.score) FROM scores s2 JOIN teams t2 ON s2.team_id=t2.id
         WHERE s2.session_id=sc.session_id AND s2.player_id=? AND t2.name='__FFA__') =
        (SELECT MAX(ptot) FROM (SELECT s3.player_id, SUM(s3.score) AS ptot FROM scores s3 JOIN teams t3 ON s3.team_id=t3.id
         WHERE s3.session_id=sc.session_id AND t3.name='__FFA__' GROUP BY s3.player_id) pt)
    AND 1=(SELECT COUNT(*) FROM (SELECT s4.player_id, SUM(s4.score) AS ptot FROM scores s4 JOIN teams t4 ON s4.team_id=t4.id
           WHERE s4.session_id=sc.session_id AND t4.name='__FFA__' GROUP BY s4.player_id) pt2
           WHERE pt2.ptot=(SELECT MAX(ptot3) FROM (SELECT s5.player_id, SUM(s5.score) AS ptot3 FROM scores s5 JOIN teams t5 ON s5.team_id=t5.id
           WHERE s5.session_id=sc.session_id AND t5.name='__FFA__' GROUP BY s5.player_id) pt3))
");
$qVitFFA->execute([$id, $id]);
$vittorie = $vittorie_teams + (int)$qVitFFA->fetchColumn();

// Volte top scorer
$qTop = $pdo->prepare('
    SELECT COUNT(*) AS volte
    FROM (SELECT session_id, SUM(score) AS totale FROM scores WHERE player_id = ? GROUP BY session_id) myTot
    WHERE myTot.totale = (
        SELECT MAX(tot) FROM (
            SELECT player_id, SUM(score) AS tot FROM scores
            WHERE session_id = myTot.session_id GROUP BY player_id
        ) allTot
    )
');
$qTop->execute([$id]);
$topScorer = $qTop->fetchColumn();

// Media gruppo
$qGruppo = $pdo->query('SELECT ROUND(AVG(tot),1) AS media FROM (SELECT player_id, session_id, SUM(score) AS tot FROM scores GROUP BY player_id, session_id) t');
$mediaGruppo = $qGruppo->fetchColumn();

// Storico sessioni
$qHist = $pdo->prepare('
    SELECT se.id AS session_id, se.date, se.location,
        t.name AS team_name, sc.team_id,
        SUM(sc.score) AS totale,
        GROUP_CONCAT(sc.score ORDER BY sc.game_number ASC SEPARATOR ",") AS game_scores
    FROM scores sc
    JOIN sessions se ON sc.session_id = se.id
    LEFT JOIN teams t ON sc.team_id = t.id
    WHERE sc.player_id = ?
    GROUP BY se.id, sc.team_id
    ORDER BY se.date DESC
');
$qHist->execute([$id]);
$historyRaw = $qHist->fetchAll();

// Aggiungi flag vittoria e top scorer per ogni sessione
$history = [];
foreach ($historyRaw as $h) {
    // Team total
    $qTeamTot = $pdo->prepare('SELECT SUM(score) FROM scores WHERE team_id = ?');
    $qTeamTot->execute([$h['team_id']]);
    $teamTotal = (int)$qTeamTot->fetchColumn();

    // Max team total nella sessione
    $qMaxTeam = $pdo->prepare('SELECT MAX(tot) FROM (SELECT SUM(score) AS tot FROM scores WHERE session_id = ? GROUP BY team_id) t');
    $qMaxTeam->execute([$h['session_id']]);
    $maxTeam = (int)$qMaxTeam->fetchColumn();

    // Max player total nella sessione
    $qMaxPlayer = $pdo->prepare('SELECT MAX(tot) FROM (SELECT player_id, SUM(score) AS tot FROM scores WHERE session_id = ? GROUP BY player_id) t');
    $qMaxPlayer->execute([$h['session_id']]);
    $maxPlayer = (int)$qMaxPlayer->fetchColumn();

    $h['vittoria']   = $teamTotal === $maxTeam;
    $h['top_scorer'] = (int)$h['totale'] === $maxPlayer;
    $h['games']      = array_map('intval', explode(',', $h['game_scores']));
    unset($h['game_scores'], $h['team_id']);
    $history[] = $h;
}

// Trend
$trend = array_map(fn($h) => [
    'date'     => $h['date'],
    'totale'   => (int)$h['totale'],
    'location' => $h['location'],
], array_reverse($history));

// Compagni di squadra
$qTeam = $pdo->prepare('
    SELECT p.id, p.name, p.emoji,
        COUNT(DISTINCT a.session_id) AS volte_insieme
    FROM scores a
    JOIN scores b ON a.session_id = b.session_id AND a.team_id = b.team_id AND b.player_id != a.player_id
    JOIN players p ON b.player_id = p.id
    WHERE a.player_id = ?
    GROUP BY p.id
    ORDER BY volte_insieme DESC
');
$qTeam->execute([$id]);
$teammatesRaw = $qTeam->fetchAll();

// Vittorie per ogni compagno
$teammates = [];
foreach ($teammatesRaw as $t) {
    $qTW = $pdo->prepare('
        SELECT COUNT(DISTINCT a.session_id) AS vittorie
        FROM scores a
        JOIN scores b ON a.session_id = b.session_id AND a.team_id = b.team_id AND b.player_id = ?
        WHERE a.player_id = ?
        AND (SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = a.team_id) = (
            SELECT MAX(team_tot) FROM (
                SELECT SUM(s3.score) AS team_tot FROM scores s3
                WHERE s3.session_id = a.session_id GROUP BY s3.team_id
            ) mx
        )
    ');
    $qTW->execute([$t['id'], $id]);
    $t['vittorie_insieme'] = (int)$qTW->fetchColumn();
    $teammates[] = $t;
}

// ── CALCOLO PAGAMENTI ─────────────────────────
// Stessa logica di leaderboard.php ma filtrata per questo giocatore
$qSessWithCost = $pdo->query('
    SELECT id, cost_per_game FROM sessions
    WHERE cost_per_game IS NOT NULL AND cost_per_game > 0
');
$sessWithCost = $qSessWithCost->fetchAll();

$saldoTotale   = 0.0;
$hasCostData   = false;
$paymentDetail = []; // dettaglio per sessione

foreach ($sessWithCost as $sess) {
    $sid  = $sess['id'];
    $cost = floatval($sess['cost_per_game']);

    // Controlla se il giocatore era in questa sessione
    $qPG = $pdo->prepare('
        SELECT team_id, COUNT(*) AS num_games
        FROM scores WHERE session_id = ? AND player_id = ?
        GROUP BY team_id
    ');
    $qPG->execute([$sid, $id]);
    $pgRow = $qPG->fetch();
    if (!$pgRow) continue;

    $hasCostData = true;
    $tid  = $pgRow['team_id'];
    $nG   = (int)$pgRow['num_games'];
    $base = $cost * $nG;

    // Totali team nella sessione
    $qTT = $pdo->prepare("
        SELECT sc.team_id, SUM(sc.score) AS tot
        FROM scores sc JOIN teams t ON sc.team_id=t.id
        WHERE sc.session_id=? AND t.name!='__FFA__'
        GROUP BY sc.team_id
    ");
    $qTT->execute([$sid]);
    $teamTotals = [];
    foreach ($qTT->fetchAll() as $t) $teamTotals[$t['team_id']] = (int)$t['tot'];

    $pagato = 0.0;
    if ($tid === null) {
        // Singolo
        $pagato = $base;
        $esito  = 'singolo';
    } else {
        $maxTot    = $teamTotals ? max($teamTotals) : 0;
        $winnerCnt = count(array_filter($teamTotals, fn($t) => $t === $maxTot));
        $isDraw    = $winnerCnt > 1;
        $myTot     = $teamTotals[$tid] ?? 0;

        if ($isDraw) {
            $pagato = $base;
            $esito  = 'pareggio';
        } elseif ($myTot === $maxTot) {
            $pagato = 0.0;
            $esito  = 'vittoria';
        } else {
            $pagato = $base * 2;
            $esito  = 'sconfitta';
        }
    }

    $saldoTotale += $pagato;
    $paymentDetail[] = [
        'session_id'    => $sid,
        'cost_per_game' => $cost,
        'num_games'     => $nG,
        'pagato'        => round($pagato, 2),
        'esito'         => $esito,
    ];
}

echo json_encode([
    'player'          => $player,
    'stats'           => [
        'serate'          => $serate,
        'game_totali'     => (int)$gameStats['game_totali'],
        'media_serata'    => $mediaSerata,
        'media_game'      => $gameStats['media_game'],
        'record_serata'   => $recordSerata,
        'record_game'     => (int)$gameStats['record_game'],
        'minimo_serata'   => $minimoSerata,
        'vittorie_squadra'=> (int)$vittorie,
        'volte_top_scorer'=> (int)$topScorer,
        'saldo_pagamenti' => $hasCostData ? round($saldoTotale, 2) : null,
        'payment_detail'  => $paymentDetail,
    ],
    'media_gruppo'    => (float)$mediaGruppo,
    'history'         => $history,
    'trend'           => $trend,
    'teammates'       => $teammates,
]);