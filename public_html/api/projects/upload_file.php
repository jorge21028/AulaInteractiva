<?php
/**
 * POST /api/projects/upload_file.php  (multipart/form-data)
 * Campos: file (UN archivo), project_id, csrf_token
 *
 * Entrega de archivos: sube un PDF/Word/Excel/etc. al trabajo del estudiante. Cada archivo queda guardado en el servidor
 * en cuanto termina de subirse (no depende de "Guardar borrador"), así que no se pierde si el estudiante cierra o actualiza
 * la página mientras la entrega siga abierta.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/availability_helpers.php';
require_once __DIR__ . '/../../includes/project_files_helpers.php';
require_once __DIR__ . '/../../includes/group_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

// Si el archivo supera post_max_size, PHP descarta todo el formulario (y el token también).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_response(['success' => false, 'message' => 'El archivo es demasiado pesado para este servidor. Prueba con uno más pequeño.'], 413);
}
csrf_verify($_POST['csrf_token'] ?? null);

if (empty($_FILES['file'])) {
    json_response(['success' => false, 'message' => 'No se recibió ningún archivo.'], 400);
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$studentId = (int) current_user_id();
$pdo = Database::getConnection();

$stmt = $pdo->prepare('SELECT * FROM student_projects WHERE id = :id AND student_id = :sid AND type = \'archivos\' LIMIT 1');
$stmt->execute(['id' => $projectId, 'sid' => project_effective_student($pdo, $projectId, $studentId)]);
$project = $stmt->fetch();

if (!$project || $project['status'] === 'submitted') {
    json_response(['success' => false, 'message' => 'No se pueden subir archivos para este trabajo (¿ya fue entregado?).'], 400);
}
$lockReason = assignment_lock_reason($pdo, $project['assignment_id'] ? (int) $project['assignment_id'] : null, $studentId);
if ($lockReason !== null) {
    json_response(['success' => false, 'message' => $lockReason], 403);
}

[$ok, $message, $file] = project_files_store($pdo, $project, $_FILES['file']);
if (!$ok) {
    json_response(['success' => false, 'message' => $message], 400);
}

$version = project_files_touch($pdo, (int) $project['id']);

json_response([
    'success' => true,
    'version' => $version,
    'file' => [
        'id' => (int) $file['id'],
        'name' => $file['original_name'],
        'size' => (int) $file['size_bytes'],
        'size_label' => materials_format_size((int) $file['size_bytes']),
        'icon' => materials_file_icon($file['original_name']),
    ],
]);
