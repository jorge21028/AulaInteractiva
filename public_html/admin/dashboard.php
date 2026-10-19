<?php
/** Panel del súper administrador: números globales y todos los profesores. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';

admin_require();
$pdo = Database::getConnection();
$adminId = (int) current_user_id();
$notice = '';
$tempPassword = null;
$tempFor = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $userId = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $target = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id AND role = 'teacher'");
    $target->execute(['id' => $userId]);
    $t = $target->fetch();

    if ($t && in_array($action, ['deactivate', 'activate'], true)) {
        admin_set_user_active($pdo, $userId, $action === 'activate');
        audit_log($pdo, $adminId, 'admin_' . $action . '_user', "Profesor #{$userId} ({$t['email']})");
        $notice = 'Cuenta de ' . $t['name'] . ($action === 'activate' ? ' activada.' : ' desactivada: ya no puede iniciar sesión.');
    } elseif ($t && $action === 'reset_password') {
        $tempPassword = admin_reset_password($pdo, $userId);
        $tempFor = $t['name'] . ' (' . $t['email'] . ')';
        audit_log($pdo, $adminId, 'admin_reset_password', "Profesor #{$userId} ({$t['email']})");
    }
}

$stats = admin_stats($pdo);
$teachers = admin_teachers($pdo);

$pageTitle = 'Súper administrador';
$adminActive = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, "/")) ?>/assets/css/spaces.css">
<h1>🛡️ Súper administrador</h1>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>

<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($tempPassword): ?>
    <div class="alert alert-success">
        🔑 Contraseña temporal para <strong><?= e($tempFor) ?></strong>:
        <code style="font-size:1.1rem; user-select:all;"><?= e($tempPassword) ?></code><br>
        <span style="font-size:0.85rem;">Cópiala ahora: no se vuelve a mostrar. La persona tendrá que cambiarla al iniciar sesión.</span>
    </div>
<?php endif; ?>

<section class="grid grid-3" style="margin-bottom:20px;">
    <div class="card"><div class="stat-number"><?= (int) $stats['teachers'] ?></div><div class="stat-label">Profesores</div></div>
    <div class="card"><div class="stat-number"><?= (int) $stats['students'] ?></div><div class="stat-label">Estudiantes</div></div>
    <div class="card"><div class="stat-number"><?= (int) $stats['courses_active'] ?></div><div class="stat-label">Cursos activos (<?= (int) $stats['courses_inactive'] ?> inactivos)</div></div>
    <div class="card"><div class="stat-number"><?= (int) $stats['activities'] + (int) $stats['quizzes'] ?></div><div class="stat-label">Actividades y cuestionarios</div></div>
    <div class="card"><div class="stat-number"><?= (int) $stats['assignments'] ?></div><div class="stat-label">Asignaciones</div></div>
    <div class="card"><div class="stat-number"><?= (int) $stats['submissions_done'] ?></div><div class="stat-label">Entregas realizadas</div></div>
    <div class="card"><div class="stat-number" style="font-size:1.6rem;"><?= e(admin_format_bytes((int) $stats['upload_bytes'])) ?></div><div class="stat-label">Imágenes subidas</div></div>
</section>

<section class="card">
    <h2 style="margin-top:0;">Profesores</h2>
    <p class="text-muted" style="margin-top:-6px; font-size:0.9rem;">
        "Entrar como profesor" te muestra el sistema exactamente como lo ve él y puedes crear, editar y eliminar todo lo suyo.
        Arriba verás un aviso para volver a tu cuenta. Todo queda en el registro.
    </p>
    <?php if (empty($teachers)): ?>
        <p class="empty-state">Todavía no hay profesores registrados.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead><tr style="text-align:left; border-bottom:2px solid var(--color-border);">
                <th style="padding:6px;">Profesor</th><th>Cursos</th><th>Est.</th><th>Act./Cuest.</th><th>Asig.</th><th>Último acceso</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($teachers as $t): ?>
                <tr style="border-bottom:1px solid var(--color-border); <?= (int) $t['is_active'] === 1 ? '' : 'opacity:.6;' ?>">
                    <td style="padding:8px 6px;">
                        <strong><?= e($t['name']) ?></strong><?= (int) $t['is_active'] === 1 ? '' : ' <span class="chip chip-red">Desactivado</span>' ?><br>
                        <span class="text-muted" style="font-size:0.8rem;"><?= e($t['email']) ?></span>
                    </td>
                    <td><?= (int) $t['courses_active'] ?><?= (int) $t['courses_inactive'] > 0 ? ' <span class="text-muted">(+' . (int) $t['courses_inactive'] . ' inact.)</span>' : '' ?></td>
                    <td><?= (int) $t['students_count'] ?></td>
                    <td><?= (int) $t['activities_count'] ?> / <?= (int) $t['quizzes_count'] ?></td>
                    <td><?= (int) $t['assignments_count'] ?></td>
                    <td><?= $t['last_login'] ? e(dt_format($t['last_login'])) : '<span class="text-muted">nunca</span>' ?></td>
                    <td style="white-space:nowrap;">
                        <form method="post" action="impersonate.php" style="display:inline;">
                            <?php csrf_field(); ?><input type="hidden" name="teacher_id" value="<?= (int) $t['id'] ?>">
                            <button class="btn" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit">👁️ Entrar como profesor</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Restablecer la contraseña de este profesor? Se generará una temporal.')">
                            <?php csrf_field(); ?><input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="action" value="reset_password">
                            <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit">🔑</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('<?= (int) $t['is_active'] === 1 ? '¿Desactivar esta cuenta? No podrá iniciar sesión.' : '¿Activar esta cuenta?' ?>')">
                            <?php csrf_field(); ?><input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="action" value="<?= (int) $t['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                            <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit"><?= (int) $t['is_active'] === 1 ? '⛔' : '✅' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="text-muted" style="font-size:0.8rem;">🔑 restablecer contraseña · ⛔ desactivar cuenta / ✅ activar.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
