<?php
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

    // ── MIGRATION 004: tabella admins per OTP ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            email         VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            name          VARCHAR(100) NOT NULL,
            active        TINYINT(1) NOT NULL DEFAULT 1,
            last_login    DATETIME DEFAULT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── MIGRATION 005: tabella otp_codes ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS otp_codes (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            admin_id    INT NOT NULL,
            code        VARCHAR(10) NOT NULL,
            expires_at  DATETIME NOT NULL,
            used        TINYINT(1) NOT NULL DEFAULT 0,
            used_at     DATETIME DEFAULT NULL,
            ip_address  VARCHAR(45) DEFAULT NULL,
            user_agent  TEXT DEFAULT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
            INDEX idx_admin_code (admin_id, code),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── MIGRATION 006: tabella login_logs ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_logs (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            admin_id       INT DEFAULT NULL,
            email          VARCHAR(255) NOT NULL,
            success        TINYINT(1) NOT NULL,
            failure_reason VARCHAR(255) DEFAULT NULL,
            ip_address     VARCHAR(45) DEFAULT NULL,
            user_agent     TEXT DEFAULT NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
            INDEX idx_admin (admin_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── MIGRATION 007: tabella password_resets ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            admin_id    INT NOT NULL,
            token       VARCHAR(64) NOT NULL UNIQUE,
            expires_at  DATETIME NOT NULL,
            used        TINYINT(1) NOT NULL DEFAULT 0,
            used_at     DATETIME DEFAULT NULL,
            ip_address  VARCHAR(45) DEFAULT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
            INDEX idx_token (token),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── MIGRATION 008: trusted_devices ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trusted_devices (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            admin_id          INT NOT NULL,
            token_hash        VARCHAR(64) NOT NULL UNIQUE,
            device_identifier VARCHAR(500) DEFAULT NULL,
            user_agent        TEXT DEFAULT NULL,
            ip_address        VARCHAR(45) DEFAULT NULL,
            created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at        TIMESTAMP NOT NULL,
            last_used_at      TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_admin  (admin_id),
            INDEX idx_token  (token_hash),
            INDEX idx_expires (expires_at),
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Cleanup scaduti silenzioso
    try { $pdo->exec("DELETE FROM trusted_devices WHERE expires_at < NOW()"); } catch (Exception $e) {}

    // ── MIGRATION 009: cleanup OTP in chiaro (pre-hashing) ──
    // Rimuove codici a 6 cifre salvati prima dell'introduzione dell'hash SHA-256
    try { $pdo->exec("DELETE FROM otp_codes WHERE LENGTH(code) <= 6"); } catch (Exception $e) {}

    // ── CREA ADMIN DI DEFAULT SE NON ESISTE ──
    try {
        $checkAdmin = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        
        if ($checkAdmin == 0) {
            $defaultEmail = 'admin@strikezone.com';
            $defaultPassword = 'StrikeZone2024!';
            $defaultPasswordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
            
            $pdo->prepare("
                INSERT INTO admins (email, password_hash, name, active)
                VALUES (?, ?, ?, 1)
            ")->execute([$defaultEmail, $defaultPasswordHash, 'Admin']);
            
            error_log("✅ Admin creato: $defaultEmail / $defaultPassword");
        }
    } catch (Exception $e) {
        error_log("Errore creazione admin: " . $e->getMessage());
    }
}
