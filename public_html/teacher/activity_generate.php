<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$errors = [];

$subjStmt = $pdo->prepare(
    'SELECT s.id, s.name, c.name AS course_name
     FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE tc.teacher_id = :teacher_id
     ORDER BY c.name, s.name'
);
$subjStmt->execute(['teacher_id' => $teacherId]);
$subjects = $subjStmt->fetchAll();

// ---- Guardar la actividad revisada por el profesor como borrador ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_draft') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $validSubject = false;
    foreach ($subjects as $s) {
        if ((int) $s['id'] === $subjectId) {
            $validSubject = true;
            break;
        }
    }

    $decoded = json_decode($_POST['generated_json'] ?? '', true);

    if (!$validSubject) {
        $errors[] = 'Selecciona una asignatura válida.';
    } elseif (!is_array($decoded) || empty($decoded['preguntas']) || !is_array($decoded['preguntas'])) {
        $errors[] = 'No hay una actividad generada válida para guardar. Genera una actividad primero.';
    } else {
        $titulo = clean_string($decoded['titulo'] ?? 'Actividad generada con IA');
        $descripcion = clean_string($decoded['descripcion'] ?? '');

        $pdo->beginTransaction();
        try {
            $insertAct = $pdo->prepare(
                'INSERT INTO activities (subject_id, teacher_id, title, description, difficulty, time_per_question, points_base, speed_bonus_max, ranking_enabled, allow_repeat, team_mode, source, status, created_at)
                 VALUES (:subject_id, :teacher_id, :title, :description, :difficulty, 20, 100, 50, 1, 1, 0, :source, :status, :created_at)'
            );
            $insertAct->execute([
                'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'title' => $titulo,
                'description' => $descripcion, 'difficulty' => 'media', 'source' => 'gemini',
                'status' => 'draft', 'created_at' => now_datetime(),
            ]);
            $activityId = (int) $pdo->lastInsertId();

            $insertQ = $pdo->prepare(
                'INSERT INTO activity_questions (activity_id, type, statement, time_seconds, points, explanation, order_index, created_at)
                 VALUES (:activity_id, :type, :statement, :time_seconds, :points, :explanation, :order_index, :created_at)'
            );
            $insertOpt = $pdo->prepare(
                'INSERT INTO question_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, :correct, :order_index)'
            );

            $orderIndex = 0;
            foreach ($decoded['preguntas'] as $q) {
                if (!is_array($q)) {
                    continue;
                }
                $type = in_array($q['tipo'] ?? '', ['multiple', 'truefalse'], true) ? $q['tipo'] : 'multiple';
                $statement = clean_string($q['enunciado'] ?? '');
                if ($statement === '') {
                    continue;
                }
                $timeSeconds = max(5, min(120, (int) ($q['tiempo'] ?? 20)));
                $points = max(10, min(1000, (int) ($q['puntos'] ?? 100)));
                $explanation = clean_string($q['explicacion'] ?? '');
                $opciones = is_array($q['opciones'] ?? null) ? $q['opciones'] : [];

                if (count($opciones) < 2) {
                    continue;
                }

                $insertQ->execute([
                    'activity_id' => $activityId, 'type' => $type, 'statement' => $statement,
                    'time_seconds' => $timeSeconds, 'points' => $points, 'explanation' => $explanation,
                    'order_index' => $orderIndex, 'created_at' => now_datetime(),
                ]);
                $questionId = (int) $pdo->lastInsertId();

                $correctCount = 0;
                foreach ($opciones as $o) {
                    if (!empty($o['correcta'])) {
                        $correctCount++;
                    }
                }

                foreach ($opciones as $optIndex => $o) {
                    $text = clean_string($o['texto'] ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $isCorrect = $correctCount === 1 ? (bool) ($o['correcta'] ?? false) : ($optIndex === 0);
                    $insertOpt->execute([
                        'qid' => $questionId, 'text' => $text,
                        'correct' => $isCorrect ? 1 : 0, 'order_index' => $optIndex,
                    ]);
                }

                $orderIndex++;
            }

            if ($orderIndex === 0) {
                throw new RuntimeException('Ninguna pregunta válida para guardar.');
            }

            $pdo->commit();
            audit_log($pdo, $teacherId, 'create_activity_gemini', "Actividad '{$titulo}' guardada como borrador desde Gemini");
            redirect('teacher/activity_edit.php?id=' . $activityId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('save_draft (gemini) error: ' . $e->getMessage());
            $errors[] = 'No fue posible guardar la actividad generada.';
        }
    }
}

$pageTitle = 'Generar actividad con Gemini';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="activities.php">&larr; Volver a mis actividades</a></p>
<h1>Generar actividad con Gemini</h1>
<p class="text-muted">
    La IA solo te ayuda a redactar las preguntas. Tú decides si las conservas, las editas o las descartas,
    y nada se publica hasta que tú lo hagas explícitamente.
</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if (empty($subjects)): ?>
    <div class="card">
        <p>Necesitas al menos una asignatura antes de generar actividades.</p>
        <a class="btn" href="dashboard.php">Ir a mis cursos</a>
    </div>
<?php else: ?>
    <div class="grid grid-2">
        <section class="card">
            <h2 style="margin-top:0;">1. Parámetros</h2>
            <form id="gen-form">
                <label for="subject_id">Asignatura</label>
                <select id="subject_id" name="subject_id" required>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= e($s['course_name']) ?> — <?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="tema">Tema</label>
                <input type="text" id="tema" name="tema" required placeholder="Ej: Modelo entidad-relación">

                <label for="descripcion">Descripción / contexto</label>
                <textarea id="descripcion" name="descripcion" rows="2" placeholder="Contexto adicional para la IA (opcional)"></textarea>

                <label for="objetivo">Objetivo de aprendizaje</label>
                <input type="text" id="objetivo" name="objetivo" placeholder="Ej: Identificar entidades y relaciones">

                <label for="cantidad">Cantidad de preguntas</label>
                <input type="text" id="cantidad" name="cantidad" value="10">

                <label for="dificultad">Dificultad</label>
                <select id="dificultad" name="dificultad">
                    <option value="facil">Fácil</option>
                    <option value="media" selected>Media</option>
                    <option value="dificil">Difícil</option>
                </select>

                <label for="tipo">Tipo de preguntas</label>
                <select id="tipo" name="tipo">
                    <option value="mixto">Mixto (selección múltiple y verdadero/falso)</option>
                    <option value="multiple">Solo selección múltiple</option>
                    <option value="truefalse">Solo verdadero/falso</option>
                </select>

                <label for="tiempo">Tiempo por pregunta (segundos)</label>
                <input type="text" id="tiempo" name="tiempo" value="20">

                <label for="instrucciones_adicionales">Instrucciones adicionales</label>
                <textarea id="instrucciones_adicionales" name="instrucciones_adicionales" rows="2" placeholder="Opcional"></textarea>

                <button type="submit" class="btn" id="btn-generate" style="width:100%;">Generar con Gemini</button>
            </form>
            <div id="gen-status" style="margin-top:12px;"></div>
        </section>

        <section class="card">
            <h2 style="margin-top:0;">2. Vista previa</h2>
            <div id="preview">
                <p class="empty-state">Genera una actividad para ver la vista previa aquí.</p>
            </div>

            <form method="post" action="activity_generate.php" id="save-form" style="display:none; margin-top:16px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save_draft">
                <input type="hidden" name="subject_id" id="save_subject_id">
                <input type="hidden" name="generated_json" id="save_generated_json">
                <button type="submit" class="btn" style="width:100%;">Guardar como borrador</button>
                <p class="text-muted" style="font-size:0.8rem; text-align:center; margin-top:8px;">
                    Se guardará como borrador. Podrás editar preguntas y opciones antes de publicarla.
                </p>
            </form>
        </section>
    </div>

    <script>
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    let lastGenerated = null;

    document.getElementById('gen-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('btn-generate');
        const status = document.getElementById('gen-status');
        const preview = document.getElementById('preview');

        btn.disabled = true;
        btn.textContent = 'Generando... (puede tardar unos segundos)';
        status.innerHTML = '';
        preview.innerHTML = '<p class="text-muted">Consultando a Gemini...</p>';

        const payload = Object.fromEntries(new FormData(form).entries());
        payload.csrf_token = CSRF_TOKEN;

        try {
            const res = await fetch('<?= e(rtrim(APP_URL, '/')) ?>/api/gemini/generate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();

            if (!data.success) {
                preview.innerHTML = '<p class="empty-state">No se generó ninguna actividad.</p>';
                status.innerHTML = `<div class="alert alert-error">${data.error}</div>`;
            } else {
                lastGenerated = data.activity;
                renderPreview(data.activity);
                document.getElementById('save_subject_id').value = payload.subject_id;
                document.getElementById('save_generated_json').value = JSON.stringify(data.activity);
                document.getElementById('save-form').style.display = 'block';
            }
        } catch (err) {
            preview.innerHTML = '<p class="empty-state">No se generó ninguna actividad.</p>';
            status.innerHTML = '<div class="alert alert-error">No fue posible generar la actividad. Intenta nuevamente.</div>';
        }

        btn.disabled = false;
        btn.textContent = 'Generar con Gemini';
    });

    function renderPreview(activity) {
        const preview = document.getElementById('preview');
        let html = `<h3 style="margin-top:0;">${escapeHtml(activity.titulo)}</h3>`;
        html += `<p class="text-muted">${escapeHtml(activity.descripcion || '')}</p>`;
        html += `<p class="text-muted" style="font-size:0.85rem;">${activity.preguntas.length} preguntas generadas</p>`;

        activity.preguntas.forEach((q, i) => {
            html += `<div class="card" style="padding:12px; margin-bottom:8px;">`;
            html += `<strong>${i + 1}. ${escapeHtml(q.enunciado)}</strong>`;
            html += `<p class="text-muted" style="font-size:0.8rem; margin:4px 0;">${q.tipo === 'multiple' ? 'Selección múltiple' : 'Verdadero/Falso'} · ${q.tiempo}s · ${q.puntos} pts</p>`;
            html += '<ul style="margin:6px 0; padding-left:20px;">';
            q.opciones.forEach(o => {
                html += `<li style="${o.correcta ? 'color:var(--color-success); font-weight:600;' : ''}">${escapeHtml(o.texto)}${o.correcta ? ' ✓' : ''}</li>`;
            });
            html += '</ul>';
            if (q.explicacion) {
                html += `<p class="text-muted" style="font-size:0.8rem;">💡 ${escapeHtml(q.explicacion)}</p>`;
            }
            html += `</div>`;
        });

        preview.innerHTML = html;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
