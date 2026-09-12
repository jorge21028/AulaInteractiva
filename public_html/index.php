<?php
define('AULA_APP', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(dashboard_url_for_role(current_role()));
}

$pageTitle = 'Bienvenido';
require __DIR__ . '/includes/header.php';
?>
<section class="card" style="text-align:center; padding:48px 24px;">
    <h1 style="margin-top:0;">AulaInteractiva</h1>
    <p class="text-muted">Actividades interactivas y trabajos académicos, todo en un mismo lugar.</p>
    <div style="margin-top:24px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
        <a class="btn" href="login.php">Iniciar sesión</a>
        <a class="btn btn-secondary" href="register.php">Crear cuenta</a>
    </div>
</section>

<section class="grid grid-3" style="margin-top:24px;">
    <div class="card">
        <h3>Para profesores</h3>
        <p class="text-muted">Crea actividades interactivas manualmente o con ayuda de IA, asígnalas y revisa resultados en tiempo real.</p>
    </div>
    <div class="card">
        <h3>Para estudiantes</h3>
        <p class="text-muted">Participa en juegos con un código y elabora tus propios trabajos: mapas mentales, resúmenes, infografías y más.</p>
    </div>
    <div class="card">
        <h3>Sin instalaciones</h3>
        <p class="text-muted">Funciona directamente desde el navegador, en computadora, tableta o teléfono.</p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
