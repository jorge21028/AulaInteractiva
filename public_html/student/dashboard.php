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

// Todas las asignaciones de este estudiante, con su asignatura
$allStmt = $pdo->prepare(
    "SELECT a.id, a.title, a.due_date, a.points, a.subject_id, sub.status, sub.returned_at
     FROM submissions sub
     INNER JOIN assignments a ON a.id = sub.assignment_id
     WHERE sub.student_id = :student_id
     ORDER BY a.due_date IS NULL, a.due_date ASC, a.title ASC"
);
$allStmt->execute(['student_id' => $studentId]);

// Agrupadas por asignatura y estado, listas para renderizar sin más consultas
$assignmentsBySubject = [];
foreach ($allStmt->fetchAll() as $a) {
    $bucket = $a['status'] === 'completed' ? 'completed' : 'pending';
    $assignmentsBySubject[$a['subject_id']][$bucket][] = $a;
}

$pageTitle = 'Panel del estudiante';
require __DIR__ . '/../includes/header.php';
?>
<h1>Mi panel</h1>

<p>
    <a class="btn" href="<?= e(rtrim(APP_URL, '/')) ?>/game/join.php">Unirse a un juego con un código</a>
    <a class="btn btn-secondary" href="<?= e(rtrim(APP_URL, '/')) ?>/student/join_course.php">Unirme a una asignatura</a>
</p>

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
                <?php foreach ($subjects as $subj): ?>
                    <?php
                        $pending = $assignmentsBySubject[$subj['id']]['pending'] ?? [];
                        $completed = $assignmentsBySubject[$subj['id']]['completed'] ?? [];
                        $pendingCount = count($pending);
                    ?>
                    <details style="margin-bottom:10px;" <?= $pendingCount > 0 ? 'open' : '' ?>>
                        <summary style="cursor:pointer; padding:8px 0; font-weight:600;">
                            <?= e($subj['name']) ?>
                            <?php if ($pendingCount > 0): ?>
                                <span class="text-muted" style="font-weight:400;"> — <?= $pendingCount ?> pendiente<?= $pendingCount === 1 ? '' : 's' ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-weight:400;"> — al día</span>
                            <?php endif; ?>
                        </summary>

                        <div style="padding:8px 4px 4px;">
                            <h4 style="margin-bottom:8px;">⏳ Pendientes</h4>
                            <?php if (empty($pending)): ?>
                                <p class="empty-state" style="padding:8px 0; font-size:0.85rem;">Sin actividades pendientes en esta asignatura.</p>
                            <?php else: ?>
                                <?php foreach ($pending as $a): ?>
                                    <a class="card" href="assignment.php?id=<?= (int) $a['id'] ?>" style="display:block; margin-bottom:6px; padding:10px 14px;">
                                        <strong><?= e($a['title']) ?></strong>
                                        <?php if (!empty($a['returned_at'])): ?>
                                            <span style="display:inline-block; margin-left:6px; padding:1px 8px; border-radius:999px; font-size:0.75rem; font-weight:700; background:#FDECEC; color:#B91C1C; border:1px solid #F8C9C9;">↩️ Devuelta para corregir</span>
                                        <?php endif; ?>
                                        <p class="text-muted" style="margin:4px 0 0; font-size:0.85rem;">
                                            <?= (int) $a['points'] ?> pts
                                            <?php if ($a['due_date']): ?>
                                                · Entrega: <?= e(date('d/m/Y', strtotime($a['due_date']))) ?>
                                            <?php endif; ?>
                                        </p>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <h4 style="margin:14px 0 8px;">✅ Realizadas</h4>
                            <?php if (empty($completed)): ?>
                                <p class="empty-state" style="padding:8px 0; font-size:0.85rem;">Todavía no completaste ninguna en esta asignatura.</p>
                            <?php else: ?>
                                <?php foreach ($completed as $a): ?>
                                    <a class="card" href="assignment.php?id=<?= (int) $a['id'] ?>" style="display:block; margin-bottom:6px; padding:10px 14px;">
                                        <strong><?= e($a['title']) ?></strong>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
