<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php'; // iconos de modos de juego
require_once __DIR__ . '/../includes/assignment_helpers.php';
require_once __DIR__ . '/../includes/project_helpers.php';
require_once __DIR__ . '/../includes/space_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$errors = [];

// Asignaturas del profesor
$subjStmt = $pdo->prepare(
    'SELECT s.id, s.name, s.course_id, c.name AS course_name
     FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE tc.teacher_id = :teacher_id
     ORDER BY c.name, s.name'
);
$subjStmt->execute(['teacher_id' => $teacherId]);
$subjects = $subjStmt->fetchAll();

// Si se llega desde el espacio de una asignatura (?subject=ID), el formulario se prepara para ella.
$preSubject = null;
foreach ($subjects as $sj) {
    if ((int) $sj['id'] === (int) ($_GET['subject'] ?? 0)) {
        $preSubject = $sj;
        break;
    }
}

// Actividades publicadas del profesor (solo esas se pueden asignar)
$actStmt = $pdo->prepare(
    "SELECT id, title, subject_id, game_mode FROM activities WHERE teacher_id = :teacher_id AND status = 'published' ORDER BY title"
);
$actStmt->execute(['teacher_id' => $teacherId]);
$activities = $actStmt->fetchAll();

// Cuestionarios publicados del profesor
$quizStmt = $pdo->prepare(
    "SELECT id, title, subject_id FROM quizzes WHERE teacher_id = :teacher_id AND status = 'published' ORDER BY title"
);
$quizStmt->execute(['teacher_id' => $teacherId]);
$quizzes = $quizStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_assignment') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $mode = clean_string($_POST['mode'] ?? 'interactive'); // 'interactive' | 'quiz' | 'creation'
    $title = clean_string($_POST['title'] ?? '');
    $description = clean_string($_POST['description'] ?? '');
    $startDate = clean_string($_POST['start_date'] ?? '');
    $dueDate = clean_string($_POST['due_date'] ?? '');
    $points = max(1, (int) ($_POST['points'] ?? 100));

    $activityId = null;
    $quizId = null;
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
    } elseif ($mode === 'quiz') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $quiz = null;
        foreach ($quizzes as $q) {
            if ((int) $q['id'] === $quizId) {
                $quiz = $q;
                break;
            }
        }
        if (!$quiz) {
            $errors[] = 'Selecciona un cuestionario publicado válido.';
        } else {
            $subjectId = (int) $quiz['subject_id'];
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
                $projectType,
                $quizId
            );
            audit_log($pdo, $teacherId, 'create_assignment', "Asignación '{$title}' creada");
            redirect('teacher/assignment_detail.php?id=' . $assignmentId);
        } catch (Throwable $e) {
            error_log('create_assignment error: ' . $e->getMessage());
            $errors[] = 'No fue posible crear la asignación.';
        }
    }
}

// Estado por curso (para los mosaicos de navegación al final de la página)
$courseTiles = [];
foreach ($subjects as $sj) {
    $courseTiles[(int) $sj['course_id']] = $sj['course_name'];
}
$courseStats = space_summarize_by(space_assignment_rows($pdo, $teacherId), 'course_id');

$pageTitle = 'Nueva asignación';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">
<?php if ($preSubject): ?>
    <nav class="crumbs" aria-label="Ruta">
        <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
        <a href="course.php?id=<?= (int) $preSubject['course_id'] ?>"><?= e($preSubject['course_name']) ?></a><span class="sep">›</span>
        <a href="subject.php?id=<?= (int) $preSubject['id'] ?>"><?= e($preSubject['name']) ?></a><span class="sep">›</span>
        <strong>Nueva asignación</strong>
    </nav>
<?php else: ?>
    <nav class="crumbs" aria-label="Ruta"><a href="dashboard.php">Mis cursos</a><span class="sep">›</span><strong>Nueva asignación</strong></nav>
<?php endif; ?>
<h1>Nueva asignación</h1>
<?php if ($preSubject): ?>
    <p class="space-sub" style="margin-top:-6px;">En <strong><?= e($preSubject['course_name']) ?> — <?= e($preSubject['name']) ?></strong>.
        Solo se muestran las actividades y cuestionarios de esta asignatura. <a href="assignments.php">Ver todos</a></p>
<?php endif; ?>

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

        <div style="display:flex; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:400;">
                <input type="radio" name="mode_selector" value="interactive" checked style="width:auto;" onclick="toggleMode('interactive')">
                Actividad interactiva
            </label>
            <label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:400;">
                <input type="radio" name="mode_selector" value="quiz" style="width:auto;" onclick="toggleMode('quiz')">
                Cuestionario
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
                <?php $activitiesShown = $preSubject ? array_values(array_filter($activities, fn($x) => (int) $x['subject_id'] === (int) $preSubject['id'])) : $activities; ?>
                <?php if (empty($activitiesShown)): ?>
                    <p class="text-muted">No tienes actividades publicadas<?= $preSubject ? ' en esta asignatura' : '' ?> todavía. <a href="activities.php">Crea una</a>.</p>
                <?php else: ?>
                    <label for="activity_id">Actividad (debe estar publicada)</label>
                    <select id="activity_id" name="activity_id">
                        <?php foreach ($activitiesShown as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"><?= e(game_mode_icon($a['game_mode'])) ?> <?= e($a['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:0.8rem;">
                        Trivia y Sapito se juegan en vivo (tú inicias la partida). Ahorcado y Crucigrama los juegan los estudiantes
                        a su ritmo, sin que inicies nada. En todos la entrega se califica sola.
                    </p>
                <?php endif; ?>
            </div>

            <div id="mode-quiz" style="display:none;">
                <?php $quizzesShown = $preSubject ? array_values(array_filter($quizzes, fn($x) => (int) $x['subject_id'] === (int) $preSubject['id'])) : $quizzes; ?>
                <?php if (empty($quizzesShown)): ?>
                    <p class="text-muted">No tienes cuestionarios publicados<?= $preSubject ? ' en esta asignatura' : '' ?> todavía. <a href="quizzes.php">Crea uno</a>.</p>
                <?php else: ?>
                    <label for="quiz_id">Cuestionario (debe estar publicado)</label>
                    <select id="quiz_id" name="quiz_id">
                        <?php foreach ($quizzesShown as $q): ?>
                            <option value="<?= (int) $q['id'] ?>">📝 <?= e($q['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:0.8rem;">
                        El estudiante lo resuelve a su ritmo, dentro de la fecha límite. Se autocalifica al entregar.
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
                        <option value="<?= (int) $s['id'] ?>" <?= ($preSubject && (int) $preSubject['id'] === (int) $s['id']) ? 'selected' : '' ?>><?= e($s['course_name']) ?> — <?= e($s['name']) ?></option>
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

    <section style="margin-top:24px;">
        <h2 style="margin-bottom:4px;">Ver mis asignaciones</h2>
        <p class="text-muted" style="margin-top:0;">Las asignaciones están organizadas por curso y asignatura: entra a un curso para ver su estado.</p>
        <div class="space-grid">
            <?php foreach ($courseTiles as $cid => $cname):
                $cs = $courseStats[$cid] ?? space_summarize([]); ?>
                <a class="space-tile" href="course.php?id=<?= (int) $cid ?>">
                    <div class="space-cover" style="background:<?= e(space_gradient((int) $cid)) ?>; height:70px;">
                        <span class="space-icon">🎓</span>
                        <?php if ($cs['needs_submissions'] > 0): ?>
                            <span class="space-corner" style="background:#DC2626;"><?= (int) $cs['needs_submissions'] ?> por corregir</span>
                        <?php endif; ?>
                    </div>
                    <div class="space-body">
                        <h3><?= e($cname) ?></h3>
                        <div class="chips"><?= space_status_chips($cs) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <script>
    function toggleMode(mode) {
        document.getElementById('mode_field').value = mode;
        document.getElementById('mode-interactive').style.display = mode === 'interactive' ? 'block' : 'none';
        document.getElementById('mode-quiz').style.display = mode === 'quiz' ? 'block' : 'none';
        document.getElementById('mode-creation').style.display = mode === 'creation' ? 'block' : 'none';
    }
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
