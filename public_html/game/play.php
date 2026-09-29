<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';

require_role('student');

$code = clean_string($_GET['code'] ?? '');
if ($code === '') {
    redirect('game/join.php');
}

$pdo = Database::getConnection();

// Unión automática por si llegó a este enlace directamente (ej: compartido por el profesor)
$result = game_join_student($pdo, $code, current_user_id(), (string) current_user_name());
if (!$result['success']) {
    $pageTitle = 'Juego no disponible';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="card"><div class="alert alert-error">' . e($result['message']) . '</div>';
    echo '<a class="btn" href="' . e(rtrim(APP_URL, '/')) . '/game/join.php">Probar con otro código</a></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = 'Jugando';
require __DIR__ . '/../includes/header.php';
?>
<style>
  .play-options { display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; margin-top:16px; }
  .play-option { background:#fff; border:2px solid var(--color-border); border-radius:12px; padding:20px; font-size:1.1rem; cursor:pointer; text-align:center; }
  .play-option:hover { border-color: var(--color-primary); background: var(--color-primary-light); }
  .play-option[disabled] { opacity:0.6; cursor:default; }
  .play-option.selected { border-color: var(--color-primary); background: var(--color-primary-light); font-weight:600; }
  .play-timer { font-size:1.8rem; font-weight:700; text-align:center; color:var(--color-primary); }
  .order-item { display:flex; align-items:center; gap:10px; background:#fff; border:2px solid var(--color-border); border-radius:10px; padding:12px 16px; margin-bottom:8px; }
  .order-item button { padding:6px 10px; border:1px solid var(--color-border); background:#fff; border-radius:6px; cursor:pointer; }
  .match-columns { display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-top:16px; }
  .match-item { background:#fff; border:2px solid var(--color-border); border-radius:10px; padding:14px; margin-bottom:8px; cursor:pointer; text-align:center; }
  .match-item.selected { border-color:var(--color-primary); background:var(--color-primary-light); }
  .match-item.paired { border-color:var(--color-success); background:#E7F6EE; opacity:0.85; }
  .completar-input { width:100%; padding:14px; font-size:1.1rem; border:2px solid var(--color-border); border-radius:10px; margin-top:16px; text-align:center; }

  /* --- Juego "El Sapito": arrastrar la respuesta al nenúfar correcto --- */
  .sapito-pond {
    position: relative;
    margin-top: 20px;
    min-height: 340px;
    background: linear-gradient(180deg, #BEE3DB 0%, #8ECFC2 100%);
    border-radius: 20px;
    border: 3px solid #4FA898;
    overflow: hidden;
    touch-action: none;
    display: flex;
    flex-wrap: wrap;
    align-content: flex-start;
    justify-content: center;
    gap: 20px;
    padding: 24px 20px 110px; /* el padding inferior deja espacio para el punto de partida de la rana */
  }
  .sapito-lilypad {
    width: 130px;
    min-height: 90px;
    background: radial-gradient(circle at 35% 30%, #7BC96F 0%, #4C9A4A 70%, #3E7F3D 100%);
    border-radius: 50%;
    border: 3px solid #2F6B30;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    font-weight: 600;
    font-size: 0.95rem;
    padding: 10px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.15);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }
  .sapito-lilypad.hover-target {
    transform: scale(1.08);
    box-shadow: 0 0 0 4px #FFD166;
  }
  .sapito-lilypad.correct-flash { background: radial-gradient(circle at 35% 30%, #8EE08A 0%, #3E9E40 70%, #2C7A2E 100%); box-shadow: 0 0 0 4px #2ECC71; }
  .sapito-lilypad.incorrect-flash { box-shadow: 0 0 0 4px #E74C3C; }
  .sapito-frog {
    position: absolute;
    width: 64px;
    height: 64px;
    font-size: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: grab;
    user-select: none;
    z-index: 5;
    filter: drop-shadow(0 3px 3px rgba(0,0,0,0.25));
    touch-action: none;
  }
  .sapito-frog.dragging { cursor: grabbing; z-index: 10; }
  .sapito-frog.locked { cursor: default; opacity: 0.6; }
  .sapito-hint { text-align:center; margin-top:10px; }
</style>

<div id="play-panel" class="card"><p class="text-muted" style="text-align:center;">Cargando...</p></div>

<script src="<?= e(rtrim(APP_URL, '/')) ?>/assets/js/wordgames.js"></script>
<script>
const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const GAME_CODE = <?= json_encode($code) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
let submitting = false;

async function fetchState(full) {
    const res = await fetch(`${AULA_APP_URL}/api/games/state.php?code=${GAME_CODE}&role=student${full ? '&full=1' : ''}`);
    return res.json();
}

async function sendAnswer(questionId, payload) {
    if (submitting) return;
    submitting = true;
    try {
        await fetch(`${AULA_APP_URL}/api/games/answer.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: GAME_CODE, question_id: questionId, csrf_token: CSRF_TOKEN, ...payload }),
        });
    } catch (e) {
        console.error(e);
    }
    submitting = false;
}

function renderQuestion(g, q) {
    if (q.type === 'palabra') {
        return `
            <p class="text-muted" style="text-align:center;">Palabra ${g.current_question_index + 1} de ${g.total_questions}</p>
            <h2 style="text-align:center;">💡 ${WG.esc(q.statement)}</h2>
            <div class="play-timer">${q.time_remaining}s</div>
            <div id="hangman-box"></div>
            <p class="text-muted" style="text-align:center; font-size:0.85rem;">Toca las letras (o usa tu teclado). Tienes ${q.hangman.max_lives} vidas.</p>
        `;
    }
    if (q.type === 'crucigrama') {
        return `
            <h2 style="text-align:center; margin-bottom:4px;">🧩 Crucigrama</h2>
            <p class="text-muted" style="text-align:center; margin-top:0;">Completa todas las palabras. Tu avance se guarda solo.</p>
            <div class="play-timer">${q.time_remaining}s</div>
            <div id="cw-root"><p class="text-muted" style="text-align:center;">Cargando crucigrama...</p></div>
            <p class="text-muted" id="cw-save-status" style="text-align:center; font-size:0.85rem; min-height:1.2em;"></p>
            <button class="btn" id="btn-send-cw" style="width:100%;">Enviar crucigrama</button>
        `;
    }

    const imageHtml = q.image_url ? `<img src="${q.image_url}" style="max-width:100%; max-height:220px; border-radius:8px; display:block; margin:10px auto;">` : '';
    const header = `
        <p class="text-muted" style="text-align:center;">Pregunta ${g.current_question_index + 1} de ${g.total_questions}</p>
        <h2 style="text-align:center;">${q.statement}</h2>
        ${imageHtml}
        <div class="play-timer">${q.time_remaining}s</div>
    `;

    if (g.game_mode === 'sapito' && q.type === 'multiple') {
        const pads = q.options.map(o => `<div class="sapito-lilypad" data-id="${o.id}">🌼 ${o.text}</div>`).join('');
        return header + `
            <p class="sapito-hint text-muted">Arrastra la rana 🐸 hasta el nenúfar con la respuesta correcta</p>
            <div class="sapito-pond" id="sapito-pond">
                ${pads}
                <div class="sapito-frog" id="sapito-frog">🐸</div>
            </div>
        `;
    }

    if (q.type === 'multiple' || q.type === 'truefalse') {
        const optionsHtml = q.options.map(o => `<div class="play-option" data-id="${o.id}">${o.text}</div>`).join('');
        return header + `<div class="play-options">${optionsHtml}</div>`;
    }

    if (q.type === 'ordenar') {
        return header + `
            <div id="order-list"></div>
            <button class="btn" id="btn-send-order" style="width:100%;">Enviar orden</button>
        `;
    }

    if (q.type === 'relacionar') {
        return header + `
            <div class="match-columns">
                <div id="match-left"></div>
                <div id="match-right"></div>
            </div>
            <button class="btn" id="btn-send-match" style="width:100%; margin-top:16px;">Enviar parejas</button>
        `;
    }

    if (q.type === 'completar') {
        return header + `
            <input type="text" id="completar-answer" class="completar-input" placeholder="Escribe tu respuesta" autocomplete="off">
            <button class="btn" id="btn-send-completar" style="width:100%; margin-top:12px;">Enviar respuesta</button>
        `;
    }

    return header;
}

function wireSapitoDrag(q) {
    const pond = document.getElementById('sapito-pond');
    const frog = document.getElementById('sapito-frog');
    if (!pond || !frog) return;

    let dragging = false;
    let answered = false;

    const startLeft = () => (pond.clientWidth / 2) - (frog.offsetWidth / 2);
    const startTop = () => pond.clientHeight - frog.offsetHeight - 10;
    frog.style.left = startLeft() + 'px';
    frog.style.top = startTop() + 'px';

    function findPadAt(clientX, clientY) {
        let found = null;
        document.querySelectorAll('.sapito-lilypad').forEach(pad => {
            const r = pad.getBoundingClientRect();
            if (clientX >= r.left && clientX <= r.right && clientY >= r.top && clientY <= r.bottom) {
                found = pad;
            }
        });
        return found;
    }

    function movePointer(e) {
        if (!dragging) return;
        const rect = pond.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        frog.style.left = (x - frog.offsetWidth / 2) + 'px';
        frog.style.top = (y - frog.offsetHeight / 2) + 'px';

        document.querySelectorAll('.sapito-lilypad').forEach(pad => pad.classList.remove('hover-target'));
        const pad = findPadAt(e.clientX, e.clientY);
        if (pad) pad.classList.add('hover-target');
    }

    frog.addEventListener('pointerdown', (e) => {
        if (answered) return;
        dragging = true;
        frog.classList.add('dragging');
        frog.setPointerCapture(e.pointerId);
        movePointer(e);
    });

    frog.addEventListener('pointermove', movePointer);

    function onRelease(e) {
        if (!dragging) return;
        dragging = false;
        frog.classList.remove('dragging');
        document.querySelectorAll('.sapito-lilypad').forEach(pad => pad.classList.remove('hover-target'));

        const pad = findPadAt(e.clientX, e.clientY);
        if (pad) {
            answered = true;
            frog.classList.add('locked');
            const rect = pond.getBoundingClientRect();
            const padRect = pad.getBoundingClientRect();
            frog.style.left = (padRect.left - rect.left + padRect.width / 2 - frog.offsetWidth / 2) + 'px';
            frog.style.top = (padRect.top - rect.top + padRect.height / 2 - frog.offsetHeight / 2) + 'px';
            pad.style.outline = '4px solid #FFD166';
            sendAnswer(q.id, { option_id: parseInt(pad.dataset.id, 10) });
        } else {
            frog.style.left = startLeft() + 'px';
            frog.style.top = startTop() + 'px';
        }
    }

    frog.addEventListener('pointerup', onRelease);
    frog.addEventListener('pointercancel', onRelease);
}

// ---------- AHORCADO ----------
let currentHangman = null;

function wireHangman(q) {
    const state = { qid: q.id, hm: q.hangman, busy: false };
    currentHangman = state;
    const box = document.getElementById('hangman-box');

    function draw() {
        box.innerHTML = WG.hangmanBoardHtml(state.hm, { disabled: state.busy });
        box.querySelectorAll('.wg-key').forEach(b => { b.onclick = () => state.guess(b.dataset.letter); });
    }

    state.guess = async function (letter) {
        if (state.busy || state.hm.guessed.includes(letter) || currentHangman !== state) return;
        state.busy = true;
        draw();
        try {
            const res = await fetch(`${AULA_APP_URL}/api/games/hangman_guess.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: GAME_CODE, question_id: state.qid, letter, csrf_token: CSRF_TOKEN }),
            });
            const data = await res.json();
            if (data.success && data.hangman) {
                state.hm = data.hangman;
            } else if (data.expired) {
                pollNow();
                return;
            }
        } catch (e) {
            console.error(e);
        }
        state.busy = false;
        draw();
        if (state.hm.done) pollNow(); // pasa enseguida a la pantalla de "palabra terminada"
    };

    draw();
}

document.addEventListener('keydown', (e) => {
    if (!currentHangman || currentHangman.busy || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key.length !== 1) return;
    const k = WG.normChar(e.key);
    if (k) currentHangman.guess(k);
});

// ---------- CRUCIGRAMA ----------
const crosswordCache = {};   // question_id -> { rows, cols, words, my_cells }
let crosswordCtl = null;
let crosswordSaveTimer = null;

async function crosswordSave(qid, cells, final) {
    const res = await fetch(`${AULA_APP_URL}/api/games/crossword_save.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        keepalive: !final,
        body: JSON.stringify({ code: GAME_CODE, question_id: qid, cells, final: !!final, csrf_token: CSRF_TOKEN }),
    });
    return res.json();
}

function wireCrossword(q) {
    const root = document.getElementById('cw-root');
    const data = crosswordCache[q.id];
    if (!root || !data) return;
    const statusEl = document.getElementById('cw-save-status');
    const sendBtn = document.getElementById('btn-send-cw');
    let dirty = false;

    async function flush() {
        if (!dirty || !crosswordCtl) return;
        dirty = false;
        try {
            const r = await crosswordSave(q.id, crosswordCtl.getCells(), false);
            if (statusEl) statusEl.textContent = r.success ? 'Avance guardado ✓' : '';
            if (r.expired) pollNow();
        } catch (e) {
            dirty = true;
            if (statusEl) statusEl.textContent = 'Sin conexión: se reintentará...';
        }
    }

    crosswordCtl = WG.mountCrossword(root, data, data.my_cells || {}, () => {
        dirty = true;
        if (statusEl) statusEl.textContent = 'Escribiendo...';
        clearTimeout(crosswordSaveTimer);
        crosswordSaveTimer = setTimeout(flush, 1200);
    });

    document.addEventListener('visibilitychange', () => { if (document.hidden) flush(); });

    sendBtn.onclick = async () => {
        const cells = crosswordCtl.getCells();
        const total = data.words.reduce((n, w) => n + w.length, 0); // referencia: casillas (con cruces cuentan doble)
        if (Object.keys(cells).length === 0 && !confirm('Aún no has escrito nada. ¿Enviar de todas formas?')) return;
        sendBtn.disabled = true;
        crosswordCtl.setDisabled(true);
        clearTimeout(crosswordSaveTimer);
        try {
            await crosswordSave(q.id, cells, true);
        } catch (e) {
            console.error(e);
        }
        pollNow();
    };
}

function wireQuestionInteractions(q, g) {
    if (q.type === 'palabra') { wireHangman(q); return; }
    if (q.type === 'crucigrama') { wireCrossword(q); return; }
    if (g && g.game_mode === 'sapito' && q.type === 'multiple') {
        wireSapitoDrag(q);
        return;
    }
    if (q.type === 'multiple' || q.type === 'truefalse') {
        document.querySelectorAll('.play-option').forEach(el => {
            el.onclick = () => {
                document.querySelectorAll('.play-option').forEach(o => o.setAttribute('disabled', 'true'));
                el.classList.add('selected');
                sendAnswer(q.id, { option_id: parseInt(el.dataset.id, 10) });
            };
        });
    } else if (q.type === 'ordenar') {
        let order = q.items.map(i => ({ id: i.id, text: i.text }));
        function renderOrder() {
            const list = document.getElementById('order-list');
            list.innerHTML = order.map((item, i) => `
                <div class="order-item">
                    <span style="flex:1;">${i + 1}. ${item.text}</span>
                    <button type="button" data-i="${i}" data-dir="up" ${i === 0 ? 'disabled' : ''}>↑</button>
                    <button type="button" data-i="${i}" data-dir="down" ${i === order.length - 1 ? 'disabled' : ''}>↓</button>
                </div>
            `).join('');
            list.querySelectorAll('button').forEach(btn => {
                btn.onclick = () => {
                    const i = parseInt(btn.dataset.i, 10);
                    const j = btn.dataset.dir === 'up' ? i - 1 : i + 1;
                    [order[i], order[j]] = [order[j], order[i]];
                    renderOrder();
                };
            });
        }
        renderOrder();
        document.getElementById('btn-send-order').onclick = () => {
            document.getElementById('btn-send-order').setAttribute('disabled', 'true');
            sendAnswer(q.id, { answer_data: order.map(o => o.id) });
        };
    } else if (q.type === 'relacionar') {
        let selectedLeft = null;
        const pairs = {}; // option_id -> right_index
        function renderMatch() {
            document.getElementById('match-left').innerHTML = q.left_items.map(item => `
                <div class="match-item ${pairs[item.id] !== undefined ? 'paired' : ''} ${selectedLeft === item.id ? 'selected' : ''}" data-id="${item.id}">${item.text}</div>
            `).join('');
            document.getElementById('match-right').innerHTML = q.right_items.map((text, i) => {
                const isUsed = Object.values(pairs).includes(i);
                return `<div class="match-item ${isUsed ? 'paired' : ''}" data-i="${i}">${text}</div>`;
            }).join('');

            document.querySelectorAll('#match-left .match-item').forEach(el => {
                el.onclick = () => { selectedLeft = parseInt(el.dataset.id, 10); renderMatch(); };
            });
            document.querySelectorAll('#match-right .match-item').forEach(el => {
                el.onclick = () => {
                    if (selectedLeft === null) return;
                    pairs[selectedLeft] = parseInt(el.dataset.i, 10);
                    selectedLeft = null;
                    renderMatch();
                };
            });
        }
        renderMatch();
        document.getElementById('btn-send-match').onclick = () => {
            if (Object.keys(pairs).length < q.left_items.length) {
                if (!confirm('No has emparejado todos los elementos. ¿Enviar de todas formas?')) return;
            }
            document.getElementById('btn-send-match').setAttribute('disabled', 'true');
            const answerData = Object.entries(pairs).map(([optionId, rightIndex]) => ({ option_id: parseInt(optionId, 10), right_index: rightIndex }));
            sendAnswer(q.id, { answer_data: answerData });
        };
    } else if (q.type === 'completar') {
        document.getElementById('btn-send-completar').onclick = () => {
            const val = document.getElementById('completar-answer').value.trim();
            if (val === '') { alert('Escribe una respuesta.'); return; }
            document.getElementById('btn-send-completar').setAttribute('disabled', 'true');
            sendAnswer(q.id, { answer_data: val });
        };
    }
}

function renderResultsFeedback(r) {
    if (r.type === 'crucigrama') {
        if (!r.my_result) return '<p class="text-muted" style="text-align:center;">No alcanzaste a participar.</p>';
        const m = r.my_result;
        const cls = m.correct_words === m.total_words ? 'alert-success' : 'alert-error';
        return `<div class="alert ${cls}" style="text-align:center;">Acertaste ${m.correct_words} de ${m.total_words} palabras · +${m.points_awarded} pts</div>`;
    }
    if (r.type === 'palabra') {
        if (!r.my_result) return '<p class="text-muted" style="text-align:center;">No alcanzaste a terminar la palabra.</p>';
        return r.my_result.is_correct
            ? `<div class="alert alert-success" style="text-align:center;">🎉 ¡La descubriste! +${r.my_result.points_awarded} pts</div>`
            : '<div class="alert alert-error" style="text-align:center;">Te quedaste sin vidas 💀</div>';
    }
    if (!r.my_result) {
        return '<p class="text-muted" style="text-align:center;">No alcanzaste a responder.</p>';
    }
    if (r.my_result.is_correct) {
        return `<div class="alert alert-success" style="text-align:center;">¡Correcto! +${r.my_result.points_awarded} pts</div>`;
    }
    const partial = r.my_result.points_awarded > 0
        ? ` Obtuviste ${r.my_result.points_awarded} pts por aciertos parciales.`
        : '';
    return `<div class="alert alert-error" style="text-align:center;">No del todo correcto.${partial}</div>`;
}

function renderResultsDetail(r) {
    if (r.type === 'palabra') {
        return `<p style="text-align:center; font-size:1.4rem; font-weight:800; letter-spacing:3px;">${WG.esc(r.correct_answer.toUpperCase())}</p>
                <p class="text-muted" style="text-align:center;">La resolvieron ${r.solved_count} de ${r.finished_count} que terminaron.</p>`;
    }
    if (r.type === 'crucigrama') {
        return WG.crosswordStaticHtml(r.crossword, 'solution', false);
    }
    if (r.type === 'ordenar') {
        return `<p class="text-muted" style="text-align:center;">Orden correcto: ${r.correct_order.join(' → ')}</p>`;
    }
    if (r.type === 'relacionar') {
        return `<p class="text-muted" style="text-align:center;">${r.correct_pairs.map(p => `${p.left} ↔ ${p.right}`).join(' · ')}</p>`;
    }
    if (r.type === 'completar') {
        return `<p class="text-muted" style="text-align:center;">Respuesta correcta: <strong>${r.correct_answer}</strong></p>`;
    }
    return '';
}

let lastSig = null;

function render(data) {
    const panel = document.getElementById('play-panel');
    if (!data.success) {
        lastSig = null;
        currentHangman = null;
        panel.innerHTML = `<div class="alert alert-error">${data.message}</div>`;
        return;
    }
    const g = data.game;

    // Solo se reconstruye la pantalla cuando cambia algo estructural (fase, pregunta, si ya respondió).
    // Antes se reemplazaba TODO cada 1.5 s y se perdía lo que el estudiante estaba escribiendo/arrastrando.
    let sig;
    if (g.status === 'question') {
        sig = `q:${g.current_question_index}:${data.question.already_answered ? 1 : 0}`;
    } else if (g.status === 'waiting') {
        sig = `w:${g.players_count}`;
    } else {
        sig = `${g.status}:${g.current_question_index}`;
    }

    if (sig === lastSig) {
        if (g.status === 'question') {
            document.querySelectorAll('.play-timer').forEach(el => { el.textContent = data.question.time_remaining + 's'; });
        }
        return;
    }
    lastSig = sig;
    currentHangman = null;
    crosswordCtl = null;

    if (g.status === 'waiting') {
        panel.innerHTML = `
            <h2 style="text-align:center;">${g.activity_title}</h2>
            <p class="text-muted" style="text-align:center;">Esperando a que el profesor inicie la partida...</p>
            <p style="text-align:center;">👥 ${g.players_count} participante(s) conectados</p>
        `;
    } else if (g.status === 'question') {
        const q = data.question;
        if (q.already_answered) {
            if (q.type === 'palabra') {
                panel.innerHTML = `
                    <h2 style="text-align:center;">${q.hangman.solved ? '🎉 ¡Completaste la palabra!' : '💀 Te quedaste sin vidas'}</h2>
                    ${WG.hangmanBoardHtml(q.hangman, { keyboard: false })}
                    <p class="text-muted" style="text-align:center;">Esperando al resto de tus compañeros...</p>
                    <div class="play-timer">${q.time_remaining}s</div>
                `;
            } else {
                const title = q.type === 'crucigrama' ? '🧩 ¡Crucigrama enviado!'
                    : (g.game_mode === 'sapito' ? '¡La rana saltó! 🐸' : '¡Respuesta enviada!');
                panel.innerHTML = `
                    <h2 style="text-align:center;">${title}</h2>
                    <p class="text-muted" style="text-align:center;">Esperando al resto de tus compañeros...</p>
                    <div class="play-timer">${q.time_remaining}s</div>
                `;
            }
        } else {
            panel.innerHTML = renderQuestion(g, q);
            wireQuestionInteractions(q, g);
        }
    } else if (g.status === 'question_results') {
        const r = data.results;
        panel.innerHTML = `
            <h2 style="text-align:center;">${r.type === 'crucigrama' ? '🧩 Crucigrama — resultados' : (r.type === 'palabra' ? '💡 ' + WG.esc(r.statement) : r.statement)}</h2>
            ${renderResultsFeedback(r)}
            ${renderResultsDetail(r)}
            ${r.explanation ? `<p class="text-muted" style="text-align:center;">${r.explanation}</p>` : ''}
            <p class="text-muted" style="text-align:center;">Esperando ${r.type === 'crucigrama' ? 'al profesor...' : 'la siguiente pregunta...'}</p>
        `;
    } else if (g.status === 'finished') {
        panel.innerHTML = `
            <h2 style="text-align:center;">🏁 Partida finalizada</h2>
            <h3 style="text-align:center;">Ranking</h3>
            <div class="grid grid-3">
                ${data.players.slice(0, 9).map((p, i) => `<div class="card" style="padding:12px; text-align:center;"><strong>${i + 1}. ${p.nickname}</strong><div class="text-muted">${p.score} pts</div></div>`).join('')}
            </div>
            <div style="text-align:center; margin-top:20px;">
                <a class="btn" href="${AULA_APP_URL}/student/dashboard.php">Volver a mi panel</a>
            </div>
        `;
    }
}

let polling = true;
let pollTimer = null;

async function poll() {
    if (!polling) return;
    pollTimer = null;
    try {
        const data = await fetchState();

        // El tablero del crucigrama solo se descarga una vez por pregunta (no cambia mientras se juega).
        if (data.success && data.game.status === 'question' && data.question.type === 'crucigrama'
            && !data.question.already_answered && !crosswordCache[data.question.id]) {
            const full = await fetchState(true);
            if (full.success && full.question && full.question.crossword) {
                crosswordCache[data.question.id] = full.question.crossword;
            }
        }

        render(data);
        if (data.success && data.game.status === 'finished') {
            polling = false;
            return;
        }
    } catch (e) {
        console.error(e);
    }
    pollTimer = setTimeout(poll, 1500);
}

function pollNow() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    poll();
}
poll();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
