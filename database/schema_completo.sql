-- =========================================================
-- AulaInteractiva — Esquema COMPLETO (Fases 1, 2, 4, 5 y 6)
-- Generado automáticamente concatenando los scripts individuales,
-- en el orden correcto, para instalaciones nuevas.
--
-- Si ya habías importado alguno de los schema_faseN.sql sueltos,
-- NO vuelvas a importar este archivo (fallaría por tablas duplicadas).
-- Este archivo es solo para instalaciones desde cero.
-- =========================================================


-- ============ schema_fase1.sql ============
-- =========================================================
-- AulaInteractiva — Esquema de base de datos — FASE 1
-- Motor: MySQL / MariaDB (compatible con InfinityFree)
-- Cargar en phpMyAdmin sobre una base de datos vacía.
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Usuarios y roles
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin', 'teacher', 'student') NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extiende users cuando role = 'teacher'
CREATE TABLE IF NOT EXISTS teachers (
    user_id     INT UNSIGNED PRIMARY KEY,
    bio         VARCHAR(500) NULL,
    CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extiende users cuando role = 'student'
CREATE TABLE IF NOT EXISTS students (
    user_id     INT UNSIGNED PRIMARY KEY,
    grade_level VARCHAR(50) NULL,
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Estructura académica: Cursos -> Asignaturas -> Estudiantes
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    created_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relación N:M profesor <-> curso (un curso puede tener más de un profesor a futuro)
CREATE TABLE IF NOT EXISTS teacher_courses (
    teacher_id  INT UNSIGNED NOT NULL,
    course_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (teacher_id, course_id),
    CONSTRAINT fk_tc_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_tc_course  FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subjects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    created_at  DATETIME NOT NULL,
    CONSTRAINT fk_subjects_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    KEY idx_subjects_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relación N:M estudiante <-> curso
CREATE TABLE IF NOT EXISTS course_students (
    course_id    INT UNSIGNED NOT NULL,
    student_id   INT UNSIGNED NOT NULL,
    enrolled_at  DATETIME NOT NULL,
    PRIMARY KEY (course_id, student_id),
    CONSTRAINT fk_cs_course  FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Auditoría (usado desde Fase 1 para login/registro/creación)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(100) NOT NULL,
    details     VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL,
    KEY idx_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------
-- Datos iniciales opcionales (puedes borrar este bloque)
-- Contraseña de ejemplo para ambos: "password123"
-- Hash generado con password_hash('password123', PASSWORD_DEFAULT)
-- ---------------------------------------------------------
-- INSERT INTO users (name, email, password_hash, role, is_active, created_at) VALUES
-- ('Profesor Demo', 'profesor@demo.com', '$2y$10$w8sUuNQ2z2k7X3jL0m2s7uQmz9r0K5nZ2s7Yb8h1cL0v0m3wq1u9K', 'teacher', 1, NOW()),
-- ('Estudiante Demo', 'estudiante@demo.com', '$2y$10$w8sUuNQ2z2k7X3jL0m2s7uQmz9r0K5nZ2s7Yb8h1cL0v0m3wq1u9K', 'student', 1, NOW());
-- INSERT INTO teachers (user_id) VALUES (1);
-- INSERT INTO students (user_id) VALUES (2);

-- =========================================================
-- NOTA: Las tablas de Fase 2 en adelante (activities, questions,
-- games, assignments, submissions, student_projects, etc.) se
-- agregarán en scripts incrementales separados
-- (schema_fase2.sql, schema_fase3.sql...) para no reescribir
-- este archivo y mantener un historial claro en GitHub.
-- =========================================================


-- ============ schema_fase2.sql ============
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


-- ============ schema_fase4.sql ============
-- =========================================================
-- AulaInteractiva — Esquema de base de datos — FASE 4
-- Asignaciones, entregas y calificaciones.
-- Ejecutar DESPUÉS de schema_fase1.sql y schema_fase2.sql.
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Asignaciones (el profesor asigna una actividad con fecha y puntaje)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS assignments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id      INT UNSIGNED NOT NULL,
    subject_id      INT UNSIGNED NOT NULL,
    activity_id     INT UNSIGNED NOT NULL,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    start_date      DATETIME NULL,
    due_date        DATETIME NULL,
    points          SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at      DATETIME NOT NULL,
    CONSTRAINT fk_assignments_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_assignments_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignments_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    KEY idx_assignments_subject (subject_id),
    KEY idx_assignments_activity (activity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Estudiantes a los que se les asignó (normalmente todos los inscritos
-- en la asignatura al momento de crear la asignación)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS assignment_students (
    assignment_id   INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (assignment_id, student_id),
    CONSTRAINT fk_asgstud_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_asgstud_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Entregas / calificaciones
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS submissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id   INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    game_id         INT UNSIGNED NULL,          -- partida desde la que se autocalificó (si aplica)
    status          ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    score           DECIMAL(6,2) NULL,          -- calificación sobre "points" de la asignación
    feedback        TEXT NULL,
    completed_at    DATETIME NULL,
    reviewed_at     DATETIME NULL,               -- cuando el profesor ajustó manualmente
    created_at      DATETIME NOT NULL,
    UNIQUE KEY uq_submission (assignment_id, student_id),
    CONSTRAINT fk_submissions_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL,
    KEY idx_submissions_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- NOTA: student_projects, presentations, mind_maps, etc. (creación
-- académica) se agregarán en schema_fase5.sql en adelante.
-- =========================================================


-- ============ schema_fase5.sql ============
-- =========================================================
-- AulaInteractiva — Esquema de base de datos — FASE 5
-- Creación académica del estudiante (resúmenes, tablas comparativas).
-- Ejecutar DESPUÉS de schema_fase1.sql, schema_fase2.sql y schema_fase4.sql.
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Ajustes a "assignments": ahora una asignación puede referirse a una
-- actividad interactiva (como hasta ahora) O a un trabajo de creación
-- académica (resumen, tabla comparativa...), nunca a ambos a la vez.
-- ---------------------------------------------------------
ALTER TABLE assignments MODIFY COLUMN activity_id INT UNSIGNED NULL;
ALTER TABLE assignments ADD COLUMN IF NOT EXISTS project_type VARCHAR(30) NULL AFTER activity_id;

-- ---------------------------------------------------------
-- Trabajos de creación académica del estudiante.
-- Un solo modelo genérico (tabla + JSON editable) para todos los tipos,
-- en vez de una tabla distinta por herramienta: así se pueden agregar
-- nuevos tipos (infografías, mapas mentales, presentaciones...) más
-- adelante sin cambiar el esquema.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_projects (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED NOT NULL,
    assignment_id   INT UNSIGNED NULL,          -- NULL = trabajo libre (no ligado a una asignación)
    type            VARCHAR(30) NOT NULL,        -- 'resumen', 'tabla_comparativa', ...
    title           VARCHAR(200) NOT NULL,
    data_json        LONGTEXT NOT NULL,           -- estructura editable (ver includes/project_helpers.php)
    status          ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft',
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    submitted_at    DATETIME NULL,
    CONSTRAINT fk_projects_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_projects_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    UNIQUE KEY uq_project_per_assignment (assignment_id, student_id),
    KEY idx_projects_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Vincular cada entrega con el trabajo entregado (cuando aplica)
-- ---------------------------------------------------------
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS project_id INT UNSIGNED NULL AFTER game_id;
ALTER TABLE submissions ADD CONSTRAINT fk_submissions_project FOREIGN KEY (project_id) REFERENCES student_projects(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- NOTA: infografías, mapas mentales, presentaciones y el editor
-- gráfico con Canvas/Fabric.js se agregarán en fases posteriores,
-- reutilizando esta misma tabla student_projects con nuevos "type".
-- =========================================================


-- ============ schema_fase6.sql ============
-- =========================================================
-- AulaInteractiva — Esquema de base de datos — FASE 6
-- Editor gráfico: infografías y mapas mentales.
-- Ejecutar DESPUÉS de schema_fase1.sql, fase2.sql, fase4.sql y fase5.sql.
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Nota: "infografia" y "mapa_mental" son simplemente nuevos valores del
-- campo student_projects.type (VARCHAR), que ya existía desde la Fase 5.
-- No se requiere ALTER TABLE para eso.
-- ---------------------------------------------------------

-- ---------------------------------------------------------
-- Registro de archivos subidos por los estudiantes (imágenes insertadas
-- en infografías, mapas mentales, y en el futuro audio/video). Sirve
-- para trazabilidad y para poder limpiar archivos huérfanos más adelante.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS uploads (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    project_id      INT UNSIGNED NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_name     VARCHAR(255) NOT NULL,   -- nombre aleatorio real en disco (ver security.php: safe_random_filename)
    mime_type       VARCHAR(100) NOT NULL,
    size_bytes      INT UNSIGNED NOT NULL,
    kind            ENUM('image', 'audio', 'video') NOT NULL DEFAULT 'image',
    created_at      DATETIME NOT NULL,
    CONSTRAINT fk_uploads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uploads_project FOREIGN KEY (project_id) REFERENCES student_projects(id) ON DELETE SET NULL,
    KEY idx_uploads_user (user_id),
    KEY idx_uploads_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- NOTA: presentaciones, fichas de estudio y demás organizadores
-- gráficos (Fase 7 en adelante) también reutilizarán student_projects
-- con nuevos valores de "type", y multimedia (audio/video) reutilizará
-- esta misma tabla "uploads".
-- =========================================================

