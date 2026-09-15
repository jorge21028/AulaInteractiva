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

/**
 * Sanea HTML enriquecido generado por el estudiante (ej: editor de
 * resúmenes) antes de guardarlo. Usa una lista blanca estricta de
 * etiquetas y atributos: cualquier cosa fuera de esa lista se elimina
 * por completo (incluyendo <script>, <style>, manejadores onXXX y
 * enlaces "javascript:"). Esto es lo único que nos protege de un
 * ataque XSS almacenado a través del contenido del propio estudiante,
 * ya que ese HTML luego se muestra sin escapar (así conserva el
 * formato) tanto al profesor como al propio estudiante.
 */
function sanitize_rich_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $allowedTags = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'blockquote', 'a', 'span', 'div', 'audio', 'video', 'source',
    ];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="aula-root">' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();

    $root = $doc->getElementById('aula-root');
    if ($root === null) {
        return '';
    }

    sanitize_dom_node($root, $allowedTags);

    $output = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $output .= $doc->saveHTML($child);
    }

    return $output;
}

/**
 * Recorre el árbol DOM y elimina cualquier etiqueta fuera de la lista
 * blanca (conservando su texto interior), y cualquier atributo que no
 * sea el "href" seguro de un enlace <a>.
 */
function sanitize_dom_node(DOMNode $node, array $allowedTags): void
{
    $children = iterator_to_array($node->childNodes);

    foreach ($children as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            continue;
        }

        if ($child->nodeType !== XML_ELEMENT_NODE) {
            $node->removeChild($child);
            continue;
        }

        /** @var DOMElement $child */
        $tagName = strtolower($child->nodeName);

        // Etiquetas peligrosas: eliminar por completo, incluyendo su contenido
        // (a diferencia de una etiqueta simplemente "no permitida", cuyo texto
        // interior sí queremos conservar).
        $stripEntirely = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'link', 'meta'];
        if (in_array($tagName, $stripEntirely, true)) {
            $node->removeChild($child);
            continue;
        }

        if (!in_array($tagName, $allowedTags, true)) {
            // Etiqueta no permitida: conservar su texto, pero no la etiqueta.
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            continue;
        }

        // Quitar todos los atributos salvo los explícitamente permitidos.
        if ($child->hasAttributes()) {
            $attrs = iterator_to_array($child->attributes);
            $uploadsPrefix = rtrim(APP_URL, '/') . '/uploads/';

            foreach ($attrs as $attr) {
                $name = strtolower($attr->name);
                $keep = false;

                if ($tagName === 'a' && $name === 'href') {
                    $value = trim($attr->value);
                    if (preg_match('~^(https?://|/)~i', $value)) {
                        $keep = true;
                    }
                } elseif (in_array($tagName, ['audio', 'video', 'source'], true) && $name === 'src') {
                    $value = trim($attr->value);
                    if (str_starts_with($value, $uploadsPrefix)) {
                        $keep = true;
                    }
                } elseif (in_array($tagName, ['audio', 'video'], true) && $name === 'controls') {
                    $keep = true;
                } elseif ($tagName === 'video' && in_array($name, ['width', 'height'], true) && ctype_digit($attr->value)) {
                    $keep = true;
                } elseif ($tagName === 'source' && $name === 'type') {
                    $keep = true;
                }

                if (!$keep) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tagName === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('rel', 'noopener noreferrer');
                $child->setAttribute('target', '_blank');
            }
        }

        sanitize_dom_node($child, $allowedTags);
    }
}
