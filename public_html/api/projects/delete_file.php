<?php
/**
 * POST /api/projects/delete_file.php
 * Body (JSON o formulario): project_id, file_id, csrf_token
 * El estudiante quita uno de sus archivos mientras la entrega siga abierta.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/availability_helpers.php';
require_once __DIR__ . '/../../includes/project_files_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;
csrf_verify($input['csrf_token'] ?? null);

$projectId = (int) ($input['project_id'] ?? 0);
$fileId = (int) ($input['file_id'] ?? 0);
$studentId = (int) current_user_id();
$pdo = Database::getConnection();

$stmt = $pdo->prepare('SELECT * FROM student_projects WHERE id = :id AND student_id = :sid AND type = \'archivos\' LIMIT 1');
$stmt->execute(['id' => $projectId, 'sid' => $studentId]);
$project = $stmt->fetch();

if (!$project || $project['status'] === 'submitted') {
    json_response(['success' => false, 'message' => 'No se puede modificar este trabajo (¿ya fue entregado?).'], 400);
}
$lockReason = assignment_lock_reason($pdo, $project['assignment_id'] ? (int) $project['assignment_id'] : null, $studentId);
if ($lockReason !== null) {
    json_response(['success' => false, 'message' => $lockReason], 403);
}

if (!project_files_student_remove($pdo, $fileId, $projectId, $studentId)) {
    json_response(['success' => false, 'message' => 'No se pudo quitar el archivo.'], 400);
}

json_response(['success' => true, 'version' => project_files_touch($pdo, $projectId)]);
