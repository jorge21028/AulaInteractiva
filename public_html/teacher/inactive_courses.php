<?php
/**
 * Cursos inactivos del profesor. Los estudiantes no los ven; el profesor conserva todo
 * (asignaturas, asignaciones, notas) y puede reactivarlos o usar su contenido en otros cursos.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/space_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate_course') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $courseId = (int) ($_POST['course_id'] ?? 0);
    if (course_set_active($pdo, $courseId, $teacherId, true)) {
        audit_log($pdo, $teacherId, 'activate_course', "Curso #{$courseId} reactivado");
        redirect('teacher/dashboard.php?reactivated=1');
    }
}

$courses = courses_for_teacher($pdo, $teacherId, false);
$courseStats = space_summarize_by(space_assignment_rows($pdo, $teacherId), 'course_id');

// Cuánto contenido reutilizable tiene cada curso inactivo
$contentCount = [];
foreach ($courses as $c) {
    $q = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM activities act INNER JOIN subjects s ON s.id = act.subject_id WHERE s.course_id = :c1 AND act.teacher_id = :t1) AS activities_count,
                (SELECT COUNT(*) FROM quizzes qz INNER JOIN subjects s ON s.id = qz.subject_id WHERE s.course_id = :c2 AND qz.teacher_id = :t2) AS quizzes_count'
    );
    $q->execute(['c1' => $c['id'], 't1' => $teacherId, 'c2' => $c['id'], 't2' => $teacherId]);
    $contentCount[(int) $c['id']] = $q->fetch();
}

$pageTitle = 'Cursos inactivos';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">
<nav class="crumbs" aria-label="Ruta"><a href="dashboard.php">Mis cursos</a><span class="sep">›</span><strong>Cursos inactivos</strong></nav>
<p style="margin:0 0 8px;"><a href="school_year.php">🗓️ Reabrir o cerrar un año escolar completo</a></p>
<h1>📦 Cursos inactivos</h1>
<p class="space-sub" style="margin-top:-6px;">
    Estos cursos ya no aparecen a los estudiantes. Tú conservas todo y puedes reactivarlos o importar su contenido a otro curso.
</p>

<?php if (!empty($_GET['deactivated'])): ?>
    <div class="alert alert-success">Curso pasado a inactivo. Ya no lo ven los estudiantes.</div>
<?php endif; ?>

<?php if (empty($courses)): ?>
    <div class="empty-box">
        <p style="font-size:1.1rem; margin:0 0 8px;">No tienes cursos inactivos.</p>
        <p class="text-muted" style="margin:0;">Para archivar un curso, entra a él y usa <em>Ajustes → Pasar a inactivo</em>.</p>
    </div>
<?php else: ?>
    <div class="space-grid">
        <?php foreach ($courses as $course):
            $cid = (int) $course['id'];
            $cs = $courseStats[$cid] ?? space_summarize([]);
            $cc = $contentCount[$cid];
        ?>
            <div class="space-tile" style="cursor:default;">
                <div class="space-cover" style="background:linear-gradient(135deg,#64748B 0%,#94A3B8 100%);">
                    <span class="space-icon">📦</span>
                </div>
                <div class="space-body">
                    <h3><?= e($course['name']) ?></h3>
                    <p class="space-meta"><?= !empty($course['school_year']) ? '🗓️ ' . e($course['school_year']) . ' · ' : '' ?>
                        <?= (int) $course['subjects_count'] ?> asignatura<?= (int) $course['subjects_count'] === 1 ? '' : 's' ?> ·
                        <?= (int) $course['students_count'] ?> estudiante<?= (int) $course['students_count'] === 1 ? '' : 's' ?> ·
                        <?= (int) $cs['assignments'] ?> asignación(es)
                    </p>
                    <p class="space-meta">
                        <?= (int) $cc['activities_count'] ?> actividad(es) · <?= (int) $cc['quizzes_count'] ?> cuestionario(s)
                        <?php if (!empty($course['deactivated_at'])): ?><br>Inactivo desde el <?= e(dt_format($course['deactivated_at'])) ?><?php endif; ?>
                    </p>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                        <a class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;" href="course.php?id=<?= $cid ?>">Abrir</a>
                        <a class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;" href="import_content.php?source=<?= $cid ?>">📥 Usar su contenido</a>
                        <form method="post" action="inactive_courses.php" style="margin:0;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="activate_course">
                            <input type="hidden" name="course_id" value="<?= $cid ?>">
                            <button type="submit" class="btn" style="margin:0; padding:6px 12px; font-size:0.85rem;">✅ Reactivar</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
