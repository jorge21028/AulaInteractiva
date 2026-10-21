/** Campanita de notificaciones del estudiante. Se inicia con AulaNotif.init({ listUrl, readUrl, csrf, allUrl }). */
(function () {
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    function init(cfg) {
        const wrap = document.getElementById('notif-wrap');
        if (!wrap) return;
        const btn = wrap.querySelector('.notif-btn');
        const badge = wrap.querySelector('.notif-badge');
        const panel = wrap.querySelector('.notif-panel');
        let loaded = false;

        function setBadge(n) {
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.classList.toggle('on', n > 0);
        }

        async function post(body) {
            try {
                const r = await fetch(cfg.readUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ csrf_token: cfg.csrf }, body)) });
                const d = await r.json();
                if (d.success) setBadge(d.unread);
                return d;
            } catch (e) { return null; }
        }

        function render(d) {
            let html = '<div class="notif-head"><span>🔔 Notificaciones</span><button type="button" id="notif-all">Marcar todas como leídas</button></div>';
            if (!d.items.length) html += '<div class="notif-empty">No tienes notificaciones.</div>';
            d.items.forEach(function (n) {
                html += '<a class="notif-item' + (n.is_read ? '' : ' unread') + '" href="' + esc(n.url || '#') + '" data-id="' + n.id + '">' +
                    esc(n.icon) + ' <strong>' + esc(n.title) + '</strong>' + (n.body ? '<small>' + esc(n.body) + '</small>' : '') + '<small>' + esc(n.ago) + '</small></a>';
            });
            html += '<a class="notif-foot" href="' + esc(cfg.allUrl) + '">Ver todas</a>';
            panel.innerHTML = html;
            const allBtn = panel.querySelector('#notif-all');
            if (allBtn) allBtn.onclick = async function () { await post({ all: true }); panel.querySelectorAll('.notif-item').forEach(function (a) { a.classList.remove('unread'); }); };
            panel.querySelectorAll('.notif-item').forEach(function (a) {
                a.addEventListener('click', function () { post({ id: parseInt(a.dataset.id, 10) }); });   // se marca como leída al abrirla
            });
        }

        async function load() {
            panel.innerHTML = '<div class="notif-empty">Cargando…</div>';
            try {
                const r = await fetch(cfg.listUrl, { credentials: 'same-origin', cache: 'no-store' });
                const d = await r.json();
                if (d.success) { setBadge(d.unread); render(d); loaded = true; }
            } catch (e) { panel.innerHTML = '<div class="notif-empty">No se pudieron cargar.</div>'; }
        }

        btn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            const open = panel.classList.toggle('open');
            if (open) load();
        });
        document.addEventListener('click', function (ev) { if (!wrap.contains(ev.target)) panel.classList.remove('open'); });
        // Actualiza el número de vez en cuando (sin abrir el panel)
        setInterval(async function () {
            if (document.hidden || panel.classList.contains('open')) return;
            try { const r = await fetch(cfg.listUrl, { credentials: 'same-origin', cache: 'no-store' }); const d = await r.json(); if (d.success) setBadge(d.unread); } catch (e) {}
        }, 90000);
    }

    window.AulaNotif = { init: init };
})();
