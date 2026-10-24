<?php
// Envío automático al terminar el tiempo (Fase 23): barrido perezoso, como mucho una vez por minuto
if (function_exists('is_logged_in') && is_logged_in()) {
    try {
        require_once __DIR__ . '/autosubmit_helpers.php';
        autosubmit_maybe_sweep(Database::getConnection());
    } catch (Throwable $e) {
        error_log('autosubmit header: ' . $e->getMessage());
    }
}

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
<?php if (is_logged_in() && current_role() === 'student'): ?>
<link rel="stylesheet" href="<?= e($baseUrl) ?>/assets/css/notifications.css">
<?php endif; ?>
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
                <?php if (current_role() === 'student'):
                    require_once __DIR__ . '/notification_helpers.php';
                    $notifPdo = Database::getConnection();
                    notifications_sync_student($notifPdo, (int) current_user_id());
                    $notifUnread = notifications_unread_count($notifPdo, (int) current_user_id());
                ?>
                    <span class="notif-wrap" id="notif-wrap">
                        <button type="button" class="notif-btn" aria-label="Notificaciones" title="Notificaciones">🔔<span class="notif-badge<?= $notifUnread > 0 ? ' on' : '' ?>"><?= $notifUnread > 99 ? '99+' : (int) $notifUnread ?></span></button>
                        <div class="notif-panel" role="dialog" aria-label="Notificaciones"></div>
                    </span>
                <?php endif; ?>
                <span class="nav-user">Hola, <?= e(current_user_name()) ?></span>
                <a href="<?= e($baseUrl) ?>/<?= e(dashboard_url_for_role(current_role())) ?>">Panel</a>
                <?php if (current_role() === 'admin'): ?>
                    <a href="<?= e($baseUrl) ?>/change_password.php">Mi contraseña</a>
                <?php elseif (current_role() === 'teacher'): ?>
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
<?php if (is_logged_in() && current_role() === 'student'): ?>
<script src="<?= e($baseUrl) ?>/assets/js/notifications.js"></script>
<script>AulaNotif.init({ listUrl: <?= json_encode($baseUrl . '/api/notifications/list.php') ?>, readUrl: <?= json_encode($baseUrl . '/api/notifications/read.php') ?>, allUrl: <?= json_encode($baseUrl . '/student/notifications.php') ?>, csrf: <?= json_encode(csrf_token()) ?> });</script>
<?php endif; ?>
<?php if (function_exists('is_impersonating') && is_impersonating()): ?>
<div class="no-print" style="background:#7C2D12; color:#fff; padding:8px 16px; display:flex; gap:12px; align-items:center; justify-content:center; flex-wrap:wrap; font-size:0.9rem; position:sticky; top:0; z-index:50;">
    <span>👁️ Estás viendo el sistema como <strong><?= e(current_user_name()) ?></strong> (profesor). Todo lo que hagas se aplica a su cuenta y queda registrado.</span>
    <form method="post" action="<?= e($baseUrl) ?>/stop_impersonation.php" style="margin:0;">
        <?php csrf_field(); ?>
        <button type="submit" style="background:#fff; color:#7C2D12; border:0; border-radius:6px; padding:5px 12px; font-weight:700; cursor:pointer;">↩ Volver a administrador</button>
    </form>
</div>
<?php endif; ?>
<main class="app-main">
