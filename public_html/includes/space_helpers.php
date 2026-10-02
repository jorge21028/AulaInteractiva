<?php
/**
 * space_helpers.php
 * Espacios estilo Moodle para el profesor: Curso (mosaico de asignaturas) → Asignatura (asignaciones con su estado).
 *
 * Estados de una asignación (se calculan con las entregas de los estudiantes):
 *   por corregir  = hay trabajos entregados que el profesor aún no revisó (solo trabajos de creación;
 *                   trivia, sapito, cuestionarios y juegos de palabras se autocalifican)
 *   pendiente     = hay estudiantes que todavía no entregan
 *   realizada     = ya entregaron todos y no queda nada por corregir
 * Una misma asignación puede estar "por corregir" y "pendiente" a la vez.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/game_helpers.php';
require_once __DIR__ . '/project_helpers.php';

const SPACE_PALETTE = [
    ['#0878F9', '#7B2CFF'], ['#00B894', '#0984E3'], ['#F59E0B', '#EF4444'], ['#7B2CFF', '#EC4899'],
    ['#06B6D4', '#2563EB'], ['#EC4899', '#F97316'], ['#334155', '#0EA5E9'], ['#16A34A', '#0EA5E9'],
];

/** Degradado estable según el id (cada curso/asignatura conserva siempre su color). */
function space_gradient(int $id): string
{
    $p = SPACE_PALETTE[$id % count(SPACE_PALETTE)];
    return "linear-gradient(135deg, {$p[0]} 0%, {$p[1]} 100%)";
}

/**
 * Asignaciones del profesor con sus conteos, opcionalmente de un curso o de una asignatura.
 * Cada fila: datos de la asignación + total, completed, needs_grading.
 */
function space_assignment_rows(PDO $pdo, int $teacherId, ?int $courseId = null, ?int $subjectId = null): array
{
    $sql = 'SELECT a.id, a.title, a.due_date, a.points, a.project_type, a.activity_id, a.quiz_id, a.created_at, a.subject_id,
                   s.name AS subject_name, s.course_id, act.title AS activity_title, act.game_mode, qz.title AS quiz_title,
                   COUNT(sub.id) AS total,
                   COALESCE(SUM(sub.status = \'completed\'), 0) AS completed,
                   COALESCE(SUM(sub.status = \'completed\' AND sub.project_id IS NOT NULL AND sub.reviewed_at IS NULL), 0) AS needs_grading
            FROM assignments a
            INNER JOIN subjects s ON s.id = a.subject_id
            LEFT JOIN activities act ON act.id = a.activity_id
            LEFT JOIN quizzes qz ON qz.id = a.quiz_id
            LEFT JOIN submissions sub ON sub.assignment_id = a.id
            WHERE a.teacher_id = :teacher_id';
    $params = ['teacher_id' => $teacherId];
    if ($courseId !== null) {
        $sql .= ' AND s.course_id = :course_id';
        $params['course_id'] = $courseId;
    }
    if ($subjectId !== null) {
        $sql .= ' AND a.subject_id = :subject_id';
        $params['subject_id'] = $subjectId;
    }
    $sql .= ' GROUP BY a.id ORDER BY a.created_at DESC, a.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['total'] = (int) $r['total'];
        $r['completed'] = (int) $r['completed'];
        $r['needs_grading'] = (int) $r['needs_grading'];
        $r['graded'] = max(0, $r['completed'] - $r['needs_grading']);
        $r['pending'] = max(0, $r['total'] - $r['completed']);
        $r['is_needs'] = $r['needs_grading'] > 0;
        $r['is_pending'] = $r['pending'] > 0;
        $r['is_done'] = $r['total'] > 0 && $r['pending'] === 0 && $r['needs_grading'] === 0;
        $r['is_overdue'] = $r['is_pending'] && !empty($r['due_date']) && strtotime($r['due_date']) < time();
    }
    unset($r);

    return $rows;
}

/** Suma los conteos de una lista de asignaciones. */
function space_summarize(array $rows): array
{
    $sum = [
        'assignments' => count($rows), 'needs_assignments' => 0, 'pending_assignments' => 0, 'done_assignments' => 0,
        'needs_submissions' => 0, 'pending_submissions' => 0, 'graded_submissions' => 0, 'total_submissions' => 0,
    ];
    foreach ($rows as $r) {
        $sum['needs_assignments'] += $r['is_needs'] ? 1 : 0;
        $sum['pending_assignments'] += $r['is_pending'] ? 1 : 0;
        $sum['done_assignments'] += $r['is_done'] ? 1 : 0;
        $sum['needs_submissions'] += $r['needs_grading'];
        $sum['pending_submissions'] += $r['pending'];
        $sum['graded_submissions'] += $r['graded'];
        $sum['total_submissions'] += $r['total'];
    }
    return $sum;
}

/** Agrupa filas por clave ('subject_id' o 'course_id') y resume cada grupo. */
function space_summarize_by(array $rows, string $key): array
{
    $groups = [];
    foreach ($rows as $r) {
        $groups[(int) $r[$key]][] = $r;
    }
    return array_map('space_summarize', $groups);
}

/** Chips de estado (HTML seguro) para un resumen: por calificar / con entregas pendientes / al día. */
function space_status_chips(array $sum): string
{
    $html = '';
    if ($sum['assignments'] === 0) {
        return '<span class="chip chip-gray">Sin asignaciones</span>';
    }
    if ($sum['needs_assignments'] > 0) {
        $n = $sum['needs_assignments'];
        $html .= '<span class="chip chip-red">🔴 ' . $n . ' por calificar</span>';
    }
    if ($sum['pending_assignments'] > 0) {
        $n = $sum['pending_assignments'];
        $html .= '<span class="chip chip-amber">⏳ ' . $n . ($n === 1 ? ' con entregas pendientes' : ' con entregas pendientes') . '</span>';
    }
    if ($sum['needs_assignments'] === 0 && $sum['pending_assignments'] === 0) {
        $html .= '<span class="chip chip-green">✅ Al día</span>';
    }
    return $html;
}

/** Icono + etiqueta del tipo de asignación. */
function space_assignment_kind(array $a): array
{
    if (!empty($a['activity_title'])) {
        return [game_mode_icon($a['game_mode'] ?? 'trivia'), $a['activity_title'] . ' · ' . game_mode_label($a['game_mode'] ?? 'trivia')];
    }
    if (!empty($a['quiz_title'])) {
        return ['📝', 'Cuestionario · ' . $a['quiz_title']];
    }
    return ['✏️', PROJECT_TYPES[$a['project_type']] ?? 'Trabajo de creación'];
}

/** Verifica que la asignatura pertenece a un curso del profesor. Devuelve la fila con datos del curso o null. */
function space_load_subject(PDO $pdo, int $subjectId, int $teacherId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name, s.course_id, c.name AS course_name
         FROM subjects s
         INNER JOIN courses c ON c.id = s.course_id
         INNER JOIN teacher_courses tc ON tc.course_id = c.id
         WHERE s.id = :sid AND tc.teacher_id = :tid LIMIT 1'
    );
    $stmt->execute(['sid' => $subjectId, 'tid' => $teacherId]);
    return $stmt->fetch() ?: null;
}
