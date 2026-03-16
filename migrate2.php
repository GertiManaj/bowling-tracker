<?php
$secretKey = 'bowling2024';
if (!isset($_GET['key']) || $_GET['key'] !== $secretKey) {
    http_response_code(403);
    die('<h2 style="font-family:monospace;color:red">❌ Accesso negato</h2>');
}
require_once 'api/db.php';
header('Content-Type: text/html; charset=utf-8');
$pdo = getDB(); $success = []; $errors = [];

$queries = [

    "CREATE OR REPLACE VIEW scores_detail AS
        SELECT sc.id, se.date, se.location,
            p.name AS player_name, p.id AS player_id, p.emoji,
            t.name AS team_name, sc.score, sc.game_number
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        JOIN players  p  ON sc.player_id  = p.id
        LEFT JOIN teams t ON sc.team_id   = t.id
        ORDER BY se.date DESC, sc.game_number ASC, sc.score DESC",

    "CREATE OR REPLACE VIEW leaderboard AS
        SELECT
            p.id, p.name, p.nickname, p.emoji,
            COUNT(DISTINCT st.session_id)   AS partite,
            SUM(st.num_games)               AS game_totali,
            ROUND(AVG(st.session_total), 1) AS media,
            ROUND(AVG(st.avg_game), 1)      AS media_game,
            MAX(st.session_total)           AS record,
            MIN(st.session_total)           AS minimo
        FROM players p
        LEFT JOIN (
            SELECT player_id, session_id,
                SUM(score)          AS session_total,
                COUNT(*)            AS num_games,
                ROUND(AVG(score),1) AS avg_game
            FROM scores
            GROUP BY player_id, session_id
        ) st ON st.player_id = p.id
        GROUP BY p.id
        ORDER BY media DESC",
];

foreach ($queries as $sql) {
    try { $pdo->exec($sql); $success[] = substr(trim($sql),0,70).'...'; }
    catch (PDOException $e) { $errors[] = $e->getMessage(); }
}
?>
<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"/>
<title>Migrazione 2</title>
<style>body{font-family:monospace;background:#0a0a0f;color:#e8e8f0;padding:2rem}h1{color:#e8ff00}.ok{color:#e8ff00;margin:.3rem 0}.err{color:#ff3cac;margin:.3rem 0}.box{background:#11111a;border:1px solid #2a2a44;border-radius:8px;padding:1.5rem;margin-top:1rem}.warn{background:rgba(255,107,53,.15);border:1px solid rgba(255,107,53,.4);border-radius:6px;padding:1rem;margin-top:2rem;color:#ff6b35}</style>
</head><body>
<h1>🎳 Migrazione 2 — Aggiorna Viste</h1>
<div class="box"><h2 style="color:#00f5ff">Operazioni (<?=count($success)?>)</h2>
<?php foreach($success as $s): ?><p class="ok">✓ <?=htmlspecialchars($s)?></p><?php endforeach; ?></div>
<?php if($errors): ?><div class="box"><h2 style="color:#ff3cac">Errori (<?=count($errors)?>)</h2>
<?php foreach($errors as $e): ?><p class="err">✕ <?=htmlspecialchars($e)?></p><?php endforeach; ?></div><?php endif; ?>
<div class="warn">⚠️ Elimina dopo l'uso:<br><br>
<code>rm /Applications/XAMPP/xamppfiles/htdocs/bowling/migrate2.php && cd /Applications/XAMPP/xamppfiles/htdocs/bowling && git add . && git commit -m "rimuovi migrate2" && git push</code></div>
<?php if(!$errors): ?><p style="margin-top:2rem;color:#e8ff00;font-size:1.1rem">🎉 Viste aggiornate!</p><?php endif; ?>
</body></html>