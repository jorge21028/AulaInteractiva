<?php
/**
 * autosubmit_helpers.php — Envío automático al terminar el tiempo (Fase 23).
 *
 * Cuando se acaba el tiempo, lo que el estudiante tenía hecho se envía solo y se califica/registra con eso:
 *  - Cuestionarios: se cierra el intento con las respuestas que ya había marcado (se guardan mientras contesta).
 *  - Juegos de palabras (ahorcado, crucigrama, sopa de letras, emparejar): se califica lo que alcanzó a resolver.
 *  - Trabajos de creación en borrador: se entregan tal cual estaban (los que no tienen nada escrito no se envían).
 * El hosting gratuito no tiene tareas programadas, así que el barrido se hace "perezosamente": cada vez que alguien
 * abre una página (como mucho una vez por minuto), y de inmediato en la pantalla del profesor y del estudiante
 * afectado. Opcionalmente un servicio de cron gratuito puede llamar a api/cron/sweep.php cada 5 minutos.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/quiz_helpers.php';
require_once __DIR__ . '/project_helpers.php';
require_once __DIR__ . '/word_play_helpers.php';

/** Margen para que el navegador alcance a enviar lo último antes de que el servidor cierre por su cuenta. */
const AUTOSUBMIT_GRACE_SECONDS = 20;
const AUTOSUBMIT_THROTTLE_SECONDS = 60;

/**
 * Cierra todo lo vencido (opcionalmente solo de una asignación y/o de un estudiante).
 * Devuelve ['quizzes' => n, 'games' => n, 'projects' => n].
 */
function autosubmit_sweep(PDO $pdo, ?int $assignmentId = null, ?int $studentId = null): array
{
    $done = ['quizzes' => 0, 'games' => 0, 'projects' => 0];
    $cut = date('Y-m-d H:i:s', time() - AUTOSUBMIT_GRACE_SECONDS);

    try {
        // ---- Cuestionarios: venció el tiempo del intento (o, si no tiene cronómetro, la fecha de entrega)
        $sql = "SELECT qa.id FROM quiz_attempts qa
                LEFT JOIN assignments a ON a.id = qa.assignment_id
                LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = qa.student_id
                WHERE qa.status = 'in_progress' AND (
                      (qa.expires_at IS NOT NULL AND qa.expires_at < :cut)
                   OR (qa.expires_at IS NULL AND COALESCE(ax.due_date, a.due_date) IS NOT NULL AND COALESCE(ax.due_date, a.due_date) < :cut2))";
        $params = ['cut' => $cut, 'cut2' => $cut];
        if ($assignmentId !== null) { $sql .= ' AND qa.assignment_id = :aid'; $params['aid'] = $assignmentId; }
        if ($studentId !== null) { $sql .= ' AND qa.student_id = :sid'; $params['sid'] = $studentId; }
        $stmt = $pdo->prepare($sql . ' LIMIT 200');
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            quiz_finalize_attempt($pdo, (int) $r['id'], true);
            $done['quizzes']++;
        }

        // ---- Juegos de palabras: venció la fecha de entrega con el intento a medias
        $sql = "SELECT wga.* FROM word_game_attempts wga
                INNER JOIN assignments a ON a.id = wga.assignment_id
                LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = wga.student_id
                WHERE wga.status = 'in_progress' AND COALESCE(ax.due_date, a.due_date) IS NOT NULL AND COALESCE(ax.due_date, a.due_date) < :cut";
        $params = ['cut' => $cut];
        if ($assignmentId !== null) { $sql .= ' AND wga.assignment_id = :aid'; $params['aid'] = $assignmentId; }
        if ($studentId !== null) { $sql .= ' AND wga.student_id = :sid'; $params['sid'] = $studentId; }
        $stmt = $pdo->prepare($sql . ' LIMIT 200');
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            if (wga_autocomplete_attempt($pdo, $r)) {
                $done['games']++;
            }
        }

        // ---- Trabajos de creación en borrador: venció la fecha (más los días de gracia si permite entregas tardías)
        $deadline = "DATE_ADD(COALESCE(ax.due_date, a.due_date), INTERVAL IF(a.late_allowed = 1, a.late_max_days, 0) DAY)";
        $sql = "SELECT sp.id, sp.student_id, sp.type, sp.data_json, a.template_key, {$deadline} AS deadline
                FROM student_projects sp
                INNER JOIN assignments a ON a.id = sp.assignment_id
                LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = sp.student_id
                WHERE sp.status = 'draft' AND a.project_type IS NOT NULL AND a.project_type <> 'foro'
                  AND COALESCE(ax.due_date, a.due_date) IS NOT NULL AND {$deadline} < :cut
                  AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id = sp.assignment_id AND s.student_id = sp.student_id AND s.returned_at IS NOT NULL)";
        $params = ['cut' => $cut];
        if ($assignmentId !== null) { $sql .= ' AND sp.assignment_id = :aid'; $params['aid'] = $assignmentId; }
        if ($studentId !== null) { $sql .= ' AND sp.student_id = :sid'; $params['sid'] = $studentId; }
        $stmt = $pdo->prepare($sql . ' LIMIT 200');
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $files = 0;
            if ($r['type'] === 'archivos') {
                require_once __DIR__ . '/project_files_helpers.php';
                $files = project_files_summary($pdo, (int) $r['id'])['count'];
            }
            // Un borrador vacío (o intacto desde la plantilla) no se envía: no hay nada que entregar.
            if (!project_has_content($r['type'], $r['data_json'], $files, $r['template_key'] ?? null)) {
                continue;
            }
            if (project_submit($pdo, (int) $r['id'], (int) $r['student_id'], (string) $r['deadline'])) {
                $done['projects']++;
            }
        }
    } catch (Throwable $e) {
        // Nunca romper la página que disparó el barrido (p. ej. si aún no se ejecutó el SQL de la fase)
        error_log('autosubmit_sweep: ' . $e->getMessage());
    }
    return $done;
}

/** Barrido global, como mucho una vez por minuto (lo dispara cualquier página con encabezado). */
function autosubmit_maybe_sweep(PDO $pdo): void
{
    $stamp = rtrim(UPLOAD_DIR, '/') . '/.autosubmit_last';
    $last = is_file($stamp) ? (int) @filemtime($stamp) : 0;
    if (time() - $last < AUTOSUBMIT_THROTTLE_SECONDS) {
        return;
    }
    @touch($stamp);   // se marca primero: si hay muchas visitas a la vez, solo una hace el barrido
    autosubmit_sweep($pdo);
}
