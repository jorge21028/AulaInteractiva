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
    $alreadyAnswered = false;
    if ($playerId) {
        $aStmt = $pdo->prepare('SELECT option_id FROM game_answers WHERE player_id = :player_id AND question_id = :question_id');
        $aStmt->execute(['player_id' => $playerId, 'question_id' => $q['id']]);
        $existing = $aStmt->fetch();
        $alreadyAnswered = (bool) $existing;
        $myAnswer = $existing && $existing['option_id'] !== null ? (int) $existing['option_id'] : null;
    }

    $response['question'] = [
        'id'             => (int) $q['id'],
        'statement'      => $q['statement'],
        'type'           => $q['type'],
        'time_seconds'   => (int) $q['time_seconds'],
        'time_remaining' => $remaining,
        'points'         => (int) $q['points'],
        'image_url'      => $q['image_path'] ? rtrim(APP_URL, '/') . '/' . $q['image_path'] : null,
        'answered_count' => game_answered_count($pdo, (int) $game['id'], (int) $q['id']),
        'already_answered' => $alreadyAnswered,
        'my_option_id'   => $myAnswer,
    ];

    if ($q['type'] === 'multiple' || $q['type'] === 'truefalse') {
        $response['question']['options'] = array_map(fn($o) => ['id' => (int) $o['id'], 'text' => $o['text']], $q['options']);
    } elseif ($q['type'] === 'ordenar') {
        $shuffled = game_stable_shuffle(
            array_map(fn($o) => ['id' => (int) $o['id'], 'text' => $o['text']], $q['options']),
            (int) $game['id'] * 1000003 + (int) $q['id']
        );
        $response['question']['items'] = $shuffled;
    } elseif ($q['type'] === 'relacionar') {
        $response['question']['left_items'] = array_map(fn($o) => ['id' => (int) $o['id'], 'text' => $o['text']], $q['options']);
        $response['question']['right_items'] = game_shuffled_right_items($q['options'], (int) $game['id'], (int) $q['id']);
    }
    // 'completar' no necesita opciones: el estudiante escribe la respuesta.
}

if ($game['status'] === 'question_results' && isset($questions[$idx])) {
    $q = $questions[$idx];

    $myAnswerRow = null;
    if ($playerId) {
        $aStmt = $pdo->prepare('SELECT option_id, answer_data, is_correct, points_awarded FROM game_answers WHERE player_id = :player_id AND question_id = :question_id');
        $aStmt->execute(['player_id' => $playerId, 'question_id' => $q['id']]);
        $myAnswerRow = $aStmt->fetch() ?: null;
    }

    $response['results'] = [
        'question_id' => (int) $q['id'],
        'statement'   => $q['statement'],
        'image_url'   => $q['image_path'] ? rtrim(APP_URL, '/') . '/' . $q['image_path'] : null,
        'explanation' => $q['explanation'],
        'type'        => $q['type'],
    ];

    if ($q['type'] === 'multiple' || $q['type'] === 'truefalse') {
        $distribution = game_answer_distribution($pdo, (int) $game['id'], (int) $q['id']);
        $correctOption = null;
        $optionsOut = [];
        foreach ($q['options'] as $o) {
            if ((int) $o['is_correct'] === 1) {
                $correctOption = (int) $o['id'];
            }
            $optionsOut[] = [
                'id' => (int) $o['id'], 'text' => $o['text'], 'is_correct' => (bool) $o['is_correct'],
                'count' => $distribution[(int) $o['id']] ?? 0,
            ];
        }
        $response['results']['correct_option_id'] = $correctOption;
        $response['results']['options'] = $optionsOut;
        $response['results']['my_result'] = $myAnswerRow ? [
            'option_id' => $myAnswerRow['option_id'] !== null ? (int) $myAnswerRow['option_id'] : null,
            'is_correct' => (bool) $myAnswerRow['is_correct'],
            'points_awarded' => (int) $myAnswerRow['points_awarded'],
        ] : null;
    } elseif ($q['type'] === 'ordenar') {
        $correctOrder = $q['options'];
        usort($correctOrder, fn($a, $b) => $a['order_index'] <=> $b['order_index']);
        $response['results']['correct_order'] = array_map(fn($o) => $o['text'], $correctOrder);
        $response['results']['my_result'] = $myAnswerRow ? [
            'is_correct' => (bool) $myAnswerRow['is_correct'],
            'points_awarded' => (int) $myAnswerRow['points_awarded'],
        ] : null;
    } elseif ($q['type'] === 'relacionar') {
        $response['results']['correct_pairs'] = array_map(
            fn($o) => ['left' => $o['text'], 'right' => $o['match_text']],
            $q['options']
        );
        $response['results']['my_result'] = $myAnswerRow ? [
            'is_correct' => (bool) $myAnswerRow['is_correct'],
            'points_awarded' => (int) $myAnswerRow['points_awarded'],
        ] : null;
    } elseif ($q['type'] === 'completar') {
        $response['results']['correct_answer'] = $q['options'][0]['text'] ?? '';
        $response['results']['my_result'] = $myAnswerRow ? [
            'submitted' => $myAnswerRow['answer_data'] !== null ? json_decode($myAnswerRow['answer_data'], true) : null,
            'is_correct' => (bool) $myAnswerRow['is_correct'],
            'points_awarded' => (int) $myAnswerRow['points_awarded'],
        ] : null;
    }
}

json_response($response);
