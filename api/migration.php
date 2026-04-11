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

    // ── MIGRATION 010: allarga colonna code in otp_codes per SHA-256 (64 char) ──
    try {
        $pdo->exec("ALTER TABLE otp_codes MODIFY COLUMN code VARCHAR(64) NOT NULL");
    } catch (Exception $e) { /* silenzioso se già corretto */ }

    // ── MIGRATION 011: tabella security_logs ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_logs (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            event_type  VARCHAR(50) NOT NULL,
            severity    ENUM('INFO','WARNING','CRITICAL') NOT NULL,
            admin_id    INT NULL,
            ip_address  VARCHAR(45),
            user_agent  TEXT,
            details     JSON,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_severity (severity),
            INDEX idx_admin (admin_id),
            INDEX idx_created (created_at),
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Cleanup log vecchi (>90 giorni) — silenzioso
    try { $pdo->exec("DELETE FROM security_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"); } catch (Exception $e) {}

    // ── MIGRATION 012: multi-group system ──
    // NOTA: `groups` è reserved word in MySQL — backtick obbligatori.
    // Il DEFAULT su group_id è mantenuto intenzionalmente per backward
    // compatibility: tutti gli endpoint esistenti continuano a funzionare
    // senza modifiche fino alla Phase 2.
    try {

        // STEP 1: Tabella `groups`
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `groups` (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100) NOT NULL UNIQUE,
                description TEXT,
                logo_url    VARCHAR(255),
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by  INT NULL,
                FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // STEP 2: Gruppo default "Strike Zone Original" (idempotente)
        $stmtGrp = $pdo->prepare("SELECT id FROM `groups` WHERE name = 'Strike Zone Original' LIMIT 1");
        $stmtGrp->execute();
        $existingGrp = $stmtGrp->fetch(PDO::FETCH_ASSOC);

        if ($existingGrp) {
            $defaultGroupId = (int)$existingGrp['id'];
        } else {
            $firstAdmin = $pdo->query("SELECT id FROM admins ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $createdBy  = $firstAdmin ? (int)$firstAdmin['id'] : null;
            $pdo->prepare("INSERT INTO `groups` (name, description, created_by) VALUES (?, ?, ?)")
                ->execute(['Strike Zone Original', 'Gruppo originale - Migrato da sistema single-group', $createdBy]);
            $defaultGroupId = (int)$pdo->lastInsertId();
        }

        // STEP 3: group_id su players (idempotente)
        if (empty($pdo->query("SHOW COLUMNS FROM players LIKE 'group_id'")->fetchAll())) {
            $pdo->exec("ALTER TABLE players ADD COLUMN group_id INT NOT NULL DEFAULT $defaultGroupId AFTER id");
            // DEFAULT mantenuto — backward compat. Phase 2 lo rimuoverà dopo aver aggiornato le API.
            try { $pdo->exec("ALTER TABLE players ADD CONSTRAINT fk_players_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE RESTRICT"); } catch (Exception $e) {}
            try { $pdo->exec("CREATE INDEX idx_players_group ON players(group_id)"); } catch (Exception $e) {}
        }

        // STEP 4: group_id su sessions (idempotente)
        if (empty($pdo->query("SHOW COLUMNS FROM sessions LIKE 'group_id'")->fetchAll())) {
            $pdo->exec("ALTER TABLE sessions ADD COLUMN group_id INT NOT NULL DEFAULT $defaultGroupId AFTER id");
            try { $pdo->exec("ALTER TABLE sessions ADD CONSTRAINT fk_sessions_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE RESTRICT"); } catch (Exception $e) {}
            try { $pdo->exec("CREATE INDEX idx_sessions_group ON sessions(group_id)"); } catch (Exception $e) {}
        }

        // STEP 5: full_name e phone su admins (idempotente)
        if (empty($pdo->query("SHOW COLUMNS FROM admins LIKE 'full_name'")->fetchAll())) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN full_name VARCHAR(100) NULL AFTER email");
        }
        if (empty($pdo->query("SHOW COLUMNS FROM admins LIKE 'phone'")->fetchAll())) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN phone VARCHAR(20) NULL AFTER full_name");
        }

        // STEP 6: Tabella admin_roles
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_roles (
                id                     INT AUTO_INCREMENT PRIMARY KEY,
                admin_id               INT NOT NULL,
                group_id               INT NULL,
                role                   ENUM('super_admin','group_admin') NOT NULL DEFAULT 'group_admin',
                can_add_players        TINYINT(1) NOT NULL DEFAULT 1,
                can_edit_players       TINYINT(1) NOT NULL DEFAULT 1,
                can_delete_players     TINYINT(1) NOT NULL DEFAULT 0,
                can_add_sessions       TINYINT(1) NOT NULL DEFAULT 1,
                can_edit_sessions      TINYINT(1) NOT NULL DEFAULT 1,
                can_delete_sessions    TINYINT(1) NOT NULL DEFAULT 0,
                can_export_data        TINYINT(1) NOT NULL DEFAULT 0,
                can_view_security_logs TINYINT(1) NOT NULL DEFAULT 0,
                created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
                INDEX idx_admin (admin_id),
                INDEX idx_group (group_id),
                INDEX idx_role  (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // STEP 7: super_admin assignment — spostato DOPO creazione admin di default
        // (vedi sezione in fondo a runMigrations)

        // STEP 8: Tabella player_auth
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS player_auth (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                player_id     INT NOT NULL UNIQUE,
                email         VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                active        TINYINT(1) NOT NULL DEFAULT 1,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login    TIMESTAMP NULL,
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
                INDEX idx_email  (email),
                INDEX idx_player (player_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // STEP 9: must_change_password su player_auth
        try {
            $pdo->query("SELECT must_change_password FROM player_auth LIMIT 1");
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE player_auth ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        }

        // STEP 10: Tabella player_password_resets
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS player_password_resets (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                player_id  INT NOT NULL,
                token      VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used       TINYINT(1) NOT NULL DEFAULT 0,
                used_at    DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (player_id) REFERENCES player_auth(id) ON DELETE CASCADE,
                INDEX idx_token   (token),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

    } catch (Exception $e) {
        error_log("Migration 012 error: " . $e->getMessage());
    }

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
            
            error_log("✅ Admin di default creato: $defaultEmail — cambia la password al primo accesso!");
        }
    } catch (Exception $e) {
        error_log("Errore creazione admin: " . $e->getMessage());
    }

    // ── ASSEGNA super_admin AL PRIMO ADMIN ──
    // Gira dopo la creazione dell'admin di default così trova sempre un admin.
    // Idempotente: salta se il ruolo esiste già.
    try {
        $firstAdmin = $pdo->query("SELECT id FROM admins ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($firstAdmin) {
            $stmtRole = $pdo->prepare("SELECT id FROM admin_roles WHERE admin_id = ? AND role = 'super_admin' LIMIT 1");
            $stmtRole->execute([$firstAdmin['id']]);
            if (!$stmtRole->fetch()) {
                $pdo->prepare("INSERT INTO admin_roles (admin_id, group_id, role) VALUES (?, NULL, 'super_admin')")
                    ->execute([$firstAdmin['id']]);
            }
        }
    } catch (Exception $e) {
        // admin_roles potrebbe non esistere su DB pre-migration 012
        error_log("Errore assegnazione super_admin: " . $e->getMessage());
    }
}
