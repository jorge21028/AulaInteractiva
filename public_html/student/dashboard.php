<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();

$stmt = $pdo->prepare(
    'SELECT c.id AS course_id, c.name AS course_name
     FROM courses c
     INNER JOIN course_students cs ON cs.course_id = c.id
     WHERE cs.student_id = :student_id
     ORDER BY c.name ASC'
);
$stmt->execute(['student_id' => $studentId]);
$courses = $stmt->fetchAll();

// Asignaturas agrupadas por curso
$subjectsByCourse = [];
if (!empty($courses)) {
    $ids = array_column($courses, 'course_id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtSub = $pdo->prepare("SELECT id, course_id, name FROM subjects WHERE course_id IN ($in) ORDER BY name ASC");
    $stmtSub->execute($ids);
    foreach ($stmtSub->fetchAll() as $subj) {
        $subjectsByCourse[$subj['course_id']][] = $subj;
    }
}

$pageTitle = 'Panel del estudiante';
require __DIR__ . '/../includes/header.php';
?>
<h1>Mis asignaturas</h1>

<?php if (empty($courses)): ?>
    <div class="card empty-state">
        <p>Aún no estás inscrito en ningún curso.</p>
        <p class="text-muted">Pídele a tu profesor que te inscriba usando el correo con el que te registraste.</p>
    </div>
<?php else: ?>
    <?php foreach ($courses as $course): ?>
        <section class="card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?= e($course['course_name']) ?></h2>
            <?php $subjects = $subjectsByCourse[$course['course_id']] ?? []; ?>
            <?php if (empty($subjects)): ?>
                <p class="empty-state" style="padding:12px 0;">Este curso todavía no tiene asignaturas.</p>
            <?php else: ?>
                <div class="grid grid-3">
                    <?php foreach ($subjects as $subj): ?>
                        <div class="card">
                            <strong><?= e($subj['name']) ?></strong>
                            <p class="text-muted" style="margin-bottom:0; font-size:0.85rem;">
                                Actividades pendientes: próximamente (Fase 2)
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
