<?php
// ============================================
//  api/suggest.php
//  POST → suggerisce squadre equilibrate
//  Body: { "player_ids": [1,2,3,4,5,6] }
// ============================================
require_once __DIR__ . '/config.php';
$pdo = getPDO();

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

// Media storica per singolo game + conteggio partite
$qMedia = $pdo->prepare("
    SELECT p.id, p.name, p.emoji,
        COALESCE(ROUND(AVG(sc.score), 1), 0) AS media_storica,
        COUNT(DISTINCT sc.session_id) AS num_partite
    FROM players p
    LEFT JOIN scores sc ON sc.player_id = p.id
    WHERE p.id IN ($placeholders)
    GROUP BY p.id
");
$qMedia->execute($playerIds);
$players = $qMedia->fetchAll();

// Forma recente (ultimi 3 mesi) - media singolo game
$threeMonths = date('Y-m-d', strtotime('-3 months'));
$qForma = $pdo->prepare("
    SELECT p.id,
        COALESCE(ROUND(AVG(sc.score), 1), 0) AS media_recente
    FROM players p
    LEFT JOIN scores sc ON sc.player_id = p.id
    LEFT JOIN sessions se ON sc.session_id = se.id
    WHERE p.id IN ($placeholders)
    AND (se.date >= ? OR se.date IS NULL)
    GROUP BY p.id
");
$qForma->execute(array_merge($playerIds, [$threeMonths]));
$formaRows = $qForma->fetchAll();
$formaMap  = array_column($formaRows, 'media_recente', 'id');

// Chimica: % vittorie per ogni coppia (basata su sessioni distinte)
$qChem = $pdo->prepare("
    SELECT a.player_id AS p1, b.player_id AS p2,
        COUNT(DISTINCT a.session_id) AS partite_insieme
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

// Per ogni coppia, calcola vittorie contando sessioni uniche
$chemMap = [];
foreach ($chemRows as $c) {
    $p1 = $c['p1']; $p2 = $c['p2'];
    $totSess = (int)$c['partite_insieme'];

    // Conta sessioni vinte insieme
    $qWin = $pdo->prepare("
        SELECT COUNT(DISTINCT a.session_id) AS vinte
        FROM scores a
        JOIN scores b ON a.session_id = b.session_id
            AND a.team_id = b.team_id
            AND b.player_id = ?
        WHERE a.player_id = ?
        AND (
            SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = a.team_id
        ) = (
            SELECT MAX(team_tot) FROM (
                SELECT SUM(s3.score) AS team_tot FROM scores s3
                WHERE s3.session_id = a.session_id
                AND s3.team_id IS NOT NULL
                GROUP BY s3.team_id
            ) mx
        )
    ");
    $qWin->execute([$p2, $p1]);
    $vinte = (int)$qWin->fetchColumn();

    $pct = $totSess > 0 ? round($vinte / $totSess * 100) : 50;
    $chemMap[$p1][$p2] = $pct;
    $chemMap[$p2][$p1] = $pct;
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
// ── GENERA TUTTE LE COMBINAZIONI ─────────────
$n    = count($playerIds);
$half = intdiv($n, 2);

function combinations($arr, $k) {
    if ($k === 0) return [[]];
    if (empty($arr)) return [];
    $first = array_shift($arr);
    $withFirst    = array_map(fn($c) => array_merge([$first], $c), combinations($arr, $k-1));
    $withoutFirst = combinations($arr, $k);
    return array_merge($withFirst, $withoutFirst);
}

$combos    = combinations($playerIds, $half);
$allCombos = [];

foreach ($combos as $teamA_ids) {
    $teamB_ids = array_values(array_diff($playerIds, $teamA_ids));
    $scoreA = array_sum(array_map(fn($id) => $playerScores[$id]['score'], $teamA_ids)) / count($teamA_ids);
    $scoreB = array_sum(array_map(fn($id) => $playerScores[$id]['score'], $teamB_ids)) / count($teamB_ids);
    $diff   = abs($scoreA - $scoreB);

    $chemBonusA = 0; $chemBonusB = 0;
    for ($i = 0; $i < count($teamA_ids); $i++)
        for ($j = $i+1; $j < count($teamA_ids); $j++)
            $chemBonusA += ($chemMap[$teamA_ids[$i]][$teamA_ids[$j]] ?? 50) - 50;
    for ($i = 0; $i < count($teamB_ids); $i++)
        for ($j = $i+1; $j < count($teamB_ids); $j++)
            $chemBonusB += ($chemMap[$teamB_ids[$i]][$teamB_ids[$j]] ?? 50) - 50;

    $allCombos[] = [
        'teamA'  => $teamA_ids,
        'teamB'  => $teamB_ids,
        'scoreA' => round($scoreA, 1),
        'scoreB' => round($scoreB, 1),
        'diff'   => round($diff, 1),
        'total'  => $diff + abs($chemBonusA - $chemBonusB) * 0.3,
    ];
}

// Ordina per equilibrio
usort($allCombos, fn($a, $b) => $a['total'] <=> $b['total']);

// Deduplica: normalizza teamA sempre in ordine crescente per confronto
$seen = [];
$unique = [];
foreach ($allCombos as $c) {
    $key = implode(',', array_merge(array_unique(array_merge(
        array_map('strval', $c['teamA']),
        array_map('strval', $c['teamB'])
    ))));
    $normA = $c['teamA']; sort($normA);
    $normKey = implode(',', $normA);
    if (!in_array($normKey, $seen)) {
        $seen[] = $normKey;
        $unique[] = $c;
    }
}

// Prende top N e seleziona 2 diverse con casualità pesata
$topN = min(6, count($unique));
$pool = array_slice($unique, 0, $topN);
$weights = array_map(fn($i) => $topN - $i, range(0, count($pool)-1));
$totalW  = array_sum($weights);

function weightedPickPhp($pool, $weights, $totalW, $excludeKey = null) {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $rand = mt_rand(1, $totalW); $cum = 0;
        foreach ($pool as $i => $combo) {
            $cum += $weights[$i];
            if ($rand <= $cum) {
                $norm = $combo['teamA']; sort($norm);
                $key  = implode(',', $norm);
                if ($excludeKey === null || $key !== $excludeKey) return [$combo, $key];
                break;
            }
        }
    }
    // fallback: prima diversa
    foreach ($pool as $combo) {
        $norm = $combo['teamA']; sort($norm);
        $key  = implode(',', $norm);
        if ($excludeKey === null || $key !== $excludeKey) return [$combo, $key];
    }
    return [$pool[0], ''];
}

[$p1, $key1] = weightedPickPhp($pool, $weights, $totalW);
[$p2, ]      = weightedPickPhp($pool, $weights, $totalW, $key1);

function buildProposal($best, $playerScores, $chemMap) {
    $result = [
        'teamA'   => array_map(fn($id) => $playerScores[$id], $best['teamA']),
        'teamB'   => array_map(fn($id) => $playerScores[$id], $best['teamB']),
        'scoreA'  => $best['scoreA'],
        'scoreB'  => $best['scoreB'],
        'diff'    => $best['diff'],
        'balanced'=> $best['diff'] < 20,
    ];
    foreach (['teamA','teamB'] as $team) {
        $ids = array_column($result[$team], 'id'); $pairs = [];
        for ($i = 0; $i < count($ids); $i++)
            for ($j = $i+1; $j < count($ids); $j++) {
                $pct = $chemMap[$ids[$i]][$ids[$j]] ?? null;
                if ($pct !== null)
                    $pairs[] = ['p1'=>$playerScores[$ids[$i]]['name'],'p2'=>$playerScores[$ids[$j]]['name'],'pct'=>$pct];
            }
        $result[$team.'_chemistry'] = $pairs;
    }
    return $result;
}

echo json_encode([
    'proposal1' => buildProposal($p1, $playerScores, $chemMap),
    'proposal2' => buildProposal($p2, $playerScores, $chemMap),
]);