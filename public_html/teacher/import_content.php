<?php
/**
 * Importar contenido (actividades interactivas y cuestionarios) desde un curso del profesor —activo o
 * inactivo— hacia una asignatura de un curso activo. Se puede importar TODO el curso o solo lo marcado.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/import_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$errors = [];
$done = null;

// Todos los cursos del profesor (origen) y asignaturas de cursos ACTIVOS (destino)
$allCourses = array_merge(courses_for_teacher($pdo, $teacherId, true), courses_for_teacher($pdo, $teacherId, false));

$destStmt = $pdo->prepare(
    'SELECT s.id, s.name, c.name AS course_name FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE tc.teacher_id = :tid AND c.is_active = 1
     ORDER BY c.name, s.name'
);
$destStmt->execute(['tid' => $teacherId]);
$destSubjects = $destStmt->fetchAll();

$destId = (int) ($_POST['dest_subject'] ?? $_GET['subject'] ?? 0);
$sourceId = (int) ($_POST['source_course'] ?? $_GET['source'] ?? 0);

$validDest = false;
foreach ($destSubjects as $d) {
    if ((int) $d['id'] === $destId) {
        $validDest = true;
        break;
    }
}
if (!$validDest) {
    $destId = 0;
}
$sourceCourse = $sourceId ? import_load_course($pdo, $sourceId, $teacherId) : null;
if (!$sourceCourse) {
    $sourceId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $scope = ($_POST['scope'] ?? 'selected') === 'all' ? 'all' : 'selected';
    $actIds = array_map('intval', (array) ($_POST['activity_ids'] ?? []));
    $quizIds = array_map('intval', (array) ($_POST['quiz_ids'] ?? []));

    if (!$destId) {
        $errors[] = 'Elige la asignatura a la que quieres importar.';
    }
    if (!$sourceId) {
        $errors[] = 'Elige el curso de origen.';
    }
    if ($scope === 'selected' && empty($actIds) && empty($quizIds)) {
        $errors[] = 'Marca al menos una actividad o cuestionario, o elige "Todo el contenido del curso".';
    }

    if (empty($errors)) {
        try {
            $done = import_run($pdo, $teacherId, $sourceId, $destId, $scope, $actIds, $quizIds);
            audit_log(
                $pdo, $teacherId, 'import_content',
                'Importadas ' . count($done['activities']) . ' actividad(es) y ' . count($done['quizzes']) . " cuestionario(s) del curso #{$sourceId} a la asignatura #{$destId}"
            );
        } catch (Throwable $e) {
            error_log('import_content error: ' . $e->getMessage());
            $errors[] = 'No fue posible importar el contenido. No se copió nada; intenta de nuevo.';
        }
    }
}

$source = ($sourceId && $destId) ? import_list_source($pdo, $sourceId, $teacherId) : null;
$destLabel = '';
foreach ($destSubjects as $d) {
    if ((int) $d['id'] === $destId) {
        $destLabel = $d['course_name'] . ' — ' . $d['name'];
    }
}

$pageTitle = 'Importar contenido';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="<?= $destId ? 'subject.php?id=' . (int) $destId : 'dashboard.php' ?>">&larr; Volver</a></p>
<h1>📥 Importar contenido</h1>
<p class="text-muted" style="margin-top:-8px;">
    Copia actividades interactivas y cuestionarios que ya creaste en otro curso —aunque esté inactivo— a una asignatura de tus cursos activos.
    Las copias son independientes del original y se importan sin partidas, entregas ni notas.
</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($done): ?>
    <div class="alert alert-success">
        ✅ Importación lista en <strong><?= e($destLabel) ?></strong>:
        <?= count($done['activities']) ?> actividad(es) y <?= count($done['quizzes']) ?> cuestionario(s).
    </div>
    <p>
        <a class="btn" href="subject.php?id=<?= (int) $destId ?>">Ir a la asignatura</a>
        <a class="btn btn-secondary" href="activities.php">Ver mis actividades</a>
        <a class="btn btn-secondary" href="quizzes.php">Ver mis cuestionarios</a>
        <a class="btn btn-secondary" href="assignments.php?subject=<?= (int) $destId ?>">➕ Asignarlas con nuevas fechas</a>
    </p>
<?php endif; ?>

<?php if (empty($destSubjects)): ?>
    <div class="card"><p>Necesitas al menos una asignatura en un curso activo para poder importar.</p></div>
<?php else: ?>
    <section class="card">
        <form method="get" action="import_content.php" class="form-narrow" style="margin:0;">
            <label for="dest_subject">1. ¿A qué asignatura lo quieres importar?</label>
            <select id="dest_subject" name="subject" required>
                <option value="">— Elige —</option>
                <?php foreach ($destSubjects as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $destId ? 'selected' : '' ?>><?= e($d['course_name']) ?> — <?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="source_course">2. ¿De qué curso lo tomas?</label>
            <select id="source_course" name="source" required>
                <option value="">— Elige —</option>
                <?php foreach ($allCourses as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $sourceId ? 'selected' : '' ?>>
                        <?= e($c['name']) ?><?= (int) $c['is_active'] === 1 ? '' : ' (inactivo)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Ver contenido del curso</button>
        </form>
    </section>

    <?php if ($source !== null): ?>
        <?php $total = count($source['activities']) + count($source['quizzes']); ?>
        <section class="card" style="margin-top:16px;">
            <h2 style="margin-top:0;">3. Qué importar de <?= e($sourceCourse['name']) ?></h2>
            <?php if ($total === 0): ?>
                <p class="empty-state">Este curso no tiene actividades ni cuestionarios tuyos para importar.</p>
            <?php else: ?>
                <form method="post" action="import_content.php" id="import-form">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="import">
                    <input type="hidden" name="dest_subject" value="<?= (int) $destId ?>">
                    <input type="hidden" name="source_course" value="<?= (int) $sourceId ?>">

                    <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
                        <input type="radio" name="scope" value="all" style="width:auto;" id="scope-all">
                        <span>📦 <strong>Todo el contenido del curso</strong> (<?= count($source['activities']) ?> actividad(es) y <?= count($source['quizzes']) ?> cuestionario(s))</span>
                    </label>
                    <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
                        <input type="radio" name="scope" value="selected" style="width:auto;" id="scope-selected" checked>
                        <span>☑️ <strong>Solo lo que yo marque</strong></span>
                    </label>

                    <div id="pick-box" style="margin-top:12px;">
                        <?php if (!empty($source['activities'])): ?>
                            <h3 style="margin-bottom:6px;">Actividades interactivas</h3>
                            <p style="margin:0 0 6px;"><a href="#" data-toggle="act">Marcar / desmarcar todas</a></p>
                            <?php foreach ($source['activities'] as $a): ?>
                                <label style="display:flex; gap:8px; align-items:center; font-weight:400; padding:6px 0; border-bottom:1px solid var(--color-border);">
                                    <input type="checkbox" name="activity_ids[]" value="<?= (int) $a['id'] ?>" data-group="act" style="width:auto;">
                                    <span>
                                        <?= e(game_mode_icon($a['game_mode'])) ?> <strong><?= e($a['title']) ?></strong>
                                        <span class="text-muted" style="font-size:0.85rem;">
                                            · <?= e($a['subject_name']) ?> · <?= e(game_mode_label($a['game_mode'])) ?> ·
                                            <?= (int) $a['items_count'] ?> <?= game_mode_is_word_game($a['game_mode']) ? 'palabras' : 'preguntas' ?> ·
                                            <?= $a['status'] === 'published' ? 'Publicada' : 'Borrador' ?>
                                        </span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($source['quizzes'])): ?>
                            <h3 style="margin:16px 0 6px;">Cuestionarios</h3>
                            <p style="margin:0 0 6px;"><a href="#" data-toggle="quiz">Marcar / desmarcar todos</a></p>
                            <?php foreach ($source['quizzes'] as $q): ?>
                                <label style="display:flex; gap:8px; align-items:center; font-weight:400; padding:6px 0; border-bottom:1px solid var(--color-border);">
                                    <input type="checkbox" name="quiz_ids[]" value="<?= (int) $q['id'] ?>" data-group="quiz" style="width:auto;">
                                    <span>
                                        📝 <strong><?= e($q['title']) ?></strong>
                                        <span class="text-muted" style="font-size:0.85rem;">
                                            · <?= e($q['subject_name']) ?> · <?= (int) $q['items_count'] ?> pregunta(s) · <?= $q['status'] === 'published' ? 'Publicado' : 'Borrador' ?>
                                        </span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <p class="text-muted" style="font-size:0.85rem; margin-top:12px;">
                        Se importa a <strong><?= e($destLabel) ?></strong>. Mantienen su estado (publicada/borrador). Después puedes asignarlas
                        a tus estudiantes con nuevas fechas desde <em>Nueva asignación</em>.
                    </p>
                    <button type="submit" class="btn">📥 Importar</button>
                </form>

                <script>
                (function () {
                    const box = document.getElementById('pick-box');
                    const all = document.getElementById('scope-all');
                    const sel = document.getElementById('scope-selected');
                    function sync() { box.style.opacity = all.checked ? '0.45' : '1'; box.style.pointerEvents = all.checked ? 'none' : 'auto'; }
                    all.addEventListener('change', sync); sel.addEventListener('change', sync); sync();
                    document.querySelectorAll('[data-toggle]').forEach(function (a) {
                        a.addEventListener('click', function (ev) {
                            ev.preventDefault();
                            const boxes = document.querySelectorAll('input[data-group="' + a.dataset.toggle + '"]');
                            const everyChecked = Array.from(boxes).every(function (b) { return b.checked; });
                            boxes.forEach(function (b) { b.checked = !everyChecked; });
                        });
                    });
                })();
                </script>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
