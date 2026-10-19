<?php
/** Todos los estudiantes: búsqueda, activar/desactivar y restablecer contraseña. */
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
$q = clean_string($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $userId = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $st = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id AND role = 'student'");
    $st->execute(['id' => $userId]);
    $s = $st->fetch();
    if ($s && in_array($action, ['deactivate', 'activate'], true)) {
        admin_set_user_active($pdo, $userId, $action === 'activate');
        audit_log($pdo, $adminId, 'admin_' . $action . '_user', "Estudiante #{$userId} ({$s['email']})");
        $notice = 'Cuenta de ' . $s['name'] . ($action === 'activate' ? ' activada.' : ' desactivada.');
    } elseif ($s && $action === 'reset_password') {
        $tempPassword = admin_reset_password($pdo, $userId);
        $tempFor = $s['name'] . ' (' . $s['email'] . ')';
        audit_log($pdo, $adminId, 'admin_reset_password', "Estudiante #{$userId} ({$s['email']})");
    }
}

$students = admin_students($pdo, $q);

$pageTitle = 'Estudiantes';
$adminActive = 'students';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">
<h1>🎓 Estudiantes</h1>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($tempPassword): ?>
    <div class="alert alert-success">🔑 Contraseña temporal para <strong><?= e($tempFor) ?></strong>:
        <code style="font-size:1.1rem; user-select:all;"><?= e($tempPassword) ?></code><br>
        <span style="font-size:0.85rem;">Cópiala ahora: no se vuelve a mostrar. Tendrá que cambiarla al iniciar sesión.</span></div>
<?php endif; ?>

<section class="card">
    <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre o correo" style="flex:1; min-width:200px; margin:0;">
        <button class="btn" type="submit" style="margin:0;">Buscar</button>
    </form>
    <?php if (empty($students)): ?>
        <p class="empty-state">No se encontraron estudiantes.</p>
    <?php else: ?>
        <p class="text-muted" style="font-size:0.85rem; margin-top:0;"><?= count($students) ?> estudiante(s)<?= count($students) >= 300 ? ' (mostrando los primeros 300; usa la búsqueda)' : '' ?>.</p>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead><tr style="text-align:left; border-bottom:2px solid var(--color-border);"><th style="padding:6px;">Estudiante</th><th>Cursos</th><th>Último acceso</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr style="border-bottom:1px solid var(--color-border); <?= (int) $s['is_active'] === 1 ? '' : 'opacity:.6;' ?>">
                    <td style="padding:8px 6px;"><strong><?= e($s['name']) ?></strong><?= (int) $s['is_active'] === 1 ? '' : ' <span class="chip chip-red">Desactivado</span>' ?><br>
                        <span class="text-muted" style="font-size:0.8rem;"><?= e($s['email']) ?></span></td>
                    <td><?= $s['courses'] ? e($s['courses']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $s['last_login'] ? e(dt_format($s['last_login'])) : '<span class="text-muted">nunca</span>' ?></td>
                    <td style="white-space:nowrap;">
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Restablecer la contraseña de este estudiante?')">
                            <?php csrf_field(); ?><input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="action" value="reset_password">
                            <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit">🔑</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('<?= (int) $s['is_active'] === 1 ? '¿Desactivar esta cuenta?' : '¿Activar esta cuenta?' ?>')">
                            <?php csrf_field(); ?><input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="action" value="<?= (int) $s['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                            <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit"><?= (int) $s['is_active'] === 1 ? '⛔' : '✅' ?></button>
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
