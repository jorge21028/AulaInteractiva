<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$assignmentId = (int) ($_GET['id'] ?? 0);
$notice = null;
$errors = [];

$stmt = $pdo->prepare(
    'SELECT a.*, act.title AS activity_title, act.id AS activity_id, s.name AS subject_name
     FROM assignments a
     INNER JOIN activities act ON act.id = a.activity_id
     INNER JOIN subjects s ON s.id = a.subject_id
     WHERE a.id = :id AND a.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'teacher_id' => $teacherId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    http_response_code(404);
    exit('Asignación no encontrada.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_grade') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $submissionId = (int) ($_POST['submission_id'] ?? 0);
    $score = $_POST['score'] !== '' ? (float) $_POST['score'] : null;
    $feedback = clean_string($_POST['feedback'] ?? '');

    $check = $pdo->prepare(
        'SELECT sub.id FROM submissions sub WHERE sub.id = :id AND sub.assignment_id = :assignment_id'
    );
    $check->execute(['id' => $submissionId, 'assignment_id' => $assignmentId]);

    if ($check->fetch()) {
        $pdo->prepare(
            "UPDATE submissions SET score = :score, feedback = :feedback, status = 'completed', reviewed_at = :reviewed_at WHERE id = :id"
        )->execute([
            'score' => $score, 'feedback' => $feedback, 'reviewed_at' => now_datetime(), 'id' => $submissionId,
        ]);
        $notice = 'Calificación guardada.';
    } else {
        $errors[] = 'Entrega no encontrada.';
    }
}

$rosterStmt = $pdo->prepare(
    'SELECT sub.id AS submission_id, sub.status, sub.score, sub.feedback, sub.completed_at, sub.reviewed_at,
        u.name AS student_name, u.email AS student_email
     FROM submissions sub
     INNER JOIN users u ON u.id = sub.student_id
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
    <?= e($assignment['subject_name']) ?> · Actividad: <?= e($assignment['activity_title']) ?> ·
    <?= (int) $assignment['points'] ?> pts
    <?php if ($assignment['due_date']): ?>
        · Entrega: <?= e(date('d/m/Y', strtotime($assignment['due_date']))) ?>
    <?php endif; ?>
</p>

<?php if ($assignment['description']): ?>
    <div class="card"><?= nl2br(e($assignment['description'])) ?></div>
<?php endif; ?>

<a class="btn" style="margin:16px 0; display:inline-block;" href="host.php?activity_id=<?= (int) $assignment['activity_id'] ?>">
    Iniciar partida de esta actividad
</a>

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
        <?php foreach ($roster as $r): ?>
            <div class="card" style="margin-bottom:10px; padding:14px 16px;">
                <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <div>
                        <strong><?= e($r['student_name']) ?></strong>
                        <span class="text-muted"> (<?= e($r['student_email']) ?>)</span>
                        <p class="text-muted" style="margin:4px 0 0; font-size:0.85rem;">
                            <?php if ($r['status'] === 'completed'): ?>
                                ✅ Completada
                                <?= $r['completed_at'] ? '· ' . e(date('d/m/Y H:i', strtotime($r['completed_at']))) : '' ?>
                                <?= $r['reviewed_at'] ? '· ajustada por el profesor' : '· calificación automática' ?>
                            <?php else: ?>
                                ⏳ Pendiente
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <form method="post" action="assignment_detail.php?id=<?= (int) $assignmentId ?>" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-top:10px;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_grade">
                    <input type="hidden" name="submission_id" value="<?= (int) $r['submission_id'] ?>">

                    <div>
                        <label style="margin-top:0;">Calificación (sobre <?= (int) $assignment['points'] ?>)</label>
                        <input type="text" name="score" value="<?= $r['score'] !== null ? e((string) $r['score']) : '' ?>" style="width:100px;">
                    </div>
                    <div style="flex:1; min-width:200px;">
                        <label style="margin-top:0;">Retroalimentación</label>
                        <input type="text" name="feedback" value="<?= e($r['feedback'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn" style="margin:0;">Guardar</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
