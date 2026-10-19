<?php
/**
 * SÚPER ADMINISTRADOR: borrar las imágenes que los estudiantes subieron en las asignaciones (trabajos de creación)
 * de TODOS los profesores.
 * Quita las imágenes del contenido de cada trabajo y elimina los archivos del servidor para liberar espacio.
 * Los textos, tablas, audio y video de los trabajos se conservan.
 * ?assignment=N preselecciona una asignación.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/delete_helpers.php';
require_once __DIR__ . '/../includes/project_helpers.php';

admin_require();
@set_time_limit(120);

$pdo = Database::getConnection();
$adminId = (int) current_user_id();
$errors = [];
$done = null;
$preselect = (int) ($_GET['assignment'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $ids = array_map('intval', (array) ($_POST['assignment_ids'] ?? []));
    if (empty($ids)) {
        $errors[] = 'Marca al menos una asignación.';
    } elseif (($_POST['confirm'] ?? '') !== '1') {
        $errors[] = 'Marca la casilla para confirmar que quieres borrar las imágenes.';
        $preselect = 0;
    } else {
        try {
            $done = delete_assignment_images($pdo, null, $ids);   // administrador: todas las asignaciones de todos los profesores
            audit_log($pdo, $adminId, 'delete_assignment_images', 'Imágenes borradas en ' . count($ids) . ' asignación(es): ' . $done['files'] . ' archivo(s)');
        } catch (Throwable $e) {
            error_log('delete_images: ' . $e->getMessage());
            $errors[] = 'No fue posible borrar las imágenes. No se cambió nada; intenta de nuevo.';
        }
    }
}

$rows = delete_images_overview($pdo, null);
$withImages = array_filter($rows, fn($r) => $r['image_count'] > 0);
$totalFiles = array_sum(array_column($rows, 'image_count'));
$totalBytes = array_sum(array_column($rows, 'image_bytes'));

$pageTitle = 'Borrar imágenes de asignaciones';
require __DIR__ . '/../includes/header.php';
?>
<?php $adminActive = 'images'; require __DIR__ . '/../includes/admin_nav.php'; ?>
<p><a href="images.php">&larr; Herramientas de imágenes</a></p>
<h1>🗑️ Borrar imágenes de las asignaciones</h1>
<p class="text-muted" style="margin-top:-8px;">
    Elimina las imágenes que los estudiantes subieron en los trabajos de <strong>todos los profesores</strong> para liberar espacio. Los textos, tablas, audio y video de los trabajos se conservan;
    solo desaparecen las imágenes. <strong>No se puede deshacer.</strong>
</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($done): ?>
    <div class="alert alert-success">
        ✅ Listo: se borraron <strong><?= (int) $done['files'] ?></strong> imagen(es) y se liberaron <strong><?= e(delete_format_bytes((int) $done['bytes'])) ?></strong>
        (<?= (int) $done['projects'] ?> trabajo(s) modificados).
    </div>
<?php endif; ?>

<?php if (empty($withImages)): ?>
    <div class="card"><p class="empty-state">No hay imágenes subidas por estudiantes en tus asignaciones.</p></div>
<?php else: ?>
    <section class="card">
        <p><strong><?= (int) $totalFiles ?></strong> imagen(es) en <?= count($withImages) ?> asignación(es) · <strong><?= e(delete_format_bytes((int) $totalBytes)) ?></strong>.</p>
        <form method="post" action="delete_images.php" onsubmit="return confirm('¿Borrar las imágenes de las asignaciones marcadas? No se puede deshacer.')">
            <?php csrf_field(); ?>
            <p style="margin:0 0 6px;"><a href="#" id="toggle-all">Marcar / desmarcar todas</a></p>
            <?php foreach ($withImages as $r): ?>
                <label style="display:flex; gap:8px; align-items:center; font-weight:400; padding:6px 0; border-bottom:1px solid var(--color-border);">
                    <input type="checkbox" name="assignment_ids[]" value="<?= (int) $r['id'] ?>" class="as-check" style="width:auto;" <?= (int) $r['id'] === $preselect ? 'checked' : '' ?>>
                    <span>
                        <strong><?= e($r['title']) ?></strong>
                        <span class="text-muted" style="font-size:0.85rem;">
                            · <?= e($r['teacher_name']) ?> · <?= e($r['course_name']) ?><?= (int) $r['is_active'] === 1 ? '' : ' (inactivo)' ?> — <?= e($r['subject_name']) ?> ·
                            <?= (int) $r['image_count'] ?> imagen(es) · <?= e(delete_format_bytes((int) $r['image_bytes'])) ?>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>

            <label style="display:flex; gap:8px; align-items:center; font-weight:600; margin-top:12px;">
                <input type="checkbox" name="confirm" value="1" style="width:auto;"> Entiendo que las imágenes se borrarán definitivamente
            </label>
            <button type="submit" class="btn" style="background:#B91C1C; border-color:#B91C1C;">🗑️ Borrar imágenes de las marcadas</button>
        </form>
        <script>
        document.getElementById('toggle-all').addEventListener('click', function (e) {
            e.preventDefault();
            var boxes = document.querySelectorAll('.as-check');
            var all = Array.prototype.every.call(boxes, function (b) { return b.checked; });
            boxes.forEach(function (b) { b.checked = !all; });
        });
        </script>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
