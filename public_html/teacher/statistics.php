<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();

// ---- Totales generales ----
$stmt = $pdo->prepare('SELECT COUNT(*) AS t FROM teacher_courses WHERE teacher_id = :id');
$stmt->execute(['id' => $teacherId]);
$courseCount = (int) $stmt->fetch()['t'];

$stmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT s.id) AS t FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE tc.teacher_id = :id'
);
$stmt->execute(['id' => $teacherId]);
$subjectCount = (int) $stmt->fetch()['t'];

$stmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT cs.student_id) AS t FROM course_students cs
     INNER JOIN teacher_courses tc ON tc.course_id = cs.course_id
     WHERE tc.teacher_id = :id'
);
$stmt->execute(['id' => $teacherId]);
$studentCount = (int) $stmt->fetch()['t'];

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS t, SUM(status = 'published') AS published FROM activities WHERE teacher_id = :id"
);
$stmt->execute(['id' => $teacherId]);
$actRow = $stmt->fetch();
$activityCount = (int) $actRow['t'];
$publishedCount = (int) $actRow['published'];

$stmt = $pdo->prepare("SELECT COUNT(*) AS t FROM games WHERE teacher_id = :id AND status = 'finished'");
$stmt->execute(['id' => $teacherId]);
$gamesFinished = (int) $stmt->fetch()['t'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS t FROM assignments WHERE teacher_id = :id');
$stmt->execute(['id' => $teacherId]);
$assignmentCount = (int) $stmt->fetch()['t'];

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total, SUM(sub.status = 'completed') AS completed
     FROM submissions sub
     INNER JOIN assignments a ON a.id = sub.assignment_id
     WHERE a.teacher_id = :id"
);
$stmt->execute(['id' => $teacherId]);
$subRow = $stmt->fetch();
$totalSubmissions = (int) ($subRow['total'] ?? 0);
$completedSubmissions = (int) ($subRow['completed'] ?? 0);
$completionRate = $totalSubmissions > 0 ? round($completedSubmissions / $totalSubmissions * 100) : 0;

// ---- Desglose por asignatura: promedio de calificación (en %) ----
$stmt = $pdo->prepare(
    "SELECT s.name AS subject_name,
        COUNT(sub.id) AS total_subs,
        SUM(sub.status = 'completed') AS completed_subs,
        AVG(CASE WHEN sub.score IS NOT NULL AND a.points > 0 THEN sub.score / a.points * 100 END) AS avg_pct
     FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     LEFT JOIN assignments a ON a.subject_id = s.id AND a.teacher_id = tc.teacher_id
     LEFT JOIN submissions sub ON sub.assignment_id = a.id
     WHERE tc.teacher_id = :id
     GROUP BY s.id, s.name
     ORDER BY s.name"
);
$stmt->execute(['id' => $teacherId]);
$bySubject = $stmt->fetchAll();

$pageTitle = 'Estadísticas';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver al panel</a></p>
<h1>Estadísticas</h1>

<section class="grid grid-3">
    <div class="card"><div class="stat-number"><?= $courseCount ?></div><div class="stat-label">Cursos</div></div>
    <div class="card"><div class="stat-number"><?= $subjectCount ?></div><div class="stat-label">Asignaturas</div></div>
    <div class="card"><div class="stat-number"><?= $studentCount ?></div><div class="stat-label">Estudiantes</div></div>
    <div class="card"><div class="stat-number"><?= $activityCount ?></div><div class="stat-label">Actividades (<?= $publishedCount ?> publicadas)</div></div>
    <div class="card"><div class="stat-number"><?= $gamesFinished ?></div><div class="stat-label">Partidas realizadas</div></div>
    <div class="card"><div class="stat-number"><?= $assignmentCount ?></div><div class="stat-label">Asignaciones creadas</div></div>
</section>

<section class="card" style="margin-top:16px;">
    <h2 style="margin-top:0;">Entregas</h2>
    <p><?= $completedSubmissions ?> de <?= $totalSubmissions ?> entregas completadas (<?= $completionRate ?>%)</p>
    <div style="background:var(--color-border); border-radius:6px; overflow:hidden; height:14px;">
        <div style="background:var(--color-success); width:<?= $completionRate ?>%; height:100%;"></div>
    </div>
</section>

<section class="card" style="margin-top:16px;">
    <h2 style="margin-top:0;">Promedio por asignatura</h2>
    <?php if (empty($bySubject)): ?>
        <p class="empty-state">Sin datos todavía.</p>
    <?php else: ?>
        <?php foreach ($bySubject as $s): ?>
            <?php $pct = $s['avg_pct'] !== null ? round((float) $s['avg_pct']) : null; ?>
            <div style="margin-bottom:14px;">
                <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:4px;">
                    <strong><?= e($s['subject_name']) ?></strong>
                    <span class="text-muted">
                        <?= $pct !== null ? $pct . '%' : 'Sin calificaciones' ?>
                        · <?= (int) $s['completed_subs'] ?> / <?= (int) $s['total_subs'] ?> entregas
                    </span>
                </div>
                <div style="background:var(--color-border); border-radius:6px; overflow:hidden; height:10px;">
                    <div style="background:var(--color-primary); width:<?= $pct ?? 0 ?>%; height:100%;"></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
