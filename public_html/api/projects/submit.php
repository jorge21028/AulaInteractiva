<?php
/**
 * POST /api/projects/submit.php
 * Body: project_id, csrf_token
 *
 * Marca el trabajo como entregado. Después de esto, el estudiante ya no
 * puede modificarlo (coincide con el requisito: "Una vez enviada, no
 * podrás modificarla").
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;

csrf_verify($input['csrf_token'] ?? null);

$projectId = (int) ($input['project_id'] ?? 0);
if ($projectId <= 0) {
    json_response(['success' => false, 'message' => 'Falta el trabajo a entregar.'], 400);
}

$pdo = Database::getConnection();
$ok = project_submit($pdo, $projectId, current_user_id());

if (!$ok) {
    json_response(['success' => false, 'message' => 'No se pudo entregar el trabajo.'], 400);
}

audit_log($pdo, current_user_id(), 'project_submit', "Trabajo #{$projectId} entregado");

json_response(['success' => true]);
