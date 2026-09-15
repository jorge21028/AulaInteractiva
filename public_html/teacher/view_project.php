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
    <?php else: ?>
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
    <?php else: ?>
        <p class="text-muted">Tipo de trabajo no reconocido.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
