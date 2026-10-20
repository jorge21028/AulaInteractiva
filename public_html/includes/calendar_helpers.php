<?php
/**
 * calendar_helpers.php
 * Calendario mensual + línea de tiempo (próximas entregas) para estudiantes y profesores.
 * Estudiante: solo ve lo que ya le corresponde ver (asignaciones iniciadas, cursos activos) y con SU fecha efectiva
 * (incluida su prórroga). Profesor: sus fechas de entrega y de inicio (programadas).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/availability_helpers.php';

const CAL_MONTHS = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const CAL_DAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/** Eventos del estudiante: una entrada por asignación con fecha de entrega. */
function calendar_student_events(PDO $pdo, int $studentId): array
{
    $now = now_datetime();
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.start_date, COALESCE(ax.due_date, a.due_date) AS due_date, ax.due_date AS ext_due_date,
                s.name AS subject_name, c.name AS course_name, sub.status, sub.returned_at
         FROM submissions sub
         INNER JOIN assignments a ON a.id = sub.assignment_id
         INNER JOIN subjects s ON s.id = a.subject_id
         INNER JOIN courses c ON c.id = s.course_id AND c.is_active = 1
         LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = :sid2
         WHERE sub.student_id = :sid AND (a.start_date IS NULL OR a.start_date <= :now)
           AND COALESCE(ax.due_date, a.due_date) IS NOT NULL
         ORDER BY COALESCE(ax.due_date, a.due_date) ASC'
    );
    $stmt->execute(['sid' => $studentId, 'sid2' => $studentId, 'now' => $now]);

    $events = [];
    foreach ($stmt->fetchAll() as $r) {
        $status = $r['status'] === 'completed' ? 'done' : (assignment_overdue_for_student($r, $r, $now) ? 'overdue' : 'pending');
        $events[] = [
            'ts'     => strtotime($r['due_date']),
            'date'   => date('Y-m-d', strtotime($r['due_date'])),
            'time'   => date('h:i A', strtotime($r['due_date'])),
            'title'  => $r['title'],
            'sub'    => $r['course_name'] . ' — ' . $r['subject_name'],
            'kind'   => 'due',
            'status' => $status,
            'ext'    => !empty($r['ext_due_date']),
            'url'    => 'assignment.php?id=' . (int) $r['id'],
        ];
    }
    return $events;
}

/** Eventos del profesor: fechas de entrega (con cuántos faltan por entregar) y fechas de inicio programadas. */
function calendar_teacher_events(PDO $pdo, int $teacherId): array
{
    $now = now_datetime();
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.start_date, a.due_date, s.name AS subject_name, c.name AS course_name,
                (SELECT COUNT(*) FROM submissions x WHERE x.assignment_id = a.id) AS total,
                (SELECT COUNT(*) FROM submissions x WHERE x.assignment_id = a.id AND x.status = \'completed\') AS done
         FROM assignments a
         INNER JOIN subjects s ON s.id = a.subject_id
         INNER JOIN courses c ON c.id = s.course_id AND c.is_active = 1
         WHERE a.teacher_id = :tid AND (a.due_date IS NOT NULL OR a.start_date IS NOT NULL)
         ORDER BY a.id DESC LIMIT 800'
    );
    $stmt->execute(['tid' => $teacherId]);

    $events = [];
    foreach ($stmt->fetchAll() as $r) {
        $sub = $r['course_name'] . ' — ' . $r['subject_name'];
        if (!empty($r['due_date'])) {
            $past = $r['due_date'] < $now;
            $events[] = [
                'ts' => strtotime($r['due_date']), 'date' => date('Y-m-d', strtotime($r['due_date'])), 'time' => date('h:i A', strtotime($r['due_date'])),
                'title' => $r['title'], 'sub' => $sub, 'kind' => 'due',
                'status' => $past ? ((int) $r['done'] >= (int) $r['total'] ? 'done' : 'overdue') : 'pending',
                'badge'  => (int) $r['done'] . '/' . (int) $r['total'] . ' entregas',
                'url'    => 'assignment_detail.php?id=' . (int) $r['id'],
            ];
        }
        if (!empty($r['start_date']) && $r['start_date'] > $now) {
            $events[] = [
                'ts' => strtotime($r['start_date']), 'date' => date('Y-m-d', strtotime($r['start_date'])), 'time' => date('h:i A', strtotime($r['start_date'])),
                'title' => $r['title'], 'sub' => $sub, 'kind' => 'start', 'status' => 'scheduled', 'badge' => 'se publica',
                'url' => 'assignment_detail.php?id=' . (int) $r['id'],
            ];
        }
    }
    usort($events, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $events;
}

function calendar_style(string $status): array
{
    return match ($status) {
        'done'      => ['#DCFCE7', '#166534', '#BBF7D0', '✅'],
        'overdue'   => ['#FDECEC', '#B91C1C', '#F8C9C9', '⌛'],
        'scheduled' => ['#EDE9FE', '#5B21B6', '#DDD6FE', '🕒'],
        default     => ['#E8F3FF', '#0658C2', '#BFDBFE', '📌'],
    };
}

/** Normaliza el parámetro ?m=YYYY-MM. Devuelve [año, mes]. */
function calendar_parse_month(?string $m): array
{
    if ($m && preg_match('/^(\d{4})-(\d{2})$/', $m, $x) && (int) $x[2] >= 1 && (int) $x[2] <= 12) {
        return [(int) $x[1], (int) $x[2]];
    }
    return [(int) date('Y'), (int) date('n')];
}

/** HTML de la cuadrícula del mes (lunes primero). $baseUrl: página que muestra el calendario. */
function calendar_render_month(array $events, int $year, int $month, string $baseUrl): string
{
    $byDay = [];
    foreach ($events as $ev) {
        if (substr($ev['date'], 0, 7) === sprintf('%04d-%02d', $year, $month)) {
            $byDay[$ev['date']][] = $ev;
        }
    }

    $first = mktime(0, 0, 0, $month, 1, $year);
    $daysInMonth = (int) date('t', $first);
    $offset = (int) date('N', $first) - 1;          // 0 = lunes
    $today = date('Y-m-d');
    $prev = date('Y-m', mktime(0, 0, 0, $month - 1, 1, $year));
    $next = date('Y-m', mktime(0, 0, 0, $month + 1, 1, $year));

    $h = '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; flex-wrap:wrap;">'
       . '<a class="btn btn-secondary" style="margin:0; padding:5px 12px;" href="' . e($baseUrl . '?m=' . $prev) . '">&larr;</a>'
       . '<strong style="font-size:1.15rem;">' . e(CAL_MONTHS[$month] . ' ' . $year) . '</strong>'
       . '<span><a class="btn btn-secondary" style="margin:0 4px 0 0; padding:5px 12px;" href="' . e($baseUrl) . '">Hoy</a>'
       . '<a class="btn btn-secondary" style="margin:0; padding:5px 12px;" href="' . e($baseUrl . '?m=' . $next) . '">&rarr;</a></span></div>';

    $h .= '<div style="overflow-x:auto;"><table style="width:100%; min-width:640px; border-collapse:collapse; table-layout:fixed;"><thead><tr>';
    foreach (CAL_DAYS as $d) {
        $h .= '<th style="padding:6px; font-size:0.8rem; color:#64748B; border-bottom:2px solid #E3E9F2;">' . $d . '</th>';
    }
    $h .= '</tr></thead><tbody>';

    $cell = 0;
    $h .= '<tr>';
    for ($i = 0; $i < $offset; $i++, $cell++) {
        $h .= '<td style="border:1px solid #EEF2F7; background:#FAFBFD; height:88px;"></td>';
    }
    for ($day = 1; $day <= $daysInMonth; $day++, $cell++) {
        if ($cell % 7 === 0 && $cell > 0) {
            $h .= '</tr><tr>';
        }
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $isToday = $date === $today;
        $h .= '<td style="vertical-align:top; border:1px solid #EEF2F7; padding:3px; height:88px; ' . ($isToday ? 'background:#FFFBEB;' : '') . '">'
            . '<div style="font-size:0.75rem; font-weight:' . ($isToday ? '800' : '600') . '; color:' . ($isToday ? '#B45309' : '#64748B') . ';">' . $day . '</div>';
        $list = $byDay[$date] ?? [];
        foreach (array_slice($list, 0, 3) as $ev) {
            [$bg, $fg, $bd, $icon] = calendar_style($ev['status']);
            $h .= '<a href="' . e($ev['url']) . '" title="' . e($ev['title'] . ' — ' . $ev['sub'] . ' · ' . $ev['time']) . '" '
                . 'style="display:block; margin-top:2px; padding:1px 5px; border-radius:6px; font-size:0.72rem; line-height:1.25; text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; background:' . $bg . '; color:' . $fg . '; border:1px solid ' . $bd . ';">'
                . $icon . ' ' . e($ev['title']) . '</a>';
        }
        if (count($list) > 3) {
            $h .= '<div style="font-size:0.7rem; color:#64748B; margin-top:2px;">+' . (count($list) - 3) . ' más</div>';
        }
        $h .= '</td>';
    }
    while ($cell % 7 !== 0) {
        $h .= '<td style="border:1px solid #EEF2F7; background:#FAFBFD;"></td>';
        $cell++;
    }
    return $h . '</tr></tbody></table></div>';
}

/**
 * Línea de tiempo: agrupa lo pendiente por "Vencidas", "Hoy", "Mañana", "Esta semana", "Próximas semanas".
 * Solo muestra lo que aún no está entregado (estudiante) o lo programado/por vencer (profesor).
 */
function calendar_timeline(array $events, bool $forTeacher = false, int $horizonDays = 60): array
{
    $today = strtotime(date('Y-m-d'));
    $groups = ['Vencidas sin entregar' => [], 'Hoy' => [], 'Mañana' => [], 'Esta semana' => [], 'Próximas semanas' => []];
    foreach ($events as $ev) {
        if (!$forTeacher && $ev['status'] === 'done') {
            continue;
        }
        $day = strtotime($ev['date']);
        $diff = (int) round(($day - $today) / 86400);
        if ($ev['status'] === 'overdue') {
            if (!$forTeacher || $diff >= -14) {
                $groups['Vencidas sin entregar'][] = $ev;
            }
            continue;
        }
        if ($ev['status'] === 'done' || $diff < 0 || $diff > $horizonDays) {
            continue;
        }
        $key = $diff === 0 ? 'Hoy' : ($diff === 1 ? 'Mañana' : ($diff <= 7 ? 'Esta semana' : 'Próximas semanas'));
        $groups[$key][] = $ev;
    }
    return array_filter($groups);
}

/** HTML de la línea de tiempo. */
function calendar_render_timeline(array $groups, string $emptyText): string
{
    if (empty($groups)) {
        return '<p class="empty-state">' . e($emptyText) . '</p>';
    }
    $h = '';
    foreach ($groups as $label => $items) {
        $h .= '<h3 style="margin:14px 0 6px;">' . e($label) . ' <span class="text-muted" style="font-weight:400; font-size:0.85rem;">(' . count($items) . ')</span></h3>';
        foreach ($items as $ev) {
            [$bg, $fg, $bd, $icon] = calendar_style($ev['status']);
            $when = ($ev['status'] === 'overdue' ? 'Venció ' : ($ev['kind'] === 'start' ? 'Se publica ' : 'Vence ')) . date('d/m', $ev['ts']) . ' ' . $ev['time'];
            $h .= '<a href="' . e($ev['url']) . '" class="card" style="display:block; margin-bottom:6px; padding:10px 14px; text-decoration:none; color:inherit; border-left:4px solid ' . $fg . ';">'
                . '<strong>' . $icon . ' ' . e($ev['title']) . '</strong>'
                . (!empty($ev['ext']) ? ' <span style="font-size:0.75rem; color:#0658C2;">⏰ prórroga</span>' : '')
                . '<div class="text-muted" style="font-size:0.85rem;">' . e($ev['sub']) . ' · <span style="color:' . $fg . '; font-weight:600;">' . e($when) . '</span>'
                . (!empty($ev['badge']) ? ' · ' . e($ev['badge']) : '') . '</div></a>';
        }
    }
    return $h;
}

/** Leyenda de colores. */
function calendar_legend(bool $forTeacher): string
{
    $items = $forTeacher ? ['pending' => 'Por vencer', 'overdue' => 'Vencida con entregas pendientes', 'done' => 'Vencida y completa', 'scheduled' => 'Se publica'] : ['pending' => 'Pendiente', 'done' => 'Entregada', 'overdue' => 'Vencida'];
    $h = '<p style="display:flex; gap:12px; flex-wrap:wrap; font-size:0.8rem; margin:8px 0 0;">';
    foreach ($items as $st => $label) {
        [$bg, $fg, $bd, $icon] = calendar_style($st);
        $h .= '<span style="padding:1px 8px; border-radius:6px; background:' . $bg . '; color:' . $fg . '; border:1px solid ' . $bd . ';">' . $icon . ' ' . e($label) . '</span>';
    }
    return $h . '</p>';
}
