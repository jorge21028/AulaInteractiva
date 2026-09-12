<?php
/**
 * security.php
 * Utilidades transversales de seguridad: CSRF, sanitización y escape.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/**
 * Genera (o reutiliza) el token CSRF de la sesión actual.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Imprime un <input hidden> listo para usar dentro de un <form>.
 */
function csrf_field(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Valida el token CSRF recibido en una petición POST/AJAX.
 * Termina la ejecución con 403 si no es válido.
 */
function csrf_verify(?string $token): void
{
    if (empty($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        if (is_ajax_request()) {
            json_response(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página.'], 403);
        }
        exit('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
    }
}

function is_ajax_request(): bool
{
    return (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || (
        !empty($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')
    );
}

/**
 * Responde en JSON y termina la ejecución. Uso estándar para /api/*.
 */
function json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Escapa texto para salida segura en HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Limpia una cadena de texto simple (trim + eliminación de caracteres de control).
 * No sustituye a prepared statements; es solo higiene de datos de entrada.
 */
function clean_string(?string $value): string
{
    $value = trim($value ?? '');
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
}

/**
 * Valida que un valor de correo tenga formato correcto.
 */
function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Genera un nombre de archivo aleatorio seguro conservando la extensión.
 */
function safe_random_filename(string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    return bin2hex(random_bytes(16)) . ($ext !== '' ? ".{$ext}" : '');
}

/**
 * Valida una extensión de archivo subido contra una lista blanca.
 */
function is_allowed_extension(string $filename, array $allowedList): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowedList, true);
}
