<?php
/**
 * Diagnóstico y reparación de la visualización de imágenes subidas.
 * Síntoma típico: la imagen "se sube" pero solo se ve el icono de imagen rota.
 * Causas habituales en hostings gratuitos: (1) el .htaccess de uploads/ usa directivas que el hosting no permite
 * y devuelve error 500 en todos los archivos; (2) los archivos subidos quedan con permisos 0600 y el servidor web
 * no puede leerlos.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$notice = '';
$errors = [];

$uploadsDir = rtrim(UPLOAD_DIR, '/');
$htaccess = $uploadsDir . '/.htaccess';
const SAFE_HTACCESS = <<<'HT'
# Evita que se ejecute cualquier script dentro de la carpeta de subidas.
# (Solo usa directivas que los hostings compartidos permiten; no usar "Options" ni "AddHandler" aquí:
#  si el hosting no las permite, TODAS las imágenes de esta carpeta dejan de verse.)
<FilesMatch "\.(php|php3|php4|php5|php7|php8|phtml|phar|pl|py|cgi|sh)$">
    Require all denied
</FilesMatch>
HT;

/** Recorre carpetas de uploads (sin materials) aplicando una función a cada archivo/carpeta. */
function diag_walk(string $dir, callable $fn): void
{
    foreach ((array) scandir($dir) as $item) {
        if ($item === '.' || $item === '..' || $item === 'materials') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $fn($path, true);
            diag_walk($path, $fn);
        } elseif ($item !== '.htaccess') {
            $fn($path, false);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'fix_perms') {
        $files = 0;
        diag_walk($uploadsDir, function ($path, $isDir) use (&$files) {
            if (@chmod($path, $isDir ? 0755 : 0644) && !$isDir) {
                $files++;
            }
        });
        @chmod($uploadsDir, 0755);
        $notice = "Permisos reparados en {$files} archivo(s).";
    } elseif ($action === 'fix_htaccess' || $action === 'remove_htaccess') {
        if (is_file($htaccess)) {
            @copy($htaccess, $uploadsDir . '/htaccess.respaldo.txt');
        }
        if ($action === 'fix_htaccess') {
            $ok = @file_put_contents($htaccess, SAFE_HTACCESS . "\n") !== false;
            $notice = $ok ? 'Se aplicó el .htaccess corregido (el anterior quedó guardado como htaccess.respaldo.txt).' : '';
        } else {
            $ok = !is_file($htaccess) || @unlink($htaccess);
            $notice = $ok ? 'Se quitó el .htaccess de uploads (respaldo: htaccess.respaldo.txt). La seguridad de las subidas la garantiza el código.' : '';
        }
        if (!$ok) {
            $errors[] = 'El servidor no me deja modificar ese archivo. Hazlo desde el Administrador de archivos del hosting: htdocs/uploads/.htaccess.';
        }
    }
}

// Datos del servidor
$gd = function_exists('imagecreatetruecolor');
$htContent = is_file($htaccess) ? (string) file_get_contents($htaccess) : null;
$riskyHt = $htContent !== null && preg_match('/^\s*(Options|AddHandler|php_flag|php_value)\b/mi', $htContent);

$latest = $pdo->query("SELECT stored_name, created_at FROM uploads WHERE kind = 'image' ORDER BY id DESC LIMIT 5")->fetchAll();
$rows = [];
$badPerms = 0;
foreach ($latest as $u) {
    $p = $uploadsDir . '/images/' . $u['stored_name'];
    $exists = is_file($p);
    $perm = $exists ? substr(sprintf('%o', fileperms($p)), -4) : null;
    if ($exists && (fileperms($p) & 0004) === 0) {
        $badPerms++;
    }
    $rows[] = ['name' => $u['stored_name'], 'exists' => $exists, 'perm' => $perm, 'size' => $exists ? filesize($p) : 0, 'at' => $u['created_at']];
}
$sample = $rows[0]['name'] ?? null;
$base = (parse_url(APP_URL, PHP_URL_PATH) ?: '');

$pageTitle = 'Diagnóstico de imágenes';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver</a></p>
<h1>🔧 Diagnóstico de imágenes</h1>
<p class="text-muted" style="margin-top:-8px;">
    Úsalo cuando las imágenes se suben pero solo aparece el icono de imagen rota. Comprueba cómo responde tu servidor
    y te propone la reparación.
</p>

<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?> Recarga esta página para volver a probar.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>

<section class="card">
    <h2 style="margin-top:0;">1. Prueba en tu navegador</h2>
    <?php if ($sample): ?>
        <p class="text-muted" style="font-size:0.9rem;">Se prueba la última imagen subida (<code><?= e($sample) ?></code>) de dos maneras:</p>
        <div style="display:flex; gap:16px; flex-wrap:wrap;">
            <div><strong>A. Dirección normal</strong> (<code>/uploads/images/…</code>)<br>
                <img id="imgA" src="<?= e($base . '/uploads/images/' . $sample) ?>" alt="A" style="max-width:200px; max-height:140px; border:1px solid #ccc; margin-top:6px;">
                <p id="resA" class="text-muted" style="font-size:0.85rem;">Probando…</p></div>
            <div><strong>B. Leída por PHP</strong><br>
                <img id="imgB" src="<?= e($base . '/api/media/image.php?f=' . rawurlencode($sample)) ?>" alt="B" style="max-width:200px; max-height:140px; border:1px solid #ccc; margin-top:6px;">
                <p id="resB" class="text-muted" style="font-size:0.85rem;">Probando…</p></div>
        </div>
        <div id="verdict" class="alert" style="margin-top:12px; display:none;"></div>
        <script>
        (async function () {
            const urlA = <?= json_encode($base . '/uploads/images/' . $sample) ?>;
            const urlB = <?= json_encode($base . '/api/media/image.php?f=' . rawurlencode($sample)) ?>;
            async function probe(url, el) {
                try {
                    const r = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                    const type = r.headers.get('content-type') || '';
                    el.textContent = 'Respuesta: ' + r.status + ' · ' + (type || 'sin tipo');
                    return { status: r.status, image: r.ok && type.indexOf('image/') === 0 };
                } catch (e) { el.textContent = 'No se pudo conectar'; return { status: 0, image: false }; }
            }
            const a = await probe(urlA, document.getElementById('resA'));
            const b = await probe(urlB, document.getElementById('resB'));
            const v = document.getElementById('verdict'); v.style.display = 'block';
            let msg, cls = 'alert-error';
            if (a.image) { msg = '✅ Las imágenes se sirven bien por la dirección normal. Si un estudiante aún no las ve, pídele que recargue la página (Ctrl+F5) y vuelva a subirla.'; cls = 'alert-success'; }
            else if (b.image && a.status === 500) { msg = '❌ El archivo está bien, pero el servidor devuelve ERROR 500 en la dirección normal: casi seguro el <code>.htaccess</code> de la carpeta uploads usa directivas que tu hosting no permite. Usa "Aplicar .htaccess corregido" (abajo).'; }
            else if (b.image && a.status === 403) { msg = '❌ El archivo está bien pero el servidor lo prohíbe (403): permisos del archivo o regla en <code>.htaccess</code>. Usa "Reparar permisos" y, si sigue igual, "Aplicar .htaccess corregido".'; }
            else if (b.image && a.status === 404) { msg = '❌ El archivo existe pero la dirección normal da 404. Revisa que <code>uploads/</code> esté dentro de <code>htdocs/</code> (la misma carpeta que <code>index.php</code>).'; }
            else if (b.image) { msg = '❌ El archivo está bien pero la dirección normal no entrega una imagen (' + a.status + '). Prueba "Reparar permisos" y "Aplicar .htaccess corregido".'; }
            else { msg = '❌ Ni siquiera PHP puede leer la imagen. Puede que el archivo no esté o esté dañado: vuelve a subirla.'; }
            v.className = 'alert ' + cls; v.innerHTML = msg;
        })();
        </script>
    <?php else: ?>
        <p class="text-muted">Aún no hay imágenes subidas para probar. Sube una desde un trabajo de estudiante y vuelve aquí.</p>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:16px;">
    <h2 style="margin-top:0;">2. Estado del servidor</h2>
    <ul style="padding-left:20px; margin:0;">
        <li>Extensión GD (reducir imágenes): <strong><?= $gd ? 'sí' : 'no' ?></strong></li>
        <li>Carpeta <code>uploads/images</code>: <strong><?= is_dir($uploadsDir . '/images') ? (is_writable($uploadsDir . '/images') ? 'existe y se puede escribir' : 'existe pero NO se puede escribir') : 'no existe' ?></strong></li>
        <li><code>uploads/.htaccess</code>: <strong><?= $htContent === null ? 'no existe' : ($riskyHt ? 'existe y usa directivas problemáticas (Options/AddHandler)' : 'existe') ?></strong></li>
        <li>Permisos de las últimas imágenes:
            <?php if (empty($rows)): ?>—<?php else: ?>
                <?php foreach ($rows as $r): ?><code><?= $r['exists'] ? e($r['perm']) : 'falta' ?></code> <?php endforeach; ?>
                <?= $badPerms > 0 ? '<strong style="color:#B91C1C;">(algunas no son legibles por el servidor web)</strong>' : '' ?>
            <?php endif; ?>
        </li>
    </ul>
</section>

<section class="card" style="margin-top:16px;">
    <h2 style="margin-top:0;">3. Reparar</h2>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <form method="post" style="margin:0;"><?php csrf_field(); ?><input type="hidden" name="action" value="fix_htaccess">
            <button class="btn" type="submit">🛠️ Aplicar .htaccess corregido</button></form>
        <form method="post" style="margin:0;"><?php csrf_field(); ?><input type="hidden" name="action" value="fix_perms">
            <button class="btn btn-secondary" type="submit">🔓 Reparar permisos</button></form>
        <form method="post" style="margin:0;" onsubmit="return confirm('¿Quitar el .htaccess de uploads? Solo hazlo si el corregido tampoco funcionó.')"><?php csrf_field(); ?><input type="hidden" name="action" value="remove_htaccess">
            <button class="btn btn-secondary" type="submit">Quitar .htaccess de uploads</button></form>
    </div>
    <p class="text-muted" style="font-size:0.85rem;">
        Empieza por "Aplicar .htaccess corregido", recarga esta página y mira si el veredicto de arriba cambia a ✅.
        "Quitar .htaccess" es el último recurso: las subidas siguen protegidas porque el sistema solo guarda imágenes validadas con nombre aleatorio.
    </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
