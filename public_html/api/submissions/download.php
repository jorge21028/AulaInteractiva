<?php
/**
 * GET /api/submissions/download.php?id=ID_ARCHIVO[&inline=1]
 * Descarga un archivo entregado por un estudiante. Pueden verlo: el propio estudiante, el profesor de esa asignación
 * y el súper administrador. Los archivos no tienen URL pública (uploads/submissions/.htaccess lo bloquea).
 * Con inline=1, los PDF e imágenes se abren en el navegador (con tipo fijo); todo lo demás siempre se descarga.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project_files_helpers.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Debes iniciar sesión.');
}
$role = current_role();
if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
    http_response_code(403);
    exit('Sin permiso.');
}

$pdo = Database::getConnection();
$file = project_files_for_user($pdo, (int) ($_GET['id'] ?? 0), (int) current_user_id(), $role);

if (!$file) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}
if (!empty($file['deleted_at'])) {
    http_response_code(410);
    exit(project_files_deleted_label($file) . '.');
}
if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,5}$/', (string) $file['stored_name'])) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$path = project_files_dir() . '/' . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('El archivo ya no está disponible.');
}

$ext = strtolower(pathinfo($file['stored_name'], PATHINFO_EXTENSION));
$inline = !empty($_GET['inline']) && isset(PROJECT_FILE_INLINE_TYPES[$ext]);
$asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['original_name']);

header('Content-Type: ' . ($inline ? PROJECT_FILE_INLINE_TYPES[$ext] : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
exit;
