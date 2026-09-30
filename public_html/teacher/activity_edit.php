<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';
require_once __DIR__ . '/../includes/aiken_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$activityId = (int) ($_GET['id'] ?? 0);
$errors = [];
$notice = null;

function load_owned_activity(PDO $pdo, int $activityId, int $teacherId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM activities WHERE id = :id AND teacher_id = :teacher_id LIMIT 1');
    $stmt->execute(['id' => $activityId, 'teacher_id' => $teacherId]);
    return $stmt->fetch() ?: null;
}

$activity = load_owned_activity($pdo, $activityId, $teacherId);
if (!$activity) {
    http_response_code(404);
    exit('Actividad no encontrada.');
}

$isWordGame = game_mode_is_word_game($activity['game_mode']); // ahorcado / crucigrama

// Asignaturas del profesor (para poder mover la actividad a otra)
$subjStmt = $pdo->prepare(
    'SELECT s.id, s.name, c.name AS course_name
     FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE tc.teacher_id = :teacher_id
     ORDER BY c.name, s.name'
);
$subjStmt->execute(['teacher_id' => $teacherId]);
$subjects = $subjStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'update_meta') {
        $title = clean_string($_POST['title'] ?? '');
        $description = clean_string($_POST['description'] ?? '');
        $instructions = clean_string($_POST['instructions'] ?? '');
        $difficulty = clean_string($_POST['difficulty'] ?? 'media');
        if ($isWordGame) {
            // Ahorcado / crucigrama: sin cronómetro, sin bonos ni ranking; se conservan los valores internos.
            $timePerQuestion = (int) $activity['time_per_question'];
            $pointsBase = (int) $activity['points_base'];
            $speedBonusMax = (int) $activity['speed_bonus_max'];
            $rankingEnabled = (int) $activity['ranking_enabled'];
        } else {
            $timePerQuestion = max(5, (int) ($_POST['time_per_question'] ?? 20));
            $pointsBase = max(10, (int) ($_POST['points_base'] ?? 100));
            $speedBonusMax = max(0, (int) ($_POST['speed_bonus_max'] ?? 50));
            $rankingEnabled = isset($_POST['ranking_enabled']) ? 1 : 0;
        }
        $allowRepeat = isset($_POST['allow_repeat']) ? 1 : 0;
        $teamMode = isset($_POST['team_mode']) ? 1 : 0;
        $subjectId = (int) ($_POST['subject_id'] ?? $activity['subject_id']);

        $validSubject = false;
        foreach ($subjects as $s) {
            if ((int) $s['id'] === $subjectId) {
                $validSubject = true;
                break;
            }
        }

        if ($title === '') {
            $errors[] = 'El título no puede estar vacío.';
        } elseif (!in_array($difficulty, ['facil', 'media', 'dificil'], true)) {
            $errors[] = 'Dificultad inválida.';
        } elseif (!$validSubject) {
            $errors[] = 'Selecciona una asignatura válida.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE activities SET title = :title, description = :description, instructions = :instructions,
                    subject_id = :subject_id,
                    difficulty = :difficulty, time_per_question = :time_per_question, points_base = :points_base,
                    speed_bonus_max = :speed_bonus_max, ranking_enabled = :ranking_enabled, allow_repeat = :allow_repeat,
                    team_mode = :team_mode, updated_at = :updated_at
                 WHERE id = :id AND teacher_id = :teacher_id'
            );
            $stmt->execute([
                'title' => $title, 'description' => $description, 'instructions' => $instructions,
                'subject_id' => $subjectId,
                'difficulty' => $difficulty, 'time_per_question' => $timePerQuestion, 'points_base' => $pointsBase,
                'speed_bonus_max' => $speedBonusMax, 'ranking_enabled' => $rankingEnabled, 'allow_repeat' => $allowRepeat,
                'team_mode' => $teamMode, 'updated_at' => now_datetime(), 'id' => $activityId, 'teacher_id' => $teacherId,
            ]);
            $notice = 'Datos guardados.';
            $activity = load_owned_activity($pdo, $activityId, $teacherId);
        }
    }

    if ($action === 'add_word') {
        if (!$isWordGame) {
            $errors[] = 'Esta actividad no es un juego de palabras.';
        } else {
            $clue = clean_string($_POST['clue'] ?? '');
            $word = clean_string($_POST['word'] ?? '');
            $timeSeconds = max(5, (int) ($_POST['time_seconds'] ?? $activity['time_per_question']));
            $points = max(10, (int) ($_POST['points'] ?? $activity['points_base']));
            $wordError = word_validate_for_mode($word, $activity['game_mode']);

            if ($clue === '') {
                $errors[] = 'La pista no puede estar vacía.';
            } elseif ($wordError !== null) {
                $errors[] = $wordError;
            } else {
                $res = aiken_import_words_into_activity(
                    $pdo, $activityId, [['clue' => $clue, 'word' => $word]], $timeSeconds, $points, $activity['game_mode']
                );
                if ($res['inserted'] > 0) {
                    $notice = 'Palabra agregada.';
                } else {
                    $errors[] = 'Esa palabra ya está en el crucigrama.';
                }
            }
        }
    }

    if ($action === 'add_question' && !$isWordGame) {
        $type = clean_string($_POST['type'] ?? 'multiple');
        $statement = clean_string($_POST['statement'] ?? '');
        $timeSeconds = max(5, (int) ($_POST['time_seconds'] ?? $activity['time_per_question']));
        $points = max(10, (int) ($_POST['points'] ?? $activity['points_base']));
        $correctAnswer = clean_string($_POST['correct_answer'] ?? ''); // solo para 'completar'

        // "El Sapito" es siempre de opción única: el estudiante arrastra la
        // respuesta al nenúfar correcto, así que forzamos el tipo sin importar
        // lo que llegue del formulario (defensa en el servidor, no solo en la UI).
        if ($activity['game_mode'] === 'sapito') {
            $type = 'multiple';
        }

        // "Ordenar", "relacionar" y "completar" fueron descontinuados: no funcionaban
        // bien en la práctica. Se dejan solo para no romper actividades ya creadas
        // con esos tipos, pero ya no se pueden crear preguntas nuevas de esos tipos.
        $validTypes = ['multiple', 'truefalse'];

        if (!in_array($type, $validTypes, true)) {
            $errors[] = 'Tipo de pregunta inválido.';
        } elseif ($statement === '') {
            $errors[] = 'El enunciado no puede estar vacío.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM activity_questions WHERE activity_id = :activity_id');
            $countStmt->execute(['activity_id' => $activityId]);
            $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO activity_questions (activity_id, type, statement, time_seconds, points, order_index, created_at)
                 VALUES (:activity_id, :type, :statement, :time_seconds, :points, :order_index, :created_at)'
            );
            $stmt->execute([
                'activity_id' => $activityId, 'type' => $type, 'statement' => $statement,
                'time_seconds' => $timeSeconds, 'points' => $points, 'order_index' => $orderIndex,
                'created_at' => now_datetime(),
            ]);
            $questionId = (int) $pdo->lastInsertId();

            if ($type === 'truefalse') {
                $optStmt = $pdo->prepare(
                    'INSERT INTO question_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, :correct, :order_index)'
                );
                $optStmt->execute(['qid' => $questionId, 'text' => 'Verdadero', 'correct' => 1, 'order_index' => 0]);
                $optStmt->execute(['qid' => $questionId, 'text' => 'Falso', 'correct' => 0, 'order_index' => 1]);
            }

            $pdo->commit();

            if ($type === 'multiple') {
                redirect('teacher/activity_question.php?id=' . $questionId);
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
                if ($isWordGame) {
                    $parsedWords = aiken_parse_words($content, $activity['game_mode']);

                    if (!empty($parsedWords['items'])) {
                        $res = aiken_import_words_into_activity(
                            $pdo, $activityId, $parsedWords['items'],
                            (int) $activity['time_per_question'], (int) $activity['points_base'], $activity['game_mode']
                        );
                        $notice = $res['inserted'] . ' palabra(s) importada(s) correctamente desde el archivo Aiken.';
                        foreach ($res['skipped'] as $dup) {
                            $errors[] = 'La palabra "' . $dup . '" ya estaba en el crucigrama; se omitió.';
                        }
                    } else {
                        $errors[] = 'No se encontró ninguna palabra válida en el archivo.';
                    }

                    foreach ($parsedWords['errors'] as $err) {
                        $errors[] = $err;
                    }
                } else {
                    $parsed = aiken_parse_questions($content);

                    if (!empty($parsed['questions'])) {
                        $imported = aiken_import_into_activity(
                            $pdo, $activityId, $parsed['questions'],
                            (int) $activity['time_per_question'], (int) $activity['points_base']
                        );
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
    }

    if ($action === 'delete_question') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $del = $pdo->prepare('DELETE FROM activity_questions WHERE id = :id AND activity_id = :activity_id');
        $del->execute(['id' => $questionId, 'activity_id' => $activityId]);
        $notice = 'Pregunta eliminada.';
    }

    if ($action === 'duplicate_question') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $qStmt = $pdo->prepare('SELECT * FROM activity_questions WHERE id = :id AND activity_id = :activity_id');
        $qStmt->execute(['id' => $questionId, 'activity_id' => $activityId]);
        $q = $qStmt->fetch();

        if ($q && $isWordGame) {
            $errors[] = 'En los juegos de palabras no se duplican palabras (no puede haber dos iguales).';
        } elseif ($q && !in_array($q['type'], ['ordenar', 'relacionar', 'completar'], true)) {
            $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM activity_questions WHERE activity_id = :activity_id');
            $countStmt->execute(['activity_id' => $activityId]);
            $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);

            $insert = $pdo->prepare(
                'INSERT INTO activity_questions (activity_id, type, statement, time_seconds, points, explanation, order_index, created_at)
                 VALUES (:activity_id, :type, :statement, :time_seconds, :points, :explanation, :order_index, :created_at)'
            );
            $insert->execute([
                'activity_id' => $activityId, 'type' => $q['type'], 'statement' => $q['statement'] . ' (copia)',
                'time_seconds' => $q['time_seconds'], 'points' => $q['points'], 'explanation' => $q['explanation'],
                'order_index' => $orderIndex, 'created_at' => now_datetime(),
            ]);
            $newQuestionId = (int) $pdo->lastInsertId();

            $optStmt = $pdo->prepare('SELECT * FROM question_options WHERE question_id = :qid ORDER BY order_index');
            $optStmt->execute(['qid' => $questionId]);
            $insertOpt = $pdo->prepare(
                'INSERT INTO question_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, :correct, :order_index)'
            );
            foreach ($optStmt->fetchAll() as $o) {
                $insertOpt->execute(['qid' => $newQuestionId, 'text' => $o['text'], 'correct' => $o['is_correct'], 'order_index' => $o['order_index']]);
            }
            $notice = 'Pregunta duplicada.';
        } elseif ($q) {
            $errors[] = 'Este tipo de pregunta ya no se puede duplicar (fue descontinuado). Puedes eliminarla o dejarla como está.';
        }
    }

    if ($action === 'move_question') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';

        $listStmt = $pdo->prepare('SELECT id, order_index FROM activity_questions WHERE activity_id = :activity_id ORDER BY order_index ASC, id ASC');
        $listStmt->execute(['activity_id' => $activityId]);
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
                $pdo->prepare('UPDATE activity_questions SET order_index = :idx WHERE id = :id')->execute(['idx' => $b['order_index'], 'id' => $a['id']]);
                $pdo->prepare('UPDATE activity_questions SET order_index = :idx WHERE id = :id')->execute(['idx' => $a['order_index'], 'id' => $b['id']]);
            }
        }
    }

    if ($action === 'toggle_status') {
        $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM activity_questions WHERE activity_id = :activity_id');
        $countStmt->execute(['activity_id' => $activityId]);
        $totalQuestions = (int) ($countStmt->fetch()['total'] ?? 0);

        if ($activity['status'] === 'draft' && $totalQuestions === 0) {
            $errors[] = $isWordGame ? 'Agrega al menos una palabra antes de publicar.' : 'Agrega al menos una pregunta antes de publicar.';
        } elseif ($activity['status'] === 'draft' && $activity['game_mode'] === 'crucigrama'
            && count(crossword_build_layout(activity_fetch_questions($pdo, $activityId))['words']) < 2) {
            $errors[] = 'Un crucigrama necesita al menos 2 palabras que se crucen entre sí. Agrega más palabras (con letras en común).';
        } else {
            $newStatus = $activity['status'] === 'draft' ? 'published' : 'draft';
            $pdo->prepare('UPDATE activities SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $activityId]);
            $activity['status'] = $newStatus;
            $notice = $newStatus === 'published' ? 'Actividad publicada.' : 'Actividad puesta como borrador.';
        }
    }
}

$questions = activity_fetch_questions($pdo, $activityId);

$pageTitle = $activity['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="activities.php">&larr; Volver a mis actividades</a></p>
<h1><?= e(game_mode_icon($activity['game_mode'])) ?> <?= e($activity['title']) ?>
    <span class="text-muted" style="font-size:0.9rem; font-weight:400;">
        (<?= $activity['status'] === 'published' ? 'Publicada' : 'Borrador' ?>)
    </span>
</h1>
<?php if ($activity['game_mode'] === 'sapito'): ?>
    <p class="text-muted" style="margin-top:-8px;">
        Actividad de integración "El Sapito": el estudiante arrastra la respuesta hasta el nenúfar correcto.
        Cada pregunta tiene un enunciado y varias opciones (nenúfares); una es la correcta.
    </p>
<?php elseif ($activity['game_mode'] === 'ahorcado'): ?>
    <p class="text-muted" style="margin-top:-8px;">
        Actividad "Ahorcado" individual: cada palabra tiene una pista y su respuesta. El estudiante la juega a su ritmo
        (sin cronómetro, 6 vidas por palabra) desde su asignación. Nota = palabras adivinadas ÷ total de palabras.
    </p>
<?php elseif ($activity['game_mode'] === 'crucigrama'): ?>
    <p class="text-muted" style="margin-top:-8px;">
        Actividad "Crucigrama" individual: escribes las pistas y sus respuestas y el tablero se arma solo (las palabras se cruzan
        por las letras que tienen en común). El estudiante lo resuelve a su ritmo, sin cronómetro, y su avance se guarda solo.
        Nota = palabras acertadas ÷ total de palabras.
    </p>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="grid grid-2">
    <section class="card">
        <h2 style="margin-top:0;">Datos generales</h2>
        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_meta">

            <label for="title">Título</label>
            <input type="text" id="title" name="title" required value="<?= e($activity['title']) ?>">

            <label for="subject_id">Asignatura</label>
            <select id="subject_id" name="subject_id">
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) $activity['subject_id'] === (int) $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['course_name']) ?> — <?= e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="description">Descripción</label>
            <textarea id="description" name="description" rows="3"><?= e($activity['description']) ?></textarea>

            <label for="instructions">Instrucciones</label>
            <textarea id="instructions" name="instructions" rows="2"><?= e($activity['instructions']) ?></textarea>

            <label for="difficulty">Dificultad</label>
            <select id="difficulty" name="difficulty">
                <?php foreach (['facil' => 'Fácil', 'media' => 'Media', 'dificil' => 'Difícil'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $activity['difficulty'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>

            <?php if (!$isWordGame): ?>
            <label for="time_per_question">Tiempo por pregunta por defecto (segundos)</label>
            <input type="text" id="time_per_question" name="time_per_question" value="<?= (int) $activity['time_per_question'] ?>">

            <label for="points_base">Puntos base por pregunta por defecto</label>
            <input type="text" id="points_base" name="points_base" value="<?= (int) $activity['points_base'] ?>">

            <label for="speed_bonus_max">Bonificación máxima por rapidez</label>
            <input type="text" id="speed_bonus_max" name="speed_bonus_max" value="<?= (int) $activity['speed_bonus_max'] ?>">

            <label style="display:flex; align-items:center; gap:8px; margin-top:16px;">
                <input type="checkbox" name="ranking_enabled" style="width:auto;" <?= $activity['ranking_enabled'] ? 'checked' : '' ?>>
                Mostrar ranking
            </label>
            <?php endif; ?>
            <label style="display:flex; align-items:center; gap:8px;<?= $isWordGame ? ' margin-top:16px;' : '' ?>">
                <input type="checkbox" name="allow_repeat" style="width:auto;" <?= $activity['allow_repeat'] ? 'checked' : '' ?>>
                <?= $isWordGame ? 'Permitir que el estudiante repita la actividad (se conserva su mejor nota)' : 'Permitir repetir la partida' ?>
            </label>
            <?php if (!$isWordGame): ?>
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" name="team_mode" style="width:auto;" <?= $activity['team_mode'] ? 'checked' : '' ?>>
                Modo por equipos (próximamente)
            </label>
            <?php endif; ?>

            <button type="submit" class="btn">Guardar datos</button>
        </form>

        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="margin-top:12px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="toggle_status">
            <button type="submit" class="btn btn-secondary">
                <?= $activity['status'] === 'draft' ? 'Publicar actividad' : 'Volver a borrador' ?>
            </button>
        </form>

        <?php if ($isWordGame): ?>
            <div class="alert alert-success" style="margin-top:12px; font-size:0.9rem;">
                Esta actividad <strong>no se inicia en vivo</strong> y no tiene cronómetro. Publícala y asígnala desde
                <a href="assignments.php">Asignaciones</a>: los estudiantes podrán jugarla a su ritmo apenas la vean asignada.
            </div>
        <?php elseif ($activity['status'] === 'published'): ?>
            <a class="btn" style="margin-top:12px; display:inline-block;" href="host.php?activity_id=<?= (int) $activityId ?>">
                Iniciar partida
            </a>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 style="margin-top:0;"><?= $isWordGame ? 'Palabras' : 'Preguntas' ?> (<?= count($questions) ?>)</h2>

        <?php if (!$isWordGame): ?>
        <div class="card" style="background:#F8FAFC; margin-bottom:16px;">
            <h3 style="margin-top:0;">Importar preguntas desde archivo Aiken</h3>
            <p class="text-muted" style="font-size:0.85rem;">
                Sube un archivo <code>.txt</code> con formato Aiken y se agregarán al final como preguntas de
                selección múltiple (con tiempo <?= (int) $activity['time_per_question'] ?>s y
                <?= (int) $activity['points_base'] ?> pts por defecto, editables después). Ejemplo de una pregunta:
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
            <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" enctype="multipart/form-data" style="margin-top:12px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="import_aiken">
                <label for="aiken_file">Archivo (.txt)</label>
                <input type="file" id="aiken_file" name="aiken_file" accept=".txt,text/plain" required>
                <button type="submit" class="btn">Importar preguntas</button>
            </form>
        </div>
        <?php else: ?>
        <div class="card" style="background:#F8FAFC; margin-bottom:16px;">
            <h3 style="margin-top:0;">Importar palabras desde archivo Aiken</h3>
            <p class="text-muted" style="font-size:0.85rem;">
                Sube un archivo <code>.txt</code>. El enunciado es la <strong>pista</strong> y la respuesta es la
                <strong>palabra</strong>. Puedes usar el Aiken de siempre (la palabra es el texto de la opción correcta):
            </p>
            <pre style="background:#fff; border:1px solid var(--color-border); border-radius:8px; padding:10px 14px; font-size:0.8rem; overflow-x:auto;">Capital de Francia
A) Madrid
B) París
C) Roma
ANSWER: B</pre>
            <p class="text-muted" style="font-size:0.85rem;">…o la forma corta, con la palabra directamente:</p>
            <pre style="background:#fff; border:1px solid var(--color-border); border-radius:8px; padding:10px 14px; font-size:0.8rem; overflow-x:auto;">Proceso por el que las plantas fabrican su alimento
ANSWER: Fotosíntesis</pre>
            <p class="text-muted" style="font-size:0.8rem;">
                Separa cada palabra con una línea en blanco. Las tildes no importan al jugar (los estudiantes escriben sin tilde).
                <?php if ($activity['game_mode'] === 'crucigrama'): ?>
                    En el crucigrama las palabras deben tener de 2 a 15 letras, sin números ni símbolos, y no pueden repetirse.
                <?php else: ?>
                    En el ahorcado puedes usar frases cortas (hasta 40 caracteres).
                <?php endif; ?>
            </p>
            <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" enctype="multipart/form-data" style="margin-top:12px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="import_aiken">
                <label for="aiken_file">Archivo (.txt)</label>
                <input type="file" id="aiken_file" name="aiken_file" accept=".txt,text/plain" required>
                <button type="submit" class="btn">Importar palabras</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (empty($questions)): ?>
            <p class="empty-state">Sin preguntas todavía.</p>
        <?php else: ?>
            <?php foreach ($questions as $i => $q): ?>
                <?php $isDeprecatedType = in_array($q['type'], ['ordenar', 'relacionar', 'completar'], true); ?>
                <div class="card" style="margin-bottom:10px; padding:14px 16px;">
                    <div style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
                        <div>
                            <strong><?= $i + 1 ?>. <?= $q['type'] === 'palabra' ? '💡 ' : '' ?><?= e(truncate_text($q['statement'], 80)) ?></strong>
                            <p class="text-muted" style="margin:4px 0 0; font-size:0.85rem;">
                                <?php if ($q['type'] === 'palabra'): ?>
                                    Respuesta: <strong><?= e($q['options'][0]['text'] ?? '—') ?></strong>
                                <?php else: ?>
                                    <?= e(question_type_label($q['type'])) ?> ·
                                    <?= (int) $q['time_seconds'] ?>s · <?= (int) $q['points'] ?> pts ·
                                    <?= count($q['options']) ?> opciones
                                <?php endif; ?>
                            </p>
                            <?php if ($isDeprecatedType): ?>
                                <p class="alert alert-error" style="margin:6px 0 0; padding:6px 10px; font-size:0.8rem;">
                                    Este tipo de pregunta fue descontinuado (no siempre funcionaba bien). Te recomendamos
                                    eliminarla y crear una de selección múltiple en su lugar.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                        <a class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;" href="activity_question.php?id=<?= (int) $q['id'] ?>">Editar</a>

                        <?php if (!$isDeprecatedType && !$isWordGame): ?>
                        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="display:inline;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="duplicate_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;">Duplicar</button>
                        </form>
                        <?php endif; ?>

                        <?php if ($i > 0): ?>
                        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="display:inline;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="move_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;">↑</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($i < count($questions) - 1): ?>
                        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="display:inline;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="move_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;">↓</button>
                        </form>
                        <?php endif; ?>

                        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="display:inline;" data-confirm="¿Eliminar esta pregunta?">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem; color:#C0392B; border-color:#C0392B;">Eliminar</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($activity['game_mode'] === 'crucigrama' && !empty($questions)):
            $cwLayout = crossword_build_layout($questions);
            if (!empty($cwLayout['words'])): ?>
            <div class="card" style="background:#F8FAFC; margin-top:16px;">
                <h3 style="margin-top:0;">Vista previa del tablero (con respuestas)</h3>
                <p class="text-muted" style="font-size:0.85rem; margin-top:-6px;">
                    <?= count($cwLayout['words']) ?> palabras colocadas · <?= (int) $cwLayout['rows'] ?> × <?= (int) $cwLayout['cols'] ?> casillas.
                    El tablero es siempre el mismo para todos los estudiantes y cambia si agregas o quitas palabras.
                </p>
                <?= crossword_render_solution_html($cwLayout) ?>
                <?php if (!empty($cwLayout['unplaced'])): ?>
                    <div class="alert alert-error" style="font-size:0.85rem;">
                        No se pudieron cruzar con el resto (no comparten letras con las demás palabras) y <strong>no aparecerán</strong> en el juego:
                        <?php foreach ($cwLayout['unplaced'] as $up): ?>
                            <strong><?= e($up['word']) ?></strong>
                        <?php endforeach; ?>
                        . Cambia la palabra o agrega otras que compartan letras con ellas.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; endif; ?>

        <?php if ($isWordGame): ?>
        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="margin-top:16px;" id="add-word-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add_word">

            <h3 style="margin-bottom:0;">Agregar una palabra</h3>

            <label for="clue">Pista (enunciado)</label>
            <textarea id="clue" name="clue" rows="2" required placeholder="Ej: Proceso por el que las plantas fabrican su alimento"></textarea>

            <label for="word">Palabra (respuesta)</label>
            <input type="text" id="word" name="word" required maxlength="40" autocomplete="off" placeholder="Ej: Fotosíntesis">

            <button type="submit" class="btn">Agregar palabra</button>
        </form>
        <?php else: ?>
        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="margin-top:16px;" id="add-question-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add_question">

            <?php if ($activity['game_mode'] === 'sapito'): ?>
                <input type="hidden" name="type" value="multiple">
                <p class="text-muted" style="font-size:0.85rem; margin-top:0;">
                    Todas las preguntas de "El Sapito" son de opción única (varios nenúfares, uno correcto).
                </p>
            <?php else: ?>
                <label for="type">Tipo de pregunta</label>
                <select id="type" name="type">
                    <option value="multiple">Selección múltiple</option>
                    <option value="truefalse">Verdadero/Falso</option>
                </select>
            <?php endif; ?>

            <label for="statement">Enunciado</label>
            <textarea id="statement" name="statement" rows="2" required placeholder="Escribe la pregunta..."></textarea>

            <label for="time_seconds">Tiempo (segundos)</label>
            <input type="text" id="time_seconds" name="time_seconds" value="<?= (int) $activity['time_per_question'] ?>">

            <label for="points">Puntos</label>
            <input type="text" id="points" name="points" value="<?= (int) $activity['points_base'] ?>">

            <button type="submit" class="btn">Agregar pregunta</button>
            <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
                "Verdadero/Falso" se crea de inmediato. "Selección múltiple" te lleva a la pantalla de la pregunta
                para agregar las opciones y marcar la correcta.
            </p>
        </form>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
