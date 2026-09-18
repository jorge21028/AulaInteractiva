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

    if ($action === 'delete_option') {
        $optionId = (int) ($_POST['option_id'] ?? 0);
        $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM question_options WHERE question_id = :qid');
        $countStmt->execute(['qid' => $questionId]);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $minRequired = in_array($question['type'], ['ordenar', 'relacionar'], true) ? 2 : 1;

        if ($total <= $minRequired) {
            $errors[] = 'Debe quedar al menos ' . $minRequired . ' elemento(s).';
        } else {
            $pdo->prepare('DELETE FROM question_options WHERE id = :id AND question_id = :qid')
                ->execute(['id' => $optionId, 'qid' => $questionId]);
            $notice = 'Elemento eliminado.';
        }
    }

    if ($action === 'set_correct') {
        $optionId = (int) ($_POST['option_id'] ?? 0);
        $pdo->prepare('UPDATE question_options SET is_correct = 0 WHERE question_id = :qid')->execute(['qid' => $questionId]);
        $pdo->prepare('UPDATE question_options SET is_correct = 1 WHERE id = :id AND question_id = :qid')
            ->execute(['id' => $optionId, 'qid' => $questionId]);
        $notice = 'Respuesta correcta actualizada.';
    }

    // ---- Preguntas de "completar": editar la respuesta correcta ----
    if ($action === 'update_completar_answer' && $question['type'] === 'completar') {
        $text = clean_string($_POST['correct_answer'] ?? '');
        if ($text === '') {
            $errors[] = 'La respuesta correcta no puede estar vacía.';
        } else {
            $pdo->prepare('UPDATE question_options SET text = :text, is_correct = 1 WHERE question_id = :qid')
                ->execute(['text' => $text, 'qid' => $questionId]);
            $notice = 'Respuesta correcta actualizada.';
        }
    }

    // ---- Preguntas de "ordenar": agregar / editar texto / mover ----
    if ($action === 'add_order_item' && $question['type'] === 'ordenar') {
        $text = clean_string($_POST['item_text'] ?? '');
        if ($text === '') {
            $errors[] = 'El texto del elemento no puede estar vacío.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM question_options WHERE question_id = :qid');
            $countStmt->execute(['qid' => $questionId]);
            $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);
            $pdo->prepare(
                'INSERT INTO question_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, 1, :order_index)'
            )->execute(['qid' => $questionId, 'text' => $text, 'order_index' => $orderIndex]);
            $notice = 'Elemento agregado al final del orden correcto.';
        }
    }

    if ($action === 'update_item_text' && in_array($question['type'], ['ordenar'], true)) {
        $optionId = (int) ($_POST['option_id'] ?? 0);
        $text = clean_string($_POST['item_text'] ?? '');
        if ($text === '') {
            $errors[] = 'El texto no puede estar vacío.';
        } else {
            $pdo->prepare('UPDATE question_options SET text = :text WHERE id = :id AND question_id = :qid')
                ->execute(['text' => $text, 'id' => $optionId, 'qid' => $questionId]);
            $notice = 'Elemento actualizado.';
        }
    }

    if ($action === 'move_option' && $question['type'] === 'ordenar') {
        $optionId = (int) ($_POST['option_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';

        $listStmt = $pdo->prepare('SELECT id, order_index FROM question_options WHERE question_id = :qid ORDER BY order_index ASC, id ASC');
        $listStmt->execute(['qid' => $questionId]);
        $list = $listStmt->fetchAll();

        $pos = null;
        foreach ($list as $i => $row) {
            if ((int) $row['id'] === $optionId) {
                $pos = $i;
                break;
            }
        }

        if ($pos !== null) {
            $swapWith = $direction === 'up' ? $pos - 1 : $pos + 1;
            if (isset($list[$swapWith])) {
                $a = $list[$pos];
                $b = $list[$swapWith];
                $pdo->prepare('UPDATE question_options SET order_index = :idx WHERE id = :id')->execute(['idx' => $b['order_index'], 'id' => $a['id']]);
                $pdo->prepare('UPDATE question_options SET order_index = :idx WHERE id = :id')->execute(['idx' => $a['order_index'], 'id' => $b['id']]);
            }
        }
    }

    // ---- Preguntas de "relacionar": agregar / editar parejas ----
    if ($action === 'add_pair' && $question['type'] === 'relacionar') {
        $left = clean_string($_POST['pair_left'] ?? '');
        $right = clean_string($_POST['pair_right'] ?? '');
        if ($left === '' || $right === '') {
            $errors[] = 'Ambos lados de la pareja son obligatorios.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM question_options WHERE question_id = :qid');
            $countStmt->execute(['qid' => $questionId]);
            $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);
            $pdo->prepare(
                'INSERT INTO question_options (question_id, text, match_text, is_correct, order_index) VALUES (:qid, :text, :match_text, 1, :order_index)'
            )->execute(['qid' => $questionId, 'text' => $left, 'match_text' => $right, 'order_index' => $orderIndex]);
            $notice = 'Pareja agregada.';
        }
    }

    if ($action === 'update_pair' && $question['type'] === 'relacionar') {
        $optionId = (int) ($_POST['option_id'] ?? 0);
        $left = clean_string($_POST['pair_left'] ?? '');
        $right = clean_string($_POST['pair_right'] ?? '');
        if ($left === '' || $right === '') {
            $errors[] = 'Ambos lados de la pareja son obligatorios.';
        } else {
            $pdo->prepare('UPDATE question_options SET text = :text, match_text = :match_text WHERE id = :id AND question_id = :qid')
                ->execute(['text' => $left, 'match_text' => $right, 'id' => $optionId, 'qid' => $questionId]);
            $notice = 'Pareja actualizada.';
        }
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

        <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--color-border);">
            <label style="margin-top:0;">Imagen de la pregunta (opcional)</label>
            <div id="question-image-preview" style="margin-bottom:10px;">
                <?php if ($question['image_path']): ?>
                    <img src="<?= e(rtrim(APP_URL, '/')) ?>/<?= e($question['image_path']) ?>" style="max-width:100%; max-height:200px; border-radius:8px; display:block;">
                <?php else: ?>
                    <p class="text-muted" style="font-size:0.85rem;">Sin imagen.</p>
                <?php endif; ?>
            </div>
            <input type="file" id="question-image-file" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none;">
            <button type="button" class="btn btn-secondary" id="btn-upload-question-image" style="margin:0;">Subir imagen</button>
            <button type="button" class="btn btn-secondary" id="btn-remove-question-image" style="margin:0; <?= $question['image_path'] ? '' : 'display:none;' ?>">Quitar imagen</button>
            <span class="text-muted" id="question-image-status" style="font-size:0.85rem; display:block; margin-top:6px;"></span>
        </div>
    </section>

    <script>
    const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
    const QUESTION_ID = <?= (int) $questionId ?>;
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

    document.getElementById('btn-upload-question-image').addEventListener('click', () => {
        document.getElementById('question-image-file').click();
    });

    document.getElementById('question-image-file').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const statusEl = document.getElementById('question-image-status');
        statusEl.textContent = 'Subiendo...';

        const formData = new FormData();
        formData.append('image', file);
        formData.append('question_id', QUESTION_ID);
        formData.append('action', 'upload');
        formData.append('csrf_token', CSRF_TOKEN);

        try {
            const res = await fetch(`${AULA_APP_URL}/api/activities/upload_question_image.php`, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                document.getElementById('question-image-preview').innerHTML =
                    `<img src="${data.image_url}" style="max-width:100%; max-height:200px; border-radius:8px; display:block;">`;
                document.getElementById('btn-remove-question-image').style.display = 'inline-block';
                statusEl.textContent = 'Imagen guardada.';
            } else {
                statusEl.textContent = data.message || 'No se pudo subir la imagen.';
            }
        } catch (err) {
            statusEl.textContent = 'No se pudo subir la imagen (revisa tu conexión).';
        }
        e.target.value = '';
    });

    document.getElementById('btn-remove-question-image').addEventListener('click', async () => {
        if (!confirm('¿Quitar la imagen de esta pregunta?')) return;
        const formData = new FormData();
        formData.append('question_id', QUESTION_ID);
        formData.append('action', 'remove');
        formData.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(`${AULA_APP_URL}/api/activities/upload_question_image.php`, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            document.getElementById('question-image-preview').innerHTML = '<p class="text-muted" style="font-size:0.85rem;">Sin imagen.</p>';
            document.getElementById('btn-remove-question-image').style.display = 'none';
            document.getElementById('question-image-status').textContent = 'Imagen eliminada.';
        }
    });
    </script>

    <section class="card">
        <?php if ($question['type'] === 'multiple' || $question['type'] === 'truefalse'): ?>
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

        <?php elseif ($question['type'] === 'ordenar'): ?>
            <h2 style="margin-top:0;">Elementos en el orden correcto</h2>
            <p class="text-muted" style="font-size:0.85rem;">
                El orden en que aparecen aquí ES el orden correcto. Usa ↑/↓ para reordenar. Al estudiante se le
                mostrarán desordenados.
            </p>

            <?php foreach ($options as $i => $o): ?>
                <div style="display:flex; align-items:center; gap:8px; padding:8px 0; border-bottom:1px solid var(--color-border);">
                    <strong style="width:24px;"><?= $i + 1 ?>.</strong>
                    <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>" style="flex:1; display:flex; gap:6px;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="update_item_text">
                        <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                        <input type="text" name="item_text" value="<?= e($o['text']) ?>" style="flex:1;">
                        <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 10px; font-size:0.8rem;">Guardar</button>
                    </form>
                    <div style="display:flex; gap:4px;">
                        <?php if ($i > 0): ?>
                        <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="move_option">
                            <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:4px 8px;">↑</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($i < count($options) - 1): ?>
                        <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="move_option">
                            <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:4px 8px;">↓</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_option">
                            <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:4px 8px; color:#C0392B; border-color:#C0392B;">✕</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>" style="margin-top:16px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add_order_item">
                <label for="item_text">Agregar elemento al final</label>
                <input type="text" id="item_text" name="item_text" required placeholder="Ej: Segunda Guerra Mundial">
                <button type="submit" class="btn">Agregar</button>
            </form>

        <?php elseif ($question['type'] === 'relacionar'): ?>
            <h2 style="margin-top:0;">Parejas correctas</h2>
            <p class="text-muted" style="font-size:0.85rem;">
                Cada fila es una pareja correcta. Al estudiante se le mostrará la columna derecha desordenada.
            </p>

            <?php foreach ($options as $o): ?>
                <div style="display:flex; gap:8px; align-items:center; padding:8px 0; border-bottom:1px solid var(--color-border);">
                    <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>" style="display:flex; gap:8px; align-items:center; flex:1;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="update_pair">
                        <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                        <input type="text" name="pair_left" value="<?= e($o['text']) ?>" placeholder="Izquierda" style="flex:1;">
                        <span class="text-muted">&harr;</span>
                        <input type="text" name="pair_right" value="<?= e($o['match_text'] ?? '') ?>" placeholder="Derecha" style="flex:1;">
                        <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 10px; font-size:0.8rem;">Guardar</button>
                    </form>
                    <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>" onsubmit="return confirm('¿Eliminar esta pareja?')">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_option">
                        <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                        <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 8px; color:#C0392B; border-color:#C0392B;">✕</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>" style="margin-top:16px; display:flex; gap:8px; align-items:flex-end;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add_pair">
                <div style="flex:1;">
                    <label for="pair_left">Izquierda</label>
                    <input type="text" id="pair_left" name="pair_left" required>
                </div>
                <div style="flex:1;">
                    <label for="pair_right">Derecha</label>
                    <input type="text" id="pair_right" name="pair_right" required>
                </div>
                <button type="submit" class="btn">Agregar pareja</button>
            </form>

        <?php elseif ($question['type'] === 'completar'): ?>
            <h2 style="margin-top:0;">Respuesta correcta</h2>
            <p class="text-muted" style="font-size:0.85rem;">
                Recuerda que el enunciado debe incluir el espacio en blanco (ej: "____").
            </p>
            <form method="post" action="activity_question.php?id=<?= (int) $questionId ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update_completar_answer">
                <label for="correct_answer">Respuesta correcta</label>
                <input type="text" id="correct_answer" name="correct_answer" required value="<?= e($options[0]['text'] ?? '') ?>">
                <button type="submit" class="btn">Guardar</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
