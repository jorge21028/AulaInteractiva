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
