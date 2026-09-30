<?php
/**
 * word_play_helpers.php
 * Ahorcado y Crucigrama como actividades INDIVIDUALES: sin cronómetro y sin partida en vivo.
 * El estudiante juega a su ritmo desde su asignación; el avance se guarda en el servidor
 * (tabla word_game_attempts) y al terminar se califica la entrega automáticamente.
 *
 * Estado guardado en word_game_attempts.state_json:
 *   ahorcado:   {"w": {"<id_palabra>": {"g": ["A","B"], "done": 1, "solved": 1}}}
 *   crucigrama: {"cells": {"fila,columna": "LETRA"}}
 * Calificación: palabras acertadas / total de palabras, escalado a los puntos de la asignación.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/game_helpers.php'; // carga también word_games_helpers.php

/** Carga la asignación del estudiante SOLO si es de un juego de palabras. */
function wga_load_assignment(PDO $pdo, int $assignmentId, int $studentId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.description, a.points, a.due_date, a.subject_id,
                act.id AS activity_id, act.title AS activity_title, act.game_mode, act.allow_repeat, act.status AS activity_status,
                sub.id AS submission_id, sub.status AS sub_status, sub.score AS sub_score, sub.feedback, sub.reviewed_at
         FROM assignments a
         INNER JOIN activities act ON act.id = a.activity_id
         INNER JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = :student_id
         WHERE a.id = :id AND act.game_mode IN ('ahorcado', 'crucigrama')
         LIMIT 1"
    );
    $stmt->execute(['id' => $assignmentId, 'student_id' => $studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function wga_is_overdue(array $assignment): bool
{
    return !empty($assignment['due_date']) && strtotime($assignment['due_date']) < time();
}

/** Devuelve el intento del estudiante, creándolo la primera vez. */
function wga_ensure_attempt(PDO $pdo, array $assignment, int $studentId): array
{
    $pdo->prepare(
        "INSERT IGNORE INTO word_game_attempts (assignment_id, activity_id, student_id, status, state_json, started_at, updated_at)
         VALUES (:aid, :act, :sid, 'in_progress', NULL, :now, :now2)"
    )->execute([
        'aid' => $assignment['id'], 'act' => $assignment['activity_id'], 'sid' => $studentId,
        'now' => now_datetime(), 'now2' => now_datetime(),
    ]);

    $stmt = $pdo->prepare('SELECT * FROM word_game_attempts WHERE assignment_id = :aid AND student_id = :sid');
    $stmt->execute(['aid' => $assignment['id'], 'sid' => $studentId]);
    return $stmt->fetch();
}

/** Bloquea y devuelve la fila del intento (usar dentro de una transacción). */
function wga_lock_attempt(PDO $pdo, int $attemptId): array
{
    $stmt = $pdo->prepare('SELECT * FROM word_game_attempts WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $attemptId]);
    return $stmt->fetch();
}

function wga_save_state(PDO $pdo, int $attemptId, array $state): void
{
    $pdo->prepare('UPDATE word_game_attempts SET state_json = :s, updated_at = :now WHERE id = :id')
        ->execute(['s' => json_encode($state, JSON_UNESCAPED_UNICODE), 'now' => now_datetime(), 'id' => $attemptId]);
}

/**
 * Cierra el intento y califica la entrega. Llamar dentro de una transacción.
 * - No pisa una calificación ajustada por el profesor (reviewed_at).
 * - Si la actividad permite repetir, solo se mejora la nota (nunca baja).
 * Devuelve ['correct','total','ratio','score','points'].
 */
function wga_complete(PDO $pdo, array $assignment, int $attemptId, int $studentId, int $correct, int $total): array
{
    $ratio = $total > 0 ? $correct / $total : 0.0;
    $points = (int) $assignment['points'];
    $score = round($ratio * $points, 2);
    $now = now_datetime();

    $pdo->prepare(
        "UPDATE word_game_attempts SET status = 'completed', correct_count = :c, total_count = :t, completed_at = :now, updated_at = :now2 WHERE id = :id"
    )->execute(['c' => $correct, 't' => $total, 'now' => $now, 'now2' => $now, 'id' => $attemptId]);

    $stmt = $pdo->prepare('SELECT id, status, score, reviewed_at FROM submissions WHERE assignment_id = :aid AND student_id = :sid FOR UPDATE');
    $stmt->execute(['aid' => $assignment['id'], 'sid' => $studentId]);
    $sub = $stmt->fetch();

    if ($sub && $sub['reviewed_at'] === null) {
        $better = $sub['status'] !== 'completed' || $sub['score'] === null || $score > (float) $sub['score'];
        if ($better) {
            $pdo->prepare("UPDATE submissions SET status = 'completed', score = :score, completed_at = :now WHERE id = :id")
                ->execute(['score' => $score, 'now' => $now, 'id' => $sub['id']]);
        }
    }

    return ['correct' => $correct, 'total' => $total, 'ratio' => $ratio, 'score' => $score, 'points' => $points];
}

/** Reinicia el intento (solo si la actividad permite repetir). La mejor nota se conserva en la entrega. */
function wga_restart(PDO $pdo, int $attemptId): void
{
    $pdo->prepare(
        "UPDATE word_game_attempts SET status = 'in_progress', state_json = NULL, correct_count = 0, completed_at = NULL, updated_at = :now WHERE id = :id"
    )->execute(['now' => now_datetime(), 'id' => $attemptId]);
}

/** Lee el cuerpo JSON de la petición y valida CSRF + rol. Devuelve [$input, $studentId]. */
function wga_api_bootstrap(): array
{
    header('Content-Type: application/json; charset=utf-8');
    require_role('student');
    $raw = json_decode(file_get_contents('php://input'), true);
    $input = is_array($raw) ? $raw : $_POST;
    csrf_verify($input['csrf_token'] ?? null);
    return [$input, current_user_id()];
}

// ---------------------------------------------------------------------------
// AHORCADO
// ---------------------------------------------------------------------------

/** Primera palabra sin terminar: [índice, palabra] o null si ya terminó todas. */
function wga_hangman_current(array $words, array $state): ?array
{
    foreach ($words as $i => $w) {
        if (empty($state['w'][(string) $w['id']]['done'])) {
            return [$i, $w];
        }
    }
    return null;
}

/** Cuenta [acertadas, terminadas] según el estado. */
function wga_hangman_counts(array $words, array $state): array
{
    $solved = 0;
    $done = 0;
    foreach ($words as $w) {
        $ws = $state['w'][(string) $w['id']] ?? [];
        if (!empty($ws['done'])) {
            $done++;
            if (!empty($ws['solved'])) {
                $solved++;
            }
        }
    }
    return [$solved, $done];
}

/** Arma la respuesta que ve el estudiante. NUNCA incluye la palabra de una palabra sin terminar. */
function wga_hangman_view(array $assignment, array $attempt, array $words, array $state): array
{
    [$solved, $done] = wga_hangman_counts($words, $state);
    $list = [];
    foreach ($words as $w) {
        $ws = $state['w'][(string) $w['id']] ?? [];
        $isDone = !empty($ws['done']);
        $item = ['id' => $w['id'], 'clue' => $w['clue'], 'status' => $isDone ? (!empty($ws['solved']) ? 'solved' : 'lost') : 'pending'];
        if ($isDone) {
            $item['answer'] = mb_strtoupper($w['word'], 'UTF-8');
        }
        $list[] = $item;
    }

    $view = [
        'success'      => true,
        'mode'         => 'ahorcado',
        'status'       => $attempt['status'],
        'total'        => count($words),
        'done'         => $done,
        'solved'       => $solved,
        'words'        => $list,
        'points'       => (int) $assignment['points'],
        'allow_repeat' => (int) $assignment['allow_repeat'] === 1,
        'overdue'      => wga_is_overdue($assignment),
        'current'      => null,
    ];

    if ($attempt['status'] === 'in_progress' && ($cur = wga_hangman_current($words, $state))) {
        [$i, $w] = $cur;
        $guesses = $state['w'][(string) $w['id']]['g'] ?? [];
        $hs = hangman_state($w['word'], is_array($guesses) ? $guesses : []);
        $view['current'] = [
            'id' => $w['id'], 'index' => $i, 'clue' => $w['clue'],
            'hangman' => [
                'pattern' => $hs['pattern'], 'guessed' => $hs['guessed'], 'wrong' => $hs['wrong'],
                'lives_left' => $hs['lives_left'], 'max_lives' => $hs['max_lives'],
            ],
        ];
    }

    if ($attempt['status'] === 'completed') {
        $total = (int) $attempt['total_count'];
        $ratio = $total > 0 ? (int) $attempt['correct_count'] / $total : 0;
        $view['result'] = [
            'correct' => (int) $attempt['correct_count'], 'total' => $total,
            'score' => round($ratio * (int) $assignment['points'], 2), 'points' => (int) $assignment['points'],
        ];
    }

    return $view;
}
