<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php'; // reutiliza question_type_label()
require_once __DIR__ . '/../includes/quiz_helpers.php';
require_once __DIR__ . '/../includes/aiken_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$quizId = (int) ($_GET['id'] ?? 0);
$errors = [];
$notice = null;

$quiz = quiz_load_owned($pdo, $quizId, $teacherId);
if (!$quiz) {
    http_response_code(404);
    exit('Cuestionario no encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'update_meta') {
        $title = clean_string($_POST['title'] ?? '');
        $description = clean_string($_POST['description'] ?? '');
        $instructions = clean_string($_POST['instructions'] ?? '');
        $timeLimitRaw = trim($_POST['time_limit_minutes'] ?? '');
        $timeLimit = $timeLimitRaw === '' ? null : max(1, (int) $timeLimitRaw);
        $maxAttempts = max(1, (int) ($_POST['max_attempts'] ?? 1));
        $questionsPerAttemptRaw = trim($_POST['questions_per_attempt'] ?? '');
        $questionsPerAttempt = $questionsPerAttemptRaw === '' ? null : max(1, (int) $questionsPerAttemptRaw);

        if ($title === '') {
            $errors[] = 'El título no puede estar vacío.';
        } else {
            $pdo->prepare(
                'UPDATE quizzes SET title = :title, description = :description, instructions = :instructions,
                    time_limit_minutes = :time_limit_minutes, max_attempts = :max_attempts,
                    questions_per_attempt = :questions_per_attempt, updated_at = :updated_at
                 WHERE id = :id AND teacher_id = :teacher_id'
            )->execute([
                'title' => $title, 'description' => $description, 'instructions' => $instructions,
                'time_limit_minutes' => $timeLimit, 'max_attempts' => $maxAttempts,
                'questions_per_attempt' => $questionsPerAttempt,
                'updated_at' => now_datetime(), 'id' => $quizId, 'teacher_id' => $teacherId,
            ]);
            $notice = 'Datos guardados.';
            $quiz = quiz_load_owned($pdo, $quizId, $teacherId);
        }
    }

    if ($action === 'add_question') {
        $type = clean_string($_POST['type'] ?? 'multiple');
        $statement = clean_string($_POST['statement'] ?? '');
        $points = max(1, (int) ($_POST['points'] ?? 10));

        if (!in_array($type, ['multiple', 'truefalse'], true)) {
            $errors[] = 'Tipo de pregunta inválido.';
        } elseif ($statement === '') {
            $errors[] = 'El enunciado no puede estar vacío.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM quiz_questions WHERE quiz_id = :quiz_id');
            $countStmt->execute(['quiz_id' => $quizId]);
            $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO quiz_questions (quiz_id, type, statement, points, order_index, created_at)
                 VALUES (:quiz_id, :type, :statement, :points, :order_index, :created_at)'
            );
            $stmt->execute([
                'quiz_id' => $quizId, 'type' => $type, 'statement' => $statement,
                'points' => $points, 'order_index' => $orderIndex, 'created_at' => now_datetime(),
            ]);
            $questionId = (int) $pdo->lastInsertId();

            if ($type === 'truefalse') {
                $optStmt = $pdo->prepare(
                    'INSERT INTO quiz_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, :correct, :order_index)'
                );
                $optStmt->execute(['qid' => $questionId, 'text' => 'Verdadero', 'correct' => 1, 'order_index' => 0]);
                $optStmt->execute(['qid' => $questionId, 'text' => 'Falso', 'correct' => 0, 'order_index' => 1]);
            }

            $pdo->commit();

            if ($type === 'multiple') {
                redirect('teacher/quiz_question.php?id=' . $questionId);
            }
            $notice = 'Pregunta agregada.';
        }
    }

    if ($action === 'import_aiken') {
        if (!isset($_FILES['aiken_file']) || $_FILES['aiken_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'No se pudo subir el archivo. Verifica que hayas seleccionado un archivo .txt.';
        } elseif ($_FILES['aiken_file']['size'] > 1 * 1024 * 1024) {
            $errors[] = 'El archivo es demasiado grande (máximo 1 MB).';
        } else {
            $content = file_get_contents($_FILES['aiken_file']['tmp_name']);
            if ($content === false || trim($content) === '') {
                $errors[] = 'El archivo está vacío o no se pudo leer.';
            } else {
                $parsed = aiken_parse_questions($content);

                if (!empty($parsed['questions'])) {
                    $imported = aiken_import_into_quiz($pdo, $quizId, $parsed['questions'], 10);
                    $notice = $imported . ' pregunta(s) importada(s) correctamente desde el archivo Aiken.';
                } else {
                    $errors[] = 'No se encontró ninguna pregunta válida en el archivo.';
                }

                foreach ($parsed['errors'] as $err) {
                    $errors[] = $err;
                }
            }
        }
    }

    if ($action === 'delete_question') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $pdo->prepare('DELETE FROM quiz_questions WHERE id = :id AND quiz_id = :quiz_id')
            ->execute(['id' => $questionId, 'quiz_id' => $quizId]);
        $notice = 'Pregunta eliminada.';
    }

    if ($action === 'duplicate_question') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $qStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE id = :id AND quiz_id = :quiz_id');
        $qStmt->execute(['id' => $questionId, 'quiz_id' => $quizId]);
        $q = $qStmt->fetch();

        if ($q) {
            $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM quiz_questions WHERE quiz_id = :quiz_id');
            $countStmt->execute(['quiz_id' => $quizId]);
            $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);

            $insert = $pdo->prepare(
                'INSERT INTO quiz_questions (quiz_id, type, statement, points, order_index, created_at)
                 VALUES (:quiz_id, :type, :statement, :points, :order_index, :created_at)'
            );
            $insert->execute([
                'quiz_id' => $quizId, 'type' => $q['type'], 'statement' => $q['statement'] . ' (copia)',
                'points' => $q['points'], 'order_index' => $orderIndex, 'created_at' => now_datetime(),
            ]);
            $newQuestionId = (int) $pdo->lastInsertId();

            $optStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = :qid ORDER BY order_index');
            $optStmt->execute(['qid' => $questionId]);
            $insertOpt = $pdo->prepare(
                'INSERT INTO quiz_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, :correct, :order_index)'
            );
            foreach ($optStmt->fetchAll() as $o) {
                $insertOpt->execute(['qid' => $newQuestionId, 'text' => $o['text'], 'correct' => $o['is_correct'], 'order_index' => $o['order_index']]);
            }
            $notice = 'Pregunta duplicada.';
        }
    }

    if ($action === 'move_question') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';

        $listStmt = $pdo->prepare('SELECT id, order_index FROM quiz_questions WHERE quiz_id = :quiz_id ORDER BY order_index ASC, id ASC');
        $listStmt->execute(['quiz_id' => $quizId]);
        $list = $listStmt->fetchAll();

        $pos = null;
        foreach ($list as $i => $row) {
            if ((int) $row['id'] === $questionId) {
                $pos = $i;
                break;
            }
        }

        if ($pos !== null) {
            $swapWith = $direction === 'up' ? $pos - 1 : $pos + 1;
            if (isset($list[$swapWith])) {
                $a = $list[$pos];
                $b = $list[$swapWith];
                $pdo->prepare('UPDATE quiz_questions SET order_index = :idx WHERE id = :id')->execute(['idx' => $b['order_index'], 'id' => $a['id']]);
                $pdo->prepare('UPDATE quiz_questions SET order_index = :idx WHERE id = :id')->execute(['idx' => $a['order_index'], 'id' => $b['id']]);
            }
        }
    }

    if ($action === 'toggle_status') {
        $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM quiz_questions WHERE quiz_id = :quiz_id');
        $countStmt->execute(['quiz_id' => $quizId]);
        $totalQuestions = (int) ($countStmt->fetch()['total'] ?? 0);

        if ($quiz['status'] === 'draft' && $totalQuestions === 0) {
            $errors[] = 'Agrega al menos una pregunta antes de publicar.';
        } else {
            $newStatus = $quiz['status'] === 'draft' ? 'published' : 'draft';
            $pdo->prepare('UPDATE quizzes SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $quizId]);
            $quiz['status'] = $newStatus;
            $notice = $newStatus === 'published' ? 'Cuestionario publicado.' : 'Cuestionario puesto como borrador.';
        }
    }
}

$questions = quiz_fetch_questions($pdo, $quizId);

$pageTitle = $quiz['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="quizzes.php">&larr; Volver a mis cuestionarios</a></p>
<h1>📝 <?= e($quiz['title']) ?>
    <span class="text-muted" style="font-size:0.9rem; font-weight:400;">
        (<?= $quiz['status'] === 'published' ? 'Publicado' : 'Borrador' ?>)
    </span>
</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="grid grid-2">
    <section class="card">
        <h2 style="margin-top:0;">Datos generales</h2>
        <form method="post" action="quiz_edit.php?id=<?= (int) $quizId ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_meta">

            <label for="title">Título</label>
            <input type="text" id="title" name="title" required value="<?= e($quiz['title']) ?>">

            <label for="description">Descripción</label>
            <textarea id="description" name="description" rows="2"><?= e($quiz['description']) ?></textarea>

            <label for="instructions">Instrucciones para el estudiante</label>
            <textarea id="instructions" name="instructions" rows="2"><?= e($quiz['instructions']) ?></textarea>

            <label for="time_limit_minutes">Tiempo límite por intento (minutos, vacío = sin límite)</label>
            <input type="text" id="time_limit_minutes" name="time_limit_minutes" value="<?= $quiz['time_limit_minutes'] !== null ? (int) $quiz['time_limit_minutes'] : '' ?>" placeholder="Sin límite">

            <label for="max_attempts">Intentos permitidos</label>
            <input type="text" id="max_attempts" name="max_attempts" value="<?= (int) $quiz['max_attempts'] ?>">
            <p class="text-muted" style="font-size:0.8rem; margin-top:-8px;">
                Si permites más de uno, se guarda la mejor calificación obtenida entre todos los intentos.
            </p>

            <label for="questions_per_attempt">Preguntas al azar por intento (vacío = todas)</label>
            <input type="text" id="questions_per_attempt" name="questions_per_attempt"
                   value="<?= $quiz['questions_per_attempt'] !== null ? (int) $quiz['questions_per_attempt'] : '' ?>" placeholder="Todas las del banco">
            <p class="text-muted" style="font-size:0.8rem; margin-top:-8px;">
                Ej: si subís 20 preguntas y ponés 10 acá, cada estudiante responde 10 al azar de esas 20 —
                así les toca un subconjunto distinto a cada uno. El orden también se mezcla siempre.
            </p>

            <button type="submit" class="btn">Guardar datos</button>
        </form>

        <form method="post" action="quiz_edit.php?id=<?= (int) $quizId ?>" style="margin-top:12px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="toggle_status">
            <button type="submit" class="btn btn-secondary">
                <?= $quiz['status'] === 'draft' ? 'Publicar cuestionario' : 'Volver a borrador' ?>
            </button>
        </form>
        <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
            Solo los cuestionarios publicados se pueden asignar a los estudiantes, desde "Asignaciones".
        </p>
    </section>

    <section class="card">
        <h2 style="margin-top:0;">Preguntas (<?= count($questions) ?>)</h2>

        <div class="card" style="background:#F8FAFC; margin-bottom:16px;">
            <h3 style="margin-top:0;">Importar preguntas desde archivo Aiken</h3>
            <p class="text-muted" style="font-size:0.85rem;">
                Sube un archivo <code>.txt</code> con formato Aiken y se agregarán al final como preguntas de
                selección múltiple (10 pts cada una por defecto, editable después). Ejemplo de una pregunta:
            </p>
            <pre style="background:#fff; border:1px solid var(--color-border); border-radius:8px; padding:10px 14px; font-size:0.8rem; overflow-x:auto;">¿Cuál es la capital de Francia?
A) Madrid
B) París
C) Roma
D) Berlín
ANSWER: B</pre>
            <p class="text-muted" style="font-size:0.8rem;">
                Puedes poner varias preguntas en el mismo archivo, separadas por una línea en blanco.
            </p>
            <form method="post" action="quiz_edit.php?id=<?= (int) $quizId ?>" enctype="multipart/form-data" style="margin-top:12px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="import_aiken">
                <label for="aiken_file">Archivo (.txt)</label>
                <input type="file" id="aiken_file" name="aiken_file" accept=".txt,text/plain" required>
                <button type="submit" class="btn">Importar preguntas</button>
            </form>
        </div>

        <?php if (empty($questions)): ?>
            <p class="empty-state">Sin preguntas todavía.</p>
        <?php else: ?>
            <?php foreach ($questions as $i => $q): ?>
                <div class="card" style="margin-bottom:10px; padding:14px 16px;">
                    <div>
                        <strong><?= $i + 1 ?>. <?= e(truncate_text($q['statement'], 80)) ?></strong>
                        <p class="text-muted" style="margin:4px 0 0; font-size:0.85rem;">
                            <?= e(question_type_label($q['type'])) ?> ·
                            <?= (int) $q['points'] ?> pts ·
                            <?= count($q['options']) ?> opciones
                        </p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                        <a class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;" href="quiz_question.php?id=<?= (int) $q['id'] ?>">Editar</a>

                        <form method="post" action="quiz_edit.php?id=<?= (int) $quizId ?>" style="display:inline;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="duplicate_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;">Duplicar</button>
                        </form>

                        <?php if ($i > 0): ?>
                        <form method="post" action="quiz_edit.php?id=<?= (int) $quizId ?>" style="display:inline;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="move_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;">↑</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($i < count($questions) - 1): ?>
                        <form method="post" action="quiz_edit.php?id=<?= (int) $quizId ?>" style="display:inline;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="move_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;">↓</button>
                        </form>
                        <?php endif; ?>

                        <form method="post" action="quiz_edit.php?id=<?= (int) $quizId ?>" style="display:inline;" data-confirm="¿Eliminar esta pregunta?">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem; color:#C0392B; border-color:#C0392B;">Eliminar</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" action="quiz_edit.php?id=<?= (int) $quizId ?>" style="margin-top:16px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add_question">

            <label for="type">Tipo de pregunta</label>
            <select id="type" name="type">
                <option value="multiple">Selección múltiple</option>
                <option value="truefalse">Verdadero/Falso</option>
            </select>

            <label for="statement">Enunciado</label>
            <textarea id="statement" name="statement" rows="2" required placeholder="Escribe la pregunta..."></textarea>

            <label for="points">Puntos</label>
            <input type="text" id="points" name="points" value="10">

            <button type="submit" class="btn">Agregar pregunta</button>
            <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
                "Verdadero/Falso" se crea de inmediato. "Selección múltiple" te lleva a la pantalla de la pregunta
                para agregar las opciones y marcar la correcta.
            </p>
        </form>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
