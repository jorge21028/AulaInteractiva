<?php
/**
 * availability_helpers.php
 * Disponibilidad de las asignaciones para los estudiantes (Fase 12):
 *
 *   - Antes de la fecha/hora de INICIO la asignación NO existe para el estudiante (solo la ve el profesor).
 *   - Después de la fecha/hora de ENTREGA sigue visible, pero como "vencida": ya no se puede entregar.
 *   - Si el curso está inactivo, el estudiante no ve nada de ese curso.
 *
 * Todas las comparaciones se hacen con la misma hora de la aplicación (now_datetime()),
 * la misma con la que se guardan las fechas, para que no haya desfases entre PHP y MySQL.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

// ---------------------------------------------------------------------------
// Formato de fecha y hora
// ---------------------------------------------------------------------------

/**
 * Convierte el valor de un <input type="datetime-local"> ("2026-10-20T08:30") en un DATETIME de MySQL.
 * Devuelve null si está vacío o no es una fecha válida. También acepta solo la fecha ("2026-10-20"),
 * que se interpreta a las 00:00 (o a las 23:59 si $endOfDay = true).
 */
function dt_from_input(?string $raw, bool $endOfDay = false): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    $raw = str_replace('T', ' ', $raw);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $raw);
        $errors = DateTime::getLastErrors();
        if ($dt && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dt->format('Y-m-d H:i:00');
        }
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $raw);
    $errors = DateTime::getLastErrors();
    if ($dt && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
        return $dt->format('Y-m-d') . ($endOfDay ? ' 23:59:00' : ' 00:00:00');
    }

    return null;
}

/** DATETIME de MySQL -> valor para un <input type="datetime-local">. */
function dt_to_input(?string $mysql): string
{
    if (empty($mysql)) {
        return '';
    }
    $ts = strtotime($mysql);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

/** DATETIME de MySQL -> "20/10/2026 08:30 AM" (vacío si no hay fecha). */
function dt_format(?string $mysql): string
{
    if (empty($mysql)) {
        return '';
    }
    $ts = strtotime($mysql);
    return $ts ? date('d/m/Y h:i A', $ts) : '';
}

// ---------------------------------------------------------------------------
// Estado de una asignación según sus fechas
// ---------------------------------------------------------------------------

/** ¿Ya llegó la fecha/hora de inicio? (sin fecha de inicio = disponible desde que se crea). */
function assignment_has_started(array $assignment, ?string $now = null): bool
{
    $now = $now ?? now_datetime();
    return empty($assignment['start_date']) || $assignment['start_date'] <= $now;
}

/** ¿Ya pasó la fecha/hora de entrega? (sin fecha de entrega nunca vence). */
function assignment_is_past_due(array $assignment, ?string $now = null): bool
{
    $now = $now ?? now_datetime();
    return !empty($assignment['due_date']) && $assignment['due_date'] < $now;
}

/**
 * Estado visto desde el profesor: 'scheduled' (aún no inicia), 'open' o 'closed' (ya venció).
 */
function assignment_window_state(array $assignment, ?string $now = null): string
{
    if (!assignment_has_started($assignment, $now)) {
        return 'scheduled';
    }
    return assignment_is_past_due($assignment, $now) ? 'closed' : 'open';
}

/**
 * ¿Está vencida PARA ESTE ESTUDIANTE? Un trabajo que el profesor devolvió para corregir
 * (submissions.returned_at con la entrega otra vez pendiente) se considera reabierto: el profesor
 * lo pidió expresamente, así que el plazo original ya no lo bloquea.
 */
function assignment_overdue_for_student(array $assignment, array $submission, ?string $now = null): bool
{
    if (($submission['status'] ?? 'pending') === 'completed') {
        return false;
    }
    if (!empty($submission['returned_at'])) {
        return false;
    }
    return assignment_is_past_due($assignment, $now);
}

// ---------------------------------------------------------------------------
// Bloqueo del trabajo de creación del estudiante (editores y API de proyectos)
// ---------------------------------------------------------------------------

/**
 * Motivo por el que el estudiante YA NO puede guardar/subir/entregar el trabajo de una asignación,
 * o null si todavía puede. $assignmentId null = trabajo libre (sin asignación): nunca se bloquea.
 */
function assignment_lock_reason(PDO $pdo, ?int $assignmentId, int $studentId): ?string
{
    if (!$assignmentId) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT a.start_date, COALESCE(ax.due_date, a.due_date) AS due_date, c.is_active, sub.status AS sub_status, sub.returned_at
         FROM assignments a
         INNER JOIN subjects s ON s.id = a.subject_id
         INNER JOIN courses c ON c.id = s.course_id
         LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = :sid
         LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = :sid2
         WHERE a.id = :aid LIMIT 1'
    );
    $stmt->execute(['aid' => $assignmentId, 'sid' => $studentId, 'sid2' => $studentId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }
    if ((int) $row['is_active'] !== 1) {
        return 'Este curso ya no está activo.';
    }
    if (!assignment_has_started($row)) {
        return 'Esta asignación todavía no está disponible.';
    }

    $submission = ['status' => $row['sub_status'] ?? 'pending', 'returned_at' => $row['returned_at'] ?? null];
    if (assignment_overdue_for_student($row, $submission)) {
        return 'La fecha de entrega ya venció: no se pueden hacer más cambios ni entregas.';
    }

    return null;
}

// ---------------------------------------------------------------------------
// Cursos activos / inactivos
// ---------------------------------------------------------------------------

/** Cursos de un profesor, activos o inactivos, con cuántas asignaturas y estudiantes tienen. */
function courses_for_teacher(PDO $pdo, int $teacherId, bool $active): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.is_active, c.deactivated_at, c.school_year,
            (SELECT COUNT(*) FROM subjects s WHERE s.course_id = c.id) AS subjects_count,
            (SELECT COUNT(DISTINCT cs.student_id) FROM course_students cs WHERE cs.course_id = c.id) AS students_count
         FROM courses c
         INNER JOIN teacher_courses tc ON tc.course_id = c.id
         WHERE tc.teacher_id = :teacher_id AND c.is_active = :active
         ORDER BY c.name ASC'
    );
    $stmt->execute(['teacher_id' => $teacherId, 'active' => $active ? 1 : 0]);
    return $stmt->fetchAll();
}

/** Cuántos cursos inactivos tiene el profesor (para el botón del panel). */
function courses_inactive_count(PDO $pdo, int $teacherId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total FROM courses c
         INNER JOIN teacher_courses tc ON tc.course_id = c.id
         WHERE tc.teacher_id = :teacher_id AND c.is_active = 0'
    );
    $stmt->execute(['teacher_id' => $teacherId]);
    return (int) ($stmt->fetch()['total'] ?? 0);
}

/** Activa o desactiva un curso del profesor. Devuelve false si el curso no es suyo. */
function course_set_active(PDO $pdo, int $courseId, int $teacherId, bool $active): bool
{
    $own = $pdo->prepare('SELECT 1 FROM teacher_courses WHERE course_id = :cid AND teacher_id = :tid');
    $own->execute(['cid' => $courseId, 'tid' => $teacherId]);
    if (!$own->fetch()) {
        return false;
    }

    $pdo->prepare('UPDATE courses SET is_active = :active, deactivated_at = :when WHERE id = :id')
        ->execute([
            'active' => $active ? 1 : 0,
            'when'   => $active ? null : now_datetime(),
            'id'     => $courseId,
        ]);
    return true;
}


// ---------------------------------------------------------------------------
// Prórroga individual (fecha de entrega especial para un estudiante)
// ---------------------------------------------------------------------------

/** Estudiantes de una asignación con su prórroga (si tienen) y su estado de entrega. */
function extensions_for_assignment(PDO $pdo, int $assignmentId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id AS student_id, u.name, u.email, sub.status, sub.returned_at, ax.due_date AS ext_due_date, ax.note AS ext_note
         FROM submissions sub
         INNER JOIN users u ON u.id = sub.student_id
         LEFT JOIN assignment_extensions ax ON ax.assignment_id = sub.assignment_id AND ax.student_id = sub.student_id
         WHERE sub.assignment_id = :aid
         ORDER BY u.name ASC'
    );
    $stmt->execute(['aid' => $assignmentId]);
    return $stmt->fetchAll();
}

/**
 * Da una prórroga (nueva fecha de entrega) a los estudiantes indicados. Solo estudiantes que tienen esa asignación.
 * Devuelve cuántos se actualizaron.
 */
function extension_set(PDO $pdo, int $assignmentId, array $studentIds, string $dueDate, ?string $note, int $byUserId): int
{
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (empty($studentIds)) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $valid = $pdo->prepare("SELECT student_id FROM submissions WHERE assignment_id = ? AND student_id IN ($in)");
    $valid->execute(array_merge([$assignmentId], $studentIds));
    $ids = array_map('intval', array_column($valid->fetchAll(), 'student_id'));

    $stmt = $pdo->prepare(
        'INSERT INTO assignment_extensions (assignment_id, student_id, due_date, note, created_by, created_at)
         VALUES (:aid, :sid, :due, :note, :by, :now)
         ON DUPLICATE KEY UPDATE due_date = VALUES(due_date), note = VALUES(note), created_by = VALUES(created_by), created_at = VALUES(created_at)'
    );
    foreach ($ids as $sid) {
        $stmt->execute(['aid' => $assignmentId, 'sid' => $sid, 'due' => $dueDate, 'note' => $note !== '' ? $note : null, 'by' => $byUserId, 'now' => now_datetime()]);
    }
    return count($ids);
}

/** Quita la prórroga a los estudiantes indicados (vuelven a la fecha general). */
function extension_clear(PDO $pdo, int $assignmentId, array $studentIds): int
{
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (empty($studentIds)) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $pdo->prepare("DELETE FROM assignment_extensions WHERE assignment_id = ? AND student_id IN ($in)");
    $stmt->execute(array_merge([$assignmentId], $studentIds));
    return $stmt->rowCount();
}
