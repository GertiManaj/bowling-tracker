-- ============================================
--  setup.sql — Script inizializzazione DB
--  Da eseguire UNA VOLTA su Railway dopo
--  aver creato il database MySQL
-- ============================================

CREATE TABLE IF NOT EXISTS players (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    nickname   VARCHAR(100) DEFAULT '',
    emoji      VARCHAR(10)  DEFAULT '🎳',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    date       DATE         NOT NULL,
    location   VARCHAR(150) DEFAULT 'Bowling',
    notes      TEXT,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teams (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    name       VARCHAR(100) NOT NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scores (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    player_id  INT NOT NULL,
    team_id    INT,
    score      INT NOT NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id)  REFERENCES players(id)  ON DELETE CASCADE,
    FOREIGN KEY (team_id)    REFERENCES teams(id)    ON DELETE SET NULL
);

-- Vista classifica
CREATE OR REPLACE VIEW leaderboard AS
SELECT
    p.id, p.name, p.nickname, p.emoji,
    COUNT(s.id)            AS partite,
    ROUND(AVG(s.score), 1) AS media,
    MAX(s.score)           AS record,
    MIN(s.score)           AS minimo
FROM players p
LEFT JOIN scores s ON s.player_id = p.id
GROUP BY p.id
ORDER BY media DESC;

-- Vista dettaglio punteggi
CREATE OR REPLACE VIEW scores_detail AS
SELECT
    sc.id,
    se.date,
    se.location,
    p.name        AS player_name,
    p.id          AS player_id,
    p.emoji,
    t.name        AS team_name,
    sc.score
FROM scores sc
JOIN sessions se ON sc.session_id = se.id
JOIN players  p  ON sc.player_id  = p.id
LEFT JOIN teams t ON sc.team_id   = t.id
ORDER BY se.date DESC, sc.score DESC;
