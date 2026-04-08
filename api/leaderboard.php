<?php
// ============================================
//  api/leaderboard.php
//  GET → classifica generale con tutte le stat
//  LOGICA VITTORIE IDENTICA A stats.php
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

// ── CACHE PRE-CALCOLATA (evita query ripetute nel loop) ───────────

// Modalità per sessione
$sessionModes = [];
foreach ($pdo->query("SELECT id, COALESCE(mode,'teams') AS mode FROM sessions") as $s) {
    $sessionModes[(int)$s['id']] = $s['mode'];
}

// Totali per team (solo sessioni teams, esclude __FFA__)
$teamTotalCache = [];
foreach ($pdo->query("
    SELECT t.id AS team_id, sc.session_id, SUM(sc.score) AS tot
    FROM scores sc JOIN teams t ON sc.team_id = t.id
    WHERE t.name != '__FFA__'
    GROUP BY t.id, sc.session_id
") as $tt) {
    $teamTotalCache[(int)$tt['session_id']][(int)$tt['team_id']] = (int)$tt['tot'];
}

// Totali individuali FFA (per player, per sessione)
$ffaTotalCache = [];
foreach ($pdo->query("
    SELECT sc.player_id, sc.session_id, SUM(sc.score) AS tot
    FROM scores sc JOIN teams t ON sc.team_id = t.id
    WHERE t.name = '__FFA__'
    GROUP BY sc.player_id, sc.session_id
") as $ft) {
    $ffaTotalCache[(int)$ft['session_id']][(int)$ft['player_id']] = (int)$ft['tot'];
}

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

    // ── VITTORIE/PAREGGI SQUADRA (solo sessioni teams, FFA escluso) ──
    $qSCS = $pdo->prepare("
        SELECT DISTINCT sc.session_id, sc.team_id
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ? AND sc.team_id IS NOT NULL
          AND t.name != '__FFA__'
          AND COALESCE(se.mode,'teams') != 'ffa'
    ");
    $qSCS->execute([$id]);
    $sessRows = $qSCS->fetchAll();

    $sessMap = [];
    foreach ($sessRows as $sr) {
        $sid = (int)$sr['session_id'];
        if (!isset($sessMap[$sid])) $sessMap[$sid] = (int)$sr['team_id'];
    }

    $vittorie = 0;
    $pareggi  = 0;

    foreach ($sessMap as $sid => $tid) {
        $tots = $teamTotalCache[$sid] ?? [];
        if (empty($tots) || !isset($tots[$tid])) continue;
        $myTot  = $tots[$tid];
        $maxTot = max($tots);
        // N solo se TUTTI i team pareggiano (logica allineata a stats.php)
        if ($myTot === $maxTot && count(array_unique($tots)) === 1) $pareggi++;
        elseif ($myTot === $maxTot) $vittorie++;
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

    // ── ULTIMI 5 RISULTATI (V/P/N) — FFA gestito individualmente ──
    $qSess = $pdo->prepare("
        SELECT DISTINCT sc.session_id, COALESCE(se.mode,'teams') AS mode,
               t.name AS team_name, sc.team_id
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        LEFT JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ? AND sc.team_id IS NOT NULL
        ORDER BY sc.session_id DESC
        LIMIT 5
    ");
    $qSess->execute([$id]);

    $seen      = [];
    $risultati = [];
    foreach ($qSess->fetchAll() as $r) {
        $sid      = (int)$r['session_id'];
        $mode     = $r['mode'];
        $teamName = $r['team_name'];
        $tid      = (int)$r['team_id'];

        if (isset($seen[$sid])) continue;
        $seen[$sid] = true;

        if ($mode === 'ffa' && $teamName === '__FFA__') {
            // FFA: confronto punteggi individuali
            $ffaScores = $ffaTotalCache[$sid] ?? [];
            if (empty($ffaScores) || !isset($ffaScores[$id])) continue;
            $myTotal  = $ffaScores[$id];
            $maxTotal = max($ffaScores);
            $topCount = count(array_filter($ffaScores, fn($s) => $s === $maxTotal));
            $risultati[] = ($myTotal === $maxTotal && $topCount === 1) ? 'V' : 'P';
        } else {
            // Sessione teams normale
            $tots = $teamTotalCache[$sid] ?? [];
            if (empty($tots) || !isset($tots[$tid])) continue;
            $myTotal  = $tots[$tid];
            $maxTotal = max($tots);
            // N solo se tutti i team pareggiano (allineato a stats.php)
            if ($myTotal === $maxTotal && count(array_unique($tots)) === 1) $risultati[] = 'N';
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
                // Singolo
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
