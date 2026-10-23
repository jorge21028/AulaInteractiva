/**
 * draft_guard.js — Protege el trabajo del estudiante para que NO se pierda al cerrar o actualizar la página.
 *
 * Qué hace (para todos los editores: resumen, tabla, infografía, mapa mental, presentación, imágenes, archivos):
 *  1. Guarda en el servidor unos segundos después del último cambio (antes solo cada 20 s, y se perdía lo de los
 *     últimos segundos si se cerraba la página).
 *  2. Mantiene una COPIA LOCAL en el navegador (localStorage) con cada cambio. Si la página se cierra, se actualiza o
 *     se cae el internet antes de que el servidor reciba el cambio, al volver a abrir el trabajo se recupera solo.
 *  3. Al ocultar/cerrar la pestaña intenta guardar de inmediato (keepalive) y avisa si todavía hay cambios sin guardar.
 *  4. Si guardar falla, el trabajo sigue protegido en la copia local y se muestra un aviso claro.
 *
 * Uso:
 *   AulaDraft.init({
 *     appUrl, projectId, csrf, userKey, version,      // version = updated_at que el servidor entregó con la página
 *     getState: () => ({ title, data }) | null,       // null = el editor todavía no está listo (no se guarda nada)
 *     applyState: (state) => void | Promise,          // vuelca un estado recuperado dentro del editor
 *     statusEl, isBusy: () => boolean                 // opcionales
 *   });
 *   await AulaDraft.saveNow(silent)  -> true/false
 */
(function (global) {
    'use strict';

    var opts = null;
    var ready = false;
    var base = '';            // versión del servidor sobre la que están los cambios locales
    var lastSer = '';         // última foto vista del estado
    var savedSer = '';        // foto que ya está guardada en el servidor
    var dirty = false;
    var dirtySince = 0;
    var changedAt = 0;
    var pollTimer = null;
    var failures = 0;

    var POLL_MS = 1500;
    var DEBOUNCE_MS = 4000;     // guarda 4 s después del último cambio
    var MAX_WAIT_MS = 15000;    // y como mucho cada 15 s si el estudiante no deja de editar
    var KEEPALIVE_MAX = 60000;  // los navegadores limitan las peticiones "keepalive" a ~64 KB

    function key() { return 'aula_draft_v1:' + (opts.userKey || '0') + ':' + opts.projectId; }

    function ser(state) { try { return JSON.stringify(state); } catch (e) { return ''; } }

    function readLocal() {
        try { var raw = global.localStorage.getItem(key()); return raw ? JSON.parse(raw) : null; } catch (e) { return null; }
    }
    function writeLocal(state, s) {
        try { global.localStorage.setItem(key(), JSON.stringify({ base: base, ser: s, state: state, at: Date.now() })); } catch (e) { /* sin espacio / modo privado */ }
    }
    function clearLocal() { try { global.localStorage.removeItem(key()); } catch (e) { /* nada */ } }

    function setStatus(text, isError) {
        if (!opts || !opts.statusEl) return;
        opts.statusEl.textContent = text;
        opts.statusEl.style.color = isError ? '#B91C1C' : '';
        opts.statusEl.style.fontWeight = isError ? '600' : '';
    }

    function clock() { return new Date().toLocaleTimeString(); }

    function payload(state) {
        var body = { project_id: opts.projectId, data: state.data, csrf_token: opts.csrf };
        if (state.title !== undefined && state.title !== null) body.title = state.title;
        return body;
    }

    function banner(message) {
        if (!opts.statusEl || !opts.statusEl.parentNode) return;
        var div = document.createElement('div');
        div.className = 'alert alert-success';
        div.style.margin = '12px 0';
        div.textContent = message;
        opts.statusEl.parentNode.parentNode.insertBefore(div, opts.statusEl.parentNode);
    }

    var inflight = null;

    /** Guarda ahora. Si ya hay un guardado en curso, espera a que termine y luego guarda lo más reciente. */
    async function saveNow(silent) {
        if (!ready) return false;
        while (inflight) { await inflight; }
        inflight = doSave(silent);
        var ok = await inflight;
        inflight = null;
        return ok;
    }

    async function doSave(silent) {
        var state = opts.getState();
        if (!state) return false;
        var s = ser(state);
        if (s === '') return false;

        if (!silent) setStatus('Guardando...');
        var ok = false;
        try {
            var res = await fetch(opts.appUrl + '/api/projects/save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload(state)),
            });
            var data = null;
            try { data = await res.json(); } catch (e) { data = null; }

            if (data && data.success) {
                ok = true;
                failures = 0;
                if (data.version) base = data.version;
                savedSer = s;
                var now = opts.getState();
                dirty = !!now && ser(now) !== savedSer;
                if (dirty) { dirtySince = Date.now(); changedAt = Date.now(); writeLocal(now, ser(now)); } else { clearLocal(); }
                setStatus('Guardado ' + clock());
            } else {
                failures++;
                var msg = (data && data.message) ? data.message : 'No se pudo guardar';
                setStatus('⚠️ ' + msg + ' — tu avance sigue guardado en este dispositivo.', true);
            }
        } catch (e) {
            failures++;
            setStatus('⚠️ Sin conexión: tu avance está guardado en este dispositivo y se enviará en cuanto vuelva internet.', true);
        }
        return ok;
    }

    /** Foto del estado cada pocos segundos: detecta cambios sin tener que enganchar cada evento de cada editor. */
    async function tick() {
        if (!opts) return;
        var state = opts.getState();
        if (!state) return;                       // editor aún cargando (o restaurando): no tocar nada
        var s = ser(state);
        if (s === '') return;

        if (!ready) { await start(state, s); return; }

        if (s !== lastSer) {
            lastSer = s;
            var nowDirty = s !== savedSer;
            if (nowDirty && !dirty) dirtySince = Date.now();
            dirty = nowDirty;
            changedAt = Date.now();
            if (dirty) writeLocal(state, s); else clearLocal();
        }

        if (dirty && !inflight) {
            var t = Date.now();
            // Tras un fallo se reintenta cada ~15 s en vez de insistir cada tick
            var waited = failures > 0 ? 15000 : DEBOUNCE_MS;
            if (t - changedAt >= waited || t - dirtySince >= MAX_WAIT_MS) saveNow(true);
        }
    }

    /** Primera vez que el editor está listo: decide si hay una copia local más reciente que la del servidor. */
    async function start(state, s) {
        ready = true;
        base = opts.version || '';
        savedSer = s;
        lastSer = s;
        dirty = false;

        var local = readLocal();
        if (local && local.state && local.ser !== s) {
            if (local.base === base) {
                // El servidor no recibió esos últimos cambios: se recuperan.
                try {
                    await opts.applyState(local.state);
                    banner('♻️ Recuperamos los cambios que no alcanzaron a guardarse la última vez. Ya se están guardando.');
                    dirty = true; dirtySince = Date.now(); changedAt = 0; // changedAt=0 => se guarda en el próximo ciclo
                    lastSer = '';                                          // fuerza a releer el estado ya restaurado
                    var fresh = opts.getState();
                    if (fresh) { lastSer = ser(fresh); writeLocal(fresh, lastSer); }
                } catch (e) {
                    console.error('AulaDraft: no se pudo restaurar', e);
                }
            } else {
                clearLocal(); // el servidor tiene una versión más nueva (guardada desde otra pestaña/dispositivo)
            }
        } else if (local) {
            clearLocal();
        }
    }

    /** Guarda lo que se pueda cuando la pestaña se oculta o se cierra. */
    function flush() {
        if (!opts || !ready) return;
        var state = opts.getState();
        if (!state) return;
        var s = ser(state);
        if (s === '') return;
        if (s !== savedSer) {
            dirty = true;
            writeLocal(state, s);                       // siempre: esto es lo que salva el trabajo
            var body = JSON.stringify(payload(state));
            if (body.length < KEEPALIVE_MAX) {
                try {
                    fetch(opts.appUrl + '/api/projects/save.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin', keepalive: true, body: body,
                    });
                } catch (e) { /* queda la copia local */ }
            }
        }
    }

    function init(options) {
        opts = options;
        if (global.__aulaDraftStarted) return;
        global.__aulaDraftStarted = true;

        pollTimer = setInterval(tick, POLL_MS);
        tick();

        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
        global.addEventListener('pagehide', flush);
        global.addEventListener('online', function () { if (dirty) saveNow(true); });
        global.addEventListener('beforeunload', function (e) {
            if (!ready) return;
            var busy = typeof opts.isBusy === 'function' && opts.isBusy();
            var state = opts.getState();
            var pending = dirty || (state && ser(state) !== savedSer);
            if (pending || busy) {
                flush();
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    global.AulaDraft = {
        init: init,
        saveNow: saveNow,
        isDirty: function () { return dirty; },
        /** Los endpoints de archivos cambian updated_at: se avisa para que la copia local siga "colgada" de la versión correcta. */
        setVersion: function (v) {
            if (!v) return;
            base = v;
            if (dirty && opts) { var st = opts.getState(); if (st) writeLocal(st, ser(st)); }
        },
        clearLocal: function () { if (opts) clearLocal(); },
        /** Vuelca una plantilla en el editor; el ciclo de autoguardado detecta el cambio y lo guarda. */
        applyTemplate: function (state) {
            if (!opts || !ready) return Promise.resolve();
            var cur = opts.getState();
            var next = { title: (cur && cur.title !== undefined) ? cur.title : undefined, data: state.data };
            return Promise.resolve(opts.applyState(next));
        },
    };
})(window);
