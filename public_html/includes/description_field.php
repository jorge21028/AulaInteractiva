<?php
/**
 * description_field.php
 * Campo "Descripción" con selector Texto sencillo / HTML, barra de ayuda y vista previa.
 * Envía los campos  {name}  y  {name}_format  (text|html).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/description_helpers.php';

/**
 * @param string $name   nombre del campo (p. ej. "description")
 * @param string $label  etiqueta visible
 * @param string $value  contenido actual
 * @param string $format 'text' | 'html'
 */
function render_description_field(string $name, string $label, string $value = '', string $format = 'text', int $rows = 4): void
{
    $format = desc_normalize_format($format);
    $id = 'df_' . preg_replace('/[^a-z0-9_]/i', '', $name);
    $previewUrl = (parse_url(APP_URL, PHP_URL_PATH) ?: '') . '/api/teacher/preview_description.php';
    $scriptUrl = (parse_url(APP_URL, PHP_URL_PATH) ?: '') . '/assets/js/html_description.js';
    static $scriptLoaded = false;
    ?>
    <div class="desc-field" id="<?= e($id) ?>_wrap" data-preview-url="<?= e($previewUrl) ?>" data-csrf="<?= e(csrf_token()) ?>">
        <label for="<?= e($id) ?>"><?= e($label) ?></label>
        <div style="display:flex; gap:14px; flex-wrap:wrap; margin:0 0 6px; font-size:0.9rem;">
            <label style="display:flex; gap:6px; align-items:center; font-weight:400; margin:0;">
                <input type="radio" name="<?= e($name) ?>_format" value="text" style="width:auto;" <?= $format === 'text' ? 'checked' : '' ?>> Texto sencillo
            </label>
            <label style="display:flex; gap:6px; align-items:center; font-weight:400; margin:0;">
                <input type="radio" name="<?= e($name) ?>_format" value="html" style="width:auto;" <?= $format === 'html' ? 'checked' : '' ?>> HTML (imágenes, videos, enlaces, tablas…)
            </label>
        </div>

        <div class="desc-tools" style="display:none; gap:6px; flex-wrap:wrap; margin-bottom:6px;">
            <button type="button" class="btn btn-secondary" data-ins="h2"     style="margin:0; padding:4px 10px; font-size:0.8rem;">Título</button>
            <button type="button" class="btn btn-secondary" data-ins="bold"   style="margin:0; padding:4px 10px; font-size:0.8rem;"><b>N</b></button>
            <button type="button" class="btn btn-secondary" data-ins="list"   style="margin:0; padding:4px 10px; font-size:0.8rem;">• Lista</button>
            <button type="button" class="btn btn-secondary" data-ins="link"   style="margin:0; padding:4px 10px; font-size:0.8rem;">🔗 Enlace</button>
            <button type="button" class="btn btn-secondary" data-ins="image"  style="margin:0; padding:4px 10px; font-size:0.8rem;">🖼️ Imagen (URL)</button>
            <button type="button" class="btn btn-secondary" data-ins="video"  style="margin:0; padding:4px 10px; font-size:0.8rem;">▶️ Video (URL)</button>
            <button type="button" class="btn btn-secondary" data-ins="table"  style="margin:0; padding:4px 10px; font-size:0.8rem;">▦ Tabla</button>
            <button type="button" class="btn btn-secondary" data-ins="preview" style="margin:0; padding:4px 10px; font-size:0.8rem;">👁️ Vista previa</button>
        </div>

        <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= (int) $rows ?>"><?= e($value) ?></textarea>

        <p class="text-muted desc-help" style="display:none; font-size:0.8rem; margin:4px 0 8px;">
            Puedes pegar HTML o usar los botones. Videos: pega el enlace de YouTube, Vimeo o Google Drive y se incrusta solo.
            Imágenes y enlaces pueden estar en otros sitios. Por seguridad se quitan los scripts, formularios y contenido de sitios no permitidos.
        </p>
        <div class="desc-preview card" style="display:none; margin:6px 0 10px; background:#fff;"></div>
    </div>
    <?php if (!$scriptLoaded): $scriptLoaded = true; ?>
        <script src="<?= e($scriptUrl) ?>"></script>
    <?php endif; ?>
    <script>AulaHtmlDesc.mount(document.getElementById(<?= json_encode($id . '_wrap') ?>));</script>
    <?php
}
