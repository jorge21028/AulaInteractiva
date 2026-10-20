<?php
/** Calendario del profesor: fechas de entrega (con avance de entregas) y publicaciones programadas. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/calendar_helpers.php';

require_role('teacher');
$pdo = Database::getConnection();
$teacherId = (int) current_user_id();

[$year, $month] = calendar_parse_month($_GET['m'] ?? null);
$events = calendar_teacher_events($pdo, $teacherId);
$timeline = calendar_timeline($events, true, 30);

$pageTitle = 'Calendario';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mis cursos</a></p>
<h1>📅 Calendario de entregas</h1>

<section class="card">
    <h2 style="margin-top:0;">Próximos 30 días</h2>
    <?= calendar_render_timeline($timeline, 'No tienes entregas ni publicaciones programadas en los próximos días.') ?>
</section>

<section class="card" style="margin-top:16px;">
    <?= calendar_render_month($events, $year, $month, 'calendar.php') ?>
    <?= calendar_legend(true) ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
