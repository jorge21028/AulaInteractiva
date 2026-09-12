<?php
/**
 * POST /api/games/join.php
 * Body: code, nickname (opcional), csrf_token
 *
 * Une al estudiante autenticado a una partida activa mediante el código.
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
$nickname = clean_string($input['nickname'] ?? '') ?: (string) current_user_name();

if ($code === '') {
    json_response(['success' => false, 'message' => 'Ingresa un código de partida.'], 400);
}

$pdo = Database::getConnection();
$result = game_join_student($pdo, $code, current_user_id(), $nickname);

if (!$result['success']) {
    json_response(['success' => false, 'message' => $result['message']], 400);
}

json_response(['success' => true, 'code' => $result['game']['code']]);
