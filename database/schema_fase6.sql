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
