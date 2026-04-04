<?php
require_once __DIR__ . '/config.php';
$pdo = getPDO();
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
$qVit = $pdo->prepare('
    SELECT COUNT(DISTINCT sc.session_id) AS vittorie
    FROM scores sc
    WHERE sc.player_id = ?
    AND sc.team_id IS NOT NULL
    AND (
        SELECT SUM(s2.score) FROM scores s2
        WHERE s2.team_id = sc.team_id AND s2.session_id = sc.session_id
    ) = (
        SELECT MAX(team_tot) FROM (
            SELECT SUM(s3.score) AS team_tot FROM scores s3
            WHERE s3.session_id = sc.session_id AND s3.team_id IS NOT NULL
            GROUP BY s3.team_id
        ) t
    )
    AND (
        SELECT COUNT(*) FROM (
            SELECT SUM(s4.score) AS team_tot FROM scores s4
            WHERE s4.session_id = sc.session_id AND s4.team_id IS NOT NULL
            GROUP BY s4.team_id
            HAVING SUM(s4.score) = (
                SELECT MAX(team_tot2) FROM (
                    SELECT SUM(s5.score) AS team_tot2 FROM scores s5
                    WHERE s5.session_id = sc.session_id AND s5.team_id IS NOT NULL
                    GROUP BY s5.team_id
                ) mx
            )
        ) winners
    ) = 1
');
$qVit->execute([$id]);
$vittorie = $qVit->fetchColumn();

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
$qHist = $pdo->prepare("
    SELECT se.id AS session_id, se.date, se.location,
        COALESCE(se.mode,'teams') AS mode,
        t.name AS team_name, sc.team_id,
        SUM(sc.score) AS totale,
        GROUP_CONCAT(sc.score ORDER BY sc.game_number ASC SEPARATOR ',') AS game_scores
    FROM scores sc
    JOIN sessions se ON sc.session_id = se.id
    LEFT JOIN teams t ON sc.team_id = t.id
    WHERE sc.player_id = ?
    GROUP BY se.id, sc.team_id, sc.player_id
    ORDER BY se.date DESC
");
$qHist->execute([$id]);
$historyRaw = $qHist->fetchAll();

$history = [];
foreach ($historyRaw as $h) {
    $sessId   = $h['session_id'];
    $mode     = $h['mode'] ?? 'teams';
    $teamName = $h['team_name'];

    $qMaxPlayer = $pdo->prepare('SELECT MAX(tot) FROM (SELECT player_id, SUM(score) AS tot FROM scores WHERE session_id = ? GROUP BY player_id) t');
    $qMaxPlayer->execute([$sessId]);
    $maxPlayer = (int)$qMaxPlayer->fetchColumn();

    if ($mode === 'ffa' && $teamName === '__FFA__') {
        $qFFAMax = $pdo->prepare("SELECT MAX(ptot) FROM (SELECT sc2.player_id, SUM(sc2.score) AS ptot FROM scores sc2 JOIN teams t2 ON sc2.team_id=t2.id WHERE sc2.session_id=? AND t2.name='__FFA__' GROUP BY sc2.player_id) pt");
        $qFFAMax->execute([$sessId]);
        $ffaMax = (int)$qFFAMax->fetchColumn();
        $qFFACnt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT sc2.player_id, SUM(sc2.score) AS ptot FROM scores sc2 JOIN teams t2 ON sc2.team_id=t2.id WHERE sc2.session_id=? AND t2.name='__FFA__' GROUP BY sc2.player_id HAVING SUM(sc2.score)=?) pt");
        $qFFACnt->execute([$sessId, $ffaMax]);
        $ffaCnt = (int)$qFFACnt->fetchColumn();
        $h['vittoria']  = ((int)$h['totale'] === $ffaMax && $ffaCnt === 1);
        $h['team_name'] = '🏆 FFA';
    } elseif ($teamName === '__FFA__') {
        continue; // skip righe FFA extra
    } elseif ($h['team_id'] === null) {
        $h['vittoria']  = false;
        $h['team_name'] = null;
    } else {
        $qTeamTot = $pdo->prepare("SELECT SUM(sc2.score) FROM scores sc2 WHERE sc2.session_id=? AND sc2.team_id=?");
        $qTeamTot->execute([$sessId, $h['team_id']]);
        $teamTotal = (int)$qTeamTot->fetchColumn();
        $qMaxTeam = $pdo->prepare("SELECT MAX(tot) FROM (SELECT sc2.team_id, SUM(sc2.score) AS tot FROM scores sc2 JOIN teams t2 ON sc2.team_id=t2.id WHERE sc2.session_id=? AND t2.name!='__FFA__' GROUP BY sc2.team_id) t");
        $qMaxTeam->execute([$sessId]);
        $maxTeam = (int)$qMaxTeam->fetchColumn();
        $qCntWin = $pdo->prepare("SELECT COUNT(*) FROM (SELECT sc2.team_id, SUM(sc2.score) AS tot FROM scores sc2 JOIN teams t2 ON sc2.team_id=t2.id WHERE sc2.session_id=? AND t2.name!='__FFA__' GROUP BY sc2.team_id HAVING SUM(sc2.score)=?) w");
        $qCntWin->execute([$sessId, $maxTeam]);
        $cntWin = (int)$qCntWin->fetchColumn();
        $h['vittoria'] = ($teamTotal === $maxTeam && $cntWin === 1);
    }

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
    $qTT = $pdo->prepare('
        SELECT team_id, SUM(score) AS tot
        FROM scores WHERE session_id = ? AND team_id IS NOT NULL
        GROUP BY team_id
    ');
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

// ── PAYMENT TREND ────────────────────────────
$qPaySess2 = $pdo->query("
    SELECT se.id, se.date, se.cost_per_game, COALESCE(se.mode,'teams') AS mode
    FROM sessions se
    WHERE se.cost_per_game IS NOT NULL AND se.cost_per_game > 0
    ORDER BY se.date ASC
");
$paySessions2 = $qPaySess2->fetchAll();
$payEvents2 = [];

foreach ($paySessions2 as $sess) {
    $sid  = $sess['id'];
    $cost = floatval($sess['cost_per_game']);
    $mode = $sess['mode'];
    $date = $sess['date'];

    // Controlla se il giocatore era in questa sessione
    $qCheck = $pdo->prepare('SELECT COUNT(*) FROM scores WHERE session_id=? AND player_id=?');
    $qCheck->execute([$sid, $id]);
    if (!(int)$qCheck->fetchColumn()) continue;

    // Calcola quanto ha pagato
    $qPGMe = $pdo->prepare('SELECT sc.team_id, COUNT(*) AS num_games FROM scores sc WHERE sc.session_id=? AND sc.player_id=? GROUP BY sc.team_id');
    $qPGMe->execute([$sid, $id]);
    $pgMe = $qPGMe->fetch();
    if (!$pgMe) continue;
    $tid  = $pgMe['team_id'];
    $base = $cost * (int)$pgMe['num_games'];

    $qTName2 = $pdo->prepare('SELECT name FROM teams WHERE id=?');
    $qTName2->execute([$tid ?? 0]);
    $tname2 = $qTName2->fetchColumn() ?: null;

    $amount = 0.0;
    if ($tid === null) {
        $amount = $base;
    } elseif ($tname2 === '__FFA__') {
        $qAllFFA2 = $pdo->prepare("SELECT sc2.player_id, SUM(sc2.score) AS ptot, COUNT(sc2.id) AS ngames FROM scores sc2 JOIN teams t2 ON sc2.team_id=t2.id WHERE sc2.session_id=? AND t2.name='__FFA__' GROUP BY sc2.player_id");
        $qAllFFA2->execute([$sid]);
        $ffaAll2 = $qAllFFA2->fetchAll();
        $myScore2 = 0;
        foreach ($ffaAll2 as $fp) { if ($fp['player_id'] == $id) { $myScore2 = (int)$fp['ptot']; } }
        $maxScore2 = max(array_column($ffaAll2, 'ptot'));
        $winnersN2 = count(array_filter($ffaAll2, fn($f) => (int)$f['ptot'] === (int)$maxScore2));
        $nPlayers2 = count($ffaAll2);
        $winnerRow2 = array_values(array_filter($ffaAll2, fn($f) => (int)$f['ptot'] === (int)$maxScore2))[0];
        $winnerBase2 = $cost * (int)$winnerRow2['ngames'];
        $quota2 = ($nPlayers2 > 1) ? $winnerBase2 / ($nPlayers2 - 1) : 0;
        $amount = ($winnersN2===1 && $myScore2===(int)$maxScore2) ? 0.0 : $base + $quota2;
    } else {
        $qTT2 = $pdo->prepare("SELECT t.id AS team_id, SUM(sc.score) AS tot FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND t.name!='__FFA__' GROUP BY t.id");
        $qTT2->execute([$sid]);
        $teamTotals2 = [];
        foreach ($qTT2->fetchAll() as $t) $teamTotals2[$t['team_id']] = (int)$t['tot'];
        $maxTot2    = $teamTotals2 ? max($teamTotals2) : 0;
        $winnerCnt2 = count(array_filter($teamTotals2, fn($t) => $t === $maxTot2));
        $myTot2     = $teamTotals2[$tid] ?? 0;
        if ($winnerCnt2 > 1)       $amount = $base;
        elseif ($myTot2===$maxTot2) $amount = 0.0;
        else                        $amount = $base * 2;
    }
    $payEvents2[] = ['date' => $date, 'amount' => round($amount, 2)];
}

// Converti in cumulativo
$cum2 = 0;
$paymentTrend2 = [];
foreach ($payEvents2 as $ev) {
    $cum2 += $ev['amount'];
    $paymentTrend2[] = ['date' => $ev['date'], 'amount' => $ev['amount'], 'cumulative' => round($cum2, 2)];
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
    'payment_trend'   => $paymentTrend2,
]);