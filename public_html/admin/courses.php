<?php
/** Todos los cursos de todos los profesores. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/schoolyear_helpers.php';

admin_require();
$pdo = Database::getConnection();
$adminId = (int) current_user_id();
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['close_year', 'reopen_year'], true)) {
        $year = (string) ($_POST['year'] ?? '');
        $n = school_year_set_active($pdo, null, $year, $action === 'reopen_year');
        audit_log($pdo, $adminId, 'admin_school_year_' . ($action === 'close_year' ? 'close' : 'reopen'), "Año escolar '" . ($year ?: 'sin año') . "': {$n} curso(s) de todos los profesores");
        $notice = $action === 'close_year' ? "Año {$year}: {$n} curso(s) pasaron a inactivos." : "Año {$year}: {$n} curso(s) reactivados.";
    } elseif (in_array($action, ['activate', 'deactivate'], true) && admin_set_course_active($pdo, $courseId, $action === 'activate')) {
        audit_log($pdo, $adminId, 'admin_' . $action . '_course', "Curso #{$courseId}");
        $notice = $action === 'activate' ? 'Curso activado.' : 'Curso desactivado: los estudiantes ya no lo ven.';
    }
}

$courses = admin_courses($pdo);

$pageTitle = 'Cursos';
$adminActive = 'courses';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">
<h1>📚 Todos los cursos</h1>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>

<?php
$yearStats = [];
foreach ($courses as $c) {
    $y = (string) ($c['school_year'] ?? '');
    $yearStats[$y] = $yearStats[$y] ?? ['a' => 0, 'i' => 0];
    $yearStats[$y][(int) $c['is_active'] === 1 ? 'a' : 'i']++;
}
krsort($yearStats);
?>
<?php if ($yearStats): ?>
<section class="card" style="margin-bottom:16px;">
    <h3 style="margin-top:0;">🗓️ Años escolares (todos los profesores)</h3>
    <?php foreach ($yearStats as $y => $st): ?>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; padding:6px 0; border-bottom:1px solid var(--color-border);">
            <strong style="min-width:110px;"><?= $y !== '' ? e($y) : 'Sin año' ?></strong>
            <span class="text-muted"><?= (int) $st['a'] ?> activo(s) · <?= (int) $st['i'] ?> inactivo(s)</span>
            <?php if ($st['a'] > 0): ?>
                <form method="post" style="margin:0;" onsubmit="return confirm('¿Cerrar el año <?= e($y ?: 'sin año') ?> en TODOS los profesores (<?= (int) $st['a'] ?> curso/s)?')">
                    <?php csrf_field(); ?><input type="hidden" name="year" value="<?= e($y) ?>"><input type="hidden" name="action" value="close_year">
                    <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit">📦 Cerrar año</button>
                </form>
            <?php endif; ?>
            <?php if ($st['i'] > 0): ?>
                <form method="post" style="margin:0;" onsubmit="return confirm('¿Reabrir el año <?= e($y ?: 'sin año') ?> en todos los profesores?')">
                    <?php csrf_field(); ?><input type="hidden" name="year" value="<?= e($y) ?>"><input type="hidden" name="action" value="reopen_year">
                    <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit">✅ Reabrir año</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
    <?php if (empty($courses)): ?>
        <p class="empty-state">No hay cursos.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead><tr style="text-align:left; border-bottom:2px solid var(--color-border);">
                <th style="padding:6px;">Curso</th><th>Profesor(es)</th><th>Asig.</th><th>Est.</th><th>Tareas</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($courses as $c):
                $owners = [];
                foreach (array_filter(explode('||', (string) $c['teachers'])) as $pair) {
                    [$tid, $tname] = array_pad(explode(':', $pair, 2), 2, '');
                    $owners[] = ['id' => (int) $tid, 'name' => $tname];
                }
            ?>
                <tr style="border-bottom:1px solid var(--color-border); <?= (int) $c['is_active'] === 1 ? '' : 'opacity:.65;' ?>">
                    <td style="padding:8px 6px;"><strong><?= e($c['name']) ?></strong>
                        <?= (int) $c['is_active'] === 1 ? '' : ' <span class="chip chip-red">Inactivo</span>' ?><br>
                        <?= !empty($c['school_year']) ? '<span class="text-muted" style="font-size:0.8rem;">🗓️ ' . e($c['school_year']) . '</span><br>' : '' ?>
                        <span class="text-muted" style="font-size:0.8rem;">Código: <?= e($c['enrollment_code']) ?></span></td>
                    <td><?= $owners ? e(implode(', ', array_column($owners, 'name'))) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= (int) $c['subjects_count'] ?></td>
                    <td><?= (int) $c['students_count'] ?></td>
                    <td><?= (int) $c['assignments_count'] ?></td>
                    <td style="white-space:nowrap;">
                        <?php foreach (array_slice($owners, 0, 2) as $o): ?>
                            <form method="post" action="impersonate.php" style="display:inline;">
                                <?php csrf_field(); ?><input type="hidden" name="teacher_id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                <button class="btn" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit" title="Abrir este curso como <?= e($o['name']) ?>">👁️ Abrir<?= count($owners) > 1 ? ' como ' . e(explode(' ', $o['name'])[0]) : '' ?></button>
                            </form>
                        <?php endforeach; ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('<?= (int) $c['is_active'] === 1 ? '¿Pasar este curso a inactivo?' : '¿Reactivar este curso?' ?>')">
                            <?php csrf_field(); ?><input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="action" value="<?= (int) $c['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                            <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit"><?= (int) $c['is_active'] === 1 ? '📦 Inactivar' : '✅ Activar' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
