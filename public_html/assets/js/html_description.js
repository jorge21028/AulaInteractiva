/**
 * html_description.js — barra de ayuda y vista previa para descripciones en HTML del profesor.
 * Uso: AulaHtmlDesc.mount(contenedor)  (lo llama includes/description_field.php)
 */
(function () {
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    /** Enlace de video -> HTML para incrustarlo. Devuelve null si no se reconoce. */
    function videoHtml(url) {
        url = url.trim();
        let m;
        if ((m = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{6,15})/))) {
            return '<iframe src="https://www.youtube.com/embed/' + m[1] + '" width="560" height="315" title="Video"></iframe>';
        }
        if ((m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/))) {
            return '<iframe src="https://player.vimeo.com/video/' + m[1] + '" width="560" height="315" title="Video"></iframe>';
        }
        if ((m = url.match(/drive\.google\.com\/file\/d\/([A-Za-z0-9_-]+)/))) {
            return '<iframe src="https://drive.google.com/file/d/' + m[1] + '/preview" width="560" height="315" title="Video"></iframe>';
        }
        if (/^https?:\/\/\S+\.(mp4|webm|ogg)(\?\S*)?$/i.test(url)) {
            return '<video src="' + esc(url) + '" controls></video>';
        }
        if (/^https:\/\/\S+$/i.test(url)) {
            return '<iframe src="' + esc(url) + '" width="560" height="315" title="Contenido"></iframe>';
        }
        return null;
    }

    function insertAtCursor(ta, text) {
        const s = ta.selectionStart, e = ta.selectionEnd;
        ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = s + text.length;
    }

    function mount(wrap) {
        if (!wrap || wrap.dataset.mounted) return;
        wrap.dataset.mounted = '1';
        const ta = wrap.querySelector('textarea');
        const tools = wrap.querySelector('.desc-tools');
        const help = wrap.querySelector('.desc-help');
        const box = wrap.querySelector('.desc-preview');
        const radios = wrap.querySelectorAll('input[type=radio]');
        const isHtml = () => Array.from(radios).some(r => r.checked && r.value === 'html');

        function sync() {
            const on = isHtml();
            tools.style.display = on ? 'flex' : 'none';
            help.style.display = on ? 'block' : 'none';
            if (!on) box.style.display = 'none';
        }
        radios.forEach(r => r.addEventListener('change', () => {
            // Al pasar de texto a HTML, los saltos de línea se conservan como <br>
            if (isHtml() && ta.value.trim() !== '' && ta.value.indexOf('<') === -1) {
                ta.value = esc(ta.value).replace(/\n/g, '<br>\n');
            }
            sync();
        }));
        sync();

        async function preview() {
            box.style.display = 'block';
            box.innerHTML = '<span class="text-muted">Generando vista previa…</span>';
            try {
                const res = await fetch(wrap.dataset.previewUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ html: ta.value, csrf_token: wrap.dataset.csrf }),
                });
                const data = await res.json();
                box.innerHTML = data.success ? (data.html || '<span class="text-muted">(vacío)</span>') : '<span class="text-muted">No se pudo generar la vista previa.</span>';
            } catch (e) {
                box.innerHTML = '<span class="text-muted">No se pudo generar la vista previa.</span>';
            }
        }

        wrap.querySelectorAll('[data-ins]').forEach(btn => btn.addEventListener('click', () => {
            const k = btn.dataset.ins;
            const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
            if (k === 'preview') { preview(); return; }
            if (k === 'h2') insertAtCursor(ta, '<h2>' + (sel || 'Título') + '</h2>\n');
            else if (k === 'bold') insertAtCursor(ta, '<strong>' + (sel || 'texto') + '</strong>');
            else if (k === 'list') insertAtCursor(ta, '<ul>\n  <li>' + (sel || 'Primer punto') + '</li>\n  <li>Segundo punto</li>\n</ul>\n');
            else if (k === 'table') insertAtCursor(ta, '<table>\n  <tr><th>Columna 1</th><th>Columna 2</th></tr>\n  <tr><td>Dato</td><td>Dato</td></tr>\n</table>\n');
            else if (k === 'link') {
                const url = prompt('Dirección del enlace (https://...):');
                if (url) insertAtCursor(ta, '<a href="' + esc(url.trim()) + '">' + esc(sel || prompt('Texto del enlace:', 'Haz clic aquí') || url) + '</a>');
            } else if (k === 'image') {
                const url = prompt('Dirección de la imagen (https://...):');
                if (url) insertAtCursor(ta, '<p><img src="' + esc(url.trim()) + '" alt="' + esc(prompt('Descripción breve de la imagen (opcional):', '') || '') + '"></p>\n');
            } else if (k === 'video') {
                const url = prompt('Enlace del video (YouTube, Vimeo, Google Drive o archivo .mp4):');
                if (!url) return;
                const html = videoHtml(url);
                if (html) insertAtCursor(ta, '<p>' + html + '</p>\n');
                else alert('No se reconoció ese enlace. Usa un enlace https de YouTube, Vimeo o Google Drive, o un archivo .mp4.');
            }
        }));
    }

    window.AulaHtmlDesc = { mount };
})();
