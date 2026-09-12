<?php
/**
 * functions.php
 * Funciones auxiliares de propósito general.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/**
 * Redirige a una URL relativa dentro de la app y termina la ejecución.
 */
function redirect(string $path): void
{
    header('Location: ' . rtrim(APP_URL, '/') . '/' . ltrim($path, '/'));
    exit;
}

/**
 * Genera un código corto numérico para partidas (ej: 739421).
 */
function generate_game_code(PDO $pdo): string
{
    do {
        $code = (string) random_int(100000, 999999);
        $stmt = $pdo->prepare("SELECT id FROM games WHERE code = :code AND status != 'finished' LIMIT 1");
        $stmt->execute(['code' => $code]);
        $exists = $stmt->fetch();
    } while ($exists);

    return $code;
}

/**
 * Devuelve la fecha/hora actual en formato MySQL DATETIME.
 */
function now_datetime(): string
{
    return (new DateTime())->format('Y-m-d H:i:s');
}

/**
 * Trunca un texto a determinada longitud agregando "..." si corresponde.
 */
function truncate_text(string $text, int $length = 120): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '…';
}

/**
 * Registra una acción relevante en audit_log (best effort: nunca interrumpe el flujo principal).
 */
function audit_log(PDO $pdo, ?int $userId, string $action, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, details, created_at) VALUES (:user_id, :action, :details, :created_at)"
        );
        $stmt->execute([
            'user_id'    => $userId,
            'action'     => $action,
            'details'    => $details,
            'created_at' => now_datetime(),
        ]);
    } catch (Throwable $e) {
        error_log('audit_log error: ' . $e->getMessage());
    }
}
