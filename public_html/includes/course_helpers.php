<?php
/**
 * course_helpers.php
 * Lógica compartida de inscripción a cursos (Fase 10: auto-matrícula por código).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/**
 * Inscribe a un estudiante en un curso (si no lo estaba ya) y, de paso,
 * le crea la entrega ("pending") de cualquier asignación que ya existiera
 * en las asignaturas de ese curso — así un estudiante que se une tarde
 * también ve las tareas pendientes, en vez de perdérselas.
 *
 * Se usa tanto desde la inscripción manual del profesor (por correo) como
 * desde la auto-matrícula del estudiante (por código).
 *
 * @return array{success: bool, message: string, already_enrolled: bool}
 */
function course_enroll_student(PDO $pdo, int $courseId, int $studentId): array
{
    $check = $pdo->prepare('SELECT 1 FROM course_students WHERE course_id = :course_id AND student_id = :student_id');
    $check->execute(['course_id' => $courseId, 'student_id' => $studentId]);

    if ($check->fetch()) {
        return ['success' => false, 'message' => 'Ya estás inscrito en este curso.', 'already_enrolled' => true];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO course_students (course_id, student_id, enrolled_at) VALUES (:course_id, :student_id, :enrolled_at)')
            ->execute(['course_id' => $courseId, 'student_id' => $studentId, 'enrolled_at' => now_datetime()]);

        // Relleno: asignaciones ya existentes en las asignaturas de este curso.
        $assignStmt = $pdo->prepare(
            'SELECT a.id FROM assignments a
             INNER JOIN subjects s ON s.id = a.subject_id
             WHERE s.course_id = :course_id'
        );
        $assignStmt->execute(['course_id' => $courseId]);
        $assignmentIds = $assignStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($assignmentIds as $assignmentId) {
            $existsStmt = $pdo->prepare('SELECT 1 FROM submissions WHERE assignment_id = :aid AND student_id = :sid');
            $existsStmt->execute(['aid' => $assignmentId, 'sid' => $studentId]);
            if ($existsStmt->fetch()) {
                continue;
            }

            $pdo->prepare('INSERT IGNORE INTO assignment_students (assignment_id, student_id) VALUES (:aid, :sid)')
                ->execute(['aid' => $assignmentId, 'sid' => $studentId]);
            $pdo->prepare(
                "INSERT INTO submissions (assignment_id, student_id, status, created_at) VALUES (:aid, :sid, 'pending', :created_at)"
            )->execute(['aid' => $assignmentId, 'sid' => $studentId, 'created_at' => now_datetime()]);
        }

        $pdo->commit();
        return ['success' => true, 'message' => 'Inscripción exitosa.', 'already_enrolled' => false];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('course_enroll_student error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'No fue posible completar la inscripción.', 'already_enrolled' => false];
    }
}

/**
 * Genera un código de auto-matrícula único (6 caracteres, sin letras/números confundibles como 0, O, 1, I).
 * Lo usan teacher/course.php (primera visita del curso y "Regenerar código").
 */
if (!function_exists('generate_course_code')) {
    function generate_course_code(PDO $pdo, int $length = 6): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $check = $pdo->prepare('SELECT 1 FROM courses WHERE enrollment_code = :code LIMIT 1');

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            $check->execute(['code' => $code]);
            if (!$check->fetch()) {
                return $code;
            }
        }
        // Muy improbable: se alargan los intentos con un caracter más para evitar colisiones.
        return generate_course_code($pdo, min($length + 1, 10));
    }
}

/**
 * Busca un curso por su código de auto-matrícula.
 */
function course_find_by_code(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE enrollment_code = :code LIMIT 1');
    $stmt->execute(['code' => strtoupper(trim($code))]);
    return $stmt->fetch() ?: null;
}

/**
 * Quita a un estudiante de un curso (y por lo tanto de todas sus
 * asignaturas, ya que la inscripción es a nivel curso). Se conserva el
 * historial de trabajo ya calificado (submissions 'completed'), pero se
 * eliminan las tareas pendientes de ese curso que ya no le corresponden.
 */
function course_unenroll_student(PDO $pdo, int $courseId, int $studentId): void
{
    $pdo->beginTransaction();
    try {
        $assignStmt = $pdo->prepare(
            'SELECT a.id FROM assignments a
             INNER JOIN subjects s ON s.id = a.subject_id
             WHERE s.course_id = :course_id'
        );
        $assignStmt->execute(['course_id' => $courseId]);
        $assignmentIds = $assignStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($assignmentIds)) {
            $in = implode(',', array_fill(0, count($assignmentIds), '?'));

            $delSub = $pdo->prepare(
                "DELETE FROM submissions WHERE student_id = ? AND status = 'pending' AND assignment_id IN ($in)"
            );
            $delSub->execute(array_merge([$studentId], $assignmentIds));

            $delAsg = $pdo->prepare(
                "DELETE ast FROM assignment_students ast
                 LEFT JOIN submissions sub ON sub.assignment_id = ast.assignment_id AND sub.student_id = ast.student_id
                 WHERE ast.student_id = ? AND sub.id IS NULL AND ast.assignment_id IN ($in)"
            );
            $delAsg->execute(array_merge([$studentId], $assignmentIds));
        }

        $pdo->prepare('DELETE FROM course_students WHERE course_id = :course_id AND student_id = :student_id')
            ->execute(['course_id' => $courseId, 'student_id' => $studentId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('course_unenroll_student error: ' . $e->getMessage());
    }
}
