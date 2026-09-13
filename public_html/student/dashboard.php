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

// Actividades pendientes (todas las asignaciones de este estudiante)
$pendingStmt = $pdo->prepare(
    "SELECT a.id, a.title, a.due_date, a.points, s.name AS subject_name, sub.status
     FROM submissions sub
     INNER JOIN assignments a ON a.id = sub.assignment_id
     INNER JOIN subjects s ON s.id = a.subject_id
     WHERE sub.student_id = :student_id
     ORDER BY (sub.status = 'pending') DESC, a.due_date IS NULL, a.due_date ASC"
);
$pendingStmt->execute(['student_id' => $studentId]);
$assignments = $pendingStmt->fetchAll();

// Contar pendientes por asignatura, para las tarjetas de abajo
$pendingCountBySubject = [];
foreach ($assignments as $a) {
    if ($a['status'] === 'pending') {
        $pendingCountBySubject[$a['subject_name']] = ($pendingCountBySubject[$a['subject_name']] ?? 0) + 1;
    }
}

$pageTitle = 'Panel del estudiante';
require __DIR__ . '/../includes/header.php';
?>
<h1>Mi panel</h1>

<p><a class="btn" href="<?= e(rtrim(APP_URL, '/')) ?>/game/join.php">🎮 Unirse a un juego con un código</a></p>

<section class="card">
    <h2 style="margin-top:0;">Actividades pendientes</h2>
    <?php $pending = array_filter($assignments, fn($a) => $a['status'] === 'pending'); ?>
    <?php if (empty($pending)): ?>
        <p class="empty-state">No tienes actividades pendientes por ahora.</p>
    <?php else: ?>
        <?php foreach ($pending as $a): ?>
            <a class="card" href="assignment.php?id=<?= (int) $a['id'] ?>" style="display:block; margin-bottom:8px; padding:12px 16px;">
                <strong><?= e($a['title']) ?></strong>
                <span class="text-muted"> — <?= e($a['subject_name']) ?></span>
                <p class="text-muted" style="margin:4px 0 0; font-size:0.85rem;">
                    <?= (int) $a['points'] ?> pts
                    <?php if ($a['due_date']): ?>
                        · Entrega: <?= e(date('d/m/Y', strtotime($a['due_date']))) ?>
                    <?php endif; ?>
                </p>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<h2 style="margin-top:24px;">Mis asignaturas</h2>

<?php if (empty($courses)): ?>
    <div class="card empty-state">
        <p>Aún no estás inscrito en ningún curso.</p>
        <p class="text-muted">Pídele a tu profesor que te inscriba usando el correo con el que te registraste.</p>
    </div>
<?php else: ?>
    <?php foreach ($courses as $course): ?>
        <section class="card" style="margin-bottom:16px;">
            <h3 style="margin-top:0;"><?= e($course['course_name']) ?></h3>
            <?php $subjects = $subjectsByCourse[$course['course_id']] ?? []; ?>
            <?php if (empty($subjects)): ?>
                <p class="empty-state" style="padding:12px 0;">Este curso todavía no tiene asignaturas.</p>
            <?php else: ?>
                <div class="grid grid-3">
                    <?php foreach ($subjects as $subj): ?>
                        <div class="card">
                            <strong><?= e($subj['name']) ?></strong>
                            <p class="text-muted" style="margin-bottom:0; font-size:0.85rem;">
                                <?= (int) ($pendingCountBySubject[$subj['name']] ?? 0) ?> actividad(es) pendiente(s)
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($assignments)): ?>
<section class="card">
    <h2 style="margin-top:0;">Historial de calificaciones</h2>
    <?php $graded = array_filter($assignments, fn($a) => $a['status'] === 'completed'); ?>
    <?php if (empty($graded)): ?>
        <p class="empty-state">Todavía no tienes actividades completadas.</p>
    <?php else: ?>
        <?php foreach ($graded as $a): ?>
            <a class="card" href="assignment.php?id=<?= (int) $a['id'] ?>" style="display:block; margin-bottom:8px; padding:10px 16px;">
                <strong><?= e($a['title']) ?></strong> — <?= e($a['subject_name']) ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
