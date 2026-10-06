<?php
/**
 * Espacio de una asignatura (estilo Moodle): todas sus asignaciones con su estado
 * (por corregir / pendientes de entrega / realizadas y corregidas).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/space_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$subjectId = (int) ($_GET['id'] ?? 0);

$subject = space_load_subject($pdo, $subjectId, $teacherId);
if (!$subject) {
    http_response_code(404);
    exit('Asignatura no encontrada.');
}

$rows = space_assignment_rows($pdo, $teacherId, null, $subjectId);
$sum = space_summarize($rows);

$counts = [
    'needs'   => $sum['needs_assignments'],
    'pending' => $sum['pending_assignments'],
    'done'    => $sum['done_assignments'],
    'all'     => $sum['assignments'],
];
$defaultTab = $counts['needs'] > 0 ? 'needs' : ($counts['pending'] > 0 ? 'pending' : 'all');

// Material de la asignatura (para llegar rápido a crear/editar)
$matStmt = $pdo->prepare(
    'SELECT (SELECT COUNT(*) FROM activities WHERE subject_id = :s1 AND teacher_id = :t1) AS activities_count,
            (SELECT COUNT(*) FROM quizzes WHERE subject_id = :s2 AND teacher_id = :t2) AS quizzes_count,
            (SELECT COUNT(*) FROM subject_folders WHERE subject_id = :s3) AS folders_count,
            (SELECT COUNT(*) FROM subject_files sf INNER JOIN subject_folders fo ON fo.id = sf.folder_id WHERE fo.subject_id = :s4) AS files_count'
);
$matStmt->execute(['s1' => $subjectId, 't1' => $teacherId, 's2' => $subjectId, 't2' => $teacherId, 's3' => $subjectId, 's4' => $subjectId]);
$material = $matStmt->fetch();

$tabs = [
    'needs'   => ['🔴 Por corregir', 'alert'],
    'pending' => ['⏳ Pendientes de entrega', ''],
    'done'    => ['✅ Realizadas y corregidas', ''],
    'all'     => ['Todas', ''],
];

$pageTitle = $subject['name'] . ' · ' . $subject['course_name'];
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">

<nav class="crumbs" aria-label="Ruta">
    <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
    <a href="course.php?id=<?= (int) $subject['course_id'] ?>"><?= e($subject['course_name']) ?></a><span class="sep">›</span>
    <strong><?= e($subject['name']) ?></strong>
</nav>

<div class="space-head">
    <div>
        <h1><?= e($subject['name']) ?></h1>
        <p class="space-sub"><?= e($subject['course_name']) ?></p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn btn-secondary" href="materials.php?subject=<?= (int) $subjectId ?>">📁 Archivos para estudiantes</a>
        <a class="btn" href="assignments.php?subject=<?= (int) $subjectId ?>">➕ Nueva asignación</a>
    </div>
</div>

<div class="stat-row">
    <div class="stat-pill"><div class="n"><?= (int) $sum['assignments'] ?></div><div class="l">Asignaciones</div></div>
    <div class="stat-pill red"><div class="n"><?= (int) $sum['needs_submissions'] ?></div><div class="l">Trabajos por corregir</div></div>
    <div class="stat-pill amber"><div class="n"><?= (int) $sum['pending_submissions'] ?></div><div class="l">Entregas pendientes</div></div>
    <div class="stat-pill green"><div class="n"><?= (int) $sum['graded_submissions'] ?></div><div class="l">Entregas corregidas / calificadas</div></div>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-box">
        <p style="font-size:1.1rem; margin:0 0 8px;">Aún no hay asignaciones en esta asignatura.</p>
        <a class="btn" href="assignments.php?subject=<?= (int) $subjectId ?>">Crear la primera asignación</a>
    </div>
<?php else: ?>
    <div class="space-tabs" role="tablist">
        <?php foreach ($tabs as $key => [$label, $cls]): ?>
            <button type="button" class="space-tab <?= $key === $defaultTab ? 'active' : '' ?>" data-tab="<?= $key ?>" role="tab">
                <?= e($label) ?> <span class="count <?= ($key === 'needs' && $counts[$key] > 0) ? 'alert' : '' ?>"><?= (int) $counts[$key] ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <p class="text-muted" id="tab-help" style="font-size:0.85rem; margin:8px 0 0;"></p>

    <div class="space-grid" id="asg-grid">
        <?php foreach ($rows as $a):
            [$icon, $kind] = space_assignment_kind($a);
            $cardClass = $a['is_needs'] ? 'needs' : ($a['is_pending'] ? 'pending' : ($a['is_done'] ? 'done' : ''));
            $total = max(1, $a['total']);
        ?>
            <a class="asg-card <?= $cardClass ?>" href="assignment_detail.php?id=<?= (int) $a['id'] ?>"
               data-needs="<?= $a['is_needs'] ? 1 : 0 ?>" data-pending="<?= $a['is_pending'] ? 1 : 0 ?>" data-done="<?= $a['is_done'] ? 1 : 0 ?>">
                <h3><?= e($icon) ?> <?= e($a['title']) ?></h3>
                <p class="asg-kind"><?= e($kind) ?></p>

                <div class="progress" title="<?= (int) $a['graded'] ?> calificadas · <?= (int) $a['needs_grading'] ?> por corregir · <?= (int) $a['pending'] ?> sin entregar">
                    <span class="p-graded" style="width:<?= round($a['graded'] / $total * 100, 1) ?>%"></span>
                    <span class="p-needs" style="width:<?= round($a['needs_grading'] / $total * 100, 1) ?>%"></span>
                </div>

                <div class="chips">
                    <?php if ($a['total'] === 0): ?>
                        <span class="chip chip-gray">Sin estudiantes inscritos</span>
                    <?php else: ?>
                        <?php if ($a['needs_grading'] > 0): ?><span class="chip chip-red">🔴 <?= (int) $a['needs_grading'] ?> por corregir</span><?php endif; ?>
                        <?php if ($a['graded'] > 0): ?><span class="chip chip-green">✅ <?= (int) $a['graded'] ?> calificada<?= $a['graded'] === 1 ? '' : 's' ?></span><?php endif; ?>
                        <?php if ($a['pending'] > 0): ?><span class="chip chip-amber">⏳ <?= (int) $a['pending'] ?> sin entregar</span><?php endif; ?>
                    <?php endif; ?>
                </div>

                <p class="asg-kind" style="margin:0;">
                    <?= (int) $a['completed'] ?> / <?= (int) $a['total'] ?> entregadas · <?= (int) $a['points'] ?> pts
                    <?php if ($a['due_date']): ?>
                        · Entrega: <?= e(date('d/m/Y', strtotime($a['due_date']))) ?>
                        <?php if ($a['is_overdue']): ?><span class="chip chip-red" style="margin-left:4px;">vencida</span><?php endif; ?>
                    <?php endif; ?>
                </p>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="empty-box" id="tab-empty" style="display:none;"></div>

    <script>
    (function () {
        const tabs = document.querySelectorAll('.space-tab');
        const cards = document.querySelectorAll('#asg-grid .asg-card');
        const empty = document.getElementById('tab-empty');
        const help = document.getElementById('tab-help');
        const texts = {
            needs:   'Trabajos de creación entregados que todavía no has revisado.',
            pending: 'Asignaciones en las que algún estudiante aún no entrega.',
            done:    'Todos entregaron y no queda nada por corregir.',
            all:     '',
        };
        const emptyMsgs = {
            needs:   '🎉 No tienes nada por corregir en esta asignatura.',
            pending: '👏 Todos los estudiantes entregaron todas las asignaciones.',
            done:    'Todavía no hay asignaciones completamente realizadas y corregidas.',
            all:     '',
        };
        function show(key) {
            let visible = 0;
            cards.forEach(c => {
                const ok = key === 'all'
                    || (key === 'needs' && c.dataset.needs === '1')
                    || (key === 'pending' && c.dataset.pending === '1')
                    || (key === 'done' && c.dataset.done === '1');
                c.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });
            tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === key));
            help.textContent = texts[key];
            empty.style.display = visible === 0 ? '' : 'none';
            empty.textContent = emptyMsgs[key];
        }
        tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.tab)));
        show(<?= json_encode($defaultTab) ?>);
    })();
    </script>
<?php endif; ?>

<section class="card" style="margin-top:20px;">
    <h2 style="margin-top:0; font-size:1.05rem;">📚 Material de esta asignatura</h2>
    <p class="text-muted" style="margin-top:0;">
        <?= (int) $material['activities_count'] ?> actividad(es) interactiva(s) ·
        <?= (int) $material['quizzes_count'] ?> cuestionario(s) ·
        <?= (int) $material['folders_count'] ?> carpeta(s) con <?= (int) $material['files_count'] ?> archivo(s) para descargar
    </p>
    <a class="btn btn-secondary" href="materials.php?subject=<?= (int) $subjectId ?>">📁 Archivos para estudiantes</a>
    <a class="btn btn-secondary" href="activities.php">Actividades interactivas</a>
    <a class="btn btn-secondary" href="quizzes.php">📝 Cuestionarios</a>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
