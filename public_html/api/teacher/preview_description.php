<?php
/**
 * POST /api/teacher/preview_description.php
 * Body JSON: { html, csrf_token }  ->  { success, html }
 * Devuelve el HTML ya limpio, exactamente como lo verán los estudiantes (vista previa del profesor).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/description_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('teacher');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;
csrf_verify($input['csrf_token'] ?? null);

json_response(['success' => true, 'html' => render_description((string) ($input['html'] ?? ''), 'html')]);
