-- Tourney database schema (MySQL 8).
-- The app runs this automatically on first start. Every statement is
-- "IF NOT EXISTS", so running it again is always safe.

CREATE TABLE IF NOT EXISTS tournaments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    start_time      DATETIME     NOT NULL,
    match_minutes   INT          NOT NULL DEFAULT 20,
    break_minutes   INT          NOT NULL DEFAULT 5,
    points_win      INT          NOT NULL DEFAULT 3,
    points_draw     INT          NOT NULL DEFAULT 1,
    points_loss     INT          NOT NULL DEFAULT 0,
    is_public       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tournament_groups (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id   INT          NOT NULL,
    name            VARCHAR(50)  NOT NULL,
    position        INT          NOT NULL DEFAULT 0,
    CONSTRAINT fk_groups_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id   INT          NOT NULL,
    group_id        INT          NULL,
    name            VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_team_name (tournament_id, name),
    CONSTRAINT fk_teams_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_teams_group FOREIGN KEY (group_id)
        REFERENCES tournament_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS players (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    team_id         INT          NOT NULL,
    name            VARCHAR(100) NOT NULL,
    number          VARCHAR(10)  NULL,
    discord         VARCHAR(100) NULL,
    CONSTRAINT fk_players_team FOREIGN KEY (team_id)
        REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id   INT          NOT NULL,
    name            VARCHAR(50)  NOT NULL,
    position        INT          NOT NULL DEFAULT 0,
    CONSTRAINT fk_courts_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id   INT          NOT NULL,
    stage           ENUM('group','final') NOT NULL DEFAULT 'group',
    group_id        INT          NULL,
    round           INT          NOT NULL DEFAULT 1,
    team1_id        INT          NOT NULL,
    team2_id        INT          NOT NULL,
    court_id        INT          NULL,
    start_time      DATETIME     NULL,
    score1          INT          NULL,
    score2          INT          NULL,
    winner_team_id  INT          NULL,
    status          ENUM('scheduled','live','finished') NOT NULL DEFAULT 'scheduled',
    KEY idx_matches_time (tournament_id, start_time),
    CONSTRAINT fk_matches_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_group FOREIGN KEY (group_id)
        REFERENCES tournament_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_team1 FOREIGN KEY (team1_id)
        REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_team2 FOREIGN KEY (team2_id)
        REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_court FOREIGN KEY (court_id)
        REFERENCES courts(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_winner FOREIGN KEY (winner_team_id)
        REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Accounts (same setup as Dev Board) ----------

CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL UNIQUE,
    name            VARCHAR(120) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            VARCHAR(20)  NOT NULL DEFAULT 'scorekeeper',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL,
    last_login_at   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    token_hash      CHAR(64)     NOT NULL,
    expires_at      DATETIME     NOT NULL,
    used_at         DATETIME     NULL,
    INDEX (token_hash),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    `key`           VARCHAR(60)  PRIMARY KEY,
    value           TEXT         NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
