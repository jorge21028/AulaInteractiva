<?php
/**
 * GET /api/cron/sweep.php?key=TU_CLAVE
 * Opcional: llámalo cada 5 minutos con un servicio de cron gratuito (p. ej. cron-job.org) para que el envío
 * automático ocurra aunque nadie esté navegando. Requiere definir CRON_KEY en config/env.php:
 *     define('CRON_KEY', 'una-clave-larga-y-secreta');
 * Sin CRON_KEY definida, este endpoint no existe (404).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/autosubmit_helpers.php';

header('Content-Type: application/json; charset=utf-8');
if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals((string) CRON_KEY, (string) ($_GET['key'] ?? ''))) {
    http_response_code(404);
    exit(json_encode(['success' => false]));
}
$done = autosubmit_sweep(Database::getConnection());
echo json_encode(['success' => true] + $done);
