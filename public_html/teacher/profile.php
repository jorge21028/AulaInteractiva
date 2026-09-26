<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();

$stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $teacherId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    exit('Usuario no encontrado.');
}

$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = clean_string($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'El nombre no puede estar vacío.';
        } else {
            $pdo->prepare('UPDATE users SET name = :name, updated_at = :updated_at WHERE id = :id')
                ->execute(['name' => $name, 'updated_at' => now_datetime(), 'id' => $teacherId]);
            $_SESSION['user_name'] = $name;
            $user['name'] = $name;
            $notice = 'Tus datos se actualizaron correctamente.';
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'La contraseña actual no es correcta.';
        } elseif (mb_strlen($newPassword) < 8) {
            $errors[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'La confirmación no coincide con la nueva contraseña.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id')
                ->execute([
                    'hash'       => password_hash($newPassword, PASSWORD_DEFAULT),
                    'updated_at' => now_datetime(),
                    'id'         => $teacherId,
                ]);
            audit_log($pdo, $teacherId, 'change_own_password', '');
            $notice = 'Tu contraseña se actualizó correctamente.';
        }
    }
}

$pageTitle = 'Mi perfil';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mi panel</a></p>
<h1>Mi perfil</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<section class="grid grid-2">
    <div class="card">
        <h2 style="margin-top:0;">Datos de la cuenta</h2>
        <form method="post" action="profile.php">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_profile">

            <label for="name">Nombre</label>
            <input type="text" id="name" name="name" value="<?= e($user['name']) ?>" required>

            <label for="email">Correo</label>
            <input type="email" id="email" value="<?= e($user['email']) ?>" disabled>
            <p class="text-muted" style="font-size:0.8rem; margin-top:-8px;">El correo no se puede cambiar desde aquí.</p>

            <button type="submit" class="btn">Guardar cambios</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Cambiar mi contraseña</h2>
        <form method="post" action="profile.php">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="change_password">

            <label for="current_password">Contraseña actual</label>
            <input type="password" id="current_password" name="current_password" required>

            <label for="new_password">Nueva contraseña</label>
            <input type="password" id="new_password" name="new_password" minlength="8" required>

            <label for="confirm_password">Confirmar nueva contraseña</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>

            <button type="submit" class="btn">Actualizar contraseña</button>
            <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">Debe tener al menos 8 caracteres.</p>
        </form>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
