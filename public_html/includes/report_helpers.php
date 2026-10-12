<?php
/**
 * report_helpers.php
 * Reporte por asignatura: progreso de cada estudiante en todas las asignaciones.
 *
 * Importante: "enviar" y "calificar" son cosas distintas. El profesor puede poner una nota a alguien que nunca
 * entregó nada (nota manual). Por eso se mira la EVIDENCIA real de entrega según el tipo de asignación:
 *   - Trabajo de creación        -> el trabajo está entregado (project_id)
 *   - Cuestionario               -> existe un intento presentado
 *   - Ahorcado / Crucigrama      -> el intento está completado
 *   - Actividad en vivo (trivia) -> jugó una partida que se sincronizó (game_id)
 *
 * Estado de cada estudiante en cada asignación:
 *   graded   = enviada y calificada
 *   sent     = enviada, todavía sin calificar
 *   manual   = calificada pero el estudiante NO la envió (nota puesta por el profesor)
 *   returned = devuelta para corregir, aún sin reenviar
 *   pending  = no enviada ni calificada
 *   none     = la asignación no es para este estudiante (asignaciones "solo para algunos")
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const REPORT_STATE_LABELS = [
    'graded'   => 'Enviada y calificada',
    'sent'     => 'Enviada, sin calificar',
    'manual'   => 'Calificada sin que el estudiante la enviara',
    'returned' => 'Devuelta para corregir',
    'pending'  => 'No enviada',
    'none'     => 'No asignada a este estudiante',
];

const REPORT_STATE_ICONS = [
    'graded' => '✅', 'sent' => '📨', 'manual' => '⚠️', 'returned' => '↩️', 'pending' => '⏳', 'none' => '–',
];

/** ¿El estudiante realmente envió esta asignación? (no depende de que tenga nota) */
function report_is_delivered(array $assignment, array $row): bool
{
    if (!empty($assignment['quiz_id'])) {
        return (int) $row['quiz_done'] > 0 || !empty($row['quiz_attempt_id']);
    }
    if (!empty($assignment['activity_id'])) {
        if (in_array($assignment['game_mode'] ?? null, ['ahorcado', 'crucigrama'], true)) {
            return ($row['wg_status'] ?? null) === 'completed';
        }
        return !empty($row['game_id']);
    }
    return !empty($row['project_id']) && ($row['project_status'] ?? 'submitted') === 'submitted';
}

function report_cell_state(bool $delivered, bool $graded, bool $returned): string
{
    if ($delivered) {
        return $graded ? 'graded' : 'sent';
    }
    if ($graded) {
        return 'manual';
    }
    return $returned ? 'returned' : 'pending';
}

/** Número de nota sin ceros sobrantes: 8.5 -> "8,5", 10.00 -> "10". */
function report_fmt_score(?float $n): string
{
    if ($n === null) {
        return '';
    }
    return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
}

/**
 * Carga el reporte completo de una asignatura.
 * Devuelve ['assignments' => [...], 'students' => [id => [...]], 'totals' => [...]].
 */
function report_load(PDO $pdo, int $subjectId, int $teacherId, int $courseId): array
{
    // Asignaciones del profesor en la asignatura (de la más antigua a la más reciente)
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.points, a.due_date, a.project_type, a.activity_id, a.quiz_id, a.audience, a.created_at,
                act.game_mode, act.title AS activity_title
         FROM assignments a
         LEFT JOIN activities act ON act.id = a.activity_id
         WHERE a.subject_id = :sid AND a.teacher_id = :tid
         ORDER BY a.created_at ASC, a.id ASC'
    );
    $stmt->execute(['sid' => $subjectId, 'tid' => $teacherId]);
    $assignments = [];
    foreach ($stmt->fetchAll() as $a) {
        $assignments[(int) $a['id']] = $a;
    }

    // Estudiantes inscritos actualmente en el curso
    $students = [];
    $cs = $pdo->prepare(
        'SELECT u.id, u.name, u.email FROM course_students c INNER JOIN users u ON u.id = c.student_id
         WHERE c.course_id = :cid ORDER BY u.name ASC'
    );
    $cs->execute(['cid' => $courseId]);
    foreach ($cs->fetchAll() as $s) {
        $students[(int) $s['id']] = ['id' => (int) $s['id'], 'name' => $s['name'], 'email' => $s['email'], 'enrolled' => true, 'cells' => []];
    }

    if (!empty($assignments)) {
        $ids = implode(',', array_keys($assignments));
        $sub = $pdo->query(
            "SELECT sub.assignment_id, sub.student_id, sub.status, sub.score, sub.feedback, sub.completed_at, sub.reviewed_at,
                    sub.returned_at, sub.project_id, sub.quiz_attempt_id, sub.game_id,
                    sp.status AS project_status,
                    wga.status AS wg_status,
                    (SELECT COUNT(*) FROM quiz_attempts qa
                      WHERE qa.assignment_id = sub.assignment_id AND qa.student_id = sub.student_id AND qa.status <> 'in_progress') AS quiz_done,
                    u.name AS student_name, u.email AS student_email
             FROM submissions sub
             INNER JOIN users u ON u.id = sub.student_id
             LEFT JOIN student_projects sp ON sp.assignment_id = sub.assignment_id AND sp.student_id = sub.student_id
             LEFT JOIN word_game_attempts wga ON wga.assignment_id = sub.assignment_id AND wga.student_id = sub.student_id
             WHERE sub.assignment_id IN ($ids)"
        )->fetchAll();

        foreach ($sub as $row) {
            $sid = (int) $row['student_id'];
            if (!isset($students[$sid])) {
                // Tuvo asignaciones en esta asignatura pero ya no está inscrito: se conserva su historial
                $students[$sid] = ['id' => $sid, 'name' => $row['student_name'], 'email' => $row['student_email'], 'enrolled' => false, 'cells' => []];
            }
            $a = $assignments[(int) $row['assignment_id']];
            $delivered = report_is_delivered($a, $row);
            $graded = $row['score'] !== null;
            $returned = !empty($row['returned_at']);

            $students[$sid]['cells'][(int) $a['id']] = [
                'state'     => report_cell_state($delivered, $graded, $returned),
                'delivered' => $delivered,
                'graded'    => $graded,
                'score'     => $graded ? (float) $row['score'] : null,
                'points'    => (int) $a['points'],
                'feedback'  => (string) ($row['feedback'] ?? ''),
                'sent_at'   => $row['completed_at'],
                'returned_note' => $returned ? 1 : 0,
            ];
        }
    }

    // Totales por estudiante y de toda la asignatura
    $totals = ['assigned' => 0, 'delivered' => 0, 'sent' => 0, 'manual' => 0, 'graded' => 0, 'pending' => 0];
    foreach ($students as $sid => &$st) {
        $c = ['assigned' => 0, 'delivered' => 0, 'not_delivered' => 0, 'graded' => 0, 'ungraded' => 0, 'sent' => 0, 'manual' => 0, 'pct_sum' => 0.0, 'pct_n' => 0];
        foreach ($st['cells'] as $cell) {
            $c['assigned']++;
            if ($cell['delivered']) {
                $c['delivered']++;
            } else {
                $c['not_delivered']++;
            }
            if ($cell['graded']) {
                $c['graded']++;
                if ($cell['points'] > 0) {
                    $c['pct_sum'] += $cell['score'] / $cell['points'] * 100;
                    $c['pct_n']++;
                }
            } else {
                $c['ungraded']++;
            }
            if ($cell['state'] === 'sent') {
                $c['sent']++;
            }
            if ($cell['state'] === 'manual') {
                $c['manual']++;
            }
        }
        $c['avg'] = $c['pct_n'] > 0 ? $c['pct_sum'] / $c['pct_n'] : null;
        $c['progress'] = $c['assigned'] > 0 ? $c['delivered'] / $c['assigned'] * 100 : null;
        $st['stats'] = $c;

        $totals['assigned'] += $c['assigned'];
        $totals['delivered'] += $c['delivered'];
        $totals['sent'] += $c['sent'];
        $totals['manual'] += $c['manual'];
        $totals['graded'] += $c['graded'];
        $totals['pending'] += $c['not_delivered'];
    }
    unset($st);

    return ['assignments' => $assignments, 'students' => $students, 'totals' => $totals];
}

/** Evita que Excel interprete un texto como fórmula (=, +, -, @). */
function report_csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/** Texto de una celda para el CSV. */
function report_csv_cell(?array $cell): string
{
    if ($cell === null) {
        return '—';
    }
    $score = $cell['score'] !== null ? report_fmt_score($cell['score']) . '/' . $cell['points'] : '';
    return match ($cell['state']) {
        'graded'   => 'Enviada y calificada ' . $score,
        'sent'     => 'Enviada, sin calificar',
        'manual'   => 'Calificada sin envío del estudiante ' . $score,
        'returned' => 'Devuelta para corregir',
        default    => 'No enviada',
    };
}
