<?php
/**
 * description_helpers.php
 * Descripciones con formato HTML escritas por el profesor (asignaciones y cuestionarios).
 *
 * El profesor puede elegir "Texto sencillo" (como siempre) o "HTML". En HTML puede usar títulos, listas, tablas,
 * colores, imágenes y videos alojados en otros sitios (YouTube, Vimeo, Google Drive/Docs, etc.), enlaces, etc.
 *
 * SEGURIDAD: lo que escribe el profesor lo ven los estudiantes, así que el HTML se limpia SIEMPRE (al guardar y
 * otra vez al mostrar) con una lista blanca: no se permiten scripts, formularios, eventos (onclick...), estilos
 * <style>, ni javascript: en enlaces. Los <iframe> solo se aceptan de los sitios de la lista DESC_IFRAME_HOSTS.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const DESC_FORMATS = ['text', 'html'];

/** Sitios desde los que se permite incrustar contenido (iframe): host => prefijos de ruta permitidos. */
const DESC_IFRAME_HOSTS = [
    'www.youtube.com'            => ['/embed/'],
    'www.youtube-nocookie.com'   => ['/embed/'],
    'player.vimeo.com'           => ['/video/'],
    'drive.google.com'           => ['/file/d/'],
    'docs.google.com'            => ['/presentation/', '/document/', '/spreadsheets/', '/forms/'],
    'www.canva.com'              => ['/design/'],
    'view.genial.ly'             => ['/'],
    'wordwall.net'               => ['/embed/', '/resource/'],
    'www.loom.com'               => ['/embed/'],
    'www.geogebra.org'           => ['/material/iframe/', '/m/'],
    'phet.colorado.edu'          => ['/sims/', '/es/simulations/'],
    'padlet.com'                 => ['/embed/'],
    'embed.ted.com'              => ['/talks/'],
    'www.slideshare.net'         => ['/slideshow/embed_code/'],
];

const DESC_ALLOWED_TAGS = [
    'p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'u', 's', 'small', 'mark', 'sup', 'sub', 'span', 'div',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre', 'code',
    'a', 'img', 'figure', 'figcaption', 'video', 'audio', 'source', 'iframe',
    'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'details', 'summary',
];

/** Etiquetas que se eliminan junto con TODO su contenido. */
const DESC_STRIP_ENTIRELY = [
    'script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'option',
    'svg', 'math', 'link', 'meta', 'base', 'noscript', 'template', 'frame', 'frameset', 'applet', 'canvas',
];

/** Propiedades CSS permitidas en style="...". */
const DESC_ALLOWED_CSS = [
    'color', 'background', 'background-color', 'text-align', 'font-size', 'font-weight', 'font-style',
    'font-family', 'text-decoration', 'line-height', 'letter-spacing', 'width', 'max-width', 'height',
    'max-height', 'min-height', 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
    'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right', 'border', 'border-top',
    'border-bottom', 'border-left', 'border-right', 'border-radius', 'border-collapse', 'display',
    'object-fit', 'aspect-ratio', 'vertical-align', 'list-style-type', 'text-transform',
];

function desc_normalize_format(?string $format): string
{
    return in_array($format, DESC_FORMATS, true) ? $format : 'text';
}

/** ¿La URL tiene un esquema permitido? $allowed: lista de esquemas; ''= ruta relativa. */
function desc_safe_url(string $url, array $allowed = ['http', 'https']): ?string
{
    $clean = preg_replace('/[\x00-\x20\x7F]+/', '', $url);   // los navegadores ignoran espacios/control dentro del esquema
    if ($clean === '' || $clean === null) {
        return null;
    }
    if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $clean, $m)) {
        return in_array(strtolower($m[1]), $allowed, true) ? trim($url) : null;
    }
    if (str_starts_with($clean, '//')) {      // "//dominio" hereda el esquema: se trata como https
        return in_array('https', $allowed, true) ? 'https:' . $clean : null;
    }
    return trim($url);                         // ruta relativa (/uploads/..., #ancla)
}

/** Valida una dirección de iframe contra la lista de sitios permitidos. Devuelve la URL o null. */
function desc_safe_iframe_src(string $url): ?string
{
    $url = desc_safe_url($url, ['https']);
    if ($url === null) {
        return null;
    }
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '/';
    if ($host === 'youtube.com' || $host === 'm.youtube.com') {
        $host = 'www.youtube.com';
    }
    if (!isset(DESC_IFRAME_HOSTS[$host]) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])) {
        return null;
    }
    foreach (DESC_IFRAME_HOSTS[$host] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return 'https://' . $host . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
    }
    return null;
}

/** Filtra un atributo style="..." dejando solo propiedades y valores seguros. */
function desc_clean_style(string $style): string
{
    $out = [];
    foreach (explode(';', $style) as $decl) {
        if (strpos($decl, ':') === false) {
            continue;
        }
        [$prop, $val] = array_map('trim', explode(':', $decl, 2));
        $prop = strtolower($prop);
        $lower = strtolower($val);
        if (!in_array($prop, DESC_ALLOWED_CSS, true) || $val === '' || strlen($val) > 200) {
            continue;
        }
        if (preg_match('/url\s*\(|expression|javascript|@import|behavior|binding|\\\\|<|>/i', $lower)) {
            continue;
        }
        if (!preg_match('/^[#a-zA-Z0-9\s.,%\-()\/"\']+$/', $val)) {
            continue;
        }
        if ($prop === 'display' && !in_array($lower, ['block', 'inline', 'inline-block', 'flex', 'none'], true)) {
            continue;
        }
        $out[] = $prop . ': ' . $val;
    }
    return implode('; ', $out);
}

/** Limpia recursivamente el DOM según la lista blanca. */
function desc_sanitize_node(DOMNode $node): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            $node->removeChild($child);   // comentarios, CDATA, instrucciones de proceso
            continue;
        }

        /** @var DOMElement $child */
        $tag = strtolower($child->nodeName);

        if (in_array($tag, DESC_STRIP_ENTIRELY, true)) {
            $node->removeChild($child);
            continue;
        }
        if (!in_array($tag, DESC_ALLOWED_TAGS, true)) {
            // Etiqueta desconocida: se conserva su contenido pero no la etiqueta.
            desc_sanitize_node($child);
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            continue;
        }

        // Atributos: se recopilan y se reconstruyen solo los permitidos.
        $attrs = [];
        foreach (iterator_to_array($child->attributes) as $attr) {
            $attrs[strtolower($attr->nodeName)] = $attr->nodeValue;
        }
        foreach (array_keys($attrs) as $name) {
            $child->removeAttribute($name);
        }

        $set = function (string $name, ?string $value) use ($child): void {
            if ($value !== null && $value !== '') {
                $child->setAttribute($name, $value);
            }
        };

        // Atributos comunes
        if (isset($attrs['style'])) {
            $set('style', desc_clean_style($attrs['style']));
        }
        if (isset($attrs['title'])) {
            $set('title', mb_substr($attrs['title'], 0, 200));
        }

        switch ($tag) {
            case 'a':
                $href = isset($attrs['href']) ? desc_safe_url($attrs['href'], ['http', 'https', 'mailto', 'tel']) : null;
                if ($href === null) {
                    // enlace sin destino válido: se deja solo el texto
                    desc_sanitize_node($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue 2;
                }
                $set('href', $href);
                if (preg_match('/^https?:/i', $href)) {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer nofollow');
                }
                break;

            case 'img':
                $src = isset($attrs['src']) ? desc_safe_url($attrs['src'], ['http', 'https']) : null;
                if ($src === null) {
                    $node->removeChild($child);
                    continue 2;
                }
                $set('src', $src);
                $set('alt', mb_substr((string) ($attrs['alt'] ?? ''), 0, 200));
                foreach (['width', 'height'] as $dim) {
                    if (isset($attrs[$dim]) && preg_match('/^\d{1,4}%?$/', $attrs[$dim])) {
                        $set($dim, $attrs[$dim]);
                    }
                }
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('referrerpolicy', 'no-referrer');
                break;

            case 'iframe':
                $src = isset($attrs['src']) ? desc_safe_iframe_src($attrs['src']) : null;
                if ($src === null) {
                    $node->removeChild($child);
                    continue 2;
                }
                $set('src', $src);
                foreach (['width', 'height'] as $dim) {
                    if (isset($attrs[$dim]) && preg_match('/^\d{1,4}%?$/', $attrs[$dim])) {
                        $set($dim, $attrs[$dim]);
                    }
                }
                $child->setAttribute('allowfullscreen', 'allowfullscreen');
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                $child->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups allow-forms');
                $set('title', $attrs['title'] ?? 'Contenido incrustado');
                while ($child->firstChild) {          // un iframe no lleva contenido
                    $child->removeChild($child->firstChild);
                }
                break;

            case 'video':
            case 'audio':
                $src = isset($attrs['src']) ? desc_safe_url($attrs['src'], ['http', 'https']) : null;
                $set('src', $src);
                $child->setAttribute('controls', 'controls');
                $child->setAttribute('preload', 'metadata');
                if ($tag === 'video') {
                    foreach (['width', 'height'] as $dim) {
                        if (isset($attrs[$dim]) && preg_match('/^\d{1,4}%?$/', $attrs[$dim])) {
                            $set($dim, $attrs[$dim]);
                        }
                    }
                    if (isset($attrs['poster']) && ($p = desc_safe_url($attrs['poster'], ['http', 'https'])) !== null) {
                        $set('poster', $p);
                    }
                }
                break;

            case 'source':
                $src = isset($attrs['src']) ? desc_safe_url($attrs['src'], ['http', 'https']) : null;
                if ($src === null) {
                    $node->removeChild($child);
                    continue 2;
                }
                $set('src', $src);
                if (isset($attrs['type']) && preg_match('#^(video|audio)/[a-z0-9.+\-]+$#i', $attrs['type'])) {
                    $set('type', $attrs['type']);
                }
                break;

            case 'td':
            case 'th':
                foreach (['colspan', 'rowspan'] as $span) {
                    if (isset($attrs[$span]) && preg_match('/^\d{1,2}$/', $attrs[$span])) {
                        $set($span, $attrs[$span]);
                    }
                }
                break;

            case 'ol':
                if (isset($attrs['start']) && preg_match('/^\d{1,4}$/', $attrs['start'])) {
                    $set('start', $attrs['start']);
                }
                break;
        }

        desc_sanitize_node($child);
    }
}

/** Limpia un HTML escrito por el profesor. Devuelve HTML seguro. */
function sanitize_description_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (strlen($html) > 60000) {
        $html = substr($html, 0, 60000);
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="aula-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();

    $root = $doc->getElementById('aula-root');
    if ($root === null) {
        return '';
    }
    desc_sanitize_node($root);

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/**
 * Lo que se guarda en la base de datos: [texto, formato]. En HTML el contenido ya queda limpio.
 */
function desc_prepare_for_save(string $raw, ?string $format): array
{
    $format = desc_normalize_format($format);
    return $format === 'html' ? [sanitize_description_html($raw), 'html'] : [clean_string($raw), 'text'];
}

/**
 * HTML listo para mostrar. Texto sencillo: se escapa y se respetan los saltos de línea.
 * HTML: se vuelve a limpiar y se envuelve en .rich-desc (imágenes y videos se ajustan al ancho).
 */
function render_description(?string $text, ?string $format = 'text'): string
{
    $text = (string) $text;
    if (trim($text) === '') {
        return '';
    }
    if (desc_normalize_format($format) !== 'html') {
        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    static $cssPrinted = false;
    $css = '';
    if (!$cssPrinted) {
        $cssPrinted = true;
        $css = '<style>
.rich-desc{overflow-wrap:anywhere;line-height:1.55}
.rich-desc img,.rich-desc video{max-width:100%;height:auto;border-radius:8px}
.rich-desc iframe{max-width:100%;border:0;border-radius:8px}
.rich-desc iframe[src*="youtube"],.rich-desc iframe[src*="vimeo"]{width:100%;aspect-ratio:16/9;height:auto}
.rich-desc table{border-collapse:collapse;max-width:100%;display:block;overflow-x:auto}
.rich-desc th,.rich-desc td{border:1px solid #CBD5E1;padding:6px 10px}
.rich-desc th{background:#F1F5F9}
.rich-desc blockquote{margin:8px 0;padding:6px 14px;border-left:4px solid #BFDBFE;background:#F8FAFC}
.rich-desc pre{background:#0F172A;color:#E2E8F0;padding:10px 12px;border-radius:8px;overflow-x:auto}
.rich-desc a{color:#0658C2}
.rich-desc > :first-child{margin-top:0}.rich-desc > :last-child{margin-bottom:0}
</style>';
    }
    return $css . '<div class="rich-desc">' . sanitize_description_html($text) . '</div>';
}

/** ¿Hay contenido visible? (para no mostrar tarjetas vacías). */
function desc_has_content(?string $text): bool
{
    return trim((string) $text) !== '';
}
