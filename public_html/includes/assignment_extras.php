<?php
/**
 * assignment_extras.php — Opciones avanzadas de una asignación de "trabajo de creación" (Fase 22):
 * entrega tardía con penalización, trabajo en grupos, plantilla inicial y ajustes del foro.
 * Lo usan teacher/assignments.php (crear) y teacher/assignment_edit.php (editar).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/templates_helpers.php';

function assignment_extras_defaults(?array $a = null): array
{
    return [
        'late_allowed' => (int) ($a['late_allowed'] ?? 0),
        'late_penalty_pct' => (int) ($a['late_penalty_pct'] ?? 10),
        'late_max_days' => (int) ($a['late_max_days'] ?? 3),
        'block_paste' => (int) ($a['block_paste'] ?? 0),
        'group_mode' => (int) ($a['group_mode'] ?? 0),
        'group_size' => (int) ($a['group_size'] ?? 3),
        'template_key' => (string) ($a['template_key'] ?? ''),
        'forum_mode' => (string) ($a['forum_mode'] ?? 'general'),
        'forum_min_threads' => (int) ($a['forum_min_threads'] ?? 1),
        'forum_min_replies' => (int) ($a['forum_min_replies'] ?? 2),
        'forum_students_start' => (int) ($a['forum_students_start'] ?? 1),
    ];
}

/** Lee y valida los campos del formulario. Devuelve [extras, errores]. */
function assignment_extras_from_post(array $post, string $projectType): array
{
    $x = assignment_extras_defaults();
    $errors = [];
    $isForum = $projectType === 'foro';

    $x['late_allowed'] = !empty($post['late_allowed']) ? 1 : 0;
    $x['late_penalty_pct'] = (int) ($post['late_penalty_pct'] ?? 10);
    $x['late_max_days'] = (int) ($post['late_max_days'] ?? 3);
    if ($x['late_allowed'] && ($x['late_penalty_pct'] < 1 || $x['late_penalty_pct'] > 100)) {
        $errors[] = 'El descuento por día de retraso debe estar entre 1% y 100%.';
    }
    if ($x['late_allowed'] && ($x['late_max_days'] < 1 || $x['late_max_days'] > 60)) {
        $errors[] = 'Los días máximos de entrega tardía deben estar entre 1 y 60.';
    }

    $x['block_paste'] = !empty($post['block_paste']) ? 1 : 0;
    $x['group_mode'] = (!$isForum && !empty($post['group_mode'])) ? 1 : 0;
    $x['group_size'] = max(2, min(12, (int) ($post['group_size'] ?? 3)));

    $key = (string) ($post['template_key'] ?? '');
    $x['template_key'] = (!$isForum && isset(templates_catalog()[$projectType][$key])) ? $key : '';

    if ($isForum) {
        $x['forum_mode'] = (($post['forum_mode'] ?? '') === 'qa') ? 'qa' : 'general';
        $x['forum_min_threads'] = max(0, min(20, (int) ($post['forum_min_threads'] ?? 1)));
        $x['forum_min_replies'] = max(0, min(50, (int) ($post['forum_min_replies'] ?? 2)));
        $x['forum_students_start'] = !empty($post['forum_students_start']) ? 1 : 0;
        if ($x['forum_min_threads'] + $x['forum_min_replies'] === 0) {
            $errors[] = 'El foro debe pedir al menos un tema o una respuesta para considerarse completado.';
        }
        if ($x['forum_min_threads'] > 0 && !$x['forum_students_start']) {
            $errors[] = 'Si los estudiantes no pueden abrir temas, pon 0 temas mínimos.';
        }
    }
    return [$x, $errors];
}

function assignment_extras_save(PDO $pdo, int $assignmentId, array $x, bool $includeGroup = true): void
{
    $sql = 'UPDATE assignments SET late_allowed = :la, late_penalty_pct = :lp, late_max_days = :lm, template_key = :tk,
                   forum_mode = :fm, forum_min_threads = :ft, forum_min_replies = :fr, forum_students_start = :fs, block_paste = :bp'
         . ($includeGroup ? ', group_mode = :gm, group_size = :gs' : '') . ' WHERE id = :id';
    $params = [
        'la' => $x['late_allowed'], 'lp' => $x['late_penalty_pct'], 'lm' => $x['late_max_days'], 'tk' => $x['template_key'] ?: null,
        'fm' => $x['forum_mode'], 'ft' => $x['forum_min_threads'], 'fr' => $x['forum_min_replies'], 'fs' => $x['forum_students_start'],
        'bp' => $x['block_paste'], 'id' => $assignmentId,
    ];
    if ($includeGroup) {
        $params['gm'] = $x['group_mode'];
        $params['gs'] = $x['group_size'];
    }
    $pdo->prepare($sql)->execute($params);
}

/**
 * Dibuja las opciones. $fixedType = tipo ya definido (edición); null = se toma del selector #project_type (creación).
 * $lockGroup = no permitir cambiar el modo grupo (ya hay trabajos empezados).
 */
function assignment_extras_render(array $x, ?string $fixedType = null, bool $lockGroup = false): void
{
    $catalog = [];
    foreach (templates_catalog() as $type => $list) {
        foreach ($list as $key => [$name]) {
            $catalog[$type][] = ['key' => $key, 'name' => $name];
        }
    }
    ?>
    <div id="extras-box" style="border:1px solid var(--color-border); border-radius:12px; padding:12px 14px; margin:14px 0; background:#FAFBFD;">
        <strong>⚙️ Opciones avanzadas</strong>

        <div id="extras-forum" style="display:none; margin-top:10px;">
            <label>Modo del foro</label>
            <select name="forum_mode">
                <option value="general" <?= $x['forum_mode'] === 'general' ? 'selected' : '' ?>>Foro general — todos ven todo</option>
                <option value="qa" <?= $x['forum_mode'] === 'qa' ? 'selected' : '' ?>>Pregunta-respuesta — el estudiante ve lo de los demás después de publicar su tema</option>
            </select>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <div style="flex:1; min-width:140px;"><label>Temas mínimos por estudiante</label><input type="number" min="0" max="20" name="forum_min_threads" value="<?= (int) $x['forum_min_threads'] ?>"></div>
                <div style="flex:1; min-width:140px;"><label>Respuestas mínimas</label><input type="number" min="0" max="50" name="forum_min_replies" value="<?= (int) $x['forum_min_replies'] ?>"></div>
            </div>
            <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
                <input type="checkbox" name="forum_students_start" value="1" style="width:auto;" <?= $x['forum_students_start'] ? 'checked' : '' ?>> Los estudiantes pueden abrir temas nuevos
            </label>
            <p class="text-muted" style="font-size:0.8rem; margin:4px 0 0;">El foro se marca como completado cuando el estudiante llega al mínimo. Tú calificas su participación.</p>
        </div>

        <div style="margin-top:10px;">
            <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
                <input type="checkbox" name="block_paste" value="1" style="width:auto;" <?= $x['block_paste'] ? 'checked' : '' ?>> 🔒 Bloquear "pegar" (Ctrl+V y clic derecho)
            </label>
            <p class="text-muted" style="font-size:0.8rem; margin:2px 0 0;">Los estudiantes tendrán que escribir con sus propias palabras. Puedes activarlo o desactivarlo cuando quieras, también desde la pantalla de la asignación.</p>
        </div>

        <div id="extras-template" style="margin-top:10px;">
            <label for="template_key">Plantilla inicial (opcional)</label>
            <select id="template_key" name="template_key"><option value="">Sin plantilla (lienzo en blanco)</option></select>
            <p class="text-muted" style="font-size:0.8rem; margin:2px 0 0;">Los estudiantes empiezan con esta estructura y pueden cambiarla desde el editor.</p>
        </div>

        <div id="extras-group" style="margin-top:10px;">
            <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
                <input type="checkbox" name="group_mode" value="1" style="width:auto;" <?= $x['group_mode'] ? 'checked' : '' ?> <?= $lockGroup ? 'disabled' : '' ?>> 👥 Trabajo en grupos
            </label>
            <?php if ($lockGroup): ?><p class="text-muted" style="font-size:0.8rem; margin:2px 0 0;">No se puede cambiar: ya hay trabajos empezados.</p><?php endif; ?>
            <div style="max-width:220px;"><label>Tamaño sugerido del grupo</label><input type="number" min="2" max="12" name="group_size" value="<?= (int) $x['group_size'] ?>" <?= $lockGroup ? 'disabled' : '' ?>></div>
            <p class="text-muted" style="font-size:0.8rem; margin:2px 0 0;">Los grupos se arman desde la pantalla de la asignación. Un solo trabajo y una sola calificación por grupo.</p>
        </div>

        <div style="margin-top:10px;">
            <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
                <input type="checkbox" id="late_allowed" name="late_allowed" value="1" style="width:auto;" <?= $x['late_allowed'] ? 'checked' : '' ?>> ⏰ Permitir entregas tardías con penalización
            </label>
            <div id="late-fields" style="display:<?= $x['late_allowed'] ? 'flex' : 'none' ?>; gap:12px; flex-wrap:wrap;">
                <div style="flex:1; min-width:150px;"><label>% que se descuenta por día</label><input type="number" min="1" max="100" name="late_penalty_pct" value="<?= (int) $x['late_penalty_pct'] ?>"></div>
                <div style="flex:1; min-width:150px;"><label>Se recibe hasta (días después)</label><input type="number" min="1" max="60" name="late_max_days" value="<?= (int) $x['late_max_days'] ?>"></div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var catalog = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;
        var fixed = <?= json_encode($fixedType) ?>;
        var current = <?= json_encode($x['template_key']) ?>;
        var typeSel = document.getElementById('project_type');
        var tplSel = document.getElementById('template_key');
        function sync() {
            var type = fixed || (typeSel ? typeSel.value : '');
            var isForum = type === 'foro';
            var list = catalog[type] || [];
            document.getElementById('extras-forum').style.display = isForum ? '' : 'none';
            document.getElementById('extras-group').style.display = isForum ? 'none' : '';
            document.getElementById('extras-template').style.display = list.length ? '' : 'none';
            var keep = tplSel.value || current;
            tplSel.length = 1;
            list.forEach(function (t) { var o = document.createElement('option'); o.value = t.key; o.textContent = t.name; if (t.key === keep) o.selected = true; tplSel.appendChild(o); });
        }
        if (typeSel) typeSel.addEventListener('change', function () { current = ''; tplSel.value = ''; sync(); });
        document.getElementById('late_allowed').addEventListener('change', function () { document.getElementById('late-fields').style.display = this.checked ? 'flex' : 'none'; });
        sync();
    })();
    </script>
    <?php
}
