<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'update_meta') {
        $title = clean_string($_POST['title'] ?? '');
        $description = clean_string($_POST['description'] ?? '');
        $instructions = clean_string($_POST['instructions'] ?? '');
        $difficulty = clean_string($_POST['difficulty'] ?? 'media');
        $timePerQuestion = max(5, (int) ($_POST['time_per_question'] ?? 20));
        $pointsBase = max(10, (int) ($_POST['points_base'] ?? 100));
        $speedBonusMax = max(0, (int) ($_POST['speed_bonus_max'] ?? 50));
        $rankingEnabled = isset($_POST['ranking_enabled']) ? 1 : 0;
        $allowRepeat = isset($_POST['allow_repeat']) ? 1 : 0;
        $teamMode = isset($_POST['team_mode']) ? 1 : 0;

        if ($title === '') {
            $errors[] = 'El título no puede estar vacío.';
        } elseif (!in_array($difficulty, ['facil', 'media', 'dificil'], true)) {
            $errors[] = 'Dificultad inválida.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE activities SET title = :title, description = :description, instructions = :instructions,
                    difficulty = :difficulty, time_per_question = :time_per_question, points_base = :points_base,
                    speed_bonus_max = :speed_bonus_max, ranking_enabled = :ranking_enabled, allow_repeat = :allow_repeat,
                    team_mode = :team_mode, updated_at = :updated_at
                 WHERE id = :id AND teacher_id = :teacher_id'
            );
            $stmt->execute([
                'title' => $title, 'description' => $description, 'instructions' => $instructions,
                'difficulty' => $difficulty, 'time_per_question' => $timePerQuestion, 'points_base' => $pointsBase,
                'speed_bonus_max' => $speedBonusMax, 'ranking_enabled' => $rankingEnabled, 'allow_repeat' => $allowRepeat,
                'team_mode' => $teamMode, 'updated_at' => now_datetime(), 'id' => $activityId, 'teacher_id' => $teacherId,
            ]);
            $notice = 'Datos guardados.';
            $activity = load_owned_activity($pdo, $activityId, $teacherId);
        }
    }

    if ($action === 'add_question') {
        $type = clean_string($_POST['type'] ?? 'multiple');
        $statement = clean_string($_POST['statement'] ?? '');
        $timeSeconds = max(5, (int) ($_POST['time_seconds'] ?? $activity['time_per_question']));
        $points = max(10, (int) ($_POST['points'] ?? $activity['points_base']));

        if (!in_array($type, ['multiple', 'truefalse'], true)) {
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

        if ($q) {
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
            $errors[] = 'Agrega al menos una pregunta antes de publicar.';
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
<h1><?= e($activity['title']) ?>
    <span class="text-muted" style="font-size:0.9rem; font-weight:400;">
        (<?= $activity['status'] === 'published' ? 'Publicada' : 'Borrador' ?>)
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
        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_meta">

            <label for="title">Título</label>
            <input type="text" id="title" name="title" required value="<?= e($activity['title']) ?>">

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
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" name="allow_repeat" style="width:auto;" <?= $activity['allow_repeat'] ? 'checked' : '' ?>>
                Permitir repetir la partida
            </label>
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" name="team_mode" style="width:auto;" <?= $activity['team_mode'] ? 'checked' : '' ?>>
                Modo por equipos (próximamente)
            </label>

            <button type="submit" class="btn">Guardar datos</button>
        </form>

        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="margin-top:12px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="toggle_status">
            <button type="submit" class="btn btn-secondary">
                <?= $activity['status'] === 'draft' ? 'Publicar actividad' : 'Volver a borrador' ?>
            </button>
        </form>

        <?php if ($activity['status'] === 'published'): ?>
            <a class="btn" style="margin-top:12px; display:inline-block;" href="host.php?activity_id=<?= (int) $activityId ?>">
                Iniciar partida
            </a>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 style="margin-top:0;">Preguntas (<?= count($questions) ?>)</h2>

        <?php if (empty($questions)): ?>
            <p class="empty-state">Sin preguntas todavía.</p>
        <?php else: ?>
            <?php foreach ($questions as $i => $q): ?>
                <div class="card" style="margin-bottom:10px; padding:14px 16px;">
                    <div style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
                        <div>
                            <strong><?= $i + 1 ?>. <?= e(truncate_text($q['statement'], 80)) ?></strong>
                            <p class="text-muted" style="margin:4px 0 0; font-size:0.85rem;">
                                <?= $q['type'] === 'multiple' ? 'Selección múltiple' : 'Verdadero/Falso' ?> ·
                                <?= (int) $q['time_seconds'] ?>s · <?= (int) $q['points'] ?> pts ·
                                <?= count($q['options']) ?> opciones
                            </p>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                        <a class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;" href="activity_question.php?id=<?= (int) $q['id'] ?>">Editar</a>

                        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="display:inline;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="duplicate_question">
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="margin:0; padding:6px 12px; font-size:0.85rem;">Duplicar</button>
                        </form>

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

        <form method="post" action="activity_edit.php?id=<?= (int) $activityId ?>" style="margin-top:16px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add_question">

            <label for="type">Tipo de pregunta</label>
            <select id="type" name="type">
                <option value="multiple">Selección múltiple</option>
                <option value="truefalse">Verdadero/Falso</option>
            </select>

            <label for="statement">Enunciado</label>
            <textarea id="statement" name="statement" rows="2" required placeholder="Escribe la pregunta..."></textarea>

            <label for="time_seconds">Tiempo (segundos)</label>
            <input type="text" id="time_seconds" name="time_seconds" value="<?= (int) $activity['time_per_question'] ?>">

            <label for="points">Puntos</label>
            <input type="text" id="points" name="points" value="<?= (int) $activity['points_base'] ?>">

            <button type="submit" class="btn">Agregar pregunta</button>
            <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
                Si eliges "Verdadero/Falso" se crea de inmediato. Si eliges "Selección múltiple" te llevará a agregar las opciones.
            </p>
        </form>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
