<?php
/**
 * Grupo de recuperación (ej. "Recuperación RA 1"):
 *   - Pestaña "Calificar": todas las actividades de recuperación agrupadas (por estudiante o por actividad), con nota y comentario.
 *   - Pestaña "Agregar actividades": por estudiante, las actividades que no realizó o tiene en 0, para agregárselas al grupo.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/space_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/late_helpers.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/recovery_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$groupId = (int) ($_GET['id'] ?? 0);

$group = recovery_group_load($pdo, $groupId, $teacherId);
if (!$group) {
    http_response_code(404);
    exit('Grupo de recuperación no encontrado.');
}
$subjectId = (int) $group['subject_id'];
$self = 'recovery_group.php?id=' . $groupId;

$errors = [];
$notice = null;
$override = [];   // notas escritas que no se pudieron guardar (para no perderlas)

// ---------------------------------------------------------------- acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_group') {
        $name = clean_string($_POST['name'] ?? '');
        $description = clean_string($_POST['description'] ?? '');
        $dueDate = dt_from_input(clean_string($_POST['due_date'] ?? ''), true);
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'El nombre es obligatorio (máximo 120 caracteres).';
        } else {
            try {
                recovery_group_update($pdo, $group, $name, $description, $dueDate);
                audit_log($pdo, $teacherId, 'recovery_group_update', "Grupo de recuperación #{$groupId} actualizado");
                redirect('teacher/' . $self . '&tab=' . urlencode((string) ($_POST['tab'] ?? 'grade')) . '&updated=1');
            } catch (Throwable $e) {
                error_log('recovery_group_update: ' . $e->getMessage());
                $errors[] = 'No se pudieron guardar los cambios.';
            }
        }
    } elseif ($action === 'delete_group') {
        if (($_POST['confirm'] ?? '') !== '1') {
            $errors[] = 'Marca la casilla para confirmar que quieres eliminar el grupo.';
        } elseif (recovery_group_delete($pdo, $group, $teacherId)) {
            audit_log($pdo, $teacherId, 'recovery_group_delete', "Grupo de recuperación '{$group['name']}' eliminado");
            redirect('teacher/recovery.php?subject=' . $subjectId . '&deleted=group');
        } else {
            $errors[] = 'No se pudo eliminar el grupo.';
        }
    } elseif ($action === 'add_items') {
        $picked = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
        [$added, $notes] = recovery_add_items($pdo, $group, $teacherId, $picked);
        if ($added > 0) {
            audit_log($pdo, $teacherId, 'recovery_add_items', "Grupo de recuperación #{$groupId}: {$added} actividad(es) agregada(s) a estudiantes");
            redirect('teacher/' . $self . '&tab=grade&added=' . $added);
        }
        $errors = array_merge($errors, $notes);
        $_GET['tab'] = 'add';
    } elseif ($action === 'remove_item') {
        $parts = explode('-', (string) ($_POST['remove'] ?? ''));
        if (count($parts) === 2 && ($_POST['confirm'] ?? '') === '1') {
            [$ok, $msg] = recovery_remove_item($pdo, $group, $teacherId, (int) $parts[0], (int) $parts[1]);
            if ($ok) {
                audit_log($pdo, $teacherId, 'recovery_remove_item', "Grupo de recuperación #{$groupId}: actividad #{$parts[0]} quitada al estudiante #{$parts[1]}");
                $notice = $msg;
            } else {
                $errors[] = $msg;
            }
        }
    } elseif ($action === 'save_grades') {
        $postedScores = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
        $postedFeedbacks = is_array($_POST['feedbacks'] ?? null) ? $_POST['feedbacks'] : [];

        $current = [];
        foreach (recovery_group_rows($pdo, $groupId) as $r) {
            $current[(int) $r['sub_id']] = $r;
        }
        $update = $pdo->prepare(
            'UPDATE submissions SET score = :score, raw_score = :raw_score, feedback = :feedback, status = :status, reviewed_at = :reviewed_at WHERE id = :id'
        );
        $saved = 0;
        $now = now_datetime();
        $pdo->beginTransaction();
        try {
            foreach ($postedScores as $subId => $rawScore) {
                $subId = (int) $subId;
                if (!isset($current[$subId])) {
                    continue;   // no es de este grupo
                }
                $row = $current[$subId];
                $rawScore = trim((string) $rawScore);
                $feedback = clean_string((string) ($postedFeedbacks[$subId] ?? ''));

                $score = null;
                if ($rawScore !== '') {
                    $norm = str_replace(',', '.', $rawScore);
                    if (!is_numeric($norm) || (float) $norm < 0) {
                        $errors[] = 'La calificación de ' . $row['student_name'] . ' en "' . $row['title'] . '" no es válida ("' . $rawScore . '"). No se guardó.';
                        $override[$subId] = ['score' => $rawScore, 'feedback' => $feedback];
                        continue;
                    }
                    $score = round((float) $norm, 2);
                }

                $penalty = (float) ($row['penalty_pct'] ?? 0);
                $shown = $row['raw_score'] ?? $row['score'];
                $oldScore = $shown !== null ? round((float) $shown, 2) : null;
                if ($score === $oldScore && $feedback === (string) ($row['feedback'] ?? '')) {
                    continue;   // sin cambios: no se marca como ajustada por el profesor
                }
                $newStatus = (($score !== null && $score > 0) || $row['status'] === 'completed') ? 'completed' : $row['status'];
                $update->execute([
                    'score' => $score !== null ? late_final_score($score, $penalty) : null,
                    'raw_score' => ($score !== null && $penalty > 0) ? $score : null,
                    'feedback' => $feedback, 'status' => $newStatus, 'reviewed_at' => $now, 'id' => $subId,
                ]);
                $saved++;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('recovery save_grades: ' . $ex->getMessage());
            $errors[] = 'No se pudieron guardar las calificaciones. Intenta de nuevo.';
            $saved = 0;
        }
        if ($saved > 0) {
            audit_log($pdo, $teacherId, 'recovery_grade', "Grupo de recuperación #{$groupId}: {$saved} calificación(es) guardada(s)");
            if (empty($errors)) {
                redirect('teacher/' . $self . '&tab=grade&view=' . urlencode((string) ($_POST['view'] ?? 'student')) . '&saved=' . $saved);
            }
            $notice = $saved === 1 ? 'Se guardó 1 calificación.' : "Se guardaron {$saved} calificaciones.";
        } elseif (empty($errors)) {
            $notice = 'No había cambios para guardar.';
        }
    }
}

// ---------------------------------------------------------------- datos
$rows = recovery_group_rows($pdo, $groupId);
foreach ($rows as &$r) {
    $r['state'] = recovery_row_state($r);
}
unset($r);

$stat = ['students' => [], 'items' => [], 'needs' => 0, 'pending' => 0, 'graded' => 0];
foreach ($rows as $r) {
    $stat['students'][(int) $r['student_id']] = true;
    $stat['items'][(int) $r['assignment_id']] = true;
    if ($r['state'] === 'sent') {
        $stat['needs']++;
    } elseif (in_array($r['state'], ['pending', 'returned'], true)) {
        $stat['pending']++;
    } else {
        $stat['graded']++;
    }
}

$tab = ($_GET['tab'] ?? '') === 'add' || ($_GET['tab'] ?? '') === 'grade' ? $_GET['tab'] : (empty($rows) ? 'add' : 'grade');
$view = ($_GET['view'] ?? 'student') === 'activity' ? 'activity' : 'student';
$candidates = $tab === 'add' ? recovery_candidates($pdo, $subjectId, $teacherId, $groupId) : [];

$STATE = [
    'graded'   => ['✅ Calificada', 'chip-green'],
    'sent'     => ['📨 Entregada · por calificar', 'chip-red'],
    'manual'   => ['⚠️ Nota sin entrega', 'chip-amber'],
    'returned' => ['↩️ Devuelta', 'chip-amber'],
    'pending'  => ['⏳ Sin entregar', 'chip-gray'],
];

$pageTitle = $group['name'] . ' · ' . $group['subject_name'];
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">

<nav class="crumbs" aria-label="Ruta">
    <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
    <a href="course.php?id=<?= (int) $group['course_id'] ?>"><?= e($group['course_name']) ?></a><span class="sep">›</span>
    <a href="subject.php?id=<?= $subjectId ?>"><?= e($group['subject_name']) ?></a><span class="sep">›</span>
    <a href="recovery.php?subject=<?= $subjectId ?>">Recuperaciones</a><span class="sep">›</span>
    <strong><?= e($group['name']) ?></strong>
</nav>

<div class="space-head">
    <div>
        <h1>🔁 <?= e($group['name']) ?></h1>
        <p class="space-sub"><?= e($group['course_name']) ?> — <?= e($group['subject_name']) ?>
            <?php if (!empty($group['due_date'])): ?> · Límite: <?= e(dt_format($group['due_date'])) ?><?php endif; ?></p>
        <?php if (!empty($group['description'])): ?><p class="text-muted" style="margin:4px 0 0; max-width:760px;"><?= e($group['description']) ?></p><?php endif; ?>
    </div>
</div>

<?php if (!empty($_GET['created'])): ?><div class="alert alert-success">Grupo creado. Agrega ahora las actividades que cada estudiante debe recuperar.</div><?php endif; ?>
<?php if (!empty($_GET['updated'])): ?><div class="alert alert-success">Cambios guardados.</div><?php endif; ?>
<?php if (!empty($_GET['added'])): ?><div class="alert alert-success">Se agregaron <?= (int) $_GET['added'] ?> actividad(es) a estudiantes. Ya les aparecen agrupadas en su panel.</div><?php endif; ?>
<?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Se guardó <?= (int) $_GET['saved'] ?> calificación<?= (int) $_GET['saved'] === 1 ? '' : 'es' ?>.</div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="stat-row">
    <div class="stat-pill"><div class="n"><?= count($stat['students']) ?></div><div class="l">Estudiantes</div></div>
    <div class="stat-pill"><div class="n"><?= count($stat['items']) ?></div><div class="l">Actividades distintas</div></div>
    <div class="stat-pill red"><div class="n"><?= (int) $stat['needs'] ?></div><div class="l">Por calificar</div></div>
    <div class="stat-pill amber"><div class="n"><?= (int) $stat['pending'] ?></div><div class="l">Sin entregar</div></div>
    <div class="stat-pill green"><div class="n"><?= (int) $stat['graded'] ?></div><div class="l">Calificadas</div></div>
</div>

<div class="space-tabs" role="tablist">
    <a class="space-tab <?= $tab === 'grade' ? 'active' : '' ?>" href="<?= e($self) ?>&tab=grade&view=<?= e($view) ?>">📝 Calificar <span class="count <?= $stat['needs'] > 0 ? 'alert' : '' ?>"><?= (int) $stat['needs'] ?></span></a>
    <a class="space-tab <?= $tab === 'add' ? 'active' : '' ?>" href="<?= e($self) ?>&tab=add">➕ Agregar actividades a estudiantes</a>
</div>

<?php if ($tab === 'add'): ?>
    <!-- =================== AGREGAR ACTIVIDADES =================== -->
    <p class="text-muted" style="margin:10px 0;">
        Aquí aparecen los estudiantes con actividades <strong>sin entregar</strong> o con <strong>nota 0</strong> en esta asignatura.
        Marca lo que quieres que recuperen; se les crea una copia de cada actividad dentro de este grupo.
    </p>

    <?php if (empty($candidates)): ?>
        <div class="empty-box">🎉 Ningún estudiante tiene actividades sin entregar o en cero en esta asignatura.</div>
    <?php else:
        // Actividades distintas, para marcarlas de una vez en todos los estudiantes
        $acts = [];
        foreach ($candidates as $c) {
            foreach ($c['items'] as $it) {
                if ($it['already']) { continue; }
                $acts[$it['assignment_id']] = ['title' => $it['title'], 'icon' => $it['icon'], 'n' => ($acts[$it['assignment_id']]['n'] ?? 0) + 1];
            }
        }
    ?>
        <form method="post" id="add-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add_items">

            <div class="card" style="margin-bottom:12px; padding:12px 14px;">
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <input type="search" id="stu-filter" placeholder="🔎 Buscar estudiante..." style="max-width:260px; margin:0;">
                    <button type="button" class="btn btn-secondary" id="sel-all" style="margin:0;">Marcar todo lo pendiente</button>
                    <button type="button" class="btn btn-secondary" id="sel-none" style="margin:0;">Desmarcar todo</button>
                </div>
                <?php if (count($acts) > 1): ?>
                    <p class="text-muted" style="font-size:0.82rem; margin:10px 0 6px;">O marca una actividad para todos los estudiantes que no la hicieron:</p>
                    <div class="chips">
                        <?php foreach ($acts as $aid => $a): ?>
                            <button type="button" class="chip chip-blue act-toggle" data-act="<?= (int) $aid ?>" style="cursor:pointer;">
                                <?= e($a['icon']) ?> <?= e(truncate_text($a['title'], 40)) ?> (<?= (int) $a['n'] ?>)
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php foreach ($candidates as $c):
                $nPending = $nZero = $nAlready = 0;
                foreach ($c['items'] as $it) {
                    if ($it['already']) { $nAlready++; } elseif ($it['state'] === 'zero') { $nZero++; } else { $nPending++; }
                }
            ?>
                <details class="card stu-card" style="margin-bottom:8px; padding:10px 14px;" data-name="<?= e(mb_strtolower($c['name'])) ?>" open>
                    <summary style="cursor:pointer; font-weight:700; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                        <span><?= e($c['name']) ?></span>
                        <?php if ($nPending > 0): ?><span class="chip chip-amber">⏳ <?= $nPending ?> sin entregar</span><?php endif; ?>
                        <?php if ($nZero > 0): ?><span class="chip chip-red">0️⃣ <?= $nZero ?> en cero</span><?php endif; ?>
                        <?php if ($nAlready > 0): ?><span class="chip chip-green">✔ <?= $nAlready ?> ya en el grupo</span><?php endif; ?>
                    </summary>
                    <div style="padding:8px 0 2px;">
                        <label style="display:flex; gap:8px; align-items:center; font-size:0.85rem; color:var(--color-text-muted);">
                            <input type="checkbox" class="stu-all" style="width:auto;"> Marcar todas las de este estudiante
                        </label>
                        <?php foreach ($c['items'] as $it): ?>
                            <label style="display:flex; gap:10px; align-items:flex-start; padding:6px 0; border-top:1px solid var(--color-border); <?= $it['already'] ? 'opacity:0.65;' : '' ?>">
                                <?php if ($it['already']): ?>
                                    <input type="checkbox" checked disabled style="width:auto; margin-top:4px;">
                                <?php else: ?>
                                    <input type="checkbox" class="item-cb" name="items[<?= (int) $c['id'] ?>][]" value="<?= (int) $it['assignment_id'] ?>" data-act="<?= (int) $it['assignment_id'] ?>" style="width:auto; margin-top:4px;">
                                <?php endif; ?>
                                <span style="flex:1;">
                                    <strong><?= e($it['icon']) ?> <?= e($it['title']) ?></strong>
                                    <span class="text-muted" style="font-size:0.8rem;"> · <?= e($it['kind']) ?> · <?= (int) $it['points'] ?> pts</span><br>
                                    <?php if ($it['already']): ?>
                                        <span class="chip chip-green">✔ Ya está en este grupo</span>
                                    <?php elseif ($it['state'] === 'zero'): ?>
                                        <span class="chip chip-red">0️⃣ Tiene nota 0</span>
                                    <?php else: ?>
                                        <span class="chip chip-amber">⏳ No la realizó</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>

            <div style="position:sticky; bottom:0; background:var(--color-surface); border-top:1px solid var(--color-border); padding:10px 0; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <button type="submit" class="btn" id="add-btn" disabled>➕ Agregar al grupo</button>
                <span class="text-muted" id="add-count">Nada seleccionado.</span>
            </div>
        </form>

        <script>
        (function () {
            const form = document.getElementById('add-form');
            const cbs = () => Array.from(form.querySelectorAll('.item-cb'));
            const btn = document.getElementById('add-btn');
            const count = document.getElementById('add-count');
            function refresh() {
                const n = cbs().filter(c => c.checked).length;
                btn.disabled = n === 0;
                count.textContent = n === 0 ? 'Nada seleccionado.' : n + ' actividad' + (n === 1 ? '' : 'es') + ' seleccionada' + (n === 1 ? '' : 's') + '.';
            }
            form.addEventListener('change', refresh);
            document.getElementById('sel-all').addEventListener('click', () => { cbs().forEach(c => c.checked = true); refresh(); });
            document.getElementById('sel-none').addEventListener('click', () => { cbs().forEach(c => c.checked = false); form.querySelectorAll('.stu-all').forEach(c => c.checked = false); refresh(); });
            form.querySelectorAll('.stu-all').forEach(all => all.addEventListener('change', () => {
                all.closest('details').querySelectorAll('.item-cb').forEach(c => c.checked = all.checked);
                refresh();
            }));
            form.querySelectorAll('.act-toggle').forEach(b => b.addEventListener('click', () => {
                const list = cbs().filter(c => c.dataset.act === b.dataset.act);
                const allOn = list.every(c => c.checked);
                list.forEach(c => c.checked = !allOn);
                refresh();
            }));
            document.getElementById('stu-filter').addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                form.querySelectorAll('.stu-card').forEach(d => { d.style.display = d.dataset.name.indexOf(q) === -1 ? 'none' : ''; });
            });
            refresh();
        })();
        </script>
    <?php endif; ?>

<?php else: ?>
    <!-- =================== CALIFICAR =================== -->
    <?php if (empty($rows)): ?>
        <div class="empty-box">
            <p style="font-size:1.05rem; margin:0 0 8px;">Este grupo todavía no tiene actividades.</p>
            <a class="btn" href="<?= e($self) ?>&tab=add">➕ Agregar actividades a estudiantes</a>
        </div>
    <?php else:
        // Agrupación elegida
        $groups = [];
        foreach ($rows as $r) {
            $key = $view === 'student' ? (int) $r['student_id'] : (int) $r['assignment_id'];
            if (!isset($groups[$key])) {
                [$icon, $kind] = space_assignment_kind($r);
                $groups[$key] = ['title' => $view === 'student' ? $r['student_name'] : $icon . ' ' . $r['title'],
                                 'sub' => $view === 'student' ? $r['student_email'] : $kind . ' · ' . (int) $r['points'] . ' pts',
                                 'aid' => (int) $r['assignment_id'], 'rows' => []];
            }
            $groups[$key]['rows'][] = $r;
        }
    ?>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:10px 0;">
            <span class="text-muted">Ver agrupado por:</span>
            <a class="btn <?= $view === 'student' ? '' : 'btn-secondary' ?>" style="margin:0; padding:6px 14px;" href="<?= e($self) ?>&tab=grade&view=student">👤 Estudiante</a>
            <a class="btn <?= $view === 'activity' ? '' : 'btn-secondary' ?>" style="margin:0; padding:6px 14px;" href="<?= e($self) ?>&tab=grade&view=activity">📚 Actividad</a>
        </div>

        <!-- formulario aparte para "quitar" (los botones lo referencian con el atributo form) -->
        <form method="post" id="rm-form" onsubmit="return confirm('¿Quitar esta actividad al estudiante? Si ya había entregado algo, se borra.');">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="remove_item">
            <input type="hidden" name="confirm" value="1">
        </form>

        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_grades">
            <input type="hidden" name="view" value="<?= e($view) ?>">

            <?php foreach ($groups as $g): ?>
                <section class="card" style="margin-bottom:12px; padding:12px 14px;">
                    <div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; align-items:baseline;">
                        <h3 style="margin:0; font-size:1.02rem;"><?= e($g['title']) ?></h3>
                        <span class="text-muted" style="font-size:0.82rem;"><?= e($g['sub']) ?></span>
                    </div>
                    <div style="overflow-x:auto;">
                    <table style="width:100%; margin-top:8px; border-collapse:collapse;">
                        <thead>
                            <tr style="text-align:left; font-size:0.78rem; color:var(--color-text-muted);">
                                <th style="padding:4px 6px;"><?= $view === 'student' ? 'Actividad' : 'Estudiante' ?></th>
                                <th style="padding:4px 6px;">Estado</th>
                                <th style="padding:4px 6px;">Nota original</th>
                                <th style="padding:4px 6px;">Nota de recuperación</th>
                                <th style="padding:4px 6px;">Comentario</th>
                                <th style="padding:4px 6px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($g['rows'] as $r):
                            $sid = (int) $r['sub_id'];
                            [$icon, $kind] = space_assignment_kind($r);
                            [$stLabel, $stClass] = $STATE[$r['state']] ?? $STATE['pending'];
                            $shown = $r['raw_score'] ?? $r['score'];
                            $scoreVal = isset($override[$sid]) ? $override[$sid]['score'] : report_fmt_score($shown !== null ? (float) $shown : null);
                            $fbVal = isset($override[$sid]) ? $override[$sid]['feedback'] : (string) ($r['feedback'] ?? '');
                            $orig = $r['orig_score'] !== null ? report_fmt_score((float) $r['orig_score']) : ($r['orig_status'] === 'pending' ? 'Sin entrega' : '—');
                        ?>
                            <tr style="border-top:1px solid var(--color-border); vertical-align:top;">
                                <td style="padding:6px;">
                                    <?php if ($view === 'student'): ?>
                                        <strong><?= e($icon) ?> <?= e($r['title']) ?></strong><br><span class="text-muted" style="font-size:0.78rem;"><?= e($kind) ?></span>
                                    <?php else: ?>
                                        <strong><?= e($r['student_name']) ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:6px;"><span class="chip <?= e($stClass) ?>"><?= e($stLabel) ?></span>
                                    <?php if (!empty($r['completed_at'])): ?><br><span class="text-muted" style="font-size:0.75rem;"><?= e(dt_format($r['completed_at'])) ?></span><?php endif; ?></td>
                                <td style="padding:6px; white-space:nowrap;"><?= e($orig) ?></td>
                                <td style="padding:6px; white-space:nowrap;">
                                    <input type="text" inputmode="decimal" name="scores[<?= $sid ?>]" value="<?= e((string) $scoreVal) ?>" placeholder="—" style="width:70px; margin:0; display:inline-block;"> / <?= (int) $r['points'] ?>
                                </td>
                                <td style="padding:6px; min-width:180px;"><input type="text" name="feedbacks[<?= $sid ?>]" value="<?= e((string) $fbVal) ?>" maxlength="500" placeholder="Comentario (opcional)" style="margin:0;"></td>
                                <td style="padding:6px; white-space:nowrap;">
                                    <a href="assignment_detail.php?id=<?= (int) $r['assignment_id'] ?>" title="Abrir la actividad para revisar el trabajo">🔍 Abrir</a>
                                    <button type="submit" form="rm-form" name="remove" value="<?= (int) $r['assignment_id'] ?>-<?= (int) $r['student_id'] ?>"
                                            style="background:none; border:none; color:#B91C1C; cursor:pointer; padding:0 0 0 8px;" title="Quitar esta actividad al estudiante">✖</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </section>
            <?php endforeach; ?>

            <div style="position:sticky; bottom:0; background:var(--color-surface); border-top:1px solid var(--color-border); padding:10px 0;">
                <button type="submit" class="btn">💾 Guardar calificaciones</button>
                <span class="text-muted" style="font-size:0.85rem;"> Las notas de los cuestionarios y juegos se ponen solas; aquí puedes ajustarlas. Para ver un trabajo de creación, usa “🔍 Abrir”.</span>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<details class="card" style="margin-top:20px; padding:10px 14px;">
    <summary style="cursor:pointer; font-weight:700;">⚙️ Ajustes del grupo</summary>
    <form method="post" style="margin-top:10px;">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="update_group">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <label for="g-name">Nombre</label>
        <input type="text" id="g-name" name="name" maxlength="120" required value="<?= e($group['name']) ?>">
        <label for="g-desc">Mensaje para los estudiantes</label>
        <textarea id="g-desc" name="description" rows="2"><?= e((string) $group['description']) ?></textarea>
        <label for="g-due">Fecha y hora límite del grupo</label>
        <input type="datetime-local" id="g-due" name="due_date" value="<?= e(dt_to_input($group['due_date'])) ?>">
        <p class="text-muted" style="font-size:0.8rem; margin:2px 0 10px;">Al guardar, la fecha se aplica a todas las actividades del grupo (también a las ya agregadas).</p>
        <button type="submit" class="btn">Guardar ajustes</button>
    </form>
    <hr style="margin:16px 0; border:none; border-top:1px solid var(--color-border);">
    <form method="post" onsubmit="return confirm('¿Eliminar el grupo y todas sus actividades? Se borran también las entregas y notas de recuperación.');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="delete_group">
        <label style="display:flex; gap:8px; align-items:center; color:#B91C1C; font-weight:600;">
            <input type="checkbox" name="confirm" value="1" style="width:auto;"> Quiero eliminar este grupo con todas sus actividades de recuperación
        </label>
        <button type="submit" class="btn" style="margin-top:8px; background:#DC2626;">🗑 Eliminar grupo</button>
    </form>
</details>
<?php require __DIR__ . '/../includes/footer.php'; ?>
