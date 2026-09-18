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
</style>

<div id="play-panel" class="card"><p class="text-muted" style="text-align:center;">Cargando...</p></div>

<script>
const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const GAME_CODE = <?= json_encode($code) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
let submitting = false;

async function fetchState() {
    const res = await fetch(`${AULA_APP_URL}/api/games/state.php?code=${GAME_CODE}&role=student`);
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
    const imageHtml = q.image_url ? `<img src="${q.image_url}" style="max-width:100%; max-height:220px; border-radius:8px; display:block; margin:10px auto;">` : '';
    const header = `
        <p class="text-muted" style="text-align:center;">Pregunta ${g.current_question_index + 1} de ${g.total_questions}</p>
        <h2 style="text-align:center;">${q.statement}</h2>
        ${imageHtml}
        <div class="play-timer">${q.time_remaining}s</div>
    `;

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

function wireQuestionInteractions(q) {
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

function render(data) {
    const panel = document.getElementById('play-panel');
    if (!data.success) {
        panel.innerHTML = `<div class="alert alert-error">${data.message}</div>`;
        return;
    }
    const g = data.game;

    if (g.status === 'waiting') {
        panel.innerHTML = `
            <h2 style="text-align:center;">${g.activity_title}</h2>
            <p class="text-muted" style="text-align:center;">Esperando a que el profesor inicie la partida...</p>
            <p style="text-align:center;">👥 ${g.players_count} participante(s) conectados</p>
        `;
    } else if (g.status === 'question') {
        const q = data.question;
        if (q.already_answered) {
            panel.innerHTML = `
                <h2 style="text-align:center;">¡Respuesta enviada!</h2>
                <p class="text-muted" style="text-align:center;">Esperando al resto de tus compañeros...</p>
                <div class="play-timer">${q.time_remaining}s</div>
            `;
        } else {
            panel.innerHTML = renderQuestion(g, q);
            wireQuestionInteractions(q);
        }
    } else if (g.status === 'question_results') {
        const r = data.results;
        panel.innerHTML = `
            <h2 style="text-align:center;">${r.statement}</h2>
            ${renderResultsFeedback(r)}
            ${renderResultsDetail(r)}
            ${r.explanation ? `<p class="text-muted" style="text-align:center;">${r.explanation}</p>` : ''}
            <p class="text-muted" style="text-align:center;">Esperando la siguiente pregunta...</p>
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
async function poll() {
    if (!polling) return;
    try {
        const data = await fetchState();
        render(data);
        if (data.success && data.game.status === 'finished') {
            polling = false;
            return;
        }
    } catch (e) {
        console.error(e);
    }
    setTimeout(poll, 1500);
}
poll();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
