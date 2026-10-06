<?php
/**
 * GET /api/materials/download.php?id=ID_ARCHIVO
 * Descarga un archivo de una asignatura. Solo el profesor de ese curso o un estudiante inscrito en él.
 * Los archivos no tienen URL pública (uploads/materials/.htaccess lo bloquea): siempre pasan por aquí.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/materials_helpers.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Debes iniciar sesión.');
}

$role = current_role();
if (!in_array($role, ['teacher', 'student'], true)) {
    http_response_code(403);
    exit('Sin permiso.');
}

$pdo = Database::getConnection();
$file = materials_file_for_user($pdo, (int) ($_GET['id'] ?? 0), current_user_id(), $role);

if (!$file || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,5}$/', (string) $file['stored_name'])) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$path = materials_dir() . '/' . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('El archivo ya no está disponible.');
}

// Siempre como descarga (nunca se muestra dentro del navegador): evita que un archivo se ejecute como página.
$asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['original_name']);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
exit;
