<?php
// ============================================
//  api/stats.php — statistiche complete
//  ?from=YYYY-MM-DD&to=YYYY-MM-DD
// ============================================
require_once 'db.php';
$pdo = getDB();

$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;

$dateWhere  = '';
$dateParams = [];
if ($from && $to)  { $dateWhere = 'WHERE se.date BETWEEN ? AND ?'; $dateParams = [$from, $to]; }
elseif ($from)     { $dateWhere = 'WHERE se.date >= ?';            $dateParams = [$from]; }
elseif ($to)       { $dateWhere = 'WHERE se.date <= ?';            $dateParams = [$to]; }

// ── TOTALI ───────────────────────────────────
$q = $pdo->prepare("
    SELECT
        MAX(sc.score)            AS record_assoluto,
        ROUND(AVG(sc.score),1)   AS media_gruppo
    FROM scores sc
    JOIN sessions se ON sc.session_id = se.id
    $dateWhere
");
$q->execute($dateParams);
$totals = $q->fetch();

$qSess = $pdo->prepare("SELECT COUNT(*) FROM sessions se " . ($dateWhere ?: ''));
$qSess->execute($dateParams);
$totals['totale_sessioni'] = $qSess->fetchColumn();

// Record holder — basato sul singolo game
$qRec = $pdo->prepare("
    SELECT p.name, p.emoji, sc.score, se.date
    FROM scores sc
    JOIN players  p  ON sc.player_id  = p.id
    JOIN sessions se ON sc.session_id = se.id
    $dateWhere
    ORDER BY sc.score DESC LIMIT 1
");
$qRec->execute($dateParams);
$recordHolder = $qRec->fetch();

// ── CLASSIFICA COMPLETA ──────────────────────
// Tutte le metriche in una query + vittorie squadra
$qLb = $pdo->prepare("
    SELECT p.id, p.name, p.emoji, p.nickname,
           COUNT(DISTINCT sc.session_id)  AS partite,
           COUNT(sc.id)                   AS game_totali,
           ROUND(AVG(sc.score),1)         AS media,
           MAX(sc.score)                  AS record,
           MIN(sc.score)                  AS minimo
    FROM players p
    LEFT JOIN scores sc   ON sc.player_id  = p.id
    LEFT JOIN sessions se ON sc.session_id = se.id
    $dateWhere
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
    WHERE se.date >= ?
    GROUP BY p.id
");
$qRecent->execute([$threeMonthsAgo]);
$recentMap = [];
foreach ($qRecent->fetchAll() as $r) {
    $recentMap[$r['id']] = $r['media_recente'];
}

// ── ULTIMI RISULTATI V/P/N ──────────────────
// Per ogni giocatore: ultime 5 sessioni con team, calcola V/P/N
$qUltimi = $pdo->prepare("
    SELECT sc.player_id, sc.session_id, sc.team_id, se.date
    FROM scores sc
    JOIN sessions se ON sc.session_id = se.id
    WHERE sc.team_id IS NOT NULL
    ORDER BY sc.player_id, sc.session_id DESC
");
$qUltimi->execute();
$ultimiRaw = $qUltimi->fetchAll();

// Precalcola totale per ogni team in ogni sessione
$teamTotalCache = [];
$qTeamTotals = $pdo->query("
    SELECT team_id, session_id, SUM(score) AS tot
    FROM scores
    WHERE team_id IS NOT NULL
    GROUP BY team_id, session_id
");
foreach ($qTeamTotals->fetchAll() as $tt) {
    $teamTotalCache[$tt['session_id']][$tt['team_id']] = (int)$tt['tot'];
}

$playerResults = [];
foreach ($ultimiRaw as $r) {
    $pid = $r['player_id'];
    $sid = $r['session_id'];
    $tid = $r['team_id'];
    if (!isset($playerResults[$pid])) $playerResults[$pid] = [];
    if (count($playerResults[$pid]) >= 5) continue;
    // Evita duplicati per stessa sessione
    $alreadyDone = false;
    foreach ($playerResults[$pid] as $existing) {
        if (isset($existing['sid']) && $existing['sid'] === $sid) { $alreadyDone = true; break; }
    }
    if ($alreadyDone) continue;

    $teamTotals = $teamTotalCache[$sid] ?? [];
    if (empty($teamTotals) || !isset($teamTotals[$tid])) continue;
    $myTotal  = $teamTotals[$tid];
    $maxTotal = max($teamTotals);

    if ($myTotal === $maxTotal && count(array_unique($teamTotals)) > 1) $esito = 'V';
    elseif ($myTotal === $maxTotal && count(array_unique($teamTotals)) === 1) $esito = 'N';
    else $esito = 'P';

    $playerResults[$pid][] = ['sid' => $sid, 'esito' => $esito];
}

$playerTeamTotals = [];
foreach ($playerResults as $pid => $results) {
    $playerTeamTotals[$pid] = array_column($results, 'esito');
}

// Precalcola vittorie_squadra, pareggi e serate_con_squadra per ogni giocatore
// Usa il $teamTotalCache già calcolato sopra per evitare query ridondanti
$vittorieMap      = [];
$pareggiMap       = [];
$serateSquadraMap = [];

foreach ($leaderboard as $pl) {
    $pid = $pl['id'];
    $params = array_merge([$pid], $dateParams);

    // Serate con squadra (filtrate per periodo)
    $qSCS = $pdo->prepare("
        SELECT DISTINCT sc.session_id, sc.team_id
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        WHERE sc.player_id = ? AND sc.team_id IS NOT NULL
        " . ($dateWhere ? str_replace('WHERE', 'AND', $dateWhere) : '') . "
    ");
    $qSCS->execute($params);
    $sessRows = $qSCS->fetchAll();

    // Deduplicazione: una riga per sessione (prende il primo team_id)
    $sessMap = [];
    foreach ($sessRows as $sr) {
        $sid = $sr['session_id'];
        if (!isset($sessMap[$sid])) $sessMap[$sid] = $sr['team_id'];
    }

    $vittorie = 0;
    $pareggi  = 0;

    foreach ($sessMap as $sid => $tid) {
        // Usa il cache già calcolato se disponibile
        $teamTotals = $teamTotalCache[$sid] ?? [];

        if (empty($teamTotals)) {
            // Fallback: ricalcola
            $qTT = $pdo->prepare('SELECT team_id, SUM(score) AS tot FROM scores WHERE session_id = ? AND team_id IS NOT NULL GROUP BY team_id');
            $qTT->execute([$sid]);
            foreach ($qTT->fetchAll() as $t) $teamTotals[$t['team_id']] = (int)$t['tot'];
        }

        if (empty($teamTotals) || !isset($teamTotals[$tid])) continue;

        $myTot  = (int)$teamTotals[$tid];
        $maxTot = max($teamTotals);
        $winCnt = count(array_filter($teamTotals, fn($t) => (int)$t === $maxTot));

        if ($myTot === $maxTot && $winCnt > 1) $pareggi++;
        elseif ($myTot === $maxTot)             $vittorie++;
    }

    $vittorieMap[$pid]      = $vittorie;
    $pareggiMap[$pid]       = $pareggi;
    $serateSquadraMap[$pid] = count($sessMap);
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

// ── TREND ────────────────────────────────────
$qTr = $pdo->prepare("
    SELECT p.id AS player_id, p.name, p.emoji, se.date, sc.score
    FROM scores sc JOIN players p ON sc.player_id=p.id JOIN sessions se ON sc.session_id=se.id
    $dateWhere ORDER BY p.id, se.date ASC
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
    $dateWhere
    GROUP BY p.id HAVING COUNT(sc.id) > 0 ORDER BY p.name
");
$qDist->execute($dateParams);
$distribution = $qDist->fetchAll();

// ── TESTA A TESTA ────────────────────────────
$qH2h = $pdo->prepare("
    SELECT a.player_id AS p1_id, pa.name AS p1_name, pa.emoji AS p1_emoji, a.score AS p1_score,
           b.player_id AS p2_id, pb.name AS p2_name, pb.emoji AS p2_emoji, b.score AS p2_score
    FROM scores a
    JOIN scores b    ON a.session_id=b.session_id AND a.player_id < b.player_id
    JOIN players pa  ON a.player_id=pa.id
    JOIN players pb  ON b.player_id=pb.id
    JOIN sessions se ON a.session_id=se.id
    $dateWhere
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
    $dateWhere
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
    $dateWhere
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
    WHERE sc.team_id IS NOT NULL
    AND (SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = sc.team_id) = (
        SELECT MAX(team_tot) FROM (
            SELECT SUM(s3.score) AS team_tot FROM scores s3
            WHERE s3.session_id = sc.session_id AND s3.team_id IS NOT NULL
            GROUP BY s3.team_id
        ) mx
    )
    $dateWhere
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
    $dateWhere
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
    'distribution'    => $distribution,
    'h2h'             => array_values($h2h),
    'chemistry'       => array_values($chemistry),
    'wins_breakdown'  => $winsBreakdown,
]);