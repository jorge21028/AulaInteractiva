<?php
/** Limpieza de imágenes huérfanas (solo súper administrador). */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/orphan_helpers.php';
require_once __DIR__ . '/../includes/delete_helpers.php';

admin_require();
@set_time_limit(120);
$pdo = Database::getConnection();
$adminId = (int) current_user_id();
$errors = [];
$done = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify($_POST['csrf_token'] ?? null);
        if (($_POST['confirm'] ?? '') !== '1') {
            $errors[] = 'Marca la casilla para confirmar.';
        } else {
            $names = array_column(orphan_scan($pdo)['orphans'], 'name');
            [$files, $bytes] = orphan_delete($pdo, $names);
            $done = ['files' => $files, 'bytes' => $bytes];
            audit_log($pdo, $adminId, 'admin_orphan_images', "{$files} imagen(es) huérfana(s) borrada(s)");
        }
    }
    $scan = orphan_scan($pdo);
} catch (Throwable $e) {
    error_log('orphan_images: ' . $e->getMessage());
    $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible revisar las imágenes.';
    $scan = ['orphans' => [], 'total' => 0, 'referenced' => 0];
}
$orphanBytes = array_sum(array_column($scan['orphans'], 'size'));
$sample = array_slice($scan['orphans'], 0, 12);
$base = (parse_url(APP_URL, PHP_URL_PATH) ?: '');

$pageTitle = 'Imágenes huérfanas';
$adminActive = 'images';
require __DIR__ . '/../includes/header.php';
?>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<p><a href="images.php">&larr; Herramientas de imágenes</a></p>
<h1>🧹 Imágenes huérfanas</h1>
<p class="text-muted" style="margin-top:-8px;">
    Son archivos de <code>uploads/images</code> que ya no usa ningún trabajo, pregunta, descripción ni aviso (por ejemplo, imágenes que alguien subió pero nunca guardó en su trabajo).
    Solo se consideran las de más de 24 horas. Nada que esté en uso se borra.
</p>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($done): ?>
    <div class="alert alert-success">✅ Se borraron <strong><?= (int) $done['files'] ?></strong> imagen(es) huérfana(s) y se liberaron <strong><?= e(admin_format_bytes((int) $done['bytes'])) ?></strong>.</div>
<?php endif; ?>

<section class="card">
    <p style="margin-top:0;">
        Imágenes en el servidor: <strong><?= (int) $scan['total'] ?></strong> ·
        en uso: <strong><?= (int) $scan['total'] - count($scan['orphans']) ?></strong> ·
        huérfanas: <strong style="color:<?= count($scan['orphans']) ? '#B91C1C' : 'inherit' ?>;"><?= count($scan['orphans']) ?></strong>
        (<?= e(admin_format_bytes((int) $orphanBytes)) ?>)
    </p>
    <?php if (empty($scan['orphans'])): ?>
        <p class="empty-state">No hay imágenes huérfanas. 🎉</p>
    <?php else: ?>
        <p class="text-muted" style="font-size:0.85rem;">Ejemplos (las más pesadas):</p>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
            <?php foreach ($sample as $o): ?>
                <a href="<?= e($base . '/api/media/image.php?f=' . rawurlencode($o['name'])) ?>" target="_blank" title="<?= e($o['name'] . ' — ' . admin_format_bytes($o['size']) . ' — ' . date('d/m/Y', $o['mtime'])) ?>">
                    <img src="<?= e($base . '/api/media/image.php?f=' . rawurlencode($o['name'])) ?>" alt="" loading="lazy" style="width:90px; height:70px; object-fit:cover; border:1px solid #CBD5E1; border-radius:6px;">
                </a>
            <?php endforeach; ?>
        </div>
        <form method="post" onsubmit="return confirm('¿Borrar <?= count($scan['orphans']) ?> imagen(es) huérfana(s)? No se puede deshacer.')">
            <?php csrf_field(); ?>
            <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
                <input type="checkbox" name="confirm" value="1" style="width:auto;"> Entiendo que se borrarán definitivamente
            </label>
            <button class="btn" type="submit" style="background:#B91C1C; border-color:#B91C1C;">🧹 Borrar las <?= count($scan['orphans']) ?> huérfanas</button>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
