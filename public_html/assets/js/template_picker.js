/**
 * template_picker.js — Selector de plantillas dentro de los editores (Fase 22).
 * Uso: AulaTemplates.open({ type: 'resumen', appUrl: AULA_APP_URL })
 * Al elegir una, reemplaza el contenido del editor (con confirmación) y el autoguardado la guarda como cualquier cambio.
 */
(function (global) {
    'use strict';

    function el(tag, css, text) {
        var n = document.createElement(tag);
        if (css) n.style.cssText = css;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    function open(opts) {
        var overlay = el('div', 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;');
        var box = el('div', 'background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:85vh;overflow:auto;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.3);');
        var head = el('div', 'display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;');
        head.appendChild(el('h2', 'margin:0;font-size:1.2rem;', '📄 Plantillas'));
        var close = el('button', 'border:none;background:none;font-size:1.4rem;cursor:pointer;', '×');
        close.type = 'button';
        head.appendChild(close);
        box.appendChild(head);
        box.appendChild(el('p', 'margin:0 0 12px;color:#64748B;font-size:.9rem;', 'Elige un punto de partida. Reemplazará lo que tengas ahora en el editor.'));
        var list = el('div', '');
        list.appendChild(el('p', 'color:#64748B;', 'Cargando...'));
        box.appendChild(list);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        function done() { overlay.remove(); }
        close.addEventListener('click', done);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) done(); });

        fetch(opts.appUrl + '/api/projects/templates.php?type=' + encodeURIComponent(opts.type), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                list.innerHTML = '';
                if (!res.success || !res.templates.length) { list.appendChild(el('p', 'color:#64748B;', 'No hay plantillas para este tipo de trabajo.')); return; }
                res.templates.forEach(function (t) {
                    var row = el('div', 'display:flex;gap:12px;align-items:center;justify-content:space-between;border:1px solid #E2E8F0;border-radius:12px;padding:10px 12px;margin-bottom:8px;');
                    var info = el('div', '');
                    info.appendChild(el('div', 'font-weight:700;', t.name));
                    info.appendChild(el('div', 'color:#64748B;font-size:.85rem;', t.desc));
                    var use = el('button', 'border:none;background:#0658C2;color:#fff;border-radius:10px;padding:8px 14px;cursor:pointer;font-weight:600;white-space:nowrap;', 'Usar');
                    use.type = 'button';
                    use.addEventListener('click', function () {
                        if (!confirm('Esto reemplaza lo que tienes ahora en el editor. ¿Usar la plantilla «' + t.name + '»?')) return;
                        Promise.resolve(global.AulaDraft.applyTemplate({ data: t.data })).then(done);
                    });
                    row.appendChild(info);
                    row.appendChild(use);
                    list.appendChild(row);
                });
            })
            .catch(function () { list.innerHTML = ''; list.appendChild(el('p', 'color:#B91C1C;', 'No se pudieron cargar las plantillas.')); });
    }

    global.AulaTemplates = { open: open };
})(window);
