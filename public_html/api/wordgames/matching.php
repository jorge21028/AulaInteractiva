<?php
/**
 * POST /api/wordgames/matching.php
 * Body: assignment_id, action ('load' | 'match' | 'submit' | 'restart'), [left_id, right_token], csrf_token
 *
 * EMPAREJAR CONCEPTOS individual (sin tiempo). El estudiante une un concepto (columna izquierda) con su
 * definición/pareja (columna derecha) y el servidor comprueba cada unión. Las tarjetas de la derecha llevan un
 * código opaco, así el navegador no puede deducir las respuestas.
 * Calificación: cuenta cada pareja que se une bien A LA PRIMERA; entregar con parejas sin resolver las deja en 0.
 * Al unir todas las parejas la entrega se califica sola.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/word_play_helpers.php';

[$input, $studentId] = wga_api_bootstrap();

$assignmentId = (int) ($input['assignment_id'] ?? 0);
$action = clean_string($input['action'] ?? 'load');

$pdo = Database::getConnection();
$assignment = wga_load_assignment($pdo, $assignmentId, $studentId);
if (!$assignment || $assignment['game_mode'] !== 'emparejar') {
    json_response(['success' => false, 'message' => 'Actividad no encontrada.'], 404);
}

$bank = word_activity_words($pdo, (int) $assignment['activity_id']);
if (empty($bank)) {
    json_response(['success' => false, 'message' => 'Esta actividad todavía no tiene parejas.'], 400);
}

function mt_context(PDO $pdo, array $assignment, int $studentId, array $bank): array
{
    $attempt = wga_ensure_attempt($pdo, $assignment, $studentId);
    $attempt = wga_ensure_selection($pdo, $assignment, $attempt, $bank);
    $attempt = wga_ensure_seed($pdo, $attempt);
    $words = wga_attempt_bank($bank, $attempt);
    $state = word_game_decode_answer($attempt['state_json']);
    return [$attempt, $words, $state];
}

function mt_view(array $assignment, array $attempt, array $words, array $state, array $extra = []): array
{
    $seed = (int) $state['seed'];
    $cards = matching_public_cards($words, $seed);
    $m = is_array($state['m'] ?? null) ? $state['m'] : [];
    $allowRepeat = (int) $assignment['allow_repeat'] === 1;
    $isDone = $attempt['status'] === 'completed';

    // Uniones ya acertadas (y cuántos fallos tuvo cada concepto)
    $matched = [];
    foreach ($words as $w) {
        $row = $m[(string) $w['id']] ?? null;
        if ($row && !empty($row['ok'])) {
            $matched[] = ['left_id' => (int) $w['id'], 'right_token' => matching_right_token((int) $w['id'], $seed), 'wrong' => (int) ($row['w'] ?? 0)];
        }
    }
    [$first, $matchedCount, $total] = matching_counts($words, $state);

    $view = [
        'success'      => true,
        'mode'         => 'emparejar',
        'status'       => $attempt['status'],
        'left'         => $cards['left'],
        'right'        => $cards['right'],
        'matched'      => $matched,
        'total'        => $total,
        'matched_count' => $matchedCount,
        'points'       => (int) $assignment['points'],
        'allow_repeat' => $allowRepeat,
        'overdue'      => wga_is_overdue($assignment),
    ];
    if ($isDone) {
        $totalCnt = (int) $attempt['total_count'];
        $ratio = $totalCnt > 0 ? (int) $attempt['correct_count'] / $totalCnt : 0;
        $view['result'] = [
            'correct' => (int) $attempt['correct_count'], 'total' => $totalCnt,
            'score' => round($ratio * (int) $assignment['points'], 2), 'points' => (int) $assignment['points'],
        ];
        $review = [];
        foreach ($words as $w) {
            $row = $m[(string) $w['id']] ?? null;
            $item = ['concept' => $w['word'], 'first_try' => $row && !empty($row['ok']) && (int) ($row['w'] ?? 0) === 0, 'solved' => (bool) ($row && !empty($row['ok']))];
            if (!$allowRepeat) {
                $item['pair'] = $w['clue'];   // con "repetir" permitido no se muestran las parejas para no regalarlas
            }
            $review[] = $item;
        }
        $view['review'] = $review;
    }
    return $view + $extra;
}

[$attempt, $words, $state] = mt_context($pdo, $assignment, $studentId, $bank);

if ($action === 'load') {
    json_response(mt_view($assignment, $attempt, $words, $state));
}

if ($action === 'restart') {
    if ((int) $assignment['allow_repeat'] !== 1 || $attempt['status'] !== 'completed') {
        json_response(['success' => false, 'message' => 'No puedes repetir esta actividad.'], 400);
    }
    if (wga_is_overdue($assignment)) {
        json_response(['success' => false, 'message' => 'La fecha de entrega ya venció.'], 400);
    }
    wga_restart($pdo, (int) $attempt['id']);
    [$attempt, $words, $state] = mt_context($pdo, $assignment, $studentId, $bank);
    json_response(mt_view($assignment, $attempt, $words, $state));
}

if ($action !== 'match' && $action !== 'submit') {
    json_response(['success' => false, 'message' => 'Acción no válida.'], 400);
}

if (wga_is_overdue($assignment)) {
    json_response(['success' => false, 'message' => 'La fecha de entrega ya venció.', 'overdue' => true], 400);
}

$result = null;   // true = unión correcta, false = incorrecta
$pdo->beginTransaction();
try {
    $attempt = wga_lock_attempt($pdo, (int) $attempt['id']);
    if ($attempt['status'] !== 'in_progress') {
        $pdo->rollBack();
        json_response(mt_view($assignment, $attempt, $words, word_game_decode_answer($attempt['state_json']), ['already_done' => true]));
    }
    $state = word_game_decode_answer($attempt['state_json']);
    $m = is_array($state['m'] ?? null) ? $state['m'] : [];

    if ($action === 'match') {
        $leftId = (int) ($input['left_id'] ?? 0);
        $token = (string) ($input['right_token'] ?? '');
        $ids = array_map(fn($w) => (int) $w['id'], $words);
        if (!in_array($leftId, $ids, true)) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Concepto no válido.'], 400);
        }
        $row = $m[(string) $leftId] ?? ['w' => 0, 'ok' => 0];
        if (empty($row['ok'])) {
            $expected = matching_right_token($leftId, (int) $state['seed']);
            if (hash_equals($expected, $token)) {
                $row['ok'] = 1;
                $result = true;
            } else {
                $row['w'] = (int) ($row['w'] ?? 0) + 1;
                $result = false;
            }
            $m[(string) $leftId] = $row;
            $state['m'] = $m;
            wga_save_state($pdo, (int) $attempt['id'], $state);
        }
    }

    [$first, $matchedCount, $total] = matching_counts($words, $state);
    $finish = $action === 'submit' || $matchedCount >= $total;
    if ($finish) {
        wga_complete($pdo, $assignment, (int) $attempt['id'], $studentId, $first, $total);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('wordgames/matching.php error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'No fue posible guardar.'], 500);
}

$attempt = wga_ensure_attempt($pdo, $assignment, $studentId);
$state = word_game_decode_answer($attempt['state_json']);
json_response(mt_view($assignment, $attempt, $words, $state, $result === null ? [] : ['correct' => $result]));
