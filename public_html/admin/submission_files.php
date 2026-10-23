<?php
/**
 * SÚPER ADMINISTRADOR: archivos que los estudiantes entregaron (PDF, Word, Excel...) en las asignaciones de TODOS los
 * profesores. Permite ver cuánto espacio ocupan y borrarlos (por asignación completa o archivo por archivo) para
 * liberar espacio en el hosting. Las entregas y las calificaciones se conservan.
 * ?assignment=N preselecciona una asignación; ?older=30|60|90 muestra solo archivos con más de esos días.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/project_files_helpers.php';

admin_require();
@set_time_limit(120);

$pdo = Database::getConnection();
$adminId = (int) current_user_id();
$errors = [];
$done = null;
$preselect = (int) ($_GET['assignment'] ?? 0);
$older = (int) ($_GET['older'] ?? 0);
if (!in_array($older, [0, 30, 60, 90, 180], true)) {
    $older = 0;
}
$tableReady = project_files_table_exists($pdo);

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $assignmentIds = array_map('intval', (array) ($_POST['assignment_ids'] ?? []));
    $fileIds = array_map('intval', (array) ($_POST['file_ids'] ?? []));

    if (empty($assignmentIds) && empty($fileIds)) {
        $errors[] = 'Marca al menos una asignación o un archivo.';
    } elseif (($_POST['confirm'] ?? '') !== '1') {
        $errors[] = 'Marca la casilla para confirmar que quieres borrar los archivos.';
    } else {
        try {
            $rows = [];
            foreach ($assignmentIds as $aid) {
                foreach (project_files_staff_select($pdo, 'assignment', $aid, 'admin', $adminId) as $r) {
                    // Con el filtro de antigüedad, "toda la asignación" significa solo los archivos que se están mostrando
                    if ($older > 0 && strtotime((string) $r['created_at']) >= time() - $older * 86400) {
                        continue;
                    }
                    $rows[(int) $r['id']] = $r;
                }
            }
            foreach (project_files_staff_select($pdo, 'ids', $fileIds, 'admin', $adminId) as $r) {
                $rows[(int) $r['id']] = $r;
            }
            $done = project_files_staff_delete($pdo, array_values($rows), $adminId, 'admin');
            audit_log($pdo, $adminId, 'project_files_delete', 'Archivos de entregas borrados: ' . $done['files'] . ' archivo(s), ' . materials_format_size($done['bytes']));
        } catch (Throwable $e) {
            error_log('submission_files: ' . $e->getMessage());
            $errors[] = 'No fue posible borrar los archivos. Intenta de nuevo.';
        }
    }
}

$overview = $tableReady ? project_files_admin_overview($pdo, $older) : [];
$filesByAssignment = [];
if ($overview) {
    foreach (project_files_admin_files($pdo, array_column($overview, 'assignment_id'), $older) as $f) {
        $filesByAssignment[(int) $f['assignment_id']][] = $f;
    }
}
$totalFiles = array_sum(array_column($overview, 'file_count'));
$totalBytes = array_sum(array_column($overview, 'total_bytes'));

$pageTitle = 'Archivos de entregas';
$adminActive = 'files';
require __DIR__ . '/../includes/header.php';
?>
<h1>📎 Archivos de entregas</h1>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<p class="text-muted" style="margin-top:-6px;">
    Archivos (PDF, Word, Excel...) que los estudiantes entregaron en las asignaciones de <strong>todos los profesores</strong>.
    Al borrarlos se libera espacio en el hosting; las entregas y las calificaciones se conservan y el estudiante verá que el archivo fue eliminado.
    <strong>No se puede deshacer.</strong>
</p>

<?php if (!$tableReady): ?>
    <div class="alert alert-error">Falta crear la tabla <code>project_files</code>. Ejecuta <code>schema_fase21_archivos.sql</code> en phpMyAdmin.</div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($done): ?>
    <div class="alert <?= $done['files'] > 0 ? 'alert-success' : 'alert-error' ?>">
        <?php if ($done['files'] > 0): ?>
            ✅ Listo: se borraron <strong><?= (int) $done['files'] ?></strong> archivo(s) y se liberaron <strong><?= e(materials_format_size((int) $done['bytes'])) ?></strong>.
        <?php else: ?>
            No se pudo borrar ningún archivo del servidor.
        <?php endif; ?>
        <?= $done['failed'] > 0 ? ' (' . (int) $done['failed'] . ' no se pudieron borrar)' : '' ?>
    </div>
<?php endif; ?>

<?php if ($tableReady): ?>
<form method="get" action="submission_files.php" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
    <label for="older" style="margin:0;">Mostrar archivos subidos hace más de</label>
    <select id="older" name="older" onchange="this.form.submit()" style="width:auto;">
        <?php foreach ([0 => 'cualquier tiempo', 30 => '30 días', 60 => '60 días', 90 => '90 días', 180 => '180 días'] as $v => $label): ?>
            <option value="<?= $v ?>" <?= $older === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (empty($overview)): ?>
    <div class="card"><p class="empty-state">No hay archivos de entregas guardados<?= $older ? ' con esa antigüedad' : '' ?>.</p></div>
<?php else: ?>
    <section class="card">
        <p><strong><?= (int) $totalFiles ?></strong> archivo(s) en <?= count($overview) ?> asignación(es) · <strong><?= e(materials_format_size((int) $totalBytes)) ?></strong>.</p>
        <form method="post" action="submission_files.php?older=<?= (int) $older ?>" onsubmit="return confirm('¿Borrar los archivos marcados? No se puede deshacer.')">
            <?php csrf_field(); ?>
            <p style="margin:0 0 6px;"><a href="#" id="toggle-all">Marcar / desmarcar todas las asignaciones</a></p>
            <?php foreach ($overview as $r): $aid = (int) $r['assignment_id']; ?>
                <div style="border-bottom:1px solid var(--color-border); padding:8px 0;">
                    <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
                        <input type="checkbox" name="assignment_ids[]" value="<?= $aid ?>" class="as-check" style="width:auto;" <?= $aid === $preselect ? 'checked' : '' ?>>
                        <span>
                            <?= e($r['title']) ?>
                            <span class="text-muted" style="font-weight:400; font-size:0.85rem;">
                                · <?= e($r['teacher_name']) ?> · <?= e($r['course_name']) ?> — <?= e($r['subject_name']) ?> ·
                                <?= (int) $r['file_count'] ?> archivo(s) · <?= e(materials_format_size((int) $r['total_bytes'])) ?>
                            </span>
                        </span>
                    </label>
                    <details style="margin:4px 0 0 26px;">
                        <summary class="text-muted" style="cursor:pointer; font-size:0.85rem;">Ver / elegir archivos uno por uno</summary>
                        <?php foreach ($filesByAssignment[$aid] ?? [] as $f): ?>
                            <label style="display:flex; gap:8px; align-items:center; font-weight:400; font-size:0.9rem; padding:3px 0;">
                                <input type="checkbox" name="file_ids[]" value="<?= (int) $f['id'] ?>" style="width:auto;">
                                <span>
                                    <?= materials_file_icon($f['original_name']) ?>
                                    <a href="../api/submissions/download.php?id=<?= (int) $f['id'] ?>"><?= e($f['original_name']) ?></a>
                                    <span class="text-muted">· <?= e($f['student_name']) ?> · <?= e(materials_format_size((int) $f['size_bytes'])) ?> · <?= e(date('d/m/Y', strtotime($f['created_at']))) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </details>
                </div>
            <?php endforeach; ?>

            <label style="display:flex; gap:8px; align-items:center; font-weight:600; margin-top:12px;">
                <input type="checkbox" name="confirm" value="1" style="width:auto;"> Entiendo que los archivos se borrarán definitivamente
            </label>
            <button type="submit" class="btn" style="background:#B91C1C; border-color:#B91C1C;">🗑️ Borrar lo marcado</button>
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
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
