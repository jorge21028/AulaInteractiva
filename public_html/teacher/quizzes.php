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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_quiz') {
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
            'INSERT INTO quizzes (subject_id, teacher_id, title, time_limit_minutes, max_attempts, status, created_at)
             VALUES (:subject_id, :teacher_id, :title, NULL, 1, \'draft\', :created_at)'
        );
        $stmt->execute([
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
            'title'      => $title,
            'created_at' => now_datetime(),
        ]);
        $quizId = (int) $pdo->lastInsertId();
        audit_log($pdo, $teacherId, 'create_quiz', "Cuestionario '{$title}' creado");
        redirect('teacher/quiz_edit.php?id=' . $quizId);
    }
}

$listStmt = $pdo->prepare(
    'SELECT q.id, q.title, q.status, q.max_attempts, q.time_limit_minutes,
        (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS questions_count,
        s.name AS subject_name
     FROM quizzes q
     INNER JOIN subjects s ON s.id = q.subject_id
     WHERE q.teacher_id = :teacher_id
     ORDER BY q.created_at DESC'
);
$listStmt->execute(['teacher_id' => $teacherId]);
$quizzes = $listStmt->fetchAll();

$pageTitle = 'Mis cuestionarios';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver al panel</a></p>
<h1>📝 Cuestionarios</h1>
<p class="text-muted" style="margin-top:-8px;">
    A diferencia de las actividades en vivo (Trivia / El Sapito), el cuestionario lo resuelve cada estudiante
    a su propio ritmo, dentro de la fecha límite que definas al asignarlo — como en Moodle.
</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if (empty($subjects)): ?>
    <div class="card">
        <p>Necesitas crear al menos un curso y una asignatura antes de crear cuestionarios.</p>
        <a class="btn" href="dashboard.php">Ir a mis cursos</a>
    </div>
<?php else: ?>
    <section class="card">
        <h2 style="margin-top:0;">Crear nuevo cuestionario</h2>
        <form method="post" action="quizzes.php" class="form-narrow" style="margin:0;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_quiz">

            <label for="title">Título</label>
            <input type="text" id="title" name="title" required placeholder="Ej: Examen corto — Unidad 3">

            <label for="subject_id">Asignatura</label>
            <select id="subject_id" name="subject_id" required>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e($s['course_name']) ?> — <?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn">Crear y continuar</button>
            <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
                El tiempo límite y la cantidad de intentos se configuran en la siguiente pantalla.
            </p>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2 style="margin-top:0;">Mis cuestionarios</h2>
        <?php if (empty($quizzes)): ?>
            <p class="empty-state">Aún no has creado cuestionarios.</p>
        <?php else: ?>
            <div class="grid grid-2">
                <?php foreach ($quizzes as $q): ?>
                    <a class="card" href="quiz_edit.php?id=<?= (int) $q['id'] ?>" style="display:block;">
                        <h3 style="margin-top:0;">📝 <?= e($q['title']) ?></h3>
                        <p class="text-muted" style="margin-bottom:4px;"><?= e($q['subject_name']) ?></p>
                        <p class="text-muted" style="margin-bottom:0; font-size:0.85rem;">
                            <?= (int) $q['questions_count'] ?> preguntas ·
                            <?= $q['status'] === 'published' ? 'Publicado' : 'Borrador' ?> ·
                            <?= (int) $q['max_attempts'] ?> intento<?= (int) $q['max_attempts'] === 1 ? '' : 's' ?> ·
                            <?= $q['time_limit_minutes'] ? (int) $q['time_limit_minutes'] . ' min' : 'sin límite de tiempo' ?>
                        </p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
