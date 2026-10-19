<?php
/** Crear un profesor (o administrador) con contraseña temporal. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

admin_require();
$pdo = Database::getConnection();
$adminId = (int) current_user_id();
$errors = [];
$created = null;
$form = ['name' => '', 'email' => '', 'role' => 'teacher'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $form['name'] = clean_string($_POST['name'] ?? '');
    $form['email'] = strtolower(clean_string($_POST['email'] ?? ''));
    $form['role'] = ($_POST['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
    $password = trim((string) ($_POST['password'] ?? '')) ?: admin_generate_password();
    try {
        $id = admin_create_user($pdo, $form['name'], $form['email'], $form['role'], $password);
        audit_log($pdo, $adminId, 'admin_create_user', "Usuario #{$id} ({$form['email']}) creado como {$form['role']}");
        $created = ['name' => $form['name'], 'email' => $form['email'], 'role' => $form['role'], 'password' => $password];
        $form = ['name' => '', 'email' => '', 'role' => 'teacher'];
    } catch (InvalidArgumentException | RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

$pageTitle = 'Crear usuario';
$adminActive = 'create';
require __DIR__ . '/../includes/header.php';
?>
<h1>➕ Crear usuario</h1>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($created): ?>
    <div class="alert alert-success">
        ✅ <strong><?= e($created['name']) ?></strong> (<?= $created['role'] === 'admin' ? 'administrador' : 'profesor' ?>) creado.<br>
        Correo: <code><?= e($created['email']) ?></code> · Contraseña temporal: <code style="font-size:1.1rem; user-select:all;"><?= e($created['password']) ?></code><br>
        <span style="font-size:0.85rem;">Cópiala ahora: no se vuelve a mostrar. Tendrá que cambiarla al iniciar sesión.</span>
    </div>
<?php endif; ?>
<section class="card">
    <form method="post" class="form-narrow" style="margin:0;">
        <?php csrf_field(); ?>
        <label for="name">Nombre completo</label>
        <input type="text" id="name" name="name" required maxlength="150" value="<?= e($form['name']) ?>">
        <label for="email">Correo electrónico</label>
        <input type="email" id="email" name="email" required value="<?= e($form['email']) ?>">
        <label for="role">Tipo de cuenta</label>
        <select id="role" name="role">
            <option value="teacher" <?= $form['role'] === 'teacher' ? 'selected' : '' ?>>Profesor</option>
            <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>Súper administrador (acceso total)</option>
        </select>
        <label for="password">Contraseña temporal (déjala vacía para generar una)</label>
        <input type="text" id="password" name="password" minlength="8" autocomplete="off">
        <button type="submit" class="btn">Crear usuario</button>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
