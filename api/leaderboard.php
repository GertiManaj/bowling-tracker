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
        COUNT(DISTINCT sc.session_id)          AS partite,
        COUNT(sc.id)                           AS game_totali,
        ROUND(AVG(sc.score), 1)                AS media,
        MAX(sc.score)                          AS record,
        MIN(sc.score)                          AS minimo
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

    // ── VITTORIE SQUADRA ──
    $qVitt = $pdo->prepare('
        SELECT COUNT(DISTINCT sc.session_id) AS vittorie
        FROM scores sc
        WHERE sc.player_id = ?
        AND sc.team_id IS NOT NULL
        AND (SELECT SUM(s2.score) FROM scores s2 WHERE s2.team_id = sc.team_id) = (
            SELECT MAX(team_tot) FROM (
                SELECT SUM(s3.score) AS team_tot
                FROM scores s3
                WHERE s3.session_id = sc.session_id
                AND s3.team_id IS NOT NULL
                GROUP BY s3.team_id
            ) t
        )
    ');
    $qVitt->execute([$id]);
    $player['vittorie_squadra'] = (int)$qVitt->fetchColumn();

    // ── SERATE CON SQUADRA (denominatore corretto per % vittorie) ──
    $qSS = $pdo->prepare('
        SELECT COUNT(DISTINCT session_id)
        FROM scores
        WHERE player_id = ? AND team_id IS NOT NULL
    ');
    $qSS->execute([$id]);
    $player['serate_con_squadra'] = (int)$qSS->fetchColumn();

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

    // ── ULTIMI 5 RISULTATI (V/P/N) ──
    // Prende le ultime 5 sessioni con squadra
    $qSess = $pdo->prepare('
        SELECT DISTINCT session_id
        FROM scores
        WHERE player_id = ? AND team_id IS NOT NULL
        ORDER BY session_id DESC
        LIMIT 5
    ');
    $qSess->execute([$id]);
    $lastSessions = $qSess->fetchAll(PDO::FETCH_COLUMN);

    $risultati = [];
    foreach ($lastSessions as $sessId) {
        // Trova il team_id del giocatore in questa sessione
        $qMyTeamId = $pdo->prepare('
            SELECT DISTINCT team_id
            FROM scores
            WHERE session_id = ? AND player_id = ? AND team_id IS NOT NULL
            LIMIT 1
        ');
        $qMyTeamId->execute([$sessId, $id]);
        $myTeamId = $qMyTeamId->fetchColumn();
        if (!$myTeamId) continue;

        // Totale del team del giocatore (somma tutti i game del team)
        $qMyTot = $pdo->prepare('
            SELECT SUM(score) AS tot
            FROM scores
            WHERE session_id = ? AND team_id = ?
        ');
        $qMyTot->execute([$sessId, $myTeamId]);
        $myTot = (int)$qMyTot->fetchColumn();

        // Massimo totale tra tutti i team nella sessione
        $qMaxTeam = $pdo->prepare('
            SELECT MAX(team_tot) FROM (
                SELECT team_id, SUM(score) AS team_tot
                FROM scores
                WHERE session_id = ? AND team_id IS NOT NULL
                GROUP BY team_id
            ) t
        ');
        $qMaxTeam->execute([$sessId]);
        $maxTeam = (int)$qMaxTeam->fetchColumn();

        // Conta quante squadre hanno raggiunto il massimo (pareggio se > 1)
        $qCountWin = $pdo->prepare('
            SELECT COUNT(*) FROM (
                SELECT team_id, SUM(score) AS team_tot
                FROM scores
                WHERE session_id = ? AND team_id IS NOT NULL
                GROUP BY team_id
                HAVING SUM(score) = ?
            ) w
        ');
        $qCountWin->execute([$sessId, $maxTeam]);
        $countWin = (int)$qCountWin->fetchColumn();

        if ($myTot === $maxTeam && $countWin > 1) {
            $risultati[] = 'N'; // Pareggio
        } elseif ($myTot === $maxTeam) {
            $risultati[] = 'V'; // Vittoria
        } else {
            $risultati[] = 'P'; // Sconfitta
        }
    }

    // Dal più vecchio al più recente
    $player['ultimi_risultati'] = array_reverse($risultati);
}

echo json_encode(array_values($players));