<?php
/**
 * POST /api/quiz/report_violation.php
 * Body: attempt_id, csrf_token
 *
 * El estudiante (vía JS en student/quiz_attempt.php) avisa que cambió de
 * pestaña, minimizó, o salió de pantalla completa mientras tenía un intento
 * de cuestionario en curso. Solo suma +1 al contador del intento; nunca
 * interrumpe ni invalida el intento (la decisión de qué hacer con eso queda
 * en manos del profesor, que ve el conteo).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/quiz_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;

csrf_verify($input['csrf_token'] ?? null);

$attemptId = (int) ($input['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    json_response(['success' => false], 400);
}

$pdo = Database::getConnection();
quiz_record_tab_switch($pdo, $attemptId, current_user_id());

json_response(['success' => true]);
