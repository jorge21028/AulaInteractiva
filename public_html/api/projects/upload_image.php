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

if (empty($_FILES['image'])) {
    // Cuando el archivo supera post_max_size, PHP descarta todo el envío y $_FILES llega vacío.
    json_response(['success' => false, 'message' => 'La imagen es demasiado pesada para subirla. Prueba con una más pequeña.'], 400);
}

$uploadError = (int) $_FILES['image']['error'];
if ($uploadError !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE  => 'La imagen es demasiado pesada para este servidor. Prueba con una más pequeña.',
        UPLOAD_ERR_FORM_SIZE => 'La imagen es demasiado pesada. Prueba con una más pequeña.',
        UPLOAD_ERR_PARTIAL   => 'La imagen no terminó de subirse. Revisa tu conexión e intenta de nuevo.',
        UPLOAD_ERR_NO_FILE   => 'No se seleccionó ninguna imagen.',
    ];
    json_response(['success' => false, 'message' => $messages[$uploadError] ?? 'No se pudo recibir la imagen. Intenta de nuevo.'], 400);
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

if ($file['size'] > UPLOAD_MAX_IMAGE_BYTES) {
    $maxMb = round(UPLOAD_MAX_IMAGE_BYTES / 1024 / 1024, 1);
    json_response(['success' => false, 'message' => "La imagen supera el tamaño máximo permitido ({$maxMb} MB)."], 400);
}

// Verificación adicional: confirmar que el contenido es realmente una imagen
// (no solo la extensión), usando el detector de tipo de imagen de PHP.
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    json_response(['success' => false, 'message' => 'El archivo no es una imagen válida (usa JPG, PNG, GIF o WebP).'], 400);
}

// La extensión se decide por el CONTENIDO real de la imagen (no por el nombre que traiga el archivo).
$extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
if (!isset($extByMime[$imageInfo['mime']])) {
    json_response(['success' => false, 'message' => 'Formato de imagen no permitido. Usa JPG, PNG, GIF o WebP.'], 400);
}

$storedName = safe_random_filename('imagen.' . $extByMime[$imageInfo['mime']]);
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

// Se devuelve una RUTA LOCAL (sin dominio): así la imagen carga aunque el sitio se abra con una dirección distinta
// a APP_URL (http/https o con/sin www), que era la causa de que las imágenes no aparecieran en el editor.
$basePath = rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?? ''), '/');
$url = $basePath . '/uploads/images/' . $storedName;

json_response(['success' => true, 'url' => $url, 'name' => $file['name']]);
