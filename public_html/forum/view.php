<?php
/**
 * Foro de discusión (tipo Moodle). Lo usan estudiantes (publicar y responder), el profesor dueño (moderar y ver la
 * participación de cada estudiante) y el administrador (solo lectura/moderación).
 *   ?id=ASIGNACION            lista de temas
 *   ?id=ASIGNACION&thread=ID  un tema con sus respuestas
 *   ?id=ASIGNACION&student=ID (profesor) solo las participaciones de ese estudiante
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/description_helpers.php';
require_once __DIR__ . '/../includes/forum_helpers.php';

require_role(['student', 'teacher', 'admin']);

$pdo = Database::getConnection();
$userId = (int) current_user_id();
$role = (string) current_role();
$assignmentId = (int) ($_GET['id'] ?? 0);
$threadId = (int) ($_GET['thread'] ?? 0);
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
$here = 'view.php?id=' . $assignmentId;

// ¿Puede publicar? (el profesor siempre; el estudiante mientras la asignación esté abierta)
$lockReason = null;
if ($role === 'student') {
    $lockReason = assignment_lock_reason($pdo, $assignmentId, $userId);
}
$canPost = $role === 'teacher' || ($role === 'student' && $lockReason === null);
$canStartThread = $role === 'teacher' || ($role === 'student' && (int) $forum['forum_students_start'] === 1);

$errors = [];

// -------------------------------------------------------------------- acciones (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $body = trim((string) ($_POST['body'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $postId = (int) ($_POST['post_id'] ?? 0);

    if (in_array($action, ['new_thread', 'reply', 'edit'], true) && !$canPost && $action !== 'edit') {
        $errors[] = $lockReason ?? 'No puedes publicar en este foro.';
    } elseif (in_array($action, ['new_thread', 'reply', 'edit'], true) && ($body === '' || mb_strlen($body) > FORUM_BODY_MAX)) {
        $errors[] = 'El mensaje no puede estar vacío ni pasar de ' . FORUM_BODY_MAX . ' caracteres.';
    } else {
        switch ($action) {
            case 'new_thread':
                if (!$canStartThread) {
                    $errors[] = 'En este foro solo el profesor abre temas nuevos.';
                    break;
                }
                if ($title === '' || mb_strlen($title) > FORUM_TITLE_MAX) {
                    $errors[] = 'Escribe un título para el tema (máx. ' . FORUM_TITLE_MAX . ' caracteres).';
                    break;
                }
                $pdo->prepare('INSERT INTO forum_posts (assignment_id, author_id, title, body, created_at) VALUES (:a,:u,:t,:b,:c)')
                    ->execute(['a' => $assignmentId, 'u' => $userId, 't' => $title, 'b' => $body, 'c' => now_datetime()]);
                $newId = (int) $pdo->lastInsertId();
                if ($role === 'student') {
                    forum_sync_submission($pdo, $forum, $userId);
                }
                redirect('forum/view.php?id=' . $assignmentId . '&thread=' . $newId);
                break;

            case 'reply':
                $thread = forum_post($pdo, $assignmentId, (int) ($_POST['thread_id'] ?? 0));
                if (!$thread || $thread['parent_id'] !== null) {
                    $errors[] = 'El tema no existe.';
                    break;
                }
                if ((int) $thread['is_closed'] === 1) {
                    $errors[] = 'Este tema está cerrado.';
                    break;
                }
                if (!forum_can_see_others($pdo, $forum, $userId, $role)) {
                    $errors[] = 'Primero publica tu propio tema para poder participar.';
                    break;
                }
                $pdo->prepare('INSERT INTO forum_posts (assignment_id, parent_id, author_id, body, created_at) VALUES (:a,:p,:u,:b,:c)')
                    ->execute(['a' => $assignmentId, 'p' => $thread['id'], 'u' => $userId, 'b' => $body, 'c' => now_datetime()]);
                if ($role === 'student') {
                    forum_sync_submission($pdo, $forum, $userId);
                }
                redirect('forum/view.php?id=' . $assignmentId . '&thread=' . $thread['id'] . '#end');
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
                redirect('forum/view.php?id=' . $assignmentId . '&thread=' . ($post['parent_id'] ?? $post['id']));
                break;

            case 'delete':
                $post = forum_post($pdo, $assignmentId, $postId);
                $mine = $post && (int) $post['author_id'] === $userId && ($role === 'teacher' || $lockReason === null);
                if (!$post || (!$mine && $role !== 'teacher' && $role !== 'admin')) {
                    $errors[] = 'No puedes borrar ese mensaje.';
                    break;
                }
                // Un tema borrado se lleva sus respuestas (se ocultan junto con él)
                $pdo->prepare('UPDATE forum_posts SET deleted_at = :d WHERE id = :id OR parent_id = :id2')
                    ->execute(['d' => now_datetime(), 'id' => $postId, 'id2' => $postId]);
                redirect('forum/view.php?id=' . $assignmentId . ($post['parent_id'] ? '&thread=' . $post['parent_id'] : ''));
                break;

            case 'pin':
            case 'close':
                if ($role !== 'teacher' && $role !== 'admin') {
                    $errors[] = 'Solo el profesor puede hacer esto.';
                    break;
                }
                $col = $action === 'pin' ? 'is_pinned' : 'is_closed';
                $pdo->prepare("UPDATE forum_posts SET {$col} = 1 - {$col} WHERE id = :id AND assignment_id = :a AND parent_id IS NULL")
                    ->execute(['id' => $postId, 'a' => $assignmentId]);
                redirect('forum/view.php?id=' . $assignmentId . ($threadId ? '&thread=' . $threadId : ''));
                break;
        }
    }
}

// -------------------------------------------------------------------- datos para mostrar
$canSee = forum_can_see_others($pdo, $forum, $userId, $role);
$thread = $threadId ? forum_post($pdo, $assignmentId, $threadId) : null;
if ($thread && $thread['parent_id'] !== null) {
    $thread = null;
}
if ($thread && !$canSee && (int) $thread['author_id'] !== $userId) {
    $thread = null; // modo pregunta-respuesta: no puede abrir temas ajenos todavía
}
$replies = $thread ? forum_replies($pdo, (int) $thread['id']) : [];
$threads = (!$thread) ? forum_threads($pdo, $assignmentId, $filterStudent ?: ($canSee ? null : $userId)) : [];
$mine = $role === 'student' ? forum_participation($pdo, $assignmentId, $userId, $forum) : null;
$table = $isStaff && !$thread ? forum_participation_table($pdo, $assignmentId) : [];

$pageTitle = $forum['title'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  .fpost { border:1px solid var(--color-border); border-radius:12px; padding:12px 14px; margin-bottom:10px; background:#fff; }
  .fpost.teacher { border-color:#BFD9FF; background:#F5F9FF; }
  .fmeta { color:var(--color-text-muted); font-size:0.82rem; margin-bottom:6px; }
  .fbody { word-break:break-word; }
  .ftools { margin-top:8px; display:flex; gap:10px; flex-wrap:wrap; font-size:0.82rem; }
  .ftools button { background:none; border:none; padding:0; color:var(--color-primary); cursor:pointer; font:inherit; }
  .ftools .danger { color:#B91C1C; }
  .trow { display:block; border:1px solid var(--color-border); border-radius:12px; padding:12px 14px; margin-bottom:8px; background:#fff; text-decoration:none; color:inherit; }
  .trow:hover { border-color:var(--color-primary); }
  .pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:0.72rem; font-weight:700; background:#EEF2F7; color:#475569; margin-left:6px; }
</style>

<p><a href="<?= e($thread ? $here : $backUrl) ?>">&larr; <?= $thread ? 'Volver a los temas' : 'Volver a la asignación' ?></a></p>
<h1 style="margin-bottom:4px;">💬 <?= e($forum['title']) ?></h1>
<p class="text-muted" style="margin-top:0;">
    <?= e($forum['subject_name']) ?> · <?= (int) $forum['points'] ?> pts
    <?= $forum['due_date'] ? '· Cierra: ' . e(dt_format($forum['due_date'])) : '' ?>
    · <?= $forum['forum_mode'] === 'qa' ? 'Modo pregunta-respuesta' : 'Foro general' ?>
</p>

<?php if (!empty($forum['description'])): ?>
    <div class="card"><?= render_description($forum['description'], $forum['description_format'] ?? 'text') ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($lockReason !== null): ?><div class="alert alert-error"><?= e($lockReason) ?> Puedes leer el foro, pero ya no publicar.</div><?php endif; ?>

<?php if ($mine): ?>
    <div class="alert <?= $mine['complete'] ? 'alert-success' : '' ?>" style="<?= $mine['complete'] ? '' : 'background:#F8FAFC; border:1px solid var(--color-border);' ?>">
        <strong>Tu participación:</strong> <?= (int) $mine['threads'] ?>/<?= (int) $forum['forum_min_threads'] ?> tema(s) ·
        <?= (int) $mine['replies'] ?>/<?= (int) $forum['forum_min_replies'] ?> respuesta(s)
        <?= $mine['complete'] ? ' — ✅ ¡Cumpliste lo pedido! Tu profesor calificará tu participación.' : '' ?>
    </div>
<?php endif; ?>
<?php if ($forum['forum_mode'] === 'qa' && !$canSee): ?>
    <div class="alert" style="background:#FFF8E6; border:1px solid #F2D98B;">🔒 Foro pregunta-respuesta: publica primero tu propio tema y entonces podrás ver y responder los de tus compañeros.</div>
<?php endif; ?>

<?php if ($thread): ?>
    <div class="fpost">
        <div class="fmeta">
            <strong><?= e($thread['author_name']) ?></strong> · <?= e(date('d/m/Y H:i', strtotime($thread['created_at']))) ?>
            <?= $thread['edited_at'] ? ' · (editado)' : '' ?>
            <?= (int) $thread['is_pinned'] ? '<span class="pill">📌 Fijado</span>' : '' ?>
            <?= (int) $thread['is_closed'] ? '<span class="pill">🔒 Cerrado</span>' : '' ?>
        </div>
        <h2 style="margin:0 0 8px;"><?= e($thread['title']) ?></h2>
        <div class="fbody"><?= forum_render_body($thread['body']) ?></div>
        <?php $tp = $thread; require __DIR__ . '/_tools.php'; ?>
    </div>

    <h3><?= count($replies) ?> respuesta(s)</h3>
    <?php if (!$canSee && (int) $thread['author_id'] === $userId): ?>
        <p class="text-muted">Las respuestas de tus compañeros se mostrarán cuando publiques tu tema.</p>
    <?php endif; ?>
    <?php foreach ($replies as $r): ?>
        <div class="fpost <?= $r['author_role'] === 'teacher' ? 'teacher' : '' ?>">
            <div class="fmeta">
                <strong><?= e($r['author_name']) ?></strong><?= $r['author_role'] === 'teacher' ? '<span class="pill">Profesor</span>' : '' ?>
                · <?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?><?= $r['edited_at'] ? ' · (editado)' : '' ?>
            </div>
            <div class="fbody"><?= forum_render_body($r['body']) ?></div>
            <?php $tp = $r; require __DIR__ . '/_tools.php'; ?>
        </div>
    <?php endforeach; ?>
    <span id="end"></span>

    <?php if ($canPost && !(int) $thread['is_closed'] && ($canSee || (int) $thread['author_id'] === $userId)): ?>
        <?php if ($canSee): ?>
            <form method="post" class="card" style="margin-top:14px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="thread_id" value="<?= (int) $thread['id'] ?>">
                <label for="body">Tu respuesta</label>
                <textarea id="body" name="body" rows="4" maxlength="<?= FORUM_BODY_MAX ?>" required></textarea>
                <button class="btn" type="submit">Responder</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

<?php else: ?>

    <?php if ($canPost && $canStartThread): ?>
        <details class="card" <?= empty($threads) ? 'open' : '' ?>>
            <summary style="cursor:pointer; font-weight:700;">➕ Abrir un tema nuevo</summary>
            <form method="post" style="margin-top:10px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="new_thread">
                <label for="title">Título</label>
                <input type="text" id="title" name="title" maxlength="<?= FORUM_TITLE_MAX ?>" required>
                <label for="body">Mensaje</label>
                <textarea id="body" name="body" rows="5" maxlength="<?= FORUM_BODY_MAX ?>" required></textarea>
                <button class="btn" type="submit">Publicar tema</button>
            </form>
        </details>
    <?php elseif ($canPost && !$canStartThread): ?>
        <p class="text-muted">En este foro solo el profesor abre temas; tú participas respondiendo.</p>
    <?php endif; ?>

    <h2><?= $filterStudent ? 'Temas de este estudiante' : 'Temas' ?> (<?= count($threads) ?>)</h2>
    <?php if (empty($threads)): ?>
        <p class="text-muted"><?= $canSee ? 'Todavía no hay temas.' : 'Aún no has publicado tu tema.' ?></p>
    <?php endif; ?>
    <?php foreach ($threads as $t): ?>
        <a class="trow" href="<?= e($here . '&thread=' . (int) $t['id']) ?>">
            <strong><?= (int) $t['is_pinned'] ? '📌 ' : '' ?><?= e($t['title']) ?></strong>
            <?= (int) $t['is_closed'] ? '<span class="pill">🔒 Cerrado</span>' : '' ?>
            <div class="fmeta" style="margin:4px 0 0;">
                <?= e($t['author_name']) ?> · <?= (int) $t['reply_count'] ?> respuesta(s) · última actividad <?= e(date('d/m/Y H:i', strtotime($t['last_activity']))) ?>
            </div>
        </a>
    <?php endforeach; ?>

    <?php if ($isStaff): ?>
        <h2>Participación de los estudiantes</h2>
        <div class="card" style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <tr><th align="left">Estudiante</th><th>Temas</th><th>Respuestas</th><th>Cumple</th><th></th></tr>
                <?php foreach ($table as $row):
                    $ok = (int) $row['threads'] >= (int) $forum['forum_min_threads'] && (int) $row['replies'] >= (int) $forum['forum_min_replies']; ?>
                    <tr style="border-top:1px solid var(--color-border);">
                        <td style="padding:6px 4px;"><?= e($row['name']) ?></td>
                        <td align="center"><?= (int) $row['threads'] ?></td>
                        <td align="center"><?= (int) $row['replies'] ?></td>
                        <td align="center"><?= $ok ? '✅' : '—' ?></td>
                        <td align="right"><a href="<?= e($here . '&student=' . (int) $row['id']) ?>">Ver temas</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="text-muted" style="font-size:0.85rem; margin-bottom:0;">Mínimo pedido: <?= (int) $forum['forum_min_threads'] ?> tema(s) y <?= (int) $forum['forum_min_replies'] ?> respuesta(s). La calificación se pone en la pantalla de la asignación.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
