<?php
/**
 * POST /api/submissions/delete_files.php
 * Body (JSON o formulario): csrf_token y UNO de: file_id | project_id | assignment_id
 *
 * El profesor (solo en sus asignaciones) o el súper administrador borran archivos entregados por los estudiantes
 * para liberar espacio en el hosting. La entrega y la calificación se conservan; solo desaparecen los archivos.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project_files_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role(['teacher', 'admin']);

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;
csrf_verify($input['csrf_token'] ?? null);

$role = (string) current_role();
$userId = (int) current_user_id();

$scope = null;
$id = 0;
foreach (['file_id' => 'file', 'project_id' => 'project', 'assignment_id' => 'assignment'] as $key => $s) {
    if (!empty($input[$key])) {
        $scope = $s;
        $id = (int) $input[$key];
        break;
    }
}
if ($scope === null || $id <= 0) {
    json_response(['success' => false, 'message' => 'Falta indicar qué archivos borrar.'], 400);
}

$pdo = Database::getConnection();
$rows = project_files_staff_select($pdo, $scope, $id, $role, $userId);
if (empty($rows)) {
    json_response(['success' => false, 'message' => 'No hay archivos para borrar (o no tienes permiso sobre ellos).'], 404);
}

$result = project_files_staff_delete($pdo, $rows, $userId, $role);
audit_log($pdo, $userId, 'project_files_delete', "Archivos borrados ({$scope} #{$id}): {$result['files']} archivo(s), " . materials_format_size($result['bytes']));

json_response([
    'success' => $result['files'] > 0,
    'files' => $result['files'],
    'bytes' => $result['bytes'],
    'failed' => $result['failed'],
    'freed' => materials_format_size($result['bytes']),
    'message' => $result['files'] > 0
        ? "Se borraron {$result['files']} archivo(s) y se liberaron " . materials_format_size($result['bytes']) . '.'
        : 'No se pudo borrar ningún archivo del servidor.',
]);
