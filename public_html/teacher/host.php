<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$activityId = (int) ($_GET['activity_id'] ?? 0);

$actStmt = $pdo->prepare('SELECT * FROM activities WHERE id = :id AND teacher_id = :teacher_id LIMIT 1');
$actStmt->execute(['id' => $activityId, 'teacher_id' => $teacherId]);
$activity = $actStmt->fetch();

if (!$activity) {
    http_response_code(404);
    exit('Actividad no encontrada.');
}

if ($activity['status'] !== 'published') {
    exit('Debes publicar la actividad antes de iniciar una partida. <a href="activity_edit.php?id=' . (int) $activityId . '">Volver</a>');
}

// Buscar partida activa existente, o crear una nueva
$gStmt = $pdo->prepare(
    "SELECT * FROM games WHERE activity_id = :activity_id AND teacher_id = :teacher_id AND status != 'finished' ORDER BY id DESC LIMIT 1"
);
$gStmt->execute(['activity_id' => $activityId, 'teacher_id' => $teacherId]);
$game = $gStmt->fetch();

if (!$game) {
    $code = generate_game_code($pdo);
    $insert = $pdo->prepare(
        "INSERT INTO games (activity_id, teacher_id, code, status, current_question_index, created_at) VALUES (:activity_id, :teacher_id, :code, 'waiting', -1, :created_at)"
    );
    $insert->execute(['activity_id' => $activityId, 'teacher_id' => $teacherId, 'code' => $code, 'created_at' => now_datetime()]);
    $gameId = (int) $pdo->lastInsertId();
    $game = game_find_by_id($pdo, $gameId);
    audit_log($pdo, $teacherId, 'game_create', "Partida {$code} creada para actividad #{$activityId}");
}

$pageTitle = 'Partida en vivo';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="activity_edit.php?id=<?= (int) $activityId ?>">&larr; Volver a la actividad</a></p>

<div class="card" style="text-align:center;">
    <h1 style="margin-top:0;"><?= e(game_mode_icon($activity['game_mode'])) ?> <?= e($activity['title']) ?></h1>
    <p class="text-muted">Código de la partida — compártelo con tus estudiantes</p>
    <div style="font-size:3rem; font-weight:800; letter-spacing:6px; color:var(--color-primary-dark);" id="game-code"><?= e($game['code']) ?></div>
    <p class="text-muted" style="margin-top:8px;">
        Proyecta esta pantalla o abre la
        <a href="<?= e(rtrim(APP_URL, '/')) ?>/projector/index.php?code=<?= e($game['code']) ?>" target="_blank">pantalla de proyección</a>
        en otra pestaña.
    </p>
</div>

<script src="<?= e(rtrim(APP_URL, '/')) ?>/assets/js/wordgames.js"></script>
<section class="card" id="host-panel" style="margin-top:16px;">
    <p class="text-muted">Cargando estado de la partida...</p>
</section>

<script>
const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const GAME_CODE = <?= json_encode($game['code']) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

async function fetchState() {
    const res = await fetch(`${AULA_APP_URL}/api/games/state.php?code=${GAME_CODE}&role=teacher`);
    return res.json();
}

let busy = false; // true mientras se procesa un clic del profesor (bloquea polling y clics repetidos)

async function hostAction(action, expected) {
    const res = await fetch(`${AULA_APP_URL}/api/games/host_action.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            code: GAME_CODE, action, csrf_token: CSRF_TOKEN,
            expected_status: expected ? expected.status : undefined,
            expected_index: expected ? expected.index : undefined,
        }),
    });
    return res.json();
}

/**
 * Enlaza un botón a una acción del anfitrión. El botón se bloquea al primer clic,
 * envía el estado que el profesor está viendo (el servidor ignora la acción si la
 * partida ya cambió) y se ignoran los clics repetidos durante un instante.
 */
function bindAction(buttonId, action, g) {
    const btn = document.getElementById(buttonId);
    if (!btn) return;
    const expected = { status: g.status, index: g.current_question_index };
    btn.onclick = async () => {
        if (busy) return;
        busy = true;
        btn.disabled = true;
        const originalText = btn.textContent;
        btn.textContent = 'Un momento...';
        try {
            await hostAction(action, expected);
        } catch (e) {
            console.error(e);
        }
        // Pequeña pausa para absorber un segundo clic accidental antes de reactivar
        setTimeout(async () => {
            busy = false;
            lastSignature = null; // fuerza a redibujar con el estado nuevo
            await poll(true);
        }, 700);
    };
}

function renderPlayers(players) {
    if (players.length === 0) {
        return '<p class="empty-state">Esperando participantes...</p>';
    }
    return '<div class="grid grid-3">' + players.map(p =>
        `<div class="card" style="padding:10px;"><strong>${p.nickname}</strong><div class="text-muted" style="font-size:0.85rem;">${p.score} pts</div></div>`
    ).join('') + '</div>';
}

let lastSignature = null;

function render(data) {
    const panel = document.getElementById('host-panel');
    if (!data.success) {
        panel.innerHTML = `<div class="alert alert-error">${data.message}</div>`;
        lastSignature = null;
        return;
    }
    const g = data.game;

    // Firma estructural: solo se redibuja TODO el panel cuando cambia la fase o la pregunta.
    // Mientras tanto solo se actualizan los datos que cambian (tiempo, respuestas, jugadores).
    // Antes se reemplazaba el panel completo cada 2 segundos y el botón desaparecía a mitad
    // del clic, por eso a veces "no respondía".
    const signature = `${g.status}:${g.current_question_index}`;

    if (signature === lastSignature) {
        const live = document.getElementById('live-meta');
        if (live && g.status === 'question') {
            live.textContent = `Tiempo restante: ${data.question.time_remaining}s · ${data.question.type === 'palabra' || data.question.type === 'crucigrama' ? 'Terminaron' : 'Respondieron'}: ${data.question.answered_count} de ${g.players_count}`;
        }
        const box = document.getElementById('players-box');
        if (box) box.innerHTML = renderPlayers(data.players);
        const title = document.getElementById('waiting-title');
        if (title) title.textContent = `Esperando jugadores (${g.players_count})`;
        return;
    }
    lastSignature = signature;

    if (g.status === 'waiting') {
        panel.innerHTML = `
            <h2 style="margin-top:0;" id="waiting-title">Esperando jugadores (${g.players_count})</h2>
            <div id="players-box">${renderPlayers(data.players)}</div>
            <button class="btn" id="btn-start">Iniciar partida</button>
        `;
        bindAction('btn-start', 'next', g);
    } else if (g.status === 'question') {
        const q = data.question;
        let bodyHtml = '';
        if (q.type === 'ordenar') {
            bodyHtml = `<p class="text-muted">${q.items.map(i => i.text).join(' · ')}</p>`;
        } else if (q.type === 'relacionar') {
            bodyHtml = `<p class="text-muted">Izquierda: ${q.left_items.map(i => i.text).join(', ')}<br>Derecha (desordenada): ${q.right_items.join(', ')}</p>`;
        } else if (q.type === 'completar') {
            bodyHtml = `<p class="text-muted">El estudiante debe completar el espacio en blanco.</p>`;
        }
        if (q.type === 'palabra' || q.type === 'crucigrama') {
            const isCw = q.type === 'crucigrama';
            panel.innerHTML = `
                <h2 style="margin-top:0;">${isCw ? '🧩 Crucigrama en curso' : `🪢 Palabra ${g.current_question_index + 1} de ${g.total_questions}`}</h2>
                ${isCw ? '<p class="text-muted">Los estudiantes resuelven todo el tablero a la vez; se califica al enviar o al terminar el tiempo.</p>'
                       : `<p style="font-size:1.2rem;">💡 ${WG.esc(q.statement)}</p>`}
                <p class="text-muted" id="live-meta">Tiempo restante: ${q.time_remaining}s · Terminaron: ${q.answered_count} de ${g.players_count}</p>
                <button class="btn" id="btn-results">Ver resultados ahora</button>
            `;
            bindAction('btn-results', 'next', g);
            return;
        }
        panel.innerHTML = `
            <h2 style="margin-top:0;">Pregunta ${g.current_question_index + 1} de ${g.total_questions}</h2>
            <p style="font-size:1.2rem;">${q.statement}</p>
            ${q.image_url ? `<img src="${q.image_url}" style="max-width:100%; max-height:200px; border-radius:8px; display:block; margin:8px auto;">` : ''}
            ${bodyHtml}
            <p class="text-muted" id="live-meta">Tiempo restante: ${q.time_remaining}s · Respondieron: ${q.answered_count} de ${g.players_count}</p>
            <button class="btn" id="btn-results">Ver resultados ahora</button>
        `;
        bindAction('btn-results', 'next', g);
    } else if (g.status === 'question_results') {
        const r = data.results;
        let bodyHtml = '';
        if (r.type === 'multiple' || r.type === 'truefalse') {
            bodyHtml = r.options.map(o =>
                `<div style="padding:6px 0;">${o.is_correct ? '✅' : '⬜'} ${o.text} — ${o.count} respuestas${o.is_correct ? ' <strong>(correcta)</strong>' : ''}</div>`
            ).join('');
        } else if (r.type === 'ordenar') {
            bodyHtml = `<p><strong>Orden correcto:</strong> ${r.correct_order.join(' → ')}</p>`;
        } else if (r.type === 'relacionar') {
            bodyHtml = `<p><strong>Parejas correctas:</strong> ${r.correct_pairs.map(p => `${p.left} ↔ ${p.right}`).join(' · ')}</p>`;
        } else if (r.type === 'completar') {
            bodyHtml = `<p><strong>Respuesta correcta:</strong> ${r.correct_answer}</p>`;
        } else if (r.type === 'palabra') {
            bodyHtml = `<p><strong>Palabra:</strong> ${WG.esc(r.correct_answer.toUpperCase())}</p>
                <p class="text-muted">La resolvieron ${r.solved_count} de ${r.finished_count} que terminaron.</p>`;
        } else if (r.type === 'crucigrama') {
            bodyHtml = WG.crosswordStaticHtml(r.crossword, 'solution', false);
        }
        const isLast = g.current_question_index >= g.total_questions - 1;
        panel.innerHTML = `
            <h2 style="margin-top:0;">Resultados — Pregunta ${g.current_question_index + 1} de ${g.total_questions}</h2>
            <p style="font-size:1.1rem;">${r.type === 'crucigrama' ? '' : (r.type === 'palabra' ? '💡 ' + WG.esc(r.statement) : r.statement)}</p>
            ${bodyHtml}
            <h3>Ranking actual</h3>
            <div id="players-box">${renderPlayers(data.players)}</div>
            <button class="btn" id="btn-next">${isLast ? 'Finalizar partida' : 'Siguiente pregunta'}</button>
        `;
        bindAction('btn-next', isLast ? 'finish' : 'next', g);
    } else if (g.status === 'finished') {
        const podium = data.players.slice(0, 3);
        const medals = ['🥇', '🥈', '🥉'];
        panel.innerHTML = `
            <h2 style="margin-top:0;">Partida finalizada</h2>
            ${podium.map((p, i) => `<div style="font-size:1.2rem;">${medals[i] || ''} ${p.nickname} — ${p.score} pts</div>`).join('')}
            <h3 style="margin-top:20px;">Ranking completo</h3>
            ${renderPlayers(data.players)}
            <a class="btn" href="${AULA_APP_URL}/teacher/activity_edit.php?id=<?= (int) $activityId ?>">Volver a la actividad</a>
        `;
    }
}

let polling = true;
let pollTimer = null;
async function poll(immediate) {
    if (!polling) return;
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    if (!busy) {
        try {
            const data = await fetchState();
            if (!busy) render(data); // si el profesor hizo clic mientras llegaba la respuesta, se descarta
            if (data.success && data.game.status === 'finished') {
                polling = false;
                return;
            }
        } catch (e) {
            console.error(e);
        }
    }
    pollTimer = setTimeout(poll, 2000);
}
poll();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
