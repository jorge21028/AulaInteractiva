<?php
/**
 * POST /api/activities/upload_question_image.php  (multipart/form-data)
 * Campos: question_id, image (archivo, opcional si action=remove), action ('upload'|'remove'), csrf_token
 *
 * Sube (o quita) la imagen asociada a una pregunta de selección múltiple
 * o verdadero/falso. Solo el profesor dueño de la actividad puede hacerlo.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/image_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('teacher');

// Si la foto es más grande que el límite del servidor, PHP descarta todo el formulario (y el token también).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_response(['success' => false, 'message' => 'La imagen es demasiado pesada para este servidor. Prueba con una más pequeña.'], 413);
}
csrf_verify($_POST['csrf_token'] ?? null);

$questionId = (int) ($_POST['question_id'] ?? 0);
$action = $_POST['action'] ?? 'upload';

$pdo = Database::getConnection();

$stmt = $pdo->prepare(
    'SELECT q.id, q.image_path FROM activity_questions q
     INNER JOIN activities a ON a.id = q.activity_id
     WHERE q.id = :id AND a.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['id' => $questionId, 'teacher_id' => current_user_id()]);
$question = $stmt->fetch();

if (!$question) {
    json_response(['success' => false, 'message' => 'Pregunta no encontrada.'], 404);
}

function delete_question_image_file(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $full = __DIR__ . '/../../' . ltrim($relativePath, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}

if ($action === 'remove') {
    delete_question_image_file($question['image_path']);
    $pdo->prepare('UPDATE activity_questions SET image_path = NULL WHERE id = :id')->execute(['id' => $questionId]);
    json_response(['success' => true, 'image_url' => null]);
}

if (empty($_FILES['image'])) {
    json_response(['success' => false, 'message' => 'La imagen es demasiado pesada para subirla. Prueba con una más pequeña.'], 400);
}
if ($_FILES['image']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['image']['error'] === UPLOAD_ERR_FORM_SIZE) {
    json_response(['success' => false, 'message' => 'La imagen es demasiado pesada para este servidor. Prueba con una más pequeña.'], 400);
}
if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'message' => 'No se recibió ninguna imagen válida.'], 400);
}

$file = $_FILES['image'];

if (!is_allowed_extension($file['name'], UPLOAD_ALLOWED_IMAGE)) {
    json_response(['success' => false, 'message' => 'Formato de imagen no permitido.'], 400);
}

if ($file['size'] > UPLOAD_MAX_IMAGE_RAW_BYTES) {
    $maxMb = round(UPLOAD_MAX_IMAGE_RAW_BYTES / 1024 / 1024, 1);
    json_response(['success' => false, 'message' => "La imagen supera el tamaño máximo permitido ({$maxMb} MB)."], 400);
}

$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    json_response(['success' => false, 'message' => 'El archivo no es una imagen válida.'], 400);
}

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
    error_log('upload_question_image.php: move_uploaded_file failed for ' . $storedName);
    json_response(['success' => false, 'message' => 'No fue posible guardar la imagen.'], 500);
}

// Reducir tamaño/dimensiones para no llenar el espacio del hosting
$opt = image_optimize($targetPath, $imageInfo['mime']);
if ($opt['path'] !== $targetPath) {
    $targetPath = $opt['path'];
    $storedName = basename($targetPath);
}
if ($opt['size'] > UPLOAD_MAX_IMAGE_BYTES) {
    @unlink($targetPath);
    json_response(['success' => false, 'message' => 'La imagen sigue siendo muy pesada incluso reducida. Prueba con otra.'], 400);
}
$imageInfo['mime'] = $opt['mime'];
$file['size'] = $opt['size'];

$relativePath = 'uploads/images/' . $storedName;

// Reemplazar: borrar la imagen anterior de esta pregunta si tenía una
delete_question_image_file($question['image_path']);

$pdo->prepare('UPDATE activity_questions SET image_path = :path WHERE id = :id')
    ->execute(['path' => $relativePath, 'id' => $questionId]);

$pdo->prepare(
    "INSERT INTO uploads (user_id, project_id, original_name, stored_name, mime_type, size_bytes, kind, created_at)
     VALUES (:user_id, NULL, :original_name, :stored_name, :mime_type, :size_bytes, 'image', :created_at)"
)->execute([
    'user_id' => current_user_id(),
    'original_name' => $file['name'],
    'stored_name' => $storedName,
    'mime_type' => $imageInfo['mime'],
    'size_bytes' => $file['size'],
    'created_at' => now_datetime(),
]);

json_response(['success' => true, 'image_url' => uploads_public_url($relativePath)]);
