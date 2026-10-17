/**
 * wg_matching.js — EMPAREJAR CONCEPTOS individual (estudiante).
 * Uso: WGMatching.start({ panel, call, backUrl, showError, fmtScore })
 * Toca un concepto (izquierda) y luego su pareja (derecha). Cuenta cada pareja que aciertes a la primera.
 */
(function () {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    function start(ctx) {
        const { panel, call, backUrl, showError, fmtScore } = ctx;
        let view = null, selLeft = null, selRight = null, busy = false, flash = null;

        const matchedLeft = () => new Set(view.matched.map(m => m.left_id));
        const matchedRight = () => new Set(view.matched.map(m => m.right_token));

        function card(kind, id, text, state) {
            const base = 'display:block; width:100%; text-align:left; padding:10px 12px; margin-bottom:8px; border-radius:10px; font-size:0.95rem; cursor:pointer; border:2px solid #E3E9F2; background:#fff; color:inherit; font-family:inherit; line-height:1.3;';
            const styles = {
                idle: '',
                selected: 'border-color:#0878F9; background:#EFF6FF;',
                done: 'border-color:#16A34A; background:#DCFCE7; cursor:default; opacity:.85;',
                wrong: 'border-color:#DC2626; background:#FEE2E2;',
            };
            return '<button type="button" class="mt-card" data-kind="' + kind + '" data-id="' + esc(id) + '" ' + (state === 'done' ? 'disabled' : '') + ' style="' + base + styles[state] + '">' + esc(text) + '</button>';
        }

        function render() {
            if (view.status === 'completed') return renderResult();
            if (view.overdue) return showError('La fecha de entrega ya venció.');
            const dl = matchedLeft(), dr = matchedRight();
            const left = view.left.map(c => card('L', c.id, c.text, dl.has(c.id) ? 'done' : (flash && flash.left === c.id ? 'wrong' : (selLeft === c.id ? 'selected' : 'idle')))).join('');
            const right = view.right.map(c => card('R', c.token, c.text, dr.has(c.token) ? 'done' : (flash && flash.right === c.token ? 'wrong' : (selRight === c.token ? 'selected' : 'idle')))).join('');
            panel.innerHTML =
                '<p class="text-muted" style="text-align:center; margin-top:0;">Une cada <strong>concepto</strong> (izquierda) con su <strong>pareja</strong> (derecha). Toca uno de cada lado. ' +
                'Cada pareja cuenta si la aciertas <strong>a la primera</strong>.</p>' +
                '<p style="text-align:center; margin:0 0 8px;"><strong>' + view.matched_count + ' de ' + view.total + '</strong> parejas unidas</p>' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;"><div>' + left + '</div><div>' + right + '</div></div>' +
                '<button class="btn" id="mt-submit" style="width:100%; margin-top:8px;">Entregar</button>';
            panel.querySelectorAll('.mt-card:not([disabled])').forEach(b => b.onclick = () => pick(b.dataset.kind, b.dataset.id));
            document.getElementById('mt-submit').onclick = submit;
        }

        async function pick(kind, id) {
            if (busy) return;
            if (kind === 'L') { selLeft = (selLeft === parseInt(id, 10)) ? null : parseInt(id, 10); }
            else { selRight = (selRight === id) ? null : id; }
            if (selLeft !== null && selRight !== null) {
                busy = true;
                const l = selLeft, r = selRight;
                let data;
                try { data = await call('match', { left_id: l, right_token: r }); }
                catch (e) { busy = false; selLeft = selRight = null; render(); return; }
                busy = false;
                if (!data.success) { selLeft = selRight = null; if (data.overdue) return showError(data.message); alert(data.message || 'No se pudo comprobar.'); render(); return; }
                view = data;
                if (data.correct === false) {
                    flash = { left: l, right: r }; selLeft = selRight = null; render();
                    setTimeout(() => { flash = null; if (view.status !== 'completed') render(); }, 700);
                    return;
                }
                selLeft = selRight = null; render();
                return;
            }
            render();
        }

        async function submit() {
            const left = view.total - view.matched_count;
            const msg = left > 0 ? 'Aún te faltan ' + left + ' pareja(s). ¿Entregar de todas formas? Las que no unas cuentan como incorrectas.' : '¿Entregar?';
            if (!confirm(msg)) return;
            const b = document.getElementById('mt-submit'); b.disabled = true;
            const data = await call('submit');
            if (!data.success) { b.disabled = false; alert(data.message || 'No se pudo entregar.'); return; }
            view = data; render();
        }

        function renderResult() {
            const r = view.result;
            const rows = view.review.map(x => '<li style="padding:6px 0; border-bottom:1px solid var(--color-border, #E3E9F2);">' +
                (x.first_try ? '✅' : (x.solved ? '⚠️' : '❌')) + ' <strong>' + esc(x.concept) + '</strong>' + (x.pair ? ' <span class="text-muted">— ' + esc(x.pair) + '</span>' : '') +
                (x.first_try ? '' : (x.solved ? ' <span class="text-muted" style="font-size:0.8rem;">(la uniste, pero no a la primera)</span>' : ' <span class="text-muted" style="font-size:0.8rem;">(sin unir)</span>')) + '</li>').join('');
            panel.innerHTML =
                '<h2 style="text-align:center; margin-top:0;">🏁 ¡Terminaste!</h2>' +
                '<div class="alert ' + (r.correct === r.total ? 'alert-success' : 'alert-error') + '" style="text-align:center; font-size:1.1rem;">Acertaste <strong>' + r.correct + ' de ' + r.total + '</strong> parejas a la primera · Calificación: <strong>' + fmtScore(r.score) + ' / ' + r.points + '</strong></div>' +
                '<ul style="list-style:none; padding:0; margin:12px 0;">' + rows + '</ul>' +
                '<div style="text-align:center;">' +
                (view.allow_repeat && !view.overdue ? '<button class="btn" id="mt-restart">Jugar de nuevo (se guarda tu mejor nota)</button> ' : '') +
                '<a class="btn btn-secondary" href="' + backUrl + '">Volver a la asignación</a></div>';
            const rb = document.getElementById('mt-restart');
            if (rb) rb.onclick = async () => { rb.disabled = true; const d = await call('restart'); if (!d.success) return showError(d.message); view = d; selLeft = selRight = null; render(); };
        }

        (async function init() {
            try {
                const data = await call('load');
                if (!data.success) return showError(data.message || 'Error');
                view = data; render();
            } catch (e) { showError('No se pudo cargar la actividad. Revisa tu conexión e intenta de nuevo.'); }
        })();
    }

    window.WGMatching = { start };
})();
