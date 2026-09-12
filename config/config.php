<?php
/**
 * config.php
 * Carga la configuración general de la aplicación.
 *
 * IMPORTANTE: este archivo NO debe contener credenciales reales.
 * Las credenciales se definen en config/env.php, el cual está
 * excluido del repositorio mediante .gitignore.
 *
 * Para desplegar en InfinityFree o en local:
 *   1. Copia config/env.example.php como config/env.php
 *   2. Rellena los valores reales en config/env.php
 */

// Evitar acceso directo a este archivo
if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

$envPath = __DIR__ . '/env.php';

if (!file_exists($envPath)) {
    http_response_code(500);
    exit('Falta config/env.php. Copia config/env.example.php y complétalo con tus credenciales.');
}

require_once $envPath;

// ---- Validación de que env.php definió lo mínimo necesario ----
$requiredConstants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
foreach ($requiredConstants as $const) {
    if (!defined($const)) {
        http_response_code(500);
        exit("Falta la constante {$const} en config/env.php. Revisa que copiaste env.example.php correctamente.");
    }
}

// ---- Configuración general de la aplicación ----
define('APP_NAME', 'AulaInteractiva');

// APP_ENV y APP_URL: se definen en env.php. Si por alguna razón no
// se definieron ahí, usamos un valor por defecto seguro.
if (!defined('APP_ENV')) {
    define('APP_ENV', 'production');
}
if (!defined('APP_URL')) {
    define('APP_URL', 'http://localhost/aulainteractiva');
}

// Mostrar errores solo en entorno local
if (APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ---- Configuración de sesiones seguras ----
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
// Activar cookie 'secure' automáticamente si la conexión es HTTPS
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

// ---- Límites de subida de archivos (ajustados para hosting gratuito) ----
define('UPLOAD_MAX_IMAGE_BYTES', 3 * 1024 * 1024);   // 3 MB
define('UPLOAD_MAX_AUDIO_BYTES', 8 * 1024 * 1024);   // 8 MB
define('UPLOAD_MAX_VIDEO_BYTES', 20 * 1024 * 1024);  // 20 MB

define('UPLOAD_ALLOWED_IMAGE', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
define('UPLOAD_ALLOWED_AUDIO', ['mp3', 'wav', 'ogg']);
define('UPLOAD_ALLOWED_VIDEO', ['mp4', 'webm']);

define('UPLOAD_DIR', __DIR__ . '/../uploads');
