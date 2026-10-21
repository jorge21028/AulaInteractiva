<?php
/** Copia de seguridad de la base de datos (solo súper administrador). */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/backup_helpers.php';

admin_require();
$pdo = Database::getConnection();
$adminId = (int) current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $gzip = ($_POST['format'] ?? 'gz') !== 'sql';
    audit_log($pdo, $adminId, 'admin_backup', 'Copia de seguridad de la base de datos (' . ($gzip && backup_can_gzip() ? '.sql.gz' : '.sql') . ')');
    backup_download($pdo, $gzip);   // termina el script
}

$overview = backup_overview($pdo);
$last = backup_last_time($pdo);
$daysAgo = $last ? (int) floor((time() - strtotime($last)) / 86400) : null;
$hasGz = backup_can_gzip();

$pageTitle = 'Copia de seguridad';
$adminActive = 'backup';
require __DIR__ . '/../includes/header.php';
?>
<h1>💾 Copia de seguridad</h1>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>

<section class="card" style="<?= ($daysAgo === null || $daysAgo > 7) ? 'border-color:#FED7AA; background:#FFF7ED;' : '' ?>">
    <p style="margin-top:0;">
        Última copia descargada:
        <strong><?= $last ? e(dt_format($last)) . ' (hace ' . (int) $daysAgo . ' día' . ($daysAgo === 1 ? '' : 's') . ')' : 'nunca' ?></strong>
        <?= ($daysAgo === null || $daysAgo > 7) ? '<br><span style="color:#9A3412;">⚠️ Se recomienda hacer una copia al menos una vez por semana.</span>' : '' ?>
    </p>
    <p class="text-muted" style="margin-bottom:12px;">
        Descarga un archivo con <strong>toda la base de datos</strong> (usuarios, cursos, actividades, entregas, notas…) para restaurarla si algo sale mal
        o si cambias de hosting. Tamaño actual de la base de datos: <strong><?= $overview['bytes'] ? e(admin_format_bytes($overview['bytes'])) : '—' ?></strong>.
    </p>
    <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:0;">
        <?php csrf_field(); ?>
        <?php if ($hasGz): ?>
            <button class="btn" type="submit" name="format" value="gz" style="margin:0;">⬇️ Descargar copia (.sql.gz, comprimida)</button>
        <?php endif; ?>
        <button class="btn <?= $hasGz ? 'btn-secondary' : '' ?>" type="submit" name="format" value="sql" style="margin:0;">⬇️ Descargar copia (.sql)</button>
    </form>
    <p class="text-muted" style="font-size:0.85rem; margin-bottom:0;">Si la base de datos es grande, la descarga puede tardar un poco: espera a que termine.</p>
</section>

<section class="card" style="margin-top:16px;">
    <h2 style="margin-top:0;">Importante</h2>
    <ul style="padding-left:20px; margin:0;">
        <li>La copia contiene <strong>datos personales y contraseñas cifradas</strong>: guárdala en un lugar privado y no la subas a GitHub ni la compartas.</li>
        <li>No incluye las <strong>imágenes, audio y video</strong> de la carpeta <code>uploads/</code>. Para respaldarlas, descarga esa carpeta desde el Administrador de archivos del hosting.</li>
        <li><strong>Para restaurar:</strong> phpMyAdmin → elige la base de datos → <em>Importar</em> → selecciona el archivo (acepta <code>.sql.gz</code>). La restauración reemplaza las tablas existentes.</li>
    </ul>
</section>

<section class="card" style="margin-top:16px;">
    <h2 style="margin-top:0;">Tablas incluidas (<?= count($overview['tables']) ?>)</h2>
    <p class="text-muted" style="font-size:0.85rem; line-height:1.7; margin:0;">
        <?php foreach ($overview['tables'] as $t): ?>
            <span style="display:inline-block; margin-right:10px;"><code><?= e($t['name']) ?></code> (<?= (int) $t['rows'] ?>)</span>
        <?php endforeach; ?>
    </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
