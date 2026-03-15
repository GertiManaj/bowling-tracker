<?php
// ============================================
//  setup.php — Inizializzazione database
//  IMPORTANTE: elimina questo file dopo l'uso!
//  Aprilo una volta sola su Railway poi cancellalo.
// ============================================

// Sicurezza base — chiave segreta nell'URL
// Aprire come: https://tuosito.railway.app/setup.php?key=bowling2024
$secretKey = 'bowling2024';
if (!isset($_GET['key']) || $_GET['key'] !== $secretKey) {
    http_response_code(403);
    die('<h2 style="font-family:monospace;color:red">❌ Accesso negato. Aggiungi ?key=bowling2024 all\'URL</h2>');
}

require_once 'api/db.php';

// Disabilita header JSON per questa pagina
header('Content-Type: text/html; charset=utf-8');

$pdo = getDB();
$errors   = [];
$success  = [];

$queries = [

    // Tabelle
    "CREATE TABLE IF NOT EXISTS players (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL,
        nickname   VARCHAR(100) DEFAULT '',
        emoji      VARCHAR(10)  DEFAULT '🎳',
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS sessions (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        date       DATE         NOT NULL,
        location   VARCHAR(150) DEFAULT 'Bowling',
        notes      TEXT,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS teams (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        name       VARCHAR(100) NOT NULL,
        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS scores (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        player_id  INT NOT NULL,
        team_id    INT,
        score      INT NOT NULL,
        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (player_id)  REFERENCES players(id)  ON DELETE CASCADE,
        FOREIGN KEY (team_id)    REFERENCES teams(id)    ON DELETE SET NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

    // Viste
    "CREATE OR REPLACE VIEW leaderboard AS
        SELECT p.id, p.name, p.nickname, p.emoji,
            COUNT(s.id) AS partite,
            ROUND(AVG(s.score),1) AS media,
            MAX(s.score) AS record,
            MIN(s.score) AS minimo
        FROM players p
        LEFT JOIN scores s ON s.player_id = p.id
        GROUP BY p.id ORDER BY media DESC",

    "CREATE OR REPLACE VIEW scores_detail AS
        SELECT sc.id, se.date, se.location,
            p.name AS player_name, p.id AS player_id, p.emoji,
            t.name AS team_name, sc.score
        FROM scores sc
        JOIN sessions se ON sc.session_id = se.id
        JOIN players  p  ON sc.player_id  = p.id
        LEFT JOIN teams t ON sc.team_id   = t.id
        ORDER BY se.date DESC, sc.score DESC",

    // Giocatori reali
    "INSERT IGNORE INTO players (id, name, nickname, emoji) VALUES
        (1, 'Mana',  '', '🐺'),
        (2, 'Nico',  '', '🦊'),
        (3, 'Dega',  '', '🐻'),
        (4, 'Mazza', '', '🦁'),
        (5, 'Sammy', '', '🐯'),
        (6, 'Willy', '', '🦋'),
        (7, 'Samu',  '', '🐸'),
        (8, 'Seme',  '', '🦅'),
        (9, 'Zotti', '', '🐉')",
];

foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        $preview = substr(trim($sql), 0, 60);
        $success[] = $preview . '...';
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8"/>
  <title>Setup — Strike Zone</title>
  <style>
    body { font-family: monospace; background: #0a0a0f; color: #e8e8f0; padding: 2rem; }
    h1   { color: #e8ff00; }
    h2   { color: #00f5ff; margin-top: 2rem; }
    .ok  { color: #e8ff00; margin: 0.3rem 0; }
    .err { color: #ff3cac; margin: 0.3rem 0; }
    .box { background: #11111a; border: 1px solid #2a2a44; border-radius: 8px; padding: 1.5rem; margin-top: 1rem; }
    .warn { background: rgba(255,107,53,0.15); border: 1px solid rgba(255,107,53,0.4); border-radius: 6px; padding: 1rem; margin-top: 2rem; color: #ff6b35; }
  </style>
</head>
<body>
  <h1>🎳 Strike Zone — Setup Database</h1>

  <div class="box">
    <h2>✅ Operazioni completate (<?= count($success) ?>)</h2>
    <?php foreach ($success as $s): ?>
      <p class="ok">✓ <?= htmlspecialchars($s) ?></p>
    <?php endforeach; ?>
  </div>

  <?php if ($errors): ?>
  <div class="box">
    <h2>❌ Errori (<?= count($errors) ?>)</h2>
    <?php foreach ($errors as $e): ?>
      <p class="err">✕ <?= htmlspecialchars($e) ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="warn">
    ⚠️ <strong>IMPORTANTE:</strong> ora che il setup è completato,
    elimina questo file dal progetto per sicurezza!<br><br>
    Nel Terminale esegui:<br>
    <code style="background:#0a0a0f;padding:0.3rem 0.6rem;border-radius:4px">
      rm /Applications/XAMPP/xamppfiles/htdocs/bowling/setup.php && cd /Applications/XAMPP/xamppfiles/htdocs/bowling && git add . && git commit -m "rimuovi setup.php" && git push
    </code>
  </div>

  <?php if (!$errors): ?>
  <p style="margin-top:2rem;color:#e8ff00;font-size:1.1rem">
    🎉 Database inizializzato con successo! Puoi aprire il sito.
  </p>
  <?php endif; ?>

</body>
</html>