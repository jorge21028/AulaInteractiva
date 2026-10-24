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

    // Aviso permanente y discreto de que pegar está desactivado
    document.addEventListener('DOMContentLoaded', function () {
        var pill = document.createElement('div');
        pill.textContent = '🔒 Pegar desactivado';
        pill.title = MSG;
        pill.style.cssText = 'position:fixed;top:10px;right:10px;background:#FFF4DD;color:#92400E;border:1px solid #F2D98B;border-radius:999px;padding:3px 10px;font-size:.75rem;font-weight:600;z-index:9998;';
        document.body.appendChild(pill);
    });
})();
