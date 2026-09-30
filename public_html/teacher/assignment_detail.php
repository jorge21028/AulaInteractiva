<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/project_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$assignmentId = (int) ($_GET['id'] ?? 0);
$notice = null;
$errors = [];

$stmt = $pdo->prepare(
    'SELECT a.*, act.id AS activity_id, act.title AS activity_title, act.game_mode, qz.title AS quiz_title, s.name AS subject_name
     FROM assignments a
     LEFT JOIN activities act ON act.id = a.activity_id
     LEFT JOIN quizzes qz ON qz.id = a.quiz_id
     INNER JOIN subjects s ON s.id = a.subject_id
     WHERE a.id = :id AND a.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'teacher_id' => $teacherId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    http_response_code(404);
    exit('Asignación no encontrada.');
}

$postedOverride = []; // valores enviados que no pasaron validación (para no perder lo escrito)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_grades_bulk') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $postedScores = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
    $postedFeedbacks = is_array($_POST['feedbacks'] ?? null) ? $_POST['feedbacks'] : [];

    // Estado actual de TODAS las entregas de esta asignación (solo las del profesor dueño, ya validado arriba)
    $currentStmt = $pdo->prepare(
        'SELECT sub.id, sub.score, sub.feedback, sub.status, u.name AS student_name
         FROM submissions sub INNER JOIN users u ON u.id = sub.student_id
         WHERE sub.assignment_id = :assignment_id'
    );
    $currentStmt->execute(['assignment_id' => $assignmentId]);
    $current = [];
    foreach ($currentStmt->fetchAll() as $row) {
        $current[(int) $row['id']] = $row;
    }

    $update = $pdo->prepare(
        'UPDATE submissions SET score = :score, feedback = :feedback, status = :status, reviewed_at = :reviewed_at WHERE id = :id'
    );

    $saved = 0;
    $now = now_datetime();
    $pdo->beginTransaction();
    try {
        foreach ($postedScores as $submissionId => $rawScore) {
            $submissionId = (int) $submissionId;
            if (!isset($current[$submissionId])) {
                continue; // no pertenece a esta asignación
            }
            $row = $current[$submissionId];

            $rawScore = trim((string) $rawScore);
            $feedback = clean_string((string) ($postedFeedbacks[$submissionId] ?? ''));

            $score = null;
            if ($rawScore !== '') {
                $normalized = str_replace(',', '.', $rawScore);
                if (!is_numeric($normalized) || (float) $normalized < 0) {
                    $errors[] = 'La calificación de ' . $row['student_name'] . ' no es válida ("' . $rawScore . '"). No se guardó.';
                    $postedOverride[$submissionId] = ['score' => $rawScore, 'feedback' => $feedback];
                    continue;
                }
                $score = round((float) $normalized, 2);
            }

            $oldScore = $row['score'] !== null ? round((float) $row['score'], 2) : null;
            $oldFeedback = (string) ($row['feedback'] ?? '');

            // Solo se tocan las entregas que realmente cambiaron: así no se marca como
            // "ajustada por el profesor" una calificación automática que no se modificó.
            if ($score === $oldScore && $feedback === $oldFeedback) {
                continue;
            }

            $newStatus = ($score !== null || $row['status'] === 'completed') ? 'completed' : $row['status'];
            $update->execute([
                'score' => $score, 'feedback' => $feedback, 'status' => $newStatus,
                'reviewed_at' => $now, 'id' => $submissionId,
            ]);
            $saved++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $errors[] = 'No se pudieron guardar las calificaciones. Intenta de nuevo.';
        $saved = 0;
    }

    if ($saved > 0) {
        $notice = $saved === 1 ? 'Se guardó 1 calificación.' : "Se guardaron {$saved} calificaciones.";
    } elseif (empty($errors)) {
        $notice = 'No había cambios para guardar.';
    }
}

$rosterStmt = $pdo->prepare(
    'SELECT sub.id AS submission_id, sub.status, sub.score, sub.feedback, sub.completed_at, sub.reviewed_at, sub.project_id, sub.quiz_attempt_id,
        qa.tab_switches, wga.status AS wg_status, wga.correct_count AS wg_correct, wga.total_count AS wg_total,
        u.name AS student_name, u.email AS student_email
     FROM submissions sub
     INNER JOIN users u ON u.id = sub.student_id
     LEFT JOIN quiz_attempts qa ON qa.id = sub.quiz_attempt_id
     LEFT JOIN word_game_attempts wga ON wga.assignment_id = sub.assignment_id AND wga.student_id = sub.student_id
     WHERE sub.assignment_id = :assignment_id
     ORDER BY u.name ASC'
);
$rosterStmt->execute(['assignment_id' => $assignmentId]);
$roster = $rosterStmt->fetchAll();

$pageTitle = $assignment['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="assignments.php">&larr; Volver a asignaciones</a></p>
<h1><?= e($assignment['title']) ?></h1>
<p class="text-muted">
    <?= e($assignment['subject_name']) ?> ·
    <?php if ($assignment['activity_title']): ?>
        Actividad: <?= e($assignment['activity_title']) ?>
    <?php elseif ($assignment['quiz_title']): ?>
        Cuestionario: 📝 <?= e($assignment['quiz_title']) ?>
    <?php else: ?>
        <?= e(PROJECT_TYPES[$assignment['project_type']] ?? 'Trabajo') ?>
    <?php endif; ?>
    ·
    <?= (int) $assignment['points'] ?> pts
    <?php if ($assignment['due_date']): ?>
        · Entrega: <?= e(date('d/m/Y', strtotime($assignment['due_date']))) ?>
    <?php endif; ?>
</p>

<?php if ($assignment['description']): ?>
    <div class="card"><?= nl2br(e($assignment['description'])) ?></div>
<?php endif; ?>

<?php if ($assignment['activity_id']): ?>
<a class="btn" style="margin:16px 0; display:inline-block;" href="host.php?activity_id=<?= (int) $assignment['activity_id'] ?>">
    Iniciar partida de esta actividad
</a>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<section class="card">
    <h2 style="margin-top:0;">Entregas (<?= count($roster) ?>)</h2>

    <?php if (empty($roster)): ?>
        <p class="empty-state">No hay estudiantes inscritos en esta asignatura todavía.</p>
    <?php else: ?>
        <form method="post" action="assignment_detail.php?id=<?= (int) $assignmentId ?>" id="grades-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_grades_bulk">

            <?php foreach ($roster as $r):
                $sid = (int) $r['submission_id'];
                $scoreValue = isset($postedOverride[$sid]) ? $postedOverride[$sid]['score'] : ($r['score'] !== null ? (string) $r['score'] : '');
                $feedbackValue = isset($postedOverride[$sid]) ? $postedOverride[$sid]['feedback'] : ($r['feedback'] ?? '');
            ?>
                <div class="card grade-row" style="margin-bottom:10px; padding:14px 16px;">
                    <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                        <div>
                            <strong><?= e($r['student_name']) ?></strong>
                            <span class="text-muted"> (<?= e($r['student_email']) ?>)</span>
                            <p class="text-muted" style="margin:4px 0 0; font-size:0.85rem;">
                                <?php if ($r['status'] === 'completed'): ?>
                                    ✅ Completada
                                    <?= $r['completed_at'] ? '· ' . e(date('d/m/Y H:i', strtotime($r['completed_at']))) : '' ?>
                                    <?= $r['reviewed_at'] ? '· ajustada por el profesor' : '· calificación automática' ?>
                                    <?= $r['wg_status'] === 'completed' ? '· ' . (int) $r['wg_correct'] . ' de ' . (int) $r['wg_total'] . ' palabras' : '' ?>
                                <?php elseif ($r['wg_status'] === 'in_progress'): ?>
                                    ▶️ En curso (todavía no termina)
                                <?php else: ?>
                                    ⏳ Pendiente
                                <?php endif; ?>
                            </p>
                            <?php if ($r['project_id']): ?>
                                <a href="view_project.php?id=<?= (int) $r['project_id'] ?>" style="font-size:0.85rem;">Ver trabajo entregado &rarr;</a>
                            <?php endif; ?>
                            <?php if ($r['quiz_attempt_id']): ?>
                                <a href="quiz_review.php?id=<?= (int) $r['quiz_attempt_id'] ?>" style="font-size:0.85rem;">Ver intento &rarr;</a>
                                <?php if ((int) $r['tab_switches'] > 0): ?>
                                    <span style="color:#C0392B; font-weight:600; font-size:0.8rem;">
                                        🚩 <?= (int) $r['tab_switches'] ?> salida<?= (int) $r['tab_switches'] === 1 ? '' : 's' ?> de la pantalla del quiz
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-top:10px;">
                        <div>
                            <label style="margin-top:0;">Calificación (sobre <?= (int) $assignment['points'] ?>)</label>
                            <input type="text" inputmode="decimal" name="scores[<?= $sid ?>]" value="<?= e($scoreValue) ?>"
                                   data-original="<?= e($r['score'] !== null ? (string) $r['score'] : '') ?>" style="width:100px;">
                        </div>
                        <div style="flex:1; min-width:200px;">
                            <label style="margin-top:0;">Retroalimentación</label>
                            <input type="text" name="feedbacks[<?= $sid ?>]" value="<?= e($feedbackValue) ?>"
                                   data-original="<?= e($r['feedback'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div id="grades-savebar" style="position:sticky; bottom:0; z-index:20; background:#fff; border-top:1px solid #E2E8F0; padding:12px 16px; margin:16px -16px -16px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <span id="grades-status" class="text-muted" style="font-size:0.9rem;">Sin cambios pendientes</span>
                <button type="submit" class="btn" id="grades-save" style="margin:0;">Guardar todas las calificaciones</button>
            </div>
        </form>

        <script>
        (function () {
            const form = document.getElementById('grades-form');
            const status = document.getElementById('grades-status');
            let submitting = false;

            function countChanged() {
                let n = 0;
                form.querySelectorAll('.grade-row').forEach(row => {
                    const inputs = row.querySelectorAll('input[data-original]');
                    let changed = false;
                    inputs.forEach(i => { if (i.value.trim() !== i.dataset.original.trim()) changed = true; });
                    row.style.outline = changed ? '2px solid var(--color-primary, #4F46E5)' : '';
                    if (changed) n++;
                });
                return n;
            }

            function refresh() {
                const n = countChanged();
                status.textContent = n === 0 ? 'Sin cambios pendientes'
                    : (n === 1 ? '1 estudiante con cambios sin guardar' : n + ' estudiantes con cambios sin guardar');
                status.style.color = n === 0 ? '' : '#B45309';
                status.style.fontWeight = n === 0 ? '' : '600';
                return n;
            }

            form.addEventListener('input', refresh);
            form.addEventListener('submit', () => { submitting = true; });
            window.addEventListener('beforeunload', (e) => {
                if (!submitting && refresh() > 0) { e.preventDefault(); e.returnValue = ''; }
            });
            // Enter dentro de un campo no debe enviar por accidente
            form.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault();
            });
            refresh();
        })();
        </script>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
