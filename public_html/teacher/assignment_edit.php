<?php
/**
 * Editar una asignación ya creada: título, instrucciones, fecha/hora de inicio, fecha/hora de entrega y puntaje.
 * El tipo (actividad, cuestionario o trabajo de creación) y la asignatura no se cambian: los estudiantes
 * ya pueden tener avance o entregas hechas sobre ese contenido.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';
require_once __DIR__ . '/../includes/project_helpers.php';
require_once __DIR__ . '/../includes/space_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$assignmentId = (int) ($_GET['id'] ?? 0);
$errors = [];

$stmt = $pdo->prepare(
    'SELECT a.*, s.name AS subject_name, s.course_id, c.name AS course_name, c.is_active AS course_active,
            act.title AS activity_title, act.game_mode, qz.title AS quiz_title
     FROM assignments a
     INNER JOIN subjects s ON s.id = a.subject_id
     INNER JOIN courses c ON c.id = s.course_id
     LEFT JOIN activities act ON act.id = a.activity_id
     LEFT JOIN quizzes qz ON qz.id = a.quiz_id
     WHERE a.id = :id AND a.teacher_id = :tid LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'tid' => $teacherId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    http_response_code(404);
    exit('Asignación no encontrada.');
}

// ¿Cuántas entregas ya tienen nota? (para avisar si cambia el puntaje)
$gradedStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM submissions WHERE assignment_id = :id AND score IS NOT NULL');
$gradedStmt->execute(['id' => $assignmentId]);
$gradedCount = (int) ($gradedStmt->fetch()['total'] ?? 0);

$form = [
    'title'       => $assignment['title'],
    'description' => (string) $assignment['description'],
    'start_date'  => dt_to_input($assignment['start_date']),
    'due_date'    => dt_to_input($assignment['due_date']),
    'points'      => (string) $assignment['points'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $form['title'] = clean_string($_POST['title'] ?? '');
    $form['description'] = clean_string($_POST['description'] ?? '');
    $form['start_date'] = clean_string($_POST['start_date'] ?? '');
    $form['due_date'] = clean_string($_POST['due_date'] ?? '');
    $form['points'] = trim((string) ($_POST['points'] ?? ''));

    $startDate = dt_from_input($form['start_date']);
    $dueDate = dt_from_input($form['due_date'], true);
    $points = (int) $form['points'];

    if ($form['title'] === '') {
        $errors[] = 'El título no puede estar vacío.';
    }
    if ($form['start_date'] !== '' && $startDate === null) {
        $errors[] = 'La fecha y hora de inicio no son válidas.';
    }
    if ($form['due_date'] !== '' && $dueDate === null) {
        $errors[] = 'La fecha y hora de entrega no son válidas.';
    }
    if ($startDate !== null && $dueDate !== null && $dueDate <= $startDate) {
        $errors[] = 'La fecha y hora de entrega deben ser posteriores a las de inicio.';
    }
    if ($points < 1 || $points > 65000) {
        $errors[] = 'La puntuación debe ser un número entre 1 y 65000.';
    }

    if (empty($errors)) {
        $pdo->prepare(
            'UPDATE assignments SET title = :title, description = :description, start_date = :start_date,
                    due_date = :due_date, points = :points
             WHERE id = :id AND teacher_id = :tid'
        )->execute([
            'title' => $form['title'], 'description' => $form['description'], 'start_date' => $startDate,
            'due_date' => $dueDate, 'points' => $points, 'id' => $assignmentId, 'tid' => $teacherId,
        ]);
        audit_log($pdo, $teacherId, 'update_assignment', "Asignación #{$assignmentId} editada ('{$form['title']}')");
        redirect('teacher/assignment_detail.php?id=' . $assignmentId . '&updated=1');
    }
}

[$icon, $kind] = space_assignment_kind($assignment);

$pageTitle = 'Editar asignación';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="assignment_detail.php?id=<?= (int) $assignmentId ?>">&larr; Volver a la asignación</a></p>
<h1>✏️ Editar asignación</h1>
<p class="text-muted" style="margin-top:-8px;">
    <?= e($assignment['course_name']) ?> — <?= e($assignment['subject_name']) ?> · <?= e($icon) ?> <?= e($kind) ?>
</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<section class="card">
    <form method="post" action="assignment_edit.php?id=<?= (int) $assignmentId ?>" class="form-narrow" style="margin:0;">
        <?php csrf_field(); ?>

        <label for="title">Título de la asignación</label>
        <input type="text" id="title" name="title" required value="<?= e($form['title']) ?>">

        <label for="description">Descripción / instrucciones</label>
        <textarea id="description" name="description" rows="4"><?= e($form['description']) ?></textarea>

        <label for="start_date">Fecha y hora de inicio (opcional)</label>
        <input type="datetime-local" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>">
        <p class="text-muted" style="font-size:0.8rem; margin:2px 0 10px;">
            Antes de esta fecha y hora los estudiantes no ven la asignación. Vacío = visible desde ya.
        </p>

        <label for="due_date">Fecha y hora de entrega (opcional)</label>
        <input type="datetime-local" id="due_date" name="due_date" value="<?= e($form['due_date']) ?>">
        <p class="text-muted" style="font-size:0.8rem; margin:2px 0 10px;">
            Pasada esta fecha y hora se ve como vencida y no se puede entregar. Si la mueves hacia adelante, vuelve a abrirse para todos.
            Hora actual del servidor: <strong><?= e(dt_format(now_datetime())) ?></strong>.
        </p>

        <label for="points">Puntuación</label>
        <input type="text" id="points" name="points" value="<?= e($form['points']) ?>">
        <?php if ($gradedCount > 0): ?>
            <p class="text-muted" style="font-size:0.8rem; margin:2px 0 10px;">
                ⚠️ Ya hay <?= $gradedCount ?> entrega(s) con nota. Cambiar la puntuación <strong>no recalcula</strong> las notas ya registradas.
            </p>
        <?php endif; ?>

        <p class="text-muted" style="font-size:0.85rem;">
            No se puede cambiar el tipo (<?= e($kind) ?>) ni la asignatura, porque los estudiantes pueden tener avance sobre ese contenido.
            Para dirigirla a más estudiantes o quitar a alguno, usa la pantalla de la asignación.
        </p>

        <button type="submit" class="btn">Guardar cambios</button>
        <a class="btn btn-secondary" href="assignment_detail.php?id=<?= (int) $assignmentId ?>">Cancelar</a>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
