<?php
if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}
$pageTitle = $pageTitle ?? APP_NAME;
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/style.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= e(rtrim(APP_URL, '/')) ?>/index.php">AulaInteractiva</a>
        <nav class="topnav">
            <?php if (is_logged_in()): ?>
                <span class="nav-user">Hola, <?= e(current_user_name()) ?></span>
                <a href="<?= e(rtrim(APP_URL, '/')) ?>/<?= e(dashboard_url_for_role(current_role())) ?>">Panel</a>
                <a href="<?= e(rtrim(APP_URL, '/')) ?>/logout.php">Salir</a>
            <?php else: ?>
                <a href="<?= e(rtrim(APP_URL, '/')) ?>/login.php">Iniciar sesión</a>
                <a href="<?= e(rtrim(APP_URL, '/')) ?>/register.php" class="btn-link">Registrarse</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="app-main">
