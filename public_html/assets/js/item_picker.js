/**
 * item_picker.js — Selector con buscador para elegir un cuestionario o una actividad interactiva (Fase 25).
 * Reemplaza visualmente al <select> (que sigue existiendo, oculto, como fuente del valor y para los eventos "change").
 * Si hay decenas de elementos: búsqueda por nombre, filtros por asignatura y tipo, orden (recientes / A-Z / más usados),
 * detalles de cada uno (preguntas, tiempo, intentos, cuántas veces se usó) y un resumen claro de lo elegido.
 *
 * AulaPicker.init({ selectId, containerId, items:[{id,title,icon,subtitle,chips:[],type,typeLabel,subjectId,subjectLabel,created,usedCount,lastUsed}],
 *                   selectedId, showSubjectFilter, showTypeFilter, noun })
 */
(function (global) {
    'use strict';

    var STYLE_ID = 'aula-picker-style';
    function injectStyle() {
        if (document.getElementById(STYLE_ID)) return;
        var st = document.createElement('style');
        st.id = STYLE_ID;
        st.textContent = [
            '.ap-box{border:1px solid var(--color-border,#E2E8F0);border-radius:12px;background:#fff;margin:6px 0 4px;overflow:hidden}',
            '.ap-tools{display:flex;gap:8px;flex-wrap:wrap;padding:10px;background:#F8FAFC;border-bottom:1px solid var(--color-border,#E2E8F0)}',
            '.ap-tools input,.ap-tools select{margin:0!important;width:auto!important;flex:1 1 150px;min-width:0;padding:8px 10px!important;font-size:.9rem!important}',
            '.ap-tools input[type=search]{flex:2 1 220px}',
            '.ap-chosen{padding:10px 12px;background:#EAF7EE;border-bottom:1px solid #BFE5CB;font-size:.92rem}',
            '.ap-list{max-height:320px;overflow-y:auto}',
            '.ap-item{display:block;width:100%;text-align:left;background:#fff;border:0;border-bottom:1px solid #EEF2F7;padding:10px 12px;cursor:pointer;font:inherit;color:inherit}',
            '.ap-item:hover{background:#F5F9FF}',
            '.ap-item.sel{background:#EAF7EE;box-shadow:inset 4px 0 0 #16A34A}',
            '.ap-title{font-weight:700}',
            '.ap-sub{color:#64748B;font-size:.82rem;margin-top:2px}',
            '.ap-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}',
            '.ap-chip{background:#EEF2F7;color:#475569;border-radius:999px;padding:1px 8px;font-size:.75rem}',
            '.ap-new{background:#FEF3C7;color:#92400E}',
            '.ap-used{background:#E0ECFF;color:#1E40AF}',
            '.ap-foot{padding:6px 12px;color:#64748B;font-size:.8rem;background:#F8FAFC}',
            '.ap-empty{padding:18px;text-align:center;color:#64748B}'
        ].join('');
        document.head.appendChild(st);
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }
    function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    function fmtDate(iso) {
        if (!iso) return '';
        var d = new Date(String(iso).replace(' ', 'T'));
        return isNaN(d) ? '' : d.toLocaleDateString('es', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    var registry = {};

    function init(cfg) {
        injectStyle();
        var select = document.getElementById(cfg.selectId);
        var host = document.getElementById(cfg.containerId);
        if (!select || !host) return;
        var items = cfg.items || [];
        var noun = cfg.noun || 'elemento';
        var plural = cfg.plural || (noun + 's');
        var current = String(cfg.selectedId || select.value || (items[0] && items[0].id) || '');

        select.style.display = 'none';       // sin JS el <select> sigue visible y funcionando
        host.innerHTML = '';

        var box = el('div', 'ap-box');
        var tools = el('div', 'ap-tools');
        var search = el('input'); search.type = 'search'; search.placeholder = '🔍 Buscar por nombre...'; search.setAttribute('aria-label', 'Buscar');
        tools.appendChild(search);

        var subjectSel = null, typeSel = null;
        var subjects = {}, types = {};
        items.forEach(function (it) { subjects[it.subjectId] = it.subjectLabel; if (it.type) types[it.type] = it.typeLabel; });
        if (cfg.showSubjectFilter && Object.keys(subjects).length > 1) {
            subjectSel = el('select');
            subjectSel.appendChild(new Option('Todas las asignaturas', ''));
            Object.keys(subjects).forEach(function (k) { subjectSel.appendChild(new Option(subjects[k], k)); });
            tools.appendChild(subjectSel);
        }
        if (cfg.showTypeFilter && Object.keys(types).length > 1) {
            typeSel = el('select');
            typeSel.appendChild(new Option('Todos los tipos', ''));
            Object.keys(types).forEach(function (k) { typeSel.appendChild(new Option(types[k], k)); });
            tools.appendChild(typeSel);
        }
        var sortSel = el('select');
        [['recent', 'Más recientes'], ['az', 'Nombre A–Z'], ['used', 'Más usados']].forEach(function (o) { sortSel.appendChild(new Option(o[1], o[0])); });
        tools.appendChild(sortSel);

        var chosen = el('div', 'ap-chosen');
        var list = el('div', 'ap-list');
        list.setAttribute('role', 'radiogroup');
        var foot = el('div', 'ap-foot');
        box.appendChild(tools); box.appendChild(chosen); box.appendChild(list); box.appendChild(foot);
        host.appendChild(box);

        function byId(id) { for (var i = 0; i < items.length; i++) if (String(items[i].id) === String(id)) return items[i]; return null; }

        function renderChosen() {
            var it = byId(current);
            chosen.innerHTML = '';
            if (!it) { chosen.textContent = 'Elige un ' + noun + ' de la lista.'; chosen.style.background = '#FFF8E6'; return; }
            chosen.style.background = '';
            var strong = el('strong', '', '✅ Elegido: ' + (it.icon || '') + ' ' + it.title);
            chosen.appendChild(strong);
            if (it.subtitle) chosen.appendChild(el('div', 'ap-sub', it.subtitle));
        }

        function setValue(id, fire) {
            current = String(id);
            select.value = current;
            renderChosen();
            Array.prototype.forEach.call(list.children, function (b) { if (b.dataset) b.classList.toggle('sel', b.dataset.id === current); b.setAttribute && b.setAttribute('aria-checked', b.dataset && b.dataset.id === current ? 'true' : 'false'); });
            if (fire) select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function render() {
            var q = norm(search.value);
            var sj = subjectSel ? subjectSel.value : '';
            var ty = typeSel ? typeSel.value : '';
            var rows = items.filter(function (it) {
                return (!q || norm(it.title + ' ' + it.subtitle + ' ' + it.typeLabel).indexOf(q) !== -1)
                    && (!sj || String(it.subjectId) === sj) && (!ty || it.type === ty);
            });
            var mode = sortSel.value;
            rows.sort(function (a, b) {
                if (mode === 'az') return norm(a.title).localeCompare(norm(b.title));
                if (mode === 'used') return (b.usedCount - a.usedCount) || String(b.created).localeCompare(String(a.created));
                return String(b.created).localeCompare(String(a.created));
            });
            list.innerHTML = '';
            if (!rows.length) list.appendChild(el('div', 'ap-empty', 'Ningún ' + noun + ' coincide con la búsqueda.'));
            rows.forEach(function (it) {
                var b = el('button', 'ap-item' + (String(it.id) === current ? ' sel' : ''));
                b.type = 'button'; b.dataset.id = String(it.id);
                b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', String(it.id) === current ? 'true' : 'false');
                b.appendChild(el('div', 'ap-title', (it.icon || '') + ' ' + it.title));
                if (it.subtitle) b.appendChild(el('div', 'ap-sub', it.subtitle));
                var chips = el('div', 'ap-chips');
                (it.chips || []).forEach(function (c) { chips.appendChild(el('span', 'ap-chip', c)); });
                if (it.usedCount > 0) chips.appendChild(el('span', 'ap-chip ap-used', 'Asignado ' + it.usedCount + (it.usedCount === 1 ? ' vez' : ' veces') + (it.lastUsed ? ' · última: ' + fmtDate(it.lastUsed) : '')));
                else chips.appendChild(el('span', 'ap-chip ap-new', 'Sin asignar aún'));
                if (it.created) chips.appendChild(el('span', 'ap-chip', 'Creado ' + fmtDate(it.created)));
                b.appendChild(chips);
                b.addEventListener('click', function () { setValue(it.id, true); });
                list.appendChild(b);
            });
            foot.textContent = 'Mostrando ' + rows.length + ' de ' + items.length + ' ' + (items.length === 1 ? noun : plural);
        }

        search.addEventListener('input', render);
        [subjectSel, typeSel, sortSel].forEach(function (s) { if (s) s.addEventListener('change', render); });

        // Si otra parte de la página cambia el <select> por código, el selector se actualiza con AulaPicker.sync(id)
        registry[cfg.selectId] = function () { current = String(select.value); renderChosen(); render(); };

        setValue(current, false);
        render();
    }

    global.AulaPicker = { init: init, sync: function (selectId) { if (registry[selectId]) registry[selectId](); } };
})(window);
