<?php
/** Herramientas de imágenes (exclusivas del súper administrador). */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

admin_require();
$pdo = Database::getConnection();
$stats = admin_stats($pdo);

$pageTitle = 'Imágenes';
$adminActive = 'images';
require __DIR__ . '/../includes/header.php';
?>
<h1>🖼️ Herramientas de imágenes</h1>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<p class="text-muted" style="margin-top:-6px;">Imágenes subidas en el servidor: <strong><?= e(admin_format_bytes((int) $stats['upload_bytes'])) ?></strong>.
    Estas herramientas actúan sobre las imágenes de <strong>todos los profesores</strong> y solo están disponibles para el súper administrador.</p>

<section class="grid grid-3">
    <a class="card" href="optimize_images.php" style="display:block; text-decoration:none; color:inherit;">
        <h3 style="margin-top:0;">🖼️ Optimizar imágenes</h3>
        <p class="text-muted" style="margin-bottom:0;">Reduce el tamaño de las imágenes ya subidas (mismo nombre y formato) para liberar espacio.</p>
    </a>
    <a class="card" href="delete_images.php" style="display:block; text-decoration:none; color:inherit;">
        <h3 style="margin-top:0;">🗑️ Borrar imágenes de trabajos</h3>
        <p class="text-muted" style="margin-bottom:0;">Elimina las imágenes que los estudiantes subieron en las asignaciones que elijas (de cualquier profesor).</p>
    </a>
    <a class="card" href="diagnose_images.php" style="display:block; text-decoration:none; color:inherit;">
        <h3 style="margin-top:0;">🔧 Diagnóstico de imágenes</h3>
        <p class="text-muted" style="margin-bottom:0;">Si las imágenes se suben pero no se ven: prueba el servidor y repara permisos o <code>.htaccess</code>.</p>
    </a>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
