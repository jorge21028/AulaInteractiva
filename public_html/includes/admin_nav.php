<?php
/** Barra de navegación del súper administrador. $adminActive = 'dashboard'|'courses'|'students'|'audit'|'create' */
if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}
$adminActive = $adminActive ?? '';
$adminBase = rtrim(APP_URL, '/') . '/admin';
$adminTabs = [
    'dashboard' => ['🏠 Panel', 'dashboard.php'],
    'courses'   => ['📚 Cursos', 'courses.php'],
    'students'  => ['🎓 Estudiantes', 'students.php'],
    'audit'     => ['🧾 Registro', 'audit.php'],
    'create'    => ['➕ Crear usuario', 'create_user.php'],
];
?>
<nav style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
    <?php foreach ($adminTabs as $key => [$label, $file]): ?>
        <a class="btn <?= $adminActive === $key ? '' : 'btn-secondary' ?>" style="margin:0; padding:6px 14px; font-size:0.9rem;" href="<?= e($adminBase . '/' . $file) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
