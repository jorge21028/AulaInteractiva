<?php
/**
 * Foro de discusión. El profesor pone el título y la descripción (el tema) al crear la asignación; los estudiantes
 * lo comentan y responden a sus compañeros. El profesor modera y ve la participación de cada estudiante.
 *   ?id=ASIGNACION               el foro
 *   ?id=ASIGNACION&student=ID    (profesor) solo la participación de ese estudiante
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/description_helpers.php';
require_once __DIR__ . '/../includes/forum_helpers.php';
require_once __DIR__ . '/../includes/project_helpers.php';

require_role(['student', 'teacher', 'admin']);

$pdo = Database::getConnection();
$userId = (int) current_user_id();
$role = (string) current_role();
$assignmentId = (int) ($_GET['id'] ?? 0);
$filterStudent = ($role !== 'student') ? (int) ($_GET['student'] ?? 0) : 0;

$forum = forum_load($pdo, $assignmentId, $userId, $role);
if (!$forum) {
    http_response_code(404);
    exit('Foro no encontrado o no tienes acceso.');
}

$isStaff = $role !== 'student';
$backUrl = $role === 'student'
    ? '../student/assignment.php?id=' . $assignmentId
    : '../teacher/assignment_detail.php?id=' . $assignmentId;

// ¿Puede publicar? (el profesor siempre; el estudiante mientras la asignación esté abierta)
$lockReason = null;
if ($role === 'student') {
    $lockReason = assignment_lock_reason($pdo, $assignmentId, $userId);
}
$canPost = $role === 'teacher' || ($role === 'student' && $lockReason === null);

$errors = [];

// -------------------------------------------------------------------- acciones (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $body = trim((string) ($_POST['body'] ?? ''));
    $postId = (int) ($_POST['post_id'] ?? 0);

    if (in_array($action, ['comment', 'reply'], true) && !$canPost) {
        $errors[] = $lockReason ?? 'No puedes publicar en este foro.';
    } elseif (in_array($action, ['comment', 'reply', 'edit'], true) && ($body === '' || mb_strlen($body) > FORUM_BODY_MAX)) {
        $errors[] = 'El mensaje no puede estar vacío ni pasar de ' . FORUM_BODY_MAX . ' caracteres.';
    } else {
        switch ($action) {
            case 'comment':
                $pdo->prepare('INSERT INTO forum_posts (assignment_id, author_id, body, created_at) VALUES (:a,:u,:b,:c)')
                    ->execute(['a' => $assignmentId, 'u' => $userId, 'b' => $body, 'c' => now_datetime()]);
                if ($role === 'student') {
                    forum_sync_submission($pdo, $forum, $userId);
                }
                redirect('forum/view.php?id=' . $assignmentId . '#c' . (int) $pdo->lastInsertId());
                break;

            case 'reply':
                $parent = forum_post($pdo, $assignmentId, (int) ($_POST['parent_id'] ?? 0));
                if (!$parent || $parent['parent_id'] !== null) {
                    $errors[] = 'El comentario no existe.';
                    break;
                }
                if (!forum_can_see_others($pdo, $forum, $userId, $role)) {
                    $errors[] = 'Primero publica tu propio comentario para poder responder a tus compañeros.';
                    break;
                }
                $pdo->prepare('INSERT INTO forum_posts (assignment_id, parent_id, author_id, body, created_at) VALUES (:a,:p,:u,:b,:c)')
                    ->execute(['a' => $assignmentId, 'p' => $parent['id'], 'u' => $userId, 'b' => $body, 'c' => now_datetime()]);
                if ($role === 'student') {
                    forum_sync_submission($pdo, $forum, $userId);
                }
                redirect('forum/view.php?id=' . $assignmentId . '#c' . (int) $parent['id']);
                break;

            case 'edit':
                $post = forum_post($pdo, $assignmentId, $postId);
                if (!$post || ((int) $post['author_id'] !== $userId && $role !== 'teacher')) {
                    $errors[] = 'No puedes editar ese mensaje.';
                    break;
                }
                if ($role === 'student' && $lockReason !== null) {
                    $errors[] = $lockReason;
                    break;
                }
                $pdo->prepare('UPDATE forum_posts SET body = :b, edited_at = :e WHERE id = :id')
                    ->execute(['b' => $body, 'e' => now_datetime(), 'id' => $postId]);
                redirect('forum/view.php?id=' . $assignmentId . '#c' . (int) ($post['parent_id'] ?? $post['id']));
                break;

            case 'delete':
                $post = forum_post($pdo, $assignmentId, $postId);
                $mine = $post && (int) $post['author_id'] === $userId && ($role === 'teacher' || $lockReason === null);
                if (!$post || (!$mine && $role !== 'teacher' && $role !== 'admin')) {
                    $errors[] = 'No puedes borrar ese mensaje.';
                    break;
                }
                // Un comentario borrado se lleva sus respuestas (se ocultan junto con él)
                $pdo->prepare('UPDATE forum_posts SET deleted_at = :d WHERE id = :id OR parent_id = :id2')
                    ->execute(['d' => now_datetime(), 'id' => $postId, 'id2' => $postId]);
                redirect('forum/view.php?id=' . $assignmentId);
                break;

            case 'pin':
                if ($role !== 'teacher' && $role !== 'admin') {
                    $errors[] = 'Solo el profesor puede hacer esto.';
                    break;
                }
                $pdo->prepare('UPDATE forum_posts SET is_pinned = 1 - is_pinned WHERE id = :id AND assignment_id = :a AND parent_id IS NULL')
                    ->execute(['id' => $postId, 'a' => $assignmentId]);
                redirect('forum/view.php?id=' . $assignmentId);
                break;
        }
    }
}

// -------------------------------------------------------------------- datos para mostrar
$canSee = forum_can_see_others($pdo, $forum, $userId, $role);
$comments = $canSee
    ? forum_comments($pdo, $assignmentId, $filterStudent ?: null)
    : forum_comments($pdo, $assignmentId, $userId);   // "ver después de participar": por ahora solo lo suyo
if (!$canSee) {   // sin comentario propio todavía no hay nada que mostrar; con él, ya se ve todo (canSee sería true)
    $comments = array_values(array_filter($comments, fn($c) => (int) $c['author_id'] === $userId));
}
$mine = $role === 'student' ? forum_participation($pdo, $assignmentId, $userId, $forum) : null;
$table = $isStaff ? forum_participation_table($pdo, $assignmentId) : [];
$total = 0;
foreach ($comments as $c) { $total += 1 + count($c['replies']); }

$pageTitle = $forum['title'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  .fpost { border:1px solid var(--color-border); border-radius:12px; padding:12px 14px; margin-bottom:10px; background:#fff; }
  .fpost.teacher { border-color:#BFD9FF; background:#F5F9FF; }
  .freply { margin:10px 0 0 18px; padding:10px 12px; border-left:3px solid #D7E3F5; background:#FAFBFD; border-radius:8px; }
  .fmeta { color:var(--color-text-muted); font-size:0.82rem; margin-bottom:6px; }
  .fbody { word-break:break-word; }
  .ftools { margin-top:8px; display:flex; gap:10px; flex-wrap:wrap; font-size:0.82rem; align-items:flex-start; }
  .ftools button { background:none; border:none; padding:0; color:var(--color-primary); cursor:pointer; font:inherit; }
  .ftools .danger { color:#B91C1C; }
  .pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:0.72rem; font-weight:700; background:#EEF2F7; color:#475569; margin-left:6px; }
</style>

<p><a href="<?= e($backUrl) ?>">&larr; Volver a la asignación</a></p>
<h1 style="margin-bottom:4px;">💬 <?= e($forum['title']) ?></h1>
<p class="text-muted" style="margin-top:0;">
    <?= e($forum['subject_name']) ?> · <?= (int) $forum['points'] ?> pts
    <?= $forum['due_date'] ? '· Cierra: ' . e(dt_format($forum['due_date'])) : '' ?>
</p>

<?php if (!empty($forum['description'])): ?>
    <div class="card" style="border-color:#BFD9FF;"><?= render_description($forum['description'], $forum['description_format'] ?? 'text') ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($lockReason !== null): ?><div class="alert alert-error"><?= e($lockReason) ?> Puedes leer el foro, pero ya no publicar.</div><?php endif; ?>

<?php if ($mine): ?>
    <div class="alert <?= $mine['complete'] ? 'alert-success' : '' ?>" style="<?= $mine['complete'] ? '' : 'background:#F8FAFC; border:1px solid var(--color-border);' ?>">
        <strong>Tu participación:</strong> <?= (int) $mine['threads'] ?>/<?= (int) $forum['forum_min_threads'] ?> comentario(s) ·
        <?= (int) $mine['replies'] ?>/<?= (int) $forum['forum_min_replies'] ?> respuesta(s) a compañeros
        <?= $mine['complete'] ? ' — ✅ ¡Cumpliste lo pedido! Tu profesor calificará tu participación.' : '' ?>
    </div>
<?php endif; ?>

<?php if ($canPost && !$filterStudent): ?>
    <form method="post" class="card">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="comment">
        <label for="body" style="margin-top:0;"><?= $role === 'teacher' ? 'Escribe un comentario para guiar la discusión' : 'Tu comentario' ?></label>
        <textarea id="body" name="body" rows="4" maxlength="<?= FORUM_BODY_MAX ?>" required placeholder="Escribe tu opinión o respuesta al tema del foro..."></textarea>
        <button class="btn" type="submit">Publicar comentario</button>
    </form>
<?php endif; ?>

<?php if (!$canSee): ?>
    <div class="alert" style="background:#FFF8E6; border:1px solid #F2D98B;">🔒 Podrás ver los comentarios de tus compañeros y responderles cuando publiques el tuyo.</div>
<?php endif; ?>

<?php if ($filterStudent): ?>
    <p><a href="view.php?id=<?= (int) $assignmentId ?>">&larr; Ver todo el foro</a> · Mostrando los comentarios donde participó este estudiante.</p>
<?php endif; ?>

<h2>Comentarios (<?= count($comments) ?>)</h2>
<?php if (empty($comments)): ?>
    <p class="text-muted"><?= $canSee ? 'Todavía no hay comentarios. ¡Sé el primero en participar!' : 'Aún no has publicado tu comentario.' ?></p>
<?php endif; ?>

<?php foreach ($comments as $c): ?>
    <div class="fpost <?= $c['author_role'] === 'teacher' ? 'teacher' : '' ?>" id="c<?= (int) $c['id'] ?>">
        <div class="fmeta">
            <strong><?= e($c['author_name']) ?></strong><?= $c['author_role'] === 'teacher' ? '<span class="pill">Profesor</span>' : '' ?>
            · <?= e(date('d/m/Y H:i', strtotime($c['created_at']))) ?><?= $c['edited_at'] ? ' · (editado)' : '' ?>
            <?= (int) $c['is_pinned'] ? '<span class="pill">📌 Fijado</span>' : '' ?>
        </div>
        <?php if (!empty($c['title'])): ?><strong><?= e($c['title']) ?></strong><br><?php endif; ?>
        <div class="fbody"><?= forum_render_body($c['body']) ?></div>
        <?php $tp = $c; require __DIR__ . '/_tools.php'; ?>

        <?php foreach ($c['replies'] as $r): ?>
            <div class="freply">
                <div class="fmeta">
                    <strong><?= e($r['author_name']) ?></strong><?= $r['author_role'] === 'teacher' ? '<span class="pill">Profesor</span>' : '' ?>
                    · <?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?><?= $r['edited_at'] ? ' · (editado)' : '' ?>
                </div>
                <div class="fbody"><?= forum_render_body($r['body']) ?></div>
                <?php $tp = $r; require __DIR__ . '/_tools.php'; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($canPost && $canSee): ?>
            <details style="margin-top:8px;">
                <summary style="cursor:pointer; color:var(--color-primary); font-size:0.9rem;">↩️ Responder</summary>
                <form method="post" style="margin-top:8px;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="parent_id" value="<?= (int) $c['id'] ?>">
                    <textarea name="body" rows="3" maxlength="<?= FORUM_BODY_MAX ?>" required placeholder="Responde a <?= e($c['author_name']) ?>..."></textarea>
                    <button class="btn" type="submit" style="margin:6px 0 0; padding:6px 14px;">Responder</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($isStaff): ?>
    <h2>Participación de los estudiantes</h2>
    <div class="card" style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse;">
            <tr><th align="left">Estudiante</th><th>Comentarios</th><th>Respuestas</th><th>Cumple</th><th></th></tr>
            <?php foreach ($table as $row):
                $ok = (int) $row['threads'] >= (int) $forum['forum_min_threads'] && (int) $row['replies'] >= (int) $forum['forum_min_replies']; ?>
                <tr style="border-top:1px solid var(--color-border);">
                    <td style="padding:6px 4px;"><?= e($row['name']) ?></td>
                    <td align="center"><?= (int) $row['threads'] ?></td>
                    <td align="center"><?= (int) $row['replies'] ?></td>
                    <td align="center"><?= $ok ? '✅' : '—' ?></td>
                    <td align="right"><a href="view.php?id=<?= (int) $assignmentId ?>&amp;student=<?= (int) $row['id'] ?>">Ver su participación</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="text-muted" style="font-size:0.85rem; margin-bottom:0;">
            Mínimo pedido: <?= (int) $forum['forum_min_threads'] ?> comentario(s) y <?= (int) $forum['forum_min_replies'] ?> respuesta(s).
            Visibilidad: <?= $forum['forum_mode'] === 'qa' ? 'los estudiantes ven los comentarios de los demás solo después de publicar el suyo' : 'los estudiantes ven todos los comentarios sin necesidad de participar' ?>.
            La calificación se pone en la pantalla de la asignación.
        </p>
    </div>
<?php endif; ?>
<?php if ($role === 'student') { echo paste_guard_tags(!empty($forum['block_paste'])); } ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
