<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/quiz_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$attemptId = (int) ($_GET['id'] ?? 0);

// Verificar propiedad: el intento debe ser de un cuestionario de este profesor
$stmt = $pdo->prepare(
    'SELECT qa.*, qz.title AS quiz_title, u.name AS student_name
     FROM quiz_attempts qa
     INNER JOIN quizzes qz ON qz.id = qa.quiz_id
     INNER JOIN users u ON u.id = qa.student_id
     WHERE qa.id = :id AND qz.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['id' => $attemptId, 'teacher_id' => $teacherId]);
$attempt = $stmt->fetch();

if (!$attempt) {
    http_response_code(404);
    exit('Intento no encontrado.');
}

$review = $attempt['status'] === 'submitted' ? quiz_attempt_review($pdo, $attemptId) : [];

$pageTitle = 'Revisión de intento';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="javascript:history.back()">&larr; Volver</a></p>
<h1>📝 <?= e($attempt['quiz_title']) ?></h1>
<p class="text-muted">
    <?= e($attempt['student_name']) ?> · Intento <?= (int) $attempt['attempt_number'] ?>
</p>

<?php if ($attempt['status'] !== 'submitted'): ?>
    <div class="card"><p class="text-muted">Este intento todavía está en curso (no se ha entregado).</p></div>
<?php else: ?>
    <div class="card" style="text-align:center;">
        <p style="font-size:1.4rem;">
            <strong><?= e((string) round((float) $attempt['score_points'], 2)) ?> / <?= e((string) round((float) $attempt['score_max'], 2)) ?></strong> puntos
            (<?= $attempt['score_max'] > 0 ? round(((float) $attempt['score_points'] / (float) $attempt['score_max']) * 100) : 0 ?>%)
        </p>
        <p class="text-muted" style="font-size:0.85rem;">
            Entregado el <?= e(date('d/m/Y H:i', strtotime($attempt['submitted_at']))) ?>
        </p>
        <?php if ((int) $attempt['tab_switches'] > 0): ?>
            <p style="color:#C0392B; font-weight:600;">
                🚩 Salió de la pantalla del cuestionario <?= (int) $attempt['tab_switches'] ?> vez(veces) durante este intento (<?= (int) $attempt['tab_switches'] >= 3 ? 'strike 3: el intento se cerró y se entregó automáticamente' : 'strikes' ?>).
            </p>
        <?php else: ?>
            <p class="text-muted" style="font-size:0.85rem;">Sin cambios de pestaña detectados durante el intento.</p>
        <?php endif; ?>
    </div>

    <section class="card" style="margin-top:16px;">
        <h2 style="margin-top:0;">Respuestas</h2>
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
                        <p class="text-muted" style="font-size:0.85rem;">No respondió esta pregunta.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
