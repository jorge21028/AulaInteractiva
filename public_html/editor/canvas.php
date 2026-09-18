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
     WHERE sp.id = :id AND sp.student_id = :sid LIMIT 1'
);
$stmt->execute(['id' => $projectId, 'sid' => $studentId]);
$project = $stmt->fetch();

if (!$project || !in_array($project['type'], PROJECT_CANVAS_TYPES, true)) {
    http_response_code(404);
    exit('Trabajo no encontrado.');
}

$data = json_decode($project['data_json'], true) ?: project_default_data($project['type']);
$isSubmitted = $project['status'] === 'submitted';
$isMindMap = $project['type'] === 'mapa_mental';

$pageTitle = $project['title'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  .canvas-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
  .canvas-toolbar button { padding:8px 12px; border:1px solid var(--color-border); background:#fff; border-radius:6px; cursor:pointer; font-size:0.85rem; }
  .canvas-toolbar button:hover { background:var(--color-primary-light); }
  .canvas-toolbar button.active { background:var(--color-primary); color:#fff; }
  .canvas-toolbar input[type=color] { width:36px; height:34px; border:1px solid var(--color-border); border-radius:6px; padding:2px; cursor:pointer; }
  .canvas-toolbar .sep { width:1px; height:28px; background:var(--color-border); margin:0 4px; }
  .canvas-toolbar label.inline { font-weight:400; margin:0; font-size:0.8rem; color:var(--color-text-muted); }
  #canvas-holder { overflow:auto; border:1px solid var(--color-border); border-radius:8px; background:#EEF1F5; padding:20px; text-align:center; }
</style>

<p><a href="<?= e(rtrim(APP_URL, '/')) ?>/student/assignment.php?id=<?= (int) $project['assignment_id'] ?>">&larr; Volver a la asignación</a></p>

<div class="card">
    <label for="title_input">Título</label>
    <input type="text" id="title_input" value="<?= e($project['title']) ?>" <?= $isSubmitted ? 'disabled' : '' ?>>

    <?php if (!$isSubmitted): ?>
    <div class="canvas-toolbar" style="margin-top:16px;">
        <button type="button" id="btn-add-text">+ Texto</button>
        <button type="button" id="btn-add-rect">+ Rectángulo</button>
        <button type="button" id="btn-add-circle">+ Círculo</button>
        <button type="button" id="btn-add-line">+ Línea</button>
        <button type="button" id="btn-upload-image">+ Imagen</button>
        <input type="file" id="file-image" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none;">

        <?php if ($isMindMap): ?>
            <div class="sep"></div>
            <button type="button" id="btn-add-node">+ Nodo</button>
            <button type="button" id="btn-connect-nodes">Conectar nodos seleccionados</button>
        <?php endif; ?>

        <div class="sep"></div>
        <button type="button" id="btn-bold"><b>B</b></button>
        <button type="button" id="btn-italic"><i>I</i></button>
        <button type="button" id="btn-underline"><u>U</u></button>
        <label class="inline">Color <input type="color" id="fill-color" value="#1E4FA3"></label>
        <label class="inline">Fondo <input type="color" id="bg-color" value="<?= e($data['backgroundColor'] ?? '#ffffff') ?>"></label>

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

    <div id="canvas-holder" style="margin-top:16px;">
        <canvas id="project-canvas"></canvas>
    </div>

    <div style="margin-top:12px;">
        <button type="button" class="btn btn-secondary" id="btn-download-png" style="margin:0;">Descargar como PNG</button>
    </div>

    <?php if ($isSubmitted): ?>
        <p class="alert alert-success" style="margin-top:16px;">Este trabajo ya fue entregado y no se puede modificar.</p>
    <?php else: ?>
        <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; align-items:center;">
            <button class="btn btn-secondary" id="btn-save" style="margin:0;">Guardar borrador</button>
            <button class="btn" id="btn-submit" style="margin:0;">Entregar actividad</button>
            <span class="text-muted" id="save-status" style="font-size:0.85rem;"></span>
        </div>
        <p class="text-muted" style="font-size:0.8rem; margin-top:8px;">
            Una vez enviada, no podrás modificarla.
            <?php if ($isMindMap): ?>Para conectar dos nodos, selecciónalos con Shift+clic y presiona "Conectar nodos seleccionados".<?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
<script>
const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const PROJECT_ID = <?= (int) $project['id'] ?>;
const ASSIGNMENT_ID = <?= (int) ($project['assignment_id'] ?? 0) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const IS_SUBMITTED = <?= $isSubmitted ? 'true' : 'false' ?>;
const IS_MINDMAP = <?= $isMindMap ? 'true' : 'false' ?>;
const INITIAL_DATA = <?= json_encode($data) ?>;
const CUSTOM_PROPS = ['isMindMapNode', 'nodeId', 'isMindMapConnector', 'fromNodeId', 'toNodeId'];

const canvas = new fabric.Canvas('project-canvas', {
    selection: !IS_SUBMITTED,
});
canvas.setWidth(INITIAL_DATA.width || 800);
canvas.setHeight(INITIAL_DATA.height || 1200);
canvas.setBackgroundColor(INITIAL_DATA.backgroundColor || '#ffffff', canvas.renderAll.bind(canvas));

let isRestoring = true;
let undoStack = [];
let redoStack = [];

function pushHistory() {
    if (isRestoring) return;
    undoStack.push(JSON.stringify(canvas.toJSON(CUSTOM_PROPS)));
    if (undoStack.length > 60) undoStack.shift();
    redoStack = [];
}

canvas.loadFromJSON({ objects: INITIAL_DATA.objects || [] }, () => {
    canvas.renderAll();
    isRestoring = false;
    pushHistory();
});

if (IS_SUBMITTED) {
    canvas.forEachObject(o => { o.selectable = false; o.evented = false; });
}

document.getElementById('btn-download-png').addEventListener('click', () => {
    const dataUrl = canvas.toDataURL({ format: 'png', quality: 1 });
    const a = document.createElement('a');
    a.href = dataUrl;
    a.download = <?= json_encode(preg_replace('/[^a-zA-Z0-9_-]+/', '_', $project['title']) . '.png') ?>;
    a.click();
});

// ---- Historial (deshacer / rehacer) ----
function restoreState(jsonStr) {
    isRestoring = true;
    const parsed = JSON.parse(jsonStr);
    canvas.loadFromJSON(parsed, () => { canvas.renderAll(); isRestoring = false; });
}

function undo() {
    if (undoStack.length < 2) return;
    redoStack.push(undoStack.pop());
    restoreState(undoStack[undoStack.length - 1]);
}

function redo() {
    if (redoStack.length === 0) return;
    const next = redoStack.pop();
    undoStack.push(next);
    restoreState(next);
}

if (!IS_SUBMITTED) {
    canvas.on('object:added', pushHistory);
    canvas.on('object:modified', pushHistory);
    canvas.on('object:removed', pushHistory);

    document.getElementById('btn-undo').addEventListener('click', undo);
    document.getElementById('btn-redo').addEventListener('click', redo);

    // ---- Herramientas básicas ----
    document.getElementById('btn-add-text').addEventListener('click', () => {
        const text = new fabric.Textbox('Escribe aquí', { left: 60, top: 60, width: 200, fontSize: 20, fill: '#1F2937' });
        canvas.add(text).setActiveObject(text);
    });

    document.getElementById('btn-add-rect').addEventListener('click', () => {
        const rect = new fabric.Rect({ left: 80, top: 80, width: 140, height: 90, fill: '#E8F0FE', stroke: '#1E4FA3', strokeWidth: 2 });
        canvas.add(rect).setActiveObject(rect);
    });

    document.getElementById('btn-add-circle').addEventListener('click', () => {
        const circle = new fabric.Circle({ left: 100, top: 100, radius: 50, fill: '#E8F0FE', stroke: '#1E4FA3', strokeWidth: 2 });
        canvas.add(circle).setActiveObject(circle);
    });

    document.getElementById('btn-add-line').addEventListener('click', () => {
        const line = new fabric.Line([50, 50, 200, 50], { stroke: '#1F2937', strokeWidth: 3 });
        canvas.add(line).setActiveObject(line);
    });

    document.getElementById('btn-upload-image').addEventListener('click', () => {
        document.getElementById('file-image').click();
    });

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
                    img.scaleToWidth(200);
                    img.set({ left: 60, top: 60 });
                    canvas.add(img).setActiveObject(img);
                }, { crossOrigin: 'anonymous' });
            } else {
                alert(data.message || 'No se pudo subir la imagen.');
            }
        } catch (err) {
            alert('No se pudo subir la imagen. Revisa tu conexión.');
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
        obj.clone((clone) => {
            clone.set({ left: obj.left + 20, top: obj.top + 20 });
            if (obj.isMindMapNode) {
                clone.set({ nodeId: 'node-' + Date.now() + '-' + Math.floor(Math.random() * 1000) });
            }
            canvas.add(clone).setActiveObject(clone);
        }, CUSTOM_PROPS);
    });

    document.getElementById('btn-delete').addEventListener('click', () => {
        const objs = canvas.getActiveObjects();
        objs.forEach(o => canvas.remove(o));
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

    // ---- Herramientas de mapa mental ----
    if (IS_MINDMAP) {
        let nodeCounter = 0;

        function addNode(text, x, y, color) {
            const nodeId = 'node-' + Date.now() + '-' + (nodeCounter++);
            const rect = new fabric.Rect({
                width: 160, height: 60, rx: 14, ry: 14,
                fill: color || '#E8F0FE', stroke: '#1E4FA3', strokeWidth: 2,
                originX: 'center', originY: 'center',
            });
            const label = new fabric.Textbox(text, {
                width: 140, fontSize: 15, textAlign: 'center', fill: '#1F2937',
                originX: 'center', originY: 'center',
            });
            const group = new fabric.Group([rect, label], { left: x, top: y, originX: 'center', originY: 'center' });
            group.set({ isMindMapNode: true, nodeId });
            canvas.add(group).setActiveObject(group);
            return group;
        }

        document.getElementById('btn-add-node').addEventListener('click', () => {
            const hasNodes = canvas.getObjects().some(o => o.isMindMapNode);
            if (!hasNodes) {
                addNode('Idea central', canvas.width / 2, canvas.height / 2, '#1E4FA3');
                canvas.getActiveObject().set('fill', '#1E4FA3');
            } else {
                const offset = 40 + Math.random() * 120;
                addNode('Nueva idea', canvas.width / 2 + offset, canvas.height / 2 + offset);
            }
        });

        document.getElementById('btn-connect-nodes').addEventListener('click', () => {
            const sel = canvas.getActiveObject();
            if (!sel || sel.type !== 'activeSelection' || sel._objects.length !== 2) {
                alert('Selecciona exactamente 2 nodos (clic en uno, luego Shift+clic en el otro) para conectar.');
                return;
            }
            const [a, b] = sel._objects;
            if (!a.isMindMapNode || !b.isMindMapNode) {
                alert('Solo puedes conectar nodos (creados con el botón "+ Nodo").');
                return;
            }
            const pa = a.getCenterPoint();
            const pb = b.getCenterPoint();
            const line = new fabric.Line([pa.x, pa.y, pb.x, pb.y], { stroke: '#94A3B8', strokeWidth: 2, selectable: true });
            line.set({ isMindMapConnector: true, fromNodeId: a.nodeId, toNodeId: b.nodeId });
            canvas.add(line);
            canvas.sendToBack(line);
            canvas.discardActiveObject();
            canvas.renderAll();
        });

        // Mantener las líneas ancladas a los nodos cuando se mueven
        canvas.on('object:moving', (e) => {
            const obj = e.target;
            if (!obj.isMindMapNode) return;
            const center = obj.getCenterPoint();
            canvas.getObjects().forEach(o => {
                if (!o.isMindMapConnector) return;
                if (o.fromNodeId === obj.nodeId) o.set({ x1: center.x, y1: center.y });
                if (o.toNodeId === obj.nodeId) o.set({ x2: center.x, y2: center.y });
            });
            canvas.renderAll();
        });
    }

    // ---- Guardar / Entregar ----
    async function saveDraft(silent) {
        const statusEl = document.getElementById('save-status');
        if (!silent) statusEl.textContent = 'Guardando...';
        const json = canvas.toJSON(CUSTOM_PROPS);
        try {
            const res = await fetch(`${AULA_APP_URL}/api/projects/save.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: PROJECT_ID,
                    title: document.getElementById('title_input').value,
                    data: {
                        width: canvas.width,
                        height: canvas.height,
                        backgroundColor: canvas.backgroundColor,
                        objects: json.objects,
                    },
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
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
