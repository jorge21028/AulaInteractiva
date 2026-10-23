<?php
/**
 * GET /api/projects/templates.php?type=resumen|tabla_comparativa|infografia|mapa_mental|presentacion
 * Lista las plantillas de un tipo de trabajo, con sus datos, para el selector del editor.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/templates_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role(['student', 'teacher', 'admin']);

$type = (string) ($_GET['type'] ?? '');
$out = [];
foreach (templates_for_type($type) as $key => $meta) {
    $out[] = ['key' => $key, 'name' => $meta['name'], 'desc' => $meta['desc'], 'data' => templates_data($type, $key)];
}
json_response(['success' => true, 'templates' => $out]);
