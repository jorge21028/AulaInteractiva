<?php
/** "Mi progreso": promedio, entregas a tiempo, pendientes/vencidas, evolución y notas recientes. */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/progress_helpers.php';

require_role('student');
$pdo = Database::getConnection();
$studentId = (int) current_user_id();

$p = progress_for_student($pdo, $studentId);
$o = $p['overall'];
$avg = progress_percent($o);
$onTimePct = $o['completed'] > 0 ? round($o['on_time'] / $o['completed'] * 100) : null;
$donePct = $o['total'] > 0 ? round($o['completed'] / $o['total'] * 100) : 0;

$pageTitle = 'Mi progreso';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="dashboard.php">&larr; Volver a mis cursos</a></p>
<h1>📈 Mi progreso</h1>

<?php if ($o['total'] === 0): ?>
    <div class="card"><p class="empty-state">Todavía no tienes asignaciones. Cuando tus profesores publiquen actividades, aquí verás tu avance.</p></div>
<?php else: ?>
<section class="grid grid-3" style="margin-bottom:16px;">
    <div class="card">
        <div class="stat-number" style="color:<?= e(progress_color($avg)) ?>;"><?= $avg !== null ? e(number_format($avg, 1, ',', '.')) . '%' : '—' ?></div>
        <div class="stat-label">Promedio general<?= $avg === null ? ' (aún sin notas)' : '' ?></div>
    </div>
    <div class="card"><div class="stat-number"><?= (int) $o['completed'] ?>/<?= (int) $o['total'] ?></div><div class="stat-label">Entregadas (<?= (int) $donePct ?>%)</div></div>
    <div class="card"><div class="stat-number"><?= $onTimePct !== null ? (int) $onTimePct . '%' : '—' ?></div><div class="stat-label">Entregadas a tiempo</div></div>
    <div class="card"><div class="stat-number"><?= (int) $o['pending'] ?></div><div class="stat-label">Pendientes</div></div>
    <div class="card"><div class="stat-number" style="color:<?= $o['overdue'] > 0 ? '#DC2626' : 'inherit' ?>;"><?= (int) $o['overdue'] ?></div><div class="stat-label">Vencidas sin entregar</div></div>
    <div class="card"><div class="stat-number"><?= (int) $o['graded'] ?></div><div class="stat-label">Con calificación</div></div>
</section>

<?php if (!empty($p['graded'])): ?>
<section class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Evolución de tus notas</h2>
    <p class="text-muted" style="font-size:0.85rem; margin-top:-6px;">Porcentaje obtenido en tus últimas <?= min(12, count($p['graded'])) ?> asignaciones calificadas, de la más antigua a la más reciente (pasa el cursor o toca una barra para ver cuál es).</p>
    <?= progress_chart_svg($p['graded']) ?>
    <p style="display:flex; gap:12px; flex-wrap:wrap; font-size:0.8rem; margin:6px 0 0;">
        <span style="color:#16A34A;">● 90% o más</span><span style="color:#0878F9;">● 70–89%</span><span style="color:#D97706;">● 60–69%</span><span style="color:#DC2626;">● menos de 60%</span>
    </p>
</section>
<?php endif; ?>

<section class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Por asignatura</h2>
    <p class="text-muted" style="font-size:0.85rem; margin-top:-6px;">El promedio cuenta más las asignaciones que valen más puntos.</p>
    <?php foreach ($p['subjects'] as $s):
        $pct = progress_percent($s);
        $done = $s['total'] > 0 ? round($s['completed'] / $s['total'] * 100) : 0;
    ?>
        <div style="padding:10px 0; border-bottom:1px solid var(--color-border);">
            <div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                <strong><?= e($s['name']) ?> <span class="text-muted" style="font-weight:400; font-size:0.85rem;">· <?= e($s['course']) ?></span></strong>
                <strong style="color:<?= e(progress_color($pct)) ?>;"><?= $pct !== null ? e(number_format($pct, 1, ',', '.')) . '%' : 'sin notas' ?></strong>
            </div>
            <div style="background:#EEF2F7; border-radius:999px; height:10px; margin:6px 0; overflow:hidden;" title="Promedio">
                <div style="width:<?= $pct !== null ? min(100, $pct) : 0 ?>%; height:100%; background:<?= e(progress_color($pct)) ?>;"></div>
            </div>
            <div class="text-muted" style="font-size:0.82rem;">
                ✅ <?= (int) $s['completed'] ?> de <?= (int) $s['total'] ?> entregadas (<?= (int) $done ?>%)
                · ⏳ <?= (int) $s['pending'] ?> pendientes
                <?= $s['overdue'] > 0 ? ' · <span style="color:#B91C1C; font-weight:600;">⌛ ' . (int) $s['overdue'] . ' vencidas</span>' : '' ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<?php if (!empty($p['recent'])): ?>
<section class="card">
    <h2 style="margin-top:0;">Tus últimas calificaciones</h2>
    <?php foreach ($p['recent'] as $g): ?>
        <div style="padding:8px 0; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <div>
                <strong><?= e($g['title']) ?></strong>
                <span class="text-muted" style="font-size:0.85rem;">· <?= e($g['subject']) ?></span>
                <?php if (!empty($g['feedback'])): ?><div class="text-muted" style="font-size:0.85rem; margin-top:2px;">💬 <?= e(mb_strimwidth((string) $g['feedback'], 0, 140, '…')) ?></div><?php endif; ?>
            </div>
            <strong style="color:<?= e(progress_color($g['pct'])) ?>;"><?= e(rtrim(rtrim(number_format($g['score'], 2, ',', '.'), '0'), ',')) ?> / <?= e((string) (int) $g['points']) ?> (<?= e((string) round($g['pct'])) ?>%)</strong>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
