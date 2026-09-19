<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/course_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();
$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $code = clean_string($_POST['code'] ?? '');

    if ($code === '') {
        $errors[] = 'Ingresa el código que te dio tu profesor.';
    } else {
        $course = course_find_by_code($pdo, $code);

        if (!$course) {
            $errors[] = 'No existe ningún curso con ese código. Verifícalo con tu profesor.';
        } else {
            $result = course_enroll_student($pdo, (int) $course['id'], $studentId);
            if ($result['success']) {
                audit_log($pdo, $studentId, 'self_enroll', "Curso #{$course['id']} vía código");
                redirect('student/dashboard.php');
            } elseif ($result['already_enrolled']) {
                $errors[] = 'Ya estás inscrito en el curso "' . $course['name'] . '".';
            } else {
                $errors[] = $result['message'];
            }
        }
    }
}

$pageTitle = 'Unirme a una asignatura';
require __DIR__ . '/../includes/header.php';
?>
<div class="card form-narrow" style="text-align:center;">
    <div class="icon-badge" style="margin:0 auto 10px;"><img src="<?= e(rtrim(APP_URL, '/')) ?>/assets/icons/Asignaturas.png" alt=""></div>
    <h1 style="margin-top:0;">Unirme a una asignatura</h1>
    <p class="text-muted">Ingresa el código de 6 caracteres que te dio tu profesor.</p>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="join_course.php">
        <?php csrf_field(); ?>
        <label for="code">Código</label>
        <input type="text" id="code" name="code" required maxlength="10" style="text-align:center; font-size:1.5rem; letter-spacing:4px; text-transform:uppercase;" autocomplete="off">
        <button type="submit" class="btn" style="width:100%;">Unirme</button>
    </form>

    <p class="text-muted" style="margin-top:20px; font-size:0.85rem;">
        <a href="<?= e(rtrim(APP_URL, '/')) ?>/student/dashboard.php">&larr; Volver a mi panel</a>
    </p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
