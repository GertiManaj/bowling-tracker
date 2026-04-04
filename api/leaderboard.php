<?php
// ============================================
//  api/leaderboard.php
//  GET → classifica generale con tutte le stat
// ============================================
require_once 'db.php';

$pdo = getDB();

// Classifica completa
$stmt = $pdo->query('
    SELECT
        p.id,
        p.name,
        p.nickname,
        p.emoji,
        COUNT(DISTINCT sc.session_id)                                    AS partite,
        COUNT(DISTINCT CASE WHEN sc.team_id IS NOT NULL THEN sc.session_id END) AS sfide,
        COUNT(sc.id)                                                     AS game_totali,
        ROUND(AVG(sc.score), 1)                                          AS media,
        MAX(sc.score)                                                    AS record,
        MIN(sc.score)                                                    AS minimo
    FROM players p
    LEFT JOIN scores sc ON sc.player_id = p.id
    GROUP BY p.id
    ORDER BY media DESC
');
$players = $stmt->fetchAll();

foreach ($players as &$player) {
    $id = $player['id'];

    // ── TREND: ultimi 5 totali per sessione ──
    $s = $pdo->prepare('
        SELECT SUM(score) AS totale
        FROM scores
        WHERE player_id = ?
        GROUP BY session_id
        ORDER BY session_id DESC
        LIMIT 5
    ');
    $s->execute([$id]);
    $recent = array_reverse($s->fetchAll(PDO::FETCH_COLUMN));
    $player['trend'] = array_map('intval', $recent);

    // ── VITTORIE SQUADRA (teams) ──
    $qVitt = $pdo->prepare('
        SELECT COUNT(DISTINCT sc.session_id) AS vittorie
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        WHERE sc.player_id = ?
        AND sc.team_id IS NOT NULL
        AND se.mode = \'teams\'
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
        AND 1 = (
            SELECT COUNT(*) FROM (
                SELECT SUM(s4.score) AS team_tot FROM scores s4
                WHERE s4.session_id = sc.session_id AND s4.team_id IS NOT NULL
                GROUP BY s4.team_id
            ) t2
            WHERE t2.team_tot = (
                SELECT MAX(team_tot) FROM (
                    SELECT SUM(s5.score) AS team_tot FROM scores s5
                    WHERE s5.session_id = sc.session_id AND s5.team_id IS NOT NULL
                    GROUP BY s5.team_id
                ) t3
            )
        )
    ');
    $qVitt->execute([$id]);
    $vittorie_teams = (int)$qVitt->fetchColumn();

    // ── VITTORIE FFA (usa team __FFA__) ──
    $qVittFFA = $pdo->prepare("
        SELECT COUNT(DISTINCT sc.session_id) AS vittorie
        FROM scores sc
        JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ?
        AND t.name = '__FFA__'
        AND (
            SELECT SUM(s2.score) FROM scores s2
            JOIN teams t2 ON s2.team_id = t2.id
            WHERE s2.session_id = sc.session_id AND s2.player_id = ?
            AND t2.name = '__FFA__'
        ) = (
            SELECT MAX(ptot) FROM (
                SELECT s3.player_id, SUM(s3.score) AS ptot
                FROM scores s3 JOIN teams t3 ON s3.team_id = t3.id
                WHERE s3.session_id = sc.session_id AND t3.name = '__FFA__'
                GROUP BY s3.player_id
            ) pt
        )
        AND 1 = (
            SELECT COUNT(*) FROM (
                SELECT s4.player_id, SUM(s4.score) AS ptot
                FROM scores s4 JOIN teams t4 ON s4.team_id = t4.id
                WHERE s4.session_id = sc.session_id AND t4.name = '__FFA__'
                GROUP BY s4.player_id
            ) pt2
            WHERE pt2.ptot = (
                SELECT MAX(ptot3) FROM (
                    SELECT s5.player_id, SUM(s5.score) AS ptot3
                    FROM scores s5 JOIN teams t5 ON s5.team_id = t5.id
                    WHERE s5.session_id = sc.session_id AND t5.name = '__FFA__'
                    GROUP BY s5.player_id
                ) pt3
            )
        )
    ");
    $qVittFFA->execute([$id, $id]);
    $vittorie_ffa = (int)$qVittFFA->fetchColumn();

    $player['vittorie_squadra'] = $vittorie_teams + $vittorie_ffa;

    // ── SERATE CON SQUADRA (teams normali, escluso FFA) ──
    $qSS = $pdo->prepare("
        SELECT COUNT(DISTINCT sc.session_id)
        FROM scores sc
        JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ? AND t.name != '__FFA__'
    ");
    $qSS->execute([$id]);
    $serateTeams = (int)$qSS->fetchColumn();

    // ── SERATE FFA ──
    $qSFFA = $pdo->prepare("
        SELECT COUNT(DISTINCT sc.session_id)
        FROM scores sc
        JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ? AND t.name = '__FFA__'
    ");
    $qSFFA->execute([$id]);
    $sérateFFA = (int)$qSFFA->fetchColumn();

    $player['serate_con_squadra'] = $serateTeams + $sérateFFA;

    // ── VOLTE TOP SCORER ──
    $qTop = $pdo->prepare('
        SELECT COUNT(*) AS volte
        FROM (
            SELECT session_id, SUM(score) AS totale
            FROM scores
            WHERE player_id = ?
            GROUP BY session_id
        ) myTot
        WHERE myTot.totale = (
            SELECT MAX(tot) FROM (
                SELECT player_id, SUM(score) AS tot
                FROM scores
                WHERE session_id = myTot.session_id
                GROUP BY player_id
            ) allTot
        )
    ');
    $qTop->execute([$id]);
    $player['volte_top_scorer'] = (int)$qTop->fetchColumn();

    // ── ULTIMI 5 RISULTATI (V/P/N) — teams + ffa ──
    $qSess = $pdo->prepare("
        SELECT DISTINCT sc.session_id, se.mode
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        LEFT JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ?
        AND sc.team_id IS NOT NULL
        AND (t.name != '__FFA__' OR se.mode = 'ffa')
        ORDER BY sc.session_id DESC
        LIMIT 5
    ");
    $qSess->execute([$id]);
    $lastSessions = $qSess->fetchAll();

    $risultati = [];
    foreach ($lastSessions as $sessRow) {
        $sessId  = $sessRow['session_id'];
        $mode    = $sessRow['mode'] ?? 'teams';

        if ($mode === 'ffa') {
            // Punteggio totale del giocatore (solo game FFA, non singoli)
            $qMyTot = $pdo->prepare("SELECT SUM(sc.score) FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND sc.player_id=? AND t.name='__FFA__'");
            $qMyTot->execute([$sessId, $id]);
            $myTot = (int)$qMyTot->fetchColumn();

            // Max tra tutti i giocatori FFA
            $qMax = $pdo->prepare("SELECT MAX(ptot) FROM (SELECT sc.player_id, SUM(sc.score) AS ptot FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND t.name='__FFA__' GROUP BY sc.player_id) pt");
            $qMax->execute([$sessId]);
            $maxTot = (int)$qMax->fetchColumn();

            // Quanti hanno il massimo
            $qCnt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT sc.player_id, SUM(sc.score) AS ptot FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND t.name='__FFA__' GROUP BY sc.player_id HAVING SUM(sc.score)=?) pt");
            $qCnt->execute([$sessId, $maxTot]);
            $cntMax = (int)$qCnt->fetchColumn();

            if ($myTot === $maxTot && $cntMax === 1) {
                $risultati[] = 'V';
            } else {
                $risultati[] = 'P';
            }
        } else {
            // Logica teams normale
            $qMyTeamId = $pdo->prepare('SELECT DISTINCT team_id FROM scores WHERE session_id = ? AND player_id = ? AND team_id IS NOT NULL LIMIT 1');
            $qMyTeamId->execute([$sessId, $id]);
            $myTeamId = $qMyTeamId->fetchColumn();
            if (!$myTeamId) continue;

            $qMyTot = $pdo->prepare('SELECT SUM(score) AS tot FROM scores WHERE session_id = ? AND team_id = ?');
            $qMyTot->execute([$sessId, $myTeamId]);
            $myTot = (int)$qMyTot->fetchColumn();

            $qMaxTeam = $pdo->prepare('SELECT MAX(team_tot) FROM (SELECT team_id, SUM(score) AS team_tot FROM scores WHERE session_id = ? AND team_id IS NOT NULL GROUP BY team_id) t');
            $qMaxTeam->execute([$sessId]);
            $maxTeam = (int)$qMaxTeam->fetchColumn();

            $qCountWin = $pdo->prepare('SELECT COUNT(*) FROM (SELECT team_id, SUM(score) AS team_tot FROM scores WHERE session_id = ? AND team_id IS NOT NULL GROUP BY team_id HAVING SUM(score) = ?) w');
            $qCountWin->execute([$sessId, $maxTeam]);
            $countWin = (int)$qCountWin->fetchColumn();

            if ($myTot === $maxTeam && $countWin > 1) {
                $risultati[] = 'N';
            } elseif ($myTot === $maxTeam) {
                $risultati[] = 'V';
            } else {
                $risultati[] = 'P';
            }
        }
    }

    // Dal più vecchio al più recente
    $player['ultimi_risultati'] = array_reverse($risultati);
}

// ── CALCOLO PAGAMENTI ─────────────────────────────────────────────────────
// Logica: vinci = €0, perdi = costo×game×2, pareggio = costo×game, singolo = costo×game

$qSessWithCost = $pdo->query('
    SELECT id, cost_per_game, COALESCE(mode, \'teams\') AS mode FROM sessions
    WHERE cost_per_game IS NOT NULL AND cost_per_game > 0
');
$sessWithCost = $qSessWithCost->fetchAll();

$paymentMap = [];

foreach ($sessWithCost as $sess) {
    $sid  = $sess['id'];
    $cost = floatval($sess['cost_per_game']);
    $mode = $sess['mode'];

    $qPG = $pdo->prepare('SELECT player_id, team_id, COUNT(*) AS num_games FROM scores WHERE session_id = ? GROUP BY player_id, team_id');
    $qPG->execute([$sid]);
    $playerGames = $qPG->fetchAll();

    if ($mode === 'ffa') {
        // ── FFA: solo giocatori con team __FFA__, singoli pagano separatamente ──
        $qFFAPG = $pdo->prepare("SELECT sc.player_id, COUNT(*) AS num_games, SUM(sc.score) AS total_score FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND t.name='__FFA__' GROUP BY sc.player_id");
        $qFFAPG->execute([$sid]);
        $ffaPlayers = $qFFAPG->fetchAll();

        $playerTotals = [];
        foreach ($ffaPlayers as $fp) {
            $playerTotals[$fp['player_id']] = ['score' => (int)$fp['total_score'], 'games' => (int)$fp['num_games']];
        }

        if ($playerTotals) {
            $maxScore = max(array_column($playerTotals, 'score'));
            $winnersN = count(array_filter($playerTotals, fn($p) => $p['score'] === $maxScore));
            $nPlayers = count($playerTotals);
            $winnerPid   = array_key_first(array_filter($playerTotals, fn($p) => $p['score'] === $maxScore));
            $winnerBase  = $cost * ($playerTotals[$winnerPid]['games'] ?? 1);
            $quota       = ($nPlayers > 1) ? $winnerBase / ($nPlayers - 1) : 0;

            foreach ($playerTotals as $pid => $pt) {
                if (!isset($paymentMap[$pid])) $paymentMap[$pid] = 0.0;
                $base = $cost * $pt['games'];
                if ($winnersN === 1 && $pt['score'] === $maxScore) {
                    $paymentMap[$pid] += 0;
                } else {
                    $paymentMap[$pid] += $base + $quota;
                }
            }
        }

        // Giocatori singoli nella sessione FFA pagano solo i loro game
        foreach ($playerGames as $pg) {
            if ($pg['team_id'] !== null) continue; // salta FFA e team
            $pid  = $pg['player_id'];
            $base = $cost * (int)$pg['num_games'];
            if (!isset($paymentMap[$pid])) $paymentMap[$pid] = 0.0;
            // singoli non contano nella dashboard
        }
    } else {
        // ── TEAMS: logica normale ──
        $qTT = $pdo->prepare('SELECT team_id, SUM(score) AS tot FROM scores WHERE session_id = ? AND team_id IS NOT NULL GROUP BY team_id');
        $qTT->execute([$sid]);
        $teamTotals = [];
        foreach ($qTT->fetchAll() as $t) $teamTotals[$t['team_id']] = (int)$t['tot'];

        $maxTot    = $teamTotals ? max($teamTotals) : 0;
        $winnerCnt = count(array_filter($teamTotals, fn($t) => $t === $maxTot));
        $isDraw    = $winnerCnt > 1;

        foreach ($playerGames as $pg) {
            $pid  = $pg['player_id'];
            $tid  = $pg['team_id'];
            $nG   = (int)$pg['num_games'];
            $base = $cost * $nG;
            if (!isset($paymentMap[$pid])) $paymentMap[$pid] = 0.0;
            if ($tid === null) {
                // Singolo — non conta dashboard
            } elseif ($isDraw) {
                $paymentMap[$pid] += $base;
            } elseif (($teamTotals[$tid] ?? 0) === $maxTot) {
                $paymentMap[$pid] += 0;
            } else {
                $paymentMap[$pid] += $base * 2;
            }
        }
    }
}

// Inietta saldo_pagamenti in ogni giocatore
foreach ($players as &$player) {
    $pid = $player['id'];
    $player['saldo_pagamenti'] = isset($paymentMap[$pid])
        ? round($paymentMap[$pid], 2)
        : null;
}
unset($player);

echo json_encode(array_values($players));
