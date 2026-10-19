<?php
/** Todos los cursos de todos los profesores. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';

admin_require();
$pdo = Database::getConnection();
$adminId = (int) current_user_id();
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['activate', 'deactivate'], true) && admin_set_course_active($pdo, $courseId, $action === 'activate')) {
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
