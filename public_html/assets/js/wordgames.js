/**
 * wordgames.js
 * Utilidades compartidas de los juegos de palabras (Ahorcado y Crucigrama) para las
 * pantallas del estudiante, el proyector y el profesor.
 */
(function (global) {
    'use strict';
    const WG = {};

    // ---------- Utilidades ----------
    WG.esc = function (s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, c => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    };

    /** Letra en mayúscula y sin tilde (Ñ se conserva). Devuelve '' si no es una letra. */
    WG.normChar = function (ch) {
        if (!ch) return '';
        let c = String(ch).toUpperCase();
        if (c === 'Ñ') return 'Ñ';
        c = c.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return /^[A-Z]$/.test(c) ? c : '';
    };

    // ---------- Estilos (se inyectan una sola vez) ----------
    function injectStyles() {
        if (document.getElementById('wg-styles')) return;
        const st = document.createElement('style');
        st.id = 'wg-styles';
        st.textContent = `
        .wg-hangman { display:flex; flex-wrap:wrap; gap:16px; justify-content:center; align-items:center; margin:12px 0; }
        .wg-gallows { width:150px; height:170px; flex:0 0 auto; }
        .wg-gallows .part { stroke:#DC2626; stroke-width:4; fill:none; stroke-linecap:round; }
        .wg-gallows .frame { stroke:#475569; stroke-width:5; fill:none; stroke-linecap:round; }
        .wg-word { display:flex; flex-wrap:wrap; gap:6px; justify-content:center; margin:10px 0; }
        .wg-letter { width:clamp(26px, 8vw, 44px); height:clamp(34px, 10vw, 54px); border-bottom:4px solid var(--color-primary, #0878F9);
            display:flex; align-items:center; justify-content:center; font-size:clamp(1.2rem, 5vw, 1.9rem); font-weight:800; color:var(--color-navy, #071A3D); }
        .wg-letter.gap { border-bottom-color:transparent; width:14px; }
        .wg-letter.sym { border-bottom-color:transparent; width:auto; min-width:14px; }
        .wg-keyboard { display:flex; flex-wrap:wrap; gap:6px; justify-content:center; max-width:560px; margin:12px auto 0; }
        .wg-key { min-width:clamp(34px, 9vw, 46px); padding:10px 0; border:2px solid var(--color-border, #E3E9F2); background:#fff; border-radius:8px;
            font-weight:700; font-size:1rem; cursor:pointer; }
        .wg-key:hover:not([disabled]) { border-color:var(--color-primary, #0878F9); background:var(--color-primary-light, #E8F3FF); }
        .wg-key.ok { background:#E7F8EE; border-color:#16A34A; color:#166534; }
        .wg-key.bad { background:#FDECEC; border-color:#DC2626; color:#991B1B; }
        .wg-key[disabled] { cursor:default; opacity:0.85; }
        .wg-lives { text-align:center; font-weight:700; }

        .cw-wrap { display:flex; flex-wrap:wrap; gap:20px; align-items:flex-start; justify-content:center; }
        .cw-board { overflow-x:auto; max-width:100%; padding:4px; }
        .cw-grid { display:grid; gap:2px; justify-content:center; grid-template-columns:repeat(var(--cw-cols), var(--cw-size)); }
        .cw-cell { position:relative; width:var(--cw-size); height:var(--cw-size); background:#fff; border:2px solid #334155; border-radius:3px;
            display:flex; align-items:center; justify-content:center; font-weight:800; color:var(--color-navy, #071A3D); box-sizing:border-box; }
        .cw-empty { background:transparent; border-color:transparent; }
        .cw-num { position:absolute; top:1px; left:3px; font-size:calc(var(--cw-size) * 0.28); line-height:1; color:#64748B; font-weight:700; pointer-events:none; z-index:2; }
        .cw-input { width:100%; height:100%; border:none; background:transparent; text-align:center; font-weight:800; padding:0; margin:0;
            font-size:calc(var(--cw-size) * 0.55); text-transform:uppercase; color:inherit; border-radius:2px; caret-color:transparent; }
        .cw-input:focus { outline:none; }
        .cw-cell.cw-active { background:#E8F3FF; }
        .cw-cell.cw-focus { background:#FFE9A8; box-shadow:0 0 0 2px #F59E0B inset; }
        .cw-cell.cw-solution { background:#E7F8EE; border-color:#16A34A; }
        .cw-clues { flex:1 1 240px; min-width:220px; max-width:420px; text-align:left; }
        .cw-clues h4 { margin:8px 0 4px; }
        .cw-clues ol { list-style:none; margin:0; padding:0; }
        .cw-clues li { padding:5px 8px; border-radius:6px; cursor:default; font-size:0.92rem; }
        .cw-clues.cw-play li { cursor:pointer; }
        .cw-clues li.cw-clue-active { background:#FFE9A8; }
        .cw-clues li b { color:var(--color-primary, #0878F9); margin-right:4px; }
        .cw-clues li small { color:#64748B; }
        .cw-big .cw-clues li { font-size:1.15rem; padding:7px 10px; }
        `;
        document.head.appendChild(st);
    }

    // ---------- AHORCADO ----------
    WG.hangmanSvg = function (livesLeft, maxLives) {
        injectStyles();
        const wrong = Math.max(0, maxLives - livesLeft);
        // Los trazos van como atributos propios del SVG para que se vean siempre, aunque falle el CSS.
        const P = 'class="part" stroke="#DC2626" stroke-width="4" stroke-linecap="round" fill="none"';
        const F = 'class="frame" stroke="#475569" stroke-width="5" stroke-linecap="round" fill="none"';
        const parts = [
            `<circle ${P} cx="105" cy="52" r="15"/>`,
            `<line ${P} x1="105" y1="67" x2="105" y2="110"/>`,
            `<line ${P} x1="105" y1="78" x2="86" y2="98"/>`,
            `<line ${P} x1="105" y1="78" x2="124" y2="98"/>`,
            `<line ${P} x1="105" y1="110" x2="88" y2="140"/>`,
            `<line ${P} x1="105" y1="110" x2="122" y2="140"/>`,
        ];
        return `<svg class="wg-gallows" width="150" height="170" viewBox="0 0 150 170" role="img" aria-label="Ahorcado: ${wrong} de ${maxLives} errores">
            <line ${F} x1="15" y1="160" x2="135" y2="160"/>
            <line ${F} x1="40" y1="160" x2="40" y2="15"/>
            <line ${F} x1="38" y1="18" x2="105" y2="18"/>
            <line ${F} x1="105" y1="18" x2="105" y2="37"/>
            ${parts.slice(0, wrong).join('')}
        </svg>`;
    };

    /** pattern: arreglo de caracteres ('_' = letra oculta). */
    WG.hangmanWordHtml = function (pattern) {
        injectStyles();
        return '<div class="wg-word">' + pattern.map(ch => {
            if (ch === '_') return '<div class="wg-letter">&nbsp;</div>';
            if (ch === ' ') return '<div class="wg-letter gap"></div>';
            const isLetter = WG.normChar(ch) !== '';
            return `<div class="wg-letter ${isLetter ? '' : 'sym'}">${WG.esc(ch)}</div>`;
        }).join('') + '</div>';
    };

    WG.HANGMAN_KEYS = 'ABCDEFGHIJKLMNÑOPQRSTUVWXYZ'.split('');

    WG.hangmanKeyboardHtml = function (hm, disabledAll) {
        injectStyles();
        return '<div class="wg-keyboard">' + WG.HANGMAN_KEYS.map(k => {
            const used = hm.guessed.includes(k);
            const bad = hm.wrong.includes(k);
            const cls = used ? (bad ? 'bad' : 'ok') : '';
            return `<button type="button" class="wg-key ${cls}" data-letter="${k}" ${(used || disabledAll) ? 'disabled' : ''}>${k}</button>`;
        }).join('') + '</div>';
    };

    WG.hangmanBoardHtml = function (hm, opts) {
        injectStyles();
        opts = opts || {};
        const hearts = '❤️'.repeat(hm.lives_left) + '🖤'.repeat(hm.max_lives - hm.lives_left);
        return `
            <div class="wg-hangman">
                ${WG.hangmanSvg(hm.lives_left, hm.max_lives)}
                <div>
                    <div class="wg-lives">${hearts}</div>
                    ${hm.wrong.length ? `<p class="text-muted" style="text-align:center; margin:6px 0 0;">Fallaste: <strong>${hm.wrong.map(WG.esc).join(' ')}</strong></p>` : ''}
                </div>
            </div>
            ${WG.hangmanWordHtml(hm.pattern)}
            ${opts.keyboard === false ? '' : WG.hangmanKeyboardHtml(hm, opts.disabled)}
        `;
    };

    // ---------- CRUCIGRAMA ----------
    function cellsOfWord(w) {
        const keys = [];
        for (let k = 0; k < w.length; k++) {
            const r = w.row + (w.dir === 'down' ? k : 0);
            const c = w.col + (w.dir === 'across' ? k : 0);
            keys.push(r + ',' + c);
        }
        return keys;
    }

    function cellSizeCss(layout, big) {
        const max = big ? 56 : 40;
        return `--cw-cols:${layout.cols}; --cw-size:clamp(20px, calc((100vw - 48px) / ${layout.cols}), ${max}px);`;
    }

    /**
     * mode: 'input' (celdas editables), 'blank' (vacío, para proyectar) o 'solution' (con respuestas).
     * big: tablero más grande (proyector).
     */
    WG.crosswordGridHtml = function (layout, mode, big) {
        injectStyles();
        const cellMap = {};
        const starts = {};
        layout.words.forEach(w => {
            const keys = cellsOfWord(w);
            keys.forEach((k, i) => { cellMap[k] = (w.answer ? w.answer[i] : ''); });
            starts[w.row + ',' + w.col] = w.number;
        });

        let html = `<div class="cw-board"><div class="cw-grid" style="${cellSizeCss(layout, big)}">`;
        for (let r = 0; r < layout.rows; r++) {
            for (let c = 0; c < layout.cols; c++) {
                const key = r + ',' + c;
                if (!(key in cellMap)) { html += '<div class="cw-cell cw-empty"></div>'; continue; }
                const num = starts[key] ? `<span class="cw-num">${starts[key]}</span>` : '';
                if (mode === 'input') {
                    html += `<div class="cw-cell" data-key="${key}">${num}<input class="cw-input" data-key="${key}" type="text" maxlength="2" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-label="Casilla ${r + 1},${c + 1}"></div>`;
                } else if (mode === 'solution') {
                    html += `<div class="cw-cell cw-solution">${num}${WG.esc(cellMap[key])}</div>`;
                } else {
                    html += `<div class="cw-cell">${num}</div>`;
                }
            }
        }
        return html + '</div></div>';
    };

    WG.crosswordCluesHtml = function (layout, playable) {
        const list = dir => layout.words.filter(w => w.dir === dir)
            .sort((a, b) => a.number - b.number)
            .map(w => `<li data-wid="${w.id}:${w.dir}"><b>${w.number}.</b>${WG.esc(w.clue)} <small>(${w.length})</small></li>`).join('');
        return `<div class="cw-clues ${playable ? 'cw-play' : ''}">
            <h4>➡️ Horizontales</h4><ol>${list('across')}</ol>
            <h4>⬇️ Verticales</h4><ol>${list('down')}</ol>
        </div>`;
    };

    /** Crucigrama solo para mirar (proyector / profesor / resultados). mode: 'blank' o 'solution'. */
    WG.crosswordStaticHtml = function (layout, mode, big) {
        return `<div class="cw-wrap ${big ? 'cw-big' : ''}">${WG.crosswordGridHtml(layout, mode, big)}${WG.crosswordCluesHtml(layout, false)}</div>`;
    };

    /**
     * Crucigrama jugable. onChange(cellsObj) se llama en cada cambio.
     * Devuelve { getCells(), setDisabled(bool) }.
     */
    WG.mountCrossword = function (root, layout, initialCells, onChange) {
        injectStyles();
        root.innerHTML = `<div class="cw-wrap">${WG.crosswordGridHtml(layout, 'input', false)}${WG.crosswordCluesHtml(layout, true)}</div>`;

        const words = layout.words;
        const cellWords = {};
        words.forEach((w, i) => cellsOfWord(w).forEach(k => { (cellWords[k] = cellWords[k] || []).push(i); }));

        const inputs = {};
        root.querySelectorAll('.cw-input').forEach(inp => { inputs[inp.dataset.key] = inp; });
        const cells = {};
        Object.keys(initialCells || {}).forEach(k => {
            if (inputs[k]) {
                const v = WG.normChar(initialCells[k]);
                if (v) { cells[k] = v; inputs[k].value = v; }
            }
        });

        let active = 0;
        let activeKey = null;
        let disabled = false;

        function wordKeys(i) { return cellsOfWord(words[i]); }

        function highlight() {
            root.querySelectorAll('.cw-cell').forEach(c => c.classList.remove('cw-active', 'cw-focus'));
            wordKeys(active).forEach(k => inputs[k] && inputs[k].parentElement.classList.add('cw-active'));
            if (activeKey && inputs[activeKey]) inputs[activeKey].parentElement.classList.add('cw-focus');
            root.querySelectorAll('.cw-clues li').forEach(li => li.classList.remove('cw-clue-active'));
            const w = words[active];
            const li = root.querySelector(`.cw-clues li[data-wid="${w.id}:${w.dir}"]`);
            if (li) li.classList.add('cw-clue-active');
        }

        function focusKey(k) { if (inputs[k]) { inputs[k].focus(); inputs[k].select(); } }

        function moveInWord(key, delta) {
            const keys = wordKeys(active);
            const i = keys.indexOf(key);
            const j = i + delta;
            if (i >= 0 && j >= 0 && j < keys.length) focusKey(keys[j]);
        }

        function neighbor(key, dr, dc) {
            const [r, c] = key.split(',').map(Number);
            let rr = r + dr, cc = c + dc;
            while (rr >= 0 && cc >= 0 && rr < layout.rows && cc < layout.cols) {
                const k = rr + ',' + cc;
                if (inputs[k]) return k;
                rr += dr; cc += dc;
            }
            return null;
        }

        Object.keys(inputs).forEach(key => {
            const inp = inputs[key];
            let wasActive = false;

            inp.addEventListener('pointerdown', () => { wasActive = (activeKey === key && document.activeElement === inp); });
            inp.addEventListener('click', () => {
                const ws = cellWords[key];
                if (wasActive && ws.length > 1) {
                    active = ws.find(x => x !== active);
                    highlight();
                }
                inp.select();
            });
            inp.addEventListener('focus', () => {
                const ws = cellWords[key];
                if (!ws.includes(active)) active = ws[0];
                activeKey = key;
                highlight();
            });
            inp.addEventListener('input', () => {
                if (disabled) return;
                const last = Array.from(inp.value).pop() || '';
                const v = WG.normChar(last);
                inp.value = v;
                if (v) {
                    cells[key] = v;
                    onChange && onChange(cells);
                    moveInWord(key, +1);
                } else {
                    delete cells[key];
                    onChange && onChange(cells);
                }
            });
            inp.addEventListener('keydown', (e) => {
                if (disabled) return;
                if (e.key === 'Backspace' && inp.value === '') {
                    e.preventDefault();
                    const keys = wordKeys(active);
                    const i = keys.indexOf(key);
                    if (i > 0) {
                        const prev = keys[i - 1];
                        inputs[prev].value = '';
                        delete cells[prev];
                        onChange && onChange(cells);
                        focusKey(prev);
                    }
                    return;
                }
                const dirs = { ArrowLeft: [0, -1], ArrowRight: [0, 1], ArrowUp: [-1, 0], ArrowDown: [1, 0] };
                if (dirs[e.key]) {
                    e.preventDefault();
                    const n = neighbor(key, dirs[e.key][0], dirs[e.key][1]);
                    if (n) {
                        const ws = cellWords[n];
                        // Si la casilla vecina pertenece a otra dirección, se cambia a esa palabra
                        if (!ws.includes(active)) active = ws[0];
                        focusKey(n);
                    }
                }
            });
        });

        root.querySelectorAll('.cw-clues li').forEach(li => {
            li.addEventListener('click', () => {
                if (disabled) return;
                const i = words.findIndex(w => (w.id + ':' + w.dir) === li.dataset.wid);
                if (i < 0) return;
                active = i;
                const keys = wordKeys(i);
                focusKey(keys.find(k => !cells[k]) || keys[0]);
                highlight();
            });
        });

        active = 0;
        highlight();

        return {
            getCells: () => Object.assign({}, cells),
            setDisabled: (v) => {
                disabled = !!v;
                Object.values(inputs).forEach(i => { i.disabled = disabled; });
            },
        };
    };

    global.WG = WG;
    if (document.head) injectStyles();
})(window);
