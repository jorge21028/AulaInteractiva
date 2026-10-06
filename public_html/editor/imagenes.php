<?php
/**
 * Editor de "Entrega de imágenes": el estudiante sube fotos desde su computador o celular como entrega de una tarea.
 * Las fotos se reducen en el navegador antes de subirlas (las fotos de celular son muy pesadas).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/project_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();
$projectId = (int) ($_GET['project_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT sp.*, a.title AS assignment_title, a.description AS assignment_description
     FROM student_projects sp
     LEFT JOIN assignments a ON a.id = sp.assignment_id
     WHERE sp.id = :id AND sp.student_id = :sid AND sp.type = "imagenes" LIMIT 1'
);
$stmt->execute(['id' => $projectId, 'sid' => $studentId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    exit('Trabajo no encontrado.');
}

$data = json_decode($project['data_json'], true) ?: ['images' => []];
$data = project_localize_data($data, 'imagenes');
$images = is_array($data['images'] ?? null) ? $data['images'] : [];
$isSubmitted = $project['status'] === 'submitted';

$pageTitle = $project['title'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  .photo-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:12px; margin-top:12px; }
  .photo-card { position:relative; background:#fff; border:1px solid var(--color-border); border-radius:12px; overflow:hidden; box-shadow:var(--shadow); }
  .photo-card .thumb { height:130px; background:#EEF1F5; display:flex; align-items:center; justify-content:center; }
  .photo-card img { max-width:100%; max-height:100%; object-fit:contain; }
  .photo-card input { margin:0; border:none; border-top:1px solid var(--color-border); border-radius:0; font-size:0.8rem; padding:6px 8px; }
  .photo-card .remove { position:absolute; top:6px; right:6px; width:28px; height:28px; border-radius:50%; border:none; background:rgba(15,23,42,0.75); color:#fff; cursor:pointer; font-size:1rem; line-height:1; }
  .photo-card .move { position:absolute; top:6px; left:6px; display:flex; gap:4px; }
  .photo-card .move button { width:26px; height:26px; border-radius:50%; border:none; background:rgba(255,255,255,0.9); cursor:pointer; font-size:0.8rem; }
  .photo-card.uploading .thumb { color:var(--color-text-muted); font-size:0.85rem; }
  .drop { border:2px dashed var(--color-primary); border-radius:14px; padding:22px 12px; text-align:center; background:var(--color-primary-light); }
  .drop.over { background:#D6E9FF; }
</style>

<p><a href="<?= e(rtrim(APP_URL, '/')) ?>/student/assignment.php?id=<?= (int) $project['assignment_id'] ?>">&larr; Volver a la asignación</a></p>

<div class="card">
    <h1 style="margin-top:0;">📷 <?= e($project['assignment_title'] ?? $project['title']) ?></h1>
    <?php if (!empty($project['assignment_description'])): ?>
        <p class="text-muted"><?= nl2br(e($project['assignment_description'])) ?></p>
    <?php endif; ?>

    <?php if ($isSubmitted): ?>
        <?php if (empty($images)): ?>
            <p class="text-muted">Sin imágenes.</p>
        <?php else: ?>
            <div class="photo-grid">
                <?php foreach ($images as $img): ?>
                    <div class="photo-card">
                        <div class="thumb"><img src="<?= e($img['url']) ?>" alt="<?= e($img['caption'] ?: 'Imagen') ?>"></div>
                        <?php if (($img['caption'] ?? '') !== ''): ?><p style="margin:0; padding:6px 8px; font-size:0.8rem;"><?= e($img['caption']) ?></p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="alert alert-success" style="margin-top:16px;">Este trabajo ya fue entregado y no se puede modificar.</p>
    <?php else: ?>
        <div class="drop" id="drop">
            <p style="margin:0 0 10px; font-weight:600;">Agrega las fotos de tu tarea</p>
            <button type="button" class="btn" id="btn-pick" style="margin:0;">📷 Elegir o tomar fotos</button>
            <input type="file" id="file-input" accept="image/png,image/jpeg,image/gif,image/webp" multiple style="display:none;">
            <p class="text-muted" style="margin:10px 0 0; font-size:0.85rem;">
                Desde tu celular puedes usar la cámara o la galería; desde el computador, arrastra las imágenes aquí.
                Máximo <?= (int) PROJECT_MAX_IMAGES ?> imágenes. Se ajustan automáticamente para que suban rápido.
            </p>
        </div>

        <p id="msg" class="text-muted" style="min-height:1.3em; margin:10px 0 0;"></p>
        <div class="photo-grid" id="grid"></div>
        <p class="text-muted" id="empty" style="display:none; text-align:center; margin-top:16px;">Todavía no has agregado imágenes.</p>

        <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; align-items:center;">
            <button class="btn btn-secondary" id="btn-save" style="margin:0;">Guardar borrador</button>
            <button class="btn" id="btn-submit" style="margin:0;">Entregar tarea</button>
            <span class="text-muted" id="save-status" style="font-size:0.85rem;"></span>
        </div>
        <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">Una vez enviada, no podrás modificarla.</p>
    <?php endif; ?>
</div>

<?php if (!$isSubmitted): ?>
<script src="<?= e(rtrim(APP_URL, '/')) ?>/assets/js/image_upload.js"></script>
<script>
const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const PROJECT_ID = <?= (int) $project['id'] ?>;
const ASSIGNMENT_ID = <?= (int) ($project['assignment_id'] ?? 0) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const MAX_IMAGES = <?= (int) PROJECT_MAX_IMAGES ?>;

let images = <?= json_encode(array_values($images), JSON_UNESCAPED_UNICODE) ?>;   // [{url, caption, name}]
let pending = 0;                                                                   // subidas en curso
let dirty = false;

const grid = document.getElementById('grid');
const msg = document.getElementById('msg');
const empty = document.getElementById('empty');
const statusEl = document.getElementById('save-status');

function render() {
    grid.innerHTML = '';
    images.forEach((img, i) => {
        const card = document.createElement('div');
        card.className = 'photo-card';
        const thumb = document.createElement('div'); thumb.className = 'thumb';
        const im = document.createElement('img'); im.src = img.url; im.alt = img.caption || ('Imagen ' + (i + 1));
        thumb.appendChild(im);
        const rm = document.createElement('button'); rm.type = 'button'; rm.className = 'remove'; rm.title = 'Quitar'; rm.textContent = '✕';
        rm.addEventListener('click', () => { images.splice(i, 1); changed(); });
        const mv = document.createElement('div'); mv.className = 'move';
        const up = document.createElement('button'); up.type = 'button'; up.textContent = '◀'; up.title = 'Mover antes';
        up.disabled = i === 0; up.addEventListener('click', () => { [images[i - 1], images[i]] = [images[i], images[i - 1]]; changed(); });
        const dn = document.createElement('button'); dn.type = 'button'; dn.textContent = '▶'; dn.title = 'Mover después';
        dn.disabled = i === images.length - 1; dn.addEventListener('click', () => { [images[i + 1], images[i]] = [images[i], images[i + 1]]; changed(); });
        mv.append(up, dn);
        const cap = document.createElement('input'); cap.type = 'text'; cap.maxLength = 200; cap.placeholder = 'Pie de foto (opcional)'; cap.value = img.caption || '';
        cap.addEventListener('input', () => { img.caption = cap.value; dirty = true; });
        card.append(thumb, rm, mv, cap);
        grid.appendChild(card);
    });
    for (let k = 0; k < pending; k++) {
        const c = document.createElement('div'); c.className = 'photo-card uploading';
        c.innerHTML = '<div class="thumb">Subiendo...</div>';
        grid.appendChild(c);
    }
    empty.style.display = (images.length === 0 && pending === 0) ? '' : 'none';
}

function changed() { dirty = true; render(); scheduleSave(); }

let saveTimer = null;
function scheduleSave() { clearTimeout(saveTimer); saveTimer = setTimeout(() => saveDraft(true), 1500); }

async function saveDraft(silent) {
    if (!silent) statusEl.textContent = 'Guardando...';
    try {
        const res = await fetch(`${AULA_APP_URL}/api/projects/save.php`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_id: PROJECT_ID, data: { images }, csrf_token: CSRF_TOKEN }),
        });
        const data = await res.json();
        if (data.success) { dirty = false; statusEl.textContent = 'Guardado ' + new Date().toLocaleTimeString(); }
        else { statusEl.textContent = data.message || 'No se pudo guardar'; }
        return !!data.success;
    } catch (e) {
        statusEl.textContent = 'No se pudo guardar (revisa tu conexión).';
        return false;
    }
}

async function addFiles(fileList) {
    const files = Array.from(fileList).filter(f => /^image\//i.test(f.type));
    if (files.length === 0) { msg.textContent = 'Elige archivos de imagen (JPG, PNG, GIF o WebP).'; return; }
    const room = MAX_IMAGES - images.length - pending;
    if (room <= 0) { msg.textContent = 'Ya llegaste al máximo de ' + MAX_IMAGES + ' imágenes.'; return; }
    const todo = files.slice(0, room);
    msg.textContent = files.length > room ? ('Solo se pueden agregar ' + room + ' más; se omitieron ' + (files.length - room) + '.') : '';

    pending += todo.length; render();
    const errors = [];
    for (const file of todo) {
        const r = await AulaImages.upload(file, { appUrl: AULA_APP_URL, projectId: PROJECT_ID, csrf: CSRF_TOKEN });
        pending--;
        if (r.ok) images.push({ url: r.url, caption: '', name: r.name || file.name });
        else errors.push(file.name + ': ' + r.message);
        render();
    }
    if (errors.length) msg.textContent = '⚠️ ' + errors.join(' · ');
    dirty = true; saveDraft(true);
}

const fileInput = document.getElementById('file-input');
document.getElementById('btn-pick').addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', () => { addFiles(fileInput.files); fileInput.value = ''; });

const drop = document.getElementById('drop');
['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', (e) => addFiles(e.dataTransfer.files));

document.getElementById('btn-save').addEventListener('click', () => saveDraft(false));

document.getElementById('btn-submit').addEventListener('click', async () => {
    if (pending > 0) { alert('Espera a que terminen de subirse las imágenes.'); return; }
    if (images.length === 0) { alert('Agrega al menos una imagen antes de entregar.'); return; }
    if (!confirm('Vas a entregar ' + images.length + ' imagen(es). Una vez enviada, no podrás modificarla. ¿Entregar ahora?')) return;
    const saved = await saveDraft(true);
    if (!saved) { alert('No se pudo guardar tu trabajo. Revisa tu conexión e intenta de nuevo.'); return; }
    const res = await fetch(`${AULA_APP_URL}/api/projects/submit.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ project_id: PROJECT_ID, csrf_token: CSRF_TOKEN }),
    });
    const data = await res.json();
    if (data.success) window.location.href = `${AULA_APP_URL}/student/assignment.php?id=${ASSIGNMENT_ID}`;
    else alert(data.message || 'No se pudo entregar el trabajo.');
});

window.addEventListener('beforeunload', (e) => { if (dirty || pending > 0) { e.preventDefault(); e.returnValue = ''; } });
render();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
