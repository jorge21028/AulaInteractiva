<?php
/** Avisos del profesor a sus cursos: crear, editar, fijar, programar y eliminar. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/announcement_helpers.php';
require_once __DIR__ . '/../includes/description_field.php';

require_role('teacher');
$pdo = Database::getConnection();
$teacherId = (int) current_user_id();
$errors = [];
$notice = '';

$courses = announcements_teacher_courses($pdo, $teacherId);
$filterCourse = (int) ($_GET['course'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId) {
    $st = $pdo->prepare('SELECT * FROM announcements WHERE id = :id AND teacher_id = :t');
    $st->execute(['id' => $editId, 't' => $teacherId]);
    $editing = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $title = clean_string($_POST['title'] ?? '');
        [$body, $format] = desc_prepare_for_save((string) ($_POST['body'] ?? ''), $_POST['body_format'] ?? 'text');
        $publish = dt_from_input($_POST['publish_at'] ?? '') ?? now_datetime();
        $expires = dt_from_input($_POST['expires_at'] ?? '');
        $pinned = !empty($_POST['is_pinned']) ? 1 : 0;

        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = 'Escribe un título (máximo 200 caracteres).';
        }
        if (trim(strip_tags($body, '<img><iframe><video>')) === '') {
            $errors[] = 'Escribe el contenido del aviso.';
        }
        if (!announcement_teaches_course($pdo, $teacherId, $courseId)) {
            $errors[] = 'Elige uno de tus cursos.';
        }
        if ($expires !== null && $expires <= $publish) {
            $errors[] = 'La fecha para ocultar el aviso debe ser posterior a la de publicación.';
        }

        if (empty($errors)) {
            if ($id) {
                $pdo->prepare(
                    'UPDATE announcements SET course_id = :c, title = :t, body = :b, body_format = :f, is_pinned = :p, publish_at = :pub, expires_at = :exp, updated_at = :now
                     WHERE id = :id AND teacher_id = :tid'
                )->execute(['c' => $courseId, 't' => $title, 'b' => $body, 'f' => $format, 'p' => $pinned, 'pub' => $publish, 'exp' => $expires, 'now' => now_datetime(), 'id' => $id, 'tid' => $teacherId]);
                $notice = 'Aviso actualizado.';
            } else {
                $pdo->prepare(
                    'INSERT INTO announcements (course_id, teacher_id, title, body, body_format, is_pinned, publish_at, expires_at, created_at)
                     VALUES (:c, :tid, :t, :b, :f, :p, :pub, :exp, :now)'
                )->execute(['c' => $courseId, 'tid' => $teacherId, 't' => $title, 'b' => $body, 'f' => $format, 'p' => $pinned, 'pub' => $publish, 'exp' => $expires, 'now' => now_datetime()]);
                $notice = $publish > now_datetime() ? 'Aviso programado: se publicará el ' . dt_format($publish) . '.' : 'Aviso publicado: los estudiantes del curso ya lo ven.';
            }
            audit_log($pdo, $teacherId, $id ? 'update_announcement' : 'create_announcement', "Aviso '{$title}' en el curso #{$courseId}");
            $editing = null;
            $editId = 0;
        } else {
            $editing = ['id' => $id, 'course_id' => $courseId, 'title' => $title, 'body' => (string) ($_POST['body'] ?? ''), 'body_format' => $_POST['body_format'] ?? 'text',
                        'is_pinned' => $pinned, 'publish_at' => $_POST['publish_at'] ?? '', 'expires_at' => $_POST['expires_at'] ?? '', '_raw' => true];
        }
    } elseif ($action === 'delete' && $id) {
        $pdo->prepare('DELETE FROM announcements WHERE id = :id AND teacher_id = :t')->execute(['id' => $id, 't' => $teacherId]);
        $pdo->prepare("DELETE FROM notifications WHERE type = 'announcement' AND ref_id = :id")->execute(['id' => $id]);
        audit_log($pdo, $teacherId, 'delete_announcement', "Aviso #{$id} eliminado");
        $notice = 'Aviso eliminado.';
    } elseif ($action === 'pin' && $id) {
        $pdo->prepare('UPDATE announcements SET is_pinned = 1 - is_pinned WHERE id = :id AND teacher_id = :t')->execute(['id' => $id, 't' => $teacherId]);
        $notice = 'Aviso actualizado.';
    }
}

$list = announcements_for_teacher($pdo, $teacherId, $filterCourse ?: null);
$form = $editing ?? ['id' => 0, 'course_id' => $filterCourse, 'title' => '', 'body' => '', 'body_format' => 'text', 'is_pinned' => 0, 'publish_at' => '', 'expires_at' => ''];
$isRaw = !empty($form['_raw']);

$pageTitle = 'Avisos';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mis cursos</a></p>
<h1>📢 Avisos a tus cursos</h1>
<p class="text-muted" style="margin-top:-8px;">Escribe un aviso y los estudiantes del curso lo ven en su panel (y les llega a su campanita 🔔). Ejemplo: “Mañana traigan una calculadora”.</p>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>

<?php if (empty($courses)): ?>
    <div class="card"><p class="empty-state">Necesitas al menos un curso activo para publicar avisos.</p></div>
<?php else: ?>
<section class="card">
    <h2 style="margin-top:0;"><?= !empty($form['id']) ? '✏️ Editar aviso' : '➕ Nuevo aviso' ?></h2>
    <form method="post" action="announcements.php" class="form-narrow" style="margin:0;">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

        <label for="course_id">Curso</label>
        <select id="course_id" name="course_id" required>
            <option value="">— Elige —</option>
            <?php foreach ($courses as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $form['course_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="title">Título</label>
        <input type="text" id="title" name="title" maxlength="200" required value="<?= e($form['title']) ?>" placeholder="Ej: Material para mañana">

        <?php render_description_field('body', 'Mensaje', (string) $form['body'], (string) $form['body_format'], 5); ?>

        <label for="publish_at">Publicar el (opcional: vacío = ahora)</label>
        <input type="datetime-local" id="publish_at" name="publish_at" value="<?= e($isRaw ? (string) $form['publish_at'] : dt_to_input($form['publish_at'] ?? '')) ?>">
        <label for="expires_at">Ocultar el (opcional)</label>
        <input type="datetime-local" id="expires_at" name="expires_at" value="<?= e($isRaw ? (string) $form['expires_at'] : dt_to_input($form['expires_at'] ?? '')) ?>">
        <p class="text-muted" style="font-size:0.8rem; margin:2px 0 10px;">Hora actual del servidor: <?= e(dt_format(now_datetime())) ?>.</p>

        <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
            <input type="checkbox" name="is_pinned" value="1" style="width:auto;" <?= !empty($form['is_pinned']) ? 'checked' : '' ?>> 📌 Fijar arriba (se queda visible aunque pasen los días)
        </label>
        <button type="submit" class="btn"><?= !empty($form['id']) ? 'Guardar cambios' : 'Publicar aviso' ?></button>
        <?php if (!empty($form['id'])): ?><a class="btn btn-secondary" href="announcements.php">Cancelar</a><?php endif; ?>
    </form>
</section>

<section style="margin-top:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <h2 style="margin:0;">Mis avisos</h2>
        <form method="get" style="margin:0;"><select name="course" onchange="this.form.submit()" style="margin:0;">
            <option value="">Todos los cursos</option>
            <?php foreach ($courses as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $filterCourse === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></form>
    </div>
    <?php if (empty($list)): ?>
        <p class="empty-state">Todavía no has publicado avisos.</p>
    <?php else: foreach ($list as $a):
        $state = ['scheduled' => ['🕒 Programado', '#5B21B6'], 'active' => ['✅ Visible', '#166534'], 'expired' => ['⌛ Oculto (expiró)', '#64748B']][$a['state']];
    ?>
        <article class="card" style="margin-top:10px;">
            <div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                <strong><?= (int) $a['is_pinned'] === 1 ? '📌 ' : '' ?><?= e($a['title']) ?></strong>
                <span style="font-size:0.8rem; color:<?= $state[1] ?>; font-weight:700;"><?= $state[0] ?></span>
            </div>
            <div class="text-muted" style="font-size:0.8rem;"><?= e($a['course_name']) ?> · <?= (int) $a['students_count'] ?> estudiante(s) · publicado <?= e(dt_format($a['publish_at'])) ?><?= $a['expires_at'] ? ' · se oculta ' . e(dt_format($a['expires_at'])) : '' ?></div>
            <div style="margin-top:8px;"><?= render_description($a['body'], $a['body_format']) ?></div>
            <div style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;">
                <a class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" href="announcements.php?edit=<?= (int) $a['id'] ?>">✏️ Editar</a>
                <form method="post" style="margin:0;"><?php csrf_field(); ?><input type="hidden" name="action" value="pin"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;" type="submit"><?= (int) $a['is_pinned'] === 1 ? 'Quitar fijado' : '📌 Fijar' ?></button></form>
                <form method="post" style="margin:0;" onsubmit="return confirm('¿Eliminar este aviso?')"><?php csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem; color:#B91C1C; border-color:#B91C1C;" type="submit">🗑️ Eliminar</button></form>
            </div>
        </article>
    <?php endforeach; endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
