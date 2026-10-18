<?php
/**
 * Eliminar una asignación, una actividad o un cuestionario (con pantalla de confirmación).
 * GET  ?type=assignment|activity|quiz&id=N  -> muestra qué se va a borrar
 * POST (con confirmación)                  -> lo borra y vuelve a la lista
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/delete_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();

$type = $_REQUEST['type'] ?? '';
$id = (int) ($_REQUEST['id'] ?? 0);
if (!in_array($type, ['assignment', 'activity', 'quiz'], true)) {
    http_response_code(404);
    exit('Elemento no válido.');
}

$described = delete_describe($pdo, $type, $id, $teacherId);
if (!$described) {
    http_response_code(404);
    exit('No encontrado (o no es tuyo).');
}
[$row, $summary] = $described;

$labels = ['assignment' => 'asignación', 'activity' => 'actividad', 'quiz' => 'cuestionario'];
$label = $labels[$type];
$title = (string) $row['title'];
$subjectId = (int) $row['subject_id'];
$backUrl = match ($type) {
    'assignment' => 'assignment_detail.php?id=' . $id,
    'activity'   => 'activity_edit.php?id=' . $id,
    default      => 'quiz_edit.php?id=' . $id,
};

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if (($_POST['confirm'] ?? '') !== '1') {
        $errors[] = 'Marca la casilla para confirmar que quieres eliminarlo.';
    } else {
        $ok = match ($type) {
            'assignment' => assignment_delete($pdo, $id, $teacherId),
            'activity'   => activity_delete($pdo, $id, $teacherId),
            default      => quiz_delete($pdo, $id, $teacherId),
        };
        if ($ok) {
            audit_log($pdo, $teacherId, 'delete_' . $type, ucfirst($label) . " #{$id} eliminada ('{$title}')");
            $next = $type === 'assignment' ? 'subject.php?id=' . $subjectId : ($type === 'activity' ? 'activities.php' : 'quizzes.php');
            redirect('teacher/' . $next . (str_contains($next, '?') ? '&' : '?') . 'deleted=' . $type);
        }
        $errors[] = 'No fue posible eliminarlo. No se borró nada; intenta de nuevo.';
    }
}

$pageTitle = 'Eliminar ' . $label;
require __DIR__ . '/../includes/header.php';
?>
<p><a href="<?= e($backUrl) ?>">&larr; Cancelar y volver</a></p>
<h1>🗑️ Eliminar <?= e($label) ?></h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<section class="card" style="border-color:#F8C9C9;">
    <p style="margin-top:0;">Vas a eliminar <strong><?= e($title) ?></strong>. <strong>Esta acción no se puede deshacer.</strong></p>
    <ul style="padding-left:20px;">
        <?php foreach ($summary as $k => $v): ?>
            <li><?= e($k) ?>: <strong><?= e((string) $v) ?></strong></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($type === 'assignment'): ?>
        <p class="text-muted">Se borran sus entregas, notas y los trabajos de los estudiantes (con sus imágenes y archivos). La actividad o cuestionario en que se basa <strong>no</strong> se borra.</p>
    <?php elseif ($type === 'activity'): ?>
        <p class="text-muted">Se borran la actividad, todas sus preguntas/palabras (con sus imágenes), las partidas en vivo y <strong>todas las asignaciones que la usan</strong>, incluidas las notas de los estudiantes.</p>
    <?php else: ?>
        <p class="text-muted">Se borran el cuestionario, su banco de preguntas, los intentos de los estudiantes y <strong>todas las asignaciones que lo usan</strong>, incluidas las notas.</p>
    <?php endif; ?>

    <form method="post" action="delete_item.php">
        <?php csrf_field(); ?>
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
            <input type="checkbox" name="confirm" value="1" style="width:auto;"> Entiendo que se borrará definitivamente
        </label>
        <button type="submit" class="btn" style="background:#B91C1C; border-color:#B91C1C;">🗑️ Eliminar <?= e($label) ?></button>
        <a class="btn btn-secondary" href="<?= e($backUrl) ?>">Cancelar</a>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
