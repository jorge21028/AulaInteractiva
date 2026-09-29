<?php
/**
 * POST /api/games/crossword_save.php
 * Body: code, question_id, cells ({"fila,columna": "LETRA"}), final (bool), csrf_token
 *
 * CRUCIGRAMA: guarda el avance del estudiante (autoguardado, borrador) o, con final = true,
 * envía el crucigrama: se califica en el servidor palabra por palabra.
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
$final = !empty($input['final']);

if ($code === '' || $questionId <= 0) {
    json_response(['success' => false, 'message' => 'Solicitud inválida.'], 400);
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

$steps = game_fetch_steps($pdo, (int) $game['activity_id']);
$game = game_auto_advance_if_expired($pdo, $game, $steps);

$idx = (int) $game['current_question_index'];
$step = $steps[$idx] ?? null;

if ($game['status'] !== 'question' || !$step || $step['type'] !== 'crucigrama' || (int) $step['id'] !== $questionId) {
    // Si el tiempo terminó justo ahora, lo que ya estaba autoguardado se califica al pasar a resultados.
    json_response(['success' => false, 'message' => 'El tiempo del crucigrama terminó.', 'expired' => true], 400);
}

$cells = crossword_sanitize_cells($step['layout'], $input['cells'] ?? []);

// Guardar borrador sin pisar una respuesta ya enviada (done = 1).
$draft = json_encode(['done' => 0, 'cells' => (object) $cells], JSON_UNESCAPED_UNICODE);
$pdo->prepare(
    "INSERT INTO game_answers (game_id, player_id, question_id, option_id, answer_data, is_correct, points_awarded, response_time_ms, answered_at)
     VALUES (:game_id, :player_id, :question_id, NULL, :data, 0, 0, NULL, :answered_at)
     ON DUPLICATE KEY UPDATE answer_data = IF(answer_data LIKE '{\"done\":0%', VALUES(answer_data), answer_data)"
)->execute([
    'game_id' => $game['id'], 'player_id' => $playerId, 'question_id' => $questionId,
    'data' => $draft, 'answered_at' => now_datetime(),
]);

if (!$final) {
    json_response(['success' => true, 'saved' => true]);
}

$startedAt = new DateTime($game['current_question_started_at']);
$responseTimeMs = max(((new DateTime())->getTimestamp() - $startedAt->getTimestamp()) * 1000, 0);

$actStmt = $pdo->prepare('SELECT speed_bonus_max FROM activities WHERE id = :id');
$actStmt->execute(['id' => $game['activity_id']]);
$speedBonusMax = (int) ($actStmt->fetch()['speed_bonus_max'] ?? 0);

try {
    $result = crossword_finalize_answer($pdo, (int) $game['id'], $playerId, $step, $speedBonusMax, $cells, (int) $responseTimeMs);
} catch (Throwable $e) {
    error_log('crossword_save.php error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'No fue posible enviar el crucigrama.'], 500);
}

if ($result === null) {
    json_response(['success' => true, 'already_done' => true]);
}

json_response([
    'success' => true, 'final' => true,
    'correct_words' => $result['correct'], 'total_words' => $result['total'], 'points_awarded' => $result['points'],
]);
