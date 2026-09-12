// main.js — utilidades ligeras de UX. Ninguna validación aquí sustituye
// a la validación del lado del servidor (ver includes/security.php).

document.addEventListener('DOMContentLoaded', () => {
    // Confirmación genérica para acciones destructivas (data-confirm="mensaje")
    document.querySelectorAll('[data-confirm]').forEach((el) => {
        el.addEventListener('click', (e) => {
            const msg = el.getAttribute('data-confirm') || '¿Estás seguro?';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });
});
