<?php
/**
 * POST /api/games/host_action.php
 * Body (JSON o form-urlencoded): code, action ('next' | 'finish'), csrf_token
 *
 * Controla el avance de una partida: pasar a la siguiente pregunta
 * (o a resultados si ya se está mostrando una) y finalizar la partida.
 * Solo el profesor dueño de la partida puede usar este endpoint.
 *
 * Opcionales: expected_status y expected_index = el estado que el profesor
 * tenía en pantalla al pulsar el botón. Si la partida ya no está en ese
 * estado (doble clic, botón repetido, otra pestaña), la acción se ignora y
 * se devuelve el estado real con stale=true. Así un doble clic NUNCA se
 * salta una pregunta.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/game_helpers.php';
require_once __DIR__ . '/../../includes/assignment_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('teacher');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;

csrf_verify($input['csrf_token'] ?? null);

$code = clean_string($input['code'] ?? '');
$action = clean_string($input['action'] ?? '');
$expectedStatus = isset($input['expected_status']) ? clean_string((string) $input['expected_status']) : null;
$expectedIndex = isset($input['expected_index']) && $input['expected_index'] !== '' ? (int) $input['expected_index'] : null;

if ($code === '' || !in_array($action, ['next', 'finish'], true)) {
    json_response(['success' => false, 'message' => 'Parámetros inválidos.'], 400);
}

$pdo = Database::getConnection();
$game = game_find_by_code($pdo, $code);

if (!$game || (int) $game['teacher_id'] !== current_user_id()) {
    json_response(['success' => false, 'message' => 'Partida no encontrada o no te pertenece.'], 404);
}

if ($game['status'] === 'finished') {
    json_response(['success' => true, 'status' => 'finished']);
}

// Guardia anti doble clic: la acción solo se aplica si la partida sigue en el
// estado que el profesor estaba viendo.
if ($expectedStatus !== null && $expectedStatus !== $game['status']) {
    json_response(['success' => true, 'stale' => true, 'status' => $game['status'], 'current_question_index' => (int) $game['current_question_index']]);
}
if ($expectedIndex !== null && $expectedIndex !== (int) $game['current_question_index']) {
    json_response(['success' => true, 'stale' => true, 'status' => $game['status'], 'current_question_index' => (int) $game['current_question_index']]);
}

$questions = activity_fetch_questions($pdo, (int) $game['activity_id']);

if ($action === 'finish') {
    $stmt = $pdo->prepare("UPDATE games SET status = 'finished', finished_at = :finished_at WHERE id = :id AND status != 'finished'");
    $stmt->execute(['finished_at' => now_datetime(), 'id' => $game['id']]);
    audit_log($pdo, current_user_id(), 'game_finish', "Partida {$code} finalizada manualmente");
    assignment_sync_from_game($pdo, (int) $game['id']);
    json_response(['success' => true, 'status' => 'finished']);
}

// action === 'next'
// Si estamos en 'waiting' o 'question_results', avanzamos a la siguiente pregunta.
// Si estamos en 'question' (el profesor decide saltarse el tiempo restante), también avanzamos a resultados.
if ($game['status'] === 'question') {
    // Condicional: si el tiempo ya venció y el polling lo pasó a resultados, no pasa nada.
    $stmt = $pdo->prepare("UPDATE games SET status = 'question_results' WHERE id = :id AND status = 'question' AND current_question_index = :idx");
    $stmt->execute(['id' => $game['id'], 'idx' => (int) $game['current_question_index']]);
    json_response(['success' => true, 'status' => 'question_results', 'current_question_index' => (int) $game['current_question_index']]);
}

$game = game_advance($pdo, $game, $questions);

if (empty($game['_advanced'])) {
    // Otra petición ya avanzó la partida: no se hace nada más.
    json_response(['success' => true, 'stale' => true, 'status' => $game['status'], 'current_question_index' => (int) $game['current_question_index']]);
}

audit_log($pdo, current_user_id(), 'game_advance', "Partida {$code} avanzó a índice {$game['current_question_index']}");

if ($game['status'] === 'finished') {
    assignment_sync_from_game($pdo, (int) $game['id']);
}

json_response(['success' => true, 'status' => $game['status'], 'current_question_index' => (int) $game['current_question_index']]);
