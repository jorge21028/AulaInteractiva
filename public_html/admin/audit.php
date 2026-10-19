<?php
/** Registro de actividad del sistema (audit_log). */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';

admin_require();
$pdo = Database::getConnection();
$action = clean_string($_GET['action'] ?? '');
$q = clean_string($_GET['q'] ?? '');
$rows = admin_audit($pdo, $action, $q);
$actions = array_column($pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(), 'action');

$pageTitle = 'Registro de actividad';
$adminActive = 'audit';
require __DIR__ . '/../includes/header.php';
?>
<h1>🧾 Registro de actividad</h1>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<section class="card">
    <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
        <select name="action" style="margin:0;">
            <option value="">Todas las acciones</option>
            <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $a === $action ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
        </select>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Usuario o detalle" style="flex:1; min-width:180px; margin:0;">
        <button class="btn" type="submit" style="margin:0;">Filtrar</button>
    </form>
    <p class="text-muted" style="font-size:0.85rem; margin-top:0;">Últimos <?= count($rows) ?> registros. Las acciones hechas por un administrador como profesor llevan <code>[admin #id]</code>.</p>
    <div style="overflow-x:auto;">
    <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
        <thead><tr style="text-align:left; border-bottom:2px solid var(--color-border);"><th style="padding:6px;">Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr style="border-bottom:1px solid var(--color-border);">
                <td style="padding:6px; white-space:nowrap;"><?= e(dt_format($r['created_at'])) ?></td>
                <td><?= $r['user_name'] ? e($r['user_name']) . ' <span class="text-muted">(' . e($r['user_role']) . ')</span>' : '<span class="text-muted">—</span>' ?></td>
                <td><code><?= e($r['action']) ?></code></td>
                <td><?= e((string) $r['details']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
