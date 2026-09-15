<?php
/**
 * POST /api/projects/save.php
 * Body: project_id, data (objeto JSON con la estructura del trabajo), title (opcional), csrf_token
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
$data = $input['data'] ?? null;
$title = isset($input['title']) ? clean_string((string) $input['title']) : null;

if ($projectId <= 0 || !is_array($data)) {
    json_response(['success' => false, 'message' => 'Datos inválidos.'], 400);
}

$pdo = Database::getConnection();
$ok = project_save($pdo, $projectId, current_user_id(), $data, $title);

if (!$ok) {
    json_response(['success' => false, 'message' => 'No se pudo guardar (¿ya fue entregado?).'], 400);
}

json_response(['success' => true, 'saved_at' => now_datetime()]);
