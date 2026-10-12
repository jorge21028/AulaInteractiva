<?php
/**
 * Reporte de una asignatura: progreso de cada estudiante (enviadas / no enviadas, calificadas / sin calificar),
 * y qué asignaciones envió o no cada uno, aunque ya tenga una nota.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/space_helpers.php';
require_once __DIR__ . '/../includes/report_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$subjectId = (int) ($_GET['id'] ?? 0);

$subject = space_load_subject($pdo, $subjectId, $teacherId);
if (!$subject) {
    http_response_code(404);
    exit('Asignatura no encontrada.');
}

$report = report_load($pdo, $subjectId, $teacherId, (int) $subject['course_id']);
$assignments = $report['assignments'];
$students = $report['students'];
$totals = $report['totals'];

// ---------- Exportar a Excel (CSV) ----------
if (($_GET['format'] ?? '') === 'csv') {
    $fileName = 'reporte_' . preg_replace('/[^A-Za-z0-9]+/', '_', $subject['course_name'] . '_' . $subject['name']) . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "\xEF\xBB\xBF"; // BOM: Excel reconoce las tildes
    $out = fopen('php://output', 'w');
    $head = ['Estudiante', 'Correo', 'Asignadas', 'Enviadas', 'Sin enviar', 'Calificadas', 'Sin calificar', 'Calificadas sin envío del estudiante', 'Promedio %'];
    foreach ($assignments as $a) {
        $head[] = report_csv_safe($a['title']) . ' (' . $a['points'] . ' pts)';
    }
    fputcsv($out, $head, ';');
    foreach ($students as $st) {
        $s = $st['stats'];
        $line = [
            report_csv_safe($st['name'] . ($st['enrolled'] ? '' : ' (ya no inscrito)')), report_csv_safe($st['email']),
            $s['assigned'], $s['delivered'], $s['not_delivered'], $s['graded'], $s['ungraded'], $s['manual'],
            $s['avg'] !== null ? report_fmt_score(round($s['avg'], 1)) : '',
        ];
        foreach ($assignments as $aid => $a) {
            $line[] = report_csv_cell($st['cells'][$aid] ?? null);
        }
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

$deliveryRate = $totals['assigned'] > 0 ? round($totals['delivered'] / $totals['assigned'] * 100) : 0;

function fmt_date(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '';
}

$pageTitle = 'Reporte · ' . $subject['name'];
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">
<style>
  .rp-legend { display:flex; flex-wrap:wrap; gap:8px 16px; font-size:0.85rem; color:var(--color-text-muted); margin:6px 0 12px; }
  .rp-bar { height:8px; background:#E2E8F0; border-radius:999px; overflow:hidden; min-width:90px; }
  .rp-bar > span { display:block; height:100%; background:#16A34A; }
  .rp-table { width:100%; border-collapse:collapse; font-size:0.9rem; }
  .rp-table th, .rp-table td { padding:8px 10px; border-bottom:1px solid var(--color-border); text-align:center; white-space:nowrap; }
  .rp-table th { background:#F8FAFC; position:sticky; top:0; z-index:2; font-size:0.8rem; color:var(--color-text-muted); }
  .rp-table td.name, .rp-table th.name { text-align:left; position:sticky; left:0; background:#fff; z-index:1; min-width:170px; max-width:220px; overflow:hidden; text-overflow:ellipsis; }
  .rp-table th.name { background:#F8FAFC; z-index:3; }
  .rp-wrap { overflow:auto; max-height:70vh; border:1px solid var(--color-border); border-radius:12px; background:#fff; }
  .rp-num-warn { color:#B45309; font-weight:700; }
  .rp-num-red { color:#B91C1C; font-weight:700; }
  .cell { display:inline-block; min-width:44px; padding:3px 8px; border-radius:8px; font-weight:600; font-size:0.82rem; text-decoration:none; }
  .cell:hover { text-decoration:none; filter:brightness(0.96); }
  .cell.graded   { background:#E7F8EE; color:#166534; }
  .cell.sent     { background:#FDECEC; color:#B91C1C; }
  .cell.manual   { background:#F3E8FF; color:#6B21A8; border:1px dashed #A855F7; }
  .cell.pending  { background:#FFF4E5; color:#B45309; }
  .cell.returned { background:#FFE4E6; color:#9F1239; }
  .cell.none     { color:#CBD5E1; }
  .detail-row td { text-align:left; white-space:normal; background:#F8FAFC; }
  .detail-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; }
  .detail-grid h4 { margin:0 0 6px; font-size:0.9rem; }
  .detail-grid ul { margin:0; padding-left:18px; font-size:0.85rem; }
  .toggle { cursor:pointer; color:var(--color-primary); font-weight:600; background:none; border:none; font:inherit; padding:0; }
  tr.hidden-row { display:none; }
</style>

<nav class="crumbs" aria-label="Ruta">
    <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
    <a href="course.php?id=<?= (int) $subject['course_id'] ?>"><?= e($subject['course_name']) ?></a><span class="sep">›</span>
    <a href="subject.php?id=<?= (int) $subjectId ?>"><?= e($subject['name']) ?></a><span class="sep">›</span>
    <strong>Reporte</strong>
</nav>

<div class="space-head">
    <div>
        <h1>📊 Reporte de <?= e($subject['name']) ?></h1>
        <p class="space-sub"><?= e($subject['course_name']) ?> · <?= count($students) ?> estudiante<?= count($students) === 1 ? '' : 's' ?> · <?= count($assignments) ?> asignación<?= count($assignments) === 1 ? '' : 'es' ?></p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn btn-secondary" href="subject.php?id=<?= (int) $subjectId ?>">&larr; Volver a la asignatura</a>
        <?php if (!empty($assignments)): ?>
            <a class="btn" href="subject_report.php?id=<?= (int) $subjectId ?>&format=csv">⬇️ Descargar para Excel</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($assignments)): ?>
    <div class="empty-box">Esta asignatura todavía no tiene asignaciones, así que no hay nada que reportar.</div>
<?php elseif (empty($students)): ?>
    <div class="empty-box">Este curso todavía no tiene estudiantes inscritos.</div>
<?php else: ?>

<div class="stat-row">
    <div class="stat-pill green"><div class="n"><?= $deliveryRate ?>%</div><div class="l">Entregas realizadas (<?= (int) $totals['delivered'] ?> de <?= (int) $totals['assigned'] ?>)</div></div>
    <div class="stat-pill amber"><div class="n"><?= (int) $totals['pending'] ?></div><div class="l">Entregas pendientes (no enviadas)</div></div>
    <div class="stat-pill red"><div class="n"><?= (int) $totals['sent'] ?></div><div class="l">Enviadas sin calificar</div></div>
    <div class="stat-pill"><div class="n" style="color:#6B21A8;"><?= (int) $totals['manual'] ?></div><div class="l">Notas sin envío del estudiante</div></div>
</div>

<div class="rp-legend">
    <span><span class="cell graded">✅ 8,5</span> Enviada y calificada</span>
    <span><span class="cell sent">📨</span> Enviada, sin calificar</span>
    <span><span class="cell manual">⚠️ 7</span> Tiene nota pero <strong>no la envió</strong></span>
    <span><span class="cell returned">↩️</span> Devuelta, sin reenviar</span>
    <span><span class="cell pending">⏳</span> No enviada</span>
    <span><span class="cell none">–</span> No es para este estudiante</span>
</div>

<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:8px 0 14px;">
    <input type="text" id="rp-search" placeholder="Buscar estudiante..." style="flex:1 1 200px; margin:0;">
    <select id="rp-filter" style="flex:0 1 280px; margin:0;">
        <option value="all">Mostrar todos los estudiantes</option>
        <option value="unsent">Con asignaciones sin enviar</option>
        <option value="ungraded">Con trabajos enviados sin calificar</option>
        <option value="manual">Con notas sin envío del estudiante</option>
        <option value="complete">Al día (todo enviado y calificado)</option>
    </select>
</div>

<h2 style="margin-bottom:6px;">Progreso por estudiante</h2>
<div class="rp-wrap">
<table class="rp-table" id="rp-summary">
    <thead>
        <tr>
            <th class="name">Estudiante</th>
            <th style="min-width:150px;">Progreso de entrega</th>
            <th title="Asignaciones que tiene">Asignadas</th>
            <th title="Realmente enviadas por el estudiante">Enviadas</th>
            <th>Sin enviar</th>
            <th>Calificadas</th>
            <th>Sin calificar</th>
            <th title="Tiene nota, pero el estudiante no la envió">⚠️ Nota sin envío</th>
            <th>Promedio</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($students as $st):
        $s = $st['stats'];
        $complete = $s['assigned'] > 0 && $s['not_delivered'] === 0 && $s['sent'] === 0 && $s['manual'] === 0;
    ?>
        <tr class="rp-row" data-name="<?= e(mb_strtolower($st['name'] . ' ' . $st['email'])) ?>"
            data-unsent="<?= $s['not_delivered'] > 0 ? 1 : 0 ?>" data-ungraded="<?= $s['sent'] > 0 ? 1 : 0 ?>"
            data-manual="<?= $s['manual'] > 0 ? 1 : 0 ?>" data-complete="<?= $complete ? 1 : 0 ?>" data-sid="<?= (int) $st['id'] ?>">
            <td class="name" title="<?= e($st['email']) ?>">
                <strong><?= e($st['name']) ?></strong><?= $st['enrolled'] ? '' : ' <span class="chip chip-gray">ya no inscrito</span>' ?>
            </td>
            <td>
                <?php if ($s['assigned'] === 0): ?>
                    <span class="text-muted">Sin asignaciones</span>
                <?php else: ?>
                    <div class="rp-bar" title="<?= round($s['progress']) ?>%"><span style="width:<?= round($s['progress']) ?>%"></span></div>
                    <small class="text-muted"><?= (int) $s['delivered'] ?> de <?= (int) $s['assigned'] ?> (<?= round($s['progress']) ?>%)</small>
                <?php endif; ?>
            </td>
            <td><?= (int) $s['assigned'] ?></td>
            <td><strong><?= (int) $s['delivered'] ?></strong></td>
            <td class="<?= $s['not_delivered'] > 0 ? 'rp-num-warn' : '' ?>"><?= (int) $s['not_delivered'] ?></td>
            <td><?= (int) $s['graded'] ?></td>
            <td class="<?= $s['sent'] > 0 ? 'rp-num-red' : '' ?>"><?= (int) $s['ungraded'] ?><?= $s['sent'] > 0 ? ' <small>(' . (int) $s['sent'] . ' enviada' . ($s['sent'] === 1 ? '' : 's') . ')</small>' : '' ?></td>
            <td class="<?= $s['manual'] > 0 ? 'rp-num-warn' : '' ?>" style="<?= $s['manual'] > 0 ? 'color:#6B21A8;' : '' ?>"><?= (int) $s['manual'] ?></td>
            <td><?= $s['avg'] !== null ? '<strong>' . e(report_fmt_score(round($s['avg'], 1))) . '%</strong>' : '<span class="text-muted">—</span>' ?></td>
            <td><button type="button" class="toggle" data-target="d-<?= (int) $st['id'] ?>">Ver detalle ▾</button></td>
        </tr>
        <tr class="detail-row hidden-row" id="d-<?= (int) $st['id'] ?>" data-for="<?= (int) $st['id'] ?>">
            <td colspan="10">
                <?php
                $groups = ['pending' => [], 'sent' => [], 'graded' => [], 'manual' => []];
                foreach ($assignments as $aid => $a) {
                    $cell = $st['cells'][$aid] ?? null;
                    if (!$cell) { continue; }
                    $key = in_array($cell['state'], ['pending', 'returned'], true) ? 'pending' : $cell['state'];
                    $groups[$key][] = [$a, $cell];
                }
                $titles = [
                    'pending' => '⏳ No enviadas', 'sent' => '📨 Enviadas, sin calificar',
                    'graded' => '✅ Enviadas y calificadas', 'manual' => '⚠️ Con nota, pero NO las envió',
                ];
                ?>
                <div class="detail-grid">
                    <?php foreach ($groups as $key => $list): ?>
                        <div>
                            <h4><?= e($titles[$key]) ?> (<?= count($list) ?>)</h4>
                            <?php if (empty($list)): ?>
                                <span class="text-muted" style="font-size:0.85rem;">Ninguna</span>
                            <?php else: ?>
                                <ul>
                                    <?php foreach ($list as [$a, $cell]): ?>
                                        <li>
                                            <a href="assignment_detail.php?id=<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a>
                                            <?php if ($cell['score'] !== null): ?> — <strong><?= e(report_fmt_score($cell['score'])) ?>/<?= (int) $cell['points'] ?></strong><?php endif; ?>
                                            <?php if ($cell['state'] === 'returned'): ?> <span class="chip chip-red">devuelta</span><?php endif; ?>
                                            <?php if ($key === 'pending' && $a['due_date'] && strtotime($a['due_date']) < time()): ?> <span class="chip chip-red">vencida</span><?php endif; ?>
                                            <?php if ($cell['sent_at'] && $key !== 'pending'): ?> <small class="text-muted">(<?= e(fmt_date($cell['sent_at'])) ?>)</small><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<h2 style="margin:24px 0 6px;">Detalle por asignación</h2>
<p class="text-muted" style="margin-top:0; font-size:0.9rem;">Cada casilla muestra si el estudiante <strong>envió</strong> la asignación y si tiene <strong>nota</strong>. Toca una casilla para abrir la asignación.</p>
<div class="rp-wrap">
<table class="rp-table" id="rp-matrix">
    <thead>
        <tr>
            <th class="name">Estudiante</th>
            <?php foreach ($assignments as $a): ?>
                <th style="min-width:96px; white-space:normal; max-width:140px;" title="<?= e($a['title']) ?> · <?= (int) $a['points'] ?> pts<?= $a['due_date'] ? ' · entrega ' . e(fmt_date($a['due_date'])) : '' ?>">
                    <a href="assignment_detail.php?id=<?= (int) $a['id'] ?>"><?= e(mb_strimwidth($a['title'], 0, 28, '…')) ?></a>
                    <br><small><?= (int) $a['points'] ?> pts<?= $a['audience'] === 'selected' ? ' 🎯' : '' ?></small>
                </th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($students as $st): ?>
        <tr class="rp-mrow" data-sid="<?= (int) $st['id'] ?>">
            <td class="name"><strong><?= e($st['name']) ?></strong></td>
            <?php foreach ($assignments as $aid => $a):
                $cell = $st['cells'][$aid] ?? null;
                if (!$cell): ?>
                    <td><span class="cell none" title="No asignada a este estudiante">–</span></td>
                <?php else:
                    $tip = REPORT_STATE_LABELS[$cell['state']];
                    if ($cell['sent_at'] && $cell['delivered']) { $tip .= ' · enviada ' . fmt_date($cell['sent_at']); }
                    if ($cell['score'] !== null) { $tip .= ' · nota ' . report_fmt_score($cell['score']) . '/' . $cell['points']; }
                    if ($cell['feedback'] !== '') { $tip .= ' · ' . mb_strimwidth($cell['feedback'], 0, 80, '…'); }
                ?>
                    <td>
                        <a class="cell <?= e($cell['state']) ?>" href="assignment_detail.php?id=<?= (int) $a['id'] ?>" title="<?= e($tip) ?>">
                            <?= e(REPORT_STATE_ICONS[$cell['state']]) ?><?= $cell['score'] !== null ? ' ' . e(report_fmt_score($cell['score'])) : '' ?>
                        </a>
                    </td>
                <?php endif; ?>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<script>
(function () {
    const search = document.getElementById('rp-search');
    const filter = document.getElementById('rp-filter');

    function apply() {
        const q = search.value.trim().toLowerCase();
        const f = filter.value;
        document.querySelectorAll('#rp-summary tr.rp-row').forEach(row => {
            const okName = !q || row.dataset.name.includes(q);
            const okFilter = f === 'all' || (f === 'unsent' && row.dataset.unsent === '1') || (f === 'ungraded' && row.dataset.ungraded === '1')
                || (f === 'manual' && row.dataset.manual === '1') || (f === 'complete' && row.dataset.complete === '1');
            const show = okName && okFilter;
            row.style.display = show ? '' : 'none';
            const det = document.getElementById('d-' + row.dataset.sid);
            if (det && !show) det.classList.add('hidden-row');
            document.querySelectorAll('#rp-matrix tr.rp-mrow[data-sid="' + row.dataset.sid + '"]').forEach(m => { m.style.display = show ? '' : 'none'; });
        });
    }
    search.addEventListener('input', apply);
    filter.addEventListener('change', apply);

    document.querySelectorAll('.toggle').forEach(b => b.addEventListener('click', () => {
        const row = document.getElementById(b.dataset.target);
        const hidden = row.classList.toggle('hidden-row');
        b.textContent = hidden ? 'Ver detalle ▾' : 'Ocultar ▴';
    }));
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
