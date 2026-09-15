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
     WHERE sp.id = :id AND sp.student_id = :sid AND sp.type = "resumen" LIMIT 1'
);
$stmt->execute(['id' => $projectId, 'sid' => $studentId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    exit('Trabajo no encontrado.');
}

$data = json_decode($project['data_json'], true) ?: ['html' => ''];
$isSubmitted = $project['status'] === 'submitted';

$pageTitle = $project['title'];
require __DIR__ . '/../includes/header.php';
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.6/quill.snow.min.css" rel="stylesheet">
<style>
  #editor-container { background:#fff; min-height:300px; }
  .ql-editor { min-height:300px; font-size:1rem; }
</style>

<p><a href="<?= e(rtrim(APP_URL, '/')) ?>/student/assignment.php?id=<?= (int) $project['assignment_id'] ?>">&larr; Volver a la asignación</a></p>

<div class="card">
    <label for="title_input">Título</label>
    <input type="text" id="title_input" value="<?= e($project['title']) ?>" <?= $isSubmitted ? 'disabled' : '' ?>>

    <label style="margin-top:16px;">Contenido</label>
    <?php if ($isSubmitted): ?>
        <div class="card" style="line-height:1.7;"><?= $data['html'] ?></div>
        <div class="no-print" style="margin-top:16px;">
            <button type="button" class="btn btn-secondary" onclick="window.print()">Imprimir / Guardar como PDF</button>
        </div>
        <p class="alert alert-success" style="margin-top:16px;">Este trabajo ya fue entregado y no se puede modificar.</p>
    <?php else: ?>
        <div style="display:flex; gap:8px; margin-bottom:8px;">
            <button type="button" class="btn btn-secondary" id="btn-add-audio" style="margin:0; padding:6px 12px; font-size:0.85rem;">+ Audio</button>
            <button type="button" class="btn btn-secondary" id="btn-add-video" style="margin:0; padding:6px 12px; font-size:0.85rem;">+ Video</button>
            <input type="file" id="file-audio" accept="audio/mpeg,audio/wav,audio/ogg" style="display:none;">
            <input type="file" id="file-video" accept="video/mp4,video/webm" style="display:none;">
        </div>
        <div id="editor-container"><?= $data['html'] ?></div>

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
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.6/quill.min.js"></script>
<script>
const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const PROJECT_ID = <?= (int) $project['id'] ?>;
const ASSIGNMENT_ID = <?= (int) ($project['assignment_id'] ?? 0) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

const quill = new Quill('#editor-container', {
    theme: 'snow',
    modules: {
        toolbar: [
            ['bold', 'italic', 'underline', 'strike'],
            [{ header: 1 }, { header: 2 }],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['blockquote', 'link'],
            ['clean'],
        ],
    },
});

async function uploadMedia(kind, file) {
    const formData = new FormData();
    formData.append('media', file);
    formData.append('kind', kind);
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);

    const res = await fetch(`${AULA_APP_URL}/api/projects/upload_media.php`, { method: 'POST', body: formData });
    return res.json();
}

document.getElementById('btn-add-audio').addEventListener('click', () => document.getElementById('file-audio').click());
document.getElementById('btn-add-video').addEventListener('click', () => document.getElementById('file-video').click());

document.getElementById('file-audio').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const data = await uploadMedia('audio', file);
    if (data.success) {
        const range = quill.getSelection(true);
        quill.clipboard.dangerouslyPasteHTML(range.index, `<audio controls src="${data.url}"></audio><p><br></p>`);
    } else {
        alert(data.message || 'No se pudo subir el audio.');
    }
    e.target.value = '';
});

document.getElementById('file-video').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const data = await uploadMedia('video', file);
    if (data.success) {
        const range = quill.getSelection(true);
        quill.clipboard.dangerouslyPasteHTML(range.index, `<video controls width="320" src="${data.url}"></video><p><br></p>`);
    } else {
        alert(data.message || 'No se pudo subir el video.');
    }
    e.target.value = '';
});

async function saveDraft(silent) {
    const statusEl = document.getElementById('save-status');    if (!silent) statusEl.textContent = 'Guardando...';
    try {
        const res = await fetch(`${AULA_APP_URL}/api/projects/save.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                project_id: PROJECT_ID,
                title: document.getElementById('title_input').value,
                data: { html: quill.root.innerHTML },
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

// Autoguardado cada 20 segundos
setInterval(() => saveDraft(true), 20000);
window.addEventListener('beforeunload', () => { navigator.sendBeacon && saveDraft(true); });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
