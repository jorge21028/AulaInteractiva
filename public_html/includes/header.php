<?php
if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}
$pageTitle = $pageTitle ?? APP_NAME;
$baseUrl = rtrim(APP_URL, '/');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>

<!-- Favicons — Dynamic SGA -->
<link rel="icon" type="image/svg+xml" href="<?= e($baseUrl) ?>/assets/icons/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e($baseUrl) ?>/assets/icons/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= e($baseUrl) ?>/assets/icons/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= e($baseUrl) ?>/assets/icons/favicon-180x180.png">
<link rel="manifest" href="<?= e($baseUrl) ?>/site.webmanifest">
<meta name="theme-color" content="#071A3D">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="<?= e($baseUrl) ?>/assets/css/style.css">
</head>
<body>
<header class="topbar no-print">
    <div class="topbar-inner">
        <a class="brand" href="<?= e($baseUrl) ?>/index.php">
            <img src="<?= e($baseUrl) ?>/assets/img/logo-icon.svg" alt="">
            <span>Dynamic <span class="brand-sga">SGA</span></span>
        </a>
        <nav class="topnav">
            <?php if (is_logged_in()): ?>
                <span class="nav-user">Hola, <?= e(current_user_name()) ?></span>
                <a href="<?= e($baseUrl) ?>/<?= e(dashboard_url_for_role(current_role())) ?>">Panel</a>
                <?php if (current_role() === 'teacher'): ?>
                    <a href="<?= e($baseUrl) ?>/teacher/profile.php">Mi perfil</a>
                <?php elseif (current_role() === 'student'): ?>
                    <a href="<?= e($baseUrl) ?>/student/profile.php">Mi perfil</a>
                <?php endif; ?>
                <a href="<?= e($baseUrl) ?>/logout.php">Salir</a>
            <?php else: ?>
                <a href="<?= e($baseUrl) ?>/login.php">Iniciar sesión</a>
                <a href="<?= e($baseUrl) ?>/register.php" class="btn-link">Registrarse</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="app-main">
