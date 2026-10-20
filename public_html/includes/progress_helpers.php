<?php
/**
 * progress_helpers.php
 * "Mi progreso" del estudiante: promedios por asignatura (ponderados por puntos), entregas a tiempo,
 * pendientes/vencidas y evolución de sus calificaciones.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/availability_helpers.php';

/** Resumen general + por asignatura + notas recientes. Solo lo que el estudiante ya puede ver. */
function progress_for_student(PDO $pdo, int $studentId): array
{
    $now = now_datetime();
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.points, COALESCE(ax.due_date, a.due_date) AS due_date,
                s.id AS subject_id, s.name AS subject_name, c.name AS course_name,
                sub.status, sub.score, sub.completed_at, sub.reviewed_at, sub.returned_at, sub.feedback
         FROM submissions sub
         INNER JOIN assignments a ON a.id = sub.assignment_id
         INNER JOIN subjects s ON s.id = a.subject_id
         INNER JOIN courses c ON c.id = s.course_id AND c.is_active = 1
         LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = :sid2
         WHERE sub.student_id = :sid AND (a.start_date IS NULL OR a.start_date <= :now)'
    );
    $stmt->execute(['sid' => $studentId, 'sid2' => $studentId, 'now' => $now]);

    $blank = ['total' => 0, 'completed' => 0, 'graded' => 0, 'score' => 0.0, 'points' => 0.0, 'pending' => 0, 'overdue' => 0, 'on_time' => 0];
    $overall = $blank;
    $subjects = [];
    $graded = [];

    foreach ($stmt->fetchAll() as $r) {
        $sid = (int) $r['subject_id'];
        if (!isset($subjects[$sid])) {
            $subjects[$sid] = $blank + ['name' => $r['subject_name'], 'course' => $r['course_name']];
        }
        $done = $r['status'] === 'completed';
        $isGraded = $done && $r['score'] !== null && (float) $r['points'] > 0;
        $overdue = !$done && assignment_overdue_for_student($r, $r, $now);

        foreach ([&$overall, &$subjects[$sid]] as &$bucket) {
            $bucket['total']++;
            if ($done) {
                $bucket['completed']++;
                if (empty($r['due_date']) || ($r['completed_at'] !== null && $r['completed_at'] <= $r['due_date'])) {
                    $bucket['on_time']++;
                }
            } elseif ($overdue) {
                $bucket['overdue']++;
            } else {
                $bucket['pending']++;
            }
            if ($isGraded) {
                $bucket['graded']++;
                $bucket['score'] += (float) $r['score'];
                $bucket['points'] += (float) $r['points'];
            }
        }
        unset($bucket);

        if ($isGraded) {
            $graded[] = [
                'title' => $r['title'], 'subject' => $r['subject_name'], 'score' => (float) $r['score'], 'points' => (float) $r['points'],
                'pct' => round((float) $r['score'] / (float) $r['points'] * 100, 1),
                'date' => $r['reviewed_at'] ?: $r['completed_at'], 'feedback' => $r['feedback'],
            ];
        }
    }

    usort($graded, fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
    uasort($subjects, fn($a, $b) => strcmp($a['name'], $b['name']));

    return [
        'overall'  => $overall,
        'subjects' => $subjects,
        'graded'   => $graded,                                   // cronológico (de la más antigua a la más reciente)
        'recent'   => array_slice(array_reverse($graded), 0, 8), // las 8 más recientes
    ];
}

/** Porcentaje ponderado por puntos (null si aún no hay notas). */
function progress_percent(array $bucket): ?float
{
    return $bucket['points'] > 0 ? round($bucket['score'] / $bucket['points'] * 100, 1) : null;
}

/** Color según el porcentaje. */
function progress_color(?float $pct): string
{
    if ($pct === null) {
        return '#94A3B8';
    }
    return $pct >= 90 ? '#16A34A' : ($pct >= 70 ? '#0878F9' : ($pct >= 60 ? '#D97706' : '#DC2626'));
}

/** Gráfico de barras SVG con las últimas notas (0-100 %). */
function progress_chart_svg(array $graded, int $max = 12): string
{
    $items = array_slice($graded, -$max);
    $n = count($items);
    if ($n === 0) {
        return '';
    }
    $w = 640;
    $h = 220;
    $padL = 34;
    $padB = 26;
    $plotW = $w - $padL - 8;
    $plotH = $h - $padB - 14;
    $barW = min(44, $plotW / $n * 0.62);
    $step = $plotW / $n;

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%; max-width:' . $w . 'px; height:auto;" role="img" aria-label="Evolución de tus calificaciones">';
    foreach ([0, 25, 50, 75, 100] as $g) {
        $y = 8 + $plotH - $plotH * $g / 100;
        $svg .= '<line x1="' . $padL . '" y1="' . $y . '" x2="' . ($w - 8) . '" y2="' . $y . '" stroke="#E3E9F2" stroke-width="1"/>'
              . '<text x="' . ($padL - 6) . '" y="' . ($y + 4) . '" font-size="10" text-anchor="end" fill="#64748B">' . $g . '</text>';
    }
    foreach ($items as $i => $g) {
        $x = $padL + $step * $i + ($step - $barW) / 2;
        $bh = max(2, $plotH * min(100, $g['pct']) / 100);
        $y = 8 + $plotH - $bh;
        $svg .= '<g><title>' . e($g['title'] . ' — ' . $g['score'] . '/' . $g['points'] . ' (' . $g['pct'] . '%)') . '</title>'
              . '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round($bh, 1) . '" rx="4" fill="' . progress_color($g['pct']) . '"/>'
              . '<text x="' . round($x + $barW / 2, 1) . '" y="' . round($y - 3, 1) . '" font-size="10" text-anchor="middle" fill="#334155" font-weight="700">' . round($g['pct']) . '</text>'
              . '<text x="' . round($x + $barW / 2, 1) . '" y="' . ($h - 8) . '" font-size="10" text-anchor="middle" fill="#64748B">' . ($i + 1) . '</text></g>';
    }
    return $svg . '</svg>';
}
