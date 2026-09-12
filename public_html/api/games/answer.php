<?php
/**
 * POST /api/games/answer.php
 * Body: code, question_id, option_id, csrf_token
 *
 * Registra la respuesta de un jugador a la pregunta actualmente activa
 * y calcula sus puntos (base + bonificación por rapidez).
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
$optionId = (int) ($input['option_id'] ?? 0);

if ($code === '' || $questionId <= 0 || $optionId <= 0) {
    json_response(['success' => false, 'message' => 'Respuesta inválida.'], 400);
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

$questions = activity_fetch_questions($pdo, (int) $game['activity_id']);
$game = game_auto_advance_if_expired($pdo, $game, $questions);

if ($game['status'] !== 'question') {
    json_response(['success' => false, 'message' => 'Esta pregunta ya no acepta respuestas.'], 400);
}

$idx = (int) $game['current_question_index'];
$question = $questions[$idx] ?? null;

if (!$question || (int) $question['id'] !== $questionId) {
    json_response(['success' => false, 'message' => 'La pregunta activa cambió, recarga la pantalla.'], 400);
}

// Verificar que la opción pertenece a la pregunta
$validOption = null;
foreach ($question['options'] as $o) {
    if ((int) $o['id'] === $optionId) {
        $validOption = $o;
        break;
    }
}
if (!$validOption) {
    json_response(['success' => false, 'message' => 'Opción inválida.'], 400);
}

// ¿Ya respondió? (uq_answer_per_question también lo protege a nivel de BD)
$existing = $pdo->prepare('SELECT id FROM game_answers WHERE player_id = :player_id AND question_id = :question_id');
$existing->execute(['player_id' => $playerId, 'question_id' => $questionId]);
if ($existing->fetch()) {
    json_response(['success' => false, 'message' => 'Ya respondiste esta pregunta.'], 400);
}

$startedAt = new DateTime($game['current_question_started_at']);
$responseTimeMs = max(((new DateTime())->getTimestamp() - $startedAt->getTimestamp()) * 1000, 0);

$isCorrect = (int) $validOption['is_correct'] === 1;

// Obtener speed_bonus_max de la actividad
$actStmt = $pdo->prepare('SELECT speed_bonus_max FROM activities WHERE id = :id');
$actStmt->execute(['id' => $game['activity_id']]);
$speedBonusMax = (int) ($actStmt->fetch()['speed_bonus_max'] ?? 0);

$points = game_calculate_points(
    $isCorrect,
    (int) $question['points'],
    $speedBonusMax,
    (int) $question['time_seconds'],
    (int) $responseTimeMs
);

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        'INSERT INTO game_answers (game_id, player_id, question_id, option_id, is_correct, points_awarded, response_time_ms, answered_at)
         VALUES (:game_id, :player_id, :question_id, :option_id, :is_correct, :points, :response_time_ms, :answered_at)'
    );
    $insert->execute([
        'game_id'          => $game['id'],
        'player_id'        => $playerId,
        'question_id'      => $questionId,
        'option_id'        => $optionId,
        'is_correct'       => $isCorrect ? 1 : 0,
        'points'           => $points,
        'response_time_ms' => $responseTimeMs,
        'answered_at'      => now_datetime(),
    ]);

    if ($points > 0) {
        $pdo->prepare('UPDATE game_players SET score = score + :points WHERE id = :id')
            ->execute(['points' => $points, 'id' => $playerId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('answer.php error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'No fue posible registrar tu respuesta.'], 500);
}

json_response(['success' => true, 'is_correct' => $isCorrect, 'points_awarded' => $points]);
