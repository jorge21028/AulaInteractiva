<?php
/**
 * submission_admin_helpers.php
 * Acciones del profesor sobre la entrega de un estudiante:
 *   - DEVOLVER un trabajo de creación para que el estudiante lo corrija y lo reenvíe (conserva su trabajo).
 *   - BORRAR la entrega (la deja como si el estudiante nunca hubiera empezado) para que pueda hacerla de nuevo.
 * Ambas validan que la entrega pertenezca a la asignación (y por tanto al profesor dueño).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/** Carga la entrega solo si pertenece a la asignación indicada. */
function submission_load_for_assignment(PDO $pdo, int $submissionId, int $assignmentId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT sub.*, u.name AS student_name FROM submissions sub
         INNER JOIN users u ON u.id = sub.student_id
         WHERE sub.id = :id AND sub.assignment_id = :aid LIMIT 1'
    );
    $stmt->execute(['id' => $submissionId, 'aid' => $assignmentId]);
    return $stmt->fetch() ?: null;
}

/**
 * Devuelve un trabajo de creación entregado: el trabajo vuelve a ser borrador (el estudiante conserva todo lo
 * que hizo) y la entrega queda pendiente, sin nota. El motivo se le muestra al estudiante.
 * Devuelve [bool ok, string mensaje].
 */
function submission_return_to_student(PDO $pdo, array $assignment, int $submissionId, string $note): array
{
    if (!empty($assignment['activity_id']) || !empty($assignment['quiz_id'])) {
        return [false, 'Solo se pueden devolver trabajos de creación (resumen, tabla, infografía, mapa mental, presentación).'];
    }

    $sub = submission_load_for_assignment($pdo, $submissionId, (int) $assignment['id']);
    if (!$sub) {
        return [false, 'Entrega no encontrada.'];
    }

    $pStmt = $pdo->prepare('SELECT id, status FROM student_projects WHERE assignment_id = :aid AND student_id = :sid LIMIT 1');
    $pStmt->execute(['aid' => $assignment['id'], 'sid' => $sub['student_id']]);
    $project = $pStmt->fetch();

    if (!$project || $project['status'] !== 'submitted') {
        return [false, $sub['student_name'] . ' todavía no ha entregado este trabajo, no hay nada que devolver.'];
    }

    $note = mb_substr(trim($note), 0, 1000);
    $now = now_datetime();

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE student_projects SET status = 'draft', submitted_at = NULL, updated_at = :now WHERE id = :id")
            ->execute(['now' => $now, 'id' => $project['id']]);

        // Se quita la nota y la revisión: al reenviar vuelve a quedar "por corregir".
        $pdo->prepare(
            "UPDATE submissions SET status = 'pending', score = NULL, feedback = NULL, completed_at = NULL, reviewed_at = NULL,
                    project_id = NULL, returned_at = :now, return_note = :note
             WHERE id = :id"
        )->execute(['now' => $now, 'note' => $note !== '' ? $note : null, 'id' => $submissionId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('submission_return_to_student: ' . $e->getMessage());
        return [false, 'No se pudo devolver el trabajo. Intenta de nuevo.'];
    }

    return [true, 'Trabajo devuelto a ' . $sub['student_name'] . '. Ya puede corregirlo y volver a enviarlo.'];
}

/**
 * Borra la entrega de un estudiante y todo lo que generó (trabajo, intentos de cuestionario o de juego), dejándola
 * como "no empezada" para que pueda hacerla de nuevo. Sirve para cualquier tipo de asignación.
 * Devuelve [bool ok, string mensaje].
 */
function submission_delete_entry(PDO $pdo, array $assignment, int $submissionId): array
{
    $sub = submission_load_for_assignment($pdo, $submissionId, (int) $assignment['id']);
    if (!$sub) {
        return [false, 'Entrega no encontrada.'];
    }

    $aid = (int) $assignment['id'];
    $sid = (int) $sub['student_id'];

    $pdo->beginTransaction();
    try {
        // Trabajo de creación
        $pdo->prepare('DELETE FROM student_projects WHERE assignment_id = :aid AND student_id = :sid')
            ->execute(['aid' => $aid, 'sid' => $sid]);

        // Cuestionario: intentos con sus preguntas y respuestas (estas tablas no tienen borrado en cascada)
        $attempts = $pdo->prepare('SELECT id FROM quiz_attempts WHERE assignment_id = :aid AND student_id = :sid');
        $attempts->execute(['aid' => $aid, 'sid' => $sid]);
        $attemptIds = array_map('intval', array_column($attempts->fetchAll(), 'id'));
        if (!empty($attemptIds)) {
            $in = implode(',', $attemptIds);
            $pdo->exec("DELETE FROM quiz_attempt_answers WHERE attempt_id IN ($in)");
            $pdo->exec("DELETE FROM quiz_attempt_questions WHERE attempt_id IN ($in)");
            $pdo->exec("DELETE FROM quiz_attempts WHERE id IN ($in)");
        }

        // Ahorcado / Crucigrama
        $pdo->prepare('DELETE FROM word_game_attempts WHERE assignment_id = :aid AND student_id = :sid')
            ->execute(['aid' => $aid, 'sid' => $sid]);

        // La entrega vuelve a su estado inicial
        $pdo->prepare(
            "UPDATE submissions SET status = 'pending', score = NULL, feedback = NULL, completed_at = NULL, reviewed_at = NULL,
                    project_id = NULL, quiz_attempt_id = NULL, game_id = NULL, returned_at = NULL, return_note = NULL
             WHERE id = :id"
        )->execute(['id' => $submissionId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('submission_delete_entry: ' . $e->getMessage());
        return [false, 'No se pudo borrar la entrega. Intenta de nuevo.'];
    }

    return [true, 'Se borró la entrega de ' . $sub['student_name'] . '. Ya puede hacer la asignación de nuevo.'];
}
