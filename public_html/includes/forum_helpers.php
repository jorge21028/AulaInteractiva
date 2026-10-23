<?php
/**
 * forum_helpers.php — Foros de discusión tipo Moodle (Fase 22).
 *
 * Un foro es una asignación con project_type = 'foro' (así hereda fechas, audiencia, notas, retraso, calendario...).
 * Los estudiantes abren temas (hilos) y responden; el foro se marca como completado cuando el estudiante alcanza el
 * mínimo de temas/respuestas que pidió el profesor, y el profesor califica con la participación a la vista.
 * Modos: 'general' (todos ven todo) y 'qa' (pregunta-respuesta: el estudiante ve lo de los demás solo después de
 * publicar su propio tema, para que no copie).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const FORUM_BODY_MAX = 5000;
const FORUM_TITLE_MAX = 200;

/** Foro + permisos de acceso del usuario, o null si no puede entrar. */
function forum_load(PDO $pdo, int $assignmentId, int $userId, string $role): ?array
{
    $stmt = $pdo->prepare(
        "SELECT a.*, s.name AS subject_name, c.is_active AS course_active
         FROM assignments a
         INNER JOIN subjects s ON s.id = a.subject_id
         INNER JOIN courses c ON c.id = s.course_id
         WHERE a.id = :id AND a.project_type = 'foro' LIMIT 1"
    );
    $stmt->execute(['id' => $assignmentId]);
    $a = $stmt->fetch();
    if (!$a) {
        return null;
    }
    if ($role === 'admin') {
        return $a;
    }
    if ($role === 'teacher') {
        return (int) $a['teacher_id'] === $userId ? $a : null;
    }
    if ($role === 'student') {
        $in = $pdo->prepare('SELECT 1 FROM assignment_students WHERE assignment_id = :a AND student_id = :s');
        $in->execute(['a' => $assignmentId, 's' => $userId]);
        if (!$in->fetchColumn() || (int) $a['course_active'] !== 1) {
            return null;
        }
        $ext = $pdo->prepare('SELECT due_date FROM assignment_extensions WHERE assignment_id = :a AND student_id = :s');
        $ext->execute(['a' => $assignmentId, 's' => $userId]);
        if ($d = $ext->fetchColumn()) {
            $a['due_date'] = $d;
        }
        return assignment_has_started($a) ? $a : null;
    }
    return null;
}

/** ¿Cuántos temas y respuestas ha publicado el estudiante? (sin contar lo borrado). */
function forum_participation(PDO $pdo, int $assignmentId, int $studentId, array $assignment = []): array
{
    $stmt = $pdo->prepare(
        'SELECT SUM(parent_id IS NULL) AS t, SUM(parent_id IS NOT NULL) AS r
         FROM forum_posts WHERE assignment_id = :a AND author_id = :s AND deleted_at IS NULL'
    );
    $stmt->execute(['a' => $assignmentId, 's' => $studentId]);
    $r = $stmt->fetch();
    $threads = (int) ($r['t'] ?? 0);
    $replies = (int) ($r['r'] ?? 0);
    $minT = (int) ($assignment['forum_min_threads'] ?? 0);
    $minR = (int) ($assignment['forum_min_replies'] ?? 0);
    return ['threads' => $threads, 'replies' => $replies, 'complete' => $threads >= $minT && $replies >= $minR && ($minT + $minR) > 0];
}

/** Si el estudiante ya cumplió el mínimo, su entrega queda completada (y se registra el retraso, si lo hubo). */
function forum_sync_submission(PDO $pdo, array $assignment, int $studentId): void
{
    $p = forum_participation($pdo, (int) $assignment['id'], $studentId, $assignment);
    if (!$p['complete']) {
        return;
    }
    $q = $pdo->prepare('SELECT id, status FROM submissions WHERE assignment_id = :a AND student_id = :s');
    $q->execute(['a' => $assignment['id'], 's' => $studentId]);
    $sub = $q->fetch();
    if (!$sub || $sub['status'] === 'completed') {
        return;
    }
    $pdo->prepare("UPDATE submissions SET status = 'completed', completed_at = :c WHERE id = :id")
        ->execute(['c' => now_datetime(), 'id' => $sub['id']]);
    require_once __DIR__ . '/late_helpers.php';
    late_stamp_submission($pdo, (int) $assignment['id'], $studentId);
}

/** Temas del foro (fijados primero, luego por actividad reciente) con autor, nº de respuestas y última actividad. */
function forum_threads(PDO $pdo, int $assignmentId, ?int $onlyAuthor = null): array
{
    $sql = 'SELECT p.*, u.name AS author_name,
                   (SELECT COUNT(*) FROM forum_posts r WHERE r.parent_id = p.id AND r.deleted_at IS NULL) AS reply_count,
                   COALESCE((SELECT MAX(r.created_at) FROM forum_posts r WHERE r.parent_id = p.id AND r.deleted_at IS NULL), p.created_at) AS last_activity
            FROM forum_posts p INNER JOIN users u ON u.id = p.author_id
            WHERE p.assignment_id = :a AND p.parent_id IS NULL AND p.deleted_at IS NULL';
    $params = ['a' => $assignmentId];
    if ($onlyAuthor !== null) {
        $sql .= ' AND p.author_id = :au';
        $params['au'] = $onlyAuthor;
    }
    $sql .= ' ORDER BY p.is_pinned DESC, last_activity DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function forum_post(PDO $pdo, int $assignmentId, int $postId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, u.name AS author_name FROM forum_posts p INNER JOIN users u ON u.id = p.author_id
         WHERE p.id = :id AND p.assignment_id = :a AND p.deleted_at IS NULL'
    );
    $stmt->execute(['id' => $postId, 'a' => $assignmentId]);
    return $stmt->fetch() ?: null;
}

function forum_replies(PDO $pdo, int $threadId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, u.name AS author_name, u.role AS author_role FROM forum_posts p INNER JOIN users u ON u.id = p.author_id
         WHERE p.parent_id = :t AND p.deleted_at IS NULL ORDER BY p.created_at ASC, p.id ASC'
    );
    $stmt->execute(['t' => $threadId]);
    return $stmt->fetchAll();
}

/** Modo pregunta-respuesta: el estudiante ve lo de los demás solo después de publicar su propio tema. */
function forum_can_see_others(PDO $pdo, array $assignment, int $userId, string $role): bool
{
    if ($role !== 'student' || ($assignment['forum_mode'] ?? 'general') !== 'qa') {
        return true;
    }
    return forum_participation($pdo, (int) $assignment['id'], $userId)['threads'] > 0;
}

/** Texto plano seguro con saltos de línea y enlaces clicables. */
function forum_render_body(string $body): string
{
    $safe = e($body);
    $safe = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>', $safe);
    return nl2br($safe);
}

/** Resumen de participación de todos los estudiantes (para el profesor): [student_id => [name, threads, replies]]. */
function forum_participation_table(PDO $pdo, int $assignmentId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name,
                (SELECT COUNT(*) FROM forum_posts p WHERE p.assignment_id = ast.assignment_id AND p.author_id = u.id AND p.parent_id IS NULL AND p.deleted_at IS NULL) AS threads,
                (SELECT COUNT(*) FROM forum_posts p WHERE p.assignment_id = ast.assignment_id AND p.author_id = u.id AND p.parent_id IS NOT NULL AND p.deleted_at IS NULL) AS replies
         FROM assignment_students ast INNER JOIN users u ON u.id = ast.student_id
         WHERE ast.assignment_id = :a ORDER BY u.name ASC'
    );
    $stmt->execute(['a' => $assignmentId]);
    return $stmt->fetchAll();
}
