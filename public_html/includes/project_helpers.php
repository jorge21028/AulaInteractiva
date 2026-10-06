<?php
/**
 * project_helpers.php
 * Lógica compartida de "creación académica" (Fase 5): resúmenes, tablas
 * comparativas y, en fases futuras, otros tipos de trabajo del estudiante.
 *
 * IMPORTANTE: aquí NUNCA se usa inteligencia artificial para generar o
 * completar el contenido del estudiante (ver requisito 35/20 de la
 * especificación). El estudiante construye el trabajo directamente.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const PROJECT_TYPES = [
    'resumen' => 'Resumen',
    'tabla_comparativa' => 'Tabla comparativa',
    'infografia' => 'Infografía',
    'mapa_mental' => 'Mapa mental',
    'presentacion' => 'Presentación',
    'imagenes' => 'Entrega de imágenes (fotos)',
];

/** Máximo de imágenes por entrega de tipo "imagenes". */
const PROJECT_MAX_IMAGES = 20;

/**
 * Tipos que usan el editor gráfico (Canvas/Fabric.js) de una sola página
 * en vez del editor de texto o de tablas.
 */
const PROJECT_CANVAS_TYPES = ['infografia', 'mapa_mental'];

/**
 * Tipos que usan el editor de varias diapositivas (también Canvas/Fabric.js,
 * pero con una lista de "slides" en vez de un único lienzo).
 */
const PROJECT_SLIDE_TYPES = ['presentacion'];

/**
 * Estructura inicial vacía para un tipo de trabajo nuevo.
 */
function project_default_data(string $type): array
{
    return match ($type) {
        'resumen' => ['html' => ''],
        'tabla_comparativa' => [
            'columns' => ['Criterio', 'Opción A', 'Opción B'],
            'rows' => [
                ['', '', ''],
            ],
        ],
        'infografia' => [
            'width' => 800,
            'height' => 1200,
            'backgroundColor' => '#ffffff',
            'objects' => [],
        ],
        'mapa_mental' => [
            'width' => 1400,
            'height' => 900,
            'backgroundColor' => '#ffffff',
            'objects' => [],
        ],
        'presentacion' => [
            'slides' => [
                ['width' => 960, 'height' => 540, 'backgroundColor' => '#ffffff', 'objects' => []],
            ],
        ],
        'imagenes' => ['images' => []],
        default => [],
    };
}

/**
 * ¿El trabajo (borrador) ya tiene contenido propio del estudiante, o sigue igual que la plantilla vacía?
 * Se compara con la estructura inicial de cada tipo. IMPORTANTE: no se usa la hora de la última edición
 * porque los editores autoguardan cada 20 s aunque el estudiante no haya escrito nada.
 */
function project_has_content(string $type, ?string $dataJson): bool
{
    $data = json_decode((string) $dataJson, true);
    if (!is_array($data)) {
        return false;
    }

    switch ($type) {
        case 'resumen':
            $html = (string) ($data['html'] ?? '');
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = trim(str_replace("\xC2\xA0", ' ', $text)); // espacios duros
            return $text !== '' || (bool) preg_match('/<(img|table|iframe|video|audio)\b/i', $html);

        case 'tabla_comparativa':
            $default = project_default_data('tabla_comparativa');
            $columns = is_array($data['columns'] ?? null) ? $data['columns'] : [];
            $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
            if ($columns !== $default['columns'] || count($rows) > count($default['rows'])) {
                return true;
            }
            foreach ($rows as $row) {
                foreach ((array) $row as $cell) {
                    if (trim((string) $cell) !== '') {
                        return true;
                    }
                }
            }
            return false;

        case 'imagenes':
            return !empty($data['images']);

        case 'infografia':
        case 'mapa_mental':
            return !empty($data['objects']);

        case 'presentacion':
            $slides = is_array($data['slides'] ?? null) ? $data['slides'] : [];
            if (count($slides) > 1) {
                return true;
            }
            foreach ($slides as $slide) {
                if (!empty($slide['objects'])) {
                    return true;
                }
            }
            return false;
    }

    return $data !== project_default_data($type);
}

/**
 * Sanea recursivamente cualquier campo "html" dentro de la estructura de
 * un trabajo, antes de guardarlo. Ver sanitize_rich_html() en security.php.
 * Los campos de texto plano (celdas de tabla, encabezados) se guardan tal
 * cual, ya que se escapan con e() al momento de mostrarlos.
 *
 * Para los tipos de editor gráfico (infografía, mapa mental), además:
 * - Acota el ancho/alto del lienzo a un rango razonable.
 * - Limita la cantidad de objetos (evita payloads abusivos).
 * - Solo permite imágenes que efectivamente subió este mismo estudiante
 *   (por prefijo de URL), para no aceptar referencias a recursos externos.
 */
function project_sanitize_data(array $data, string $type = '', int $studentId = 0): array
{
    if (isset($data['html']) && is_string($data['html'])) {
        $data['html'] = sanitize_rich_html($data['html']);
    }

    if (in_array($type, PROJECT_CANVAS_TYPES, true)) {
        $data = project_sanitize_canvas_layer($data);
    }

    if ($type === 'imagenes') {
        $data = project_sanitize_images_data($data);
    }

    if (in_array($type, PROJECT_SLIDE_TYPES, true)) {
        $slides = is_array($data['slides'] ?? null) ? $data['slides'] : [];
        $slides = array_slice($slides, 0, 60); // límite razonable de diapositivas

        $cleanSlides = [];
        foreach ($slides as $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $cleanSlides[] = project_sanitize_canvas_layer($slide);
        }

        if (empty($cleanSlides)) {
            $cleanSlides = project_default_data('presentacion')['slides'];
        }

        $data['slides'] = $cleanSlides;
    }

    return $data;
}

/**
 * Convierte a rutas locales las URL de archivos subidos que haya dentro de los datos de un trabajo YA guardado
 * (proyectos creados antes de que se guardaran rutas locales, con la URL absoluta de APP_URL). Se usa al cargar
 * un trabajo en el editor o en la vista del profesor, para que las imágenes carguen aunque el sitio se abra con
 * otra dirección (http/https o con/sin www) distinta a APP_URL.
 */
function project_localize_data(array $data, string $type): array
{
    $localizeUrl = function ($url) {
        if (!is_string($url)) {
            return $url;
        }
        foreach (['images', 'audio', 'video'] as $kind) {
            $local = uploads_local_path($url, $kind);
            if ($local !== null) {
                return $local;
            }
        }
        return $url;
    };

    $localizeObjects = function (array $objects) use ($localizeUrl): array {
        foreach ($objects as &$o) {
            if (is_array($o) && isset($o['src'])) {
                $o['src'] = $localizeUrl($o['src']);
            }
        }
        unset($o);
        return $objects;
    };

    if (isset($data['objects']) && is_array($data['objects'])) {
        $data['objects'] = $localizeObjects($data['objects']);
    }
    if (isset($data['slides']) && is_array($data['slides'])) {
        foreach ($data['slides'] as &$slide) {
            if (is_array($slide) && isset($slide['objects']) && is_array($slide['objects'])) {
                $slide['objects'] = $localizeObjects($slide['objects']);
            }
        }
        unset($slide);
    }
    if (isset($data['images']) && is_array($data['images'])) {
        foreach ($data['images'] as &$img) {
            if (is_array($img) && isset($img['url'])) {
                $img['url'] = $localizeUrl($img['url']);
            }
        }
        unset($img);
    }
    if (isset($data['html']) && is_string($data['html'])) {
        $data['html'] = preg_replace_callback(
            '~\b(src)="([^"]+)"~i',
            fn($m) => 'src="' . htmlspecialchars((string) $localizeUrl(html_entity_decode($m[2])), ENT_QUOTES, 'UTF-8') . '"',
            $data['html']
        );
    }

    return $data;
}

/**
 * Sanea los datos de una "Entrega de imágenes": lista de imágenes propias (subidas con upload_image.php), cada una
 * con un pie de foto corto. Se descartan las que no sean archivos subidos por el sistema.
 */
function project_sanitize_images_data(array $data): array
{
    $images = is_array($data['images'] ?? null) ? $data['images'] : [];
    $clean = [];

    foreach ($images as $img) {
        if (!is_array($img) || count($clean) >= PROJECT_MAX_IMAGES) {
            continue;
        }
        $local = uploads_local_path((string) ($img['url'] ?? ''), 'images');
        if ($local === null) {
            continue;
        }
        $clean[] = [
            'url'     => $local,
            'caption' => mb_substr(trim(strip_tags((string) ($img['caption'] ?? ''))), 0, 200),
            'name'    => mb_substr(trim(strip_tags((string) ($img['name'] ?? ''))), 0, 120),
        ];
    }

    return ['images' => $clean];
}

/**
 * Sanea una sola "capa" tipo lienzo (ancho, alto, color de fondo y lista
 * de objetos Fabric.js): se usa tanto para infografías/mapas mentales
 * (una sola capa) como para cada diapositiva de una presentación.
 */
function project_sanitize_canvas_layer(array $layer): array
{
    $layer['width'] = max(200, min(3000, (int) ($layer['width'] ?? 800)));
    $layer['height'] = max(200, min(3000, (int) ($layer['height'] ?? 1200)));

    if (!isset($layer['backgroundColor']) || !is_string($layer['backgroundColor'])) {
        $layer['backgroundColor'] = '#ffffff';
    }

    $objects = is_array($layer['objects'] ?? null) ? $layer['objects'] : [];
    $objects = array_slice($objects, 0, 300);

    foreach ($objects as $i => &$obj) {
        if (!is_array($obj)) {
            unset($objects[$i]);
            continue;
        }
        if (($obj['type'] ?? '') === 'image') {
            // Se guarda la ruta local ("/uploads/images/xxx.jpg"), no la URL absoluta: así la imagen carga aunque el
            // sitio se abra con otra dirección distinta de APP_URL (http/https, con o sin www).
            $local = uploads_local_path((string) ($obj['src'] ?? ''), 'images');
            if ($local !== null) {
                $obj['src'] = $local;
            }
            if ($local === null) {
                // Imagen que no viene de nuestro propio endpoint de subida: descartar el objeto.
                unset($objects[$i]);
            }
        }
    }
    unset($obj);

    $layer['objects'] = array_values($objects);

    return $layer;
}

/**
 * Busca (o crea) el trabajo del estudiante para una asignación de tipo
 * "creación académica". Idempotente: si ya existe, lo devuelve tal cual.
 */
function project_find_or_create_for_assignment(PDO $pdo, int $assignmentId, int $studentId, string $type, string $defaultTitle): array
{
    $stmt = $pdo->prepare('SELECT * FROM student_projects WHERE assignment_id = :aid AND student_id = :sid LIMIT 1');
    $stmt->execute(['aid' => $assignmentId, 'sid' => $studentId]);
    $project = $stmt->fetch();

    if ($project) {
        return $project;
    }

    $insert = $pdo->prepare(
        "INSERT INTO student_projects (student_id, assignment_id, type, title, data_json, status, created_at, updated_at)
         VALUES (:sid, :aid, :type, :title, :data_json, 'draft', :created_at, :updated_at)"
    );
    $now = now_datetime();
    $insert->execute([
        'sid' => $studentId,
        'aid' => $assignmentId,
        'type' => $type,
        'title' => $defaultTitle,
        'data_json' => json_encode(project_default_data($type)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $stmt->execute(['aid' => $assignmentId, 'sid' => $studentId]);
    return $stmt->fetch();
}

/**
 * Guarda el progreso de un trabajo (autosave / guardar borrador).
 * No se puede editar un trabajo ya entregado.
 */
function project_save(PDO $pdo, int $projectId, int $studentId, array $data, ?string $title = null): bool
{
    $stmt = $pdo->prepare('SELECT status, type FROM student_projects WHERE id = :id AND student_id = :sid');
    $stmt->execute(['id' => $projectId, 'sid' => $studentId]);
    $project = $stmt->fetch();

    if (!$project || $project['status'] === 'submitted') {
        return false;
    }

    $fields = 'data_json = :data_json, updated_at = :updated_at';
    $params = [
        'data_json' => json_encode(project_sanitize_data($data, $project['type'], $studentId)),
        'updated_at' => now_datetime(), 'id' => $projectId, 'sid' => $studentId,
    ];

    if ($title !== null && $title !== '') {
        $fields .= ', title = :title';
        $params['title'] = $title;
    }

    $pdo->prepare("UPDATE student_projects SET {$fields} WHERE id = :id AND student_id = :sid")->execute($params);
    return true;
}

/**
 * Marca un trabajo como entregado y actualiza (o crea) la entrega
 * correspondiente en "submissions", en estado 'completed' sin calificar
 * todavía (la calificación de trabajos abiertos la pone el profesor).
 */
function project_submit(PDO $pdo, int $projectId, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM student_projects WHERE id = :id AND student_id = :sid');
    $stmt->execute(['id' => $projectId, 'sid' => $studentId]);
    $project = $stmt->fetch();

    if (!$project || $project['status'] === 'submitted') {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE student_projects SET status = 'submitted', submitted_at = :submitted_at, updated_at = :updated_at WHERE id = :id"
        )->execute(['submitted_at' => now_datetime(), 'updated_at' => now_datetime(), 'id' => $projectId]);

        if ($project['assignment_id']) {
            $subStmt = $pdo->prepare(
                'SELECT id, reviewed_at FROM submissions WHERE assignment_id = :aid AND student_id = :sid'
            );
            $subStmt->execute(['aid' => $project['assignment_id'], 'sid' => $studentId]);
            $submission = $subStmt->fetch();

            if ($submission) {
                // No pisar una calificación que el profesor ya ajustó manualmente.
                if ($submission['reviewed_at'] === null) {
                    // Al reenviar un trabajo devuelto, deja de estar "devuelto".
                    $pdo->prepare(
                        "UPDATE submissions SET status = 'completed', project_id = :pid, completed_at = :now,
                                returned_at = NULL, return_note = NULL WHERE id = :id"
                    )->execute(['pid' => $projectId, 'now' => now_datetime(), 'id' => $submission['id']]);
                } else {
                    $pdo->prepare('UPDATE submissions SET project_id = :pid WHERE id = :id')
                        ->execute(['pid' => $projectId, 'id' => $submission['id']]);
                }
            } else {
                $pdo->prepare(
                    "INSERT INTO submissions (assignment_id, student_id, project_id, status, completed_at, created_at)
                     VALUES (:aid, :sid, :pid, 'completed', :completed_at, :created_at)"
                )->execute([
                    'aid' => $project['assignment_id'], 'sid' => $studentId, 'pid' => $projectId,
                    'completed_at' => now_datetime(), 'created_at' => now_datetime(),
                ]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('project_submit error: ' . $e->getMessage());
        return false;
    }
}
