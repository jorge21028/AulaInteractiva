<?php
if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}
?>
</main>
<footer class="app-footer no-print">
    <p><img src="<?= e(rtrim(APP_URL, '/')) ?>/assets/img/logo-icon.svg" alt="">&copy; <?= date('Y') ?> Dynamic SGA. Aprende. Crea. Participa.</p>
</footer>
<script src="<?= e(rtrim(APP_URL, '/')) ?>/assets/js/main.js"></script>
</body>
</html>
