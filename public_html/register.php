<?php
define('AULA_APP', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(dashboard_url_for_role(current_role()));
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $name     = clean_string($_POST['name'] ?? '');
    $email    = clean_string($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password2= (string) ($_POST['password_confirm'] ?? '');
    $role     = clean_string($_POST['role'] ?? '');

    if ($name === '' || $email === '' || $password === '' || $role === '') {
        $errors[] = 'Completa todos los campos.';
    } elseif ($password !== $password2) {
        $errors[] = 'Las contraseñas no coinciden.';
    } elseif (!in_array($role, ['teacher', 'student'], true)) {
        $errors[] = 'Selecciona un rol válido.';
    } else {
        try {
            $pdo = Database::getConnection();
            $userId = register_user($pdo, $name, $email, $password, $role);
            audit_log($pdo, $userId, 'register', "Registro como {$role}");
            $success = true;
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Crear cuenta';
require __DIR__ . '/includes/header.php';
?>
<div class="card form-narrow">
    <h2 style="margin-top:0;">Crear cuenta</h2>

    <?php if ($success): ?>
        <div class="alert alert-success">Cuenta creada correctamente. Ya puedes <a href="login.php">iniciar sesión</a>.</div>
    <?php else: ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="register.php" novalidate>
            <?php csrf_field(); ?>

            <label for="name">Nombre completo</label>
            <input type="text" id="name" name="name" required value="<?= e($_POST['name'] ?? '') ?>">

            <label for="email">Correo electrónico</label>
            <input type="email" id="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">

            <label for="role">Soy...</label>
            <select id="role" name="role" required>
                <option value="">Selecciona una opción</option>
                <option value="teacher" <?= (($_POST['role'] ?? '') === 'teacher') ? 'selected' : '' ?>>Profesor</option>
                <option value="student" <?= (($_POST['role'] ?? '') === 'student') ? 'selected' : '' ?>>Estudiante</option>
            </select>

            <label for="password">Contraseña (mínimo 8 caracteres)</label>
            <input type="password" id="password" name="password" required minlength="8">

            <label for="password_confirm">Confirmar contraseña</label>
            <input type="password" id="password_confirm" name="password_confirm" required minlength="8">

            <button type="submit" class="btn" style="width:100%;">Crear cuenta</button>
        </form>
    <?php endif; ?>

    <p class="text-muted" style="margin-top:20px; text-align:center;">
        ¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a>
    </p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
