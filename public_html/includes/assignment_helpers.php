<?php
/**
 * assignment_helpers.php
 * Lógica compartida del sistema de asignaciones y entregas (Fase 4).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/**
 * Crea una asignación y la reparte automáticamente entre todos los
 * estudiantes actualmente inscritos en el curso de esa asignatura,
 * creando una entrega en estado 'pending' para cada uno.
 */
function assignment_create(
    PDO $pdo,
    int $teacherId,
    int $subjectId,
    ?int $activityId,
    string $title,
    string $description,
    ?string $startDate,
    ?string $dueDate,
    int $points,
    ?string $projectType = null
): int {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO assignments (teacher_id, subject_id, activity_id, project_type, title, description, start_date, due_date, points, created_at)
             VALUES (:teacher_id, :subject_id, :activity_id, :project_type, :title, :description, :start_date, :due_date, :points, :created_at)'
        );
        $stmt->execute([
            'teacher_id'  => $teacherId,
            'subject_id'  => $subjectId,
            'activity_id' => $activityId,
            'project_type'=> $projectType,
            'title'       => $title,
            'description' => $description,
            'start_date'  => $startDate ?: null,
            'due_date'    => $dueDate ?: null,
            'points'      => $points,
            'created_at'  => now_datetime(),
        ]);
        $assignmentId = (int) $pdo->lastInsertId();

        // Estudiantes inscritos en el curso de esta asignatura
        $studStmt = $pdo->prepare(
            'SELECT cs.student_id FROM subjects s
             INNER JOIN course_students cs ON cs.course_id = s.course_id
             WHERE s.id = :subject_id'
        );
        $studStmt->execute(['subject_id' => $subjectId]);
        $students = $studStmt->fetchAll();

        $insertAS = $pdo->prepare('INSERT INTO assignment_students (assignment_id, student_id) VALUES (:aid, :sid)');
        $insertSub = $pdo->prepare(
            "INSERT INTO submissions (assignment_id, student_id, status, created_at) VALUES (:aid, :sid, 'pending', :created_at)"
        );

        foreach ($students as $s) {
            $insertAS->execute(['aid' => $assignmentId, 'sid' => $s['student_id']]);
            $insertSub->execute(['aid' => $assignmentId, 'sid' => $s['student_id'], 'created_at' => now_datetime()]);
        }

        $pdo->commit();
        return $assignmentId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Se llama cada vez que una partida termina. Busca asignaciones que usen
 * esa misma actividad y autocalifica la entrega de cada estudiante que
 * jugó, según su porcentaje de aciertos escalado al puntaje de la
 * asignación. Nunca sobrescribe una entrega ya calificada manualmente
 * por el profesor (reviewed_at no nulo).
 */
function assignment_sync_from_game(PDO $pdo, int $gameId): void
{
    $gStmt = $pdo->prepare('SELECT * FROM games WHERE id = :id');
    $gStmt->execute(['id' => $gameId]);
    $game = $gStmt->fetch();
    if (!$game) {
        return;
    }

    $activityId = (int) $game['activity_id'];

    $actStmt = $pdo->prepare('SELECT allow_repeat FROM activities WHERE id = :id');
    $actStmt->execute(['id' => $activityId]);
    $allowRepeat = (int) ($actStmt->fetch()['allow_repeat'] ?? 0) === 1;

    $tqStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM activity_questions WHERE activity_id = :activity_id');
    $tqStmt->execute(['activity_id' => $activityId]);
    $totalQuestions = (int) ($tqStmt->fetch()['total'] ?? 0);

    if ($totalQuestions === 0) {
        return;
    }

    $asgStmt = $pdo->prepare(
        'SELECT * FROM assignments WHERE activity_id = :activity_id AND teacher_id = :teacher_id'
    );
    $asgStmt->execute(['activity_id' => $activityId, 'teacher_id' => $game['teacher_id']]);
    $assignments = $asgStmt->fetchAll();

    if (empty($assignments)) {
        return;
    }

    $playersStmt = $pdo->prepare('SELECT id, student_id FROM game_players WHERE game_id = :game_id');
    $playersStmt->execute(['game_id' => $gameId]);
    $players = $playersStmt->fetchAll();

    if (empty($players)) {
        return;
    }

    foreach ($assignments as $assignment) {
        foreach ($players as $player) {
            $correctStmt = $pdo->prepare(
                'SELECT COUNT(*) AS total FROM game_answers WHERE game_id = :game_id AND player_id = :player_id AND is_correct = 1'
            );
            $correctStmt->execute(['game_id' => $gameId, 'player_id' => $player['id']]);
            $correct = (int) ($correctStmt->fetch()['total'] ?? 0);

            $percentage = $correct / $totalQuestions;
            $newScore = round($percentage * (int) $assignment['points'], 2);

            // Asegurar que exista una fila de entrega (por si el estudiante
            // jugó sin haber sido inscrito formalmente en la asignación).
            $subStmt = $pdo->prepare(
                'SELECT * FROM submissions WHERE assignment_id = :aid AND student_id = :sid'
            );
            $subStmt->execute(['aid' => $assignment['id'], 'sid' => $player['student_id']]);
            $submission = $subStmt->fetch();

            if (!$submission) {
                $pdo->prepare(
                    "INSERT INTO submissions (assignment_id, student_id, status, created_at) VALUES (:aid, :sid, 'pending', :created_at)"
                )->execute(['aid' => $assignment['id'], 'sid' => $player['student_id'], 'created_at' => now_datetime()]);
                $subStmt->execute(['aid' => $assignment['id'], 'sid' => $player['student_id']]);
                $submission = $subStmt->fetch();
            }

            // Nunca pisar una calificación ajustada manualmente por el profesor.
            if ($submission['reviewed_at'] !== null) {
                continue;
            }

            // Si ya está completada y no se permite repetir, se conserva el primer intento.
            if ($submission['status'] === 'completed' && !$allowRepeat) {
                continue;
            }

            // Si se permite repetir, solo mejoramos la nota (nunca la bajamos).
            if ($submission['status'] === 'completed' && $allowRepeat && $newScore <= (float) $submission['score']) {
                continue;
            }

            $pdo->prepare(
                "UPDATE submissions SET status = 'completed', score = :score, game_id = :game_id, completed_at = :completed_at
                 WHERE id = :id"
            )->execute([
                'score' => $newScore,
                'game_id' => $gameId,
                'completed_at' => now_datetime(),
                'id' => $submission['id'],
            ]);
        }
    }
}
