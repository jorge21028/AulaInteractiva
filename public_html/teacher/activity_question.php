<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$questionId = (int) ($_GET['id'] ?? 0);
$errors = [];
$notice = null;

// Cargar pregunta y verificar propiedad a través de la actividad
$stmt = $pdo->prepare(
    'SELECT q.*, a.id AS activity_id, a.teacher_id, a.title AS activity_title
     FROM activity_questions q
     INNER JOIN activities a ON a.id = q.activity_id
     WHERE q.id = :id AND a.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['id' => $questionId, 'teacher_id' => $teacherId]);
$question = $stmt->fetch();

if (!$question) {
    http_response_code(404);
    exit('Pregunta no encontrada.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'update_question') {
        $statement = clean_string($_POST['statement'] ?? '');
        $timeSeconds = max(5, (int) ($_POST['time_seconds'] ?? 20));
        $points = max(10, (int) ($_POST['points'] ?? 100));
        $explanation = clean_string($_POST['explanation'] ?? '');

        if ($statement === '') {
            $errors[] = 'El enunciado no puede estar vacío.';
        } else {
            $pdo->prepare(
                'UPDATE activity_questions SET statement = :statement, time_seconds = :time_seconds, points = :points, explanation = :explanation WHERE id = :id'
            )->execute([
                'statement' => $statement, 'time_seconds' => $timeSeconds, 'points' => $points,
                'explanation' => $explanation, 'id' => $questionId,
            ]);
            $notice = 'Pregunta actualizada.';
            $question['statement'] = $statement;
            $question['time_seconds'] = $timeSeconds;
            $question['points'] = $points;
            $question['explanation'] = $explanation;
        }
    }

    if ($action === 'add_option' && $question['type'] === 'multiple') {
        $text = clean_string($_POST['option_text'] ?? '');
        if ($text === '') {
            $errors[] = 'El texto de la opción no puede estar vacío.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM question_options WHERE question_id = :qid');
            $countStmt->execute(['qid' => $questionId]);
            $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);

            $pdo->prepare(
                'INSERT INTO question_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, 0, :order_index)'
            )->execute(['qid' => $questionId, 'text' => $text, 'order_index' => $orderIndex]);
            $notice = 'Opción agregada.';
        }
    }

    if ($action === 'delete_option' && $question['type'] === 'multiple') {
        $optionId = (int) ($_POST['option_id'] ?? 0);
        $pdo->prepare('DELETE FROM question_options WHERE id = :id AND question_id = :qid')
            ->execute(['id' => $optionId, 'qid' => $questionId]);
        $notice = 'Opción eliminada.';
    }

    if ($action === 'set_correct') {
        $optionId = (int) ($_POST['option_id'] ?? 0);
        $pdo->prepare('UPDATE question_options SET is_correct = 0 WHERE question_id = :qid')->execute(['qid' => $questionId]);
        $pdo->prepare('UPDATE question_options SET is_correct = 1 WHERE id = :id AND question_id = :qid')
            ->execute(['id' => $optionId, 'qid' => $questionId]);
        $notice = 'Respuesta correcta actualizada.';
    }
}

$optStmt = $pdo->prepare('SELECT * FROM question_options WHERE question_id = :qid ORDER BY order_index ASC, id ASC');
$optStmt->execute(['qid' => $questionId]);
$options = $optStmt->fetchAll();

$hasCorrect = false;
foreach ($options as $o) {
    if ((int) $o['is_correct'] === 1) {
        $hasCorrect = true;
        break;
    }
}

$pageTitle = 'Editar pregunta';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="activity_edit.php?id=<?= (int) $question['activity_id'] ?>">&larr; Volver a "<?= e($question['activity_title']) ?>"</a></p>
<h1>Editar pregunta</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>
<?php if (!$hasCorrect): ?>
    <div class="alert alert-error">Esta pregunta todavía no tiene marcada una respuesta correcta.</div>
<?php endif; ?>

<div class="grid grid-2">
    <section class="card">
        <h2 style="margin-top:0;">Enunciado</h2>
        <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_question">

            <label for="statement">Pregunta</label>
            <textarea id="statement" name="statement" rows="3" required><?= e($question['statement']) ?></textarea>

            <label for="time_seconds">Tiempo (segundos)</label>
            <input type="text" id="time_seconds" name="time_seconds" value="<?= (int) $question['time_seconds'] ?>">

            <label for="points">Puntos</label>
            <input type="text" id="points" name="points" value="<?= (int) $question['points'] ?>">

            <label for="explanation">Explicación (se muestra después de responder)</label>
            <textarea id="explanation" name="explanation" rows="2"><?= e($question['explanation']) ?></textarea>

            <button type="submit" class="btn">Guardar</button>
        </form>
    </section>

    <section class="card">
        <h2 style="margin-top:0;">Opciones de respuesta</h2>
        <p class="text-muted" style="font-size:0.85rem;">Marca cuál es la respuesta correcta.</p>

        <?php foreach ($options as $o): ?>
            <div style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--color-border);">
                <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>" style="display:flex; align-items:center; gap:8px; flex:1;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="set_correct">
                    <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                    <input type="radio" name="_noop" style="width:auto;" onclick="this.form.submit()" <?= $o['is_correct'] ? 'checked' : '' ?>>
                    <span style="<?= $o['is_correct'] ? 'font-weight:600; color:var(--color-success);' : '' ?>"><?= e($o['text']) ?></span>
                </form>

                <?php if ($question['type'] === 'multiple'): ?>
                    <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_option">
                        <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                        <button type="submit" class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem; color:#C0392B; border-color:#C0392B;">Quitar</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($question['type'] === 'multiple'): ?>
            <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>" style="margin-top:16px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add_option">
                <label for="option_text">Nueva opción</label>
                <input type="text" id="option_text" name="option_text" required placeholder="Texto de la opción">
                <button type="submit" class="btn">Agregar opción</button>
            </form>
        <?php else: ?>
            <p class="text-muted" style="font-size:0.85rem; margin-top:12px;">
                Las preguntas de Verdadero/Falso tienen opciones fijas; solo puedes cambiar cuál es la correcta.
            </p>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
