<?php
// ============================================
//  api/migration.php
//  Esegue automaticamente le migration mancanti
//  Chiamato da db.php ad ogni connessione (veloce, no-op se già fatto)
// ============================================

function runMigrations(PDO $pdo) {

    // ── MIGRATION 001: cost_per_game in sessions ──
    try {
        $pdo->query('SELECT cost_per_game FROM sessions LIMIT 1');
    } catch (Exception $e) {
        $pdo->exec('ALTER TABLE sessions ADD COLUMN cost_per_game DECIMAL(6,2) DEFAULT NULL');
    }

    // ── MIGRATION 002: mode in sessions (teams|ffa) ──
    try {
        $pdo->query('SELECT mode FROM sessions LIMIT 1');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE sessions ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'teams'");
    }

}