<?php
/** Todas las notificaciones del estudiante. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification_helpers.php';

require_role('student');
$pdo = Database::getConnection();
$uid = (int) current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    notifications_mark_read($pdo, $uid, null);
    redirect('student/notifications.php');
}

notifications_sync_student($pdo, $uid, true);
$onlyUnread = !empty($_GET['unread']);
$items = notifications_recent($pdo, $uid, 100, $onlyUnread);
$unread = notifications_unread_count($pdo, $uid);

$pageTitle = 'Notificaciones';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mis cursos</a></p>
<h1>🔔 Notificaciones</h1>
<p style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <a class="btn <?= $onlyUnread ? 'btn-secondary' : '' ?>" style="margin:0; padding:5px 12px;" href="notifications.php">Todas</a>
    <a class="btn <?= $onlyUnread ? '' : 'btn-secondary' ?>" style="margin:0; padding:5px 12px;" href="notifications.php?unread=1">Sin leer (<?= (int) $unread ?>)</a>
    <?php if ($unread > 0): ?>
        <form method="post" style="margin:0;"><?php csrf_field(); ?><button class="btn btn-secondary" style="margin:0; padding:5px 12px;" type="submit">Marcar todas como leídas</button></form>
    <?php endif; ?>
</p>
<?php if (empty($items)): ?>
    <div class="card"><p class="empty-state"><?= $onlyUnread ? 'No tienes notificaciones sin leer.' : 'Todavía no tienes notificaciones. Aquí te avisaremos de nuevas asignaciones, prórrogas, calificaciones y avisos de tus profesores.' ?></p></div>
<?php else: foreach ($items as $n): ?>
    <a class="card" href="<?= e(rtrim(APP_URL, '/') . '/' . ltrim((string) $n['url'], '/')) ?>" style="display:block; margin-bottom:8px; padding:10px 14px; text-decoration:none; color:inherit; <?= (int) $n['is_read'] === 0 ? 'border-left:4px solid #0878F9; background:#EFF6FF;' : '' ?>">
        <?= e(notifications_icon($n['type'])) ?> <strong><?= e($n['title']) ?></strong>
        <?php if ($n['body']): ?><div class="text-muted" style="font-size:0.85rem;"><?= e($n['body']) ?></div><?php endif; ?>
        <div class="text-muted" style="font-size:0.78rem;"><?= e(notifications_time_ago($n['created_at'])) ?></div>
    </a>
<?php endforeach; endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
