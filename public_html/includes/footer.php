<?php
if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}
?>
</main>
<footer class="app-footer">
    <p>&copy; <?= date('Y') ?> AulaInteractiva. Fase 1 — base del proyecto.</p>
</footer>
<script src="<?= e(rtrim(APP_URL, '/')) ?>/assets/js/main.js"></script>
</body>
</html>
