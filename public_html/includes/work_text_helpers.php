<?php
/**
 * work_text_helpers.php — Extrae el TEXTO de lo que entregó un estudiante, para que Gemini pueda sugerir una
 * calificación (Fase 22). Soporta resúmenes, tablas, infografías/mapas/presentaciones (textos de los lienzos),
 * pie de foto de imágenes, comentario + contenido de archivos .docx/.txt/.csv, y las participaciones de un foro.
 * Los PDF, imágenes y Excel no se leen (solo se mencionan sus nombres).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const WORK_TEXT_MAX_CHARS = 12000;

function work_text_clean(string $t): string
{
    $t = html_entity_decode(strip_tags(str_replace(['</p>', '</h1>', '</h2>', '</h3>', '</li>', '<br>', '<br/>'], "\n", $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t\xC2\xA0]+/u", ' ', $t);
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

function work_text_from_canvas_objects(array $objects): array
{
    $out = [];
    foreach ($objects as $o) {
        if (is_array($o) && in_array($o['type'] ?? '', ['textbox', 'i-text', 'text'], true) && trim((string) ($o['text'] ?? '')) !== '') {
            $out[] = trim((string) $o['text']);
        }
        if (is_array($o) && isset($o['objects']) && is_array($o['objects'])) { // grupos
            $out = array_merge($out, work_text_from_canvas_objects($o['objects']));
        }
    }
    return $out;
}

/** Texto de un archivo entregado (solo formatos legibles). */
function work_text_from_file(string $path, string $ext): string
{
    if (!is_file($path)) {
        return '';
    }
    if (in_array($ext, ['txt', 'csv'], true)) {
        return (string) @file_get_contents($path, false, null, 0, 40000);
    }
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
            $xml = preg_replace('~</w:p>~', "\n", $xml);
            return html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    return '';
}

/** Texto del trabajo de un proyecto de creación. */
function work_text_for_project(PDO $pdo, array $project): string
{
    $data = json_decode((string) $project['data_json'], true) ?: [];
    $parts = [];

    switch ($project['type']) {
        case 'resumen':
            $parts[] = work_text_clean((string) ($data['html'] ?? ''));
            break;
        case 'tabla_comparativa':
            $parts[] = implode(' | ', array_map('strval', (array) ($data['columns'] ?? [])));
            foreach ((array) ($data['rows'] ?? []) as $row) {
                $parts[] = implode(' | ', array_map('strval', (array) $row));
            }
            break;
        case 'infografia':
        case 'mapa_mental':
            $parts = work_text_from_canvas_objects((array) ($data['objects'] ?? []));
            break;
        case 'presentacion':
            foreach ((array) ($data['slides'] ?? []) as $i => $slide) {
                $texts = work_text_from_canvas_objects((array) ($slide['objects'] ?? []));
                $parts[] = 'Diapositiva ' . ($i + 1) . ': ' . implode(' / ', $texts);
            }
            break;
        case 'imagenes':
            foreach ((array) ($data['images'] ?? []) as $i => $img) {
                $parts[] = 'Imagen ' . ($i + 1) . (!empty($img['caption']) ? ': ' . $img['caption'] : '');
            }
            break;
        case 'archivos':
            require_once __DIR__ . '/project_files_helpers.php';
            if (trim((string) ($data['note'] ?? '')) !== '') {
                $parts[] = 'Comentario del estudiante: ' . $data['note'];
            }
            foreach (project_files_list($pdo, (int) $project['id'], false) as $f) {
                $ext = strtolower(pathinfo($f['stored_name'], PATHINFO_EXTENSION));
                $text = trim(work_text_from_file(project_files_dir() . '/' . $f['stored_name'], $ext));
                $parts[] = 'Archivo «' . $f['original_name'] . '»' . ($text !== '' ? ":\n" . $text : ' (formato que no se puede leer automáticamente)');
            }
            break;
    }
    return mb_substr(trim(implode("\n", array_filter($parts, fn($p) => trim((string) $p) !== ''))), 0, WORK_TEXT_MAX_CHARS);
}

/** Texto de la participación de un estudiante en un foro. */
function work_text_for_forum(PDO $pdo, int $assignmentId, int $studentId): string
{
    $stmt = $pdo->prepare(
        'SELECT p.title, p.body, p.parent_id, t.title AS thread_title
         FROM forum_posts p LEFT JOIN forum_posts t ON t.id = p.parent_id
         WHERE p.assignment_id = :a AND p.author_id = :s AND p.deleted_at IS NULL ORDER BY p.created_at ASC'
    );
    $stmt->execute(['a' => $assignmentId, 's' => $studentId]);
    $parts = [];
    foreach ($stmt->fetchAll() as $p) {
        $parts[] = $p['parent_id'] === null
            ? 'COMENTARIO' . ($p['title'] ? ' «' . $p['title'] . '»' : '') . ': ' . $p['body']
            : 'RESPUESTA A UN COMPAÑERO: ' . $p['body'];
    }
    return mb_substr(implode("\n\n", $parts), 0, WORK_TEXT_MAX_CHARS);
}
