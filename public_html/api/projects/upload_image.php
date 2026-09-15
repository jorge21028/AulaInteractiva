<?php
/**
 * POST /api/projects/upload_image.php  (multipart/form-data)
 * Campos: image (archivo), project_id, csrf_token
 *
 * Valida extensión y tamaño contra una lista blanca (ver config.php),
 * genera un nombre aleatorio (nunca se conserva el nombre original en
 * disco) y guarda el archivo fuera del alcance de ejecución de scripts
 * (ver public_html/uploads/.htaccess).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

csrf_verify($_POST['csrf_token'] ?? null);

$projectId = (int) ($_POST['project_id'] ?? 0);

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'message' => 'No se recibió ninguna imagen válida.'], 400);
}

$file = $_FILES['image'];

// Verificar que el proyecto exista, pertenezca a este estudiante y no esté ya entregado
$pdo = Database::getConnection();
if ($projectId > 0) {
    $check = $pdo->prepare('SELECT status FROM student_projects WHERE id = :id AND student_id = :sid');
    $check->execute(['id' => $projectId, 'sid' => current_user_id()]);
    $project = $check->fetch();
    if (!$project || $project['status'] === 'submitted') {
        json_response(['success' => false, 'message' => 'No se puede subir la imagen para este trabajo.'], 400);
    }
}

if (!is_allowed_extension($file['name'], UPLOAD_ALLOWED_IMAGE)) {
    json_response(['success' => false, 'message' => 'Formato de imagen no permitido.'], 400);
}

if ($file['size'] > UPLOAD_MAX_IMAGE_BYTES) {
    $maxMb = round(UPLOAD_MAX_IMAGE_BYTES / 1024 / 1024, 1);
    json_response(['success' => false, 'message' => "La imagen supera el tamaño máximo permitido ({$maxMb} MB)."], 400);
}

// Verificación adicional: confirmar que el contenido es realmente una imagen
// (no solo la extensión), usando el detector de tipo de imagen de PHP.
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    json_response(['success' => false, 'message' => 'El archivo no es una imagen válida.'], 400);
}

$storedName = safe_random_filename($file['name']);
$targetDir = UPLOAD_DIR . '/images';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}
$targetPath = $targetDir . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    error_log('upload_image.php: move_uploaded_file failed for ' . $storedName);
    json_response(['success' => false, 'message' => 'No fue posible guardar la imagen.'], 500);
}

$stmt = $pdo->prepare(
    "INSERT INTO uploads (user_id, project_id, original_name, stored_name, mime_type, size_bytes, kind, created_at)
     VALUES (:user_id, :project_id, :original_name, :stored_name, :mime_type, :size_bytes, 'image', :created_at)"
);
$stmt->execute([
    'user_id' => current_user_id(),
    'project_id' => $projectId > 0 ? $projectId : null,
    'original_name' => $file['name'],
    'stored_name' => $storedName,
    'mime_type' => $imageInfo['mime'],
    'size_bytes' => $file['size'],
    'created_at' => now_datetime(),
]);

$url = rtrim(APP_URL, '/') . '/uploads/images/' . $storedName;

json_response(['success' => true, 'url' => $url]);
