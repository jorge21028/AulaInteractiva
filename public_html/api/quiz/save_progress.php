<?php
/**
 * POST /api/quiz/save_progress.php  (JSON: attempt_id, answers {pregunta: opción}, csrf_token)
 * Guarda en el servidor las respuestas que el estudiante lleva marcadas, para que si se acaba el tiempo (o cierra
 * la página, o se le cae el internet) el cuestionario se envíe con lo que ya tenía en vez de en blanco.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
csrf_verify($input['csrf_token'] ?? null);

$answers = [];
foreach ((array) ($input['answers'] ?? []) as $qid => $oid) {
    if ((int) $qid > 0 && (int) $oid > 0) {
        $answers[(int) $qid] = (int) $oid;
    }
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare("UPDATE quiz_attempts SET draft_answers = :d WHERE id = :id AND student_id = :sid AND status = 'in_progress'");
$stmt->execute(['d' => json_encode($answers), 'id' => (int) ($input['attempt_id'] ?? 0), 'sid' => current_user_id()]);

json_response(['success' => true, 'saved' => $stmt->rowCount() > 0, 'count' => count($answers)]);
