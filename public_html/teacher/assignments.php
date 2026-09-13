<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assignment_helpers.php';

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

    $activityId = (int) ($_POST['activity_id'] ?? 0);
    $title = clean_string($_POST['title'] ?? '');
    $description = clean_string($_POST['description'] ?? '');
    $startDate = clean_string($_POST['start_date'] ?? '');
    $dueDate = clean_string($_POST['due_date'] ?? '');
    $points = max(1, (int) ($_POST['points'] ?? 100));

    $activity = null;
    foreach ($activities as $a) {
        if ((int) $a['id'] === $activityId) {
            $activity = $a;
            break;
        }
    }

    if (!$activity) {
        $errors[] = 'Selecciona una actividad publicada válida.';
    } elseif ($title === '') {
        $errors[] = 'El título no puede estar vacío.';
    } else {
        try {
            $assignmentId = assignment_create(
                $pdo, $teacherId, (int) $activity['subject_id'], $activityId,
                $title, $description,
                $startDate !== '' ? $startDate . ' 00:00:00' : null,
                $dueDate !== '' ? $dueDate . ' 23:59:59' : null,
                $points
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
    'SELECT a.id, a.title, a.due_date, a.points, act.title AS activity_title, s.name AS subject_name,
        (SELECT COUNT(*) FROM assignment_students ast WHERE ast.assignment_id = a.id) AS total_students,
        (SELECT COUNT(*) FROM submissions sub WHERE sub.assignment_id = a.id AND sub.status = "completed") AS completed_count
     FROM assignments a
     INNER JOIN activities act ON act.id = a.activity_id
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

<?php if (empty($activities)): ?>
    <div class="card">
        <p>Necesitas al menos una actividad <strong>publicada</strong> antes de poder asignarla.</p>
        <a class="btn" href="activities.php">Ir a mis actividades</a>
    </div>
<?php else: ?>
    <section class="card">
        <h2 style="margin-top:0;">Nueva asignación</h2>
        <form method="post" action="assignments.php" class="form-narrow" style="margin:0;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_assignment">

            <label for="activity_id">Actividad (debe estar publicada)</label>
            <select id="activity_id" name="activity_id" required>
                <?php foreach ($activities as $a): ?>
                    <option value="<?= (int) $a['id'] ?>"><?= e($a['title']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="title">Título de la asignación</label>
            <input type="text" id="title" name="title" required placeholder="Ej: Quiz de fracciones - Unidad 3">

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
                Se asignará automáticamente a todos los estudiantes inscritos en la asignatura de esa actividad.
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
                        <p class="text-muted" style="margin-bottom:4px;"><?= e($a['subject_name']) ?> · <?= e($a['activity_title']) ?></p>
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
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
