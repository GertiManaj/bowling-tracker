<?php
function runMigrations(PDO $pdo) {

    // ── MIGRATION 001: cost_per_game in sessions ──
    try { $pdo->query('SELECT cost_per_game FROM sessions LIMIT 1'); }
    catch (Exception $e) { $pdo->exec('ALTER TABLE sessions ADD COLUMN cost_per_game DECIMAL(6,2) DEFAULT NULL'); }

    // ── MIGRATION 002: mode in sessions (teams|ffa) ──
    try { $pdo->query('SELECT mode FROM sessions LIMIT 1'); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE sessions ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'teams'"); }

    // ── MIGRATION 003: tabella tickets ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tickets (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            type        ENUM('bug','feature','correction') NOT NULL DEFAULT 'bug',
            name        VARCHAR(100) DEFAULT NULL,
            description TEXT NOT NULL,
            status      ENUM('open','in_progress','resolved','rejected') NOT NULL DEFAULT 'open',
            reply       TEXT DEFAULT NULL,
            replied_at  DATETIME DEFAULT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
