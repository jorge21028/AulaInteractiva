<?php
/**
 * GET /api/media/image.php?f=<archivo>
 * Entrega una imagen subida (uploads/images/<archivo>) leyéndola con PHP. Se usa para diagnosticar si el problema
 * al ver imágenes está en el servidor web (configuración/permisos) y no en el archivo. Requiere sesión iniciada.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

$name = (string) ($_GET['f'] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$/i', $name) || str_contains($name, '..')) {
    http_response_code(404);
    exit;
}

$path = rtrim(UPLOAD_DIR, '/') . '/images/' . $name;
$info = is_file($path) ? @getimagesize($path) : false;
if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
