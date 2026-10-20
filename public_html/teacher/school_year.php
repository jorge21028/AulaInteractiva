<?php
/**
 * Año escolar: cerrar (pasar a inactivos) o reabrir todos los cursos de un año de una sola vez.
 * Los estudiantes dejan de ver los cursos cerrados; tú conservas todo y puedes reabrirlos.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schoolyear_helpers.php';

require_role('teacher');
$pdo = Database::getConnection();
$teacherId = (int) current_user_id();
$notice = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $year = (string) ($_POST['year'] ?? '');
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['close', 'reopen'], true)) {
        $errors[] = 'Acción no válida.';
    } elseif (($_POST['confirm'] ?? '') !== '1') {
        $errors[] = 'Marca la casilla para confirmar.';
    } else {
        $n = school_year_set_active($pdo, $teacherId, $year, $action === 'reopen');
        audit_log($pdo, $teacherId, 'school_year_' . $action, "Año escolar '" . ($year ?: 'sin año') . "': {$n} curso(s)");
        $notice = $action === 'close'
            ? "Año escolar " . ($year ?: 'sin año') . " cerrado: {$n} curso(s) pasaron a inactivos y los estudiantes ya no los ven."
            : "Año escolar " . ($year ?: 'sin año') . " reabierto: {$n} curso(s) vuelven a estar activos.";
    }
}

$summary = school_year_summary($pdo, $teacherId);
$current = school_year_current();

$pageTitle = 'Año escolar';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mis cursos</a></p>
<h1>🗓️ Año escolar</h1>
<p class="text-muted" style="margin-top:-8px;">
    Cada curso pertenece a un año escolar. Al terminar el año, ciérralo aquí y todos sus cursos pasan a <a href="inactive_courses.php">Cursos inactivos</a>
    de una sola vez (los estudiantes dejan de verlos; tú conservas todo y puedes reabrirlo). El año escolar actual es <strong><?= e($current) ?></strong>.
</p>

<?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>

<?php if (empty($summary)): ?>
    <div class="card"><p class="empty-state">Todavía no tienes cursos.</p></div>
<?php else: ?>
    <?php foreach ($summary as $row):
        $y = (string) $row['school_year'];
        $active = school_year_courses($pdo, $teacherId, $y, true);
        $inactive = school_year_courses($pdo, $teacherId, $y, false);
    ?>
        <section class="card" style="margin-bottom:14px;">
            <h2 style="margin-top:0;"><?= $y !== '' ? e($y) : 'Sin año escolar' ?><?= $y === $current ? ' <span style="font-size:0.8rem; color:#0658C2;">(actual)</span>' : '' ?></h2>
            <p class="text-muted" style="margin-top:-6px;">
                <?= (int) $row['active_count'] ?> activo(s) · <?= (int) $row['inactive_count'] ?> inactivo(s)
            </p>
            <?php if ($active): ?>
                <p style="margin:0 0 6px;"><strong>Activos:</strong> <?= e(implode(', ', array_column($active, 'name'))) ?></p>
                <form method="post" onsubmit="return confirm('¿Cerrar el año escolar <?= e($y ?: 'sin año') ?>? Sus <?= count($active) ?> curso(s) pasarán a inactivos.')" style="margin:0 0 10px;">
                    <?php csrf_field(); ?><input type="hidden" name="year" value="<?= e($y) ?>"><input type="hidden" name="action" value="close">
                    <label style="display:flex; gap:8px; align-items:center; font-weight:400; margin:0 0 6px;"><input type="checkbox" name="confirm" value="1" style="width:auto;"> Confirmo que quiero cerrar este año</label>
                    <button class="btn" type="submit" style="margin:0;">📦 Cerrar año escolar (<?= count($active) ?> curso<?= count($active) === 1 ? '' : 's' ?>)</button>
                </form>
            <?php endif; ?>
            <?php if ($inactive): ?>
                <p style="margin:0 0 6px;"><strong>Inactivos:</strong> <?= e(implode(', ', array_column($inactive, 'name'))) ?></p>
                <form method="post" onsubmit="return confirm('¿Reabrir el año escolar <?= e($y ?: 'sin año') ?>?')" style="margin:0;">
                    <?php csrf_field(); ?><input type="hidden" name="year" value="<?= e($y) ?>"><input type="hidden" name="action" value="reopen">
                    <input type="hidden" name="confirm" value="1">
                    <button class="btn btn-secondary" type="submit" style="margin:0;">✅ Reabrir año (<?= count($inactive) ?> curso<?= count($inactive) === 1 ? '' : 's' ?>)</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <p class="text-muted" style="font-size:0.85rem;">Para cambiar el año de un curso, entra a él → Ajustes → Año escolar.</p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
