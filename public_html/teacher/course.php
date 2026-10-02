<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/course_helpers.php';
require_once __DIR__ . '/../includes/space_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$courseId = (int) ($_GET['id'] ?? 0);

// Verificar que el curso pertenece a este profesor
$stmt = $pdo->prepare(
    'SELECT c.id, c.name, c.enrollment_code FROM courses c
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE c.id = :course_id AND tc.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['course_id' => $courseId, 'teacher_id' => $teacherId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    exit('Curso no encontrado.');
}

// Generar el código de auto-matrícula la primera vez que se visita el curso
// (los cursos creados antes de la Fase 10 no tenían este campo).
if (empty($course['enrollment_code'])) {
    $newCode = generate_course_code($pdo);
    $pdo->prepare('UPDATE courses SET enrollment_code = :code WHERE id = :id')
        ->execute(['code' => $newCode, 'id' => $courseId]);
    $course['enrollment_code'] = $newCode;
}

$tab = in_array($_GET['tab'] ?? 'subjects', ['subjects', 'students', 'settings'], true) ? ($_GET['tab'] ?? 'subjects') : 'subjects';
$errors = [];
$notice = null;
$resetInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'create_subject') {
        $name = clean_string($_POST['subject_name'] ?? '');
        if ($name === '') {
            $errors[] = 'El nombre de la asignatura no puede estar vacío.';
        } else {
            $pdo->prepare('INSERT INTO subjects (course_id, name, created_at) VALUES (:course_id, :name, :created_at)')
                ->execute(['course_id' => $courseId, 'name' => $name, 'created_at' => now_datetime()]);
            audit_log($pdo, $teacherId, 'create_subject', "Asignatura '{$name}' en curso #{$courseId}");
            redirect('teacher/course.php?id=' . $courseId);
        }
    }

    if ($action === 'rename_course') {
        $name = clean_string($_POST['course_name'] ?? '');
        if ($name === '') {
            $errors[] = 'El nombre del curso no puede estar vacío.';
        } else {
            $pdo->prepare('UPDATE courses SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $courseId]);
            $course['name'] = $name;
            audit_log($pdo, $teacherId, 'rename_course', "Curso #{$courseId} renombrado a '{$name}'");
            $notice = 'Curso renombrado.';
        }
    }

    if ($action === 'rename_subject') {
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $name = clean_string($_POST['subject_name_edit'] ?? '');
        if ($name === '') {
            $errors[] = 'El nombre de la asignatura no puede estar vacío.';
        } else {
            $pdo->prepare('UPDATE subjects SET name = :name WHERE id = :id AND course_id = :course_id')
                ->execute(['name' => $name, 'id' => $subjectId, 'course_id' => $courseId]);
            $notice = 'Asignatura renombrada.';
        }
    }

    if ($action === 'delete_subject') {
        $subjectId = (int) ($_POST['subject_id'] ?? 0);

        $usedStmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM activities WHERE subject_id = :sid1)
             + (SELECT COUNT(*) FROM quizzes WHERE subject_id = :sid2)
             + (SELECT COUNT(*) FROM assignments WHERE subject_id = :sid3) AS total'
        );
        $usedStmt->execute(['sid1' => $subjectId, 'sid2' => $subjectId, 'sid3' => $subjectId]);
        $inUse = (int) ($usedStmt->fetch()['total'] ?? 0);

        if ($inUse > 0) {
            $errors[] = 'No se puede eliminar: esta asignatura ya tiene actividades, cuestionarios o asignaciones. Puedes renombrarla en su lugar.';
        } else {
            $pdo->prepare('DELETE FROM subjects WHERE id = :id AND course_id = :course_id')
                ->execute(['id' => $subjectId, 'course_id' => $courseId]);
            $notice = 'Asignatura eliminada.';
        }
    }

    if ($action === 'unenroll_student') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        course_unenroll_student($pdo, $courseId, $studentId);
        audit_log($pdo, $teacherId, 'unenroll_student', "Estudiante #{$studentId} sacado del curso #{$courseId}");
        $notice = 'Estudiante quitado del curso (y de todas sus asignaturas). Su historial de calificaciones se conserva.';
    }

    if ($action === 'enroll_student') {
        $email = clean_string($_POST['student_email'] ?? '');
        $stmtS = $pdo->prepare("SELECT u.id FROM users u WHERE u.email = :email AND u.role = 'student' LIMIT 1");
        $stmtS->execute(['email' => $email]);
        $student = $stmtS->fetch();

        if (!$student) {
            $errors[] = 'No se encontró ningún estudiante registrado con ese correo.';
        } else {
            $result = course_enroll_student($pdo, $courseId, (int) $student['id']);
            if (!$result['success']) {
                $errors[] = $result['message'];
            } else {
                $notice = 'Estudiante inscrito correctamente.';
            }
        }
    }

    if ($action === 'regenerate_code') {
        $newCode = generate_course_code($pdo);
        $pdo->prepare('UPDATE courses SET enrollment_code = :code WHERE id = :id')
            ->execute(['code' => $newCode, 'id' => $courseId]);
        $course['enrollment_code'] = $newCode;
        audit_log($pdo, $teacherId, 'regenerate_course_code', "Curso #{$courseId}");
        $notice = 'Código regenerado. El código anterior ya no funcionará.';
    }

    if ($action === 'reset_student_password') {
        $studentId = (int) ($_POST['student_id'] ?? 0);

        // Solo se puede restablecer la contraseña de un estudiante que esté
        // inscrito en ESTE curso del profesor (evita que un profesor resetee
        // la contraseña de cualquier estudiante del sistema).
        $checkStmt = $pdo->prepare(
            "SELECT u.id, u.name FROM course_students cs
             INNER JOIN users u ON u.id = cs.student_id
             WHERE cs.course_id = :course_id AND cs.student_id = :student_id AND u.role = 'student'
             LIMIT 1"
        );
        $checkStmt->execute(['course_id' => $courseId, 'student_id' => $studentId]);
        $target = $checkStmt->fetch();

        if (!$target) {
            $errors[] = 'Ese estudiante no está inscrito en este curso.';
        } else {
            $newPassword = generate_temp_password();
            $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id')
                ->execute([
                    'hash'       => password_hash($newPassword, PASSWORD_DEFAULT),
                    'updated_at' => now_datetime(),
                    'id'         => $studentId,
                ]);
            audit_log($pdo, $teacherId, 'reset_student_password', "Estudiante #{$studentId} ({$target['name']})");
            $resetInfo = ['name' => $target['name'], 'password' => $newPassword];
        }
    }
}

// Asignaturas del curso
$subjStmt = $pdo->prepare('SELECT id, name FROM subjects WHERE course_id = :course_id ORDER BY name ASC');
$subjStmt->execute(['course_id' => $courseId]);
$subjects = $subjStmt->fetchAll();

// Estudiantes inscritos
$studStmt = $pdo->prepare(
    'SELECT u.id, u.name, u.email FROM course_students cs
     INNER JOIN users u ON u.id = cs.student_id
     WHERE cs.course_id = :course_id ORDER BY u.name ASC'
);
$studStmt->execute(['course_id' => $courseId]);
$students = $studStmt->fetchAll();

// Estado de cada asignatura (por calificar / entregas pendientes)
$allRows = space_assignment_rows($pdo, $teacherId, $courseId);
$subjectStats = space_summarize_by($allRows, 'subject_id');
$courseSum = space_summarize($allRows);

$pageTitle = $course['name'];
require __DIR__ . '/../includes/header.php';
$self = 'course.php?id=' . (int) $courseId;
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">

<nav class="crumbs" aria-label="Ruta">
    <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
    <strong><?= e($course['name']) ?></strong>
</nav>

<div class="space-head">
    <div>
        <h1><?= e($course['name']) ?></h1>
        <p class="space-sub">Código de matrícula: <strong style="letter-spacing:2px;"><?= e($course['enrollment_code']) ?></strong></p>
    </div>
    <a class="btn btn-secondary" href="assignments.php">➕ Nueva asignación</a>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>
<?php if ($resetInfo): ?>
    <div class="alert alert-success" style="border:2px solid var(--color-primary); font-size:1rem;">
        Contraseña restablecida para <strong><?= e($resetInfo['name']) ?></strong>. Nueva contraseña temporal:
        <div style="font-size:1.6rem; font-weight:800; letter-spacing:3px; margin:8px 0; text-align:center;">
            <?= e($resetInfo['password']) ?>
        </div>
        Comunícasela al estudiante ahora — no se volverá a mostrar. Recomiéndale cambiarla luego de iniciar sesión.
    </div>
<?php endif; ?>

<div class="stat-row">
    <div class="stat-pill"><div class="n"><?= count($subjects) ?></div><div class="l">Asignaturas</div></div>
    <div class="stat-pill"><div class="n"><?= count($students) ?></div><div class="l">Estudiantes</div></div>
    <div class="stat-pill red"><div class="n"><?= (int) $courseSum['needs_submissions'] ?></div><div class="l">Trabajos por corregir</div></div>
    <div class="stat-pill amber"><div class="n"><?= (int) $courseSum['pending_submissions'] ?></div><div class="l">Entregas pendientes</div></div>
</div>

<div class="space-tabs">
    <a class="space-tab <?= $tab === 'subjects' ? 'active' : '' ?>" href="<?= e($self) ?>">📚 Asignaturas <span class="count"><?= count($subjects) ?></span></a>
    <a class="space-tab <?= $tab === 'students' ? 'active' : '' ?>" href="<?= e($self) ?>&tab=students">👥 Estudiantes <span class="count"><?= count($students) ?></span></a>
    <a class="space-tab <?= $tab === 'settings' ? 'active' : '' ?>" href="<?= e($self) ?>&tab=settings">⚙️ Ajustes</a>
</div>

<?php if ($tab === 'subjects'): ?>
    <div class="space-grid">
        <?php foreach ($subjects as $sj):
            $sid = (int) $sj['id'];
            $ss = $subjectStats[$sid] ?? space_summarize([]);
        ?>
            <a class="space-tile" href="subject.php?id=<?= $sid ?>">
                <div class="space-cover" style="background:<?= e(space_gradient($sid + 3)) ?>;">
                    <span class="space-icon">📘</span>
                    <?php if ($ss['needs_submissions'] > 0): ?>
                        <span class="space-corner" style="background:#DC2626;"><?= (int) $ss['needs_submissions'] ?> por corregir</span>
                    <?php endif; ?>
                </div>
                <div class="space-body">
                    <h3><?= e($sj['name']) ?></h3>
                    <p class="space-meta">
                        <?= (int) $ss['assignments'] ?> <?= (int) $ss['assignments'] === 1 ? 'asignación' : 'asignaciones' ?>
                        <?php if ($ss['pending_submissions'] > 0): ?> · <?= (int) $ss['pending_submissions'] ?> entrega(s) pendiente(s)<?php endif; ?>
                    </p>
                    <div class="chips"><?= space_status_chips($ss) ?></div>
                </div>
            </a>
        <?php endforeach; ?>

        <details class="space-tile space-new" <?= empty($subjects) ? 'open' : '' ?>>
            <summary>＋ Nueva asignatura</summary>
            <form method="post" action="<?= e($self) ?>" style="margin-top:12px;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_subject">
                <label for="subject_name">Nombre de la asignatura</label>
                <input type="text" id="subject_name" name="subject_name" placeholder="Ej: Informática" required>
                <button type="submit" class="btn">Agregar asignatura</button>
            </form>
        </details>
    </div>
    <?php if (empty($subjects)): ?>
        <p class="text-muted">Este curso todavía no tiene asignaturas. Crea la primera con el mosaico de arriba.</p>
    <?php endif; ?>

<?php elseif ($tab === 'students'): ?>
    <section class="card" style="text-align:center; background:var(--gradient-brand-soft); border-color:var(--color-primary-light);">
        <p class="text-muted" style="margin:0 0 6px;">Código de auto-matrícula — compártelo con tus estudiantes</p>
        <div style="font-size:2.4rem; font-weight:800; letter-spacing:6px; color:var(--color-primary-dark);">
            <?= e($course['enrollment_code']) ?>
        </div>
        <p class="text-muted" style="font-size:0.85rem; margin-top:6px;">
            El estudiante lo ingresa en "Unirme a una asignatura" desde su panel, y queda inscrito automáticamente
            (sin que tengas que escribir su correo).
        </p>
        <form method="post" action="<?= e($self) ?>&tab=students" style="margin-top:12px;" onsubmit="return confirm('El código actual dejará de funcionar. ¿Generar uno nuevo?')">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="regenerate_code">
            <button type="submit" class="btn btn-secondary" style="margin:0;">Regenerar código</button>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2 style="margin-top:0;">Estudiantes inscritos</h2>
        <?php if (empty($students)): ?>
            <p class="empty-state">Sin estudiantes todavía.</p>
        <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0;">
                <?php foreach ($students as $st): ?>
                    <li style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; padding:6px 0; border-bottom:1px solid var(--color-border);">
                        <span><?= e($st['name']) ?> <span class="text-muted">(<?= e($st['email']) ?>)</span></span>
                        <span style="display:flex; gap:6px; flex-wrap:wrap;">
                            <form method="post" action="<?= e($self) ?>&tab=students"
                                  onsubmit="return confirm('¿Restablecer la contraseña de <?= e(addslashes($st['name'])) ?>? Se generará una nueva contraseña temporal.')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="reset_student_password">
                                <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem; white-space:nowrap;">
                                    Restablecer contraseña
                                </button>
                            </form>
                            <form method="post" action="<?= e($self) ?>&tab=students"
                                  onsubmit="return confirm('¿Quitar a <?= e(addslashes($st['name'])) ?> de este curso? Se eliminarán sus tareas pendientes de este curso (se conserva lo ya calificado).')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="unenroll_student">
                                <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem; white-space:nowrap; color:#C0392B; border-color:#C0392B;">
                                    Quitar del curso
                                </button>
                            </form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="<?= e($self) ?>&tab=students" style="margin-top:16px; max-width:460px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="enroll_student">
            <label for="student_email">Inscribir estudiante por correo (opcional)</label>
            <input type="email" id="student_email" name="student_email" placeholder="estudiante@correo.com" required>
            <button type="submit" class="btn">Inscribir</button>
        </form>
        <p class="text-muted" style="margin-top:10px; font-size:0.85rem;">
            El estudiante debe haberse registrado previamente en Dynamic SGA con ese correo. También puede
            inscribirse solo con el código de arriba, sin que hagas nada aquí.
        </p>
    </section>

<?php else: ?>
    <section class="card">
        <h2 style="margin-top:0;">Nombre del curso</h2>
        <form method="post" action="<?= e($self) ?>&tab=settings" style="max-width:420px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="rename_course">
            <input type="text" name="course_name" value="<?= e($course['name']) ?>" required>
            <button type="submit" class="btn btn-secondary" style="margin-top:8px;">Guardar nombre</button>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2 style="margin-top:0;">Asignaturas</h2>
        <?php if (empty($subjects)): ?>
            <p class="empty-state">Sin asignaturas todavía.</p>
        <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0;">
                <?php foreach ($subjects as $sj): ?>
                    <li style="padding:10px 0; border-bottom:1px solid var(--color-border); display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                        <a href="subject.php?id=<?= (int) $sj['id'] ?>" style="font-weight:600;"><?= e($sj['name']) ?></a>
                        <span style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <form method="post" action="<?= e($self) ?>&tab=settings" style="display:flex; gap:6px;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="rename_subject">
                                <input type="hidden" name="subject_id" value="<?= (int) $sj['id'] ?>">
                                <input type="text" name="subject_name_edit" value="<?= e($sj['name']) ?>" style="padding:4px 8px; font-size:0.85rem;">
                                <button type="submit" class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem;">Renombrar</button>
                            </form>
                            <form method="post" action="<?= e($self) ?>&tab=settings"
                                  onsubmit="return confirm('¿Eliminar la asignatura <?= e(addslashes($sj['name'])) ?>? Solo se puede si no tiene actividades, cuestionarios ni asignaciones.')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_subject">
                                <input type="hidden" name="subject_id" value="<?= (int) $sj['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="margin:0; padding:4px 10px; font-size:0.8rem; color:#C0392B; border-color:#C0392B;">Eliminar</button>
                            </form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
