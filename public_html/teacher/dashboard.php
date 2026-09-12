<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();

$errors = [];

// ---- Crear curso ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_course') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $name = clean_string($_POST['course_name'] ?? '');

    if ($name === '') {
        $errors[] = 'El nombre del curso no puede estar vacío.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO courses (name, created_at) VALUES (:name, :created_at)'
        );
        $stmt->execute(['name' => $name, 'created_at' => now_datetime()]);
        $courseId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO teacher_courses (teacher_id, course_id) VALUES (:teacher_id, :course_id)')
            ->execute(['teacher_id' => $teacherId, 'course_id' => $courseId]);

        audit_log($pdo, $teacherId, 'create_course', "Curso creado: {$name}");
        redirect('teacher/dashboard.php');
    }
}

// ---- Cursos del profesor ----
$stmt = $pdo->prepare(
    'SELECT c.id, c.name,
        (SELECT COUNT(*) FROM subjects s WHERE s.course_id = c.id) AS subjects_count,
        (SELECT COUNT(DISTINCT cs.student_id) FROM course_students cs WHERE cs.course_id = c.id) AS students_count
     FROM courses c
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE tc.teacher_id = :teacher_id
     ORDER BY c.name ASC'
);
$stmt->execute(['teacher_id' => $teacherId]);
$courses = $stmt->fetchAll();

// ---- Estadísticas rápidas ----
$totalCourses = count($courses);
$totalSubjects = array_sum(array_column($courses, 'subjects_count'));
$totalStudents = 0;
if ($totalCourses > 0) {
    $ids = array_column($courses, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT student_id) AS total FROM course_students WHERE course_id IN ($in)");
    $stmt2->execute($ids);
    $totalStudents = (int) ($stmt2->fetch()['total'] ?? 0);
}

$pageTitle = 'Panel del profesor';
require __DIR__ . '/../includes/header.php';
?>
<h1>Panel del profesor</h1>

<section class="grid grid-3">
    <div class="card">
        <div class="stat-number"><?= (int) $totalCourses ?></div>
        <div class="stat-label">Cursos</div>
    </div>
    <div class="card">
        <div class="stat-number"><?= (int) $totalSubjects ?></div>
        <div class="stat-label">Asignaturas</div>
    </div>
    <div class="card">
        <div class="stat-number"><?= (int) $totalStudents ?></div>
        <div class="stat-label">Estudiantes</div>
    </div>
</section>

<section class="card" style="margin-top:24px;">
    <h2 style="margin-top:0;">Mis cursos</h2>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <?php if (empty($courses)): ?>
        <p class="empty-state">Aún no tienes cursos. Crea el primero abajo.</p>
    <?php else: ?>
        <div class="grid grid-2">
            <?php foreach ($courses as $course): ?>
                <a class="card" href="course.php?id=<?= (int) $course['id'] ?>" style="display:block;">
                    <h3 style="margin-top:0;"><?= e($course['name']) ?></h3>
                    <p class="text-muted" style="margin-bottom:0;">
                        <?= (int) $course['subjects_count'] ?> asignaturas ·
                        <?= (int) $course['students_count'] ?> estudiantes
                    </p>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="dashboard.php" style="margin-top:24px; max-width:420px;">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_course">
        <label for="course_name">Nuevo curso</label>
        <input type="text" id="course_name" name="course_name" placeholder="Ej: 4to A" required>
        <button type="submit" class="btn">Crear curso</button>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
