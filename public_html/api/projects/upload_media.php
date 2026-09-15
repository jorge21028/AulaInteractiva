<?php
/**
 * POST /api/projects/upload_media.php  (multipart/form-data)
 * Campos: media (archivo), kind ('audio'|'video'), project_id, csrf_token
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

csrf_verify($_POST['csrf_token'] ?? null);

$projectId = (int) ($_POST['project_id'] ?? 0);
$kind = $_POST['kind'] ?? '';

if (!in_array($kind, ['audio', 'video'], true)) {
    json_response(['success' => false, 'message' => 'Tipo de archivo inválido.'], 400);
}

if (empty($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'message' => 'No se recibió ningún archivo válido.'], 400);
}

$file = $_FILES['media'];

$pdo = Database::getConnection();
if ($projectId > 0) {
    $check = $pdo->prepare('SELECT status FROM student_projects WHERE id = :id AND student_id = :sid');
    $check->execute(['id' => $projectId, 'sid' => current_user_id()]);
    $project = $check->fetch();
    if (!$project || $project['status'] === 'submitted') {
        json_response(['success' => false, 'message' => 'No se puede subir archivos para este trabajo.'], 400);
    }
}

$allowedList = $kind === 'audio' ? UPLOAD_ALLOWED_AUDIO : UPLOAD_ALLOWED_VIDEO;
$maxBytes = $kind === 'audio' ? UPLOAD_MAX_AUDIO_BYTES : UPLOAD_MAX_VIDEO_BYTES;

if (!is_allowed_extension($file['name'], $allowedList)) {
    json_response(['success' => false, 'message' => 'Formato de archivo no permitido.'], 400);
}

if ($file['size'] > $maxBytes) {
    $maxMb = round($maxBytes / 1024 / 1024, 1);
    json_response(['success' => false, 'message' => "El archivo supera el tamaño máximo permitido ({$maxMb} MB)."], 400);
}

$storedName = safe_random_filename($file['name']);
$targetDir = UPLOAD_DIR . '/' . ($kind === 'audio' ? 'audio' : 'video');
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}
$targetPath = $targetDir . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    error_log('upload_media.php: move_uploaded_file failed for ' . $storedName);
    json_response(['success' => false, 'message' => 'No fue posible guardar el archivo.'], 500);
}

$mimeType = mime_content_type($targetPath) ?: ($kind === 'audio' ? 'audio/mpeg' : 'video/mp4');

$pdo->prepare(
    "INSERT INTO uploads (user_id, project_id, original_name, stored_name, mime_type, size_bytes, kind, created_at)
     VALUES (:user_id, :project_id, :original_name, :stored_name, :mime_type, :size_bytes, :kind, :created_at)"
)->execute([
    'user_id' => current_user_id(),
    'project_id' => $projectId > 0 ? $projectId : null,
    'original_name' => $file['name'],
    'stored_name' => $storedName,
    'mime_type' => $mimeType,
    'size_bytes' => $file['size'],
    'kind' => $kind,
    'created_at' => now_datetime(),
]);

$url = rtrim(APP_URL, '/') . '/uploads/' . ($kind === 'audio' ? 'audio' : 'video') . '/' . $storedName;

json_response(['success' => true, 'url' => $url, 'kind' => $kind]);
