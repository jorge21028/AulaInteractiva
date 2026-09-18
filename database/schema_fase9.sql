-- =========================================================
-- AulaInteractiva — Esquema de base de datos — FASE 9
-- Nuevos tipos de actividad interactiva estilo Educaplay:
-- ordenar elementos, relacionar parejas, completar espacios.
-- Ejecutar DESPUÉS de schema_fase1.sql, fase2.sql, fase4.sql, fase5.sql, fase6.sql.
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Ampliar los tipos de pregunta permitidos
-- ---------------------------------------------------------
ALTER TABLE activity_questions
    MODIFY COLUMN type ENUM('multiple', 'truefalse', 'ordenar', 'relacionar', 'completar') NOT NULL DEFAULT 'multiple';

-- ---------------------------------------------------------
-- "relacionar": cada opción guarda el texto izquierdo en "text" (ya
-- existía) y ahora también el texto derecho con el que debe emparejarse.
-- Para "ordenar", el orden correcto es simplemente order_index.
-- Para "completar", se usa una sola opción con is_correct = 1 cuyo
-- texto es la respuesta correcta.
-- ---------------------------------------------------------
ALTER TABLE question_options ADD COLUMN IF NOT EXISTS match_text VARCHAR(300) NULL AFTER text;

-- ---------------------------------------------------------
-- game_answers: los tipos nuevos no se responden con una sola opción,
-- sino con una estructura (el orden elegido, las parejas elegidas, o el
-- texto escrito). option_id queda NULL para esos casos y se usa
-- answer_data en su lugar.
-- ---------------------------------------------------------
ALTER TABLE game_answers ADD COLUMN IF NOT EXISTS answer_data TEXT NULL AFTER option_id;

SET FOREIGN_KEY_CHECKS = 1;
