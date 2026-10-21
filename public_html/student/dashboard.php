<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/announcement_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();

$stmt = $pdo->prepare(
    'SELECT c.id AS course_id, c.name AS course_name
     FROM courses c
     INNER JOIN course_students cs ON cs.course_id = c.id
     WHERE cs.student_id = :student_id AND c.is_active = 1
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
    "SELECT a.id, a.title, a.start_date, COALESCE(ax.due_date, a.due_date) AS due_date, a.points, a.subject_id, sub.status, sub.returned_at
     FROM submissions sub
     INNER JOIN assignments a ON a.id = sub.assignment_id
     LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = :student_id2
     WHERE sub.student_id = :student_id
       AND (a.start_date IS NULL OR a.start_date <= :now)
     ORDER BY COALESCE(ax.due_date, a.due_date) IS NULL, COALESCE(ax.due_date, a.due_date) ASC, a.title ASC"
);
// Las asignaciones cuya fecha/hora de inicio aún no llega NO aparecen (solo las ve el profesor).
$allStmt->execute(['student_id' => $studentId, 'student_id2' => $studentId, 'now' => now_datetime()]);

// Agrupadas por asignatura y estado, listas para renderizar sin más consultas
$assignmentsBySubject = [];
foreach ($allStmt->fetchAll() as $a) {
    if ($a['status'] === 'completed') {
        $bucket = 'completed';
    } elseif (assignment_overdue_for_student($a, $a)) {
        $bucket = 'overdue';   // venció la entrega: se muestra como vencida y ya no se puede entregar
    } else {
        $bucket = 'pending';
    }
    $assignmentsBySubject[$a['subject_id']][$bucket][] = $a;
}

// Cuántos archivos publicó el profesor en cada asignatura (para mostrar el enlace "Archivos")
$filesBySubject = [];
if (!empty($courses)) {
    $fids = array_column($courses, 'course_id');
    $fin = implode(',', array_fill(0, count($fids), '?'));
    $fStmt = $pdo->prepare(
        "SELECT fo.subject_id, COUNT(sf.id) AS files_count
         FROM subject_folders fo
         INNER JOIN subjects s ON s.id = fo.subject_id
         LEFT JOIN subject_files sf ON sf.folder_id = fo.id
         WHERE s.course_id IN ($fin)
         GROUP BY fo.subject_id"
    );
    $fStmt->execute($fids);
    foreach ($fStmt->fetchAll() as $fr) {
        $filesBySubject[(int) $fr['subject_id']] = (int) $fr['files_count'];
    }
}

$announcements = announcements_for_student($pdo, (int) $studentId, 4, 21);

$pageTitle = 'Panel del estudiante';
require __DIR__ . '/../includes/header.php';
?>
<h1>Mi panel</h1>

<p>
    <a class="btn" href="<?= e(rtrim(APP_URL, '/')) ?>/game/join.php">Unirse a un juego con un código</a>
    <a class="btn btn-secondary" href="<?= e(rtrim(APP_URL, '/')) ?>/student/join_course.php">Unirme a una asignatura</a>
    <a class="btn btn-secondary" href="<?= e(rtrim(APP_URL, '/')) ?>/student/calendar.php">📅 Mi calendario</a>
    <a class="btn btn-secondary" href="<?= e(rtrim(APP_URL, '/')) ?>/student/progress.php">📈 Mi progreso</a>
</p>

<?php if (!empty($announcements)): ?>
    <section class="card" style="margin-top:16px; border-left:4px solid #F59E0B; background:#FFFBEB;">
        <h2 style="margin:0 0 8px;">📢 Avisos</h2>
        <?php foreach ($announcements as $an): ?>
            <div style="padding:8px 0; border-top:1px solid #FDE68A;">
                <strong><?= (int) $an['is_pinned'] === 1 ? '📌 ' : '' ?><?= e($an['title']) ?></strong>
                <span class="text-muted" style="font-size:0.8rem;"> · <?= e($an['course_name']) ?> · <?= e(dt_format($an['publish_at'])) ?></span>
                <div style="margin-top:4px;"><?= render_description($an['body'], $an['body_format']) ?></div>
            </div>
        <?php endforeach; ?>
        <p style="margin:8px 0 0;"><a href="<?= e(rtrim(APP_URL, '/')) ?>/student/announcements.php">Ver todos los avisos</a></p>
    </section>
<?php endif; ?>

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
                        $overdue = $assignmentsBySubject[$subj['id']]['overdue'] ?? [];
                        $pendingCount = count($pending);
                        $overdueCount = count($overdue);
                    ?>
                    <details style="margin-bottom:10px;" <?= $pendingCount > 0 ? 'open' : '' ?>>
                        <summary style="cursor:pointer; padding:8px 0; font-weight:600;">
                            <?= e($subj['name']) ?>
                            <?php if ($pendingCount > 0): ?>
                                <span class="text-muted" style="font-weight:400;"> — <?= $pendingCount ?> pendiente<?= $pendingCount === 1 ? '' : 's' ?></span>
                            <?php elseif ($overdueCount > 0): ?>
                                <span style="font-weight:600; color:#B91C1C;"> — <?= $overdueCount ?> vencida<?= $overdueCount === 1 ? '' : 's' ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-weight:400;"> — al día</span>
                            <?php endif; ?>
                        </summary>

                        <div style="padding:8px 4px 4px;">
                            <?php $fileCount = $filesBySubject[(int) $subj['id']] ?? 0; ?>
                            <?php if ($fileCount > 0): ?>
                                <a class="btn btn-secondary" style="margin:0 0 8px; padding:6px 14px; font-size:0.85rem;"
                                   href="materials.php?subject=<?= (int) $subj['id'] ?>">📁 Archivos de la asignatura (<?= $fileCount ?>)</a>
                            <?php endif; ?>
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
                                                · Entrega: <?= e(dt_format($a['due_date'])) ?>
                                            <?php endif; ?>
                                        </p>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (!empty($overdue)): ?>
                                <h4 style="margin:14px 0 8px;">⌛ Vencidas</h4>
                                <?php foreach ($overdue as $a): ?>
                                    <a class="card" href="assignment.php?id=<?= (int) $a['id'] ?>" style="display:block; margin-bottom:6px; padding:10px 14px; border-color:#F8C9C9; background:#FFF8F8;">
                                        <strong><?= e($a['title']) ?></strong>
                                        <span style="display:inline-block; margin-left:6px; padding:1px 8px; border-radius:999px; font-size:0.75rem; font-weight:700; background:#FDECEC; color:#B91C1C; border:1px solid #F8C9C9;">Vencida</span>
                                        <p class="text-muted" style="margin:4px 0 0; font-size:0.85rem;">
                                            <?= (int) $a['points'] ?> pts · Venció el <?= e(dt_format($a['due_date'])) ?> · ya no se puede entregar
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
