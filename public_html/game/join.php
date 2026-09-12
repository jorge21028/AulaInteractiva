<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';

require_role('student');

$errors = [];
$prefillCode = clean_string($_GET['code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $code = clean_string($_POST['code'] ?? '');
    $nickname = clean_string($_POST['nickname'] ?? '') ?: (string) current_user_name();

    if ($code === '') {
        $errors[] = 'Ingresa el código que te dio tu profesor.';
    } else {
        $pdo = Database::getConnection();
        $result = game_join_student($pdo, $code, current_user_id(), $nickname);

        if (!$result['success']) {
            $errors[] = $result['message'];
        } else {
            redirect('game/play.php?code=' . $result['game']['code']);
        }
    }
}

$pageTitle = 'Unirse a un juego';
require __DIR__ . '/../includes/header.php';
?>
<div class="card form-narrow" style="text-align:center;">
    <h1 style="margin-top:0;">Unirse a un juego</h1>
    <p class="text-muted">Ingresa el código de 6 dígitos que te dio tu profesor.</p>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="join.php">
        <?php csrf_field(); ?>
        <label for="code">Código</label>
        <input type="text" id="code" name="code" required maxlength="6" style="text-align:center; font-size:1.5rem; letter-spacing:4px;" value="<?= e($prefillCode) ?>">

        <label for="nickname">Tu nombre en el juego</label>
        <input type="text" id="nickname" name="nickname" value="<?= e(current_user_name()) ?>">

        <button type="submit" class="btn" style="width:100%;">Entrar</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
