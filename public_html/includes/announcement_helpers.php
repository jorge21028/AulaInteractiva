<?php
/**
 * announcement_helpers.php
 * Avisos del profesor a un curso ("mañana traigan…"). El estudiante los ve en su panel mientras estén publicados
 * (publish_at <= ahora) y no hayan expirado (expires_at), y solo si el curso sigue activo.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/description_helpers.php';

/** Avisos visibles para un estudiante: fijados primero, luego los más recientes. */
function announcements_for_student(PDO $pdo, int $studentId, int $limit = 5, int $days = 60): array
{
    $now = now_datetime();
    $stmt = $pdo->prepare(
        'SELECT an.id, an.title, an.body, an.body_format, an.is_pinned, an.publish_at, an.expires_at, c.name AS course_name, u.name AS teacher_name
         FROM course_students cs
         INNER JOIN announcements an ON an.course_id = cs.course_id
         INNER JOIN courses c ON c.id = cs.course_id AND c.is_active = 1
         INNER JOIN users u ON u.id = an.teacher_id
         WHERE cs.student_id = :sid AND an.publish_at <= :now AND (an.expires_at IS NULL OR an.expires_at > :now2)
           AND (an.is_pinned = 1 OR an.publish_at >= :since)
         ORDER BY an.is_pinned DESC, an.publish_at DESC, an.id DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute(['sid' => $studentId, 'now' => $now, 'now2' => $now, 'since' => date('Y-m-d H:i:s', time() - $days * 86400)]);
    return $stmt->fetchAll();
}

/** Avisos del profesor (opcionalmente de un curso), con estado: 'scheduled' | 'active' | 'expired'. */
function announcements_for_teacher(PDO $pdo, int $teacherId, ?int $courseId = null): array
{
    $sql = 'SELECT an.*, c.name AS course_name, c.is_active AS course_active,
                   (SELECT COUNT(*) FROM course_students cs WHERE cs.course_id = an.course_id) AS students_count
            FROM announcements an INNER JOIN courses c ON c.id = an.course_id
            WHERE an.teacher_id = :tid' . ($courseId ? ' AND an.course_id = :cid' : '') . '
            ORDER BY an.is_pinned DESC, an.publish_at DESC, an.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($courseId ? ['tid' => $teacherId, 'cid' => $courseId] : ['tid' => $teacherId]);
    $now = now_datetime();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['state'] = $r['publish_at'] > $now ? 'scheduled' : (!empty($r['expires_at']) && $r['expires_at'] <= $now ? 'expired' : 'active');
    }
    return $rows;
}

/** Cursos (activos) del profesor para elegir a cuál dirigir el aviso. */
function announcements_teacher_courses(PDO $pdo, int $teacherId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name FROM courses c INNER JOIN teacher_courses tc ON tc.course_id = c.id
         WHERE tc.teacher_id = :tid AND c.is_active = 1 ORDER BY c.name'
    );
    $stmt->execute(['tid' => $teacherId]);
    return $stmt->fetchAll();
}

function announcement_teaches_course(PDO $pdo, int $teacherId, int $courseId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM teacher_courses WHERE teacher_id = :t AND course_id = :c');
    $stmt->execute(['t' => $teacherId, 'c' => $courseId]);
    return (bool) $stmt->fetch();
}

/** Texto corto sin etiquetas para listas y notificaciones. */
function announcement_excerpt(string $body, string $format, int $len = 140): string
{
    $text = $format === 'html' ? html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8') : $body;
    return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text)), 0, $len, '…');
}
