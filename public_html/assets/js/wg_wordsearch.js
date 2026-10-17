/**
 * wg_wordsearch.js — SOPA DE LETRAS individual (estudiante).
 * Uso: WGWordSearch.start({ panel, call, backUrl, showError, fmtScore })
 * Marcar una palabra: arrastra con el dedo/ratón de la primera a la última letra, o toca la primera y luego la última.
 */
(function () {
    const COLORS = ['#BFDBFE', '#BBF7D0', '#FDE68A', '#FBCFE8', '#DDD6FE', '#FED7AA', '#A7F3D0', '#FECACA', '#C7D2FE', '#FDE047'];
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    function lineCells(r1, c1, r2, c2) {
        const dr = Math.sign(r2 - r1), dc = Math.sign(c2 - c1);
        const len = Math.max(Math.abs(r2 - r1), Math.abs(c2 - c1)) + 1;
        const out = [];
        for (let k = 0; k < len; k++) out.push([r1 + dr * k, c1 + dc * k]);
        return out;
    }

    /** Ajusta el punto final a la línea recta (horizontal, vertical o diagonal) más cercana. */
    function snap(r1, c1, r2, c2, size) {
        const dr = r2 - r1, dc = c2 - c1, adr = Math.abs(dr), adc = Math.abs(dc);
        let er = r2, ec = c2;
        if (adr !== 0 && adc !== 0 && adr !== adc) {
            if (adr > adc * 2) ec = c1;
            else if (adc > adr * 2) er = r1;
            else { const len = Math.max(adr, adc); er = r1 + Math.sign(dr) * len; ec = c1 + Math.sign(dc) * len; }
        }
        er = Math.max(0, Math.min(size - 1, er)); ec = Math.max(0, Math.min(size - 1, ec));
        // si el recorte rompió la diagonal, se acorta
        const a = Math.abs(er - r1), b = Math.abs(ec - c1);
        if (a !== 0 && b !== 0 && a !== b) { const len = Math.min(a, b); er = r1 + Math.sign(er - r1) * len; ec = c1 + Math.sign(ec - c1) * len; }
        return [er, ec];
    }

    function start(ctx) {
        const { panel, call, backUrl, showError, fmtScore } = ctx;
        let view = null, busy = false, startCell = null, tapStart = null, hover = null, dragging = false, toastTimer = null;

        function cellEl(r, c) { return panel.querySelector('.ws-cell[data-r="' + r + '"][data-c="' + c + '"]'); }

        function paint() {
            panel.querySelectorAll('.ws-cell').forEach(el => { el.style.background = '#fff'; el.style.outline = 'none'; });
            view.words.forEach((w, i) => {
                const cells = w.cells || w.solution;
                if (!cells) return;
                const col = w.cells ? COLORS[i % COLORS.length] : '#FEE2E2';
                lineCells(cells[0], cells[1], cells[2], cells[3]).forEach(([r, c]) => { const el = cellEl(r, c); if (el) el.style.background = col; });
            });
            // selección en curso
            let from = startCell || tapStart, to = hover || tapStart;
            if (from && to) {
                const [er, ec] = snap(from[0], from[1], to[0], to[1], view.size);
                lineCells(from[0], from[1], er, ec).forEach(([r, c]) => { const el = cellEl(r, c); if (el) { el.style.background = '#E0F2FE'; el.style.outline = '2px solid #0878F9'; } });
            }
        }

        function toast(msg, ok) {
            const t = panel.querySelector('#ws-toast');
            if (!t) return;
            t.textContent = msg; t.style.color = ok ? '#15803D' : '#B91C1C';
            clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.textContent = ''; }, 2200);
        }

        function wordList() {
            return view.words.map((w, i) => {
                const col = COLORS[i % COLORS.length];
                const done = w.found;
                return '<li style="padding:6px 8px; border-radius:8px; margin-bottom:4px; display:flex; gap:8px; align-items:flex-start; ' +
                    (done ? 'background:' + col + ';' : 'background:#F8FAFC;') + '">' +
                    '<span>' + (done ? '✅' : '🔎') + '</span><span style="' + (done ? 'text-decoration:line-through; opacity:.8;' : '') + '">' + esc(w.clue) +
                    ' <span class="text-muted" style="font-size:0.8rem;">(' + w.length + ' letras)</span>' +
                    (w.word && done ? ' → <strong>' + esc(w.word) + '</strong>' : '') + '</span></li>';
            }).join('');
        }

        function render() {
            if (view.status === 'completed') return renderResult();
            if (view.overdue) return showError('La fecha de entrega ya venció.');
            const size = view.size;
            const cellPx = Math.max(24, Math.min(40, Math.floor(Math.min(panel.clientWidth - 8, 640) / size)));
            let grid = '<div id="ws-grid" style="display:grid; grid-template-columns:repeat(' + size + ', ' + cellPx + 'px); gap:2px; justify-content:center; touch-action:none; user-select:none; -webkit-user-select:none; margin:6px auto;">';
            for (let r = 0; r < size; r++) for (let c = 0; c < size; c++) {
                grid += '<div class="ws-cell" data-r="' + r + '" data-c="' + c + '" style="width:' + cellPx + 'px; height:' + cellPx + 'px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:' + Math.round(cellPx * 0.5) + 'px; border:1px solid #E3E9F2; border-radius:4px; background:#fff; cursor:pointer;">' + esc(view.grid[r][c]) + '</div>';
            }
            grid += '</div>';
            panel.innerHTML =
                '<p class="text-muted" style="text-align:center; margin-top:0;">Encuentra las <strong>' + view.total + '</strong> palabras. Arrastra desde la primera hasta la última letra (o toca la primera y luego la última). ' +
                'Van en horizontal, vertical o diagonal ↗ ↘.</p>' +
                '<p style="text-align:center; margin:0;"><strong>' + view.found + ' de ' + view.total + '</strong> encontradas</p>' +
                '<p id="ws-toast" style="text-align:center; min-height:1.4em; margin:4px 0; font-weight:600;"></p>' +
                grid +
                '<h3 style="margin-bottom:6px;">Palabras a buscar</h3><ul style="list-style:none; padding:0; margin:0 0 14px;">' + wordList() + '</ul>' +
                '<button class="btn" id="ws-submit" style="width:100%;">Entregar</button>';
            paint();
            bind();
            document.getElementById('ws-submit').onclick = submit;
        }

        function cellFromEvent(e) {
            const el = document.elementFromPoint(e.clientX, e.clientY);
            const t = el && el.closest ? el.closest('.ws-cell') : null;
            return t ? [parseInt(t.dataset.r, 10), parseInt(t.dataset.c, 10)] : null;
        }

        function bind() {
            const g = document.getElementById('ws-grid');
            g.addEventListener('pointerdown', (e) => {
                if (busy) return;
                const cell = cellFromEvent(e);
                if (!cell) return;
                e.preventDefault();
                if (tapStart) {            // segundo toque: cierra la selección
                    const [er, ec] = snap(tapStart[0], tapStart[1], cell[0], cell[1], view.size);
                    const from = tapStart; tapStart = null; hover = null;
                    send(from, [er, ec]);
                    return;
                }
                startCell = cell; hover = cell; dragging = true; paint();
            });
            g.addEventListener('pointermove', (e) => {
                if (!dragging && !tapStart) return;
                const cell = cellFromEvent(e);
                if (cell && (!hover || hover[0] !== cell[0] || hover[1] !== cell[1])) { hover = cell; paint(); }
            });
            const up = (e) => {
                if (!dragging) return;
                dragging = false;
                const cell = cellFromEvent(e) || hover || startCell;
                const from = startCell; startCell = null;
                if (!from) return;
                if (!cell || (cell[0] === from[0] && cell[1] === from[1])) { tapStart = from; hover = from; paint(); return; }   // fue un toque
                const [er, ec] = snap(from[0], from[1], cell[0], cell[1], view.size);
                hover = null; send(from, [er, ec]);
            };
            g.addEventListener('pointerup', up);
            g.addEventListener('pointercancel', () => { dragging = false; startCell = null; hover = null; paint(); });
        }

        async function send(from, to) {
            busy = true; paint();
            let data;
            try { data = await call('select', { r1: from[0], c1: from[1], r2: to[0], c2: to[1] }); }
            catch (e) { busy = false; hover = null; paint(); toast('Sin conexión. Intenta de nuevo.', false); return; }
            busy = false; hover = null;
            if (!data.success) { if (data.overdue) return showError(data.message); toast(data.message || 'No se pudo comprobar.', false); paint(); return; }
            view = data;
            if (data.status === 'completed') { render(); return; }
            render();
            toast(data.hit ? '🎉 ¡Encontraste una palabra!' : 'Esa no es una de las palabras de la lista.', !!data.hit);
        }

        async function submit() {
            const left = view.total - view.found;
            const msg = left > 0 ? 'Aún te faltan ' + left + ' palabra(s). ¿Entregar de todas formas? Se calificará lo que hayas encontrado.' : '¿Entregar?';
            if (!confirm(msg)) return;
            const b = document.getElementById('ws-submit'); b.disabled = true;
            const data = await call('submit');
            if (!data.success) { b.disabled = false; alert(data.message || 'No se pudo entregar.'); return; }
            view = data; render();
        }

        function renderResult() {
            const r = view.result;
            const hasSol = view.words.some(w => w.solution);
            const size = view.size;
            const cellPx = Math.max(20, Math.min(32, Math.floor(Math.min(panel.clientWidth - 8, 560) / size)));
            let grid = '<div id="ws-grid" style="display:grid; grid-template-columns:repeat(' + size + ', ' + cellPx + 'px); gap:2px; justify-content:center; margin:8px auto;">';
            for (let rr = 0; rr < size; rr++) for (let c = 0; c < size; c++) {
                grid += '<div class="ws-cell" data-r="' + rr + '" data-c="' + c + '" style="width:' + cellPx + 'px; height:' + cellPx + 'px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:' + Math.round(cellPx * 0.5) + 'px; border:1px solid #E3E9F2; border-radius:4px; background:#fff;">' + esc(view.grid[rr][c]) + '</div>';
            }
            grid += '</div>';
            const rows = view.words.map(w => '<li style="padding:5px 0; border-bottom:1px solid var(--color-border, #E3E9F2);">' + (w.found ? '✅' : '❌') + ' ' + esc(w.clue) +
                (w.word ? ' → <strong>' + esc(w.word) + '</strong>' : '') + '</li>').join('');
            panel.innerHTML =
                '<h2 style="text-align:center; margin-top:0;">🏁 ¡Sopa de letras entregada!</h2>' +
                '<div class="alert ' + (r.correct === r.total ? 'alert-success' : 'alert-error') + '" style="text-align:center; font-size:1.1rem;">Encontraste <strong>' + r.correct + ' de ' + r.total + '</strong> palabras · Calificación: <strong>' + fmtScore(r.score) + ' / ' + r.points + '</strong></div>' +
                grid +
                (hasSol ? '<p class="text-muted" style="text-align:center; font-size:0.85rem;">En rojo: las palabras que no encontraste.</p>' : '') +
                '<ul style="list-style:none; padding:0; margin:12px 0;">' + rows + '</ul>' +
                '<div style="text-align:center;">' +
                (view.allow_repeat && !view.overdue ? '<button class="btn" id="ws-restart">Intentar de nuevo (se guarda tu mejor nota)</button> ' : '') +
                '<a class="btn btn-secondary" href="' + backUrl + '">Volver a la asignación</a></div>';
            paint();
            const rb = document.getElementById('ws-restart');
            if (rb) rb.onclick = async () => { rb.disabled = true; const d = await call('restart'); if (!d.success) return showError(d.message); view = d; render(); };
        }

        (async function init() {
            try {
                const data = await call('load');
                if (!data.success) return showError(data.message || 'Error');
                view = data; render();
            } catch (e) { showError('No se pudo cargar la actividad. Revisa tu conexión e intenta de nuevo.'); }
        })();
    }

    window.WGWordSearch = { start };
})();
