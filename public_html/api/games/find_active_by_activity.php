<?php
/**
 * GET /api/games/find_active_by_activity.php?activity_id=X
 *
 * Permite al estudiante saber si existe una partida en curso para una
 * actividad que tiene asignada, sin necesitar el código de memoria.
 * Solo responde si el estudiante tiene esa actividad asignada
 * (a través de assignment_students), para no filtrar partidas ajenas.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

$activityId = (int) ($_GET['activity_id'] ?? 0);
if ($activityId <= 0) {
    json_response(['success' => false, 'message' => 'Falta la actividad.'], 400);
}

$pdo = Database::getConnection();

$check = $pdo->prepare(
    'SELECT 1 FROM assignment_students ast
     INNER JOIN assignments a ON a.id = ast.assignment_id
     INNER JOIN subjects sj ON sj.id = a.subject_id
     INNER JOIN courses cc ON cc.id = sj.course_id AND cc.is_active = 1
     WHERE ast.student_id = :student_id AND a.activity_id = :activity_id
       AND (a.start_date IS NULL OR a.start_date <= :now)
     LIMIT 1'
);
$check->execute(['student_id' => current_user_id(), 'activity_id' => $activityId, 'now' => now_datetime()]);

if (!$check->fetch()) {
    json_response(['success' => false, 'message' => 'No tienes esta actividad asignada.'], 403);
}

$gameStmt = $pdo->prepare(
    "SELECT code FROM games WHERE activity_id = :activity_id AND status != 'finished' ORDER BY id DESC LIMIT 1"
);
$gameStmt->execute(['activity_id' => $activityId]);
$game = $gameStmt->fetch();

if (!$game) {
    json_response(['success' => false, 'message' => 'Tu profesor todavía no ha iniciado la partida.']);
}

json_response(['success' => true, 'code' => $game['code']]);
