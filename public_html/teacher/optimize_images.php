<?php
/**
 * Reduce las imágenes que YA están subidas (trabajos de estudiantes e imágenes de preguntas) para liberar
 * espacio en el hosting. Cada imagen conserva su mismo nombre y formato, así que ningún trabajo ni pregunta
 * deja de mostrarla; solo se reemplaza el archivo si el resultado pesa menos. Se procesa por lotes.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image_helpers.php';

require_role('teacher');
@set_time_limit(120);

$dir = UPLOAD_DIR . '/images';
$files = [];
foreach ((array) glob($dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $f) {
    if (is_file($f)) {
        $files[] = $f;
    }
}
sort($files);
$total = count($files);
$totalBytes = array_sum(array_map('filesize', $files));

const OPT_BATCH = 20;
$offset = max(0, (int) ($_POST['offset'] ?? 0));
$savedSoFar = max(0, (int) ($_POST['saved'] ?? 0));
$changedSoFar = max(0, (int) ($_POST['changed'] ?? 0));
$running = false;
$finished = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $running = true;
    $pdo = Database::getConnection();
    $upd = $pdo->prepare('UPDATE uploads SET size_bytes = :size WHERE stored_name = :name');

    $slice = array_slice($files, $offset, OPT_BATCH);
    foreach ($slice as $f) {
        $info = @getimagesize($f);
        if (!$info) {
            continue;
        }
        $res = image_optimize($f, $info['mime'], false);   // mismo formato y mismo nombre
        if ($res['optimized']) {
            $savedSoFar += $res['original_size'] - $res['size'];
            $changedSoFar++;
            $upd->execute(['size' => $res['size'], 'name' => basename($f)]);
        }
    }
    $offset += OPT_BATCH;
    $finished = $offset >= $total;
}

function opt_mb(int $bytes): string
{
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

$pageTitle = 'Optimizar imágenes';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver</a></p>
<h1>🖼️ Optimizar imágenes subidas</h1>
<p class="text-muted" style="margin-top:-8px;">
    Reduce el tamaño de las imágenes que ya están guardadas (lado mayor máximo <?= (int) IMAGE_MAX_SIDE ?> px, sin metadatos).
    Siguen mostrándose igual en los trabajos y preguntas; solo ocupan menos espacio. Las nuevas imágenes ya se reducen solas al subirlas.
</p>

<section class="card">
    <p><strong><?= $total ?></strong> imágenes en el servidor · <strong><?= opt_mb((int) $totalBytes) ?></strong> en total.</p>

    <?php if ($running): ?>
        <?php if (function_exists('imagecreatetruecolor') === false): ?>
            <div class="alert alert-error">Este servidor no tiene la extensión GD de PHP, así que no se pueden reducir imágenes.</div>
        <?php elseif ($finished): ?>
            <div class="alert alert-success">
                ✅ Listo. Se redujeron <?= $changedSoFar ?> imagen(es) y se liberaron <strong><?= opt_mb($savedSoFar) ?></strong>.
            </div>
        <?php else: ?>
            <p>Procesando… <?= min($offset, $total) ?> de <?= $total ?> · liberados hasta ahora: <?= opt_mb($savedSoFar) ?></p>
            <form method="post" action="optimize_images.php" id="next-form">
                <?php csrf_field(); ?>
                <input type="hidden" name="offset" value="<?= (int) $offset ?>">
                <input type="hidden" name="saved" value="<?= (int) $savedSoFar ?>">
                <input type="hidden" name="changed" value="<?= (int) $changedSoFar ?>">
                <button type="submit" class="btn">Continuar</button>
            </form>
            <script>setTimeout(function () { document.getElementById('next-form').submit(); }, 600);</script>
        <?php endif; ?>
    <?php else: ?>
        <p class="text-muted" style="font-size:0.9rem;">
            ⚠️ Esta acción reemplaza los archivos por versiones más pequeñas y no se puede deshacer. Si quieres, haz antes una copia de la carpeta
            <code>uploads/images</code> desde el administrador de archivos del hosting.
        </p>
        <form method="post" action="optimize_images.php" onsubmit="return confirm('¿Reducir todas las imágenes subidas? No se puede deshacer.')">
            <?php csrf_field(); ?>
            <input type="hidden" name="offset" value="0">
            <button type="submit" class="btn">🖼️ Optimizar ahora</button>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
