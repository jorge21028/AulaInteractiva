<?php
/**
 * Perfil del estudiante: ver sus datos y cambiar su propia contraseña.
 * (El nombre y el correo los administra el profesor/colegio, por eso aquí solo se muestran.)
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();

$stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $studentId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    exit('Usuario no encontrado.');
}

$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    if (($_POST['action'] ?? '') === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'La contraseña actual no es correcta.';
        } elseif (mb_strlen($newPassword) < 8) {
            $errors[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'La confirmación no coincide con la nueva contraseña.';
        } elseif (password_verify($newPassword, $user['password_hash'])) {
            $errors[] = 'La nueva contraseña debe ser distinta a la actual.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id')
                ->execute(['hash' => $newHash, 'updated_at' => now_datetime(), 'id' => $studentId]);
            $user['password_hash'] = $newHash;
            session_regenerate_id(true); // buena práctica al cambiar credenciales
            audit_log($pdo, $studentId, 'change_own_password', 'Estudiante');
            $notice = 'Tu contraseña se actualizó correctamente. Úsala la próxima vez que inicies sesión.';
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
        <h2 style="margin-top:0;">Mis datos</h2>
        <label for="name">Nombre</label>
        <input type="text" id="name" value="<?= e($user['name']) ?>" disabled>
        <label for="email">Correo</label>
        <input type="email" id="email" value="<?= e($user['email']) ?>" disabled>
        <p class="text-muted" style="font-size:0.8rem;">
            Si tu nombre o tu correo están mal, avísale a tu profesor.
        </p>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Cambiar mi contraseña</h2>
        <form method="post" action="profile.php" autocomplete="off">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="change_password">

            <label for="current_password">Contraseña actual</label>
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

            <label for="new_password">Nueva contraseña</label>
            <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required>

            <label for="confirm_password">Confirmar nueva contraseña</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>

            <button type="submit" class="btn">Actualizar contraseña</button>
            <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
                Debe tener al menos 8 caracteres. Si olvidaste tu contraseña actual, pídele a tu profesor que la restablezca.
            </p>
        </form>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
