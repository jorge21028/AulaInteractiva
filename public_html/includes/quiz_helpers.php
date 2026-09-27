<?php
/**
 * quiz_helpers.php
 * Lógica del módulo "Cuestionario" (Fase 12): actividad individual, a su
 * propio ritmo (tipo Moodle), a diferencia de las actividades en vivo
 * (Trivia / El Sapito) que maneja game_helpers.php.
 *
 * Un estudiante puede tener varios intentos (según max_attempts del
 * cuestionario). Cada intento tiene un límite de tiempo opcional
 * (time_limit_minutes). Al entregar, se autocalifica al instante
 * (solo hay preguntas 'multiple' y 'truefalse', igual que en Trivia).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/**
 * Busca un intento 'in_progress' vigente (no vencido) para este estudiante,
 * o crea uno nuevo si no hay ninguno y todavía le quedan intentos disponibles.
 * Devuelve ['attempt' => array|null, 'error' => string|null].
 */
function quiz_start_or_resume_attempt(PDO $pdo, array $quiz, int $studentId, ?int $assignmentId): array
{
    $quizId = (int) $quiz['id'];

    // ¿Hay un intento en curso que todavía no venció?
    $stmt = $pdo->prepare(
        "SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id
         AND status = 'in_progress' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['quiz_id' => $quizId, 'student_id' => $studentId]);
    $open = $stmt->fetch();

    if ($open) {
        if ($open['expires_at'] !== null && strtotime($open['expires_at']) < time()) {
            // Se venció el tiempo sin que el estudiante entregara: se autoentrega tal cual quedó.
            quiz_submit_attempt($pdo, (int) $open['id'], []);
        } else {
            return ['attempt' => $open, 'error' => null];
        }
    }

    // ¿Cuántos intentos ya completó?
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'submitted'"
    );
    $countStmt->execute(['quiz_id' => $quizId, 'student_id' => $studentId]);
    $submittedCount = (int) ($countStmt->fetch()['total'] ?? 0);

    if ($submittedCount >= (int) $quiz['max_attempts']) {
        return ['attempt' => null, 'error' => 'Ya utilizaste todos tus intentos disponibles para este cuestionario.'];
    }

    $startedAt = now_datetime();
    $expiresAt = null;
    if (!empty($quiz['time_limit_minutes'])) {
        $expiresAt = date('Y-m-d H:i:s', time() + ((int) $quiz['time_limit_minutes'] * 60));
    }

    $insert = $pdo->prepare(
        'INSERT INTO quiz_attempts (quiz_id, student_id, assignment_id, attempt_number, status, started_at, expires_at)
         VALUES (:quiz_id, :student_id, :assignment_id, :attempt_number, \'in_progress\', :started_at, :expires_at)'
    );
    $insert->execute([
        'quiz_id'        => $quizId,
        'student_id'     => $studentId,
        'assignment_id'  => $assignmentId,
        'attempt_number' => $submittedCount + 1,
        'started_at'     => $startedAt,
        'expires_at'     => $expiresAt,
    ]);
    $attemptId = (int) $pdo->lastInsertId();

    $getStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = :id');
    $getStmt->execute(['id' => $attemptId]);

    return ['attempt' => $getStmt->fetch(), 'error' => null];
}

/**
 * Califica y cierra un intento. $answers es un array [question_id => option_id]
 * (puede venir incompleto: las preguntas sin responder cuentan como incorrectas).
 * Devuelve el intento ya actualizado (con score_points/score_max).
 */
function quiz_submit_attempt(PDO $pdo, int $attemptId, array $answers): array
{
    $attStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = :id');
    $attStmt->execute(['id' => $attemptId]);
    $attempt = $attStmt->fetch();

    if (!$attempt || $attempt['status'] === 'submitted') {
        return $attempt ?: [];
    }

    $qStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = :quiz_id ORDER BY order_index ASC');
    $qStmt->execute(['quiz_id' => $attempt['quiz_id']]);
    $questions = $qStmt->fetchAll();

    $optStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = :qid');
    $insertAns = $pdo->prepare(
        'INSERT INTO quiz_attempt_answers (attempt_id, question_id, selected_option_id, is_correct, points_earned)
         VALUES (:attempt_id, :question_id, :option_id, :is_correct, :points_earned)'
    );

    $scorePoints = 0.0;
    $scoreMax = 0.0;

    foreach ($questions as $q) {
        $scoreMax += (float) $q['points'];

        $optStmt->execute(['qid' => $q['id']]);
        $options = $optStmt->fetchAll();

        $correctOption = null;
        foreach ($options as $o) {
            if ((int) $o['is_correct'] === 1) {
                $correctOption = $o;
                break;
            }
        }

        $selectedOptionId = isset($answers[$q['id']]) ? (int) $answers[$q['id']] : null;
        $isCorrect = $correctOption && $selectedOptionId === (int) $correctOption['id'];
        $pointsEarned = $isCorrect ? (float) $q['points'] : 0.0;
        $scorePoints += $pointsEarned;

        $insertAns->execute([
            'attempt_id'    => $attemptId,
            'question_id'   => $q['id'],
            'option_id'     => $selectedOptionId,
            'is_correct'    => $isCorrect ? 1 : 0,
            'points_earned' => $pointsEarned,
        ]);
    }

    $pdo->prepare(
        "UPDATE quiz_attempts SET status = 'submitted', submitted_at = :submitted_at, score_points = :score_points, score_max = :score_max WHERE id = :id"
    )->execute([
        'submitted_at' => now_datetime(),
        'score_points' => $scorePoints,
        'score_max'    => $scoreMax,
        'id'           => $attemptId,
    ]);

    quiz_sync_submission($pdo, (int) $attempt['quiz_id'], (int) $attempt['student_id'], $attemptId);

    $attStmt->execute(['id' => $attemptId]);
    return $attStmt->fetch();
}

/**
 * Refleja el resultado de un intento en la(s) entrega(s) (submissions) de las
 * asignaciones que usan este cuestionario, quedándose siempre con la MEJOR
 * calificación obtenida entre todos los intentos (igual que el criterio por
 * defecto de Moodle). Nunca pisa una calificación ya ajustada a mano por el
 * profesor (reviewed_at no nulo).
 */
function quiz_sync_submission(PDO $pdo, int $quizId, int $studentId, int $attemptId): void
{
    $attStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = :id');
    $attStmt->execute(['id' => $attemptId]);
    $attempt = $attStmt->fetch();
    if (!$attempt || (float) $attempt['score_max'] <= 0) {
        return;
    }
    $percentage = (float) $attempt['score_points'] / (float) $attempt['score_max'];

    $asgStmt = $pdo->prepare('SELECT * FROM assignments WHERE quiz_id = :quiz_id');
    $asgStmt->execute(['quiz_id' => $quizId]);
    $assignments = $asgStmt->fetchAll();

    foreach ($assignments as $assignment) {
        $newScore = round($percentage * (int) $assignment['points'], 2);

        $subStmt = $pdo->prepare('SELECT * FROM submissions WHERE assignment_id = :aid AND student_id = :sid');
        $subStmt->execute(['aid' => $assignment['id'], 'sid' => $studentId]);
        $submission = $subStmt->fetch();

        if (!$submission) {
            $pdo->prepare(
                "INSERT INTO submissions (assignment_id, student_id, status, created_at) VALUES (:aid, :sid, 'pending', :created_at)"
            )->execute(['aid' => $assignment['id'], 'sid' => $studentId, 'created_at' => now_datetime()]);
            $subStmt->execute(['aid' => $assignment['id'], 'sid' => $studentId]);
            $submission = $subStmt->fetch();
        }

        if ($submission['reviewed_at'] !== null) {
            continue; // el profesor ya ajustó esta nota a mano: no se toca
        }

        if ($submission['status'] === 'completed' && $newScore <= (float) $submission['score']) {
            continue; // ya hay una nota igual o mejor de un intento anterior
        }

        $pdo->prepare(
            "UPDATE submissions SET status = 'completed', score = :score, quiz_attempt_id = :attempt_id, completed_at = :completed_at WHERE id = :id"
        )->execute([
            'score'      => $newScore,
            'attempt_id' => $attemptId,
            'completed_at' => now_datetime(),
            'id'         => $submission['id'],
        ]);
    }
}

/**
 * Devuelve las preguntas del cuestionario con sus opciones (sin revelar
 * cuál es la correcta), listas para mostrarle al estudiante durante el intento.
 */
function quiz_questions_for_attempt(PDO $pdo, int $quizId): array
{
    $qStmt = $pdo->prepare('SELECT id, type, statement, points FROM quiz_questions WHERE quiz_id = :quiz_id ORDER BY order_index ASC');
    $qStmt->execute(['quiz_id' => $quizId]);
    $questions = $qStmt->fetchAll();

    $optStmt = $pdo->prepare('SELECT id, text FROM quiz_options WHERE question_id = :qid ORDER BY order_index ASC');
    foreach ($questions as &$q) {
        $optStmt->execute(['qid' => $q['id']]);
        $q['options'] = $optStmt->fetchAll();
    }
    unset($q);

    return $questions;
}

/**
 * Devuelve las preguntas de un cuestionario con sus opciones (para la
 * pantalla de edición del profesor, que sí necesita ver cuál es la correcta).
 */
function quiz_fetch_questions(PDO $pdo, int $quizId): array
{
    $stmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = :quiz_id ORDER BY order_index ASC, id ASC');
    $stmt->execute(['quiz_id' => $quizId]);
    $questions = $stmt->fetchAll();

    foreach ($questions as &$q) {
        $optStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = :question_id ORDER BY order_index ASC, id ASC');
        $optStmt->execute(['question_id' => $q['id']]);
        $q['options'] = $optStmt->fetchAll();
    }
    unset($q);

    return $questions;
}

/**
 * Busca un cuestionario propiedad de este profesor.
 */
function quiz_load_owned(PDO $pdo, int $quizId, int $teacherId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = :id AND teacher_id = :teacher_id LIMIT 1');
    $stmt->execute(['id' => $quizId, 'teacher_id' => $teacherId]);
    return $stmt->fetch() ?: null;
}
function quiz_attempt_review(PDO $pdo, int $attemptId): array
{
    $qStmt = $pdo->prepare(
        'SELECT qq.id, qq.type, qq.statement, qq.points, qaa.selected_option_id, qaa.is_correct, qaa.points_earned
         FROM quiz_questions qq
         LEFT JOIN quiz_attempt_answers qaa ON qaa.question_id = qq.id AND qaa.attempt_id = :attempt_id
         WHERE qq.quiz_id = (SELECT quiz_id FROM quiz_attempts WHERE id = :attempt_id2)
         ORDER BY qq.order_index ASC'
    );
    $qStmt->execute(['attempt_id' => $attemptId, 'attempt_id2' => $attemptId]);
    $questions = $qStmt->fetchAll();

    $optStmt = $pdo->prepare('SELECT id, text, is_correct FROM quiz_options WHERE question_id = :qid ORDER BY order_index ASC');
    foreach ($questions as &$q) {
        $optStmt->execute(['qid' => $q['id']]);
        $q['options'] = $optStmt->fetchAll();
    }
    unset($q);

    return $questions;
}
