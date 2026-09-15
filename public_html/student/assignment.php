<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/project_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();
$assignmentId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT a.*, act.id AS activity_id, act.title AS activity_title, s.name AS subject_name,
        sub.id AS submission_id, sub.status, sub.score, sub.feedback, sub.completed_at, sub.project_id
     FROM assignments a
     LEFT JOIN activities act ON act.id = a.activity_id
     INNER JOIN subjects s ON s.id = a.subject_id
     INNER JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = :student_id
     WHERE a.id = :id LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'student_id' => $studentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    http_response_code(404);
    exit('Asignación no encontrada o no te pertenece.');
}

$isOverdue = $assignment['due_date'] && strtotime($assignment['due_date']) < time();
$isCreation = $assignment['activity_id'] === null;

// Si es un trabajo de creación y todavía está pendiente, aseguramos que
// exista el borrador para poder enlazar directo al editor.
$project = null;
if ($isCreation && $assignment['status'] !== 'completed') {
    $project = project_find_or_create_for_assignment(
        $pdo, $assignmentId, $studentId, $assignment['project_type'], $assignment['title']
    );
}

$editorUrlByType = [
    'resumen' => 'resumen.php',
    'tabla_comparativa' => 'tabla.php',
    'infografia' => 'canvas.php',
    'mapa_mental' => 'canvas.php',
];

$pageTitle = $assignment['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mi panel</a></p>
<h1><?= e($assignment['title']) ?></h1>
<p class="text-muted">
    <?= e($assignment['subject_name']) ?> ·
    <?= $isCreation ? e(PROJECT_TYPES[$assignment['project_type']] ?? 'Trabajo') : 'Actividad: ' . e($assignment['activity_title']) ?> ·
    <?= (int) $assignment['points'] ?> pts
    <?php if ($assignment['due_date']): ?>
        · Entrega: <?= e(date('d/m/Y', strtotime($assignment['due_date']))) ?>
        <?= $isOverdue ? ' <span style="color:var(--color-danger);">(vencida)</span>' : '' ?>
    <?php endif; ?>
</p>

<?php if ($assignment['description']): ?>
    <div class="card"><?= nl2br(e($assignment['description'])) ?></div>
<?php endif; ?>

<?php if ($assignment['status'] === 'completed'): ?>
    <div class="card">
        <h2 style="margin-top:0;">✅ Completada</h2>
        <?php if ($assignment['score'] !== null): ?>
            <p style="font-size:1.3rem;">
                Calificación: <strong><?= e((string) $assignment['score']) ?> / <?= (int) $assignment['points'] ?></strong>
            </p>
        <?php else: ?>
            <p class="text-muted">Entregado. Tu profesor todavía no ha calificado este trabajo.</p>
        <?php endif; ?>
        <?php if ($assignment['feedback']): ?>
            <p class="text-muted">💬 <?= nl2br(e($assignment['feedback'])) ?></p>
        <?php endif; ?>
        <?php if ($assignment['completed_at']): ?>
            <p class="text-muted" style="font-size:0.85rem;">
                Completada el <?= e(date('d/m/Y H:i', strtotime($assignment['completed_at']))) ?>
            </p>
        <?php endif; ?>
        <?php if ($isCreation && $assignment['project_id']): ?>
            <a class="btn btn-secondary" href="<?= e(rtrim(APP_URL, '/')) ?>/editor/<?= e($editorUrlByType[$assignment['project_type']] ?? '') ?>?project_id=<?= (int) $assignment['project_id'] ?>">
                Ver mi trabajo entregado
            </a>
        <?php endif; ?>
    </div>
<?php elseif ($isCreation): ?>
    <div class="card" style="text-align:center;">
        <h2 style="margin-top:0;">⏳ Pendiente</h2>
        <p class="text-muted">Trabaja a tu ritmo. Puedes guardar tu avance y continuar más tarde.</p>
        <a class="btn" href="<?= e(rtrim(APP_URL, '/')) ?>/editor/<?= e($editorUrlByType[$assignment['project_type']] ?? '') ?>?project_id=<?= (int) $project['id'] ?>">
            Abrir editor
        </a>
    </div>
<?php else: ?>
    <div class="card" id="pending-panel" style="text-align:center;">
        <h2 style="margin-top:0;">⏳ Pendiente</h2>
        <p class="text-muted">Esta actividad se juega en vivo. Cuando tu profesor inicie la partida, podrás entrar desde aquí.</p>
        <div id="join-status">
            <p class="text-muted">Buscando si la partida ya está activa...</p>
        </div>
        <p class="text-muted" style="margin-top:16px; font-size:0.85rem;">
            También puedes <a href="<?= e(rtrim(APP_URL, '/')) ?>/game/join.php">unirte manualmente con un código</a>.
        </p>
    </div>

    <script>
    const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
    const ACTIVITY_ID = <?= (int) $assignment['activity_id'] ?>;

    async function checkActiveGame() {
        const statusEl = document.getElementById('join-status');
        try {
            const res = await fetch(`${AULA_APP_URL}/api/games/find_active_by_activity.php?activity_id=${ACTIVITY_ID}`);
            const data = await res.json();
            if (data.success) {
                statusEl.innerHTML = `<a class="btn" href="${AULA_APP_URL}/game/play.php?code=${data.code}">¡La partida está activa! Unirme ahora</a>`;
            } else {
                statusEl.innerHTML = `<p class="text-muted">${data.message}</p>`;
                setTimeout(checkActiveGame, 5000);
            }
        } catch (e) {
            setTimeout(checkActiveGame, 5000);
        }
    }
    checkActiveGame();
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
