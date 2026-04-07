<?php
// ============================================
//  api/leaderboard.php
//  GET → classifica generale con tutte le stat
//  FIXED: Logica vittorie unificata con stats.php
// ============================================
require_once __DIR__ . '/config.php';

$pdo = getPDO();

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

    // ── VITTORIE/PAREGGI SQUADRA (LOGICA UNIFICATA CON STATS.PHP) ──
    // Prende tutte le sessioni con team
    $qSCS = $pdo->prepare("
        SELECT DISTINCT sc.session_id, sc.team_id, se.mode
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        WHERE sc.player_id = ? AND sc.team_id IS NOT NULL
    ");
    $qSCS->execute([$id]);
    $sessRows = $qSCS->fetchAll();

    // Deduplicazione: una riga per sessione
    $sessMap = [];
    foreach ($sessRows as $sr) {
        $sid = $sr['session_id'];
        if (!isset($sessMap[$sid])) {
            $sessMap[$sid] = [
                'team_id' => $sr['team_id'],
                'mode' => $sr['mode'] ?? 'teams'
            ];
        }
    }

    $vittorie = 0;
    $pareggi  = 0;

    foreach ($sessMap as $sid => $info) {
        $tid = $info['team_id'];
        $mode = $info['mode'];

        if ($mode === 'ffa') {
            // FFA: confronta punteggi GIOCATORI
            $qFFATot = $pdo->prepare("
                SELECT sc.player_id, SUM(sc.score) AS ptot
                FROM scores sc
                JOIN teams t ON sc.team_id = t.id
                WHERE sc.session_id = ? AND t.name = '__FFA__'
                GROUP BY sc.player_id
            ");
            $qFFATot->execute([$sid]);
            $playerTotals = [];
            $myTotal = null;
            foreach ($qFFATot->fetchAll() as $pt) {
                $playerTotals[$pt['player_id']] = (int)$pt['ptot'];
                if ($pt['player_id'] == $id) $myTotal = (int)$pt['ptot'];
            }

            if ($myTotal === null || empty($playerTotals)) continue;

            $maxTotal = max($playerTotals);
            $winCnt = count(array_filter($playerTotals, fn($t) => $t === $maxTotal));

            if ($myTotal === $maxTotal && $winCnt > 1) $pareggi++;
            elseif ($myTotal === $maxTotal) $vittorie++;

        } else {
            // TEAMS: confronta punteggi TEAM
            $qTT = $pdo->prepare('
                SELECT team_id, SUM(score) AS tot
                FROM scores
                WHERE session_id = ? AND team_id IS NOT NULL
                GROUP BY team_id
            ');
            $qTT->execute([$sid]);
            $teamTotals = [];
            foreach ($qTT->fetchAll() as $t) {
                $teamTotals[$t['team_id']] = (int)$t['tot'];
            }

            if (empty($teamTotals) || !isset($teamTotals[$tid])) continue;

            $myTotal  = $teamTotals[$tid];
            $maxTotal = max($teamTotals);
            $winCnt = count(array_filter($teamTotals, fn($t) => $t === $maxTotal));

            if ($myTotal === $maxTotal && $winCnt > 1) $pareggi++;
            elseif ($myTotal === $maxTotal) $vittorie++;
        }
    }

    $player['vittorie_squadra'] = $vittorie;
    $player['pareggi_squadra'] = $pareggi;
    $player['serate_con_squadra'] = count($sessMap);

    // ── VOLTE TOP SCORER (ESCLUSI PAREGGI) ──
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
        AND 1 = (
            SELECT COUNT(*) FROM (
                SELECT player_id, SUM(score) AS tot
                FROM scores
                WHERE session_id = myTot.session_id
                GROUP BY player_id
                HAVING SUM(score) = (
                    SELECT MAX(tot2) FROM (
                        SELECT SUM(score) AS tot2
                        FROM scores
                        WHERE session_id = myTot.session_id
                        GROUP BY player_id
                    ) maxScores
                )
            ) winners
        )
    ');
    $qTop->execute([$id]);
    $player['volte_top_scorer'] = (int)$qTop->fetchColumn();

    // ── ULTIMI 5 RISULTATI (V/P/N) ──
    $qSess = $pdo->prepare("
        SELECT DISTINCT sc.session_id, se.mode
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        LEFT JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ?
        AND sc.team_id IS NOT NULL
        ORDER BY sc.session_id DESC
        LIMIT 5
    ");
    $qSess->execute([$id]);
    $lastSessions = $qSess->fetchAll();

    $risultati = [];
    foreach ($lastSessions as $sessRow) {
        $sessId = $sessRow['session_id'];
        $mode = $sessRow['mode'] ?? 'teams';

        if ($mode === 'ffa') {
            $qFFATot = $pdo->prepare("
                SELECT sc.player_id, SUM(sc.score) AS ptot
                FROM scores sc
                JOIN teams t ON sc.team_id = t.id
                WHERE sc.session_id = ? AND t.name = '__FFA__'
                GROUP BY sc.player_id
            ");
            $qFFATot->execute([$sessId]);
            $playerTotals = [];
            $myTotal = null;
            foreach ($qFFATot->fetchAll() as $pt) {
                $playerTotals[$pt['player_id']] = (int)$pt['ptot'];
                if ($pt['player_id'] == $id) $myTotal = (int)$pt['ptot'];
            }

            if ($myTotal === null) continue;

            $maxTotal = max($playerTotals);
            $winCnt = count(array_filter($playerTotals, fn($t) => $t === $maxTotal));

            if ($myTotal === $maxTotal && $winCnt === 1) $risultati[] = 'V';
            elseif ($myTotal === $maxTotal && $winCnt > 1) $risultati[] = 'N';
            else $risultati[] = 'P';

        } else {
            $qMyTeamId = $pdo->prepare('
                SELECT DISTINCT team_id
                FROM scores
                WHERE session_id = ? AND player_id = ? AND team_id IS NOT NULL
                LIMIT 1
            ');
            $qMyTeamId->execute([$sessId, $id]);
            $myTeamId = $qMyTeamId->fetchColumn();
            if (!$myTeamId) continue;

            $qTT = $pdo->prepare('
                SELECT team_id, SUM(score) AS tot
                FROM scores
                WHERE session_id = ? AND team_id IS NOT NULL
                GROUP BY team_id
            ');
            $qTT->execute([$sessId]);
            $teamTotals = [];
            foreach ($qTT->fetchAll() as $t) {
                $teamTotals[$t['team_id']] = (int)$t['tot'];
            }

            if (!isset($teamTotals[$myTeamId])) continue;

            $myTotal = $teamTotals[$myTeamId];
            $maxTotal = max($teamTotals);
            $winCnt = count(array_filter($teamTotals, fn($t) => $t === $maxTotal));

            if ($myTotal === $maxTotal && $winCnt > 1) $risultati[] = 'N';
            elseif ($myTotal === $maxTotal) $risultati[] = 'V';
            else $risultati[] = 'P';
        }
    }

    $player['ultimi_risultati'] = array_reverse($risultati);
}

// ── CALCOLO PAGAMENTI ──
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

    $qPG = $pdo->prepare('
        SELECT player_id, team_id, COUNT(*) AS num_games
        FROM scores
        WHERE session_id = ?
        GROUP BY player_id, team_id
    ');
    $qPG->execute([$sid]);
    $playerGames = $qPG->fetchAll();

    if ($mode === 'ffa') {
        $qFFAPG = $pdo->prepare("
            SELECT sc.player_id, COUNT(*) AS num_games, SUM(sc.score) AS total_score
            FROM scores sc
            JOIN teams t ON sc.team_id = t.id
            WHERE sc.session_id = ? AND t.name = '__FFA__'
            GROUP BY sc.player_id
        ");
        $qFFAPG->execute([$sid]);
        $ffaPlayers = $qFFAPG->fetchAll();

        $playerTotals = [];
        foreach ($ffaPlayers as $fp) {
            $playerTotals[$fp['player_id']] = [
                'score' => (int)$fp['total_score'],
                'games' => (int)$fp['num_games']
            ];
        }

        if ($playerTotals) {
            $maxScore = max(array_column($playerTotals, 'score'));
            $winnersN = count(array_filter($playerTotals, fn($p) => $p['score'] === $maxScore));
            $nPlayers = count($playerTotals);
            $winnerPid = array_key_first(array_filter($playerTotals, fn($p) => $p['score'] === $maxScore));
            $winnerBase = $cost * ($playerTotals[$winnerPid]['games'] ?? 1);
            $quota = ($nPlayers > 1) ? $winnerBase / ($nPlayers - 1) : 0;

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

    } else {
        $qTT = $pdo->prepare('
            SELECT team_id, SUM(score) AS tot
            FROM scores
            WHERE session_id = ? AND team_id IS NOT NULL
            GROUP BY team_id
        ');
        $qTT->execute([$sid]);
        $teamTotals = [];
        foreach ($qTT->fetchAll() as $t) {
            $teamTotals[$t['team_id']] = (int)$t['tot'];
        }

        $maxTot = $teamTotals ? max($teamTotals) : 0;
        $winnerCnt = count(array_filter($teamTotals, fn($t) => $t === $maxTot));
        $isDraw = $winnerCnt > 1;

        foreach ($playerGames as $pg) {
            $pid = $pg['player_id'];
            $tid = $pg['team_id'];
            $nG = (int)$pg['num_games'];
            $base = $cost * $nG;

            if (!isset($paymentMap[$pid])) $paymentMap[$pid] = 0.0;

            if ($tid === null) {
                // Singolo - non conta
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

// Inietta saldo_pagamenti
foreach ($players as &$player) {
    $pid = $player['id'];
    $player['saldo_pagamenti'] = isset($paymentMap[$pid]) ? round($paymentMap[$pid], 2) : null;
}
unset($player);

echo json_encode(array_values($players));
