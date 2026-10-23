<?php
/** Botones de un mensaje del foro (editar / borrar / fijar / cerrar). Se incluye desde view.php con $tp = el mensaje. */
if (!defined('AULA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); }

$isRoot = $tp['parent_id'] === null;
$isMine = (int) $tp['author_id'] === $userId;
$canEdit = ($isMine && ($role === 'teacher' || $lockReason === null)) || $role === 'teacher';
$canDelete = $canEdit || $role === 'admin';
?>
<?php if ($canEdit || $canDelete || (($role === 'teacher' || $role === 'admin') && $isRoot)): ?>
    <div class="ftools">
        <?php if ($canEdit): ?>
            <details>
                <summary style="cursor:pointer; color:var(--color-primary);">Editar</summary>
                <form method="post" style="margin-top:8px;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="post_id" value="<?= (int) $tp['id'] ?>">
                    <textarea name="body" rows="4" maxlength="<?= FORUM_BODY_MAX ?>" required><?= e($tp['body']) ?></textarea>
                    <button class="btn" type="submit" style="margin:6px 0 0; padding:6px 12px;">Guardar cambios</button>
                </form>
            </details>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <form method="post" onsubmit="return confirm('¿Borrar este mensaje<?= $isRoot ? ' y todas sus respuestas' : '' ?>?')" style="display:inline;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="post_id" value="<?= (int) $tp['id'] ?>">
                <button type="submit" class="danger">Borrar</button>
            </form>
        <?php endif; ?>
        <?php if (($role === 'teacher' || $role === 'admin') && $isRoot): ?>
            <form method="post" style="display:inline;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="pin">
                <input type="hidden" name="post_id" value="<?= (int) $tp['id'] ?>">
                <button type="submit"><?= (int) $tp['is_pinned'] ? 'Quitar fijado' : '📌 Fijar' ?></button>
            </form>
            <form method="post" style="display:inline;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="post_id" value="<?= (int) $tp['id'] ?>">
                <button type="submit"><?= (int) $tp['is_closed'] ? 'Reabrir' : '🔒 Cerrar tema' ?></button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
