<?php
/** El súper administrador entra como un profesor (POST con CSRF). */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

admin_require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/dashboard.php');
}
csrf_verify($_POST['csrf_token'] ?? null);

$pdo = Database::getConnection();
$teacherId = (int) ($_POST['teacher_id'] ?? 0);
$courseId = (int) ($_POST['course_id'] ?? 0);

if (!start_impersonation($pdo, $teacherId)) {
    http_response_code(400);
    exit('No fue posible entrar como ese profesor.');
}

redirect($courseId > 0 ? 'teacher/course.php?id=' . $courseId : 'teacher/dashboard.php');
