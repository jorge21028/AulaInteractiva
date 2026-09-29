<?php
/**
 * POST /api/games/hangman_guess.php
 * Body: code, question_id, letter, csrf_token
 *
 * AHORCADO: el estudiante prueba UNA letra. La palabra nunca viaja al navegador:
 * el servidor guarda las letras probadas, calcula qué posiciones se revelan y cuántas
 * vidas quedan, y solo cuando la palabra se completa (o se agotan las vidas) cierra la
 * pregunta y otorga los puntos.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/game_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;
csrf_verify($input['csrf_token'] ?? null);

$code = clean_string($input['code'] ?? '');
$questionId = (int) ($input['question_id'] ?? 0);
$letter = word_normalize_char(mb_substr((string) ($input['letter'] ?? ''), 0, 1, 'UTF-8'));

if ($code === '' || $questionId <= 0 || $letter === '') {
    json_response(['success' => false, 'message' => 'Letra inválida.'], 400);
}

$pdo = Database::getConnection();
$game = game_find_by_code($pdo, $code);
if (!$game) {
    json_response(['success' => false, 'message' => 'Partida no encontrada.'], 404);
}

$pStmt = $pdo->prepare('SELECT id FROM game_players WHERE game_id = :game_id AND student_id = :student_id');
$pStmt->execute(['game_id' => $game['id'], 'student_id' => current_user_id()]);
$player = $pStmt->fetch();
if (!$player) {
    json_response(['success' => false, 'message' => 'No estás unido a esta partida.'], 403);
}
$playerId = (int) $player['id'];

$questions = game_fetch_steps($pdo, (int) $game['activity_id']);
$game = game_auto_advance_if_expired($pdo, $game, $questions);

if ($game['status'] !== 'question') {
    json_response(['success' => false, 'message' => 'El tiempo de esta palabra terminó.', 'expired' => true], 400);
}

$idx = (int) $game['current_question_index'];
$question = $questions[$idx] ?? null;
if (!$question || (int) $question['id'] !== $questionId || $question['type'] !== 'palabra') {
    json_response(['success' => false, 'message' => 'La palabra activa cambió, recarga la pantalla.'], 400);
}

$word = (string) ($question['options'][0]['text'] ?? '');
$startedAt = new DateTime($game['current_question_started_at']);
$responseTimeMs = max(((new DateTime())->getTimestamp() - $startedAt->getTimestamp()) * 1000, 0);

$actStmt = $pdo->prepare('SELECT speed_bonus_max FROM activities WHERE id = :id');
$actStmt->execute(['id' => $game['activity_id']]);
$speedBonusMax = (int) ($actStmt->fetch()['speed_bonus_max'] ?? 0);

$pdo->beginTransaction();
try {
    // Crea la fila en borrador la primera vez (la marca {"done":0 permite distinguirla de una respuesta cerrada)
    $pdo->prepare(
        'INSERT IGNORE INTO game_answers (game_id, player_id, question_id, option_id, answer_data, is_correct, points_awarded, response_time_ms, answered_at)
         VALUES (:game_id, :player_id, :question_id, NULL, :data, 0, 0, NULL, :answered_at)'
    )->execute([
        'game_id' => $game['id'], 'player_id' => $playerId, 'question_id' => $questionId,
        'data' => json_encode(['done' => 0, 'g' => []]), 'answered_at' => now_datetime(),
    ]);

    $sel = $pdo->prepare('SELECT id, answer_data FROM game_answers WHERE player_id = :player_id AND question_id = :question_id FOR UPDATE');
    $sel->execute(['player_id' => $playerId, 'question_id' => $questionId]);
    $row = $sel->fetch();

    $data = word_game_decode_answer($row['answer_data']);
    $guesses = is_array($data['g'] ?? null) ? $data['g'] : [];

    if (!empty($data['done'])) {
        $pdo->rollBack();
        $state = hangman_state($word, $guesses);
        json_response(['success' => true, 'already_done' => true, 'hangman' => $state + ['done' => true]]);
    }

    if (!in_array($letter, $guesses, true)) {
        $guesses[] = $letter;
    }
    $state = hangman_state($word, $guesses);
    $points = 0;
    $finished = $state['solved'] || $state['lost'];

    if ($finished) {
        // Resolver = puntaje completo escalado por las vidas que quedaron (mín. 50 %); perder = 0.
        $ratio = $state['solved'] ? 0.5 + 0.5 * ($state['lives_left'] / $state['max_lives']) : 0.0;
        $points = game_calculate_points_ratio(
            $ratio, (int) $question['points'], $speedBonusMax, (int) $question['time_seconds'], (int) $responseTimeMs
        );
        $payload = ['done' => 1, 'g' => $guesses, 'solved' => $state['solved'], 'lives_left' => $state['lives_left']];
    } else {
        $payload = ['done' => 0, 'g' => $guesses];
    }

    $pdo->prepare(
        'UPDATE game_answers SET answer_data = :data, is_correct = :ok, points_awarded = :points, response_time_ms = :rt, answered_at = :at WHERE id = :id'
    )->execute([
        'data' => json_encode($payload), 'ok' => ($finished && $state['solved']) ? 1 : 0,
        'points' => $points, 'rt' => $finished ? $responseTimeMs : null, 'at' => now_datetime(), 'id' => $row['id'],
    ]);

    if ($points > 0) {
        $pdo->prepare('UPDATE game_players SET score = score + :points WHERE id = :id')
            ->execute(['points' => $points, 'id' => $playerId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('hangman_guess.php error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'No fue posible registrar la letra.'], 500);
}

$response = ['success' => true, 'hangman' => $state + ['done' => $finished], 'letter_correct' => !in_array($letter, $state['wrong'], true)];
if ($finished) {
    $response['points_awarded'] = $points;
    $response['answer'] = mb_strtoupper($word, 'UTF-8'); // ya terminó: se puede mostrar la palabra completa
}
json_response($response);
