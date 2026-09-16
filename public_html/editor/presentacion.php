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
     WHERE sp.id = :id AND sp.student_id = :sid AND sp.type = "presentacion" LIMIT 1'
);
$stmt->execute(['id' => $projectId, 'sid' => $studentId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    exit('Trabajo no encontrado.');
}

$data = json_decode($project['data_json'], true) ?: project_default_data('presentacion');
if (empty($data['slides'])) {
    $data = project_default_data('presentacion');
}
$isSubmitted = $project['status'] === 'submitted';

$pageTitle = $project['title'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  .canvas-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
  .canvas-toolbar button { padding:8px 12px; border:1px solid var(--color-border); background:#fff; border-radius:6px; cursor:pointer; font-size:0.85rem; }
  .canvas-toolbar button:hover { background:var(--color-primary-light); }
  .canvas-toolbar input[type=color] { width:36px; height:34px; border:1px solid var(--color-border); border-radius:6px; padding:2px; cursor:pointer; }
  .canvas-toolbar .sep { width:1px; height:28px; background:var(--color-border); margin:0 4px; }
  .canvas-toolbar label.inline { font-weight:400; margin:0; font-size:0.8rem; color:var(--color-text-muted); }
  #present-editor { display:grid; grid-template-columns: 180px 1fr; gap:16px; margin-top:16px; }
  #slide-list { display:flex; flex-direction:column; gap:8px; max-height:600px; overflow-y:auto; }
  .slide-thumb { border:2px solid var(--color-border); border-radius:8px; padding:10px; text-align:center; cursor:pointer; font-size:0.85rem; background:#fff; }
  .slide-thumb.active { border-color:var(--color-primary); background:var(--color-primary-light); font-weight:600; }
  #canvas-holder { overflow:auto; border:1px solid var(--color-border); border-radius:8px; background:#EEF1F5; padding:20px; text-align:center; }
  #present-overlay { display:none; position:fixed; inset:0; background:#111; z-index:9999; align-items:center; justify-content:center; flex-direction:column; }
  #present-overlay.active { display:flex; }
  #present-canvas-holder { max-width:95vw; max-height:85vh; }
  #present-controls { margin-top:16px; display:flex; gap:12px; align-items:center; color:#fff; }
  #present-controls button { padding:10px 18px; border:none; border-radius:6px; background:#fff; cursor:pointer; }
  @media (max-width: 700px) { #present-editor { grid-template-columns: 1fr; } }
</style>

<p class="no-print"><a href="<?= e(rtrim(APP_URL, '/')) ?>/student/assignment.php?id=<?= (int) $project['assignment_id'] ?>">&larr; Volver a la asignación</a></p>

<div class="card">
    <label for="title_input">Título</label>
    <input type="text" id="title_input" value="<?= e($project['title']) ?>" <?= $isSubmitted ? 'disabled' : '' ?>>

    <?php if (!$isSubmitted): ?>
    <div class="canvas-toolbar no-print" style="margin-top:16px;">
        <button type="button" id="btn-add-text">+ Texto</button>
        <button type="button" id="btn-add-rect">+ Rectángulo</button>
        <button type="button" id="btn-add-circle">+ Círculo</button>
        <button type="button" id="btn-add-line">+ Línea</button>
        <button type="button" id="btn-upload-image">+ Imagen</button>
        <input type="file" id="file-image" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none;">
        <div class="sep"></div>
        <button type="button" id="btn-bold"><b>B</b></button>
        <button type="button" id="btn-italic"><i>I</i></button>
        <button type="button" id="btn-underline"><u>U</u></button>
        <label class="inline">Color <input type="color" id="fill-color" value="#1E4FA3"></label>
        <label class="inline">Fondo <input type="color" id="bg-color" value="#ffffff"></label>
        <div class="sep"></div>
        <button type="button" id="btn-duplicate">Duplicar</button>
        <button type="button" id="btn-delete">Eliminar</button>
        <button type="button" id="btn-front">Al frente</button>
        <button type="button" id="btn-back">Atrás</button>
        <div class="sep"></div>
        <button type="button" id="btn-undo">↶ Deshacer</button>
        <button type="button" id="btn-redo">↷ Rehacer</button>
    </div>
    <?php endif; ?>

    <div id="present-editor">
        <div id="slide-list" class="no-print"></div>
        <div>
            <?php if (!$isSubmitted): ?>
            <div class="no-print" style="display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary" id="btn-slide-add" style="margin:0;">+ Diapositiva</button>
                <button type="button" class="btn btn-secondary" id="btn-slide-duplicate" style="margin:0;">Duplicar</button>
                <button type="button" class="btn btn-secondary" id="btn-slide-delete" style="margin:0;">Eliminar</button>
                <button type="button" class="btn btn-secondary" id="btn-slide-up" style="margin:0;">↑</button>
                <button type="button" class="btn btn-secondary" id="btn-slide-down" style="margin:0;">↓</button>
            </div>
            <?php endif; ?>
            <div id="canvas-holder">
                <canvas id="project-canvas"></canvas>
            </div>
        </div>
    </div>

    <div class="no-print" style="margin-top:16px;">
        <button type="button" class="btn" id="btn-present" style="margin:0;">▶ Presentar</button>
    </div>

    <?php if ($isSubmitted): ?>
        <p class="alert alert-success" style="margin-top:16px;">Este trabajo ya fue entregado y no se puede modificar.</p>
    <?php else: ?>
        <div class="no-print" style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; align-items:center;">
            <button class="btn btn-secondary" id="btn-save" style="margin:0;">Guardar borrador</button>
            <button class="btn" id="btn-submit" style="margin:0;">Entregar actividad</button>
            <span class="text-muted" id="save-status" style="font-size:0.85rem;"></span>
        </div>
        <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">Una vez enviada, no podrás modificarla.</p>
    <?php endif; ?>
</div>

<!-- Modo presentación en pantalla completa -->
<div id="present-overlay">
    <div id="present-canvas-holder">
        <canvas id="present-canvas"></canvas>
    </div>
    <div id="present-controls">
        <button type="button" id="present-prev">&larr; Anterior</button>
        <span id="present-counter" style="min-width:80px; text-align:center;">1 / 1</span>
        <button type="button" id="present-next">Siguiente &rarr;</button>
        <button type="button" id="present-close">✕ Cerrar</button>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
<script>
const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const PROJECT_ID = <?= (int) $project['id'] ?>;
const ASSIGNMENT_ID = <?= (int) ($project['assignment_id'] ?? 0) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const IS_SUBMITTED = <?= $isSubmitted ? 'true' : 'false' ?>;
const CUSTOM_PROPS = [];

let slides = <?= json_encode($data['slides']) ?>;
let currentSlide = 0;

const canvas = new fabric.Canvas('project-canvas', { selection: !IS_SUBMITTED });

function renderSlideList() {
    const list = document.getElementById('slide-list');
    list.innerHTML = slides.map((s, i) =>
        `<div class="slide-thumb ${i === currentSlide ? 'active' : ''}" data-index="${i}">Diapositiva ${i + 1}</div>`
    ).join('');
    list.querySelectorAll('.slide-thumb').forEach(el => {
        el.addEventListener('click', () => switchToSlide(parseInt(el.dataset.index, 10)));
    });
}

function captureCurrentSlide() {
    const json = canvas.toJSON(CUSTOM_PROPS);
    slides[currentSlide] = {
        width: canvas.width,
        height: canvas.height,
        backgroundColor: canvas.backgroundColor,
        objects: json.objects,
    };
}

let isRestoring = false;
let undoStack = [];
let redoStack = [];

function pushHistory() {
    if (isRestoring || IS_SUBMITTED) return;
    undoStack.push(JSON.stringify(canvas.toJSON(CUSTOM_PROPS)));
    if (undoStack.length > 60) undoStack.shift();
    redoStack = [];
}

function loadSlideIntoCanvas(index) {
    const slide = slides[index];
    isRestoring = true;
    canvas.setWidth(slide.width || 960);
    canvas.setHeight(slide.height || 540);
    canvas.setBackgroundColor(slide.backgroundColor || '#ffffff', canvas.renderAll.bind(canvas));
    canvas.loadFromJSON({ objects: slide.objects || [] }, () => {
        canvas.renderAll();
        if (IS_SUBMITTED) {
            canvas.forEachObject(o => { o.selectable = false; o.evented = false; });
        }
        isRestoring = false;
        undoStack = [];
        redoStack = [];
        pushHistory();
    });
}

function switchToSlide(index) {
    if (index === currentSlide) return;
    if (!IS_SUBMITTED) captureCurrentSlide();
    currentSlide = index;
    loadSlideIntoCanvas(currentSlide);
    renderSlideList();
}

renderSlideList();
loadSlideIntoCanvas(currentSlide);

if (!IS_SUBMITTED) {
    canvas.on('object:added', pushHistory);
    canvas.on('object:modified', pushHistory);
    canvas.on('object:removed', pushHistory);

    document.getElementById('btn-undo').addEventListener('click', () => {
        if (undoStack.length < 2) return;
        redoStack.push(undoStack.pop());
        isRestoring = true;
        canvas.loadFromJSON(JSON.parse(undoStack[undoStack.length - 1]), () => { canvas.renderAll(); isRestoring = false; });
    });
    document.getElementById('btn-redo').addEventListener('click', () => {
        if (redoStack.length === 0) return;
        const next = redoStack.pop();
        undoStack.push(next);
        isRestoring = true;
        canvas.loadFromJSON(JSON.parse(next), () => { canvas.renderAll(); isRestoring = false; });
    });

    document.getElementById('btn-add-text').addEventListener('click', () => {
        const t = new fabric.Textbox('Escribe aquí', { left: 60, top: 60, width: 300, fontSize: 28, fill: '#1F2937' });
        canvas.add(t).setActiveObject(t);
    });
    document.getElementById('btn-add-rect').addEventListener('click', () => {
        const r = new fabric.Rect({ left: 80, top: 80, width: 180, height: 110, fill: '#E8F0FE', stroke: '#1E4FA3', strokeWidth: 2 });
        canvas.add(r).setActiveObject(r);
    });
    document.getElementById('btn-add-circle').addEventListener('click', () => {
        const c = new fabric.Circle({ left: 100, top: 100, radius: 60, fill: '#E8F0FE', stroke: '#1E4FA3', strokeWidth: 2 });
        canvas.add(c).setActiveObject(c);
    });
    document.getElementById('btn-add-line').addEventListener('click', () => {
        const l = new fabric.Line([50, 50, 250, 50], { stroke: '#1F2937', strokeWidth: 3 });
        canvas.add(l).setActiveObject(l);
    });
    document.getElementById('btn-upload-image').addEventListener('click', () => document.getElementById('file-image').click());
    document.getElementById('file-image').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('image', file);
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        try {
            const res = await fetch(`${AULA_APP_URL}/api/projects/upload_image.php`, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                fabric.Image.fromURL(data.url, (img) => {
                    img.scaleToWidth(240);
                    img.set({ left: 60, top: 60 });
                    canvas.add(img).setActiveObject(img);
                }, { crossOrigin: 'anonymous' });
            } else {
                alert(data.message || 'No se pudo subir la imagen.');
            }
        } catch (err) {
            alert('No se pudo subir la imagen.');
        }
        e.target.value = '';
    });

    document.getElementById('fill-color').addEventListener('input', (e) => {
        const obj = canvas.getActiveObject();
        if (obj) { obj.set('fill', e.target.value); canvas.renderAll(); pushHistory(); }
    });
    document.getElementById('bg-color').addEventListener('input', (e) => {
        canvas.setBackgroundColor(e.target.value, canvas.renderAll.bind(canvas));
    });
    document.getElementById('btn-bold').addEventListener('click', () => toggleTextProp('fontWeight', 'bold', 'normal'));
    document.getElementById('btn-italic').addEventListener('click', () => toggleTextProp('fontStyle', 'italic', 'normal'));
    document.getElementById('btn-underline').addEventListener('click', () => toggleTextProp('underline', true, false));
    function toggleTextProp(prop, onValue, offValue) {
        const obj = canvas.getActiveObject();
        if (!obj || obj.type !== 'textbox') return;
        obj.set(prop, obj[prop] === onValue ? offValue : onValue);
        canvas.renderAll();
        pushHistory();
    }
    document.getElementById('btn-duplicate').addEventListener('click', () => {
        const obj = canvas.getActiveObject();
        if (!obj) return;
        obj.clone((clone) => { clone.set({ left: obj.left + 20, top: obj.top + 20 }); canvas.add(clone).setActiveObject(clone); });
    });
    document.getElementById('btn-delete').addEventListener('click', () => {
        canvas.getActiveObjects().forEach(o => canvas.remove(o));
        canvas.discardActiveObject();
        canvas.renderAll();
    });
    document.getElementById('btn-front').addEventListener('click', () => {
        const obj = canvas.getActiveObject();
        if (obj) { canvas.bringToFront(obj); pushHistory(); }
    });
    document.getElementById('btn-back').addEventListener('click', () => {
        const obj = canvas.getActiveObject();
        if (obj) { canvas.sendToBack(obj); pushHistory(); }
    });

    // ---- Gestión de diapositivas ----
    document.getElementById('btn-slide-add').addEventListener('click', () => {
        captureCurrentSlide();
        slides.splice(currentSlide + 1, 0, { width: 960, height: 540, backgroundColor: '#ffffff', objects: [] });
        currentSlide += 1;
        loadSlideIntoCanvas(currentSlide);
        renderSlideList();
    });
    document.getElementById('btn-slide-duplicate').addEventListener('click', () => {
        captureCurrentSlide();
        const copy = JSON.parse(JSON.stringify(slides[currentSlide]));
        slides.splice(currentSlide + 1, 0, copy);
        currentSlide += 1;
        loadSlideIntoCanvas(currentSlide);
        renderSlideList();
    });
    document.getElementById('btn-slide-delete').addEventListener('click', () => {
        if (slides.length <= 1) { alert('Debe quedar al menos una diapositiva.'); return; }
        if (!confirm('¿Eliminar esta diapositiva?')) return;
        slides.splice(currentSlide, 1);
        currentSlide = Math.max(0, currentSlide - 1);
        loadSlideIntoCanvas(currentSlide);
        renderSlideList();
    });
    document.getElementById('btn-slide-up').addEventListener('click', () => {
        if (currentSlide === 0) return;
        captureCurrentSlide();
        [slides[currentSlide - 1], slides[currentSlide]] = [slides[currentSlide], slides[currentSlide - 1]];
        currentSlide -= 1;
        renderSlideList();
    });
    document.getElementById('btn-slide-down').addEventListener('click', () => {
        if (currentSlide === slides.length - 1) return;
        captureCurrentSlide();
        [slides[currentSlide + 1], slides[currentSlide]] = [slides[currentSlide], slides[currentSlide + 1]];
        currentSlide += 1;
        renderSlideList();
    });

    async function saveDraft(silent) {
        captureCurrentSlide();
        const statusEl = document.getElementById('save-status');
        if (!silent) statusEl.textContent = 'Guardando...';
        try {
            const res = await fetch(`${AULA_APP_URL}/api/projects/save.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: PROJECT_ID,
                    title: document.getElementById('title_input').value,
                    data: { slides },
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

    setInterval(() => saveDraft(true), 20000);
}

// ---- Modo presentación (pantalla completa) ----
let presentIndex = 0;
let presentCanvas = null;

function openPresent() {
    if (!IS_SUBMITTED) captureCurrentSlide();
    presentIndex = currentSlide;
    document.getElementById('present-overlay').classList.add('active');
    if (document.documentElement.requestFullscreen) {
        document.getElementById('present-overlay').requestFullscreen?.().catch(() => {});
    }
    if (!presentCanvas) {
        presentCanvas = new fabric.Canvas('present-canvas', { selection: false });
    }
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

function closePresent() {
    document.getElementById('present-overlay').classList.remove('active');
    if (document.fullscreenElement) {
        document.exitFullscreen?.().catch(() => {});
    }
}

document.getElementById('btn-present').addEventListener('click', openPresent);
document.getElementById('present-close').addEventListener('click', closePresent);
document.getElementById('present-next').addEventListener('click', () => {
    if (presentIndex < slides.length - 1) { presentIndex++; renderPresentSlide(); }
});
document.getElementById('present-prev').addEventListener('click', () => {
    if (presentIndex > 0) { presentIndex--; renderPresentSlide(); }
});
document.addEventListener('keydown', (e) => {
    if (!document.getElementById('present-overlay').classList.contains('active')) return;
    if (e.key === 'ArrowRight' || e.key === ' ') { document.getElementById('present-next').click(); }
    if (e.key === 'ArrowLeft') { document.getElementById('present-prev').click(); }
    if (e.key === 'Escape') { closePresent(); }
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
