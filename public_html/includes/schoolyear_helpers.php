<?php
/**
 * schoolyear_helpers.php
 * Año escolar de los cursos (courses.school_year, p. ej. "2026-2027"): permite cerrar (pasar a inactivos) o
 * reabrir todos los cursos de un año de una sola vez. El año escolar empieza en agosto.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/** Año escolar que corresponde a una fecha (agosto-diciembre: año-año+1; enero-julio: año-1-año). */
function school_year_current(?int $ts = null): string
{
    $ts = $ts ?? time();
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    return $m >= 8 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y;
}

/** Normaliza lo que escribió el usuario ("2026/2027", "2026 - 2027") a "2026-2027". null si no es válido. */
function school_year_normalize(?string $raw): ?string
{
    if (preg_match('/^\s*(\d{4})\s*[-\/]\s*(\d{4})\s*$/', (string) $raw, $m) && (int) $m[2] === (int) $m[1] + 1) {
        return $m[1] . '-' . $m[2];
    }
    return null;
}

/** Años escolares de los cursos del profesor con cuántos cursos activos/inactivos tiene cada uno. */
function school_year_summary(PDO $pdo, int $teacherId): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(c.school_year, '') AS school_year,
                SUM(c.is_active = 1) AS active_count, SUM(c.is_active = 0) AS inactive_count
         FROM courses c INNER JOIN teacher_courses tc ON tc.course_id = c.id
         WHERE tc.teacher_id = :tid
         GROUP BY COALESCE(c.school_year, '') ORDER BY school_year DESC"
    );
    $stmt->execute(['tid' => $teacherId]);
    return $stmt->fetchAll();
}

/** Nombres de los cursos del profesor de un año (para mostrar qué se va a cerrar). */
function school_year_courses(PDO $pdo, int $teacherId, string $year, bool $active): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name FROM courses c INNER JOIN teacher_courses tc ON tc.course_id = c.id
         WHERE tc.teacher_id = :tid AND COALESCE(c.school_year, '') = :y AND c.is_active = :a ORDER BY c.name"
    );
    $stmt->execute(['tid' => $teacherId, 'y' => $year, 'a' => $active ? 1 : 0]);
    return $stmt->fetchAll();
}

/**
 * Pasa a inactivos (o reactiva) TODOS los cursos del profesor de un año escolar. $teacherId = null: de todos los
 * profesores (administrador). Devuelve cuántos cursos cambiaron.
 */
function school_year_set_active(PDO $pdo, ?int $teacherId, string $year, bool $active): int
{
    $sql = "UPDATE courses c SET c.is_active = :new, c.deactivated_at = :when
            WHERE COALESCE(c.school_year, '') = :y AND c.is_active = :old";
    $params = ['new' => $active ? 1 : 0, 'when' => $active ? null : now_datetime(), 'y' => $year, 'old' => $active ? 0 : 1];
    if ($teacherId !== null) {
        $sql .= ' AND EXISTS (SELECT 1 FROM teacher_courses tc WHERE tc.course_id = c.id AND tc.teacher_id = :tid)';
        $params['tid'] = $teacherId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
