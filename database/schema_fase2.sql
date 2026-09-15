-- =========================================================
-- AulaInteractiva — Esquema de base de datos — FASE 2
-- Actividades interactivas, preguntas, opciones y partidas.
-- Ejecutar DESPUÉS de schema_fase1.sql, sobre la misma base de datos.
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Actividades interactivas
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS activities (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id          INT UNSIGNED NOT NULL,
    teacher_id          INT UNSIGNED NOT NULL,
    title               VARCHAR(200) NOT NULL,
    description         TEXT NULL,
    instructions        TEXT NULL,
    difficulty          ENUM('facil', 'media', 'dificil') NOT NULL DEFAULT 'media',
    time_per_question   SMALLINT UNSIGNED NOT NULL DEFAULT 20,   -- segundos, valor por defecto para preguntas nuevas
    points_base         SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    speed_bonus_max     SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    ranking_enabled     TINYINT(1) NOT NULL DEFAULT 1,
    allow_repeat        TINYINT(1) NOT NULL DEFAULT 1,
    team_mode           TINYINT(1) NOT NULL DEFAULT 0,
    source              ENUM('manual', 'gemini') NOT NULL DEFAULT 'manual',
    status              ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    created_at          DATETIME NOT NULL,
    updated_at          DATETIME NULL,
    CONSTRAINT fk_activities_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_activities_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(user_id) ON DELETE CASCADE,
    KEY idx_activities_subject (subject_id),
    KEY idx_activities_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Preguntas de una actividad
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_questions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id     INT UNSIGNED NOT NULL,
    type            ENUM('multiple', 'truefalse') NOT NULL DEFAULT 'multiple',
    statement       TEXT NOT NULL,
    image_path      VARCHAR(255) NULL,
    time_seconds    SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    points          SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    explanation     TEXT NULL,
    order_index     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    CONSTRAINT fk_questions_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    KEY idx_questions_activity (activity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Opciones de respuesta de cada pregunta
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS question_options (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id     INT UNSIGNED NOT NULL,
    text            VARCHAR(300) NOT NULL,
    is_correct      TINYINT(1) NOT NULL DEFAULT 0,
    order_index     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_options_question FOREIGN KEY (question_id) REFERENCES activity_questions(id) ON DELETE CASCADE,
    KEY idx_options_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Partidas (instancia en vivo de una actividad)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS games (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id             INT UNSIGNED NOT NULL,
    teacher_id              INT UNSIGNED NOT NULL,
    code                    VARCHAR(10) NOT NULL,
    status                  ENUM('waiting', 'question', 'question_results', 'finished') NOT NULL DEFAULT 'waiting',
    current_question_index  SMALLINT NOT NULL DEFAULT -1,   -- -1 = aún no inicia
    current_question_started_at DATETIME NULL,
    started_at              DATETIME NULL,
    finished_at             DATETIME NULL,
    created_at              DATETIME NOT NULL,
    CONSTRAINT fk_games_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_games_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(user_id) ON DELETE CASCADE,
    KEY idx_games_code (code),
    KEY idx_games_activity (activity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Jugadores conectados a una partida
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_players (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id         INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    nickname        VARCHAR(60) NOT NULL,
    score           INT UNSIGNED NOT NULL DEFAULT 0,
    joined_at       DATETIME NOT NULL,
    CONSTRAINT fk_players_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_players_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE,
    UNIQUE KEY uq_player_per_game (game_id, student_id),
    KEY idx_players_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Respuestas registradas por partida/pregunta
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_answers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id             INT UNSIGNED NOT NULL,
    player_id           INT UNSIGNED NOT NULL,
    question_id         INT UNSIGNED NOT NULL,
    option_id           INT UNSIGNED NULL,
    is_correct          TINYINT(1) NOT NULL DEFAULT 0,
    points_awarded      INT NOT NULL DEFAULT 0,
    response_time_ms    INT UNSIGNED NULL,
    answered_at         DATETIME NOT NULL,
    CONSTRAINT fk_answers_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_player FOREIGN KEY (player_id) REFERENCES game_players(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES activity_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_option FOREIGN KEY (option_id) REFERENCES question_options(id) ON DELETE SET NULL,
    UNIQUE KEY uq_answer_per_question (player_id, question_id),
    KEY idx_answers_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- NOTA: assignments, submissions, student_projects, etc. se
-- agregarán en schema_fase3.sql / schema_fase4.sql en adelante.
-- =========================================================
