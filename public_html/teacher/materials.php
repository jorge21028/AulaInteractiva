<?php
/**
 * Archivos de una asignatura (carpetas por subida): el profesor sube archivos para que los estudiantes los descarguen.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/space_helpers.php';
require_once __DIR__ . '/../includes/materials_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$subjectId = (int) ($_GET['subject'] ?? 0);

$subject = space_load_subject($pdo, $subjectId, $teacherId);
if (!$subject) {
    http_response_code(404);
    exit('Asignatura no encontrada.');
}

$errors = [];
$notice = null;

/** Carpeta de esta asignatura (cualquier profesor del curso puede administrarla). */
function load_folder(PDO $pdo, int $folderId, int $subjectId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM subject_folders WHERE id = :id AND subject_id = :sid');
    $stmt->execute(['id' => $folderId, 'sid' => $subjectId]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si el envío supera post_max_size, PHP descarta todo (también el token CSRF): se avisa con claridad.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Los archivos son demasiado pesados para enviarlos juntos. Sube menos archivos a la vez o más livianos.';
    } else {
        csrf_verify($_POST['csrf_token'] ?? null);
        $action = $_POST['action'] ?? '';

        if ($action === 'create_folder') {
            $title = mb_substr(clean_string($_POST['title'] ?? ''), 0, 150);
            $description = mb_substr(clean_string($_POST['description'] ?? ''), 0, 500);
            $uploads = materials_collect_uploads($_FILES['files'] ?? []);

            if (empty($uploads)) {
                $errors[] = 'Selecciona al menos un archivo para subir.';
            } else {
                if ($title === '') {
                    $title = 'Archivos del ' . date('d/m/Y');
                }
                $pdo->prepare(
                    'INSERT INTO subject_folders (subject_id, teacher_id, title, description, created_at) VALUES (:sid, :tid, :title, :desc, :now)'
                )->execute(['sid' => $subjectId, 'tid' => $teacherId, 'title' => $title, 'desc' => $description !== '' ? $description : null, 'now' => now_datetime()]);
                $folderId = (int) $pdo->lastInsertId();

                $res = materials_store_files($pdo, $folderId, $uploads);
                $errors = array_merge($errors, $res['errors']);

                if ($res['saved'] === 0) {
                    // Nada se pudo guardar: no se deja una carpeta vacía
                    $pdo->prepare('DELETE FROM subject_folders WHERE id = :id')->execute(['id' => $folderId]);
                } else {
                    $notice = 'Carpeta «' . $title . '» creada con ' . $res['saved'] . ' archivo' . ($res['saved'] === 1 ? '' : 's') . '. Tus estudiantes ya pueden descargarlos.';
                    audit_log($pdo, $teacherId, 'materials_upload', "Carpeta #{$folderId} en asignatura #{$subjectId}");
                }
            }
        } elseif ($action === 'add_files') {
            $folder = load_folder($pdo, (int) ($_POST['folder_id'] ?? 0), $subjectId);
            $uploads = materials_collect_uploads($_FILES['files'] ?? []);
            if (!$folder) {
                $errors[] = 'Carpeta no encontrada.';
            } elseif (empty($uploads)) {
                $errors[] = 'Selecciona al menos un archivo para agregar.';
            } else {
                $res = materials_store_files($pdo, (int) $folder['id'], $uploads);
                $errors = array_merge($errors, $res['errors']);
                if ($res['saved'] > 0) {
                    $pdo->prepare('UPDATE subject_folders SET updated_at = :now WHERE id = :id')->execute(['now' => now_datetime(), 'id' => $folder['id']]);
                    $notice = $res['saved'] . ' archivo' . ($res['saved'] === 1 ? '' : 's') . ' agregado' . ($res['saved'] === 1 ? '' : 's') . ' a «' . $folder['title'] . '».';
                }
            }
        } elseif ($action === 'rename_folder') {
            $folder = load_folder($pdo, (int) ($_POST['folder_id'] ?? 0), $subjectId);
            $title = mb_substr(clean_string($_POST['title'] ?? ''), 0, 150);
            if (!$folder) {
                $errors[] = 'Carpeta no encontrada.';
            } elseif ($title === '') {
                $errors[] = 'El nombre de la carpeta no puede estar vacío.';
            } else {
                $description = mb_substr(clean_string($_POST['description'] ?? ''), 0, 500);
                $pdo->prepare('UPDATE subject_folders SET title = :t, description = :d, updated_at = :now WHERE id = :id')
                    ->execute(['t' => $title, 'd' => $description !== '' ? $description : null, 'now' => now_datetime(), 'id' => $folder['id']]);
                $notice = 'Carpeta actualizada.';
            }
        } elseif ($action === 'delete_file') {
            $folder = load_folder($pdo, (int) ($_POST['folder_id'] ?? 0), $subjectId);
            if ($folder && materials_delete_file($pdo, (int) ($_POST['file_id'] ?? 0), (int) $folder['id'])) {
                $notice = 'Archivo eliminado.';
            } else {
                $errors[] = 'Archivo no encontrado.';
            }
        } elseif ($action === 'delete_folder') {
            $folder = load_folder($pdo, (int) ($_POST['folder_id'] ?? 0), $subjectId);
            if ($folder) {
                materials_delete_folder($pdo, (int) $folder['id']);
                audit_log($pdo, $teacherId, 'materials_delete_folder', "Carpeta #{$folder['id']} de asignatura #{$subjectId}");
                $notice = 'Carpeta «' . $folder['title'] . '» eliminada con todos sus archivos.';
            } else {
                $errors[] = 'Carpeta no encontrada.';
            }
        }
    }
}

$folders = materials_folders_for_subject($pdo, $subjectId);
$totalFiles = array_sum(array_map(fn($f) => count($f['files']), $folders));
$maxMb = (int) (MATERIAL_MAX_BYTES / 1048576);
$acceptList = '.' . implode(',.', MATERIAL_ALLOWED_EXT);

$pageTitle = 'Archivos · ' . $subject['name'];
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">
<style>
  .folder { background:var(--color-surface); border:1px solid var(--color-border); border-left:5px solid var(--color-primary); border-radius:var(--radius); box-shadow:var(--shadow); margin-bottom:14px; }
  .folder > summary { cursor:pointer; padding:14px 16px; list-style:none; display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; }
  .folder > summary::-webkit-details-marker { display:none; }
  .folder-body { padding:0 16px 16px; }
  .file-row { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; padding:8px 0; border-top:1px solid var(--color-border); }
  .file-row a.dl { font-weight:600; word-break:break-word; }
</style>

<nav class="crumbs" aria-label="Ruta">
    <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
    <a href="course.php?id=<?= (int) $subject['course_id'] ?>"><?= e($subject['course_name']) ?></a><span class="sep">›</span>
    <a href="subject.php?id=<?= (int) $subjectId ?>"><?= e($subject['name']) ?></a><span class="sep">›</span>
    <strong>Archivos</strong>
</nav>

<div class="space-head">
    <div>
        <h1>📁 Archivos para estudiantes</h1>
        <p class="space-sub"><?= e($subject['course_name']) ?> — <?= e($subject['name']) ?> · <?= count($folders) ?> carpeta<?= count($folders) === 1 ? '' : 's' ?> · <?= (int) $totalFiles ?> archivo<?= (int) $totalFiles === 1 ? '' : 's' ?></p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<section class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">⬆️ Subir archivos</h2>
    <p class="text-muted" style="margin-top:-6px;">Cada subida crea una carpeta que verán todos los estudiantes del curso para descargar.</p>
    <form method="post" action="materials.php?subject=<?= (int) $subjectId ?>" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_folder">

        <label for="title">Nombre de la carpeta</label>
        <input type="text" id="title" name="title" maxlength="150" placeholder="Ej: Guías del primer periodo">

        <label for="description">Descripción (opcional)</label>
        <input type="text" id="description" name="description" maxlength="500" placeholder="Ej: Léanlas antes de la próxima clase">

        <label for="files">Archivos (hasta <?= (int) MATERIAL_MAX_FILES_PER_UPLOAD ?>, máx. <?= $maxMb ?> MB cada uno)</label>
        <input type="file" id="files" name="files[]" multiple required accept="<?= e($acceptList) ?>">
        <p class="text-muted" style="font-size:0.8rem; margin:4px 0 0;">
            Permitidos: PDF, Word, Excel, PowerPoint, OpenDocument, texto, imágenes, audio, video y ZIP.
            Si tu hosting limita el tamaño de subida, los archivos muy pesados serán rechazados con un aviso.
        </p>
        <button type="submit" class="btn">Subir y publicar</button>
    </form>
</section>

<?php if (empty($folders)): ?>
    <div class="empty-box">Todavía no has subido archivos a esta asignatura.</div>
<?php else: ?>
    <?php foreach ($folders as $i => $folder): ?>
        <details class="folder" <?= $i === 0 ? 'open' : '' ?>>
            <summary>
                <span>
                    <strong style="font-size:1.05rem;">📁 <?= e($folder['title']) ?></strong>
                    <span class="text-muted" style="font-size:0.85rem;">
                        · <?= count($folder['files']) ?> archivo<?= count($folder['files']) === 1 ? '' : 's' ?>
                        · <?= e(materials_format_size((int) $folder['total_size'])) ?>
                        · <?= e(date('d/m/Y', strtotime($folder['created_at']))) ?>
                    </span>
                </span>
            </summary>
            <div class="folder-body">
                <?php if (!empty($folder['description'])): ?>
                    <p class="text-muted" style="margin-top:0;"><?= e($folder['description']) ?></p>
                <?php endif; ?>

                <?php foreach ($folder['files'] as $file): ?>
                    <div class="file-row">
                        <a class="dl" href="<?= e(rtrim(APP_URL, '/')) ?>/api/materials/download.php?id=<?= (int) $file['id'] ?>">
                            <?= e(materials_file_icon($file['original_name'])) ?> <?= e($file['original_name']) ?>
                            <span class="text-muted" style="font-weight:400; font-size:0.8rem;">(<?= e(materials_format_size((int) $file['size_bytes'])) ?>)</span>
                        </a>
                        <form method="post" action="materials.php?subject=<?= (int) $subjectId ?>"
                              onsubmit="return confirm('¿Eliminar «<?= e(addslashes($file['original_name'])) ?>»? Los estudiantes ya no podrán descargarlo.')">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_file">
                            <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                            <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:3px 10px; font-size:0.78rem; color:#C0392B; border-color:#C0392B;">Quitar</button>
                        </form>
                    </div>
                <?php endforeach; ?>

                <div style="display:flex; gap:16px; flex-wrap:wrap; margin-top:14px; padding-top:12px; border-top:1px solid var(--color-border);">
                    <form method="post" action="materials.php?subject=<?= (int) $subjectId ?>" enctype="multipart/form-data" style="flex:1 1 260px;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="add_files">
                        <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                        <label style="margin-top:0;">Agregar más archivos a esta carpeta</label>
                        <input type="file" name="files[]" multiple required accept="<?= e($acceptList) ?>">
                        <button type="submit" class="btn btn-secondary" style="margin-top:6px;">Agregar</button>
                    </form>
                    <form method="post" action="materials.php?subject=<?= (int) $subjectId ?>" style="flex:1 1 260px;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="rename_folder">
                        <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                        <label style="margin-top:0;">Nombre y descripción</label>
                        <input type="text" name="title" value="<?= e($folder['title']) ?>" maxlength="150" required>
                        <input type="text" name="description" value="<?= e($folder['description'] ?? '') ?>" maxlength="500" placeholder="Descripción (opcional)">
                        <button type="submit" class="btn btn-secondary" style="margin-top:6px;">Guardar</button>
                    </form>
                </div>
                <form method="post" action="materials.php?subject=<?= (int) $subjectId ?>" style="margin-top:12px;"
                      onsubmit="return confirm('¿Eliminar la carpeta «<?= e(addslashes($folder['title'])) ?>» con TODOS sus archivos? Esta acción no se puede deshacer.')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_folder">
                    <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                    <button type="submit" class="btn btn-secondary" style="margin:0; color:#C0392B; border-color:#C0392B;">🗑️ Eliminar carpeta completa</button>
                </form>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
