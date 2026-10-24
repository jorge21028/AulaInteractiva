<?php
/**
 * Editor de "Entrega de archivos": el estudiante sube PDF, Word, Excel y otros archivos como entrega de una tarea.
 * Cada archivo queda guardado en el servidor apenas termina de subirse, así que no se pierde si cierra o actualiza la página.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/project_helpers.php';
require_once __DIR__ . '/../includes/group_helpers.php';
require_once __DIR__ . '/../includes/project_files_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = (int) current_user_id();
$projectId = (int) ($_GET['project_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT sp.*, a.title AS assignment_title, a.description AS assignment_description
     FROM student_projects sp
     LEFT JOIN assignments a ON a.id = sp.assignment_id
     WHERE sp.id = :id AND sp.student_id = :sid AND sp.type = "archivos" LIMIT 1'
);
$stmt->execute(['id' => $projectId, 'sid' => project_effective_student($pdo, $projectId, (int) $studentId)]); // el dueño real (grupos)
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    exit('Trabajo no encontrado.');
}

$data = json_decode((string) $project['data_json'], true) ?: ['note' => ''];
$note = (string) ($data['note'] ?? '');
$files = project_files_list($pdo, (int) $project['id'], true);
$isSubmitted = $project['status'] === 'submitted';
// Fecha de entrega vencida / curso inactivo: el trabajo queda en solo lectura
$deadlineLocked = !$isSubmitted && assignment_lock_reason($pdo, $project['assignment_id'] ? (int) $project['assignment_id'] : null, $studentId) !== null;
$readOnly = $isSubmitted || $deadlineLocked;

$allowedLabel = 'PDF, Word, Excel, PowerPoint, texto, ZIP o imágenes';
$accept = '.' . implode(',.', PROJECT_FILE_ALLOWED_EXT);
$base = rtrim(APP_URL, '/');
$dl = $base . '/api/submissions/download.php?id=';

$pageTitle = $project['title'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  .file-list { list-style:none; margin:12px 0 0; padding:0; }
  .file-list li { display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid var(--color-border); border-radius:10px; background:#fff; margin-bottom:8px; flex-wrap:wrap; }
  .file-list .fname { flex:1; min-width:160px; word-break:break-word; }
  .file-list .fsize { color:var(--color-text-muted); font-size:0.85rem; white-space:nowrap; }
  .file-list .gone { color:var(--color-text-muted); font-style:italic; }
  .file-list .rm { border:none; background:none; color:#B91C1C; cursor:pointer; font-size:0.9rem; }
  .drop { border:2px dashed var(--color-primary); border-radius:14px; padding:22px 12px; text-align:center; background:var(--color-primary-light); }
  .drop.over { background:#D6E9FF; }
  .bar { height:6px; background:#E2E8F0; border-radius:999px; overflow:hidden; width:100%; }
  .bar > span { display:block; height:100%; width:0; background:var(--color-primary); transition:width .15s; }
</style>

<p><a href="<?= e($base) ?>/student/assignment.php?id=<?= (int) $project['assignment_id'] ?>">&larr; Volver a la asignación</a></p>

<div class="card">
    <h1 style="margin-top:0;">📎 <?= e($project['assignment_title'] ?? $project['title']) ?></h1>
    <?php if (!empty($project['assignment_description'])): ?>
        <p class="text-muted"><?= nl2br(e($project['assignment_description'])) ?></p>
    <?php endif; ?>

    <?php if ($readOnly): ?>
        <?php if (empty($files)): ?>
            <p class="text-muted">No se subieron archivos.</p>
        <?php else: ?>
            <ul class="file-list">
                <?php foreach ($files as $f): ?>
                    <li>
                        <?php if (!empty($f['deleted_at'])): ?>
                            <span>🗑️</span>
                            <span class="fname gone"><?= e($f['original_name']) ?> — <?= e(project_files_deleted_label($f)) ?></span>
                        <?php else: ?>
                            <span><?= materials_file_icon($f['original_name']) ?></span>
                            <a class="fname" href="<?= e($dl . (int) $f['id']) ?>"><?= e($f['original_name']) ?></a>
                            <span class="fsize"><?= e(materials_format_size((int) $f['size_bytes'])) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($note !== ''): ?><p style="margin-top:12px;"><strong>Tu comentario:</strong> <?= nl2br(e($note)) ?></p><?php endif; ?>
        <p class="alert alert-success" style="margin-top:16px;"><?= $deadlineLocked ? 'La fecha de entrega venció: este trabajo ya no se puede modificar ni entregar.' : 'Este trabajo ya fue entregado y no se puede modificar.' ?></p>
    <?php else: ?>
        <div class="drop" id="drop">
            <p style="margin:0 0 10px; font-weight:600;">Sube los archivos de tu tarea</p>
            <button type="button" class="btn" id="btn-pick" style="margin:0;">📎 Elegir archivos</button>
            <input type="file" id="file-input" accept="<?= e($accept) ?>" multiple style="display:none;">
            <p class="text-muted" style="margin:10px 0 0; font-size:0.85rem;">
                Formatos: <?= e($allowedLabel) ?>. Máximo <?= (int) PROJECT_FILE_MAX_COUNT ?> archivos de hasta <?= e(materials_format_size(PROJECT_FILE_MAX_BYTES)) ?> cada uno.
                Puedes arrastrarlos aquí. Cada archivo queda guardado apenas termina de subirse.
            </p>
        </div>

        <p id="msg" class="text-muted" style="min-height:1.3em; margin:10px 0 0;"></p>
        <ul class="file-list" id="list"></ul>
        <p class="text-muted" id="empty" style="display:none; text-align:center; margin-top:16px;">Todavía no has subido archivos.</p>

        <label for="note" style="margin-top:16px;">Comentario para tu profesor (opcional)</label>
        <textarea id="note" rows="3" maxlength="1000" placeholder="Por ejemplo: aclaraciones sobre tu entrega"><?= e($note) ?></textarea>

        <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; align-items:center;">
            <button class="btn btn-secondary" id="btn-save" style="margin:0;">Guardar borrador</button>
            <button class="btn" id="btn-submit" style="margin:0;">Entregar tarea</button>
            <span class="text-muted" id="save-status" style="font-size:0.85rem;"></span>
        </div>
        <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">Puedes seguir editando hasta la fecha de entrega. Una vez enviada, no podrás modificarla.</p>
    <?php endif; ?>
</div>

<?php if (!$readOnly): ?>
<?= paste_guard_tags(project_paste_blocked($pdo, $project)) ?>
<script src="<?= e($base) ?>/assets/js/draft_guard.js"></script>
<script>
const AULA_APP_URL = <?= json_encode($base) ?>;
const PROJECT_ID = <?= (int) $project['id'] ?>;
const ASSIGNMENT_ID = <?= (int) ($project['assignment_id'] ?? 0) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const MAX_FILES = <?= (int) PROJECT_FILE_MAX_COUNT ?>;
const MAX_BYTES = <?= (int) PROJECT_FILE_MAX_BYTES ?>;
const ALLOWED = <?= json_encode(PROJECT_FILE_ALLOWED_EXT) ?>;
const DOWNLOAD = <?= json_encode($dl) ?>;

// Archivos ya guardados en el servidor (los eliminados por el profesor solo se muestran como aviso)
let files = <?= json_encode(array_map(fn($f) => [
    'id' => (int) $f['id'], 'name' => $f['original_name'], 'size' => (int) $f['size_bytes'],
    'size_label' => materials_format_size((int) $f['size_bytes']), 'icon' => materials_file_icon($f['original_name']),
    'gone' => !empty($f['deleted_at']), 'gone_label' => !empty($f['deleted_at']) ? project_files_deleted_label($f) : '',
], $files), JSON_UNESCAPED_UNICODE) ?>;
let uploading = [];   // [{name, pct}]

const list = document.getElementById('list');
const msg = document.getElementById('msg');
const empty = document.getElementById('empty');
const statusEl = document.getElementById('save-status');
const noteEl = document.getElementById('note');

function activeCount() { return files.filter(f => !f.gone).length; }

function render() {
    list.innerHTML = '';
    files.forEach((f) => {
        const li = document.createElement('li');
        if (f.gone) {
            li.innerHTML = '<span>🗑️</span><span class="fname gone"></span>';
            li.querySelector('.fname').textContent = f.name + ' — ' + f.gone_label;
        } else {
            const ic = document.createElement('span'); ic.textContent = f.icon;
            const a = document.createElement('a'); a.className = 'fname'; a.href = DOWNLOAD + f.id; a.textContent = f.name;
            const sz = document.createElement('span'); sz.className = 'fsize'; sz.textContent = f.size_label;
            const rm = document.createElement('button'); rm.type = 'button'; rm.className = 'rm'; rm.textContent = '✕ Quitar';
            rm.addEventListener('click', () => removeFile(f));
            li.append(ic, a, sz, rm);
        }
        list.appendChild(li);
    });
    uploading.forEach((u) => {
        const li = document.createElement('li');
        li.innerHTML = '<span>⏳</span><span class="fname"></span><div class="bar"><span></span></div>';
        li.querySelector('.fname').textContent = u.name + ' — subiendo...';
        li.querySelector('.bar span').style.width = u.pct + '%';
        list.appendChild(li);
    });
    empty.style.display = (files.length === 0 && uploading.length === 0) ? '' : 'none';
}

function uploadOne(file) {
    return new Promise((resolve) => {
        const entry = { name: file.name, pct: 0 };
        uploading.push(entry); render();
        const fd = new FormData();
        fd.append('file', file); fd.append('project_id', PROJECT_ID); fd.append('csrf_token', CSRF_TOKEN);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', AULA_APP_URL + '/api/projects/upload_file.php');
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) { entry.pct = Math.round(e.loaded * 100 / e.total); render(); } };
        const done = (res) => { uploading = uploading.filter(u => u !== entry); render(); resolve(res); };
        xhr.onload = () => {
            let data = null; try { data = JSON.parse(xhr.responseText); } catch (e) { /* respuesta no JSON */ }
            if (data && data.success) done({ ok: true, file: data.file, version: data.version });
            else done({ ok: false, message: (data && data.message) || 'No se pudo subir el archivo.' });
        };
        xhr.onerror = () => done({ ok: false, message: 'Falló la conexión al subir «' + file.name + '».' });
        xhr.send(fd);
    });
}

async function addFiles(fileList) {
    const picked = Array.from(fileList);
    if (picked.length === 0) return;
    const errors = [];
    const valid = [];
    for (const f of picked) {
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!ALLOWED.includes(ext)) errors.push('«' + f.name + '»: tipo no permitido (.' + ext + ').');
        else if (f.size > MAX_BYTES) errors.push('«' + f.name + '» es demasiado pesado (máx. ' + Math.round(MAX_BYTES / 1048576) + ' MB).');
        else valid.push(f);
    }
    const room = MAX_FILES - activeCount() - uploading.length;
    if (valid.length > room) { errors.push('Solo caben ' + Math.max(room, 0) + ' archivo(s) más (máximo ' + MAX_FILES + ').'); }
    for (const f of valid.slice(0, Math.max(room, 0))) {
        const r = await uploadOne(f);
        if (r.ok) { files.push({ ...r.file, gone: false }); AulaDraft.setVersion(r.version); render(); }
        else errors.push(r.message);
    }
    msg.textContent = errors.length ? '⚠️ ' + errors.join(' · ') : '';
}

async function removeFile(f) {
    if (!confirm('¿Quitar «' + f.name + '»?')) return;
    try {
        const res = await fetch(AULA_APP_URL + '/api/projects/delete_file.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_id: PROJECT_ID, file_id: f.id, csrf_token: CSRF_TOKEN }),
        });
        const data = await res.json();
        if (data.success) { files = files.filter(x => x.id !== f.id); AulaDraft.setVersion(data.version); render(); }
        else msg.textContent = '⚠️ ' + (data.message || 'No se pudo quitar el archivo.');
    } catch (e) { msg.textContent = '⚠️ No se pudo quitar el archivo (revisa tu conexión).'; }
}

const fileInput = document.getElementById('file-input');
document.getElementById('btn-pick').addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', () => { addFiles(fileInput.files); fileInput.value = ''; });
const drop = document.getElementById('drop');
['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', (e) => addFiles(e.dataTransfer.files));

// El comentario se protege con el autoguardado compartido (copia local + guardado al cerrar la página)
AulaDraft.init({
    appUrl: AULA_APP_URL, projectId: PROJECT_ID, csrf: CSRF_TOKEN, userKey: <?= (int) $studentId ?>, version: <?= json_encode($project['updated_at']) ?>,
    statusEl: statusEl,
    getState: () => ({ data: { note: noteEl.value } }),
    applyState: (s) => { noteEl.value = (s.data && s.data.note) || ''; },
    isBusy: () => uploading.length > 0,
});

document.getElementById('btn-save').addEventListener('click', async () => {
    const ok = await AulaDraft.saveNow(false);
    if (!ok) statusEl.textContent = statusEl.textContent || 'No se pudo guardar';
});

document.getElementById('btn-submit').addEventListener('click', async () => {
    if (uploading.length > 0) { alert('Espera a que terminen de subirse los archivos.'); return; }
    const n = activeCount();
    if (n === 0) { alert('Sube al menos un archivo antes de entregar.'); return; }
    if (!confirm('Vas a entregar ' + n + ' archivo(s). Una vez enviada, no podrás modificarla. ¿Entregar ahora?')) return;
    if (!(await AulaDraft.saveNow(true))) { alert('No se pudo guardar tu trabajo. Revisa tu conexión e intenta de nuevo.'); return; }
    const res = await fetch(AULA_APP_URL + '/api/projects/submit.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ project_id: PROJECT_ID, csrf_token: CSRF_TOKEN }),
    });
    const data = await res.json();
    if (data.success) { AulaDraft.clearLocal(); window.location.href = AULA_APP_URL + '/student/assignment.php?id=' + ASSIGNMENT_ID; }
    else alert(data.message || 'No se pudo entregar el trabajo.');
});

render();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
