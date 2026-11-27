<?php
/**
 * Exportar actividades de una asignatura a CSV, eligiendo cuáles.
 * Columnas: Nombre actividad, Descripción, Valor, Tipo de actividad
 * (opcional: una columna extra por estudiante con su calificación).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/space_helpers.php';
require_once __DIR__ . '/../includes/report_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$subjectId = (int) ($_GET['subject'] ?? $_POST['subject'] ?? 0);

$subject = space_load_subject($pdo, $subjectId, $teacherId);
if (!$subject) {
    http_response_code(404);
    exit('Asignatura no encontrada.');
}

/** Descripción en texto plano (sin etiquetas HTML, en una sola línea). */
function export_plain_text(?string $text): string
{
    $text = (string) $text;
    $text = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6])\s*/?>#i', ' ', $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/** Fecha de la actividad: su fecha límite de entrega; si no tiene, la fecha en que se creó. Formato dd/mm/aaaa. */
function export_date(array $a): string
{
    $raw = !empty($a['due_date']) ? $a['due_date'] : ($a['created_at'] ?? '');
    $ts = $raw !== '' ? strtotime($raw) : false;
    return $ts ? date('d/m/Y', $ts) : '';
}

/** Tipo de actividad, corto y claro (sin el título del cuestionario o de la actividad). */
function export_kind_label(array $a): string
{
    if (!empty($a['activity_id'])) {
        return game_mode_label($a['game_mode'] ?? 'trivia');
    }
    if (!empty($a['quiz_id'])) {
        return 'Cuestionario';
    }
    return PROJECT_TYPES[$a['project_type']] ?? 'Trabajo de creación';
}

// recovery_group_id puede no existir si aún no se hizo la migración de recuperaciones
$cols = recovery_ready($pdo) ? 'a.recovery_group_id' : 'NULL AS recovery_group_id';
$stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.description, a.description_format, a.points, a.project_type, a.activity_id, a.quiz_id, a.due_date, a.created_at,
            {$cols}, act.game_mode
     FROM assignments a
     LEFT JOIN activities act ON act.id = a.activity_id
     WHERE a.subject_id = :sid AND a.teacher_id = :tid
     ORDER BY COALESCE(a.due_date, a.created_at) DESC, a.id DESC"
);
$stmt->execute(['sid' => $subjectId, 'tid' => $teacherId]);
$all = $stmt->fetchAll();
$byId = [];
foreach ($all as $a) {
    $byId[(int) $a['id']] = $a;
}

$errors = [];

// ---------------------------------------------------------------- descarga
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $picked = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($id) => isset($byId[$id])));
    if (empty($picked)) {
        $errors[] = 'Marca al menos una actividad para exportar.';
    } else {
        $delimiter = ($_POST['delimiter'] ?? ';') === ',' ? ',' : ';';
        $withGrades = !empty($_POST['with_grades']);
        // Respetar el orden de la lista (fecha más reciente primero), no el del formulario
        $selected = array_values(array_filter($all, fn($a) => in_array((int) $a['id'], $picked, true)));

        $students = [];
        $scores = [];
        if ($withGrades) {
            $st = $pdo->prepare(
                'SELECT u.id, u.name FROM course_students cs INNER JOIN users u ON u.id = cs.student_id WHERE cs.course_id = :cid ORDER BY u.name ASC'
            );
            $st->execute(['cid' => $subject['course_id']]);
            $students = $st->fetchAll();
            $in = implode(',', array_map('intval', $picked));
            foreach ($pdo->query("SELECT assignment_id, student_id, score FROM submissions WHERE assignment_id IN ({$in})")->fetchAll() as $s) {
                $scores[(int) $s['assignment_id']][(int) $s['student_id']] = $s['score'];
            }
        }

        $fileName = 'actividades_' . preg_replace('/[^A-Za-z0-9]+/', '_', $subject['course_name'] . '_' . $subject['name']) . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        echo "\xEF\xBB\xBF"; // BOM: Excel reconoce las tildes
        $out = fopen('php://output', 'w');
        $head = ['Nombre actividad', 'Descripción', 'Valor', 'Tipo de actividad', 'Fecha'];
        foreach ($students as $stu) {
            $head[] = report_csv_safe($stu['name']);
        }
        fputcsv($out, $head, $delimiter);
        foreach ($selected as $a) {
            $line = [
                report_csv_safe($a['title']),
                report_csv_safe(export_plain_text($a['description'])),
                (int) $a['points'],
                export_kind_label($a),
                export_date($a),
            ];
            foreach ($students as $stu) {
                $sc = $scores[(int) $a['id']][(int) $stu['id']] ?? null;
                $line[] = $sc === null ? '' : report_fmt_score((float) $sc);
            }
            fputcsv($out, $line, $delimiter);
        }
        fclose($out);
        exit;
    }
}

// ---------------------------------------------------------------- selección
$pageTitle = 'Exportar actividades · ' . $subject['name'];
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">

<nav class="crumbs" aria-label="Ruta">
    <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
    <a href="course.php?id=<?= (int) $subject['course_id'] ?>"><?= e($subject['course_name']) ?></a><span class="sep">›</span>
    <a href="subject.php?id=<?= (int) $subjectId ?>"><?= e($subject['name']) ?></a><span class="sep">›</span>
    <strong>Exportar CSV</strong>
</nav>

<div class="space-head">
    <div>
        <h1>⬇️ Exportar actividades a CSV</h1>
        <p class="space-sub"><?= e($subject['course_name']) ?> — <?= e($subject['name']) ?></p>
    </div>
</div>

<?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (empty($all)): ?>
    <div class="empty-box">Esta asignatura todavía no tiene actividades.</div>
<?php else: ?>
    <p class="text-muted" style="max-width:760px;">
        Elige las actividades. El archivo tendrá estas columnas: <strong>Nombre actividad, Descripción, Valor, Tipo de actividad, Fecha</strong>
        (el valor son los puntos; la fecha es la de entrega de la actividad, o la de creación si no tiene). Van ordenadas de la más reciente a la más antigua.
    </p>

    <form method="post" id="export-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="subject" value="<?= (int) $subjectId ?>">

        <div class="card" style="padding:12px 14px; margin-bottom:10px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <button type="button" class="btn btn-secondary" id="sel-all" style="margin:0;">Marcar todas</button>
            <button type="button" class="btn btn-secondary" id="sel-none" style="margin:0;">Desmarcar todas</button>
            <input type="search" id="act-filter" placeholder="🔎 Buscar actividad..." style="max-width:240px; margin:0;">
            <span class="text-muted" id="sel-count" style="margin-left:auto;"></span>
        </div>

        <div class="card" style="padding:4px 14px; margin-bottom:10px;">
            <?php foreach ($all as $a): ?>
                <label class="act-row" data-title="<?= e(mb_strtolower($a['title'])) ?>"
                       style="display:flex; gap:10px; align-items:flex-start; padding:9px 0; border-bottom:1px solid var(--color-border); cursor:pointer;">
                    <input type="checkbox" class="act-cb" name="ids[]" value="<?= (int) $a['id'] ?>" style="width:auto; margin-top:4px;"
                        <?= (!empty($_POST['ids']) && in_array((string) $a['id'], array_map('strval', (array) $_POST['ids']), true)) ? 'checked' : '' ?>>
                    <span style="flex:1;">
                        <strong><?= e($a['title']) ?></strong>
                        <?php if (!empty($a['recovery_group_id'])): ?><span class="chip chip-amber">🔁 Recuperación</span><?php endif; ?>
                        <br><span class="text-muted" style="font-size:0.82rem;"><?= e(export_kind_label($a)) ?> · <?= (int) $a['points'] ?> pts · <?= e(export_date($a)) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="card" style="padding:12px 14px; margin-bottom:12px;">
            <label for="delimiter" style="margin-top:0;">Separador de columnas</label>
            <select id="delimiter" name="delimiter" style="max-width:340px;">
                <option value=";">Punto y coma ( ; ) — para Excel en español</option>
                <option value=",">Coma ( , ) — formato CSV estándar</option>
            </select>
            <label style="display:flex; gap:8px; align-items:center; font-weight:400; margin-top:10px;">
                <input type="checkbox" name="with_grades" value="1" style="width:auto;"> Agregar también las calificaciones de los estudiantes (una columna por estudiante)
            </label>
        </div>

        <div style="position:sticky; bottom:0; background:var(--color-surface); border-top:1px solid var(--color-border); padding:10px 0;">
            <button type="submit" class="btn">⬇️ Descargar CSV</button>
        </div>
    </form>

    <script>
    (function () {
        const cbs = () => Array.from(document.querySelectorAll('.act-cb'));
        const count = document.getElementById('sel-count');
        function refresh() { const n = cbs().filter(c => c.checked).length; count.textContent = n + ' de ' + cbs().length + ' seleccionadas'; }
        document.getElementById('export-form').addEventListener('change', refresh);
        document.getElementById('sel-all').addEventListener('click', () => { cbs().forEach(c => { if (c.closest('.act-row').style.display !== 'none') c.checked = true; }); refresh(); });
        document.getElementById('sel-none').addEventListener('click', () => { cbs().forEach(c => c.checked = false); refresh(); });
        document.getElementById('act-filter').addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.act-row').forEach(r => { r.style.display = r.dataset.title.indexOf(q) === -1 ? 'none' : 'flex'; });
        });
        refresh();
    })();
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
