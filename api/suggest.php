<?php
// ============================================
//  api/suggest.php
//  POST → suggerisce squadre equilibrate
//  Body: { "player_ids": [1,2,3,4,5,6] }
// ============================================
require_once 'db.php';
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito']);
    exit;
}

$data       = json_decode(file_get_contents('php://input'), true);
$playerIds  = array_map('intval', $data['player_ids'] ?? []);
$livelli    = $data['livelli'] ?? []; // [player_id => score_stimato] per nuovi giocatori

if (count($playerIds) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'Seleziona almeno 2 giocatori']);
    exit;
}

// ── DATI GIOCATORI ───────────────────────────
$placeholders = implode(',', array_fill(0, count($playerIds), '?'));

// Media storica per sessione + conteggio partite
$qMedia = $pdo->prepare("
    SELECT p.id, p.name, p.emoji,
        COALESCE(ROUND(AVG(st.totale), 1), 0) AS media_storica,
        COUNT(DISTINCT st.session_id) AS num_partite
    FROM players p
    LEFT JOIN (
        SELECT player_id, session_id, SUM(score) AS totale
        FROM scores GROUP BY player_id, session_id
    ) st ON st.player_id = p.id
    WHERE p.id IN ($placeholders)
    GROUP BY p.id
");
$qMedia->execute($playerIds);
$players = $qMedia->fetchAll();

// Forma recente (ultimi 3 mesi)
$threeMonths = date('Y-m-d', strtotime('-3 months'));
$qForma = $pdo->prepare("
    SELECT p.id,
        COALESCE(ROUND(AVG(st.totale), 1), 0) AS media_recente
    FROM players p
    LEFT JOIN (
        SELECT sc.player_id, sc.session_id, SUM(sc.score) AS totale
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        WHERE se.date >= ?
        GROUP BY sc.player_id, sc.session_id
    ) st ON st.player_id = p.id
    WHERE p.id IN ($placeholders)
    GROUP BY p.id
");
$qForma->execute(array_merge([$threeMonths], $playerIds));
$formaRows = $qForma->fetchAll();
$formaMap  = array_column($formaRows, 'media_recente', 'id');

// Chimica: % vittorie per ogni coppia
$qChem = $pdo->prepare("
    SELECT a.player_id AS p1, b.player_id AS p2,
        COUNT(DISTINCT a.session_id) AS partite_insieme,
        SUM(CASE WHEN (
            SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = a.team_id
        ) = (
            SELECT MAX(tot) FROM (SELECT SUM(s3.score) AS tot FROM scores s3
                WHERE s3.session_id = a.session_id GROUP BY s3.team_id) mx
        ) THEN 1 ELSE 0 END) AS vittorie_insieme
    FROM scores a
    JOIN scores b ON a.session_id = b.session_id
        AND a.team_id = b.team_id
        AND a.player_id < b.player_id
    WHERE a.player_id IN ($placeholders)
    AND b.player_id IN ($placeholders)
    GROUP BY a.player_id, b.player_id
");
$qChem->execute(array_merge($playerIds, $playerIds));
$chemRows = $qChem->fetchAll();

// Mappa chimica: [p1_id][p2_id] = win_pct
$chemMap = [];
foreach ($chemRows as $c) {
    $pct = $c['partite_insieme'] > 0
        ? round($c['vittorie_insieme'] / $c['partite_insieme'] * 100)
        : 50;
    $chemMap[$c['p1']][$c['p2']] = $pct;
    $chemMap[$c['p2']][$c['p1']] = $pct;
}

// ── CALCOLO PUNTEGGIO GIOCATORE ──────────────
// Score = 60% media storica + 40% forma recente
// Per nuovi giocatori usa il livello manuale se fornito
$playerScores = [];
foreach ($players as $p) {
    $pid      = $p['id'];
    $storica  = (float)$p['media_storica'];
    $nPartite = (int)$p['num_partite'];
    $recente  = (float)($formaMap[$pid] ?? $storica);

    // Se il giocatore non ha partite e ha un livello manuale, usalo
    $livelloManuale = isset($livelli[$pid]) ? (float)$livelli[$pid] : null;
    if ($nPartite === 0 && $livelloManuale !== null) {
        $storica = $livelloManuale;
        $recente = $livelloManuale;
    }

    $score = $storica > 0
        ? round($storica * 0.6 + $recente * 0.4, 1)
        : 0;

    $playerScores[$pid] = [
        'id'              => $pid,
        'name'            => $p['name'],
        'emoji'           => $p['emoji'],
        'media_storica'   => $nPartite > 0 ? $storica : null,
        'media_recente'   => $recente > 0 && $nPartite > 0 ? $recente : null,
        'score'           => $score,
        'num_partite'     => $nPartite,
        'livello_manuale' => $nPartite === 0 ? $livelloManuale : null,
    ];
}

// ── GENERA TUTTE LE COMBINAZIONI ─────────────
// Divide i giocatori in 2 squadre il più equilibrate possibile

$n     = count($playerIds);
$half  = intdiv($n, 2);
$best  = null;
$bestDiff = PHP_FLOAT_MAX;

// Genera tutte le combinazioni di metà giocatori per squadra A
function combinations($arr, $k) {
    if ($k === 0) return [[]];
    if (empty($arr)) return [];
    $first = array_shift($arr);
    $withFirst    = array_map(fn($c) => array_merge([$first], $c), combinations($arr, $k-1));
    $withoutFirst = combinations($arr, $k);
    return array_merge($withFirst, $withoutFirst);
}

$combos = combinations($playerIds, $half);

foreach ($combos as $teamA_ids) {
    $teamB_ids = array_values(array_diff($playerIds, $teamA_ids));

    // Punteggio medio squadra
    $scoreA = array_sum(array_map(fn($id) => $playerScores[$id]['score'], $teamA_ids)) / count($teamA_ids);
    $scoreB = array_sum(array_map(fn($id) => $playerScores[$id]['score'], $teamB_ids)) / count($teamB_ids);
    $diff   = abs($scoreA - $scoreB);

    // Bonus chimica: se una squadra ha coppie con alta win%,
    // penalizza leggermente per favorire squadre più equilibrate
    $chemBonusA = 0;
    $chemBonusB = 0;
    for ($i = 0; $i < count($teamA_ids); $i++) {
        for ($j = $i+1; $j < count($teamA_ids); $j++) {
            $p1 = $teamA_ids[$i]; $p2 = $teamA_ids[$j];
            $chemBonusA += ($chemMap[$p1][$p2] ?? 50) - 50;
        }
    }
    for ($i = 0; $i < count($teamB_ids); $i++) {
        for ($j = $i+1; $j < count($teamB_ids); $j++) {
            $p1 = $teamB_ids[$i]; $p2 = $teamB_ids[$j];
            $chemBonusB += ($chemMap[$p1][$p2] ?? 50) - 50;
        }
    }
    // Penalità chimica sbilanciata
    $chemPenalty = abs($chemBonusA - $chemBonusB) * 0.3;
    $totalDiff   = $diff + $chemPenalty;

    if ($totalDiff < $bestDiff) {
        $bestDiff = $totalDiff;
        $best = [
            'teamA' => $teamA_ids,
            'teamB' => $teamB_ids,
            'scoreA' => round($scoreA, 1),
            'scoreB' => round($scoreB, 1),
            'diff'   => round($diff, 1),
        ];
    }
}

// Costruisci risposta con dati completi giocatori
$result = [
    'teamA' => array_map(fn($id) => $playerScores[$id], $best['teamA']),
    'teamB' => array_map(fn($id) => $playerScores[$id], $best['teamB']),
    'scoreA' => $best['scoreA'],
    'scoreB' => $best['scoreB'],
    'diff'   => $best['diff'],
    'balanced' => $best['diff'] < 20,
];

// Aggiungi chimica per ogni squadra
foreach (['teamA', 'teamB'] as $team) {
    $ids  = array_column($result[$team], 'id');
    $pairs = [];
    for ($i = 0; $i < count($ids); $i++) {
        for ($j = $i+1; $j < count($ids); $j++) {
            $p1 = $ids[$i]; $p2 = $ids[$j];
            $pct = $chemMap[$p1][$p2] ?? null;
            if ($pct !== null) {
                $pairs[] = [
                    'p1' => $playerScores[$p1]['name'],
                    'p2' => $playerScores[$p2]['name'],
                    'pct' => $pct,
                ];
            }
        }
    }
    $result[$team . '_chemistry'] = $pairs;
}

echo json_encode($result);