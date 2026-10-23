<?php
/**
 * late_helpers.php — Penalización por entrega tardía (Fase 22).
 *
 * El profesor puede permitir entregas después de la fecha límite: cada día de retraso descuenta un % de la nota
 * y pasados N días ya no se recibe nada. Aplica a los trabajos de creación (resúmenes, archivos, foros, etc.).
 * La nota que escribe el profesor es SIEMPRE la "nota sin penalización" (raw_score); el sistema calcula la nota
 * final (score) y la muestra con el desglose, así nunca se penaliza dos veces.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/** Nota final = nota del profesor menos el % de penalización. */
function late_final_score(float $raw, float $penaltyPct): float
{
    return round($raw * (1 - max(0, min(100, $penaltyPct)) / 100), 2);
}

/** Días de retraso (se redondea hacia arriba: 1 minuto tarde = 1 día) y % de penalización resultante. */
function late_compute(?string $dueDate, ?string $completedAt, int $penaltyPerDay): array
{
    if (!$dueDate || !$completedAt) {
        return [0, 0.0];
    }
    $diff = strtotime($completedAt) - strtotime($dueDate);
    if ($diff <= 0) {
        return [0, 0.0];
    }
    $days = (int) ceil($diff / 86400);
    return [$days, (float) min(100, $days * max(0, $penaltyPerDay))];
}

/**
 * Registra el retraso de una entrega recién completada (late_days / penalty_pct). Si el profesor ya había calificado,
 * recalcula la nota final con la nota original. Es seguro llamarla más de una vez.
 */
function late_stamp_submission(PDO $pdo, int $assignmentId, int $studentId): void
{
    $stmt = $pdo->prepare(
        'SELECT sub.id, sub.completed_at, sub.raw_score, sub.score, a.late_allowed, a.late_penalty_pct,
                COALESCE(ax.due_date, a.due_date) AS due
         FROM submissions sub
         INNER JOIN assignments a ON a.id = sub.assignment_id
         LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = sub.student_id
         WHERE sub.assignment_id = :aid AND sub.student_id = :sid LIMIT 1'
    );
    $stmt->execute(['aid' => $assignmentId, 'sid' => $studentId]);
    $r = $stmt->fetch();
    if (!$r) {
        return;
    }

    [$days, $pct] = !empty($r['late_allowed'])
        ? late_compute($r['due'], $r['completed_at'], (int) $r['late_penalty_pct'])
        : [0, 0.0];

    $pdo->prepare('UPDATE submissions SET late_days = :d, penalty_pct = :p WHERE id = :id')
        ->execute(['d' => $days, 'p' => $pct, 'id' => $r['id']]);

    // Ya calificada antes de que se registrara el retraso: se recalcula la nota final desde la nota original.
    $raw = $r['raw_score'] ?? $r['score'];
    if ($raw !== null && $r['score'] !== null) {
        $pdo->prepare('UPDATE submissions SET raw_score = :raw, score = :score WHERE id = :id')->execute([
            'raw' => $pct > 0 ? $raw : null, 'score' => late_final_score((float) $raw, $pct), 'id' => $r['id'],
        ]);
    }
}

/** Texto corto de la política para mostrar en formularios y en la página del estudiante. */
function late_policy_text(array $a): string
{
    if (empty($a['late_allowed'])) {
        return '';
    }
    return 'Se aceptan entregas tardías hasta ' . (int) $a['late_max_days'] . ' día(s) después de la fecha límite, '
        . 'con un descuento de ' . (int) $a['late_penalty_pct'] . '% por cada día de retraso.';
}
