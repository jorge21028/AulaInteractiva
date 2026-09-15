<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assignment_helpers.php';
require_once __DIR__ . '/../includes/project_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$errors = [];

// Asignaturas del profesor
$subjStmt = $pdo->prepare(
    'SELECT s.id, s.name, c.name AS course_name
     FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE tc.teacher_id = :teacher_id
     ORDER BY c.name, s.name'
);
$subjStmt->execute(['teacher_id' => $teacherId]);
$subjects = $subjStmt->fetchAll();

// Actividades publicadas del profesor (solo esas se pueden asignar)
$actStmt = $pdo->prepare(
    "SELECT id, title, subject_id FROM activities WHERE teacher_id = :teacher_id AND status = 'published' ORDER BY title"
);
$actStmt->execute(['teacher_id' => $teacherId]);
$activities = $actStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_assignment') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $mode = clean_string($_POST['mode'] ?? 'interactive'); // 'interactive' | 'creation'
    $title = clean_string($_POST['title'] ?? '');
    $description = clean_string($_POST['description'] ?? '');
    $startDate = clean_string($_POST['start_date'] ?? '');
    $dueDate = clean_string($_POST['due_date'] ?? '');
    $points = max(1, (int) ($_POST['points'] ?? 100));

    $activityId = null;
    $projectType = null;
    $subjectId = null;

    if ($mode === 'interactive') {
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $activity = null;
        foreach ($activities as $a) {
            if ((int) $a['id'] === $activityId) {
                $activity = $a;
                break;
            }
        }
        if (!$activity) {
            $errors[] = 'Selecciona una actividad publicada válida.';
        } else {
            $subjectId = (int) $activity['subject_id'];
        }
    } else {
        $projectType = clean_string($_POST['project_type'] ?? '');
        $subjectId = (int) ($_POST['subject_id_creation'] ?? 0);

        if (!array_key_exists($projectType, PROJECT_TYPES)) {
            $errors[] = 'Selecciona un tipo de trabajo válido.';
        }
        $validSubject = false;
        foreach ($subjects as $s) {
            if ((int) $s['id'] === $subjectId) {
                $validSubject = true;
                break;
            }
        }
        if (!$validSubject) {
            $errors[] = 'Selecciona una asignatura válida.';
        }
    }

    if ($title === '') {
        $errors[] = 'El título no puede estar vacío.';
    }

    if (empty($errors)) {
        try {
            $assignmentId = assignment_create(
                $pdo, $teacherId, $subjectId, $activityId,
                $title, $description,
                $startDate !== '' ? $startDate . ' 00:00:00' : null,
                $dueDate !== '' ? $dueDate . ' 23:59:59' : null,
                $points,
                $projectType
            );
            audit_log($pdo, $teacherId, 'create_assignment', "Asignación '{$title}' creada");
            redirect('teacher/assignment_detail.php?id=' . $assignmentId);
        } catch (Throwable $e) {
            error_log('create_assignment error: ' . $e->getMessage());
            $errors[] = 'No fue posible crear la asignación.';
        }
    }
}

$listStmt = $pdo->prepare(
    'SELECT a.id, a.title, a.due_date, a.points, a.project_type, act.title AS activity_title, s.name AS subject_name,
        (SELECT COUNT(*) FROM assignment_students ast WHERE ast.assignment_id = a.id) AS total_students,
        (SELECT COUNT(*) FROM submissions sub WHERE sub.assignment_id = a.id AND sub.status = "completed") AS completed_count
     FROM assignments a
     LEFT JOIN activities act ON act.id = a.activity_id
     INNER JOIN subjects s ON s.id = a.subject_id
     WHERE a.teacher_id = :teacher_id
     ORDER BY a.created_at DESC'
);
$listStmt->execute(['teacher_id' => $teacherId]);
$assignments = $listStmt->fetchAll();

$pageTitle = 'Asignaciones';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver al panel</a></p>
<h1>Asignaciones</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if (empty($subjects)): ?>
    <div class="card">
        <p>Necesitas al menos una asignatura antes de crear asignaciones.</p>
        <a class="btn" href="dashboard.php">Ir a mis cursos</a>
    </div>
<?php else: ?>
    <section class="card">
        <h2 style="margin-top:0;">Nueva asignación</h2>

        <div style="display:flex; gap:16px; margin-bottom:16px;">
            <label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:400;">
                <input type="radio" name="mode_selector" value="interactive" checked style="width:auto;" onclick="toggleMode('interactive')">
                Actividad interactiva
            </label>
            <label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:400;">
                <input type="radio" name="mode_selector" value="creation" style="width:auto;" onclick="toggleMode('creation')">
                Trabajo de creación
            </label>
        </div>

        <form method="post" action="assignments.php" class="form-narrow" style="margin:0;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_assignment">
            <input type="hidden" name="mode" id="mode_field" value="interactive">

            <div id="mode-interactive">
                <?php if (empty($activities)): ?>
                    <p class="text-muted">No tienes actividades publicadas todavía. <a href="activities.php">Crea una</a>.</p>
                <?php else: ?>
                    <label for="activity_id">Actividad (debe estar publicada)</label>
                    <select id="activity_id" name="activity_id">
                        <?php foreach ($activities as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"><?= e($a['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:0.8rem;">
                        Se juega en vivo: tú inicias la partida cuando quieras y la entrega se califica sola.
                    </p>
                <?php endif; ?>
            </div>

            <div id="mode-creation" style="display:none;">
                <label for="project_type">Tipo de trabajo</label>
                <select id="project_type" name="project_type">
                    <?php foreach (PROJECT_TYPES as $val => $label): ?>
                        <option value="<?= e($val) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="subject_id_creation">Asignatura</label>
                <select id="subject_id_creation" name="subject_id_creation">
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= e($s['course_name']) ?> — <?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted" style="font-size:0.8rem;">
                    El estudiante trabaja a su ritmo hasta la fecha de entrega. Tú calificas manualmente.
                </p>
            </div>

            <label for="title">Título de la asignación</label>
            <input type="text" id="title" name="title" required placeholder="Ej: Resumen sobre sistemas operativos">

            <label for="description">Descripción / instrucciones</label>
            <textarea id="description" name="description" rows="2"></textarea>

            <label for="start_date">Fecha de inicio (opcional)</label>
            <input type="date" id="start_date" name="start_date">

            <label for="due_date">Fecha de entrega (opcional)</label>
            <input type="date" id="due_date" name="due_date">

            <label for="points">Puntuación</label>
            <input type="text" id="points" name="points" value="100">

            <button type="submit" class="btn">Crear asignación</button>
            <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
                Se asignará automáticamente a todos los estudiantes inscritos en la asignatura.
            </p>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2 style="margin-top:0;">Mis asignaciones</h2>
        <?php if (empty($assignments)): ?>
            <p class="empty-state">Aún no has creado asignaciones.</p>
        <?php else: ?>
            <div class="grid grid-2">
                <?php foreach ($assignments as $a): ?>
                    <a class="card" href="assignment_detail.php?id=<?= (int) $a['id'] ?>" style="display:block;">
                        <h3 style="margin-top:0;"><?= e($a['title']) ?></h3>
                        <p class="text-muted" style="margin-bottom:4px;">
                            <?= e($a['subject_name']) ?> ·
                            <?= $a['activity_title'] ? e($a['activity_title']) : e(PROJECT_TYPES[$a['project_type']] ?? 'Trabajo') ?>
                        </p>
                        <p class="text-muted" style="margin-bottom:0; font-size:0.85rem;">
                            <?= (int) $a['completed_count'] ?> / <?= (int) $a['total_students'] ?> completadas ·
                            <?= (int) $a['points'] ?> pts
                            <?php if ($a['due_date']): ?>
                                · Entrega: <?= e(date('d/m/Y', strtotime($a['due_date']))) ?>
                            <?php endif; ?>
                        </p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <script>
    function toggleMode(mode) {
        document.getElementById('mode_field').value = mode;
        document.getElementById('mode-interactive').style.display = mode === 'interactive' ? 'block' : 'none';
        document.getElementById('mode-creation').style.display = mode === 'creation' ? 'block' : 'none';
    }
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
