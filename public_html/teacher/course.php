<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/course_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$courseId = (int) ($_GET['id'] ?? 0);

// Verificar que el curso pertenece a este profesor
$stmt = $pdo->prepare(
    'SELECT c.id, c.name, c.enrollment_code FROM courses c
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE c.id = :course_id AND tc.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['course_id' => $courseId, 'teacher_id' => $teacherId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    exit('Curso no encontrado.');
}

// Generar el código de auto-matrícula la primera vez que se visita el curso
// (los cursos creados antes de la Fase 10 no tenían este campo).
if (empty($course['enrollment_code'])) {
    $newCode = generate_course_code($pdo);
    $pdo->prepare('UPDATE courses SET enrollment_code = :code WHERE id = :id')
        ->execute(['code' => $newCode, 'id' => $courseId]);
    $course['enrollment_code'] = $newCode;
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
            $result = course_enroll_student($pdo, $courseId, (int) $student['id']);
            if (!$result['success']) {
                $errors[] = $result['message'];
            } else {
                $notice = 'Estudiante inscrito correctamente.';
            }
        }
    }

    if ($action === 'regenerate_code') {
        $newCode = generate_course_code($pdo);
        $pdo->prepare('UPDATE courses SET enrollment_code = :code WHERE id = :id')
            ->execute(['code' => $newCode, 'id' => $courseId]);
        $course['enrollment_code'] = $newCode;
        audit_log($pdo, $teacherId, 'regenerate_course_code', "Curso #{$courseId}");
        $notice = 'Código regenerado. El código anterior ya no funcionará.';
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

<section class="card" style="text-align:center; background:var(--gradient-brand-soft); border-color:var(--color-primary-light);">
    <p class="text-muted" style="margin:0 0 6px;">Código de auto-matrícula — compártelo con tus estudiantes</p>
    <div style="font-size:2.4rem; font-weight:800; letter-spacing:6px; color:var(--color-primary-dark);">
        <?= e($course['enrollment_code']) ?>
    </div>
    <p class="text-muted" style="font-size:0.85rem; margin-top:6px;">
        El estudiante lo ingresa en "Unirme a una asignatura" desde su panel, y queda inscrito automáticamente
        (sin que tengas que escribir su correo).
    </p>
    <form method="post" action="course.php?id=<?= (int) $courseId ?>" style="margin-top:12px;" onsubmit="return confirm('El código actual dejará de funcionar. ¿Generar uno nuevo?')">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="regenerate_code">
        <button type="submit" class="btn btn-secondary" style="margin:0;">Regenerar código</button>
    </form>
</section>

<section class="grid grid-2" style="margin-top:16px;">
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
            <label for="student_email">Inscribir estudiante por correo (opcional)</label>
            <input type="email" id="student_email" name="student_email" placeholder="estudiante@correo.com" required>
            <button type="submit" class="btn">Inscribir</button>
        </form>
        <p class="text-muted" style="margin-top:10px; font-size:0.85rem;">
            El estudiante debe haberse registrado previamente en Dynamic SGA con ese correo. También puede
            inscribirse solo con el código de arriba, sin que hagas nada aquí.
        </p>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
