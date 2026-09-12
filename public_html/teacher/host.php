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
    <h1 style="margin-top:0;"><?= e($activity['title']) ?></h1>
    <p class="text-muted">Código de la partida — compártelo con tus estudiantes</p>
    <div style="font-size:3rem; font-weight:800; letter-spacing:6px; color:var(--color-primary-dark);" id="game-code"><?= e($game['code']) ?></div>
    <p class="text-muted" style="margin-top:8px;">
        Proyecta esta pantalla o abre la
        <a href="<?= e(rtrim(APP_URL, '/')) ?>/projector/index.php?code=<?= e($game['code']) ?>" target="_blank">pantalla de proyección</a>
        en otra pestaña.
    </p>
</div>

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

async function hostAction(action) {
    const res = await fetch(`${AULA_APP_URL}/api/games/host_action.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: GAME_CODE, action, csrf_token: CSRF_TOKEN }),
    });
    return res.json();
}

function renderPlayers(players) {
    if (players.length === 0) {
        return '<p class="empty-state">Esperando participantes...</p>';
    }
    return '<div class="grid grid-3">' + players.map(p =>
        `<div class="card" style="padding:10px;"><strong>${p.nickname}</strong><div class="text-muted" style="font-size:0.85rem;">${p.score} pts</div></div>`
    ).join('') + '</div>';
}

function render(data) {
    const panel = document.getElementById('host-panel');
    if (!data.success) {
        panel.innerHTML = `<div class="alert alert-error">${data.message}</div>`;
        return;
    }
    const g = data.game;

    if (g.status === 'waiting') {
        panel.innerHTML = `
            <h2 style="margin-top:0;">Esperando jugadores (${g.players_count})</h2>
            ${renderPlayers(data.players)}
            <button class="btn" id="btn-start">Iniciar partida</button>
        `;
        document.getElementById('btn-start').onclick = async () => { await hostAction('next'); poll(); };
    } else if (g.status === 'question') {
        const q = data.question;
        panel.innerHTML = `
            <h2 style="margin-top:0;">Pregunta ${g.current_question_index + 1} de ${g.total_questions}</h2>
            <p style="font-size:1.2rem;">${q.statement}</p>
            <p class="text-muted">Tiempo restante: ${q.time_remaining}s · Respondieron: ${q.answered_count} de ${g.players_count}</p>
            <button class="btn" id="btn-results">Ver resultados ahora</button>
        `;
        document.getElementById('btn-results').onclick = async () => { await hostAction('next'); poll(); };
    } else if (g.status === 'question_results') {
        const r = data.results;
        const optionsHtml = r.options.map(o =>
            `<div style="padding:6px 0;">${o.is_correct ? '✅' : '⬜'} ${o.text} — ${o.count} respuestas${o.is_correct ? ' <strong>(correcta)</strong>' : ''}</div>`
        ).join('');
        const isLast = g.current_question_index >= g.total_questions - 1;
        panel.innerHTML = `
            <h2 style="margin-top:0;">Resultados — Pregunta ${g.current_question_index + 1} de ${g.total_questions}</h2>
            <p style="font-size:1.1rem;">${r.statement}</p>
            ${optionsHtml}
            <h3>Ranking actual</h3>
            ${renderPlayers(data.players)}
            <button class="btn" id="btn-next">${isLast ? 'Finalizar partida' : 'Siguiente pregunta'}</button>
        `;
        document.getElementById('btn-next').onclick = async () => {
            await hostAction(isLast ? 'finish' : 'next');
            poll();
        };
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
    setTimeout(poll, 2000);
}
poll();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
