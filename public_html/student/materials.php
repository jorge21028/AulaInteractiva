<?php
/**
 * Archivos de una asignatura: lo que el profesor subió para descargar.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/materials_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();
$subjectId = (int) ($_GET['subject'] ?? 0);

$subject = materials_subject_for_student($pdo, $subjectId, $studentId);
if (!$subject) {
    http_response_code(404);
    exit('Asignatura no encontrada.');
}

$folders = materials_folders_for_subject($pdo, $subjectId);

$pageTitle = 'Archivos · ' . $subject['name'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  .folder { background:var(--color-surface); border:1px solid var(--color-border); border-left:5px solid var(--color-primary); border-radius:var(--radius); box-shadow:var(--shadow); margin-bottom:14px; }
  .folder > summary { cursor:pointer; padding:14px 16px; list-style:none; }
  .folder > summary::-webkit-details-marker { display:none; }
  .folder-body { padding:0 16px 14px; }
  .file-row { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; padding:10px 0; border-top:1px solid var(--color-border); }
</style>
<p><a href="dashboard.php">&larr; Volver a mi panel</a></p>
<h1>📁 Archivos de <?= e($subject['name']) ?></h1>
<p class="text-muted" style="margin-top:-8px;"><?= e($subject['course_name']) ?> · material que subió tu profesor</p>

<?php if (empty($folders)): ?>
    <div class="card" style="text-align:center;"><p class="text-muted">Tu profesor todavía no ha subido archivos a esta asignatura.</p></div>
<?php else: ?>
    <?php foreach ($folders as $i => $folder): ?>
        <details class="folder" <?= $i === 0 ? 'open' : '' ?>>
            <summary>
                <strong style="font-size:1.05rem;">📁 <?= e($folder['title']) ?></strong>
                <span class="text-muted" style="font-size:0.85rem;">
                    · <?= count($folder['files']) ?> archivo<?= count($folder['files']) === 1 ? '' : 's' ?>
                    · <?= e(date('d/m/Y', strtotime($folder['created_at']))) ?>
                </span>
            </summary>
            <div class="folder-body">
                <?php if (!empty($folder['description'])): ?>
                    <p class="text-muted" style="margin-top:0;"><?= e($folder['description']) ?></p>
                <?php endif; ?>
                <?php foreach ($folder['files'] as $file): ?>
                    <div class="file-row">
                        <span style="word-break:break-word;">
                            <?= e(materials_file_icon($file['original_name'])) ?> <strong><?= e($file['original_name']) ?></strong>
                            <span class="text-muted" style="font-size:0.8rem;">(<?= e(materials_format_size((int) $file['size_bytes'])) ?>)</span>
                        </span>
                        <a class="btn" style="margin:0; padding:6px 14px; font-size:0.85rem;"
                           href="<?= e(rtrim(APP_URL, '/')) ?>/api/materials/download.php?id=<?= (int) $file['id'] ?>">⬇️ Descargar</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
