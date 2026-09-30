<?php
/**
 * POST /api/wordgames/crossword.php
 * Body: assignment_id, action ('load' | 'save' | 'submit' | 'restart'), [cells], csrf_token
 *
 * CRUCIGRAMA individual (sin tiempo). 'save' autoguarda el avance; 'submit' entrega y califica
 * en el servidor palabra por palabra. Las respuestas nunca se envían al navegador antes de entregar.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/word_play_helpers.php';

[$input, $studentId] = wga_api_bootstrap();

$assignmentId = (int) ($input['assignment_id'] ?? 0);
$action = clean_string($input['action'] ?? 'load');

$pdo = Database::getConnection();
$assignment = wga_load_assignment($pdo, $assignmentId, $studentId);
if (!$assignment || $assignment['game_mode'] !== 'crucigrama') {
    json_response(['success' => false, 'message' => 'Actividad no encontrada.'], 404);
}

$layout = crossword_build_layout(activity_fetch_questions($pdo, (int) $assignment['activity_id']));
if (empty($layout['words'])) {
    json_response(['success' => false, 'message' => 'Este crucigrama todavía no tiene palabras.'], 400);
}

$attempt = wga_ensure_attempt($pdo, $assignment, $studentId);

/** Respuesta estándar con el estado actual del intento. */
function cw_view(array $assignment, array $attempt, array $layout, array $extra = []): array
{
    $state = word_game_decode_answer($attempt['state_json']);
    $cells = crossword_sanitize_cells($layout, $state['cells'] ?? []);
    $allowRepeat = (int) $assignment['allow_repeat'] === 1;

    $view = [
        'success'      => true,
        'mode'         => 'crucigrama',
        'status'       => $attempt['status'],
        'layout'       => crossword_public_layout($layout),
        'cells'        => (object) $cells,
        'total'        => count($layout['words']),
        'points'       => (int) $assignment['points'],
        'allow_repeat' => $allowRepeat,
        'overdue'      => wga_is_overdue($assignment),
    ];

    if ($attempt['status'] === 'completed') {
        $grade = crossword_grade($layout, $cells);
        $ratio = $grade['total'] > 0 ? $grade['correct'] / $grade['total'] : 0;
        $view['result'] = [
            'correct' => $grade['correct'], 'total' => $grade['total'],
            'score' => round($ratio * (int) $assignment['points'], 2), 'points' => (int) $assignment['points'],
            'words' => (object) $grade['words'],
        ];
        // Con "repetir" permitido no se muestran las respuestas para no regalarlas; solo qué palabras fallaron.
        if (!$allowRepeat) {
            $view['solution'] = ['rows' => $layout['rows'], 'cols' => $layout['cols'], 'words' => $layout['words']];
        }
    }
    return $view + $extra;
}

if ($action === 'load') {
    json_response(cw_view($assignment, $attempt, $layout));
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
    json_response(cw_view($assignment, $attempt, $layout));
}

if ($action !== 'save' && $action !== 'submit') {
    json_response(['success' => false, 'message' => 'Acción no válida.'], 400);
}

if (wga_is_overdue($assignment)) {
    json_response(['success' => false, 'message' => 'La fecha de entrega ya venció.', 'overdue' => true], 400);
}

$cells = crossword_sanitize_cells($layout, $input['cells'] ?? []);

$pdo->beginTransaction();
try {
    $attempt = wga_lock_attempt($pdo, (int) $attempt['id']);

    if ($attempt['status'] !== 'in_progress') {
        $pdo->rollBack();
        json_response(cw_view($assignment, $attempt, $layout, ['already_done' => true]));
    }

    wga_save_state($pdo, (int) $attempt['id'], ['cells' => (object) $cells]);

    if ($action === 'submit') {
        $grade = crossword_grade($layout, $cells);
        wga_complete($pdo, $assignment, (int) $attempt['id'], $studentId, $grade['correct'], $grade['total']);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('wordgames/crossword.php error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'No fue posible guardar.'], 500);
}

if ($action === 'save') {
    json_response(['success' => true, 'saved' => true]);
}

$attempt = wga_ensure_attempt($pdo, $assignment, $studentId);
json_response(cw_view($assignment, $attempt, $layout));
