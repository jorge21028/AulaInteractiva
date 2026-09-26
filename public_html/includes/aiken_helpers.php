<?php
/**
 * aiken_helpers.php
 * Importación masiva de preguntas de selección múltiple desde archivos
 * en formato Aiken (estándar simple usado por Moodle y otras plataformas).
 *
 * Formato esperado, un bloque por pregunta, separado por línea en blanco:
 *
 *   ¿Cuál es la capital de Francia?
 *   A) Madrid
 *   B) París
 *   C) Roma
 *   D) Berlín
 *   ANSWER: B
 *
 * Se acepta tanto "A)" como "A." antes de cada opción, y "ANSWER:" o
 * "ANSWER" (sin dos puntos), sin distinguir mayúsculas/minúsculas.
 * Sirve tanto para actividades de Trivia como para "El Sapito": en ambas,
 * las preguntas son de opción única (statement + opciones + una correcta).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/**
 * Parsea el contenido completo de un archivo Aiken.
 * Devuelve ['questions' => array, 'errors' => array de strings].
 * Cada pregunta válida: ['statement' => string, 'options' => [['letter','text'],...], 'answer_letter' => string]
 */
function aiken_parse_questions(string $content): array
{
    // Normalizar saltos de línea y quitar BOM si viene de un editor de Windows
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    $blocks = preg_split('/\n[ \t]*\n+/', trim($content));
    $questions = [];
    $errors = [];

    foreach ($blocks as $blockIndex => $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        $lines = array_values(array_filter(
            array_map('rtrim', explode("\n", $block)),
            fn($l) => trim($l) !== ''
        ));

        $numero = $blockIndex + 1;
        $statementLines = [];
        $options = [];
        $answerLetter = null;
        $i = 0;

        // El enunciado son todas las líneas hasta la primera que parezca una opción (ej: "A) ..." o "A. ...")
        while ($i < count($lines) && !preg_match('/^\s*[A-Za-z][\.\)]\s+.+$/', $lines[$i])) {
            $statementLines[] = trim($lines[$i]);
            $i++;
        }

        for (; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/^\s*ANSWER\s*:?\s*([A-Za-z])\s*$/i', $line, $m)) {
                $answerLetter = strtoupper($m[1]);
                continue;
            }

            if (preg_match('/^\s*([A-Za-z])[\.\)]\s+(.+)$/', $line, $m)) {
                $options[] = ['letter' => strtoupper($m[1]), 'text' => trim($m[2])];
            }
        }

        $statement = trim(implode(' ', $statementLines));

        if ($statement === '') {
            $errors[] = "Pregunta {$numero}: no se encontró el enunciado.";
            continue;
        }
        if (count($options) < 2) {
            $errors[] = "Pregunta {$numero}: necesita al menos 2 opciones (\"{$statement}\").";
            continue;
        }
        if ($answerLetter === null) {
            $errors[] = "Pregunta {$numero}: falta la línea \"ANSWER: X\" (\"{$statement}\").";
            continue;
        }

        $hasCorrect = false;
        foreach ($options as $o) {
            if ($o['letter'] === $answerLetter) {
                $hasCorrect = true;
                break;
            }
        }
        if (!$hasCorrect) {
            $errors[] = "Pregunta {$numero}: la respuesta \"{$answerLetter}\" no coincide con ninguna opción (\"{$statement}\").";
            continue;
        }

        $questions[] = [
            'statement'     => $statement,
            'options'       => $options,
            'answer_letter' => $answerLetter,
        ];
    }

    return ['questions' => $questions, 'errors' => $errors];
}

/**
 * Inserta en la base de datos las preguntas ya parseadas para una actividad,
 * como preguntas de tipo 'multiple' con sus opciones. Devuelve la cantidad
 * de preguntas insertadas.
 */
function aiken_import_into_activity(PDO $pdo, int $activityId, array $parsedQuestions, int $timeSeconds, int $points): int
{
    $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM activity_questions WHERE activity_id = :activity_id');
    $countStmt->execute(['activity_id' => $activityId]);
    $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);

    $insertQ = $pdo->prepare(
        'INSERT INTO activity_questions (activity_id, type, statement, time_seconds, points, order_index, created_at)
         VALUES (:activity_id, :type, :statement, :time_seconds, :points, :order_index, :created_at)'
    );
    $insertOpt = $pdo->prepare(
        'INSERT INTO question_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, :correct, :order_index)'
    );

    $inserted = 0;

    foreach ($parsedQuestions as $q) {
        $insertQ->execute([
            'activity_id'  => $activityId,
            'type'         => 'multiple',
            'statement'    => $q['statement'],
            'time_seconds' => $timeSeconds,
            'points'       => $points,
            'order_index'  => $orderIndex,
            'created_at'   => now_datetime(),
        ]);
        $questionId = (int) $pdo->lastInsertId();
        $orderIndex++;

        foreach ($q['options'] as $optIndex => $opt) {
            $insertOpt->execute([
                'qid'        => $questionId,
                'text'       => $opt['text'],
                'correct'    => $opt['letter'] === $q['answer_letter'] ? 1 : 0,
                'order_index'=> $optIndex,
            ]);
        }

        $inserted++;
    }

    return $inserted;
}
