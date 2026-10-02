<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/space_helpers.php';

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

// Estado de cada curso (por calificar / entregas pendientes) para su mosaico
$courseStats = space_summarize_by(space_assignment_rows($pdo, $teacherId), 'course_id');

$pageTitle = 'Panel del profesor';
require __DIR__ . '/../includes/header.php';
?>
<h1>Panel del profesor</h1>

<p><a class="btn" href="activities.php">Actividades interactivas</a> <a class="btn btn-secondary" href="quizzes.php">📝 Cuestionarios</a> <a class="btn btn-secondary" href="assignments.php">➕ Nueva asignación</a> <a class="btn btn-secondary" href="statistics.php">Estadísticas</a></p>

<section class="grid grid-3">
    <div class="card">
        <div class="icon-badge"><img src="<?= e(rtrim(APP_URL, '/')) ?>/assets/icons/Proyectos.png" alt=""></div>
        <div class="stat-number"><?= (int) $totalCourses ?></div>
        <div class="stat-label">Cursos</div>
    </div>
    <div class="card">
        <div class="icon-badge"><img src="<?= e(rtrim(APP_URL, '/')) ?>/assets/icons/Asignaturas.png" alt=""></div>
        <div class="stat-number"><?= (int) $totalSubjects ?></div>
        <div class="stat-label">Asignaturas</div>
    </div>
    <div class="card">
        <div class="icon-badge"><img src="<?= e(rtrim(APP_URL, '/')) ?>/assets/icons/Estudiantes.png" alt=""></div>
        <div class="stat-number"><?= (int) $totalStudents ?></div>
        <div class="stat-label">Estudiantes</div>
    </div>
</section>

<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">

<section style="margin-top:24px;">
    <h2 style="margin-bottom:4px;">Mis cursos</h2>
    <p class="text-muted" style="margin-top:0;">Entra a un curso para ver sus asignaturas y el estado de cada asignación.</p>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="space-grid">
        <?php foreach ($courses as $course):
            $cid = (int) $course['id'];
            $cs = $courseStats[$cid] ?? space_summarize([]);
        ?>
            <a class="space-tile" href="course.php?id=<?= $cid ?>">
                <div class="space-cover" style="background:<?= e(space_gradient($cid)) ?>;">
                    <span class="space-icon">🎓</span>
                    <?php if ($cs['needs_submissions'] > 0): ?>
                        <span class="space-corner" style="background:#DC2626;"><?= (int) $cs['needs_submissions'] ?> por corregir</span>
                    <?php endif; ?>
                </div>
                <div class="space-body">
                    <h3><?= e($course['name']) ?></h3>
                    <p class="space-meta">
                        <?= (int) $course['subjects_count'] ?> asignatura<?= (int) $course['subjects_count'] === 1 ? '' : 's' ?> ·
                        <?= (int) $course['students_count'] ?> estudiante<?= (int) $course['students_count'] === 1 ? '' : 's' ?> ·
                        <?= (int) $cs['assignments'] ?> <?= (int) $cs['assignments'] === 1 ? 'asignación' : 'asignaciones' ?>
                    </p>
                    <div class="chips"><?= space_status_chips($cs) ?></div>
                </div>
            </a>
        <?php endforeach; ?>

        <details class="space-tile space-new" <?= empty($courses) || !empty($errors) ? 'open' : '' ?>>
            <summary>＋ Nuevo curso</summary>
            <form method="post" action="dashboard.php" style="margin-top:12px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_course">
                <label for="course_name">Nombre del curso</label>
                <input type="text" id="course_name" name="course_name" placeholder="Ej: 4to A" required>
                <button type="submit" class="btn">Crear curso</button>
            </form>
        </details>
    </div>
    <?php if (empty($courses)): ?>
        <p class="text-muted">Aún no tienes cursos. Crea el primero con el mosaico de arriba.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
