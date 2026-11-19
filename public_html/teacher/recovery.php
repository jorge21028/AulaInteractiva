<?php
/**
 * Recuperaciones de una asignatura: lista los grupos (ej. "Recuperación RA 1") y permite crear uno nuevo.
 * Las actividades se agregan a los estudiantes desde la pantalla del grupo (recovery_group.php).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/space_helpers.php';
require_once __DIR__ . '/../includes/availability_helpers.php';
require_once __DIR__ . '/../includes/recovery_helpers.php';

require_role('teacher');

$pdo = Database::getConnection();
$teacherId = current_user_id();
$subjectId = (int) ($_GET['subject'] ?? 0);

$subject = space_load_subject($pdo, $subjectId, $teacherId);
if (!$subject) {
    http_response_code(404);
    exit('Asignatura no encontrada.');
}

$ready = recovery_ready($pdo);
$errors = [];

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_group') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $name = clean_string($_POST['name'] ?? '');
    $description = clean_string($_POST['description'] ?? '');
    $dueDate = dt_from_input(clean_string($_POST['due_date'] ?? ''), true);

    if ($name === '') {
        $errors[] = 'Escribe un nombre para el grupo (por ejemplo: Recuperación RA 1).';
    } elseif (mb_strlen($name) > 120) {
        $errors[] = 'El nombre es demasiado largo (máximo 120 caracteres).';
    }
    if (empty($errors)) {
        try {
            $gid = recovery_group_create($pdo, $teacherId, $subjectId, $name, $description, $dueDate);
            audit_log($pdo, $teacherId, 'recovery_group_create', "Grupo de recuperación '{$name}' creado en la asignatura #{$subjectId}");
            redirect('teacher/recovery_group.php?id=' . $gid . '&created=1');
        } catch (Throwable $e) {
            error_log('recovery_group_create: ' . $e->getMessage());
            $errors[] = 'No fue posible crear el grupo.';
        }
    }
}

$groups = $ready ? recovery_groups_for_subject($pdo, $subjectId, $teacherId) : [];

$pageTitle = 'Recuperaciones · ' . $subject['name'];
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/')) ?>/assets/css/spaces.css">

<nav class="crumbs" aria-label="Ruta">
    <a href="dashboard.php">Mis cursos</a><span class="sep">›</span>
    <a href="course.php?id=<?= (int) $subject['course_id'] ?>"><?= e($subject['course_name']) ?></a><span class="sep">›</span>
    <a href="subject.php?id=<?= (int) $subjectId ?>"><?= e($subject['name']) ?></a><span class="sep">›</span>
    <strong>Recuperaciones</strong>
</nav>

<div class="space-head">
    <div>
        <h1>🔁 Recuperaciones</h1>
        <p class="space-sub"><?= e($subject['course_name']) ?> — <?= e($subject['name']) ?></p>
    </div>
</div>

<?php if (!$ready): ?>
    <div class="alert alert-error">
        Falta ejecutar la migración de la base de datos (archivo <code>migracion_recuperacion.sql</code>) en phpMyAdmin.
        Cuando lo hagas, recarga esta página.
    </div>
<?php else: ?>
    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>
    <?php if (($_GET['deleted'] ?? '') === 'group'): ?><div class="alert alert-success">Grupo de recuperación eliminado.</div><?php endif; ?>

    <p class="text-muted" style="max-width:760px;">
        Crea un grupo (por ejemplo <strong>Recuperación RA 1</strong>) y agrégale, estudiante por estudiante, las actividades que no realizaron
        o que tienen en cero. A los estudiantes les aparecerán agrupadas y tú las calificarás agrupadas.
    </p>

    <section class="card" style="margin:14px 0;">
        <h2 style="margin-top:0; font-size:1.05rem;">➕ Nuevo grupo de recuperación</h2>
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_group">
            <label for="rg-name">Nombre del grupo</label>
            <input type="text" id="rg-name" name="name" maxlength="120" required placeholder="Ej: Recuperación RA 1" value="<?= e((string) ($_POST['name'] ?? '')) ?>">
            <label for="rg-desc">Mensaje para los estudiantes (opcional)</label>
            <textarea id="rg-desc" name="description" rows="2" placeholder="Ej: Entrega estas actividades para recuperar el RA 1."><?= e((string) ($_POST['description'] ?? '')) ?></textarea>
            <label for="rg-due">Fecha y hora límite para todo el grupo (opcional)</label>
            <input type="datetime-local" id="rg-due" name="due_date" value="<?= e((string) ($_POST['due_date'] ?? '')) ?>">
            <p class="text-muted" style="font-size:0.8rem; margin:2px 0 10px;">Podrás cambiarla después; el cambio se aplica a todas las actividades del grupo.</p>
            <button type="submit" class="btn">Crear grupo</button>
        </form>
    </section>

    <?php if (empty($groups)): ?>
        <div class="empty-box">Todavía no hay grupos de recuperación en esta asignatura.</div>
    <?php else: ?>
        <h2 style="font-size:1.05rem;">Grupos de esta asignatura</h2>
        <div class="space-grid">
            <?php foreach ($groups as $g):
                $total = max(1, $g['total']);
                $cardClass = $g['needs_grading'] > 0 ? 'needs' : ($g['pending'] > 0 ? 'pending' : ($g['total'] > 0 ? 'done' : ''));
            ?>
                <a class="asg-card <?= $cardClass ?>" href="recovery_group.php?id=<?= (int) $g['id'] ?>">
                    <h3>🔁 <?= e($g['name']) ?></h3>
                    <p class="asg-kind"><?= (int) $g['students'] ?> estudiante<?= $g['students'] === 1 ? '' : 's' ?> · <?= (int) $g['items'] ?> actividad<?= $g['items'] === 1 ? '' : 'es' ?></p>
                    <div class="progress">
                        <span class="p-graded" style="width:<?= round($g['graded'] / $total * 100, 1) ?>%"></span>
                        <span class="p-needs" style="width:<?= round($g['needs_grading'] / $total * 100, 1) ?>%"></span>
                    </div>
                    <div class="chips">
                        <?php if ($g['total'] === 0): ?>
                            <span class="chip chip-gray">Sin actividades todavía</span>
                        <?php else: ?>
                            <?php if ($g['needs_grading'] > 0): ?><span class="chip chip-red">🔴 <?= (int) $g['needs_grading'] ?> por corregir</span><?php endif; ?>
                            <?php if ($g['graded'] > 0): ?><span class="chip chip-green">✅ <?= (int) $g['graded'] ?> lista<?= $g['graded'] === 1 ? '' : 's' ?></span><?php endif; ?>
                            <?php if ($g['pending'] > 0): ?><span class="chip chip-amber">⏳ <?= (int) $g['pending'] ?> sin entregar</span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($g['due_date'])): ?>
                        <p class="asg-kind" style="margin:0;">Límite: <?= e(dt_format($g['due_date'])) ?></p>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
