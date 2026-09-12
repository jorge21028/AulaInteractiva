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
