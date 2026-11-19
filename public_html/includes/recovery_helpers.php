<?php
/**
 * recovery_helpers.php
 * Grupos de recuperación (ej. "Recuperación RA 1").
 *
 * Cómo funciona:
 *   - Un GRUPO (recovery_groups) pertenece a una asignatura y agrupa actividades de recuperación.
 *   - Cada actividad de recuperación es una asignación normal, copia de la original, dirigida solo a los estudiantes
 *     elegidos (audience = 'selected'), con assignments.recovery_group_id y assignments.source_assignment_id.
 *     Así se reutiliza TODO lo existente: el estudiante la entrega igual, el profesor la califica igual.
 *   - Un estudiante es candidato para una actividad cuando todavía no la entregó (pendiente) o tiene nota 0.
 *
 * Todo es tolerante: si la migración SQL aún no se ejecutó, recovery_ready() es false y las pantallas siguen como antes.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const RECOVERY_TITLE_SUFFIX = ' · Recuperación';

/** ¿Ya existe la estructura de recuperación en la base de datos? */
function recovery_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM assignments LIKE 'recovery_group_id'")->fetch();
        $tbl = $pdo->query("SHOW TABLES LIKE 'recovery_groups'")->fetch();
        $ready = (bool) ($col && $tbl);
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** Fragmento SELECT para traer el grupo de una asignación (o NULL si aún no hay migración). Ej: ", a.recovery_group_id". */
function recovery_col(PDO $pdo, string $alias = 'a'): string
{
    return recovery_ready($pdo) ? ", {$alias}.recovery_group_id" : ', NULL AS recovery_group_id';
}

function recovery_is_assignment(PDO $pdo, int $assignmentId): bool
{
    static $cache = [];
    if (!recovery_ready($pdo) || $assignmentId <= 0) {
        return false;
    }
    if (!isset($cache[$assignmentId])) {
        $st = $pdo->prepare('SELECT recovery_group_id FROM assignments WHERE id = :id');
        $st->execute(['id' => $assignmentId]);
        $cache[$assignmentId] = !empty($st->fetch()['recovery_group_id']);
    }
    return $cache[$assignmentId];
}

/**
 * Filtro SQL para contar/buscar intentos de cuestionario sin mezclar los de la recuperación con los de la asignación original.
 *   - Asignación de recuperación: solo cuentan los intentos hechos desde ESA asignación.
 *   - Asignación normal: no cuentan los intentos hechos desde una recuperación.
 */
function recovery_attempt_scope(PDO $pdo, ?int $assignmentId, string $alias = ''): string
{
    if (!recovery_ready($pdo) || !$assignmentId) {
        return '';
    }
    $p = $alias === '' ? '' : $alias . '.';
    if (recovery_is_assignment($pdo, (int) $assignmentId)) {
        return " AND {$p}assignment_id = " . (int) $assignmentId;
    }
    return " AND ({$p}assignment_id IS NULL OR {$p}assignment_id NOT IN (SELECT id FROM assignments WHERE recovery_group_id IS NOT NULL))";
}

/** Grupo del profesor (o null). */
function recovery_group_load(PDO $pdo, int $groupId, int $teacherId): ?array
{
    if (!recovery_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT rg.*, s.name AS subject_name, s.course_id, c.name AS course_name
         FROM recovery_groups rg
         INNER JOIN subjects s ON s.id = rg.subject_id
         INNER JOIN courses c ON c.id = s.course_id
         WHERE rg.id = :id AND rg.teacher_id = :tid LIMIT 1'
    );
    $st->execute(['id' => $groupId, 'tid' => $teacherId]);
    return $st->fetch() ?: null;
}

/** Grupos de una asignatura con sus conteos. */
function recovery_groups_for_subject(PDO $pdo, int $subjectId, int $teacherId): array
{
    if (!recovery_ready($pdo)) {
        return [];
    }
    $st = $pdo->prepare(
        "SELECT rg.*,
                COUNT(DISTINCT a.id) AS items,
                COUNT(DISTINCT sub.student_id) AS students,
                COUNT(sub.id) AS total,
                COALESCE(SUM(sub.status = 'completed'), 0) AS completed,
                COALESCE(SUM(sub.status = 'completed' AND sub.project_id IS NOT NULL AND sub.reviewed_at IS NULL), 0) AS needs_grading
         FROM recovery_groups rg
         LEFT JOIN assignments a ON a.recovery_group_id = rg.id
         LEFT JOIN submissions sub ON sub.assignment_id = a.id
         WHERE rg.subject_id = :sid AND rg.teacher_id = :tid
         GROUP BY rg.id
         ORDER BY rg.created_at DESC, rg.id DESC"
    );
    $st->execute(['sid' => $subjectId, 'tid' => $teacherId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        foreach (['items', 'students', 'total', 'completed', 'needs_grading'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['pending'] = max(0, $r['total'] - $r['completed']);
        $r['graded'] = max(0, $r['completed'] - $r['needs_grading']);
    }
    unset($r);
    return $rows;
}

/** Crea un grupo. Devuelve su id. */
function recovery_group_create(PDO $pdo, int $teacherId, int $subjectId, string $name, ?string $description, ?string $dueDate): int
{
    $pdo->prepare(
        'INSERT INTO recovery_groups (subject_id, teacher_id, name, description, due_date, created_at)
         VALUES (:s, :t, :n, :d, :due, :c)'
    )->execute([
        's' => $subjectId, 't' => $teacherId, 'n' => $name,
        'd' => ($description !== null && $description !== '') ? $description : null,
        'due' => $dueDate ?: null, 'c' => now_datetime(),
    ]);
    return (int) $pdo->lastInsertId();
}

/** Cambia nombre, descripción y fecha límite. La fecha se aplica a todas las actividades del grupo. */
function recovery_group_update(PDO $pdo, array $group, string $name, ?string $description, ?string $dueDate): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE recovery_groups SET name = :n, description = :d, due_date = :due WHERE id = :id')
            ->execute([
                'n' => $name, 'd' => ($description !== null && $description !== '') ? $description : null,
                'due' => $dueDate ?: null, 'id' => $group['id'],
            ]);
        $pdo->prepare('UPDATE assignments SET due_date = :due WHERE recovery_group_id = :g')
            ->execute(['due' => $dueDate ?: null, 'g' => $group['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Estudiantes de la asignatura con las actividades que NO han realizado o tienen en 0.
 * Devuelve [ ['id','name','email','items'=>[ ['assignment_id','title','points','state'(pending|zero),'already','kind','icon'] ]], ...]
 * Solo trae estudiantes con al menos una actividad candidata (ya agregada o no).
 */
function recovery_candidates(PDO $pdo, int $subjectId, int $teacherId, int $groupId = 0): array
{
    require_once __DIR__ . '/space_helpers.php';

    $st = $pdo->prepare(
        "SELECT a.id AS assignment_id, a.title, a.points, a.due_date, a.project_type, a.activity_id, a.quiz_id,
                act.title AS activity_title, act.game_mode, qz.title AS quiz_title,
                sub.student_id, sub.status, sub.score,
                u.name AS student_name, u.email AS student_email,
                EXISTS (SELECT 1 FROM assignments r INNER JOIN submissions rs ON rs.assignment_id = r.id
                        WHERE r.recovery_group_id = :g AND r.source_assignment_id = a.id AND rs.student_id = sub.student_id) AS already
         FROM assignments a
         INNER JOIN subjects s ON s.id = a.subject_id
         INNER JOIN course_students cs ON cs.course_id = s.course_id
         INNER JOIN users u ON u.id = cs.student_id
         INNER JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = cs.student_id
         LEFT JOIN activities act ON act.id = a.activity_id
         LEFT JOIN quizzes qz ON qz.id = a.quiz_id
         WHERE a.subject_id = :sid AND a.teacher_id = :tid AND a.recovery_group_id IS NULL
           AND (a.start_date IS NULL OR a.start_date <= :now)
           AND (sub.status = 'pending' OR (sub.score IS NOT NULL AND sub.score = 0))
         ORDER BY u.name ASC, a.created_at ASC, a.id ASC"
    );
    $st->execute(['g' => $groupId, 'sid' => $subjectId, 'tid' => $teacherId, 'now' => now_datetime()]);

    $students = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int) $r['student_id'];
        if (!isset($students[$sid])) {
            $students[$sid] = ['id' => $sid, 'name' => $r['student_name'], 'email' => $r['student_email'], 'items' => []];
        }
        [$icon, $kind] = space_assignment_kind($r);
        $students[$sid]['items'][] = [
            'assignment_id' => (int) $r['assignment_id'],
            'title' => $r['title'],
            'points' => (int) $r['points'],
            'state' => ($r['score'] !== null && (float) $r['score'] == 0.0) ? 'zero' : 'pending',
            'already' => (int) $r['already'] === 1,
            'icon' => $icon,
            'kind' => $kind,
            'due_date' => $r['due_date'],
        ];
    }
    return array_values($students);
}

/**
 * Agrega actividades a estudiantes dentro del grupo.
 * $picked = [studentId => [assignmentId, ...]]. Solo se aceptan las que de verdad son candidatas (no realizadas / en 0).
 * Devuelve [int cantidad de asignaciones a estudiantes agregadas, string[] avisos].
 */
function recovery_add_items(PDO $pdo, array $group, int $teacherId, array $picked): array
{
    require_once __DIR__ . '/assignment_helpers.php';
    require_once __DIR__ . '/submission_admin_helpers.php';

    $valid = [];
    foreach (recovery_candidates($pdo, (int) $group['subject_id'], $teacherId, (int) $group['id']) as $stu) {
        foreach ($stu['items'] as $it) {
            if (!$it['already']) {
                $valid[$it['assignment_id']][$stu['id']] = true;
            }
        }
    }

    $bySource = [];
    foreach ($picked as $sid => $aids) {
        foreach ((array) $aids as $aid) {
            $sid = (int) $sid;
            $aid = (int) $aid;
            if (isset($valid[$aid][$sid])) {
                $bySource[$aid][$sid] = $sid;
            }
        }
    }
    if (empty($bySource)) {
        return [0, ['No elegiste ninguna actividad válida (quizá ya estaban agregadas).']];
    }

    $added = 0;
    $notes = [];
    $srcStmt = $pdo->prepare('SELECT * FROM assignments WHERE id = :id AND teacher_id = :tid');
    $existStmt = $pdo->prepare('SELECT * FROM assignments WHERE recovery_group_id = :g AND source_assignment_id = :src LIMIT 1');

    foreach ($bySource as $srcId => $studentIds) {
        $studentIds = array_values($studentIds);
        $srcStmt->execute(['id' => $srcId, 'tid' => $teacherId]);
        $src = $srcStmt->fetch();
        if (!$src) {
            continue;
        }
        try {
            $existStmt->execute(['g' => $group['id'], 'src' => $srcId]);
            $existing = $existStmt->fetch();
            if ($existing) {
                // Ya hay una copia de esta actividad en el grupo: solo se suman los estudiantes
                [$n, $msg] = assignment_add_students($pdo, $existing, $studentIds);
                $added += $n;
                if ($n === 0) {
                    $notes[] = $msg;
                }
                continue;
            }

            $dueDate = !empty($group['due_date']) ? $group['due_date'] : null;
            $newId = assignment_create(
                $pdo, $teacherId, (int) $src['subject_id'],
                $src['activity_id'] !== null ? (int) $src['activity_id'] : null,
                mb_substr($src['title'], 0, 200 - mb_strlen(RECOVERY_TITLE_SUFFIX)) . RECOVERY_TITLE_SUFFIX,
                (string) $src['description'],
                null, $dueDate, (int) $src['points'],
                $src['project_type'] !== null && $src['project_type'] !== '' ? $src['project_type'] : null,
                $src['quiz_id'] !== null ? (int) $src['quiz_id'] : null,
                $studentIds,
                $src['description_format'] ?: 'text'
            );

            // Misma configuración que la original, pero individual (sin grupos) y sin cero automático.
            $pdo->prepare(
                'UPDATE assignments SET recovery_group_id = :g, source_assignment_id = :src,
                        late_allowed = :la, late_penalty_pct = :lp, late_max_days = :lm, block_paste = :bp, template_key = :tk,
                        forum_mode = :fm, forum_min_threads = :ft, forum_min_replies = :fr, forum_students_start = :fs,
                        group_mode = 0, auto_zero = 0
                 WHERE id = :id'
            )->execute([
                'g' => $group['id'], 'src' => $srcId,
                'la' => $dueDate ? (int) $src['late_allowed'] : 0, 'lp' => $src['late_penalty_pct'], 'lm' => $src['late_max_days'],
                'bp' => $src['block_paste'], 'tk' => $src['template_key'],
                'fm' => $src['forum_mode'], 'ft' => $src['forum_min_threads'], 'fr' => $src['forum_min_replies'], 'fs' => $src['forum_students_start'],
                'id' => $newId,
            ]);
            $added += count($studentIds);
        } catch (Throwable $e) {
            error_log('recovery_add_items: ' . $e->getMessage());
            $notes[] = 'No se pudo agregar "' . $src['title'] . '". Intenta de nuevo.';
        }
    }
    return [$added, $notes];
}

/**
 * Todas las entregas del grupo (una fila por estudiante + actividad), con la nota de la actividad original.
 * Cada fila trae lo necesario para report_is_delivered() y para pintar el estado.
 */
function recovery_group_rows(PDO $pdo, int $groupId): array
{
    require_once __DIR__ . '/space_helpers.php';

    $st = $pdo->prepare(
        "SELECT a.id AS assignment_id, a.title, a.points, a.due_date, a.project_type, a.activity_id, a.quiz_id, a.source_assignment_id,
                act.title AS activity_title, act.game_mode, qz.title AS quiz_title,
                sub.id AS sub_id, sub.student_id, sub.status, sub.score, sub.raw_score, sub.penalty_pct, sub.feedback,
                sub.completed_at, sub.reviewed_at, sub.returned_at, sub.project_id, sub.quiz_attempt_id, sub.game_id,
                sp.status AS project_status, wga.status AS wg_status,
                (SELECT COUNT(*) FROM quiz_attempts qa
                  WHERE qa.assignment_id = sub.assignment_id AND qa.student_id = sub.student_id AND qa.status <> 'in_progress') AS quiz_done,
                (SELECT os.score FROM submissions os WHERE os.assignment_id = a.source_assignment_id AND os.student_id = sub.student_id LIMIT 1) AS orig_score,
                (SELECT os2.status FROM submissions os2 WHERE os2.assignment_id = a.source_assignment_id AND os2.student_id = sub.student_id LIMIT 1) AS orig_status,
                u.name AS student_name, u.email AS student_email
         FROM assignments a
         INNER JOIN submissions sub ON sub.assignment_id = a.id
         INNER JOIN users u ON u.id = sub.student_id
         LEFT JOIN activities act ON act.id = a.activity_id
         LEFT JOIN quizzes qz ON qz.id = a.quiz_id
         LEFT JOIN student_projects sp ON sp.assignment_id = sub.assignment_id AND sp.student_id = sub.student_id
         LEFT JOIN word_game_attempts wga ON wga.assignment_id = sub.assignment_id AND wga.student_id = sub.student_id
         WHERE a.recovery_group_id = :g
         ORDER BY u.name ASC, a.created_at ASC, a.id ASC"
    );
    $st->execute(['g' => $groupId]);
    return $st->fetchAll();
}

/** Quita a un estudiante de una actividad del grupo; si la actividad se queda sin estudiantes, se elimina. */
function recovery_remove_item(PDO $pdo, array $group, int $teacherId, int $assignmentId, int $studentId): array
{
    require_once __DIR__ . '/submission_admin_helpers.php';
    require_once __DIR__ . '/delete_helpers.php';

    $aStmt = $pdo->prepare('SELECT * FROM assignments WHERE id = :id AND recovery_group_id = :g AND teacher_id = :tid');
    $aStmt->execute(['id' => $assignmentId, 'g' => $group['id'], 'tid' => $teacherId]);
    $assignment = $aStmt->fetch();
    if (!$assignment) {
        return [false, 'Actividad no encontrada en este grupo.'];
    }
    $sStmt = $pdo->prepare('SELECT id FROM submissions WHERE assignment_id = :aid AND student_id = :sid');
    $sStmt->execute(['aid' => $assignmentId, 'sid' => $studentId]);
    $subId = (int) ($sStmt->fetch()['id'] ?? 0);
    if (!$subId) {
        return [false, 'El estudiante no tiene esta actividad.'];
    }

    [$ok, $msg] = assignment_remove_student($pdo, $assignment, $subId);
    if (!$ok) {
        return [false, $msg];
    }
    $left = $pdo->prepare('SELECT COUNT(*) AS n FROM submissions WHERE assignment_id = :aid');
    $left->execute(['aid' => $assignmentId]);
    if ((int) $left->fetch()['n'] === 0) {
        assignment_delete($pdo, $assignmentId, $teacherId);
    }
    return [true, $msg];
}

/** Elimina el grupo y TODAS sus actividades de recuperación (con las entregas de los estudiantes en ellas). */
function recovery_group_delete(PDO $pdo, array $group, int $teacherId): bool
{
    require_once __DIR__ . '/delete_helpers.php';

    $ids = $pdo->prepare('SELECT id FROM assignments WHERE recovery_group_id = :g AND teacher_id = :tid');
    $ids->execute(['g' => $group['id'], 'tid' => $teacherId]);
    $assignmentIds = array_map('intval', array_column($ids->fetchAll(), 'id'));

    $pdo->beginTransaction();
    try {
        $left = delete_assignments_core($pdo, $assignmentIds);
        $pdo->prepare('DELETE FROM recovery_groups WHERE id = :id AND teacher_id = :tid')
            ->execute(['id' => $group['id'], 'tid' => $teacherId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('recovery_group_delete: ' . $e->getMessage());
        return false;
    }
    delete_finish_files($pdo, $left);
    return true;
}

/** Estado de una fila (de recovery_group_rows) para pintar: graded | sent | manual | returned | pending */
function recovery_row_state(array $row): string
{
    require_once __DIR__ . '/report_helpers.php';
    $assignment = ['quiz_id' => $row['quiz_id'], 'activity_id' => $row['activity_id'], 'game_mode' => $row['game_mode']];
    $delivered = report_is_delivered($assignment, $row);
    $graded = $row['score'] !== null;
    return report_cell_state($delivered, $graded, !empty($row['returned_at']) && !$delivered);
}
