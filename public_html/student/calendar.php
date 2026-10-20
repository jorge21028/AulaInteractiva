<?php
/** Calendario del estudiante: mes + línea de tiempo de lo que tiene pendiente. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/calendar_helpers.php';

require_role('student');
$pdo = Database::getConnection();
$studentId = (int) current_user_id();

[$year, $month] = calendar_parse_month($_GET['m'] ?? null);
$events = calendar_student_events($pdo, $studentId);
$timeline = calendar_timeline($events, false);

$pageTitle = 'Mi calendario';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mis cursos</a></p>
<h1>📅 Mi calendario</h1>

<section class="card">
    <h2 style="margin-top:0;">Lo que viene</h2>
    <?= calendar_render_timeline($timeline, '🎉 No tienes entregas pendientes con fecha.') ?>
</section>

<section class="card" style="margin-top:16px;">
    <?= calendar_render_month($events, $year, $month, 'calendar.php') ?>
    <?= calendar_legend(false) ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
