<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
// Pantalla de proyección: no exige haber iniciado sesión (se muestra
// públicamente en el salón); auth.php solo se incluye para tener
// disponibles las funciones de escape/seguridad y el header compartido.

$code = clean_string($_GET['code'] ?? '');
$pageTitle = 'Proyección de partida';
require __DIR__ . '/../includes/header.php';
?>
<style>
  .projector-code { font-size: 4rem; font-weight: 800; letter-spacing: 10px; color: var(--color-primary-dark); text-align:center; }
  .projector-question { font-size: 1.8rem; text-align:center; margin: 20px 0; }
  .projector-options { display:grid; grid-template-columns: repeat(2, 1fr); gap:16px; }
  .projector-option { background:#fff; border:2px solid var(--color-border); border-radius:12px; padding:20px; font-size:1.3rem; text-align:center; }
  .projector-timer { font-size:2rem; text-align:center; font-weight:700; color:var(--color-primary); }
</style>

<?php if ($code === ''): ?>
    <div class="card form-narrow" style="text-align:center;">
        <h2>Pantalla de proyección</h2>
        <form onsubmit="location.href = location.pathname + '?code=' + document.getElementById('code').value; return false;">
            <label for="code">Código de la partida</label>
            <input type="text" id="code" required>
            <button type="submit" class="btn">Mostrar</button>
        </form>
    </div>
<?php else: ?>
    <div id="projector-panel"><p class="text-muted" style="text-align:center;">Cargando...</p></div>

    <script>
    const AULA_APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
    const GAME_CODE = <?= json_encode($code) ?>;

    async function fetchState() {
        const res = await fetch(`${AULA_APP_URL}/api/games/state.php?code=${GAME_CODE}&role=projector`);
        return res.json();
    }

    function render(data) {
        const panel = document.getElementById('projector-panel');
        if (!data.success) {
            panel.innerHTML = `<div class="alert alert-error" style="text-align:center;">${data.message}</div>`;
            return;
        }
        const g = data.game;

        if (g.status === 'waiting') {
            panel.innerHTML = `
                <div class="card">
                    <p style="text-align:center;" class="text-muted">${g.activity_title}</p>
                    <div class="projector-code">${GAME_CODE}</div>
                    <p style="text-align:center;" class="text-muted">Entra con este código para participar</p>
                    <h2 style="text-align:center;">${g.players_count} participante(s) conectados</h2>
                </div>
            `;
        } else if (g.status === 'question') {
            const q = data.question;
            const imageHtml = q.image_url ? `<img src="${q.image_url}" style="max-width:100%; max-height:280px; border-radius:8px; display:block; margin:10px auto;">` : '';
            let bodyHtml = '';
            if (q.type === 'multiple' || q.type === 'truefalse') {
                bodyHtml = `<div class="projector-options" style="margin-top:20px;">${q.options.map(o => `<div class="projector-option">${o.text}</div>`).join('')}</div>`;
            } else if (q.type === 'ordenar') {
                bodyHtml = `<div class="projector-options" style="margin-top:20px;">${q.items.map(i => `<div class="projector-option">${i.text}</div>`).join('')}</div>
                    <p class="text-muted" style="text-align:center; margin-top:10px;">Ordénalos en el orden correcto</p>`;
            } else if (q.type === 'relacionar') {
                bodyHtml = `<div class="projector-options" style="margin-top:20px;">
                    ${q.left_items.map(i => `<div class="projector-option">${i.text}</div>`).join('')}
                    ${q.right_items.map(t => `<div class="projector-option" style="background:#F3F4F6;">${t}</div>`).join('')}
                </div>`;
            } else if (q.type === 'completar') {
                bodyHtml = `<p class="text-muted" style="text-align:center; margin-top:10px;">Completa el espacio en blanco</p>`;
            }
            panel.innerHTML = `
                <div class="card">
                    <p class="text-muted" style="text-align:center;">Pregunta ${g.current_question_index + 1} de ${g.total_questions}</p>
                    <div class="projector-question">${q.statement}</div>
                    ${imageHtml}
                    <div class="projector-timer">${q.time_remaining}s</div>
                    ${bodyHtml}
                    <p style="text-align:center; margin-top:16px;" class="text-muted">Respondieron ${q.answered_count} de ${g.players_count}</p>
                </div>
            `;
        } else if (g.status === 'question_results') {
            const r = data.results;
            let bodyHtml = '';
            if (r.type === 'multiple' || r.type === 'truefalse') {
                bodyHtml = `<div class="projector-options">${r.options.map(o =>
                    `<div class="projector-option" style="${o.is_correct ? 'border-color:var(--color-success); background:#E7F6EE;' : ''}">${o.text}<br><span class="text-muted" style="font-size:1rem;">${o.count} respuestas</span></div>`
                ).join('')}</div>`;
            } else if (r.type === 'ordenar') {
                bodyHtml = `<p style="text-align:center; font-size:1.3rem;">${r.correct_order.join(' → ')}</p>`;
            } else if (r.type === 'relacionar') {
                bodyHtml = `<div class="projector-options">${r.correct_pairs.map(p => `<div class="projector-option">${p.left} ↔ ${p.right}</div>`).join('')}</div>`;
            } else if (r.type === 'completar') {
                bodyHtml = `<p style="text-align:center; font-size:1.5rem; font-weight:700; color:var(--color-success);">${r.correct_answer}</p>`;
            }
            panel.innerHTML = `
                <div class="card">
                    <p class="text-muted" style="text-align:center;">Resultados — Pregunta ${g.current_question_index + 1} de ${g.total_questions}</p>
                    <div class="projector-question">${r.statement}</div>
                    ${bodyHtml}
                    <h3 style="text-align:center; margin-top:24px;">Ranking</h3>
                    ${renderRanking(data.players)}
                </div>
            `;
        } else if (g.status === 'finished') {
            panel.innerHTML = `
                <div class="card">
                    <h2 style="text-align:center;">🏆 Partida finalizada 🏆</h2>
                    ${renderPodium(data.players)}
                    <h3 style="text-align:center; margin-top:24px;">Ranking completo</h3>
                    ${renderRanking(data.players)}
                </div>
            `;
        }
    }

    function renderRanking(players) {
        if (players.length === 0) return '<p class="empty-state">Sin participantes.</p>';
        return '<div class="grid grid-3">' + players.slice(0, 9).map(p =>
            `<div class="card" style="padding:12px; text-align:center;"><strong>${p.nickname}</strong><div class="text-muted">${p.score} pts</div></div>`
        ).join('') + '</div>';
    }

    function renderPodium(players) {
        const medals = ['🥇', '🥈', '🥉'];
        return '<div style="text-align:center; font-size:1.5rem;">' + players.slice(0, 3).map((p, i) =>
            `<div>${medals[i] || ''} ${p.nickname} — ${p.score} pts</div>`
        ).join('') + '</div>';
    }

    async function poll() {
        try {
            const data = await fetchState();
            render(data);
        } catch (e) {
            console.error(e);
        }
        setTimeout(poll, 1500);
    }
    poll();
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
