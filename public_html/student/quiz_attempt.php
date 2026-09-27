<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/quiz_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT a.id AS assignment_id, a.due_date, a.points AS assignment_points, a.quiz_id AS assignment_quiz_id,
        qz.id AS quiz_id, qz.title, qz.description, qz.instructions,
        qz.time_limit_minutes, qz.max_attempts, qz.status
     FROM assignments a
     INNER JOIN quizzes qz ON qz.id = a.quiz_id
     INNER JOIN assignment_students ast ON ast.assignment_id = a.id AND ast.student_id = :student_id
     WHERE a.id = :assignment_id LIMIT 1"
);
$stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
$row = $stmt->fetch();

if (!$row || $row['status'] !== 'published') {
    http_response_code(404);
    exit('Cuestionario no encontrado o no disponible.');
}

$quiz = [
    'id' => (int) $row['quiz_id'],
    'title' => $row['title'],
    'description' => $row['description'],
    'instructions' => $row['instructions'],
    'time_limit_minutes' => $row['time_limit_minutes'],
    'max_attempts' => (int) $row['max_attempts'],
];

$isOverdue = $row['due_date'] && strtotime($row['due_date']) < time();
$errors = [];
$reviewOnly = isset($_GET['review']);

// ---- Entrega del intento ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_attempt') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $attemptId = (int) ($_POST['attempt_id'] ?? 0);

    // Verificar que el intento es de este estudiante y de este cuestionario, y sigue abierto
    $attStmt = $pdo->prepare(
        "SELECT * FROM quiz_attempts WHERE id = :id AND quiz_id = :quiz_id AND student_id = :student_id AND status = 'in_progress'"
    );
    $attStmt->execute(['id' => $attemptId, 'quiz_id' => $quiz['id'], 'student_id' => $studentId]);
    $attempt = $attStmt->fetch();

    if (!$attempt) {
        $errors[] = 'Este intento ya no está disponible (puede que ya lo hayas entregado o haya vencido).';
    } else {
        $rawAnswers = $_POST['answers'] ?? [];
        $answers = [];
        foreach ($rawAnswers as $questionId => $optionId) {
            if ($optionId !== '') {
                $answers[(int) $questionId] = (int) $optionId;
            }
        }
        $attempt = quiz_submit_attempt($pdo, $attemptId, $answers);
    }
}

// ---- ¿Hay un intento recién entregado (para mostrar resultado) o hay que iniciar/retomar uno? ----
$submittedAttempt = null;
if (isset($attempt) && $attempt && $attempt['status'] === 'submitted') {
    $submittedAttempt = $attempt;
}

$activeAttempt = null;
$startError = null;

if ($reviewOnly && !$submittedAttempt) {
    // Solo quiere ver su mejor resultado, sin iniciar ni retomar ningún intento.
    $bestStmt = $pdo->prepare(
        "SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'submitted'
         ORDER BY (score_points / NULLIF(score_max, 0)) DESC, id DESC LIMIT 1"
    );
    $bestStmt->execute(['quiz_id' => $quiz['id'], 'student_id' => $studentId]);
    $submittedAttempt = $bestStmt->fetch() ?: null;
    if (!$submittedAttempt) {
        $startError = 'Todavía no has entregado ningún intento de este cuestionario.';
    }
}

if (!$submittedAttempt && !$reviewOnly) {
    // ¿Cuántos intentos ya usó, para decidir si puede empezar uno nuevo?
    $usedStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'submitted'"
    );
    $usedStmt->execute(['quiz_id' => $quiz['id'], 'student_id' => $studentId]);
    $usedAttempts = (int) ($usedStmt->fetch()['total'] ?? 0);

    $openStmt = $pdo->prepare(
        "SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'in_progress' ORDER BY id DESC LIMIT 1"
    );
    $openStmt->execute(['quiz_id' => $quiz['id'], 'student_id' => $studentId]);
    $hasOpenAttempt = (bool) $openStmt->fetch();

    if ($isOverdue && !$hasOpenAttempt) {
        $startError = 'La fecha de entrega de esta asignación ya venció y no tienes ningún intento en curso.';
    } elseif ($usedAttempts >= $quiz['max_attempts'] && !$hasOpenAttempt) {
        $startError = 'Ya utilizaste todos tus intentos disponibles (' . $quiz['max_attempts'] . ') para este cuestionario.';
    } else {
        $result = quiz_start_or_resume_attempt($pdo, [
            'id' => $quiz['id'], 'max_attempts' => $quiz['max_attempts'], 'time_limit_minutes' => $quiz['time_limit_minutes'],
        ], $studentId, $assignmentId);

        if ($result['error']) {
            $startError = $result['error'];
        } else {
            $activeAttempt = $result['attempt'];
            if ($activeAttempt['status'] === 'submitted') {
                // Se autoentregó por vencimiento del tiempo justo ahora.
                $submittedAttempt = $activeAttempt;
                $activeAttempt = null;
            }
        }
    }

    // Si no se pudo iniciar/retomar un intento (venció o se acabaron), pero ya
    // había al menos uno entregado antes (incluyendo uno que se acabe de
    // autoentregar por vencimiento justo en esta visita), mostramos la
    // revisión del mejor en vez de dejar al estudiante solo con un error.
    if ($startError) {
        $bestStmt = $pdo->prepare(
            "SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'submitted'
             ORDER BY (score_points / NULLIF(score_max, 0)) DESC, id DESC LIMIT 1"
        );
        $bestStmt->execute(['quiz_id' => $quiz['id'], 'student_id' => $studentId]);
        $bestAttempt = $bestStmt->fetch();
        if ($bestAttempt) {
            $submittedAttempt = $bestAttempt;
            $startError = null;
        }
    }
}

$questions = $activeAttempt ? quiz_questions_for_attempt($pdo, $quiz['id']) : [];
$review = $submittedAttempt ? quiz_attempt_review($pdo, (int) $submittedAttempt['id']) : [];

$pageTitle = $quiz['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="assignment.php?id=<?= (int) $assignmentId ?>">&larr; Volver a la asignación</a></p>
<h1>📝 <?= e($quiz['title']) ?></h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($submittedAttempt): ?>
    <div class="card" style="text-align:center;">
        <h2 style="margin-top:0;">✅ Cuestionario entregado</h2>
        <p style="font-size:1.4rem;">
            <strong><?= e((string) round((float) $submittedAttempt['score_points'], 2)) ?> / <?= e((string) round((float) $submittedAttempt['score_max'], 2)) ?></strong>
            puntos
            (<?= $submittedAttempt['score_max'] > 0 ? round(((float) $submittedAttempt['score_points'] / (float) $submittedAttempt['score_max']) * 100) : 0 ?>%)
        </p>
        <p class="text-muted" style="font-size:0.85rem;">
            Intento <?= (int) $submittedAttempt['attempt_number'] ?> de <?= (int) $quiz['max_attempts'] ?> ·
            Entregado <?= e(date('d/m/Y H:i', strtotime($submittedAttempt['submitted_at']))) ?>
        </p>
    </div>

    <section class="card" style="margin-top:16px;">
        <h2 style="margin-top:0;">Revisión</h2>
        <?php foreach ($review as $i => $q): ?>
            <div class="card" style="margin-bottom:10px; padding:14px 16px; border-left:4px solid <?= $q['is_correct'] ? 'var(--color-success)' : '#C0392B' ?>;">
                <strong><?= $i + 1 ?>. <?= e($q['statement']) ?></strong>
                <span class="text-muted" style="font-size:0.85rem;"> (<?= e((string) round((float) $q['points_earned'], 2)) ?> / <?= (int) $q['points'] ?> pts)</span>
                <div style="margin-top:8px;">
                    <?php foreach ($q['options'] as $o): ?>
                        <?php
                            $isSelected = (int) $q['selected_option_id'] === (int) $o['id'];
                            $isCorrectOpt = (int) $o['is_correct'] === 1;
                            $style = 'padding:4px 8px; border-radius:6px; margin-bottom:4px;';
                            if ($isCorrectOpt) {
                                $style .= 'background:#E8F8EF; color:#1E7E42; font-weight:600;';
                            } elseif ($isSelected) {
                                $style .= 'background:#FDECEA; color:#C0392B;';
                            }
                        ?>
                        <div style="<?= $style ?>">
                            <?= $isSelected ? '➡️ ' : '' ?><?= e($o['text']) ?><?= $isCorrectOpt ? ' ✓' : '' ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($q['selected_option_id'] === null): ?>
                        <p class="text-muted" style="font-size:0.85rem;">No respondiste esta pregunta.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ((int) $submittedAttempt['attempt_number'] < $quiz['max_attempts'] && !$isOverdue): ?>
        <p style="margin-top:16px;">
            <a class="btn" href="quiz_attempt.php?assignment_id=<?= (int) $assignmentId ?>">Intentar de nuevo</a>
            <span class="text-muted" style="font-size:0.85rem;"> (se guarda tu mejor calificación)</span>
        </p>
    <?php endif; ?>

<?php elseif ($startError): ?>
    <div class="card" style="text-align:center;">
        <h2 style="margin-top:0;">No disponible</h2>
        <p class="text-muted"><?= e($startError) ?></p>
    </div>

<?php elseif ($activeAttempt): ?>
    <?php if ($quiz['description']): ?>
        <div class="card"><?= nl2br(e($quiz['description'])) ?></div>
    <?php endif; ?>
    <?php if ($quiz['instructions']): ?>
        <div class="card" style="background:#F8FAFC;"><strong>Instrucciones:</strong> <?= nl2br(e($quiz['instructions'])) ?></div>
    <?php endif; ?>

    <p class="text-muted" style="font-size:0.85rem;">
        Intento <?= (int) $activeAttempt['attempt_number'] ?> de <?= (int) $quiz['max_attempts'] ?>
        <?php if ($activeAttempt['expires_at']): ?>
            · Tiempo restante: <strong id="quiz-timer"></strong>
        <?php endif; ?>
    </p>

    <form method="post" action="quiz_attempt.php?assignment_id=<?= (int) $assignmentId ?>" id="quiz-form"
          onsubmit="return confirm('¿Entregar el cuestionario? No podrás cambiar tus respuestas después.')">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="submit_attempt">
        <input type="hidden" name="attempt_id" value="<?= (int) $activeAttempt['id'] ?>">

        <?php foreach ($questions as $i => $q): ?>
            <div class="card" style="margin-bottom:12px;">
                <strong><?= $i + 1 ?>. <?= e($q['statement']) ?></strong>
                <span class="text-muted" style="font-size:0.85rem;"> (<?= (int) $q['points'] ?> pts)</span>
                <div style="margin-top:10px;">
                    <?php foreach ($q['options'] as $o): ?>
                        <label style="display:flex; align-items:center; gap:8px; font-weight:400; padding:6px 0;">
                            <input type="radio" name="answers[<?= (int) $q['id'] ?>]" value="<?= (int) $o['id'] ?>" style="width:auto;">
                            <?= e($o['text']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn">Entregar cuestionario</button>
    </form>

    <?php if ($activeAttempt['expires_at']): ?>
    <script>
    const expiresAt = new Date(<?= json_encode($activeAttempt['expires_at']) ?>.replace(' ', 'T'));
    const timerEl = document.getElementById('quiz-timer');
    function tick() {
        const diffMs = expiresAt - new Date();
        if (diffMs <= 0) {
            timerEl.textContent = '00:00';
            alert('Se acabó el tiempo. El cuestionario se entregará automáticamente con lo que hayas respondido.');
            document.getElementById('quiz-form').removeAttribute('onsubmit');
            document.getElementById('quiz-form').submit();
            return;
        }
        const totalSeconds = Math.floor(diffMs / 1000);
        const m = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
        const s = (totalSeconds % 60).toString().padStart(2, '0');
        timerEl.textContent = `${m}:${s}`;
        setTimeout(tick, 1000);
    }
    tick();
    </script>
    <?php endif; ?>

<?php else: ?>
    <div class="card" style="text-align:center;">
        <p class="text-muted">No se pudo cargar el cuestionario.</p>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
