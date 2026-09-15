<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/project_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();
$projectId = (int) ($_GET['project_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT sp.*, a.title AS assignment_title
     FROM student_projects sp
     LEFT JOIN assignments a ON a.id = sp.assignment_id
     WHERE sp.id = :id AND sp.student_id = :sid AND sp.type = "tabla_comparativa" LIMIT 1'
);
$stmt->execute(['id' => $projectId, 'sid' => $studentId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    exit('Trabajo no encontrado.');
}

$data = json_decode($project['data_json'], true) ?: ['columns' => ['Criterio', 'Opción A'], 'rows' => [['', '']]];
$isSubmitted = $project['status'] === 'submitted';

$pageTitle = $project['title'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  #table-wrap { overflow-x:auto; }
  #comparative-table { width:100%; border-collapse:collapse; }
  #comparative-table th, #comparative-table td { border:1px solid var(--color-border); padding:4px; }
  #comparative-table input { border:none; width:100%; padding:8px; font-size:0.95rem; font-family:inherit; }
  #comparative-table input:focus { outline:2px solid var(--color-primary); background:var(--color-primary-light); }
  .row-actions, .col-actions { text-align:center; }
</style>

<p><a href="<?= e(rtrim(APP_URL, '/')) ?>/student/assignment.php?id=<?= (int) $project['assignment_id'] ?>">&larr; Volver a la asignación</a></p>

<div class="card">
    <label for="title_input">Título</label>
    <input type="text" id="title_input" value="<?= e($project['title']) ?>" <?= $isSubmitted ? 'disabled' : '' ?>>

    <?php if ($isSubmitted): ?>
        <div id="table-wrap" style="margin-top:16px;">
            <table id="comparative-table">
                <thead><tr>
                    <?php foreach ($data['columns'] as $col): ?><th><?= e($col) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($data['rows'] as $row): ?>
                        <tr><?php foreach ($row as $cell): ?><td><?= e($cell) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="no-print" style="margin-top:16px;">
            <button type="button" class="btn btn-secondary" onclick="window.print()">Imprimir / Guardar como PDF</button>
        </div>
        <p class="alert alert-success" style="margin-top:16px;">Este trabajo ya fue entregado y no se puede modificar.</p>
    <?php else: ?>
        <div style="display:flex; gap:10px; margin:16px 0; flex-wrap:wrap;">
            <button class="btn btn-secondary" id="btn-add-col" style="margin:0;">+ Columna</button>
            <button class="btn btn-secondary" id="btn-add-row" style="margin:0;">+ Fila</button>
        </div>
        <div id="table-wrap"><table id="comparative-table"></table></div>

        <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; align-items:center;">
            <button class="btn btn-secondary" id="btn-save" style="margin:0;">Guardar borrador</button>
            <button class="btn" id="btn-submit" style="margin:0;">Entregar actividad</button>
            <span class="text-muted" id="save-status" style="font-size:0.85rem;"></span>
        </div>
        <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
            Una vez enviada, no podrás modificarla.
        </p>
    <?php endif; ?>
</div>

<?php if (!$isSubmitted): ?>
<script>
const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const PROJECT_ID = <?= (int) $project['id'] ?>;
const ASSIGNMENT_ID = <?= (int) ($project['assignment_id'] ?? 0) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

let tableData = <?= json_encode($data) ?>;

function renderTable() {
    const table = document.getElementById('comparative-table');
    let html = '<thead><tr>';
    tableData.columns.forEach((col, ci) => {
        html += `<th><input type="text" value="${escapeAttr(col)}" data-col="${ci}" class="col-header"></th>`;
    });
    html += '<th class="col-actions">-</th></tr></thead><tbody>';

    tableData.rows.forEach((row, ri) => {
        html += '<tr>';
        row.forEach((cell, ci) => {
            html += `<td><input type="text" value="${escapeAttr(cell)}" data-row="${ri}" data-col="${ci}" class="cell-input"></td>`;
        });
        html += `<td class="row-actions"><button type="button" class="btn-remove-row" data-row="${ri}" style="border:none; background:none; cursor:pointer; color:var(--color-danger);">✕</button></td></tr>`;
    });
    html += '</tbody>';
    table.innerHTML = html;

    document.querySelectorAll('.col-header').forEach(el => {
        el.addEventListener('input', e => { tableData.columns[e.target.dataset.col] = e.target.value; });
    });
    document.querySelectorAll('.cell-input').forEach(el => {
        el.addEventListener('input', e => { tableData.rows[e.target.dataset.row][e.target.dataset.col] = e.target.value; });
    });
    document.querySelectorAll('.btn-remove-row').forEach(el => {
        el.addEventListener('click', e => {
            if (tableData.rows.length <= 1) return;
            tableData.rows.splice(parseInt(e.target.dataset.row, 10), 1);
            renderTable();
        });
    });
}

function escapeAttr(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML.replace(/"/g, '&quot;');
}

document.getElementById('btn-add-col').addEventListener('click', () => {
    tableData.columns.push('Nueva columna');
    tableData.rows.forEach(row => row.push(''));
    renderTable();
});

document.getElementById('btn-add-row').addEventListener('click', () => {
    tableData.rows.push(tableData.columns.map(() => ''));
    renderTable();
});

async function saveDraft(silent) {
    const statusEl = document.getElementById('save-status');
    if (!silent) statusEl.textContent = 'Guardando...';
    try {
        const res = await fetch(`${AULA_APP_URL}/api/projects/save.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                project_id: PROJECT_ID,
                title: document.getElementById('title_input').value,
                data: tableData,
                csrf_token: CSRF_TOKEN,
            }),
        });
        const data = await res.json();
        statusEl.textContent = data.success ? 'Guardado ' + new Date().toLocaleTimeString() : (data.message || 'No se pudo guardar');
    } catch (e) {
        statusEl.textContent = 'No se pudo guardar (revisa tu conexión).';
    }
}

document.getElementById('btn-save').addEventListener('click', () => saveDraft(false));

document.getElementById('btn-submit').addEventListener('click', async () => {
    if (!confirm('Una vez enviada, no podrás modificarla. ¿Entregar ahora?')) return;
    await saveDraft(true);
    const res = await fetch(`${AULA_APP_URL}/api/projects/submit.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ project_id: PROJECT_ID, csrf_token: CSRF_TOKEN }),
    });
    const data = await res.json();
    if (data.success) {
        window.location.href = `${AULA_APP_URL}/student/assignment.php?id=${ASSIGNMENT_ID}`;
    } else {
        alert(data.message || 'No se pudo entregar el trabajo.');
    }
});

renderTable();
setInterval(() => saveDraft(true), 20000);
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
