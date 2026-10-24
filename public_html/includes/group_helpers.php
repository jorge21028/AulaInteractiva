<?php
/**
 * group_helpers.php — Trabajo en grupos (Fase 22).
 *
 * Una asignación con group_mode = 1 se entrega por grupos. El trabajo (student_projects) vive a nombre del
 * "representante" del grupo (assignment_groups.owner_id) y TODOS los integrantes lo editan: cada endpoint resuelve
 * el dueño real con project_effective_student(). Al entregar, la entrega de cada integrante se marca completada
 * (cada uno conserva su propia fila en submissions) y la calificación del profesor se copia a todo el grupo.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

function group_tables_exist(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) $pdo->query("SHOW TABLES LIKE 'assignment_groups'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Grupo de un estudiante en una asignación: ['id','name','owner_id'] o null. */
function group_of_student(PDO $pdo, int $assignmentId, int $studentId): ?array
{
    if (!group_tables_exist($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT g.id, g.name, g.owner_id FROM assignment_group_members gm
         INNER JOIN assignment_groups g ON g.id = gm.group_id
         WHERE gm.assignment_id = :aid AND gm.student_id = :sid LIMIT 1'
    );
    $stmt->execute(['aid' => $assignmentId, 'sid' => $studentId]);
    return $stmt->fetch() ?: null;
}

/** Integrantes de un grupo: [['id','name'], ...]. */
function group_members(PDO $pdo, int $groupId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name FROM assignment_group_members gm INNER JOIN users u ON u.id = gm.student_id
         WHERE gm.group_id = :gid ORDER BY u.name ASC'
    );
    $stmt->execute(['gid' => $groupId]);
    return $stmt->fetchAll();
}

/** A nombre de quién vive el trabajo de este estudiante en esa asignación (él mismo, o el representante de su grupo). */
function group_owner_for(PDO $pdo, int $assignmentId, int $studentId): int
{
    $g = group_of_student($pdo, $assignmentId, $studentId);
    return ($g && !empty($g['owner_id'])) ? (int) $g['owner_id'] : $studentId;
}

/**
 * Dueño efectivo de un trabajo para este estudiante: si es el dueño, o integrante del mismo grupo, devuelve el id del
 * dueño (para usarlo en las consultas "student_id = ?" de siempre). Si no tiene acceso, devuelve el id del estudiante
 * (y esas consultas simplemente no encuentran nada).
 */
function project_effective_student(PDO $pdo, int $projectId, int $studentId): int
{
    $stmt = $pdo->prepare('SELECT student_id, assignment_id FROM student_projects WHERE id = :id');
    $stmt->execute(['id' => $projectId]);
    $p = $stmt->fetch();
    if (!$p || (int) $p['student_id'] === $studentId || !$p['assignment_id']) {
        return $studentId;
    }
    $owner = group_of_student($pdo, (int) $p['assignment_id'], (int) $p['student_id']);
    $me = group_of_student($pdo, (int) $p['assignment_id'], $studentId);
    return ($owner && $me && (int) $owner['id'] === (int) $me['id']) ? (int) $p['student_id'] : $studentId;
}

/** IDs de los demás integrantes del grupo de este estudiante (vacío si trabaja solo). */
function group_mates(PDO $pdo, int $assignmentId, int $studentId): array
{
    $g = group_of_student($pdo, $assignmentId, $studentId);
    if (!$g) {
        return [];
    }
    $ids = array_map(fn($m) => (int) $m['id'], group_members($pdo, (int) $g['id']));
    return array_values(array_filter($ids, fn($id) => $id !== $studentId));
}

/**
 * Cuando el representante entrega, los demás integrantes quedan con la entrega completada (mismo trabajo y misma hora).
 * Se llama dentro de project_submit (ya dentro de su transacción).
 */
function group_propagate_submission(PDO $pdo, int $assignmentId, int $ownerId, int $projectId): void
{
    $mates = group_mates($pdo, $assignmentId, $ownerId);
    if (!$mates) {
        return;
    }
    require_once __DIR__ . '/late_helpers.php';
    $ownerSub = $pdo->prepare('SELECT completed_at, auto_submitted FROM submissions WHERE assignment_id = :aid AND student_id = :sid');
    $ownerSub->execute(['aid' => $assignmentId, 'sid' => $ownerId]);
    $ownerRow = $ownerSub->fetch() ?: [];
    $completedAt = $ownerRow['completed_at'] ?? now_datetime();
    $autoFlag = (int) ($ownerRow['auto_submitted'] ?? 0);

    foreach ($mates as $sid) {
        $q = $pdo->prepare('SELECT id, reviewed_at FROM submissions WHERE assignment_id = :aid AND student_id = :sid');
        $q->execute(['aid' => $assignmentId, 'sid' => $sid]);
        $row = $q->fetch();
        if (!$row) {
            $pdo->prepare("INSERT INTO submissions (assignment_id, student_id, project_id, status, completed_at, created_at) VALUES (:aid,:sid,:pid,'completed',:c,:n)")
                ->execute(['aid' => $assignmentId, 'sid' => $sid, 'pid' => $projectId, 'c' => $completedAt, 'n' => now_datetime()]);
        } elseif ($row['reviewed_at'] === null) {
            $pdo->prepare("UPDATE submissions SET status='completed', project_id=:pid, completed_at=:c, returned_at=NULL, return_note=NULL WHERE id=:id")
                ->execute(['pid' => $projectId, 'c' => $completedAt, 'id' => $row['id']]);
        } else {
            $pdo->prepare('UPDATE submissions SET project_id = :pid WHERE id = :id')->execute(['pid' => $projectId, 'id' => $row['id']]);
        }
        $pdo->prepare('UPDATE submissions SET auto_submitted = :a WHERE assignment_id = :aid AND student_id = :sid')
            ->execute(['a' => $autoFlag, 'aid' => $assignmentId, 'sid' => $sid]);
        late_stamp_submission($pdo, $assignmentId, $sid);
    }
}

// ---------------------------------------------------------------------------
// Administración de grupos (profesor)
// ---------------------------------------------------------------------------

/** Estudiantes de la asignación: [['id','name'], ...]. */
function group_assignment_students(PDO $pdo, int $assignmentId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name FROM assignment_students ast INNER JOIN users u ON u.id = ast.student_id
         WHERE ast.assignment_id = :aid ORDER BY u.name ASC'
    );
    $stmt->execute(['aid' => $assignmentId]);
    return $stmt->fetchAll();
}

/** Grupos de la asignación con sus integrantes. */
function group_list(PDO $pdo, int $assignmentId): array
{
    $stmt = $pdo->prepare('SELECT * FROM assignment_groups WHERE assignment_id = :aid ORDER BY id ASC');
    $stmt->execute(['aid' => $assignmentId]);
    $groups = $stmt->fetchAll();
    foreach ($groups as &$g) {
        $g['members'] = group_members($pdo, (int) $g['id']);
    }
    return $groups;
}

function group_create(PDO $pdo, int $assignmentId, string $name): int
{
    $pdo->prepare('INSERT INTO assignment_groups (assignment_id, name, created_at) VALUES (:aid, :n, :c)')
        ->execute(['aid' => $assignmentId, 'n' => mb_substr($name, 0, 80), 'c' => now_datetime()]);
    return (int) $pdo->lastInsertId();
}

/** Mueve a un estudiante a un grupo (0 = sin grupo), cuidando el representante y el trabajo ya empezado. */
function group_assign_student(PDO $pdo, int $assignmentId, int $studentId, int $groupId): void
{
    $current = group_of_student($pdo, $assignmentId, $studentId);
    if ($current && (int) $current['id'] === $groupId) {
        return;
    }

    if ($current) {
        $pdo->prepare('DELETE FROM assignment_group_members WHERE group_id = :g AND student_id = :s')->execute(['g' => $current['id'], 's' => $studentId]);
        group_fix_owner($pdo, $assignmentId, (int) $current['id'], $studentId);
    }
    if ($groupId > 0) {
        $pdo->prepare('INSERT INTO assignment_group_members (group_id, assignment_id, student_id) VALUES (:g, :a, :s)')
            ->execute(['g' => $groupId, 'a' => $assignmentId, 's' => $studentId]);
        group_fix_owner($pdo, $assignmentId, $groupId, null);
        group_sync_submitted($pdo, $assignmentId, $groupId);
    }
}

/** Garantiza que el grupo tenga un representante válido; si el anterior se fue, le pasa el trabajo al nuevo. */
function group_fix_owner(PDO $pdo, int $assignmentId, int $groupId, ?int $leavingId): void
{
    $g = $pdo->prepare('SELECT owner_id FROM assignment_groups WHERE id = :id');
    $g->execute(['id' => $groupId]);
    $owner = $g->fetchColumn();
    $members = array_map(fn($m) => (int) $m['id'], group_members($pdo, $groupId));

    if (empty($members)) {
        $pdo->prepare('UPDATE assignment_groups SET owner_id = NULL WHERE id = :id')->execute(['id' => $groupId]);
        return;
    }
    if ($owner && in_array((int) $owner, $members, true)) {
        return;
    }
    $newOwner = $members[0];
    $pdo->prepare('UPDATE assignment_groups SET owner_id = :o WHERE id = :id')->execute(['o' => $newOwner, 'id' => $groupId]);
    if ($owner && $leavingId !== null && (int) $owner === $leavingId) {
        // El trabajo compartido pasa al nuevo representante (si este no tenía uno propio).
        $has = $pdo->prepare('SELECT COUNT(*) FROM student_projects WHERE assignment_id = :a AND student_id = :s');
        $has->execute(['a' => $assignmentId, 's' => $newOwner]);
        if ((int) $has->fetchColumn() === 0) {
            $pdo->prepare('UPDATE student_projects SET student_id = :n WHERE assignment_id = :a AND student_id = :o')
                ->execute(['n' => $newOwner, 'a' => $assignmentId, 'o' => $owner]);
        }
    }
}

/** Si el trabajo del grupo ya fue entregado, el integrante nuevo queda con la entrega completada también. */
function group_sync_submitted(PDO $pdo, int $assignmentId, int $groupId): void
{
    $g = $pdo->prepare('SELECT owner_id FROM assignment_groups WHERE id = :id');
    $g->execute(['id' => $groupId]);
    $owner = (int) $g->fetchColumn();
    if (!$owner) {
        return;
    }
    $p = $pdo->prepare("SELECT id FROM student_projects WHERE assignment_id = :a AND student_id = :s AND status = 'submitted'");
    $p->execute(['a' => $assignmentId, 's' => $owner]);
    $pid = $p->fetchColumn();
    if ($pid) {
        group_propagate_submission($pdo, $assignmentId, $owner, (int) $pid);
    }
}

function group_delete(PDO $pdo, int $assignmentId, int $groupId): void
{
    $pdo->prepare('DELETE FROM assignment_groups WHERE id = :id AND assignment_id = :a')->execute(['id' => $groupId, 'a' => $assignmentId]);
}

/** Reparte al azar a los estudiantes SIN grupo en grupos de $size (el último puede quedar más pequeño). Devuelve cuántos grupos creó. */
function group_auto_create(PDO $pdo, int $assignmentId, int $size): int
{
    $size = max(2, min(12, $size));
    $stmt = $pdo->prepare(
        'SELECT ast.student_id FROM assignment_students ast
         WHERE ast.assignment_id = :a AND ast.student_id NOT IN (SELECT student_id FROM assignment_group_members WHERE assignment_id = :a2)'
    );
    $stmt->execute(['a' => $assignmentId, 'a2' => $assignmentId]);
    $free = array_map('intval', array_column($stmt->fetchAll(), 'student_id'));
    if (!$free) {
        return 0;
    }
    shuffle($free);

    $n = (int) $pdo->query('SELECT COUNT(*) FROM assignment_groups WHERE assignment_id = ' . (int) $assignmentId)->fetchColumn();
    $chunks = array_chunk($free, $size);
    // Evita un grupo de 1 persona: si sobra uno solo, se suma al grupo anterior.
    if (count($chunks) > 1 && count(end($chunks)) === 1) {
        $last = array_pop($chunks);
        $chunks[count($chunks) - 1][] = $last[0];
    }
    foreach ($chunks as $members) {
        $gid = group_create($pdo, $assignmentId, 'Grupo ' . (++$n));
        foreach ($members as $sid) {
            group_assign_student($pdo, $assignmentId, $sid, $gid);
        }
    }
    return count($chunks);
}
