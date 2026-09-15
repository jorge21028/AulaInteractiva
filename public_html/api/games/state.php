<?php
/**
 * GET /api/games/state.php?code=XXXXXX&role=teacher|projector|student
 *
 * Devuelve el estado actual de la partida. Es el endpoint que consultan
 * cada 1-2 segundos las pantallas de profesor, proyector y estudiante.
 * No expone la respuesta correcta mientras la pregunta sigue activa.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/game_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$code = clean_string($_GET['code'] ?? '');
$role = clean_string($_GET['role'] ?? 'projector');

if ($code === '') {
    json_response(['success' => false, 'message' => 'Falta el código de partida.'], 400);
}

$pdo = Database::getConnection();
$game = game_find_by_code($pdo, $code);

if (!$game) {
    json_response(['success' => false, 'message' => 'No existe ninguna partida con ese código.'], 404);
}

// ---- Control de acceso según el rol declarado ----
$playerId = null;

if ($role === 'teacher') {
    require_role('teacher');
    if ((int) $game['teacher_id'] !== current_user_id()) {
        json_response(['success' => false, 'message' => 'Esta partida no te pertenece.'], 403);
    }
} elseif ($role === 'student') {
    require_role('student');
    $pStmt = $pdo->prepare('SELECT id FROM game_players WHERE game_id = :game_id AND student_id = :student_id LIMIT 1');
    $pStmt->execute(['game_id' => $game['id'], 'student_id' => current_user_id()]);
    $player = $pStmt->fetch();
    if (!$player) {
        json_response(['success' => false, 'message' => 'Todavía no te has unido a esta partida.'], 403);
    }
    $playerId = (int) $player['id'];
}
// role === 'projector': acceso abierto (pantalla de proyección en el salón), sin datos sensibles.

$questions = activity_fetch_questions($pdo, (int) $game['activity_id']);
$game = game_auto_advance_if_expired($pdo, $game, $questions);

$activityStmt = $pdo->prepare('SELECT title, ranking_enabled FROM activities WHERE id = :id');
$activityStmt->execute(['id' => $game['activity_id']]);
$activity = $activityStmt->fetch();

$players = game_ranking($pdo, (int) $game['id']);

$response = [
    'success' => true,
    'game' => [
        'code'                  => $game['code'],
        'status'                => $game['status'],
        'current_question_index'=> (int) $game['current_question_index'],
        'total_questions'       => count($questions),
        'activity_title'        => $activity['title'] ?? '',
        'ranking_enabled'       => (bool) ($activity['ranking_enabled'] ?? true),
        'players_count'         => count($players),
    ],
    'players' => array_map(fn($p) => ['nickname' => $p['nickname'], 'score' => (int) $p['score']], $players),
    'question' => null,
    'results'  => null,
];

$idx = (int) $game['current_question_index'];

if ($game['status'] === 'question' && isset($questions[$idx])) {
    $q = $questions[$idx];
    $startedAt = new DateTime($game['current_question_started_at']);
    $elapsed = (new DateTime())->getTimestamp() - $startedAt->getTimestamp();
    $remaining = max((int) $q['time_seconds'] - $elapsed, 0);

    $myAnswer = null;
    if ($playerId) {
        $aStmt = $pdo->prepare('SELECT option_id FROM game_answers WHERE player_id = :player_id AND question_id = :question_id');
        $aStmt->execute(['player_id' => $playerId, 'question_id' => $q['id']]);
        $existing = $aStmt->fetch();
        $myAnswer = $existing ? (int) $existing['option_id'] : null;
    }

    $response['question'] = [
        'id'             => (int) $q['id'],
        'statement'      => $q['statement'],
        'type'           => $q['type'],
        'time_seconds'   => (int) $q['time_seconds'],
        'time_remaining' => $remaining,
        'points'         => (int) $q['points'],
        'image_url'      => $q['image_path'] ? rtrim(APP_URL, '/') . '/' . $q['image_path'] : null,
        'options'        => array_map(fn($o) => ['id' => (int) $o['id'], 'text' => $o['text']], $q['options']),
        'answered_count' => game_answered_count($pdo, (int) $game['id'], (int) $q['id']),
        'already_answered' => $myAnswer !== null,
        'my_option_id'   => $myAnswer,
    ];
}

if ($game['status'] === 'question_results' && isset($questions[$idx])) {
    $q = $questions[$idx];
    $distribution = game_answer_distribution($pdo, (int) $game['id'], (int) $q['id']);
    $correctOption = null;

    $optionsOut = [];
    foreach ($q['options'] as $o) {
        if ((int) $o['is_correct'] === 1) {
            $correctOption = (int) $o['id'];
        }
        $optionsOut[] = [
            'id'         => (int) $o['id'],
            'text'       => $o['text'],
            'is_correct' => (bool) $o['is_correct'],
            'count'      => $distribution[(int) $o['id']] ?? 0,
        ];
    }

    $myResult = null;
    if ($playerId) {
        $aStmt = $pdo->prepare('SELECT option_id, is_correct, points_awarded FROM game_answers WHERE player_id = :player_id AND question_id = :question_id');
        $aStmt->execute(['player_id' => $playerId, 'question_id' => $q['id']]);
        $mine = $aStmt->fetch();
        if ($mine) {
            $myResult = [
                'option_id'      => $mine['option_id'] !== null ? (int) $mine['option_id'] : null,
                'is_correct'     => (bool) $mine['is_correct'],
                'points_awarded' => (int) $mine['points_awarded'],
            ];
        }
    }

    $response['results'] = [
        'question_id'     => (int) $q['id'],
        'statement'        => $q['statement'],
        'image_url'        => $q['image_path'] ? rtrim(APP_URL, '/') . '/' . $q['image_path'] : null,
        'explanation'      => $q['explanation'],
        'correct_option_id'=> $correctOption,
        'options'          => $optionsOut,
        'my_result'        => $myResult,
    ];
}

json_response($response);
