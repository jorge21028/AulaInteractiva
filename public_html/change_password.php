<?php
/**
 * Cambiar la contraseña (cualquier rol). Es obligatorio cuando la cuenta es nueva o un administrador
 * restableció la contraseña (users.must_change_password = 1).
 */
define('AULA_APP', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
if (is_impersonating()) {
    redirect(dashboard_url_for_role(current_role()));   // no se cambia la contraseña de otra persona desde aquí
}

$pdo = Database::getConnection();
$userId = (int) current_user_id();
$forced = !empty($_SESSION['must_change_password']);
$errors = [];

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$hash = (string) ($stmt->fetch()['password_hash'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($current, $hash)) {
        $errors[] = 'La contraseña actual no es correcta.';
    } elseif (mb_strlen($new) < 8) {
        $errors[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($new !== $confirm) {
        $errors[] = 'La confirmación no coincide.';
    } elseif ($new === $current) {
        $errors[] = 'La nueva contraseña debe ser diferente de la actual.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash = :h, must_change_password = 0, updated_at = :t WHERE id = :id')
            ->execute(['h' => password_hash($new, PASSWORD_DEFAULT), 't' => now_datetime(), 'id' => $userId]);
        $_SESSION['must_change_password'] = false;
        session_regenerate_id(true);
        audit_log($pdo, $userId, 'change_own_password', $forced ? 'Cambio obligatorio de contraseña' : '');
        redirect(dashboard_url_for_role(current_role()));
    }
}

$pageTitle = 'Cambiar contraseña';
require __DIR__ . '/includes/header.php';
?>
<div class="card form-narrow">
    <h2 style="margin-top:0;">🔑 <?= $forced ? 'Crea tu contraseña definitiva' : 'Cambiar contraseña' ?></h2>
    <?php if ($forced): ?>
        <div class="alert" style="background:#FFF7ED; border:1px solid #FED7AA; color:#9A3412;">
            Tu contraseña es temporal. Por seguridad debes cambiarla antes de continuar.
        </div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>
    <form method="post" action="change_password.php">
        <?php csrf_field(); ?>
        <label for="current_password"><?= $forced ? 'Contraseña temporal' : 'Contraseña actual' ?></label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        <label for="new_password">Nueva contraseña (mínimo 8 caracteres)</label>
        <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
        <label for="confirm_password">Confirmar nueva contraseña</label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
        <button type="submit" class="btn">Guardar contraseña</button>
        <?php if (!$forced): ?><a class="btn btn-secondary" href="<?= e(dashboard_url_for_role(current_role())) ?>">Cancelar</a><?php endif; ?>
    </form>
    <?php if ($forced): ?><p style="margin-top:12px;"><a href="logout.php">Salir</a></p><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
