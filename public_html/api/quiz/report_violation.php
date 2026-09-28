<?php
/**
 * POST /api/quiz/report_violation.php
 * Body: attempt_id, csrf_token
 *
 * El estudiante (vía JS en student/quiz_attempt.php) avisa que cambió de
 * pestaña, minimizó, o salió de pantalla completa mientras tenía un intento
 * de cuestionario en curso. Cada aviso es un strike (sistema de 3 strikes):
 *   1.º: -10 % del tiempo establecido · 2.º: -20 % · 3.º: se cierra el intento.
 * Responde con el detalle para que la pantalla del estudiante muestre la alerta.
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
$result = quiz_register_strike($pdo, $attemptId, current_user_id());

if (empty($result['active'])) {
    json_response(['success' => true, 'active' => false]);
}

json_response(['success' => true] + $result);
