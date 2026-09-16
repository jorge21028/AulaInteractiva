<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/project_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$projectId = (int) ($_GET['id'] ?? 0);

// Verificar que el trabajo pertenece a una asignación de este profesor
$stmt = $pdo->prepare(
    'SELECT sp.*, u.name AS student_name, a.title AS assignment_title, a.teacher_id
     FROM student_projects sp
     INNER JOIN users u ON u.id = sp.student_id
     LEFT JOIN assignments a ON a.id = sp.assignment_id
     WHERE sp.id = :id LIMIT 1'
);
$stmt->execute(['id' => $projectId]);
$project = $stmt->fetch();

if (!$project || (int) $project['teacher_id'] !== $teacherId) {
    http_response_code(404);
    exit('Trabajo no encontrado.');
}

$data = json_decode($project['data_json'], true) ?: [];

$pageTitle = $project['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="javascript:history.back()" class="no-print">&larr; Volver</a></p>
<h1><?= e($project['title']) ?></h1>
<p class="text-muted">
    Entregado por <?= e($project['student_name']) ?>
    <?php if ($project['submitted_at']): ?>
        el <?= e(date('d/m/Y H:i', strtotime($project['submitted_at']))) ?>
    <?php endif; ?>
</p>

<div class="no-print" style="margin-bottom:16px;">
    <?php if (in_array($project['type'], PROJECT_CANVAS_TYPES, true)): ?>
        <button type="button" class="btn btn-secondary" id="btn-download-png" style="margin:0;">Descargar como PNG</button>
    <?php elseif ($project['type'] !== 'presentacion'): ?>
        <button type="button" class="btn btn-secondary" onclick="window.print()" style="margin:0;">Imprimir / Guardar como PDF</button>
    <?php endif; ?>
</div>

<div class="card">
    <?php if ($project['type'] === 'resumen'): ?>
        <div style="line-height:1.7;"><?= $data['html'] ?? '<p class="text-muted">Sin contenido.</p>' ?></div>
    <?php elseif ($project['type'] === 'tabla_comparativa'): ?>
        <?php $columns = $data['columns'] ?? []; $rows = $data['rows'] ?? []; ?>
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th style="border:1px solid var(--color-border); padding:8px; background:var(--color-primary-light); text-align:left;"><?= e($col) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td style="border:1px solid var(--color-border); padding:8px;"><?= e($cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif (in_array($project['type'], PROJECT_CANVAS_TYPES, true)): ?>
        <div style="overflow:auto; border:1px solid var(--color-border); border-radius:8px; background:#EEF1F5; padding:20px; text-align:center;">
            <canvas id="project-canvas"></canvas>
        </div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
        <script>
        const data = <?= json_encode($data) ?>;
        const canvas = new fabric.Canvas('project-canvas', { selection: false });
        canvas.setWidth(data.width || 800);
        canvas.setHeight(data.height || 1200);
        canvas.setBackgroundColor(data.backgroundColor || '#ffffff', canvas.renderAll.bind(canvas));
        canvas.loadFromJSON({ objects: data.objects || [] }, () => {
            canvas.forEachObject(o => { o.selectable = false; o.evented = false; });
            canvas.renderAll();
        });

        document.getElementById('btn-download-png').addEventListener('click', () => {
            const dataUrl = canvas.toDataURL({ format: 'png', quality: 1 });
            const a = document.createElement('a');
            a.href = dataUrl;
            a.download = <?= json_encode(preg_replace('/[^a-zA-Z0-9_-]+/', '_', $project['title']) . '.png') ?>;
            a.click();
        });
        </script>
    <?php elseif ($project['type'] === 'presentacion'): ?>
        <style>
          #present-overlay { display:none; position:fixed; inset:0; background:#111; z-index:9999; align-items:center; justify-content:center; flex-direction:column; }
          #present-overlay.active { display:flex; }
          #present-controls { margin-top:16px; display:flex; gap:12px; align-items:center; color:#fff; }
          #present-controls button { padding:10px 18px; border:none; border-radius:6px; background:#fff; cursor:pointer; }
        </style>
        <div class="no-print" style="margin-bottom:12px;">
            <button type="button" class="btn" id="btn-present" style="margin:0;">▶ Presentar</button>
        </div>
        <div style="overflow:auto; border:1px solid var(--color-border); border-radius:8px; background:#EEF1F5; padding:20px; text-align:center;">
            <canvas id="project-canvas"></canvas>
        </div>
        <p class="text-muted no-print" style="font-size:0.85rem; margin-top:8px;" id="slide-nav">
            <button type="button" id="slide-prev" class="btn btn-secondary" style="margin:0; padding:4px 10px;">&larr;</button>
            <span id="slide-counter">1 / 1</span>
            <button type="button" id="slide-next" class="btn btn-secondary" style="margin:0; padding:4px 10px;">&rarr;</button>
        </p>

        <div id="present-overlay">
            <canvas id="present-canvas"></canvas>
            <div id="present-controls">
                <button type="button" id="present-prev">&larr; Anterior</button>
                <span id="present-counter">1 / 1</span>
                <button type="button" id="present-next">Siguiente &rarr;</button>
                <button type="button" id="present-close">✕ Cerrar</button>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
        <script>
        const slides = <?= json_encode($data['slides'] ?? []) ?>;
        let viewIndex = 0;
        const viewCanvas = new fabric.Canvas('project-canvas', { selection: false });

        function renderViewSlide() {
            const slide = slides[viewIndex];
            viewCanvas.setWidth(slide.width || 960);
            viewCanvas.setHeight(slide.height || 540);
            viewCanvas.setBackgroundColor(slide.backgroundColor || '#ffffff', viewCanvas.renderAll.bind(viewCanvas));
            viewCanvas.loadFromJSON({ objects: slide.objects || [] }, () => {
                viewCanvas.forEachObject(o => { o.selectable = false; o.evented = false; });
                viewCanvas.renderAll();
            });
            document.getElementById('slide-counter').textContent = `${viewIndex + 1} / ${slides.length}`;
        }
        document.getElementById('slide-prev').addEventListener('click', () => { if (viewIndex > 0) { viewIndex--; renderViewSlide(); } });
        document.getElementById('slide-next').addEventListener('click', () => { if (viewIndex < slides.length - 1) { viewIndex++; renderViewSlide(); } });
        renderViewSlide();

        let presentIndex = 0;
        let presentCanvas = null;
        function openPresent() {
            presentIndex = viewIndex;
            document.getElementById('present-overlay').classList.add('active');
            if (!presentCanvas) presentCanvas = new fabric.Canvas('present-canvas', { selection: false });
            renderPresentSlide();
        }
        function renderPresentSlide() {
            const slide = slides[presentIndex];
            presentCanvas.setWidth(slide.width || 960);
            presentCanvas.setHeight(slide.height || 540);
            presentCanvas.setBackgroundColor(slide.backgroundColor || '#ffffff', presentCanvas.renderAll.bind(presentCanvas));
            presentCanvas.loadFromJSON({ objects: slide.objects || [] }, () => {
                presentCanvas.forEachObject(o => { o.selectable = false; o.evented = false; });
                presentCanvas.renderAll();
            });
            document.getElementById('present-counter').textContent = `${presentIndex + 1} / ${slides.length}`;
        }
        document.getElementById('btn-present').addEventListener('click', openPresent);
        document.getElementById('present-close').addEventListener('click', () => document.getElementById('present-overlay').classList.remove('active'));
        document.getElementById('present-next').addEventListener('click', () => { if (presentIndex < slides.length - 1) { presentIndex++; renderPresentSlide(); } });
        document.getElementById('present-prev').addEventListener('click', () => { if (presentIndex > 0) { presentIndex--; renderPresentSlide(); } });
        document.addEventListener('keydown', (e) => {
            if (!document.getElementById('present-overlay').classList.contains('active')) return;
            if (e.key === 'ArrowRight' || e.key === ' ') document.getElementById('present-next').click();
            if (e.key === 'ArrowLeft') document.getElementById('present-prev').click();
            if (e.key === 'Escape') document.getElementById('present-overlay').classList.remove('active');
        });
        </script>
    <?php else: ?>
        <p class="text-muted">Tipo de trabajo no reconocido.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
