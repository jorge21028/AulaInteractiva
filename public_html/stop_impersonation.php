<?php
/** Vuelve de "entrar como profesor" a la cuenta del súper administrador (POST con CSRF). */
define('AULA_APP', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_impersonating() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(is_logged_in() ? dashboard_url_for_role(current_role()) : 'login.php');
}
csrf_verify($_POST['csrf_token'] ?? null);

if (stop_impersonation(Database::getConnection())) {
    redirect('admin/dashboard.php');
}
redirect('login.php');
