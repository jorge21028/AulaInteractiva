<?php
/**
 * Prórrogas por estudiante: da (o quita) una fecha de entrega especial a estudiantes concretos de una asignación,
 * sin reabrirla para todo el curso.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = (int) current_user_id();
$assignmentId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.start_date, a.due_date, s.name AS subject_name, c.name AS course_name
     FROM assignments a INNER JOIN subjects s ON s.id = a.subject_id INNER JOIN courses c ON c.id = s.course_id
     WHERE a.id = :id AND a.teacher_id = :tid LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'tid' => $teacherId]);
$assignment = $stmt->fetch();
if (!$assignment) {
    http_response_code(404);
    exit('Asignación no encontrada.');
}

$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $ids = array_map('intval', (array) ($_POST['student_ids'] ?? []));
    $action = $_POST['action'] ?? '';

    if (empty($ids)) {
        $errors[] = 'Marca al menos un estudiante.';
    } elseif ($action === 'set') {
        $due = dt_from_input($_POST['due_date'] ?? '', true);
        if ($due === null) {
            $errors[] = 'Elige la nueva fecha y hora de entrega.';
        } elseif (empty($assignment['due_date'])) {
            $errors[] = 'Esta asignación no tiene fecha de entrega, así que no necesita prórroga.';
        } elseif ($due <= $assignment['due_date']) {
            $errors[] = 'La prórroga debe ser posterior a la fecha de entrega general (' . dt_format($assignment['due_date']) . ').';
        } else {
            $n = extension_set($pdo, $assignmentId, $ids, $due, clean_string($_POST['note'] ?? ''), $teacherId);
            audit_log($pdo, $teacherId, 'extension_set', "Asignación #{$assignmentId}: prórroga hasta {$due} a {$n} estudiante(s)");
            $notice = "Prórroga aplicada a {$n} estudiante(s): ahora tienen hasta el " . dt_format($due) . '.';
        }
    } elseif ($action === 'clear') {
        $n = extension_clear($pdo, $assignmentId, $ids);
        audit_log($pdo, $teacherId, 'extension_clear', "Asignación #{$assignmentId}: prórroga quitada a {$n} estudiante(s)");
        $notice = "Prórroga quitada a {$n} estudiante(s): vuelven a la fecha general.";
    }
}

$rows = extensions_for_assignment($pdo, $assignmentId);
$now = now_datetime();
$suggest = !empty($assignment['due_date']) ? dt_to_input(date('Y-m-d H:i:s', strtotime($assignment['due_date']) + 3 * 86400)) : '';

$pageTitle = 'Prórrogas';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="assignment_detail.php?id=<?= (int) $assignmentId ?>">&larr; Volver a la asignación</a></p>
<h1>⏰ Prórrogas por estudiante</h1>
<p class="text-muted" style="margin-top:-8px;">
    <?= e($assignment['course_name']) ?> — <?= e($assignment['subject_name']) ?> · <strong><?= e($assignment['title']) ?></strong><br>
    Fecha de entrega general: <strong><?= $assignment['due_date'] ? e(dt_format($assignment['due_date'])) : 'sin fecha' ?></strong>
</p>

<?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>

<?php if (empty($assignment['due_date'])): ?>
    <div class="card"><p class="empty-state">Esta asignación no tiene fecha de entrega, así que no vence y no necesita prórrogas. Si le pones una fecha (✏️ Editar), podrás dar prórrogas aquí.</p></div>
<?php elseif (empty($rows)): ?>
    <div class="card"><p class="empty-state">Esta asignación no tiene estudiantes.</p></div>
<?php else: ?>
<form method="post" action="assignment_extensions.php">
    <?php csrf_field(); ?>
    <input type="hidden" name="id" value="<?= (int) $assignmentId ?>">

    <section class="card">
        <h2 style="margin-top:0;">Dar prórroga a los marcados</h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <div><label for="due_date" style="margin:0;">Nueva fecha y hora de entrega</label>
                <input type="datetime-local" id="due_date" name="due_date" value="<?= e($suggest) ?>" style="margin:0;"></div>
            <div style="flex:1; min-width:200px;"><label for="note" style="margin:0;">Motivo (opcional, solo lo ves tú)</label>
                <input type="text" id="note" name="note" maxlength="255" style="margin:0;" placeholder="Ej: estuvo enfermo"></div>
            <button class="btn" type="submit" name="action" value="set" style="margin:0;">Dar prórroga</button>
            <button class="btn btn-secondary" type="submit" name="action" value="clear" style="margin:0;" onclick="return confirm('¿Quitar la prórroga a los estudiantes marcados?')">Quitar prórroga</button>
        </div>
        <p class="text-muted" style="font-size:0.8rem; margin-bottom:0;">
            Hora actual del servidor: <?= e(dt_format($now)) ?>. La prórroga solo vale para esos estudiantes; el resto sigue con la fecha general.
        </p>
    </section>

    <section class="card" style="margin-top:16px;">
        <p style="margin:0 0 6px;"><a href="#" id="toggle-all">Marcar / desmarcar todos</a> ·
           <a href="#" id="toggle-late">Marcar a los que no entregaron</a></p>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead><tr style="text-align:left; border-bottom:2px solid var(--color-border);">
                <th style="padding:6px; width:30px;"></th><th>Estudiante</th><th>Estado</th><th>Fecha límite que tiene</th><th>Prórroga</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $done = $r['status'] === 'completed';
                $effective = $r['ext_due_date'] ?: $assignment['due_date'];
                $late = !$done && $effective < $now && empty($r['returned_at']);
            ?>
                <tr style="border-bottom:1px solid var(--color-border);">
                    <td style="padding:6px;"><input type="checkbox" name="student_ids[]" value="<?= (int) $r['student_id'] ?>" class="st-check" data-pending="<?= $done ? 0 : 1 ?>" style="width:auto;"></td>
                    <td><strong><?= e($r['name']) ?></strong><br><span class="text-muted" style="font-size:0.8rem;"><?= e($r['email']) ?></span></td>
                    <td><?= $done ? '✅ Entregada' : ($late ? '<span style="color:#B91C1C; font-weight:600;">⌛ Vencida</span>' : '⏳ Pendiente') ?></td>
                    <td><?= e(dt_format($effective)) ?></td>
                    <td><?= $r['ext_due_date'] ? '⏰ hasta ' . e(dt_format($r['ext_due_date'])) . ($r['ext_note'] ? '<br><span class="text-muted" style="font-size:0.8rem;">' . e($r['ext_note']) . '</span>' : '') : '<span class="text-muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
</form>
<script>
document.getElementById('toggle-all').addEventListener('click', function (e) {
    e.preventDefault();
    var b = document.querySelectorAll('.st-check'), all = Array.prototype.every.call(b, function (x) { return x.checked; });
    b.forEach(function (x) { x.checked = !all; });
});
document.getElementById('toggle-late').addEventListener('click', function (e) {
    e.preventDefault();
    document.querySelectorAll('.st-check').forEach(function (x) { x.checked = x.dataset.pending === '1'; });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
