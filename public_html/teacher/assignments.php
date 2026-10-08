<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php'; // iconos de modos de juego
require_once __DIR__ . '/../includes/assignment_helpers.php';
require_once __DIR__ . '/../includes/project_helpers.php';
require_once __DIR__ . '/../includes/space_helpers.php';
require_once __DIR__ . '/../includes/submission_admin_helpers.php'; // estudiantes del curso

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$errors = [];

// Asignaturas del profesor
$subjStmt = $pdo->prepare(
    'SELECT s.id, s.name, s.course_id, c.name AS course_name
     FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE tc.teacher_id = :teacher_id
     ORDER BY c.name, s.name'
);
$subjStmt->execute(['teacher_id' => $teacherId]);
$subjects = $subjStmt->fetchAll();

// Si se llega desde el espacio de una asignatura (?subject=ID), el formulario se prepara para ella.
$preSubject = null;
foreach ($subjects as $sj) {
    if ((int) $sj['id'] === (int) ($_GET['subject'] ?? 0)) {
        $preSubject = $sj;
        break;
    }
}

// Actividades publicadas del profesor (solo esas se pueden asignar)
$actStmt = $pdo->prepare(
    "SELECT id, title, subject_id, game_mode FROM activities WHERE teacher_id = :teacher_id AND status = 'published' ORDER BY title"
);
$actStmt->execute(['teacher_id' => $teacherId]);
$activities = $actStmt->fetchAll();

// Cuestionarios publicados del profesor
$quizStmt = $pdo->prepare(
    "SELECT id, title, subject_id FROM quizzes WHERE teacher_id = :teacher_id AND status = 'published' ORDER BY title"
);
$quizStmt->execute(['teacher_id' => $teacherId]);
$quizzes = $quizStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_assignment') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $mode = clean_string($_POST['mode'] ?? 'interactive'); // 'interactive' | 'quiz' | 'creation'
    $title = clean_string($_POST['title'] ?? '');
    $description = clean_string($_POST['description'] ?? '');
    $startDate = clean_string($_POST['start_date'] ?? '');
    $dueDate = clean_string($_POST['due_date'] ?? '');
    $points = max(1, (int) ($_POST['points'] ?? 100));

    $activityId = null;
    $quizId = null;
    $projectType = null;
    $subjectId = null;

    if ($mode === 'interactive') {
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $activity = null;
        foreach ($activities as $a) {
            if ((int) $a['id'] === $activityId) {
                $activity = $a;
                break;
            }
        }
        if (!$activity) {
            $errors[] = 'Selecciona una actividad publicada válida.';
        } else {
            $subjectId = (int) $activity['subject_id'];
        }
    } elseif ($mode === 'quiz') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $quiz = null;
        foreach ($quizzes as $q) {
            if ((int) $q['id'] === $quizId) {
                $quiz = $q;
                break;
            }
        }
        if (!$quiz) {
            $errors[] = 'Selecciona un cuestionario publicado válido.';
        } else {
            $subjectId = (int) $quiz['subject_id'];
        }
    } else {
        $projectType = clean_string($_POST['project_type'] ?? '');
        $subjectId = (int) ($_POST['subject_id_creation'] ?? 0);

        if (!array_key_exists($projectType, PROJECT_TYPES)) {
            $errors[] = 'Selecciona un tipo de trabajo válido.';
        }
        $validSubject = false;
        foreach ($subjects as $s) {
            if ((int) $s['id'] === $subjectId) {
                $validSubject = true;
                break;
            }
        }
        if (!$validSubject) {
            $errors[] = 'Selecciona una asignatura válida.';
        }
    }

    if ($title === '') {
        $errors[] = 'El título no puede estar vacío.';
    }

    // ¿Para quién es? Todos los estudiantes del curso, o solo los que el profesor elija (recuperación, refuerzo...)
    $audience = ($_POST['audience'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $studentIds = null;
    if ($audience === 'selected' && $subjectId) {
        $courseStudentIds = array_map(fn($s) => (int) $s['id'], course_students_for_subject($pdo, (int) $subjectId));
        $picked = array_values(array_unique(array_map('intval', (array) ($_POST['student_ids'] ?? []))));
        $studentIds = array_values(array_intersect($picked, $courseStudentIds)); // solo estudiantes de ESTE curso
        if (empty($studentIds)) {
            $errors[] = 'Elige al menos un estudiante para esta asignación, o selecciona "Todos los estudiantes".';
            $studentIds = null;
        }
    }

    if (empty($errors)) {
        try {
            $assignmentId = assignment_create(
                $pdo, $teacherId, $subjectId, $activityId,
                $title, $description,
                $startDate !== '' ? $startDate . ' 00:00:00' : null,
                $dueDate !== '' ? $dueDate . ' 23:59:59' : null,
                $points,
                $projectType,
                $quizId,
                $studentIds
            );
            audit_log(
                $pdo, $teacherId, 'create_assignment',
                "Asignación '{$title}' creada" . ($studentIds !== null ? ' solo para ' . count($studentIds) . ' estudiante(s)' : '')
            );
            redirect('teacher/assignment_detail.php?id=' . $assignmentId);
        } catch (Throwable $e) {
            error_log('create_assignment error: ' . $e->getMessage());
            $errors[] = 'No fue posible crear la asignación.';
        }
    }
}

// Estudiantes por curso y mapas asignatura/actividad/cuestionario -> curso, para el selector "¿Para quién?"
$studentsByCourse = [];
$subjectCourse = [];
foreach ($subjects as $sj) {
    $subjectCourse[(int) $sj['id']] = ['course_id' => (int) $sj['course_id'], 'label' => $sj['course_name'] . ' — ' . $sj['name']];
    $cid = (int) $sj['course_id'];
    if (!isset($studentsByCourse[$cid])) {
        $studentsByCourse[$cid] = array_map(
            fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'email' => $s['email']],
            course_students_for_subject($pdo, (int) $sj['id'])
        );
    }
}
$activitySubject = [];
foreach ($activities as $a) { $activitySubject[(int) $a['id']] = (int) $a['subject_id']; }
$quizSubject = [];
foreach ($quizzes as $q) { $quizSubject[(int) $q['id']] = (int) $q['subject_id']; }
$postedState = [
    'mode'     => in_array($_POST['mode'] ?? '', ['interactive', 'quiz', 'creation'], true) ? $_POST['mode'] : 'interactive',
    'audience' => ($_POST['audience'] ?? 'all') === 'selected' ? 'selected' : 'all',
    'ids'      => array_values(array_map('intval', (array) ($_POST['student_ids'] ?? []))),
    'title'    => (string) ($_POST['title'] ?? ''),
];

// Estado por curso (para los mosaicos de navegación al final de la página)
$courseTiles = [];
foreach ($subjects as $sj) {
    $courseTiles[(int) $sj['course_id']] = $sj['course_name'];
}
$courseStats = space_summarize_by(space_assignment_rows($pdo, $teacherId), 'course_id');

$pageTitle = 'Nueva asignación';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">
<?php if ($preSubject): ?>
    <nav class="crumbs" aria-label="Ruta">
        <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
        <a href="course.php?id=<?= (int) $preSubject['course_id'] ?>"><?= e($preSubject['course_name']) ?></a><span class="sep">›</span>
        <a href="subject.php?id=<?= (int) $preSubject['id'] ?>"><?= e($preSubject['name']) ?></a><span class="sep">›</span>
        <strong>Nueva asignación</strong>
    </nav>
<?php else: ?>
    <nav class="crumbs" aria-label="Ruta"><a href="dashboard.php">Mis cursos</a><span class="sep">›</span><strong>Nueva asignación</strong></nav>
<?php endif; ?>
<h1>Nueva asignación</h1>
<?php if ($preSubject): ?>
    <p class="space-sub" style="margin-top:-6px;">En <strong><?= e($preSubject['course_name']) ?> — <?= e($preSubject['name']) ?></strong>.
        Solo se muestran las actividades y cuestionarios de esta asignatura. <a href="assignments.php">Ver todos</a></p>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if (empty($subjects)): ?>
    <div class="card">
        <p>Necesitas al menos una asignatura antes de crear asignaciones.</p>
        <a class="btn" href="dashboard.php">Ir a mis cursos</a>
    </div>
<?php else: ?>
    <section class="card">

        <div style="display:flex; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:400;">
                <input type="radio" name="mode_selector" value="interactive" checked style="width:auto;" onclick="toggleMode('interactive')">
                Actividad interactiva
            </label>
            <label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:400;">
                <input type="radio" name="mode_selector" value="quiz" style="width:auto;" onclick="toggleMode('quiz')">
                Cuestionario
            </label>
            <label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:400;">
                <input type="radio" name="mode_selector" value="creation" style="width:auto;" onclick="toggleMode('creation')">
                Trabajo de creación
            </label>
        </div>

        <form method="post" action="assignments.php" class="form-narrow" style="margin:0;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_assignment">
            <input type="hidden" name="mode" id="mode_field" value="interactive">

            <div id="mode-interactive">
                <?php $activitiesShown = $preSubject ? array_values(array_filter($activities, fn($x) => (int) $x['subject_id'] === (int) $preSubject['id'])) : $activities; ?>
                <?php if (empty($activitiesShown)): ?>
                    <p class="text-muted">No tienes actividades publicadas<?= $preSubject ? ' en esta asignatura' : '' ?> todavía. <a href="activities.php">Crea una</a>.</p>
                <?php else: ?>
                    <label for="activity_id">Actividad (debe estar publicada)</label>
                    <select id="activity_id" name="activity_id">
                        <?php foreach ($activitiesShown as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"><?= e(game_mode_icon($a['game_mode'])) ?> <?= e($a['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:0.8rem;">
                        Trivia y Sapito se juegan en vivo (tú inicias la partida). Ahorcado y Crucigrama los juegan los estudiantes
                        a su ritmo, sin que inicies nada. En todos la entrega se califica sola.
                    </p>
                <?php endif; ?>
            </div>

            <div id="mode-quiz" style="display:none;">
                <?php $quizzesShown = $preSubject ? array_values(array_filter($quizzes, fn($x) => (int) $x['subject_id'] === (int) $preSubject['id'])) : $quizzes; ?>
                <?php if (empty($quizzesShown)): ?>
                    <p class="text-muted">No tienes cuestionarios publicados<?= $preSubject ? ' en esta asignatura' : '' ?> todavía. <a href="quizzes.php">Crea uno</a>.</p>
                <?php else: ?>
                    <label for="quiz_id">Cuestionario (debe estar publicado)</label>
                    <select id="quiz_id" name="quiz_id">
                        <?php foreach ($quizzesShown as $q): ?>
                            <option value="<?= (int) $q['id'] ?>">📝 <?= e($q['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:0.8rem;">
                        El estudiante lo resuelve a su ritmo, dentro de la fecha límite. Se autocalifica al entregar.
                    </p>
                <?php endif; ?>
            </div>

            <div id="mode-creation" style="display:none;">
                <label for="project_type">Tipo de trabajo</label>
                <select id="project_type" name="project_type">
                    <?php foreach (PROJECT_TYPES as $val => $label): ?>
                        <option value="<?= e($val) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="subject_id_creation">Asignatura</label>
                <select id="subject_id_creation" name="subject_id_creation">
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= ($preSubject && (int) $preSubject['id'] === (int) $s['id']) ? 'selected' : '' ?>><?= e($s['course_name']) ?> — <?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted" style="font-size:0.8rem;">
                    El estudiante trabaja a su ritmo hasta la fecha de entrega. Tú calificas manualmente.
                </p>
            </div>

            <label for="title">Título de la asignación</label>
            <input type="text" id="title" name="title" required placeholder="Ej: Resumen sobre sistemas operativos">

            <label for="description">Descripción / instrucciones</label>
            <textarea id="description" name="description" rows="2"></textarea>

            <label for="start_date">Fecha de inicio (opcional)</label>
            <input type="date" id="start_date" name="start_date">

            <label for="due_date">Fecha de entrega (opcional)</label>
            <input type="date" id="due_date" name="due_date">

            <label for="points">Puntuación</label>
            <input type="text" id="points" name="points" value="100">

            <div id="audience-box" style="margin-top:18px; padding:14px; border:1px solid var(--color-border); border-radius:12px; background:#F8FAFC;">
                <strong>¿Para quién es esta asignación?</strong>
                <label style="display:flex; align-items:center; gap:8px; margin:10px 0 4px; font-weight:400;">
                    <input type="radio" name="audience" value="all" id="aud-all" checked style="width:auto;">
                    <span>👥 Todos los estudiantes del curso <span class="text-muted" id="aud-all-count"></span></span>
                </label>
                <label style="display:flex; align-items:center; gap:8px; margin:4px 0; font-weight:400;">
                    <input type="radio" name="audience" value="selected" id="aud-selected" style="width:auto;">
                    <span>🎯 Solo algunos estudiantes <span class="text-muted">(recuperación, refuerzo, actividades extra)</span></span>
                </label>

                <div id="audience-picker" style="display:none; margin-top:10px;">
                    <p class="text-muted" style="margin:0 0 8px; font-size:0.85rem;">Curso: <strong id="aud-course"></strong>. Marca a quiénes les aparecerá; los demás no la verán.</p>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:8px;">
                        <input type="text" id="aud-search" placeholder="Buscar estudiante..." style="flex:1 1 180px; margin:0;">
                        <button type="button" class="btn btn-secondary" id="aud-all-btn" style="margin:0; padding:6px 12px; font-size:0.85rem;">Marcar todos</button>
                        <button type="button" class="btn btn-secondary" id="aud-none-btn" style="margin:0; padding:6px 12px; font-size:0.85rem;">Ninguno</button>
                    </div>
                    <div id="aud-list" style="max-height:260px; overflow:auto; border:1px solid var(--color-border); border-radius:10px; background:#fff;"></div>
                    <p class="text-muted" id="aud-counter" style="margin:6px 0 0; font-size:0.85rem;"></p>
                </div>
            </div>

            <button type="submit" class="btn" id="btn-create">Crear asignación</button>
            <p class="text-muted" style="font-size:0.8rem; margin-top:8px;" id="aud-note">
                Se asignará automáticamente a todos los estudiantes inscritos en la asignatura.
            </p>
        </form>
    </section>

    <section style="margin-top:24px;">
        <h2 style="margin-bottom:4px;">Ver mis asignaciones</h2>
        <p class="text-muted" style="margin-top:0;">Las asignaciones están organizadas por curso y asignatura: entra a un curso para ver su estado.</p>
        <div class="space-grid">
            <?php foreach ($courseTiles as $cid => $cname):
                $cs = $courseStats[$cid] ?? space_summarize([]); ?>
                <a class="space-tile" href="course.php?id=<?= (int) $cid ?>">
                    <div class="space-cover" style="background:<?= e(space_gradient((int) $cid)) ?>; height:70px;">
                        <span class="space-icon">🎓</span>
                        <?php if ($cs['needs_submissions'] > 0): ?>
                            <span class="space-corner" style="background:#DC2626;"><?= (int) $cs['needs_submissions'] ?> por corregir</span>
                        <?php endif; ?>
                    </div>
                    <div class="space-body">
                        <h3><?= e($cname) ?></h3>
                        <div class="chips"><?= space_status_chips($cs) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <script>
    const COURSE_STUDENTS = <?= json_encode($studentsByCourse, JSON_UNESCAPED_UNICODE) ?>;
    const SUBJECT_COURSE = <?= json_encode($subjectCourse, JSON_UNESCAPED_UNICODE) ?>;
    const ACTIVITY_SUBJECT = <?= json_encode($activitySubject) ?>;
    const QUIZ_SUBJECT = <?= json_encode($quizSubject) ?>;
    const POSTED = <?= json_encode($postedState, JSON_UNESCAPED_UNICODE) ?>;

    function toggleMode(mode) {
        document.getElementById('mode_field').value = mode;
        document.getElementById('mode-interactive').style.display = mode === 'interactive' ? 'block' : 'none';
        document.getElementById('mode-quiz').style.display = mode === 'quiz' ? 'block' : 'none';
        document.getElementById('mode-creation').style.display = mode === 'creation' ? 'block' : 'none';
        renderAudience();
    }

    // ---------- "¿Para quién?" ----------
    let shownCourse = null;      // curso cuyo listado está dibujado ahora
    const checkedIds = new Set(); // selección vigente (se conserva si cambia la búsqueda; se reinicia si cambia de curso)

    function currentSubjectId() {
        const mode = document.getElementById('mode_field').value;
        if (mode === 'interactive') {
            const el = document.getElementById('activity_id');
            return el ? ACTIVITY_SUBJECT[el.value] : null;
        }
        if (mode === 'quiz') {
            const el = document.getElementById('quiz_id');
            return el ? QUIZ_SUBJECT[el.value] : null;
        }
        const el = document.getElementById('subject_id_creation');
        return el ? parseInt(el.value, 10) : null;
    }

    function updateCounter() {
        const list = COURSE_STUDENTS[shownCourse] || [];
        const n = checkedIds.size;
        document.getElementById('aud-counter').textContent = n + ' de ' + list.length + ' estudiantes marcados';
        const selected = document.getElementById('aud-selected').checked;
        document.getElementById('aud-note').textContent = selected
            ? (n === 0 ? 'Marca al menos un estudiante: solo ellos recibirán la asignación.'
                       : 'Solo la recibirán los ' + n + ' estudiante' + (n === 1 ? '' : 's') + ' marcado' + (n === 1 ? '' : 's') + '; los demás no la verán.')
            : 'Se asignará automáticamente a todos los estudiantes inscritos en la asignatura.';
    }

    function renderAudience() {
        const subjectId = currentSubjectId();
        const info = subjectId ? SUBJECT_COURSE[subjectId] : null;
        const courseId = info ? info.course_id : null;
        const list = courseId ? (COURSE_STUDENTS[courseId] || []) : [];
        document.getElementById('aud-all-count').textContent = list.length ? '(' + list.length + ')' : '';

        const selected = document.getElementById('aud-selected').checked;
        document.getElementById('audience-picker').style.display = selected ? 'block' : 'none';
        if (!selected) { updateCounter(); return; }

        if (courseId !== shownCourse) {            // otro curso: la selección anterior ya no aplica
            checkedIds.clear();
            if (POSTED.audience === 'selected' && shownCourse === null) POSTED.ids.forEach(id => checkedIds.add(id));
            shownCourse = courseId;
        }
        document.getElementById('aud-course').textContent = info ? info.label.split(' — ')[0] : '—';

        const box = document.getElementById('aud-list');
        box.innerHTML = '';
        if (!list.length) {
            box.innerHTML = '<p class="text-muted" style="padding:12px; margin:0;">Este curso todavía no tiene estudiantes inscritos.</p>';
        }
        list.forEach(st => {
            const row = document.createElement('label');
            row.className = 'aud-row';
            row.dataset.search = (st.name + ' ' + st.email).toLowerCase();
            row.style.cssText = 'display:flex; align-items:center; gap:10px; margin:0; padding:8px 12px; font-weight:400; border-bottom:1px solid #EEF1F5; cursor:pointer;';
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.name = 'student_ids[]'; cb.value = st.id; cb.style.width = 'auto';
            cb.checked = checkedIds.has(st.id);
            cb.addEventListener('change', () => { cb.checked ? checkedIds.add(st.id) : checkedIds.delete(st.id); updateCounter(); });
            const txt = document.createElement('span');
            txt.innerHTML = '<strong></strong> <small class="text-muted"></small>';
            txt.querySelector('strong').textContent = st.name;
            txt.querySelector('small').textContent = st.email;
            row.append(cb, txt);
            box.appendChild(row);
        });
        applySearch();
        updateCounter();
    }

    function applySearch() {
        const q = document.getElementById('aud-search').value.trim().toLowerCase();
        document.querySelectorAll('#aud-list .aud-row').forEach(r => { r.style.display = (!q || r.dataset.search.includes(q)) ? 'flex' : 'none'; });
    }

    document.getElementById('aud-search').addEventListener('input', applySearch);
    document.getElementById('aud-all-btn').addEventListener('click', () => {
        document.querySelectorAll('#aud-list .aud-row').forEach(r => {
            if (r.style.display === 'none') return;
            const cb = r.querySelector('input'); cb.checked = true; checkedIds.add(parseInt(cb.value, 10));
        });
        updateCounter();
    });
    document.getElementById('aud-none-btn').addEventListener('click', () => {
        document.querySelectorAll('#aud-list .aud-row input').forEach(cb => { cb.checked = false; });
        checkedIds.clear(); updateCounter();
    });
    ['aud-all', 'aud-selected'].forEach(id => document.getElementById(id).addEventListener('change', renderAudience));
    ['activity_id', 'quiz_id', 'subject_id_creation'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', renderAudience);
    });

    document.getElementById('btn-create').closest('form').addEventListener('submit', (e) => {
        if (document.getElementById('aud-selected').checked && checkedIds.size === 0) {
            e.preventDefault();
            alert('Marca al menos un estudiante, o elige "Todos los estudiantes del curso".');
        }
    });

    // Si la página se recarga por un error, se restaura lo que había elegido
    (function restore() {
        if (POSTED.mode !== 'interactive') {
            const r = document.querySelector('input[name=mode_selector][value=' + POSTED.mode + ']');
            if (r) r.checked = true;
            toggleMode(POSTED.mode);
        }
        if (POSTED.title) document.getElementById('title').value = POSTED.title;
        if (POSTED.audience === 'selected') document.getElementById('aud-selected').checked = true;
        renderAudience();
    })();
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
