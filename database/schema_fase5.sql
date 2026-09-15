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
