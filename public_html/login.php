<?php
define('AULA_APP', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(dashboard_url_for_role(current_role()));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $email    = clean_string($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $errors[] = 'Completa correo y contraseña.';
    } else {
        $pdo = Database::getConnection();

        // Límite de intentos: 5 fallos en 15 minutos para el mismo correo bloquean el acceso temporalmente.
        $since = date('Y-m-d H:i:s', time() - 900);
        $failKey = 'email=' . mb_strtolower($email, 'UTF-8');
        $fails = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed' AND details = :d AND created_at >= :since");
        $fails->execute(['d' => $failKey, 'since' => $since]);

        if ((int) $fails->fetchColumn() >= 5) {
            $errors[] = 'Demasiados intentos fallidos. Espera 15 minutos e inténtalo de nuevo.';
        } elseif (attempt_login($pdo, $email, $password)) {
            audit_log($pdo, current_user_id(), 'login', 'Inicio de sesión exitoso');
            redirect(dashboard_url_for_role(current_role()));
        } else {
            audit_log($pdo, null, 'login_failed', $failKey);
            $errors[] = 'Correo o contraseña incorrectos.';
        }
    }
}

$pageTitle = 'Iniciar sesión';
require __DIR__ . '/includes/header.php';
?>
<div class="card form-narrow">
    <h2 style="margin-top:0;">Iniciar sesión</h2>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="login.php" novalidate>
        <?php csrf_field(); ?>

        <label for="email">Correo electrónico</label>
        <input type="email" id="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">

        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="btn" style="width:100%;">Entrar</button>
    </form>

    <p class="text-muted" style="margin-top:20px; text-align:center;">
        ¿No tienes cuenta? <a href="register.php">Regístrate</a>
    </p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
