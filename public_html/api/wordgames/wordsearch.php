<?php
/**
 * POST /api/wordgames/wordsearch.php
 * Body: assignment_id, action ('load' | 'select' | 'submit' | 'restart'), [r1,c1,r2,c2], csrf_token
 *
 * SOPA DE LETRAS individual (sin tiempo). El estudiante marca una línea de la cuadrícula ('select') y el servidor
 * comprueba si deletrea una palabra pendiente. Las posiciones de las palabras nunca se envían al navegador.
 * Si encuentra todas, la entrega se califica sola; también puede entregar antes ('submit').
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
if (!$assignment || $assignment['game_mode'] !== 'sopa_letras') {
    json_response(['success' => false, 'message' => 'Actividad no encontrada.'], 404);
}

$bank = word_activity_words($pdo, (int) $assignment['activity_id']);
if (empty($bank)) {
    json_response(['success' => false, 'message' => 'Esta sopa de letras todavía no tiene palabras.'], 400);
}

/** Intento + palabras sorteadas + semilla + tablero. */
function ws_context(PDO $pdo, array $assignment, int $studentId, array $bank): array
{
    $attempt = wga_ensure_attempt($pdo, $assignment, $studentId);
    $attempt = wga_ensure_selection($pdo, $assignment, $attempt, $bank);
    $attempt = wga_ensure_seed($pdo, $attempt);
    $words = wga_attempt_bank($bank, $attempt);
    $state = word_game_decode_answer($attempt['state_json']);
    $layout = wordsearch_build_layout($words, (int) $state['seed']);
    return [$attempt, $words, $state, $layout];
}

/** Respuesta estándar con el estado actual del intento. */
function ws_view(array $assignment, array $attempt, array $words, array $state, array $layout, array $extra = []): array
{
    $found = is_array($state['found'] ?? null) ? $state['found'] : [];
    $allowRepeat = (int) $assignment['allow_repeat'] === 1;
    $isDone = $attempt['status'] === 'completed';

    $list = [];
    foreach ($words as $w) {
        $f = $found[(string) $w['id']] ?? null;
        $item = [
            'id' => (int) $w['id'], 'clue' => $w['clue'],
            'length' => mb_strlen(word_letters($w['word']), 'UTF-8'),
            'found' => $f !== null,
        ];
        if ($f !== null) {
            $item['cells'] = array_map('intval', $f);
            $item['word'] = mb_strtoupper($w['word'], 'UTF-8');
        }
        // Terminado y sin repetición permitida: se muestran las respuestas y dónde estaban.
        if ($isDone && !$allowRepeat && $f === null && isset($layout['placed'][(int) $w['id']])) {
            $p = $layout['placed'][(int) $w['id']];
            $item['word'] = mb_strtoupper($w['word'], 'UTF-8');
            $item['solution'] = [$p['r'], $p['c'], $p['r'] + $p['dr'] * ($p['len'] - 1), $p['c'] + $p['dc'] * ($p['len'] - 1)];
        }
        $list[] = $item;
    }

    $view = [
        'success'      => true,
        'mode'         => 'sopa_letras',
        'status'       => $attempt['status'],
        'size'         => $layout['size'],
        'grid'         => $layout['grid'],
        'words'        => $list,
        'total'        => count($words),
        'found'        => count($found),
        'points'       => (int) $assignment['points'],
        'allow_repeat' => $allowRepeat,
        'overdue'      => wga_is_overdue($assignment),
    ];
    if ($isDone) {
        $total = (int) $attempt['total_count'];
        $ratio = $total > 0 ? (int) $attempt['correct_count'] / $total : 0;
        $view['result'] = [
            'correct' => (int) $attempt['correct_count'], 'total' => $total,
            'score' => round($ratio * (int) $assignment['points'], 2), 'points' => (int) $assignment['points'],
        ];
    }
    return $view + $extra;
}

[$attempt, $words, $state, $layout] = ws_context($pdo, $assignment, $studentId, $bank);

if ($action === 'load') {
    json_response(ws_view($assignment, $attempt, $words, $state, $layout));
}

if ($action === 'restart') {
    if ((int) $assignment['allow_repeat'] !== 1 || $attempt['status'] !== 'completed') {
        json_response(['success' => false, 'message' => 'No puedes repetir esta actividad.'], 400);
    }
    if (wga_is_overdue($assignment)) {
        json_response(['success' => false, 'message' => 'La fecha de entrega ya venció.'], 400);
    }
    wga_restart($pdo, (int) $attempt['id']);
    [$attempt, $words, $state, $layout] = ws_context($pdo, $assignment, $studentId, $bank);
    json_response(ws_view($assignment, $attempt, $words, $state, $layout));
}

if ($action !== 'select' && $action !== 'submit') {
    json_response(['success' => false, 'message' => 'Acción no válida.'], 400);
}

if (wga_is_overdue($assignment)) {
    json_response(['success' => false, 'message' => 'La fecha de entrega ya venció.', 'overdue' => true], 400);
}

$matchedId = null;
$pdo->beginTransaction();
try {
    $attempt = wga_lock_attempt($pdo, (int) $attempt['id']);
    if ($attempt['status'] !== 'in_progress') {
        $pdo->rollBack();
        json_response(ws_view($assignment, $attempt, $words, word_game_decode_answer($attempt['state_json']), $layout, ['already_done' => true]));
    }
    $state = word_game_decode_answer($attempt['state_json']);
    $found = is_array($state['found'] ?? null) ? $state['found'] : [];

    if ($action === 'select') {
        $pending = array_values(array_filter($words, fn($w) => !isset($found[(string) $w['id']])));
        $matchedId = wordsearch_check_selection(
            $layout, $pending,
            (int) ($input['r1'] ?? -1), (int) ($input['c1'] ?? -1), (int) ($input['r2'] ?? -1), (int) ($input['c2'] ?? -1)
        );
        if ($matchedId !== null) {
            $found[(string) $matchedId] = [(int) $input['r1'], (int) $input['c1'], (int) $input['r2'], (int) $input['c2']];
            $state['found'] = (object) $found;
            wga_save_state($pdo, (int) $attempt['id'], $state);
        }
    }

    $finish = $action === 'submit' || ($matchedId !== null && count($found) >= count($words));
    if ($finish) {
        wga_complete($pdo, $assignment, (int) $attempt['id'], $studentId, count($found), count($words));
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('wordgames/wordsearch.php error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'No fue posible guardar.'], 500);
}

$attempt = wga_ensure_attempt($pdo, $assignment, $studentId);
$state = word_game_decode_answer($attempt['state_json']);
json_response(ws_view($assignment, $attempt, $words, $state, $layout, ['hit' => $matchedId !== null, 'hit_id' => $matchedId]));
