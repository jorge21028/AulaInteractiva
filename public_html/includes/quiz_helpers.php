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

    quiz_snapshot_questions_for_attempt($pdo, $attemptId, $quizId, $quiz['questions_per_attempt'] ?? null);

    $getStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = :id');
    $getStmt->execute(['id' => $attemptId]);

    return ['attempt' => $getStmt->fetch(), 'error' => null];
}

/**
 * Elige qué preguntas le tocan a un intento nuevo y las "congela" en
 * quiz_attempt_questions, para que el subconjunto al azar no cambie durante
 * el intento aunque el profesor edite el banco de preguntas después.
 * Si $questionsPerAttempt es NULL, 0, o mayor o igual al total del banco,
 * se incluyen TODAS las preguntas (mismo comportamiento que antes de esta
 * función existir), simplemente en orden aleatorio.
 */
function quiz_snapshot_questions_for_attempt(PDO $pdo, int $attemptId, int $quizId, ?int $questionsPerAttempt): void
{
    $idsStmt = $pdo->prepare('SELECT id FROM quiz_questions WHERE quiz_id = :quiz_id');
    $idsStmt->execute(['quiz_id' => $quizId]);
    $ids = array_column($idsStmt->fetchAll(), 'id');

    if (empty($ids)) {
        return;
    }

    shuffle($ids);

    if ($questionsPerAttempt !== null && $questionsPerAttempt > 0 && $questionsPerAttempt < count($ids)) {
        $ids = array_slice($ids, 0, $questionsPerAttempt);
    }

    $insert = $pdo->prepare(
        'INSERT INTO quiz_attempt_questions (attempt_id, question_id, order_index) VALUES (:attempt_id, :question_id, :order_index)'
    );
    foreach ($ids as $orderIndex => $questionId) {
        $insert->execute(['attempt_id' => $attemptId, 'question_id' => $questionId, 'order_index' => $orderIndex]);
    }
}

/**
 * Registra que el estudiante cambió de pestaña / minimizó / salió de
 * pantalla completa durante un intento en curso. Se ignora silenciosamente
 * si el intento no existe, no es de ese estudiante, o ya fue entregado.
 */
function quiz_record_tab_switch(PDO $pdo, int $attemptId, int $studentId): void
{
    $pdo->prepare(
        "UPDATE quiz_attempts SET tab_switches = tab_switches + 1
         WHERE id = :id AND student_id = :student_id AND status = 'in_progress'"
    )->execute(['id' => $attemptId, 'student_id' => $studentId]);
}

/** Máximo de strikes por intento: al llegar a este número el intento se cierra y se entrega. */
const QUIZ_MAX_STRIKES = 3;
/** Descuento de tiempo (porcentaje del tiempo ESTABLECIDO del cuestionario) por cada strike. */
const QUIZ_STRIKE_PENALTY_PERCENT = [1 => 10, 2 => 20];
/** Segundos de gracia para que el navegador envíe las respuestas tras el tercer strike. */
const QUIZ_CLOSE_GRACE_SECONDS = 30;

/**
 * Registra un strike (salida de la pantalla del cuestionario) y aplica su consecuencia:
 *   strike 1 -> descuenta 10 % del tiempo establecido del cuestionario
 *   strike 2 -> descuenta 20 % del tiempo establecido
 *   strike 3 -> el intento se cierra (el navegador envía lo respondido)
 * Usa tab_switches como contador de strikes, así el profesor sigue viendo el mismo dato.
 * El descuento se aplica adelantando expires_at, por lo que el servidor es la autoridad
 * del tiempo aunque el estudiante manipule el navegador.
 *
 * Devuelve ['active' => false] si el intento no existe, no es del estudiante o ya se entregó.
 */
function quiz_register_strike(PDO $pdo, int $attemptId, int $studentId): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT qa.id, qa.tab_switches, qa.expires_at, qz.time_limit_minutes
             FROM quiz_attempts qa INNER JOIN quizzes qz ON qz.id = qa.quiz_id
             WHERE qa.id = :id AND qa.student_id = :student_id AND qa.status = 'in_progress'
             FOR UPDATE"
        );
        $stmt->execute(['id' => $attemptId, 'student_id' => $studentId]);
        $attempt = $stmt->fetch();

        if (!$attempt) {
            $pdo->rollBack();
            return ['active' => false];
        }

        $strike = (int) $attempt['tab_switches'] + 1;
        $limitSeconds = !empty($attempt['time_limit_minutes']) ? (int) $attempt['time_limit_minutes'] * 60 : 0;
        $hasTimeLimit = $attempt['expires_at'] !== null && $limitSeconds > 0;
        $closed = $strike >= QUIZ_MAX_STRIKES;

        $percent = $closed ? 0 : (QUIZ_STRIKE_PENALTY_PERCENT[$strike] ?? 0);
        $penaltySeconds = 0;
        $newExpires = $attempt['expires_at'];

        if ($closed) {
            // Se cierra: margen corto para que el navegador envíe las respuestas; si no lo hace,
            // al vencer se autoentrega con lo guardado.
            $graceEnd = time() + QUIZ_CLOSE_GRACE_SECONDS;
            $current = $attempt['expires_at'] !== null ? strtotime($attempt['expires_at']) : PHP_INT_MAX;
            $newExpires = date('Y-m-d H:i:s', min($graceEnd, $current));
        } elseif ($hasTimeLimit && $percent > 0) {
            $penaltySeconds = (int) round($limitSeconds * $percent / 100);
            $newExpires = date('Y-m-d H:i:s', strtotime($attempt['expires_at']) - $penaltySeconds);
        }

        $pdo->prepare('UPDATE quiz_attempts SET tab_switches = :strike, expires_at = :expires WHERE id = :id')
            ->execute(['strike' => $strike, 'expires' => $newExpires, 'id' => $attemptId]);
        $pdo->commit();

        return [
            'active'            => true,
            'strike'            => $strike,
            'max_strikes'       => QUIZ_MAX_STRIKES,
            'strikes_left'      => max(0, QUIZ_MAX_STRIKES - $strike),
            'closed'            => $closed,
            'has_time_limit'    => $hasTimeLimit,
            'penalty_percent'   => $percent,
            'penalty_seconds'   => $penaltySeconds,
            'remaining_seconds' => $newExpires !== null ? max(0, strtotime($newExpires) - time()) : null,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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

    // Solo se califica con las preguntas que efectivamente le tocaron a ESTE
    // intento (el subconjunto al azar congelado al empezar), no todo el banco.
    $qStmt = $pdo->prepare(
        'SELECT qq.* FROM quiz_attempt_questions qaq
         INNER JOIN quiz_questions qq ON qq.id = qaq.question_id
         WHERE qaq.attempt_id = :attempt_id ORDER BY qaq.order_index ASC'
    );
    $qStmt->execute(['attempt_id' => $attemptId]);
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
 * Devuelve las preguntas que le tocaron a ESTE intento (el subconjunto al
 * azar ya congelado), con sus opciones (sin revelar cuál es la correcta),
 * listas para mostrarle al estudiante mientras responde.
 */
function quiz_questions_for_attempt(PDO $pdo, int $attemptId): array
{
    $qStmt = $pdo->prepare(
        'SELECT qq.id, qq.type, qq.statement, qq.points
         FROM quiz_attempt_questions qaq
         INNER JOIN quiz_questions qq ON qq.id = qaq.question_id
         WHERE qaq.attempt_id = :attempt_id ORDER BY qaq.order_index ASC'
    );
    $qStmt->execute(['attempt_id' => $attemptId]);
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
         FROM quiz_attempt_questions qaq
         INNER JOIN quiz_questions qq ON qq.id = qaq.question_id
         LEFT JOIN quiz_attempt_answers qaa ON qaa.question_id = qq.id AND qaa.attempt_id = qaq.attempt_id
         WHERE qaq.attempt_id = :attempt_id
         ORDER BY qaq.order_index ASC'
    );
    $qStmt->execute(['attempt_id' => $attemptId]);
    $questions = $qStmt->fetchAll();

    $optStmt = $pdo->prepare('SELECT id, text, is_correct FROM quiz_options WHERE question_id = :qid ORDER BY order_index ASC');
    foreach ($questions as &$q) {
        $optStmt->execute(['qid' => $q['id']]);
        $q['options'] = $optStmt->fetchAll();
    }
    unset($q);

    return $questions;
}
