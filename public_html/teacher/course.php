<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$courseId = (int) ($_GET['id'] ?? 0);

// Verificar que el curso pertenece a este profesor
$stmt = $pdo->prepare(
    'SELECT c.id, c.name FROM courses c
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE c.id = :course_id AND tc.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['course_id' => $courseId, 'teacher_id' => $teacherId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    exit('Curso no encontrado.');
}

$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'create_subject') {
        $name = clean_string($_POST['subject_name'] ?? '');
        if ($name === '') {
            $errors[] = 'El nombre de la asignatura no puede estar vacío.';
        } else {
            $pdo->prepare('INSERT INTO subjects (course_id, name, created_at) VALUES (:course_id, :name, :created_at)')
                ->execute(['course_id' => $courseId, 'name' => $name, 'created_at' => now_datetime()]);
            audit_log($pdo, $teacherId, 'create_subject', "Asignatura '{$name}' en curso #{$courseId}");
            redirect('teacher/course.php?id=' . $courseId);
        }
    }

    if ($action === 'enroll_student') {
        $email = clean_string($_POST['student_email'] ?? '');
        $stmtS = $pdo->prepare("SELECT u.id FROM users u WHERE u.email = :email AND u.role = 'student' LIMIT 1");
        $stmtS->execute(['email' => $email]);
        $student = $stmtS->fetch();

        if (!$student) {
            $errors[] = 'No se encontró ningún estudiante registrado con ese correo.';
        } else {
            $studentId = (int) $student['id'];
            $checkStmt = $pdo->prepare('SELECT 1 FROM course_students WHERE course_id = :course_id AND student_id = :student_id');
            $checkStmt->execute(['course_id' => $courseId, 'student_id' => $studentId]);

            if ($checkStmt->fetch()) {
                $errors[] = 'Ese estudiante ya está inscrito en este curso.';
            } else {
                $pdo->prepare('INSERT INTO course_students (course_id, student_id, enrolled_at) VALUES (:course_id, :student_id, :enrolled_at)')
                    ->execute(['course_id' => $courseId, 'student_id' => $studentId, 'enrolled_at' => now_datetime()]);
                $notice = 'Estudiante inscrito correctamente.';
            }
        }
    }
}

// Asignaturas del curso
$subjStmt = $pdo->prepare('SELECT id, name FROM subjects WHERE course_id = :course_id ORDER BY name ASC');
$subjStmt->execute(['course_id' => $courseId]);
$subjects = $subjStmt->fetchAll();

// Estudiantes inscritos
$studStmt = $pdo->prepare(
    'SELECT u.id, u.name, u.email FROM course_students cs
     INNER JOIN users u ON u.id = cs.student_id
     WHERE cs.course_id = :course_id ORDER BY u.name ASC'
);
$studStmt->execute(['course_id' => $courseId]);
$students = $studStmt->fetchAll();

$pageTitle = $course['name'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mis cursos</a></p>
<h1><?= e($course['name']) ?></h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<section class="grid grid-2">
    <div class="card">
        <h2 style="margin-top:0;">Asignaturas</h2>
        <?php if (empty($subjects)): ?>
            <p class="empty-state">Sin asignaturas todavía.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($subjects as $s): ?>
                    <li><?= e($s['name']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="course.php?id=<?= (int) $courseId ?>" style="margin-top:16px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_subject">
            <label for="subject_name">Nueva asignatura</label>
            <input type="text" id="subject_name" name="subject_name" placeholder="Ej: Informática" required>
            <button type="submit" class="btn">Agregar asignatura</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Estudiantes inscritos</h2>
        <?php if (empty($students)): ?>
            <p class="empty-state">Sin estudiantes todavía.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($students as $st): ?>
                    <li><?= e($st['name']) ?> <span class="text-muted">(<?= e($st['email']) ?>)</span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="course.php?id=<?= (int) $courseId ?>" style="margin-top:16px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="enroll_student">
            <label for="student_email">Inscribir estudiante por correo</label>
            <input type="email" id="student_email" name="student_email" placeholder="estudiante@correo.com" required>
            <button type="submit" class="btn">Inscribir</button>
        </form>
        <p class="text-muted" style="margin-top:10px; font-size:0.85rem;">
            El estudiante debe haberse registrado previamente en AulaInteractiva con ese correo.
        </p>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
