/**
 * paste_guard.js — Bloquea pegar texto en una actividad de creación cuando el profesor lo desactivó (Fase 23).
 * Se activa solo si la página define window.AULA_BLOCK_PASTE = true. Bloquea:
 *   · Ctrl+V / Cmd+V / Shift+Insert, y "Pegar" del clic derecho o del menú del teléfono (evento "paste")
 *   · arrastrar y soltar texto dentro de un campo de escritura
 * No bloquea subir archivos o imágenes con los botones ni arrastrar un archivo a una zona de subida.
 * Es una barrera pensada para estudiantes honestos (evita el "copiar y pegar" fácil), no una seguridad infalible.
 */
(function () {
    'use strict';
    if (!window.AULA_BLOCK_PASTE) return;

    var MSG = 'Pegar está desactivado en esta actividad: escribe con tus propias palabras.';
    var toastEl = null, toastTimer = null;

    // ---- Registro al servidor (marca "Copy and Paste" para el profesor) ----
    var cfg = window.AULA_PASTE_REPORT || null;
    var pending = { hits: 0, attempts: 0, chars: 0 }, flushTimer = null;
    var lastAttempt = 0, lastHit = 0, allowUntil = 0, undoUntil = 0;
    var startedAt = Date.now();
    var BIG_INSERT = 30;   // escribir a mano no inserta tantos caracteres de una vez

    function flush() {
        flushTimer = null;
        if (!cfg || (!pending.hits && !pending.attempts)) return;
        var sent = pending;
        pending = { hits: 0, attempts: 0, chars: 0 };
        var body = new FormData();
        body.append('project_id', cfg.project);
        body.append('csrf_token', cfg.csrf);
        body.append('hits', sent.hits);
        body.append('attempts', sent.attempts);
        body.append('chars', sent.chars);
        try {
            fetch(cfg.url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error('http'); })
                .catch(function () {   // si falla, se reintenta con el siguiente aviso
                    pending.hits += sent.hits; pending.attempts += sent.attempts; pending.chars = Math.max(pending.chars, sent.chars);
                });
        } catch (err) { /* sin red: se pierde este aviso */ }
    }
    function queue(kind, chars) {
        if (!cfg) return;
        if (kind === 'hit') { pending.hits++; pending.chars = Math.max(pending.chars, chars || 0); lastHit = Date.now(); }
        else { pending.attempts++; }
        if (!flushTimer) flushTimer = setTimeout(flush, 1500);
    }
    window.addEventListener('pagehide', flush);

    // Permite inserciones legítimas hechas por la propia página (p. ej. aplicar una plantilla)
    window.AulaPasteGuard = { allow: function (ms) { allowUntil = Date.now() + (ms || 4000); } };

    function toast() {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.setAttribute('role', 'status');
            toastEl.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#1E293B;color:#fff;padding:10px 16px;border-radius:12px;font-size:.9rem;z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.3);max-width:90vw;text-align:center;';
            toastEl.textContent = '🔒 ' + MSG;
            document.body.appendChild(toastEl);
        }
        toastEl.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.style.display = 'none'; }, 3200);
    }

    function stop(e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        toast();
        var t = Date.now();
        if (t - lastAttempt > 500) { lastAttempt = t; queue('attempt'); }   // Ctrl+V + paste + beforeinput = una sola acción
    }

    var TEXT_FIELD = 'input:not([type=file]):not([type=checkbox]):not([type=radio]), textarea, [contenteditable=""], [contenteditable="true"], .ql-editor';

    // Capturamos en "document" y en fase de captura: así nadie (ni Quill, ni Fabric) alcanza a recibir el pegado.
    document.addEventListener('paste', stop, true);
    document.addEventListener('beforeinput', function (e) {
        if (/^insertFrom(Paste|PasteAsQuotation|Drop|Yank)$/.test(e.inputType || '')) stop(e);
    }, true);
    document.addEventListener('keydown', function (e) {
        var key = (e.key || '').toLowerCase();
        if (((e.ctrlKey || e.metaKey) && key === 'v') || (e.shiftKey && key === 'insert')) stop(e);
    }, true);
    document.addEventListener('drop', function (e) {
        var t = e.target && e.target.closest ? e.target.closest(TEXT_FIELD) : null;
        var types = (e.dataTransfer && e.dataTransfer.types) ? Array.prototype.slice.call(e.dataTransfer.types) : [];
        if (t && (types.indexOf('text/plain') > -1 || types.indexOf('text/html') > -1) && types.indexOf('Files') === -1) stop(e);
    }, true);

    // ---- Detección de pegados que se colaron (JS, extensiones, execCommand, API del editor...) ----
    // a) Eventos de entrada: un "pegar/soltar" que llega hasta aquí significa que alguien desactivó el bloqueo,
    //    o se insertaron de golpe muchos caracteres seguidos (a mano no se escribe así).
    window.addEventListener('beforeinput', function (e) {
        if (/^history(Undo|Redo)$/.test(e.inputType || '')) undoUntil = Date.now() + 1500;
    }, true);
    window.addEventListener('keydown', function (e) {
        var k = (e.key || '').toLowerCase();
        if ((e.ctrlKey || e.metaKey) && (k === 'z' || k === 'y')) undoUntil = Date.now() + 1500;
    }, true);
    window.addEventListener('input', function (e) {
        if (Date.now() < allowUntil) return;
        var type = e.inputType || '';
        if (/^insertFrom(Paste|PasteAsQuotation|Drop|Yank)$/.test(type)) { queue('hit', (e.data || '').length); return; }
        if (!e.isComposing && !/Composition|Replacement/.test(type) && e.data && e.data.length >= BIG_INSERT) queue('hit', e.data.length);
    }, true);

    // b) Revisión periódica: si un campo que tiene el cursor gana de golpe 30+ caracteres, se insertó texto sin teclear.
    //    Esto atrapa lo que no genera eventos (editor.insertText(...), campo.value = ..., innerHTML...).
    var TEXT_SEL = TEXT_FIELD;
    var sizes = typeof WeakMap === 'function' ? new WeakMap() : null;
    function sizeOf(el) { return ((el.value !== undefined && el.value !== null) ? el.value : (el.textContent || '')).length; }
    function scan() {
        if (!sizes) return;
        var fields = document.querySelectorAll(TEXT_SEL);
        var quiet = Date.now() < allowUntil || Date.now() < undoUntil || Date.now() - startedAt < 2500;
        for (var i = 0; i < fields.length; i++) {
            var el = fields[i], n = sizeOf(el), prev = sizes.get(el);
            sizes.set(el, n);
            if (prev === undefined || quiet) continue;
            if (n - prev >= BIG_INSERT && document.activeElement === el && Date.now() - lastHit > 2000) queue('hit', n - prev);
        }
    }
    setInterval(scan, 600);

    // Aviso permanente y discreto de que pegar está desactivado
    document.addEventListener('DOMContentLoaded', function () {
        var pill = document.createElement('div');
        pill.textContent = '🔒 Pegar desactivado';
        pill.title = MSG;
        pill.style.cssText = 'position:fixed;top:10px;right:10px;background:#FFF4DD;color:#92400E;border:1px solid #F2D98B;border-radius:999px;padding:3px 10px;font-size:.75rem;font-weight:600;z-index:9998;';
        document.body.appendChild(pill);
    });
})();
