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

async function sendAnswer(questionId, optionId) {
    if (submitting) return;
    submitting = true;
    try {
        await fetch(`${AULA_APP_URL}/api/games/answer.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: GAME_CODE, question_id: questionId, option_id: optionId, csrf_token: CSRF_TOKEN }),
        });
    } catch (e) {
        console.error(e);
    }
    submitting = false;
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
            const optionsHtml = q.options.map(o =>
                `<div class="play-option" data-id="${o.id}">${o.text}</div>`
            ).join('');
            const imageHtml = q.image_url ? `<img src="${q.image_url}" style="max-width:100%; max-height:220px; border-radius:8px; display:block; margin:10px auto;">` : '';
            panel.innerHTML = `
                <p class="text-muted" style="text-align:center;">Pregunta ${g.current_question_index + 1} de ${g.total_questions}</p>
                <h2 style="text-align:center;">${q.statement}</h2>
                ${imageHtml}
                <div class="play-timer">${q.time_remaining}s</div>
                <div class="play-options">${optionsHtml}</div>
            `;
            document.querySelectorAll('.play-option').forEach(el => {
                el.onclick = () => {
                    document.querySelectorAll('.play-option').forEach(o => o.setAttribute('disabled', 'true'));
                    el.classList.add('selected');
                    sendAnswer(q.id, parseInt(el.dataset.id, 10));
                };
            });
        }
    } else if (g.status === 'question_results') {
        const r = data.results;
        let feedback = '<p class="text-muted" style="text-align:center;">No alcanzaste a responder.</p>';
        if (r.my_result) {
            feedback = r.my_result.is_correct
                ? `<div class="alert alert-success" style="text-align:center;">¡Correcto! +${r.my_result.points_awarded} pts</div>`
                : `<div class="alert alert-error" style="text-align:center;">Incorrecto.</div>`;
        }
        panel.innerHTML = `
            <h2 style="text-align:center;">${r.statement}</h2>
            ${feedback}
            ${r.explanation ? `<p class="text-muted" style="text-align:center;">${r.explanation}</p>` : ''}
            <p class="text-muted" style="text-align:center;">Esperando la siguiente pregunta...</p>
        `;
    } else if (g.status === 'finished') {
        const mine = data.players.find(p => p.nickname && true);
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
