<?php
// ============================================
//  api/stats.php — statistiche complete
//  ?from=YYYY-MM-DD&to=YYYY-MM-DD
//  ?group_id=X  → filtra per gruppo
// ============================================
require_once __DIR__ . '/config.php';
$pdo = getPDO();


$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;

$dateWhere  = '';
$dateParams = [];
if ($from && $to)  { $dateWhere = 'WHERE se.date BETWEEN ? AND ?'; $dateParams = [$from, $to]; }
elseif ($from)     { $dateWhere = 'WHERE se.date >= ?';            $dateParams = [$from]; }
elseif ($to)       { $dateWhere = 'WHERE se.date <= ?';            $dateParams = [$to]; }

// ── Group filter (valore intero sicuro per interpolazione) ──
$groupId = isset($_GET['group_id']) && $_GET['group_id'] !== 'all'
    ? (int)$_GET['group_id'] : null;

// Condizioni per query che usano alias "p" (players) + "$dateWhere" (sessions)
$gAfterDate  = $groupId !== null
    ? ($dateWhere ? "AND p.group_id = $groupId" : "WHERE p.group_id = $groupId")
    : '';
// Solo group (senza date) — per query che non usano $dateWhere
$gPlayerOnly = $groupId !== null ? "WHERE p.group_id = $groupId" : '';
// Versione AND per query con WHERE già presenti
$gAnd        = $groupId !== null ? "AND p.group_id = $groupId" : '';
// Per query senza join a players: aggiunge JOIN + filtro (safe integer)
$gJoinPlayer = $groupId !== null ? "JOIN players p ON sc.player_id = p.id" : '';
$gJoinAnd    = $groupId !== null
    ? ($dateWhere ? "AND p.group_id = $groupId" : "WHERE p.group_id = $groupId")
    : '';

// ── TOTALI ───────────────────────────────────
$q = $pdo->prepare("
    SELECT
        MAX(sc.score)            AS record_assoluto,
        ROUND(AVG(sc.score),1)   AS media_gruppo
    FROM scores sc
    JOIN sessions se ON sc.session_id = se.id
    $gJoinPlayer
    $dateWhere
    $gJoinAnd
");
$q->execute($dateParams);
$totals = $q->fetch();

$sessGroupFilter = $groupId !== null
    ? ($dateWhere ? "AND se.group_id = $groupId" : "WHERE se.group_id = $groupId")
    : '';
$qSess = $pdo->prepare("SELECT COUNT(*) FROM sessions se " . ($dateWhere ?: '') . " $sessGroupFilter");
$qSess->execute($dateParams);
$totals['totale_sessioni'] = $qSess->fetchColumn();

// Record holder — basato sul singolo game
$qRec = $pdo->prepare("
    SELECT p.name, p.emoji, sc.score, se.date
    FROM scores sc
    JOIN players  p  ON sc.player_id  = p.id
    JOIN sessions se ON sc.session_id = se.id
    $dateWhere
    $gAfterDate
    ORDER BY sc.score DESC LIMIT 1
");
$qRec->execute($dateParams);
$recordHolder = $qRec->fetch();

// ── CLASSIFICA COMPLETA ──────────────────────
// Tutte le metriche in una query + vittorie squadra
$qLb = $pdo->prepare("
    SELECT p.id, p.name, p.emoji, p.nickname, p.group_id,
           COUNT(DISTINCT sc.session_id)  AS partite,
           COUNT(sc.id)                   AS game_totali,
           ROUND(AVG(sc.score),1)         AS media,
           MAX(sc.score)                  AS record,
           MIN(sc.score)                  AS minimo
    FROM players p
    LEFT JOIN scores sc   ON sc.player_id  = p.id
    LEFT JOIN sessions se ON sc.session_id = se.id
    $dateWhere
    $gAfterDate
    GROUP BY p.id
");
$qLb->execute($dateParams);
$leaderboard = $qLb->fetchAll();

// ── MEDIA ULTIMI 3 MESI (forma recente) ──────
// Sempre su tutto lo storico — non filtrata dal periodo scelto
$threeMonthsAgo = date('Y-m-d', strtotime('-3 months'));
$qRecent = $pdo->prepare("
    SELECT p.id, ROUND(AVG(sc.score),1) AS media_recente
    FROM players p
    JOIN scores sc   ON sc.player_id  = p.id
    JOIN sessions se ON sc.session_id = se.id
    WHERE se.date >= ? $gAnd
    GROUP BY p.id
");
$qRecent->execute([$threeMonthsAgo]);
$recentMap = [];
foreach ($qRecent->fetchAll() as $r) {
    $recentMap[$r['id']] = $r['media_recente'];
}

// ── ULTIMI RISULTATI V/P/N ──────────────────
// Per ogni giocatore: ultime 5 sessioni (teams + FFA), calcola V/P/N
$gUltimiFilter = $groupId !== null
    ? "AND sc.player_id IN (SELECT id FROM players WHERE group_id = $groupId)"
    : '';
$qUltimi = $pdo->prepare("
    SELECT sc.player_id, sc.session_id, sc.team_id, se.date, COALESCE(se.mode,'teams') AS mode, t.name AS team_name
    FROM scores sc
    JOIN sessions se ON sc.session_id = se.id
    LEFT JOIN teams t ON sc.team_id = t.id
    WHERE sc.team_id IS NOT NULL $gUltimiFilter
    ORDER BY sc.player_id, sc.session_id DESC
");
$qUltimi->execute();
$ultimiRaw = $qUltimi->fetchAll();

// Precalcola totale per ogni team in ogni sessione (escluso __FFA__)
$teamTotalCache = [];
$qTeamTotals = $pdo->query("
    SELECT t.id AS team_id, sc.session_id, SUM(sc.score) AS tot
    FROM scores sc JOIN teams t ON sc.team_id=t.id
    WHERE t.name != '__FFA__'
    GROUP BY t.id, sc.session_id
");
foreach ($qTeamTotals->fetchAll() as $tt) {
    $teamTotalCache[$tt['session_id']][$tt['team_id']] = (int)$tt['tot'];
}

// Precalcola totale per ogni giocatore FFA in ogni sessione
$ffaTotalCache = [];
$qFFATotals = $pdo->query("
    SELECT sc.player_id, sc.session_id, SUM(sc.score) AS tot
    FROM scores sc JOIN teams t ON sc.team_id=t.id
    WHERE t.name = '__FFA__'
    GROUP BY sc.player_id, sc.session_id
");
foreach ($qFFATotals->fetchAll() as $ft) {
    $ffaTotalCache[$ft['session_id']][$ft['player_id']] = (int)$ft['tot'];
}

$playerResults = [];
foreach ($ultimiRaw as $r) {
    $pid       = $r['player_id'];
    $sid       = $r['session_id'];
    $tid       = $r['team_id'];
    $mode      = $r['mode'];
    $teamName  = $r['team_name'];
    if (!isset($playerResults[$pid])) $playerResults[$pid] = [];
    if (count($playerResults[$pid]) >= 5) continue;
    $alreadyDone = false;
    foreach ($playerResults[$pid] as $existing) {
        if (isset($existing['sid']) && $existing['sid'] === $sid) { $alreadyDone = true; break; }
    }
    if ($alreadyDone) continue;

    if ($mode === 'ffa' && $teamName === '__FFA__') {
        // FFA: solo il primo unico vince
        $ffaScores = $ffaTotalCache[$sid] ?? [];
        if (empty($ffaScores) || !isset($ffaScores[$pid])) continue;
        $myTotal  = $ffaScores[$pid];
        $maxTotal = max($ffaScores);
        $topCount = count(array_filter($ffaScores, fn($s) => $s === $maxTotal));
        $esito = ($myTotal === $maxTotal && $topCount === 1) ? 'V' : 'P';
    } else {
        // Teams normale
        $teamTotals = $teamTotalCache[$sid] ?? [];
        if (empty($teamTotals) || !isset($teamTotals[$tid])) continue;
        $myTotal  = $teamTotals[$tid];
        $maxTotal = max($teamTotals);
        if ($myTotal === $maxTotal && count(array_unique($teamTotals)) > 1) $esito = 'V';
        elseif ($myTotal === $maxTotal && count(array_unique($teamTotals)) === 1) $esito = 'N';
        else $esito = 'P';
    }

    $playerResults[$pid][] = ['sid' => $sid, 'esito' => $esito];
}

$playerTeamTotals = [];
foreach ($playerResults as $pid => $results) {
    // La query ordina session_id DESC (recente→vecchio), invertiamo per avere vecchio→recente
    $playerTeamTotals[$pid] = array_reverse(array_column($results, 'esito'));
}

// Precalcola vittorie_squadra, pareggi e serate_con_squadra per ogni giocatore
// Usa il $teamTotalCache già calcolato sopra per evitare query ridondanti
$vittorieMap      = [];
$pareggiMap       = [];
$serateSquadraMap = [];

foreach ($leaderboard as $pl) {
    $pid = $pl['id'];
    $params = array_merge([$pid], $dateParams);

    // ── VITTORIE/PAREGGI: sessioni teams ──
    $qTeam = $pdo->prepare("
        SELECT DISTINCT sc.session_id, sc.team_id
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ? AND sc.team_id IS NOT NULL
          AND t.name != '__FFA__'
          AND COALESCE(se.mode,'teams') != 'ffa'
        " . ($dateWhere ? str_replace('WHERE', 'AND', $dateWhere) : '') . "
    ");
    $qTeam->execute($params);
    $sessMap = [];
    foreach ($qTeam->fetchAll() as $sr) {
        $sid = (int)$sr['session_id'];
        if (!isset($sessMap[$sid])) $sessMap[$sid] = (int)$sr['team_id'];
    }

    // ── FFA: sessioni individuali ──
    $qFFA = $pdo->prepare("
        SELECT DISTINCT sc.session_id
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ? AND sc.team_id IS NOT NULL
          AND t.name = '__FFA__'
          AND COALESCE(se.mode,'teams') = 'ffa'
        " . ($dateWhere ? str_replace('WHERE', 'AND', $dateWhere) : '') . "
    ");
    $qFFA->execute($params);
    $ffaSessions = $qFFA->fetchAll(PDO::FETCH_COLUMN);

    $vittorie = 0;
    $pareggi  = 0;

    // Sessioni teams: V se squadra ha il max, N se pareggio completo
    foreach ($sessMap as $sid => $tid) {
        $tots = $teamTotalCache[$sid] ?? [];
        if (empty($tots) || !isset($tots[$tid])) continue;
        $myTot  = $tots[$tid];
        $maxTot = max($tots);
        if ($myTot === $maxTot && count(array_unique($tots)) === 1) $pareggi++;
        elseif ($myTot === $maxTot) $vittorie++;
    }

    // Sessioni FFA: V solo per il 1° classificato unico, tutti gli altri P
    foreach ($ffaSessions as $sid) {
        $sid       = (int)$sid;
        $ffaScores = $ffaTotalCache[$sid] ?? [];
        if (empty($ffaScores) || !isset($ffaScores[$pid])) continue;
        $myTotal  = $ffaScores[$pid];
        $maxTotal = max($ffaScores);
        $topCount = count(array_filter($ffaScores, fn($s) => $s === $maxTotal));
        if ($myTotal === $maxTotal && $topCount === 1) $vittorie++;
        // altrimenti: sconfitta → calcolata come serate - vittorie - pareggi
    }

    $vittorieMap[$pid]      = $vittorie;
    $pareggiMap[$pid]       = $pareggi;
    $serateSquadraMap[$pid] = count($sessMap) + count($ffaSessions);
}

// Inietta tutti i campi nella leaderboard
foreach ($leaderboard as &$p) {
    $pid = $p['id'];
    $p['media_recente']      = $recentMap[$pid] ?? null;
    $p['ultimi_risultati']   = $playerTeamTotals[$pid] ?? [];
    $p['vittorie_squadra']   = $vittorieMap[$pid] ?? 0;
    $p['pareggi_squadra']    = $pareggiMap[$pid]  ?? 0;
    $p['serate_con_squadra'] = $serateSquadraMap[$pid] ?? 0;
}
unset($p);

// ── CALCOLO PAGAMENTI (filtrato per periodo) ──────────────────────────────
$payGroupAnd = $groupId !== null ? "AND se.group_id = $groupId" : '';
$qSessWithCost = $pdo->prepare("
    SELECT se.id, se.cost_per_game, COALESCE(se.mode,'teams') AS mode
    FROM sessions se
    WHERE se.cost_per_game IS NOT NULL AND se.cost_per_game > 0
    $payGroupAnd
    " . ($dateWhere ? str_replace('WHERE', 'AND', $dateWhere) : '') . "
");
$qSessWithCost->execute($dateParams);
$sessWithCost = $qSessWithCost->fetchAll();

$paymentSfide   = [];
$paymentSingolo = [];

foreach ($sessWithCost as $sess) {
    $sid  = $sess['id'];
    $cost = floatval($sess['cost_per_game']);
    $mode = $sess['mode'];

    $qPG = $pdo->prepare('SELECT sc.player_id, sc.team_id, COUNT(*) AS num_games FROM scores sc WHERE sc.session_id = ? GROUP BY sc.player_id, sc.team_id');
    $qPG->execute([$sid]);
    $playerGames = $qPG->fetchAll();

    if ($mode === 'ffa') {
        // ── FFA: solo giocatori con team __FFA__ partecipano alla sfida ──
        $qFFAPG = $pdo->prepare("SELECT sc.player_id, COUNT(*) AS num_games, SUM(sc.score) AS total_score FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND t.name='__FFA__' GROUP BY sc.player_id");
        $qFFAPG->execute([$sid]);
        $ffaP = $qFFAPG->fetchAll();
        $playerTotals = [];
        foreach ($ffaP as $fp) $playerTotals[$fp['player_id']] = ['score'=>(int)$fp['total_score'],'games'=>(int)$fp['num_games']];

        if ($playerTotals) {
            $maxScore = max(array_column($playerTotals, 'score'));
            $winnersN = count(array_filter($playerTotals, fn($p) => $p['score'] === $maxScore));
            $nPlayers = count($playerTotals);
            $winnerPid  = array_key_first(array_filter($playerTotals, fn($p) => $p['score'] === $maxScore));
            $winnerBase = $cost * ($playerTotals[$winnerPid]['games'] ?? 1);
            $quota      = ($nPlayers > 1) ? $winnerBase / ($nPlayers - 1) : 0;
            foreach ($playerTotals as $pid => $pt) {
                if (!isset($paymentSfide[$pid])) $paymentSfide[$pid] = 0.0;
                $base = $cost * $pt['games'];
                $paymentSfide[$pid] += ($winnersN===1 && $pt['score']===$maxScore) ? 0 : $base + $quota;
            }
        }
        // Giocatori extra (team_id NULL) pagano normalmente
        foreach ($playerGames as $pg) {
            if ($pg['team_id'] !== null) continue;
            $pid = $pg['player_id'];
            $base = $cost * (int)$pg['num_games'];
            if (!isset($paymentSingolo[$pid])) $paymentSingolo[$pid] = 0.0;
            $paymentSingolo[$pid] += $base;
        }
    } else {
        $qTT = $pdo->prepare("SELECT t.id AS team_id, SUM(sc.score) AS tot FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND t.name!='__FFA__' GROUP BY t.id");
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
            if ($tid === null) {
                if (!isset($paymentSingolo[$pid])) $paymentSingolo[$pid] = 0.0;
                $paymentSingolo[$pid] += $base;
            } else {
                if (!isset($paymentSfide[$pid])) $paymentSfide[$pid] = 0.0;
                if ($isDraw)                                    $paymentSfide[$pid] += $base;
                elseif (($teamTotals[$tid] ?? 0) === $maxTot)  $paymentSfide[$pid] += 0;
                else                                            $paymentSfide[$pid] += $base * 2;
            }
        }
    }
}

// Inietta pagamenti e partite nel leaderboard
foreach ($leaderboard as &$p) {
    $pid = $p['id'];

    // Game nelle sfide (teams normali + FFA, escluso team __FFA__ che conta separato)
    $qGS = $pdo->prepare("
        SELECT COUNT(sc.id) FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        LEFT JOIN teams t ON sc.team_id = t.id
        WHERE sc.player_id = ? AND sc.team_id IS NOT NULL
        AND (t.name IS NULL OR t.name != '__FFA__' OR se.mode = 'ffa')
        " . ($dateWhere ? str_replace('WHERE', 'AND', $dateWhere) : '') . "
    ");
    $qGS->execute(array_merge([$pid], $dateParams));
    $p['partite_sfide'] = (int)$qGS->fetchColumn();

    // Game senza sfida (team_id NULL)
    $qSolo = $pdo->prepare("
        SELECT COUNT(sc.id) FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        WHERE sc.player_id = ? AND sc.team_id IS NULL
        " . ($dateWhere ? str_replace('WHERE', 'AND', $dateWhere) : '') . "
    ");
    $qSolo->execute(array_merge([$pid], $dateParams));
    $p['partite_singolo'] = (int)$qSolo->fetchColumn();

    $hasCost = isset($paymentSfide[$pid]) || isset($paymentSingolo[$pid]);
    $p['pagato_sfide']   = $hasCost ? round($paymentSfide[$pid]   ?? 0, 2) : null;
    $p['pagato_singolo'] = $hasCost ? round($paymentSingolo[$pid] ?? 0, 2) : null;
    $p['pagato_totale']  = $hasCost ? round(($paymentSfide[$pid] ?? 0) + ($paymentSingolo[$pid] ?? 0), 2) : null;
    $p['saldo_pagamenti'] = $p['pagato_totale'];
}
unset($p);

// ── TREND ────────────────────────────────────
$qTr = $pdo->prepare("
    SELECT p.id AS player_id, p.name, p.emoji, se.date, sc.score
    FROM scores sc JOIN players p ON sc.player_id=p.id JOIN sessions se ON sc.session_id=se.id
    $dateWhere $gAfterDate ORDER BY p.id, se.date ASC
");
$qTr->execute($dateParams);
$trend = [];
foreach ($qTr->fetchAll() as $r) {
    $pid = $r['player_id'];
    if (!isset($trend[$pid])) $trend[$pid] = ['name'=>$r['name'],'emoji'=>$r['emoji'],'data'=>[]];
    $trend[$pid]['data'][] = ['date'=>$r['date'], 'score'=>(int)$r['score']];
}

// ── DISTRIBUZIONE ────────────────────────────
$qDist = $pdo->prepare("
    SELECT p.id, p.name, p.emoji,
        SUM(CASE WHEN sc.score <  100 THEN 1 ELSE 0 END) AS r0,
        SUM(CASE WHEN sc.score BETWEEN 100 AND 149 THEN 1 ELSE 0 END) AS r100,
        SUM(CASE WHEN sc.score BETWEEN 150 AND 199 THEN 1 ELSE 0 END) AS r150,
        SUM(CASE WHEN sc.score BETWEEN 200 AND 249 THEN 1 ELSE 0 END) AS r200,
        SUM(CASE WHEN sc.score >= 250 THEN 1 ELSE 0 END) AS r250
    FROM players p
    LEFT JOIN scores sc ON sc.player_id=p.id
    LEFT JOIN sessions se ON sc.session_id=se.id
    $dateWhere $gAfterDate
    GROUP BY p.id HAVING COUNT(sc.id) > 0 ORDER BY p.name
");
$qDist->execute($dateParams);
$distribution = $qDist->fetchAll();

// ── TESTA A TESTA ────────────────────────────
$gH2h = $groupId !== null
    ? ($dateWhere ? "AND pa.group_id = $groupId AND pb.group_id = $groupId"
                  : "WHERE pa.group_id = $groupId AND pb.group_id = $groupId")
    : '';
$qH2h = $pdo->prepare("
    SELECT a.player_id AS p1_id, pa.name AS p1_name, pa.emoji AS p1_emoji, a.score AS p1_score,
           b.player_id AS p2_id, pb.name AS p2_name, pb.emoji AS p2_emoji, b.score AS p2_score
    FROM scores a
    JOIN scores b    ON a.session_id=b.session_id AND a.player_id < b.player_id
    JOIN players pa  ON a.player_id=pa.id
    JOIN players pb  ON b.player_id=pb.id
    JOIN sessions se ON a.session_id=se.id
    $dateWhere $gH2h
");
$qH2h->execute($dateParams);
$h2h = [];
foreach ($qH2h->fetchAll() as $r) {
    $key = $r['p1_id'].'_'.$r['p2_id'];
    if (!isset($h2h[$key])) $h2h[$key] = [
        'p1_id'=>$r['p1_id'],'p1_name'=>$r['p1_name'],'p1_emoji'=>$r['p1_emoji'],'p1_wins'=>0,
        'p2_id'=>$r['p2_id'],'p2_name'=>$r['p2_name'],'p2_emoji'=>$r['p2_emoji'],'p2_wins'=>0,
        'draws'=>0,'total'=>0
    ];
    $h2h[$key]['total']++;
    if     ($r['p1_score'] > $r['p2_score']) $h2h[$key]['p1_wins']++;
    elseif ($r['p2_score'] > $r['p1_score']) $h2h[$key]['p2_wins']++;
    else                                     $h2h[$key]['draws']++;
}

// ── CHIMICA DI SQUADRA ───────────────────────
$qChem = $pdo->prepare("
    SELECT a.player_id AS p1_id, pa.name AS p1_name, pa.emoji AS p1_emoji,
           b.player_id AS p2_id, pb.name AS p2_name, pb.emoji AS p2_emoji,
           a.team_id, a.session_id
    FROM scores a
    JOIN scores b    ON a.session_id=b.session_id AND a.team_id=b.team_id AND a.player_id < b.player_id
    JOIN players pa  ON a.player_id=pa.id
    JOIN players pb  ON b.player_id=pb.id
    JOIN sessions se ON a.session_id=se.id
    $dateWhere $gH2h
");
$qChem->execute($dateParams);
$chemistry = [];
$seenSessions = [];
foreach ($qChem->fetchAll() as $r) {
    $key = $r['p1_id'].'_'.$r['p2_id'];
    $sid = $r['session_id'];
    if (!isset($chemistry[$key])) $chemistry[$key] = [
        'p1_id'=>$r['p1_id'],'p1_name'=>$r['p1_name'],'p1_emoji'=>$r['p1_emoji'],
        'p2_id'=>$r['p2_id'],'p2_name'=>$r['p2_name'],'p2_emoji'=>$r['p2_emoji'],
        'wins'=>0,'total'=>0
    ];
    $uniqueKey = $key.'_'.$sid;
    if (isset($seenSessions[$uniqueKey])) continue;
    $seenSessions[$uniqueKey] = true;
    $chemistry[$key]['total']++;
    $teamTotal = $pdo->prepare("SELECT SUM(score) FROM scores WHERE team_id=?");
    $teamTotal->execute([$r['team_id']]);
    $myTotal = (int)$teamTotal->fetchColumn();
    $maxTotal = $pdo->prepare("SELECT MAX(tot) FROM (SELECT SUM(score) AS tot FROM scores WHERE session_id=? GROUP BY team_id) sub");
    $maxTotal->execute([$sid]);
    if ($myTotal === (int)$maxTotal->fetchColumn()) $chemistry[$key]['wins']++;
}

// ── VITTORIE BREAKDOWN ───────────────────────
$qWins = $pdo->prepare("
    SELECT p.id, p.name, p.emoji,
        COUNT(DISTINCT sc.session_id) AS sessioni_totali,
        SUM(CASE WHEN (
            SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = sc.team_id
        ) = (
            SELECT MAX(tot) FROM (SELECT SUM(s3.score) AS tot FROM scores s3 WHERE s3.session_id=sc.session_id GROUP BY s3.team_id) mx
        ) THEN 1 ELSE 0 END) AS vittorie_squadra,
        SUM(CASE WHEN sc.score = (
            SELECT MAX(s4.score) FROM scores s4 WHERE s4.session_id=sc.session_id
        ) THEN 1 ELSE 0 END) AS vittorie_individuali
    FROM players p
    JOIN scores sc ON sc.player_id=p.id
    JOIN sessions se ON sc.session_id=se.id
    $dateWhere $gAfterDate
    GROUP BY p.id ORDER BY vittorie_squadra DESC
");
$qWins->execute($dateParams);
$winsBreakdown = $qWins->fetchAll();

$lastSession = $pdo->query('SELECT date, location FROM sessions ORDER BY date DESC LIMIT 1')->fetch();

// ── PIÙ VITTORIE ─────────────────────────────
$qMostWins = $pdo->prepare("
    SELECT p.id, p.name, p.emoji,
        COUNT(DISTINCT sc.session_id) AS vittorie
    FROM scores sc
    JOIN players p ON sc.player_id = p.id
    JOIN sessions se ON sc.session_id = se.id
    WHERE sc.team_id IS NOT NULL $gAnd
    AND (SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = sc.team_id AND s2.session_id = sc.session_id) = (
        SELECT MAX(team_tot) FROM (
            SELECT SUM(s3.score) AS team_tot FROM scores s3
            WHERE s3.session_id = sc.session_id AND s3.team_id IS NOT NULL
            GROUP BY s3.team_id
        ) mx
    )
    AND 1 = (
        SELECT COUNT(*) FROM (
            SELECT SUM(s4.score) AS team_tot FROM scores s4
            WHERE s4.session_id = sc.session_id AND s4.team_id IS NOT NULL
            GROUP BY s4.team_id
            HAVING SUM(s4.score) = (
                SELECT MAX(tt) FROM (
                    SELECT SUM(s5.score) AS tt FROM scores s5
                    WHERE s5.session_id = sc.session_id AND s5.team_id IS NOT NULL
                    GROUP BY s5.team_id
                ) mx2
            )
        ) winners
    )
    " . ($dateWhere ? str_replace('WHERE', 'AND', $dateWhere) : '') . "
    GROUP BY p.id
    ORDER BY vittorie DESC
    LIMIT 1
");
$qMostWins->execute($dateParams);
$mostWins = $qMostWins->fetch() ?: null;

// ── PIÙ MIGLIORATO ───────────────────────────
// Confronta media prima metà sessioni vs seconda metà (min 4 sessioni)
$qMostImproved = $pdo->prepare("
    SELECT p.id, p.name, p.emoji,
        ROUND(AVG(sc.score), 1) AS media_totale,
        COUNT(DISTINCT sc.session_id) AS serate
    FROM scores sc
    JOIN players p ON sc.player_id = p.id
    JOIN sessions se ON sc.session_id = se.id
    $dateWhere $gAfterDate
    GROUP BY p.id
    HAVING serate >= 2
    ORDER BY media_totale DESC
");
$qMostImproved->execute($dateParams);
$allForImproved = $qMostImproved->fetchAll();

$mostImproved = null;
$bestImprovement = 0;

foreach ($allForImproved as $pl) {
    // Prende le sessioni ordinate per data
    $qSess = $pdo->prepare("
        SELECT se.id, AVG(sc.score) AS media_sess
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        WHERE sc.player_id = ?
        GROUP BY se.id
        ORDER BY se.date ASC
    ");
    $qSess->execute([$pl['id']]);
    $sessions = $qSess->fetchAll();
    if (count($sessions) < 2) continue;

    // Prima metà vs seconda metà
    $half     = intdiv(count($sessions), 2);
    $firstHalf  = array_slice($sessions, 0, $half);
    $secondHalf = array_slice($sessions, $half);

    $avgFirst  = array_sum(array_column($firstHalf,  'media_sess')) / count($firstHalf);
    $avgSecond = array_sum(array_column($secondHalf, 'media_sess')) / count($secondHalf);
    $improvement = round($avgSecond - $avgFirst, 1);

    if ($improvement > $bestImprovement) {
        $bestImprovement = $improvement;
        $mostImproved = [
            'id'            => $pl['id'],
            'name'          => $pl['name'],
            'emoji'         => $pl['emoji'],
            'miglioramento' => $improvement,
            'serate'        => $pl['serate'],
        ];
    }
}

// ── PAYMENT TREND ────────────────────────────
// Per ogni giocatore: array {date, pagato, cumulativo} ordinato per data
$qPayTrend = $pdo->prepare("
    SELECT se.id, se.date, COALESCE(se.mode,'teams') AS mode, se.cost_per_game
    FROM sessions se
    WHERE se.cost_per_game IS NOT NULL AND se.cost_per_game > 0
    $payGroupAnd
    " . ($dateWhere ? str_replace('WHERE', 'AND', $dateWhere) : '') . "
    ORDER BY se.date ASC, se.id ASC
");
$qPayTrend->execute($dateParams);
$payTrendSessions = $qPayTrend->fetchAll();

$paymentTrend = []; // pid → [{date, pagato, cumulativo}]

foreach ($payTrendSessions as $sess) {
    $sid  = $sess['id'];
    $cost = floatval($sess['cost_per_game']);
    $mode = $sess['mode'];
    $date = $sess['date'];

    $qPG2 = $pdo->prepare('SELECT sc.player_id, sc.team_id, COUNT(*) AS num_games FROM scores sc WHERE sc.session_id=? GROUP BY sc.player_id, sc.team_id');
    $qPG2->execute([$sid]);
    $pg2 = $qPG2->fetchAll();

    $sessPayments = []; // pid → pagato in questa sessione

    if ($mode === 'ffa') {
        $qFFAPG2 = $pdo->prepare("SELECT sc.player_id, COUNT(*) AS ng, SUM(sc.score) AS ts FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND t.name='__FFA__' GROUP BY sc.player_id");
        $qFFAPG2->execute([$sid]);
        $ffaP2 = $qFFAPG2->fetchAll();
        $ptotals2 = [];
        foreach ($ffaP2 as $fp) $ptotals2[$fp['player_id']] = ['score'=>(int)$fp['ts'],'games'=>(int)$fp['ng']];
        if ($ptotals2) {
            $maxS2 = max(array_column($ptotals2,'score'));
            $winN2 = count(array_filter($ptotals2,fn($p)=>$p['score']===$maxS2));
            $nP2   = count($ptotals2);
            $wPid2 = array_key_first(array_filter($ptotals2,fn($p)=>$p['score']===$maxS2));
            $wBase2= $cost * ($ptotals2[$wPid2]['games']??1);
            $quota2= $nP2>1 ? $wBase2/($nP2-1) : 0;
            foreach ($ptotals2 as $pid=>$pt) {
                $base2 = $cost*$pt['games'];
                $sessPayments[$pid] = ($winN2===1&&$pt['score']===$maxS2) ? 0 : $base2+$quota2;
            }
        }
        // singoli in FFA
        foreach ($pg2 as $pg) {
            if ($pg['team_id']===null) {
                $sessPayments[$pg['player_id']] = ($sessPayments[$pg['player_id']]??0) + $cost*(int)$pg['num_games'];
            }
        }
    } else {
        $qTT2 = $pdo->prepare("SELECT t.id AS tid, SUM(sc.score) AS tot FROM scores sc JOIN teams t ON sc.team_id=t.id WHERE sc.session_id=? AND t.name!='__FFA__' GROUP BY t.id");
        $qTT2->execute([$sid]);
        $tt2 = [];
        foreach ($qTT2->fetchAll() as $t) $tt2[$t['tid']] = (int)$t['tot'];
        $maxT2   = $tt2 ? max($tt2) : 0;
        $winCnt2 = count(array_filter($tt2,fn($t)=>$t===$maxT2));
        $isDraw2 = $winCnt2>1;
        foreach ($pg2 as $pg) {
            $pid2=$pg['player_id']; $tid2=$pg['team_id']; $nG2=(int)$pg['num_games']; $base2=$cost*$nG2;
            if ($tid2===null) { $sessPayments[$pid2] = ($sessPayments[$pid2]??0)+$base2; }
            else {
                if ($isDraw2) $sessPayments[$pid2] = ($sessPayments[$pid2]??0)+$base2;
                elseif (($tt2[$tid2]??0)===$maxT2) $sessPayments[$pid2] = ($sessPayments[$pid2]??0)+0;
                else $sessPayments[$pid2] = ($sessPayments[$pid2]??0)+$base2*2;
            }
        }
    }

    foreach ($sessPayments as $pid=>$paid) {
        if (!isset($paymentTrend[$pid])) $paymentTrend[$pid] = [];
        $cumul = count($paymentTrend[$pid]) ? end($paymentTrend[$pid])['cumulativo'] : 0;
        $paymentTrend[$pid][] = ['date'=>$date, 'pagato'=>round($paid,2), 'cumulativo'=>round($cumul+$paid,2)];
    }
}

echo json_encode([
    'totale_sessioni' => (int)$totals['totale_sessioni'],
    'record_assoluto' => (int)($totals['record_assoluto'] ?? 0),
    'media_gruppo'    => (float)($totals['media_gruppo']  ?? 0),
    'ultima_sessione' => $lastSession,
    'record_holder'   => $recordHolder ?: null,
    'most_wins'       => $mostWins,
    'most_improved'   => $mostImproved,
    'leaderboard'     => $leaderboard,
    'trend'           => array_values($trend),
    'payment_trend'   => $paymentTrend,
    'distribution'    => $distribution,
    'h2h'             => array_values($h2h),
    'chemistry'       => array_values($chemistry),
    'wins_breakdown'  => $winsBreakdown,
]);