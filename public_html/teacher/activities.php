<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$errors = [];

// Asignaturas disponibles (de los cursos de este profesor)
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_activity') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $title = clean_string($_POST['title'] ?? '');
    $subjectId = (int) ($_POST['subject_id'] ?? 0);

    $validSubject = false;
    foreach ($subjects as $s) {
        if ((int) $s['id'] === $subjectId) {
            $validSubject = true;
            break;
        }
    }

    if ($title === '') {
        $errors[] = 'El título no puede estar vacío.';
    } elseif (!$validSubject) {
        $errors[] = 'Selecciona una asignatura válida.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO activities (subject_id, teacher_id, title, difficulty, time_per_question, points_base, speed_bonus_max, ranking_enabled, allow_repeat, team_mode, source, status, created_at)
             VALUES (:subject_id, :teacher_id, :title, :difficulty, :time_per_question, 100, 50, 1, 1, 0, :source, :status, :created_at)'
        );
        $stmt->execute([
            'subject_id'        => $subjectId,
            'teacher_id'        => $teacherId,
            'title'             => $title,
            'difficulty'        => 'media',
            'time_per_question' => 20,
            'source'            => 'manual',
            'status'            => 'draft',
            'created_at'        => now_datetime(),
        ]);
        $activityId = (int) $pdo->lastInsertId();
        audit_log($pdo, $teacherId, 'create_activity', "Actividad '{$title}' creada");
        redirect('teacher/activity_edit.php?id=' . $activityId);
    }
}

$listStmt = $pdo->prepare(
    'SELECT a.id, a.title, a.status, a.difficulty,
        (SELECT COUNT(*) FROM activity_questions q WHERE q.activity_id = a.id) AS questions_count,
        s.name AS subject_name
     FROM activities a
     INNER JOIN subjects s ON s.id = a.subject_id
     WHERE a.teacher_id = :teacher_id
     ORDER BY a.created_at DESC'
);
$listStmt->execute(['teacher_id' => $teacherId]);
$activities = $listStmt->fetchAll();

$pageTitle = 'Mis actividades';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver al panel</a></p>
<h1>Actividades interactivas</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if (empty($subjects)): ?>
    <div class="card">
        <p>Necesitas crear al menos un curso y una asignatura antes de crear actividades.</p>
        <a class="btn" href="dashboard.php">Ir a mis cursos</a>
    </div>
<?php else: ?>
    <section class="card">
        <h2 style="margin-top:0;">Crear nueva actividad</h2>
        <form method="post" action="activities.php" class="form-narrow" style="margin:0;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_activity">

            <label for="title">Título</label>
            <input type="text" id="title" name="title" required placeholder="Ej: Desafío de fracciones">

            <label for="subject_id">Asignatura</label>
            <select id="subject_id" name="subject_id" required>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e($s['course_name']) ?> — <?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn">Crear y continuar</button>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2 style="margin-top:0;">Mis actividades</h2>
        <?php if (empty($activities)): ?>
            <p class="empty-state">Aún no has creado actividades.</p>
        <?php else: ?>
            <div class="grid grid-2">
                <?php foreach ($activities as $a): ?>
                    <a class="card" href="activity_edit.php?id=<?= (int) $a['id'] ?>" style="display:block;">
                        <h3 style="margin-top:0;"><?= e($a['title']) ?></h3>
                        <p class="text-muted" style="margin-bottom:4px;"><?= e($a['subject_name']) ?></p>
                        <p class="text-muted" style="margin-bottom:0; font-size:0.85rem;">
                            <?= (int) $a['questions_count'] ?> preguntas ·
                            <?= $a['status'] === 'published' ? 'Publicada' : 'Borrador' ?> ·
                            Dificultad: <?= e($a['difficulty']) ?>
                        </p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
