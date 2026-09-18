<?php
/**
 * POST /api/games/answer.php
 * Body: code, question_id, csrf_token, y según el tipo de pregunta:
 *   - multiple/truefalse: option_id
 *   - ordenar: answer_data = [option_id, option_id, ...] (el orden elegido)
 *   - relacionar: answer_data = [{option_id, right_index}, ...]
 *   - completar: answer_data = "texto escrito por el estudiante"
 *
 * Registra la respuesta de un jugador a la pregunta actualmente activa
 * y calcula sus puntos (base + bonificación por rapidez), con crédito
 * parcial para ordenar/relacionar según el porcentaje de acierto.
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
$answerData = $input['answer_data'] ?? null;

if ($code === '' || $questionId <= 0) {
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

// ¿Ya respondió? (uq_answer_per_question también lo protege a nivel de BD)
$existing = $pdo->prepare('SELECT id FROM game_answers WHERE player_id = :player_id AND question_id = :question_id');
$existing->execute(['player_id' => $playerId, 'question_id' => $questionId]);
if ($existing->fetch()) {
    json_response(['success' => false, 'message' => 'Ya respondiste esta pregunta.'], 400);
}

$startedAt = new DateTime($game['current_question_started_at']);
$responseTimeMs = max(((new DateTime())->getTimestamp() - $startedAt->getTimestamp()) * 1000, 0);

$actStmt = $pdo->prepare('SELECT speed_bonus_max FROM activities WHERE id = :id');
$actStmt->execute(['id' => $game['activity_id']]);
$speedBonusMax = (int) ($actStmt->fetch()['speed_bonus_max'] ?? 0);

$storeOptionId = null;
$storeAnswerData = null;
$isCorrect = false;
$ratio = 0.0;

switch ($question['type']) {
    case 'multiple':
    case 'truefalse':
        if ($optionId <= 0) {
            json_response(['success' => false, 'message' => 'Respuesta inválida.'], 400);
        }
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
        $isCorrect = (int) $validOption['is_correct'] === 1;
        $ratio = $isCorrect ? 1.0 : 0.0;
        $storeOptionId = $optionId;
        break;

    case 'ordenar':
        if (!is_array($answerData) || empty($answerData)) {
            json_response(['success' => false, 'message' => 'Debes enviar el orden elegido.'], 400);
        }
        $submittedOrder = array_map('intval', $answerData);
        $validIds = array_map(fn($o) => (int) $o['id'], $question['options']);
        foreach ($submittedOrder as $id) {
            if (!in_array($id, $validIds, true)) {
                json_response(['success' => false, 'message' => 'Respuesta inválida.'], 400);
            }
        }
        $ratio = game_score_ordenar($question['options'], $submittedOrder);
        $isCorrect = $ratio >= 0.999;
        $storeAnswerData = json_encode($submittedOrder);
        break;

    case 'relacionar':
        if (!is_array($answerData) || empty($answerData)) {
            json_response(['success' => false, 'message' => 'Debes enviar las parejas elegidas.'], 400);
        }
        $shuffledRight = game_shuffled_right_items($question['options'], (int) $game['id'], $questionId);
        $ratio = game_score_relacionar($question['options'], $answerData, $shuffledRight);
        $isCorrect = $ratio >= 0.999;
        $storeAnswerData = json_encode($answerData);
        break;

    case 'completar':
        $submittedText = is_string($answerData) ? trim($answerData) : '';
        if ($submittedText === '') {
            json_response(['success' => false, 'message' => 'Escribe una respuesta.'], 400);
        }
        $correctAnswer = $question['options'][0]['text'] ?? '';
        $ratio = game_score_completar($correctAnswer, $submittedText);
        $isCorrect = $ratio >= 0.999;
        $storeAnswerData = json_encode($submittedText);
        break;

    default:
        json_response(['success' => false, 'message' => 'Tipo de pregunta no soportado.'], 400);
}

$points = game_calculate_points_ratio(
    $ratio,
    (int) $question['points'],
    $speedBonusMax,
    (int) $question['time_seconds'],
    (int) $responseTimeMs
);

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        'INSERT INTO game_answers (game_id, player_id, question_id, option_id, answer_data, is_correct, points_awarded, response_time_ms, answered_at)
         VALUES (:game_id, :player_id, :question_id, :option_id, :answer_data, :is_correct, :points, :response_time_ms, :answered_at)'
    );
    $insert->execute([
        'game_id'          => $game['id'],
        'player_id'        => $playerId,
        'question_id'      => $questionId,
        'option_id'        => $storeOptionId,
        'answer_data'      => $storeAnswerData,
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

json_response(['success' => true, 'is_correct' => $isCorrect, 'ratio' => round($ratio, 2), 'points_awarded' => $points]);
