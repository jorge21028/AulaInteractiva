-- =========================================================
-- Dynamic SGA — Esquema de base de datos — FASE 10
-- Código de auto-matrícula: el profesor comparte un código de su
-- curso y los estudiantes se inscriben ellos mismos con ese código,
-- sin que el profesor tenga que escribir su correo manualmente.
-- Ejecutar DESPUÉS de todos los scripts anteriores.
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE courses ADD COLUMN IF NOT EXISTS enrollment_code VARCHAR(10) NULL AFTER name;

-- La unicidad se agrega en un ALTER separado porque "ADD COLUMN ... UNIQUE"
-- combinado con IF NOT EXISTS no es compatible en todas las versiones de
-- MySQL/MariaDB. Si vuelves a correr este script sobre una base de datos
-- que ya tiene este índice, omite (o comenta) la siguiente línea.
ALTER TABLE courses ADD UNIQUE KEY uq_courses_enrollment_code (enrollment_code);

SET FOREIGN_KEY_CHECKS = 1;
