/**
 * image_upload.js
 * Subida de imágenes para los editores de los estudiantes.
 *  - Reduce la foto ANTES de subirla (las fotos de celular pesan 4-10 MB y el servidor gratuito las rechazaba).
 *  - Muestra mensajes claros cuando algo falla (antes la imagen simplemente no aparecía).
 * Uso: const r = await AulaImages.upload(file, { appUrl, projectId, csrf });  // -> { ok, url, message }
 */
(function (global) {
    'use strict';

    const MAX_SIDE = 1600;          // lado mayor máximo en píxeles
    const SKIP_BELOW = 600 * 1024;  // si ya pesa menos de esto y no es enorme, se sube tal cual

    function loadBitmap(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('decode')); };
            img.src = url;
        });
    }

    /** Devuelve un File ya reducido (JPEG/PNG) o el original si no hace falta / no se puede reducir. */
    async function prepare(file) {
        if (!file || !/^image\/(jpeg|png|webp)$/i.test(file.type)) return file; // GIF se sube tal cual (animación)
        try {
            const img = await loadBitmap(file);
            const longest = Math.max(img.naturalWidth, img.naturalHeight);
            if (file.size <= SKIP_BELOW && longest <= MAX_SIDE) return file;

            const scale = Math.min(1, MAX_SIDE / longest);
            const w = Math.max(1, Math.round(img.naturalWidth * scale));
            const h = Math.max(1, Math.round(img.naturalHeight * scale));
            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d');
            const keepPng = file.type === 'image/png';
            if (!keepPng) { ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, w, h); }
            ctx.drawImage(img, 0, 0, w, h);

            const type = keepPng ? 'image/png' : 'image/jpeg';
            const blob = await new Promise(res => canvas.toBlob(res, type, 0.85));
            if (!blob || blob.size >= file.size) return file; // no mejoró: se deja el original
            const base = (file.name || 'imagen').replace(/\.[^.]+$/, '');
            return new File([blob], base + (keepPng ? '.png' : '.jpg'), { type });
        } catch (e) {
            return file;
        }
    }

    /** Sube la imagen al servidor. Siempre devuelve { ok, url, message } (nunca lanza). */
    async function upload(file, opts) {
        try {
            const prepared = await prepare(file);
            const fd = new FormData();
            fd.append('image', prepared, prepared.name || 'imagen.jpg');
            fd.append('project_id', opts.projectId || 0);
            fd.append('csrf_token', opts.csrf);

            // Siempre se sube al MISMO sitio desde el que se abrió la página (solo se toma la carpeta de APP_URL),
            // así la sesión y las cookies viajan aunque APP_URL difiera (http/https, con o sin www).
            let base = '';
            try { base = new URL(opts.appUrl, window.location.href).pathname.replace(/\/$/, ''); } catch (e) { base = ''; }
            const res = await fetch(base + '/api/projects/upload_image.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            let data = null;
            try { data = await res.json(); } catch (e) { /* respuesta no JSON */ }

            if (data && data.success && data.url) return { ok: true, url: data.url, name: data.name || file.name };
            if (data && data.message) return { ok: false, message: data.message };
            if (res.status === 413) return { ok: false, message: 'La imagen es demasiado pesada. Prueba con una más pequeña.' };
            return { ok: false, message: 'No se pudo subir la imagen (error ' + res.status + '). Intenta de nuevo.' };
        } catch (err) {
            return { ok: false, message: 'No se pudo subir la imagen. Revisa tu conexión a internet.' };
        }
    }

    /** Comprueba que la URL realmente carga como imagen (para no dejar un objeto invisible en el lienzo). */
    function canLoad(url) {
        return new Promise(resolve => {
            const i = new Image();
            i.onload = () => resolve(i.naturalWidth > 0);
            i.onerror = () => resolve(false);
            i.src = url;
        });
    }

    global.AulaImages = { prepare, upload, canLoad };
})(window);
