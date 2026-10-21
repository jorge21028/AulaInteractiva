<?php
/** Todos los avisos vigentes de los cursos del estudiante. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/announcement_helpers.php';

require_role('student');
$pdo = Database::getConnection();
$list = announcements_for_student($pdo, (int) current_user_id(), 100, 90);

$pageTitle = 'Avisos';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mis cursos</a></p>
<h1>📢 Avisos de mis profesores</h1>
<?php if (empty($list)): ?>
    <div class="card"><p class="empty-state">No hay avisos por ahora.</p></div>
<?php else: foreach ($list as $a): ?>
    <article class="card" id="a<?= (int) $a['id'] ?>" style="margin-bottom:10px;">
        <strong><?= (int) $a['is_pinned'] === 1 ? '📌 ' : '' ?><?= e($a['title']) ?></strong>
        <div class="text-muted" style="font-size:0.8rem;"><?= e($a['course_name']) ?> · <?= e($a['teacher_name']) ?> · <?= e(dt_format($a['publish_at'])) ?></div>
        <div style="margin-top:8px;"><?= render_description($a['body'], $a['body_format']) ?></div>
    </article>
<?php endforeach; endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
