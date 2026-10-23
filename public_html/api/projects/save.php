<?php
/**
 * POST /api/projects/save.php
 * Body: project_id, data (objeto JSON con la estructura del trabajo), title (opcional), csrf_token
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project_helpers.php';
require_once __DIR__ . '/../../includes/group_helpers.php';

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

// ¿Venció la fecha de entrega (o el curso ya no está activo)?
$lock = $pdo->prepare('SELECT assignment_id FROM student_projects WHERE id = :id AND student_id = :sid');
$ownerId = project_effective_student($pdo, $projectId, (int) current_user_id()); // el dueño real (trabajo en grupo)
$lock->execute(['id' => $projectId, 'sid' => $ownerId]);
$lockRow = $lock->fetch();
if ($lockRow) {
    $reason = assignment_lock_reason($pdo, $lockRow['assignment_id'] ? (int) $lockRow['assignment_id'] : null, (int) current_user_id());
    if ($reason !== null) {
        json_response(['success' => false, 'message' => $reason], 403);
    }
}

$savedAt = null;
$ok = project_save($pdo, $projectId, $ownerId, $data, $title, $savedAt);

if (!$ok) {
    json_response(['success' => false, 'message' => 'No se pudo guardar (¿ya fue entregado?).'], 400);
}

json_response(['success' => true, 'saved_at' => $savedAt, 'version' => $savedAt]);
