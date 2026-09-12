<?php
/**
 * POST /api/games/host_action.php
 * Body (JSON o form-urlencoded): code, action ('next' | 'finish'), csrf_token
 *
 * Controla el avance de una partida: pasar a la siguiente pregunta
 * (o a resultados si ya se está mostrando una) y finalizar la partida.
 * Solo el profesor dueño de la partida puede usar este endpoint.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/game_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('teacher');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;

csrf_verify($input['csrf_token'] ?? null);

$code = clean_string($input['code'] ?? '');
$action = clean_string($input['action'] ?? '');

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

$questions = activity_fetch_questions($pdo, (int) $game['activity_id']);

if ($action === 'finish') {
    $stmt = $pdo->prepare("UPDATE games SET status = 'finished', finished_at = :finished_at WHERE id = :id");
    $stmt->execute(['finished_at' => now_datetime(), 'id' => $game['id']]);
    audit_log($pdo, current_user_id(), 'game_finish', "Partida {$code} finalizada manualmente");
    json_response(['success' => true, 'status' => 'finished']);
}

// action === 'next'
// Si estamos en 'waiting' o 'question_results', avanzamos a la siguiente pregunta.
// Si estamos en 'question' (el profesor decide saltarse el tiempo restante), también avanzamos a resultados.
if ($game['status'] === 'question') {
    $stmt = $pdo->prepare("UPDATE games SET status = 'question_results' WHERE id = :id");
    $stmt->execute(['id' => $game['id']]);
    json_response(['success' => true, 'status' => 'question_results']);
}

$game = game_advance($pdo, $game, $questions);
audit_log($pdo, current_user_id(), 'game_advance', "Partida {$code} avanzó a índice {$game['current_question_index']}");

json_response(['success' => true, 'status' => $game['status'], 'current_question_index' => (int) $game['current_question_index']]);
