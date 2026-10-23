<?php
/**
 * templates_helpers.php — Plantillas para los editores (Fase 22).
 *
 * Cada plantilla es el MISMO formato de datos que ya guarda cada editor (html de Quill, columnas/filas de la tabla,
 * objetos de Fabric.js, diapositivas), así que no hace falta ninguna tabla nueva: el profesor puede elegir una como
 * punto de partida de la asignación (assignments.template_key) y el estudiante puede aplicar otra desde el editor.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const TPL_BLUE = '#0658C2';
const TPL_LIGHT = '#E8F3FF';
const TPL_TEXT = '#1E293B';
const TPL_MUTED = '#64748B';

// ---------------------------------------------------------------- piezas para lienzos (Fabric.js 5)
function tpl_text(string $text, int $left, int $top, int $width, int $size = 22, string $fill = TPL_TEXT, bool $bold = false, string $align = 'left'): array
{
    return [
        'type' => 'textbox', 'left' => $left, 'top' => $top, 'width' => $width, 'text' => $text, 'fontSize' => $size,
        'fill' => $fill, 'fontFamily' => 'Arial', 'fontWeight' => $bold ? 'bold' : 'normal', 'textAlign' => $align,
    ];
}

function tpl_rect(int $left, int $top, int $width, int $height, string $fill, string $stroke = '', int $radius = 14): array
{
    return [
        'type' => 'rect', 'left' => $left, 'top' => $top, 'width' => $width, 'height' => $height, 'fill' => $fill,
        'stroke' => $stroke !== '' ? $stroke : null, 'strokeWidth' => $stroke !== '' ? 3 : 0, 'rx' => $radius, 'ry' => $radius,
    ];
}

function tpl_circle(int $left, int $top, int $radius, string $fill): array
{
    return ['type' => 'circle', 'left' => $left, 'top' => $top, 'radius' => $radius, 'fill' => $fill];
}

function tpl_line(int $x1, int $y1, int $x2, int $y2, string $color = TPL_BLUE, int $w = 4): array
{
    return [
        'type' => 'line', 'left' => min($x1, $x2), 'top' => min($y1, $y2), 'width' => abs($x2 - $x1), 'height' => abs($y2 - $y1),
        'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'stroke' => $color, 'strokeWidth' => $w,
    ];
}

function tpl_layer(int $w, int $h, array $objects, string $bg = '#ffffff'): array
{
    return ['width' => $w, 'height' => $h, 'backgroundColor' => $bg, 'objects' => $objects];
}

/** Tarjeta con título y texto de ayuda (para infografías y diapositivas). */
function tpl_card(int $l, int $t, int $w, int $h, string $title, string $hint, string $fill = TPL_LIGHT, string $titleColor = TPL_BLUE): array
{
    return [
        tpl_rect($l, $t, $w, $h, $fill),
        tpl_text($title, $l + 20, $t + 16, $w - 40, 26, $titleColor, true),
        tpl_text($hint, $l + 20, $t + 60, $w - 40, 20, TPL_MUTED),
    ];
}

// ---------------------------------------------------------------- catálogo
function templates_catalog(): array
{
    return [
        'resumen' => [
            'resumen_estructurado' => ['Resumen estructurado', 'Tema, ideas principales, palabras clave y conclusión.'],
            'ficha_lectura' => ['Ficha de lectura', 'Datos del texto, resumen, personajes o ideas y opinión personal.'],
            'informe_laboratorio' => ['Informe de laboratorio', 'Objetivo, hipótesis, materiales, procedimiento, resultados y conclusiones.'],
            'cornell' => ['Notas estilo Cornell', 'Preguntas clave, notas y resumen final.'],
            'ensayo' => ['Ensayo breve', 'Introducción, desarrollo en tres ideas y conclusión.'],
        ],
        'tabla_comparativa' => [
            'comparacion' => ['Comparación de dos opciones', 'Criterios en filas, dos opciones en columnas.'],
            'pros_contras' => ['Ventajas y desventajas', 'Por cada tema, lo bueno y lo malo.'],
            'antes_despues' => ['Antes y después', 'Cómo cambió cada aspecto.'],
            'sqa' => ['Lo que sé / quiero saber / aprendí', 'Organizador SQA para antes y después de estudiar.'],
            'causa_efecto' => ['Causa y efecto', 'Causas, efectos y un ejemplo de cada una.'],
        ],
        'infografia' => [
            'proceso_4' => ['Proceso en 4 pasos', 'Título y cuatro pasos numerados.'],
            'datos_clave' => ['Datos clave', 'Tres cifras o datos destacados y una conclusión.'],
            'comparativa' => ['Comparativa en dos columnas', 'Dos ideas frente a frente con una conclusión.'],
        ],
        'mapa_mental' => [
            'mapa_4' => ['Idea central y 4 ramas', 'Un tema central con cuatro ideas principales.'],
            'mapa_6' => ['Idea central y 6 ramas', 'Un tema central con seis ideas principales.'],
        ],
        'presentacion' => [
            'basica_5' => ['Presentación básica (5 diapositivas)', 'Portada, introducción, desarrollo, ejemplo y conclusión.'],
            'problema_solucion' => ['Problema y solución (4 diapositivas)', 'Portada, problema, solución y cierre.'],
        ],
    ];
}

/** Plantillas disponibles para un tipo de trabajo: [key => ['name','desc']]. */
function templates_for_type(string $type): array
{
    $out = [];
    foreach (templates_catalog()[$type] ?? [] as $key => [$name, $desc]) {
        $out[$key] = ['name' => $name, 'desc' => $desc];
    }
    return $out;
}

/** Datos listos para guardar en el trabajo del estudiante, o null si la plantilla no existe. */
function templates_data(string $type, ?string $key): ?array
{
    if ($key === null || $key === '' || !isset(templates_catalog()[$type][$key])) {
        return null;
    }
    return match ($type) {
        'resumen' => ['html' => tpl_resumen_html($key)],
        'tabla_comparativa' => tpl_tabla($key),
        'infografia' => tpl_infografia($key),
        'mapa_mental' => tpl_mapa($key),
        'presentacion' => tpl_presentacion($key),
        default => null,
    };
}

// ---------------------------------------------------------------- resúmenes (HTML de Quill)
function tpl_resumen_html(string $key): string
{
    $sec = fn(string $h, string $hint = 'Escribe aquí...') => "<h2>{$h}</h2><p>{$hint}</p>";
    $list = fn(string $h, int $n) => "<h2>{$h}</h2><ul>" . str_repeat('<li>Escribe aquí...</li>', $n) . '</ul>';

    return match ($key) {
        'resumen_estructurado' => $sec('Tema') . $list('Ideas principales', 3) . $list('Palabras clave', 3) . $sec('Conclusión'),
        'ficha_lectura' => $sec('Título y autor') . $sec('De qué trata (resumen)') . $list('Personajes o ideas importantes', 3) . $sec('Mi opinión personal'),
        'informe_laboratorio' => $sec('Objetivo') . $sec('Hipótesis') . $list('Materiales', 3)
            . '<h2>Procedimiento</h2><ol><li>Escribe aquí...</li><li>Escribe aquí...</li><li>Escribe aquí...</li></ol>'
            . $sec('Resultados') . $sec('Conclusiones'),
        'cornell' => $list('Preguntas clave', 3) . $sec('Notas', 'Escribe aquí tus apuntes de la clase o la lectura...') . $sec('Resumen (en tus propias palabras)'),
        'ensayo' => $sec('Introducción', 'Presenta el tema y tu idea central.') . $sec('Desarrollo — idea 1') . $sec('Desarrollo — idea 2') . $sec('Desarrollo — idea 3') . $sec('Conclusión', 'Cierra retomando tu idea central.'),
        default => '',
    };
}

// ---------------------------------------------------------------- tablas
function tpl_tabla(string $key): array
{
    $blank = fn(int $cols, int $rows) => array_fill(0, $rows, array_fill(0, $cols, ''));
    return match ($key) {
        'comparacion' => ['columns' => ['Criterio', 'Opción A', 'Opción B'], 'rows' => [['Definición', '', ''], ['Características', '', ''], ['Ventajas', '', ''], ['Desventajas', '', '']]],
        'pros_contras' => ['columns' => ['Tema', 'Ventajas', 'Desventajas'], 'rows' => $blank(3, 3)],
        'antes_despues' => ['columns' => ['Aspecto', 'Antes', 'Después'], 'rows' => $blank(3, 4)],
        'sqa' => ['columns' => ['Lo que sé', 'Lo que quiero saber', 'Lo que aprendí'], 'rows' => $blank(3, 4)],
        'causa_efecto' => ['columns' => ['Causa', 'Efecto', 'Ejemplo'], 'rows' => $blank(3, 4)],
        default => ['columns' => ['Criterio', 'Opción A', 'Opción B'], 'rows' => [['', '', '']]],
    };
}

// ---------------------------------------------------------------- infografías (800 x 1200)
function tpl_infografia(string $key): array
{
    $o = [tpl_rect(0, 0, 800, 170, TPL_BLUE, '', 0), tpl_text('Título de tu infografía', 40, 50, 720, 44, '#FFFFFF', true, 'center'),
          tpl_text('Una frase que explique de qué trata', 40, 112, 720, 22, '#DCEBFF', false, 'center')];

    if ($key === 'proceso_4') {
        $colors = [TPL_LIGHT, '#E9F9F0', '#FFF4DD', '#FDECEC'];
        foreach ([0, 1, 2, 3] as $i) {
            $top = 210 + $i * 230;
            $o[] = tpl_rect(40, $top, 720, 190, $colors[$i]);
            $o[] = tpl_circle(60, $top + 20, 36, TPL_BLUE);
            $o[] = tpl_text((string) ($i + 1), 85, $top + 36, 30, 36, '#FFFFFF', true, 'center');
            $o[] = tpl_text('Paso ' . ($i + 1), 160, $top + 24, 560, 28, TPL_BLUE, true);
            $o[] = tpl_text('Describe este paso en una o dos frases.', 160, $top + 72, 560, 22, TPL_MUTED);
        }
    } elseif ($key === 'datos_clave') {
        foreach ([0, 1, 2] as $i) {
            $left = 40 + $i * 245;
            $o[] = tpl_rect($left, 210, 230, 260, TPL_LIGHT);
            $o[] = tpl_text('00%', $left + 10, 250, 210, 56, TPL_BLUE, true, 'center');
            $o[] = tpl_text('Dato clave ' . ($i + 1), $left + 15, 350, 200, 24, TPL_TEXT, true, 'center');
            $o[] = tpl_text('Explica qué significa esta cifra.', $left + 15, 395, 200, 18, TPL_MUTED, false, 'center');
        }
        $o[] = tpl_text('¿Qué nos dicen estos datos?', 40, 520, 720, 30, TPL_BLUE, true);
        $o[] = tpl_rect(40, 570, 720, 260, '#F8FAFC', '#CBD5E1');
        $o[] = tpl_text('Escribe aquí tu conclusión o análisis.', 60, 590, 680, 22, TPL_MUTED);
    } else { // comparativa
        foreach ([0, 1] as $i) {
            $left = 40 + $i * 370;
            $o[] = tpl_rect($left, 210, 350, 60, $i === 0 ? TPL_BLUE : '#10B981');
            $o[] = tpl_text($i === 0 ? 'Idea A' : 'Idea B', $left + 10, 222, 330, 30, '#FFFFFF', true, 'center');
            $o[] = tpl_rect($left, 280, 350, 520, $i === 0 ? TPL_LIGHT : '#E9F9F0');
            $o[] = tpl_text("• Característica 1\n• Característica 2\n• Característica 3", $left + 20, 310, 310, 22, TPL_TEXT);
        }
        $o[] = tpl_text('En conclusión...', 40, 840, 720, 30, TPL_BLUE, true);
        $o[] = tpl_rect(40, 890, 720, 220, '#F8FAFC', '#CBD5E1');
    }
    return tpl_layer(800, 1200, $o);
}

// ---------------------------------------------------------------- mapas mentales (1400 x 900)
function tpl_mapa(string $key): array
{
    $n = $key === 'mapa_6' ? 6 : 4;
    $pos = $n === 6
        ? [[110, 90], [560, 40], [1010, 90], [110, 700], [560, 760], [1010, 700]]
        : [[140, 110], [980, 110], [140, 690], [980, 690]];
    $colors = ['#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4', '#EC4899'];
    $cx = 700; $cy = 450;
    $o = [];
    foreach ($pos as $i => [$l, $t]) {   // primero las líneas (quedan debajo de las cajas)
        $o[] = tpl_line($cx, $cy, $l + 140, $t + 45, $colors[$i], 5);
    }
    $o[] = tpl_rect($cx - 150, $cy - 60, 300, 120, TPL_BLUE, '', 60);
    $o[] = tpl_text('IDEA CENTRAL', $cx - 130, $cy - 18, 260, 30, '#FFFFFF', true, 'center');
    foreach ($pos as $i => [$l, $t]) {
        $o[] = tpl_rect($l, $t, 280, 90, '#FFFFFF', $colors[$i], 45);
        $o[] = tpl_text('Idea ' . ($i + 1), $l + 20, $t + 28, 240, 26, TPL_TEXT, true, 'center');
    }
    return tpl_layer(1400, 900, $o);
}

// ---------------------------------------------------------------- presentaciones (960 x 540)
function tpl_slide(array $objects, string $bg = '#ffffff'): array
{
    return tpl_layer(960, 540, $objects, $bg);
}

function tpl_presentacion(string $key): array
{
    $cover = tpl_slide([
        tpl_rect(0, 0, 960, 540, TPL_BLUE, '', 0),
        tpl_text('Título de la presentación', 80, 190, 800, 54, '#FFFFFF', true, 'center'),
        tpl_text('Tu nombre · Asignatura · Fecha', 80, 290, 800, 24, '#DCEBFF', false, 'center'),
    ]);
    $content = fn(string $title, string $hint) => tpl_slide([
        tpl_rect(0, 0, 960, 110, TPL_BLUE, '', 0),
        tpl_text($title, 50, 32, 860, 40, '#FFFFFF', true),
        tpl_text($hint, 60, 160, 840, 26, TPL_TEXT),
    ]);

    $slides = $key === 'problema_solucion'
        ? [$cover, $content('El problema', "• ¿Qué situación vamos a resolver?\n• ¿A quién afecta?\n• ¿Por qué importa?"),
           $content('Nuestra solución', "• Qué proponemos\n• Cómo funciona\n• Beneficios"), $content('Conclusión', "• Idea principal\n• Qué sigue\n• ¡Gracias!")]
        : [$cover, $content('Introducción', "• ¿De qué trata el tema?\n• ¿Por qué es importante?"),
           $content('Desarrollo', "• Idea principal 1\n• Idea principal 2\n• Idea principal 3"),
           tpl_slide([
               tpl_rect(0, 0, 960, 110, TPL_BLUE, '', 0), tpl_text('Ejemplo', 50, 32, 860, 40, '#FFFFFF', true),
               tpl_rect(60, 150, 840, 330, TPL_LIGHT), tpl_text('Describe un ejemplo o caso real aquí.', 90, 180, 780, 26, TPL_TEXT),
           ]),
           $content('Conclusión', "• Lo más importante que aprendimos\n• Preguntas")];

    return ['slides' => $slides];
}
