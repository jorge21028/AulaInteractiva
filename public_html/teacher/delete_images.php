<?php
/**
 * Esta herramienta ahora es exclusiva del súper administrador (ver admin/delete_images.php).
 * Se deja este archivo para que ningún enlace o copia antigua funcione desde la cuenta de un profesor.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
redirect(dashboard_url_for_role(current_role()));
