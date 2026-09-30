<?php
/**
 * POST /api/wordgames/hangman.php
 * Body: assignment_id, action ('state' | 'guess' | 'restart'), [question_id, letter], csrf_token
 *
 * AHORCADO individual (sin tiempo). La palabra nunca viaja al navegador: el servidor guarda las
 * letras probadas, calcula qué posiciones se revelan y cuántas vidas quedan. Solo se revela la
 * palabra cuando ya se terminó (acertada o perdida).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/word_play_helpers.php';

[$input, $studentId] = wga_api_bootstrap();

$assignmentId = (int) ($input['assignment_id'] ?? 0);
$action = clean_string($input['action'] ?? 'state');

$pdo = Database::getConnection();
$assignment = wga_load_assignment($pdo, $assignmentId, $studentId);
if (!$assignment || $assignment['game_mode'] !== 'ahorcado') {
    json_response(['success' => false, 'message' => 'Actividad no encontrada.'], 404);
}

$words = word_activity_words($pdo, (int) $assignment['activity_id']);
if (empty($words)) {
    json_response(['success' => false, 'message' => 'Esta actividad todavía no tiene palabras.'], 400);
}

$attempt = wga_ensure_attempt($pdo, $assignment, $studentId);

if ($action === 'state') {
    json_response(wga_hangman_view($assignment, $attempt, $words, word_game_decode_answer($attempt['state_json'])));
}

if ($action === 'restart') {
    if ((int) $assignment['allow_repeat'] !== 1 || $attempt['status'] !== 'completed') {
        json_response(['success' => false, 'message' => 'No puedes repetir esta actividad.'], 400);
    }
    if (wga_is_overdue($assignment)) {
        json_response(['success' => false, 'message' => 'La fecha de entrega ya venció.'], 400);
    }
    wga_restart($pdo, (int) $attempt['id']);
    $attempt = wga_ensure_attempt($pdo, $assignment, $studentId);
    json_response(wga_hangman_view($assignment, $attempt, $words, []));
}

if ($action !== 'guess') {
    json_response(['success' => false, 'message' => 'Acción no válida.'], 400);
}

if (wga_is_overdue($assignment)) {
    json_response(['success' => false, 'message' => 'La fecha de entrega ya venció.'], 400);
}

$questionId = (int) ($input['question_id'] ?? 0);
$letter = word_normalize_char(mb_substr((string) ($input['letter'] ?? ''), 0, 1, 'UTF-8'));
if ($questionId <= 0 || $letter === '') {
    json_response(['success' => false, 'message' => 'Letra inválida.'], 400);
}

$finishedWord = null;
$completion = null;

$pdo->beginTransaction();
try {
    $attempt = wga_lock_attempt($pdo, (int) $attempt['id']);
    $state = word_game_decode_answer($attempt['state_json']);

    if ($attempt['status'] !== 'in_progress') {
        $pdo->rollBack();
        json_response(wga_hangman_view($assignment, $attempt, $words, $state));
    }

    $cur = wga_hangman_current($words, $state);
    if (!$cur || (int) $cur[1]['id'] !== $questionId) {
        // Otra pestaña ya avanzó: devolvemos el estado real para que la pantalla se ponga al día.
        $pdo->rollBack();
        $view = wga_hangman_view($assignment, $attempt, $words, $state);
        $view['stale'] = true;
        json_response($view);
    }

    $word = $cur[1];
    $key = (string) $word['id'];
    $guesses = is_array($state['w'][$key]['g'] ?? null) ? $state['w'][$key]['g'] : [];
    if (!in_array($letter, $guesses, true)) {
        $guesses[] = $letter;
    }

    $hs = hangman_state($word['word'], $guesses);
    $state['w'][$key] = ['g' => $guesses, 'done' => 0, 'solved' => 0];

    if ($hs['solved'] || $hs['lost']) {
        $state['w'][$key]['done'] = 1;
        $state['w'][$key]['solved'] = $hs['solved'] ? 1 : 0;
        $finishedWord = ['id' => $word['id'], 'solved' => $hs['solved'], 'answer' => mb_strtoupper($word['word'], 'UTF-8')];
    }

    wga_save_state($pdo, (int) $attempt['id'], $state);

    if ($finishedWord && wga_hangman_current($words, $state) === null) {
        [$solvedCount] = wga_hangman_counts($words, $state);
        $completion = wga_complete($pdo, $assignment, (int) $attempt['id'], $studentId, $solvedCount, count($words));
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('wordgames/hangman.php error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'No fue posible registrar la letra.'], 500);
}

$attempt = wga_ensure_attempt($pdo, $assignment, $studentId);
$view = wga_hangman_view($assignment, $attempt, $words, $state);

// Vista de la palabra que se acaba de jugar (para mostrar el resultado aunque el intento ya cerró)
$playedGuesses = $state['w'][(string) $cur[1]['id']]['g'] ?? [];
$hsPlayed = hangman_state($cur[1]['word'], $playedGuesses);
$view['played'] = [
    'id' => $cur[1]['id'], 'letter' => $letter,
    'letter_correct' => !in_array($letter, $hsPlayed['wrong'], true),
    'hangman' => [
        'pattern' => $hsPlayed['pattern'], 'guessed' => $hsPlayed['guessed'], 'wrong' => $hsPlayed['wrong'],
        'lives_left' => $hsPlayed['lives_left'], 'max_lives' => $hsPlayed['max_lives'],
    ],
];
if ($finishedWord) {
    $view['finished_word'] = $finishedWord;
}
json_response($view);
