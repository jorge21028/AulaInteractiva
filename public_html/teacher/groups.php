<?php
/**
 * Armar los grupos de una asignación "en grupos": reparto automático al azar, grupos manuales y movimiento
 * de estudiantes entre grupos. Un solo trabajo y una sola calificación por grupo.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/group_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = (int) current_user_id();
$assignmentId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT a.*, s.name AS subject_name FROM assignments a INNER JOIN subjects s ON s.id = a.subject_id WHERE a.id = :id AND a.teacher_id = :tid');
$stmt->execute(['id' => $assignmentId, 'tid' => $teacherId]);
$assignment = $stmt->fetch();
if (!$assignment || empty($assignment['group_mode'])) {
    http_response_code(404);
    exit('Asignación no encontrada o no es una asignación en grupos.');
}

$errors = [];
$notice = $_GET['ok'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $validStudents = array_map(fn($s) => (int) $s['id'], group_assignment_students($pdo, $assignmentId));
    $validGroups = array_map(fn($g) => (int) $g['id'], group_list($pdo, $assignmentId));

    try {
        $pdo->beginTransaction();
        if ($action === 'auto') {
            $n = group_auto_create($pdo, $assignmentId, (int) ($_POST['size'] ?? $assignment['group_size']));
            $msg = $n > 0 ? "Se crearon {$n} grupo(s) con los estudiantes que no tenían grupo." : 'No hay estudiantes sin grupo.';
        } elseif ($action === 'new') {
            $name = trim((string) ($_POST['name'] ?? ''));
            group_create($pdo, $assignmentId, $name !== '' ? $name : 'Grupo ' . (count($validGroups) + 1));
            $msg = 'Grupo creado.';
        } elseif ($action === 'save') {
            foreach ((array) ($_POST['names'] ?? []) as $gid => $name) {
                if (in_array((int) $gid, $validGroups, true) && trim((string) $name) !== '') {
                    $pdo->prepare('UPDATE assignment_groups SET name = :n WHERE id = :id')->execute(['n' => mb_substr(trim((string) $name), 0, 80), 'id' => (int) $gid]);
                }
            }
            foreach ((array) ($_POST['member'] ?? []) as $sid => $gid) {
                $sid = (int) $sid;
                $gid = (int) $gid;
                if (in_array($sid, $validStudents, true) && ($gid === 0 || in_array($gid, $validGroups, true))) {
                    group_assign_student($pdo, $assignmentId, $sid, $gid);
                }
            }
            // Un grupo vacío no sirve: se elimina
            foreach (group_list($pdo, $assignmentId) as $g) {
                if (empty($g['members'])) {
                    group_delete($pdo, $assignmentId, (int) $g['id']);
                }
            }
            $msg = 'Grupos guardados.';
        } elseif ($action === 'delete') {
            $gid = (int) ($_POST['group_id'] ?? 0);
            if (in_array($gid, $validGroups, true)) {
                group_delete($pdo, $assignmentId, $gid);
            }
            $msg = 'Grupo eliminado (sus integrantes quedaron sin grupo).';
        } else {
            throw new RuntimeException('Acción desconocida');
        }
        $pdo->commit();
        redirect('teacher/groups.php?id=' . $assignmentId . '&ok=' . urlencode($msg));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('groups: ' . $e->getMessage());
        $errors[] = 'No se pudieron guardar los cambios. Intenta de nuevo.';
    }
}

$students = group_assignment_students($pdo, $assignmentId);
$groups = group_list($pdo, $assignmentId);
$memberOf = [];
foreach ($groups as $g) {
    foreach ($g['members'] as $m) {
        $memberOf[(int) $m['id']] = (int) $g['id'];
    }
}
$free = count(array_filter($students, fn($s) => !isset($memberOf[(int) $s['id']])));

$pageTitle = 'Grupos — ' . $assignment['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="assignment_detail.php?id=<?= (int) $assignmentId ?>">&larr; Volver a la asignación</a></p>
<h1>👥 Grupos: <?= e($assignment['title']) ?></h1>
<p class="text-muted"><?= e($assignment['subject_name']) ?> · Tamaño sugerido: <?= (int) $assignment['group_size'] ?> · <?= count($students) ?> estudiante(s), <?= $free ?> sin grupo.
   Cada grupo trabaja en <strong>un solo trabajo</strong> y recibe <strong>una sola calificación</strong> (se copia a todos).</p>

<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
<?php if ($notice !== ''): ?><div class="alert alert-success"><?= e((string) $notice) ?></div><?php endif; ?>

<div class="card">
    <h2 style="margin-top:0;">Crear grupos</h2>
    <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:flex-end;">
        <form method="post" style="display:flex; gap:8px; align-items:flex-end;">
            <?php csrf_field(); ?><input type="hidden" name="action" value="auto">
            <div><label style="margin-top:0;">Al azar, de a</label><input type="number" name="size" min="2" max="12" value="<?= (int) $assignment['group_size'] ?>" style="width:90px;"></div>
            <button class="btn" type="submit" style="margin:0;" <?= $free === 0 ? 'disabled' : '' ?>>🎲 Repartir a los <?= $free ?> sin grupo</button>
        </form>
        <form method="post" style="display:flex; gap:8px; align-items:flex-end;">
            <?php csrf_field(); ?><input type="hidden" name="action" value="new">
            <div><label style="margin-top:0;">Grupo vacío</label><input type="text" name="name" maxlength="80" placeholder="Nombre (opcional)"></div>
            <button class="btn btn-secondary" type="submit" style="margin:0;">➕ Crear</button>
        </form>
    </div>
</div>

<form method="post" class="card">
    <?php csrf_field(); ?><input type="hidden" name="action" value="save">
    <h2 style="margin-top:0;">Estudiantes y grupos</h2>
    <?php if (!empty($groups)): ?>
        <p class="text-muted" style="margin-bottom:6px;">Nombres de los grupos:</p>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
            <?php foreach ($groups as $g): ?>
                <input type="text" name="names[<?= (int) $g['id'] ?>]" value="<?= e($g['name']) ?>" maxlength="80" style="width:170px;">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse;">
            <tr><th align="left">Estudiante</th><th align="left">Grupo</th></tr>
            <?php foreach ($students as $st): $cur = $memberOf[(int) $st['id']] ?? 0; ?>
                <tr style="border-top:1px solid var(--color-border);">
                    <td style="padding:6px 4px;"><?= e($st['name']) ?></td>
                    <td>
                        <select name="member[<?= (int) $st['id'] ?>]" style="max-width:240px;">
                            <option value="0" <?= $cur === 0 ? 'selected' : '' ?>>— Sin grupo —</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= (int) $g['id'] ?>" <?= $cur === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <button class="btn" type="submit">Guardar grupos</button>
    <p class="text-muted" style="font-size:0.85rem;">Los estudiantes sin grupo no podrán trabajar en esta asignación hasta que los pongas en uno. Si mueves a alguien de un grupo que ya empezó, el trabajo se queda con el grupo.</p>
</form>

<?php if (!empty($groups)): ?>
<div class="card">
    <h2 style="margin-top:0;">Resumen</h2>
    <?php foreach ($groups as $g): ?>
        <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; padding:6px 0; border-top:1px solid var(--color-border);">
            <div><strong><?= e($g['name']) ?></strong> <span class="text-muted">(<?= count($g['members']) ?>)</span><br>
                <span class="text-muted" style="font-size:0.9rem;"><?= e(implode(', ', array_map(fn($m) => $m['name'], $g['members'])) ?: 'Sin integrantes') ?></span></div>
            <form method="post" onsubmit="return confirm('¿Eliminar este grupo? Sus integrantes quedarán sin grupo.')">
                <?php csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
                <button class="btn btn-secondary" type="submit" style="margin:0; color:#B91C1C; border-color:#B91C1C;">Eliminar</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
