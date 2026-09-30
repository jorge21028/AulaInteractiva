<?php
/**
 * Ahorcado y Crucigrama INDIVIDUALES: el estudiante juega a su ritmo, sin cronómetro y sin que
 * el profesor inicie nada. El avance se guarda en el servidor; puede cerrar y continuar después.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/word_play_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);

$assignment = wga_load_assignment($pdo, $assignmentId, $studentId);
if (!$assignment) {
    http_response_code(404);
    exit('Actividad no encontrada o no te pertenece.');
}

$isHangman = $assignment['game_mode'] === 'ahorcado';
$apiUrl = rtrim(APP_URL, '/') . '/api/wordgames/' . ($isHangman ? 'hangman.php' : 'crossword.php');

$pageTitle = $assignment['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="assignment.php?id=<?= (int) $assignmentId ?>">&larr; Volver a la asignación</a></p>
<h1><?= e(game_mode_icon($assignment['game_mode'])) ?> <?= e($assignment['title']) ?></h1>
<p class="text-muted" style="margin-top:-8px;">
    <?= e(game_mode_label($assignment['game_mode'])) ?> · sin límite de tiempo · tu avance se guarda solo
    · <?= (int) $assignment['points'] ?> pts
    <?php if ($assignment['due_date']): ?>
        · Entrega: <?= e(date('d/m/Y', strtotime($assignment['due_date']))) ?>
    <?php endif; ?>
</p>

<section class="card" id="wg-panel" style="max-width:900px; margin:0 auto;">
    <p class="text-muted" style="text-align:center;">Cargando...</p>
</section>

<script src="<?= e(rtrim(APP_URL, '/')) ?>/assets/js/wordgames.js"></script>
<script>
const API_URL = <?= json_encode($apiUrl) ?>;
const ASSIGNMENT_ID = <?= (int) $assignmentId ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const IS_HANGMAN = <?= $isHangman ? 'true' : 'false' ?>;
const BACK_URL = <?= json_encode('assignment.php?id=' . (int) $assignmentId) ?>;
const panel = document.getElementById('wg-panel');

async function call(action, extra) {
    const res = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ assignment_id: ASSIGNMENT_ID, action, csrf_token: CSRF_TOKEN }, extra || {})),
    });
    return res.json();
}

function showError(msg) {
    panel.innerHTML = `<div class="alert alert-error">${WG.esc(msg)}</div>
        <p style="text-align:center;"><a class="btn btn-secondary" href="${BACK_URL}">Volver</a></p>`;
}

function fmtScore(n) { return (Math.round(n * 100) / 100).toString(); }

/* =====================================================================
   AHORCADO
   ===================================================================== */
let hm = { view: null, busy: false, banner: null, hangman: null };

function progressDots(view) {
    return '<div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap; margin:6px 0 14px;">' +
        view.words.map((w, i) => {
            const isCur = view.current && view.current.id === w.id && !hm.banner;
            const bg = w.status === 'solved' ? '#16A34A' : (w.status === 'lost' ? '#DC2626' : (isCur ? '#0878F9' : '#E3E9F2'));
            return `<span title="Palabra ${i + 1}" style="width:14px; height:14px; border-radius:50%; background:${bg}; ${isCur ? 'box-shadow:0 0 0 3px #BFDBFE;' : ''}"></span>`;
        }).join('') + '</div>';
}

function renderHangmanResult(view) {
    const r = view.result;
    const rows = view.words.map((w) => `
        <li style="padding:6px 0; border-bottom:1px solid var(--color-border, #E3E9F2);">
            ${w.status === 'solved' ? '✅' : '❌'} <strong>${WG.esc(w.answer || '')}</strong>
            <span class="text-muted"> — ${WG.esc(w.clue)}</span>
        </li>`).join('');
    panel.innerHTML = `
        <h2 style="text-align:center; margin-top:0;">🏁 ¡Terminaste!</h2>
        <div class="alert ${r.correct === r.total ? 'alert-success' : 'alert-error'}" style="text-align:center; font-size:1.1rem;">
            Adivinaste <strong>${r.correct} de ${r.total}</strong> palabras ·
            Calificación: <strong>${fmtScore(r.score)} / ${r.points}</strong>
        </div>
        <ul style="list-style:none; padding:0; margin:12px 0;">${rows}</ul>
        <div style="text-align:center;">
            ${view.allow_repeat && !view.overdue ? '<button class="btn" id="btn-restart">Jugar de nuevo (se guarda tu mejor nota)</button>' : ''}
            <a class="btn btn-secondary" href="${BACK_URL}">Volver a la asignación</a>
        </div>`;
    const rb = document.getElementById('btn-restart');
    if (rb) rb.onclick = async () => { rb.disabled = true; hm.banner = null; applyHangmanView(await call('restart')); };
}

function renderHangman() {
    const view = hm.view;
    if (view.status === 'completed' && !hm.banner) { renderHangmanResult(view); return; }

    const banner = hm.banner;
    const cur = view.current;
    const board = hm.hangman || (cur && cur.hangman);
    const clue = banner ? banner.clue : (cur ? cur.clue : '');
    const idx = banner ? banner.index : (cur ? cur.index : 0);

    let html = `
        <p class="text-muted" style="text-align:center; margin:0;">Palabra ${idx + 1} de ${view.total} · ✅ ${view.solved} acertada${view.solved === 1 ? '' : 's'}</p>
        ${progressDots(view)}
        <h2 style="text-align:center; margin-top:0;">💡 ${WG.esc(clue)}</h2>
        <div id="hangman-box"></div>`;

    if (banner) {
        html += `<div class="alert ${banner.solved ? 'alert-success' : 'alert-error'}" style="text-align:center; font-size:1.05rem;">
                ${banner.solved ? '🎉 ¡Correcto!' : '💀 Te quedaste sin vidas.'} La palabra era <strong>${WG.esc(banner.answer)}</strong>.
            </div>
            <div style="text-align:center;">
                <button class="btn" id="btn-next-word">${view.status === 'completed' ? 'Ver mi resultado' : 'Siguiente palabra →'}</button>
            </div>`;
    } else {
        html += '<p class="text-muted" style="text-align:center; font-size:0.85rem;">Toca las letras o usa tu teclado. Tienes 6 vidas por palabra.</p>';
    }
    panel.innerHTML = html;

    const box = document.getElementById('hangman-box');
    box.innerHTML = WG.hangmanBoardHtml(board, { disabled: !!banner || hm.busy });
    if (!banner) box.querySelectorAll('.wg-key').forEach(b => { b.onclick = () => guess(b.dataset.letter); });

    const nb = document.getElementById('btn-next-word');
    if (nb) nb.onclick = () => { hm.banner = null; hm.hangman = hm.view.current ? hm.view.current.hangman : null; renderHangman(); };
}

function applyHangmanView(view) {
    if (!view.success) { showError(view.message || 'Error'); return; }
    hm.view = view;
    hm.busy = false;
    if (view.status === 'in_progress' && !hm.banner) hm.hangman = view.current ? view.current.hangman : null;
    if (view.overdue && view.status === 'in_progress') {
        showError('La fecha de entrega ya venció.');
        return;
    }
    renderHangman();
}

async function guess(letter) {
    if (hm.busy || hm.banner || !hm.view || !hm.view.current) return;
    if (hm.hangman && hm.hangman.guessed.includes(letter)) return;
    hm.busy = true;
    const cur = hm.view.current;
    document.querySelectorAll('.wg-key').forEach(b => { b.disabled = true; });
    let data;
    try {
        data = await call('guess', { question_id: cur.id, letter });
    } catch (e) {
        hm.busy = false;
        renderHangman();
        return;
    }
    if (!data.success) { showError(data.message || 'No se pudo registrar la letra.'); return; }
    if (data.stale) { hm.banner = null; applyHangmanView(data); return; }

    if (data.finished_word) {
        // Se muestra el resultado de esta palabra antes de pasar a la siguiente.
        hm.banner = { clue: cur.clue, index: cur.index, solved: data.finished_word.solved, answer: data.finished_word.answer };
        hm.hangman = data.played.hangman;
        hm.view = data;
    } else {
        hm.view = data;
        hm.hangman = data.current ? data.current.hangman : hm.hangman;
    }
    hm.busy = false;
    renderHangman();
}

document.addEventListener('keydown', (e) => {
    if (!IS_HANGMAN || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
    const k = WG.normChar(e.key);
    if (k) guess(k);
});

/* =====================================================================
   CRUCIGRAMA
   ===================================================================== */
let cw = { ctl: null, saveTimer: null, dirty: false, data: null };

function renderCrosswordResult(data) {
    const r = data.result;
    const marks = data.layout.words.slice().sort((a, b) => a.number - b.number).map(w => {
        const ok = r.words[w.id + ':' + w.dir];
        return `<li style="padding:5px 0;">${ok ? '✅' : '❌'} <strong>${w.number}. ${w.dir === 'across' ? '➡️' : '⬇️'}</strong> ${WG.esc(w.clue)} <span class="text-muted">(${w.length})</span></li>`;
    }).join('');
    panel.innerHTML = `
        <h2 style="text-align:center; margin-top:0;">🏁 ¡Crucigrama entregado!</h2>
        <div class="alert ${r.correct === r.total ? 'alert-success' : 'alert-error'}" style="text-align:center; font-size:1.1rem;">
            Acertaste <strong>${r.correct} de ${r.total}</strong> palabras ·
            Calificación: <strong>${fmtScore(r.score)} / ${r.points}</strong>
        </div>
        ${data.solution ? '<h3>Respuestas</h3>' + WG.crosswordStaticHtml(data.solution, 'solution', false)
                        : '<p class="text-muted">Las respuestas no se muestran porque puedes repetir la actividad.</p>'}
        <h3>Revisión por palabra</h3>
        <ul style="list-style:none; padding:0; margin:0 0 12px;">${marks}</ul>
        <div style="text-align:center;">
            ${data.allow_repeat && !data.overdue ? '<button class="btn" id="btn-restart">Intentar de nuevo (se guarda tu mejor nota)</button>' : ''}
            <a class="btn btn-secondary" href="${BACK_URL}">Volver a la asignación</a>
        </div>`;
    const rb = document.getElementById('btn-restart');
    if (rb) rb.onclick = async () => { rb.disabled = true; applyCrossword(await call('restart')); };
}

async function flushSave() {
    if (!cw.dirty || !cw.ctl) return;
    cw.dirty = false;
    const st = document.getElementById('cw-save-status');
    try {
        const r = await call('save', { cells: cw.ctl.getCells() });
        if (st) st.textContent = r.success ? 'Avance guardado ✓' : (r.message || '');
        if (!r.success) cw.dirty = true;
    } catch (e) {
        cw.dirty = true;
        if (st) st.textContent = 'Sin conexión: se reintentará...';
        setTimeout(flushSave, 4000);
    }
}

function applyCrossword(data) {
    if (!data.success) { showError(data.message || 'Error'); return; }
    cw.data = data;
    if (data.status === 'completed') { renderCrosswordResult(data); return; }
    if (data.overdue) { showError('La fecha de entrega ya venció.'); return; }
    panel.innerHTML = `
        <p class="text-muted" style="text-align:center; margin-top:0;">
            Completa las <strong>${data.total}</strong> palabras. Toca una casilla o una pista para empezar; toca dos veces una casilla que cruza para cambiar de dirección.
        </p>
        <div id="cw-root"></div>
        <p class="text-muted" id="cw-save-status" style="text-align:center; font-size:0.85rem; min-height:1.2em;"></p>
        <button class="btn" id="btn-send-cw" style="width:100%;">Entregar crucigrama</button>`;

    cw.dirty = false;
    cw.ctl = WG.mountCrossword(document.getElementById('cw-root'), data.layout, data.cells || {}, () => {
        cw.dirty = true;
        const st = document.getElementById('cw-save-status');
        if (st) st.textContent = 'Escribiendo...';
        clearTimeout(cw.saveTimer);
        cw.saveTimer = setTimeout(flushSave, 1200);
    });

    document.getElementById('btn-send-cw').onclick = async () => {
        const cells = cw.ctl.getCells();
        const filled = Object.keys(cells).length;
        const msg = filled === 0
            ? 'Aún no has escrito nada. ¿Entregar de todas formas?'
            : '¿Entregar el crucigrama? Se calificará y no podrás cambiar tus respuestas' + (data.allow_repeat ? ' (pero podrás intentarlo de nuevo).' : '.');
        if (!confirm(msg)) return;
        const btn = document.getElementById('btn-send-cw');
        btn.disabled = true;
        cw.ctl.setDisabled(true);
        clearTimeout(cw.saveTimer);
        const res = await call('submit', { cells });
        if (!res.success) { btn.disabled = false; cw.ctl.setDisabled(false); alert(res.message || 'No se pudo entregar.'); return; }
        applyCrossword(res);
    };
}

document.addEventListener('visibilitychange', () => { if (document.hidden && !IS_HANGMAN) flushSave(); });
window.addEventListener('pagehide', () => { if (!IS_HANGMAN) flushSave(); });

/* ---------- Arranque ---------- */
(async function init() {
    try {
        if (IS_HANGMAN) applyHangmanView(await call('state'));
        else applyCrossword(await call('load'));
    } catch (e) {
        showError('No se pudo cargar la actividad. Revisa tu conexión e intenta de nuevo.');
    }
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
