<?php
/**
 * notification_helpers.php
 * "Campanita" del estudiante. Las notificaciones se generan al cargar sus páginas (como mucho una vez por minuto)
 * a partir de lo que ya existe en la base de datos, así respetan las asignaciones programadas (solo avisan cuando ya
 * son visibles) y no hace falta tocar cada lugar donde el profesor califica o da una prórroga.
 *
 * Tipos: assignment (nueva asignación), extension (prórroga), grade (calificación), returned (devuelto para corregir),
 * announcement (aviso del profesor). Cada (usuario, tipo, elemento) es una sola fila; si el elemento se actualiza
 * (nueva prórroga, nota corregida) la fila se actualiza y vuelve a quedar "no leída".
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const NOTIF_WINDOW_NEW_DAYS = 7;
const NOTIF_WINDOW_EVENT_DAYS = 14;

/** Genera las notificaciones pendientes del estudiante (con límite de una vez por minuto por sesión). */
function notifications_sync_student(PDO $pdo, int $studentId, bool $force = false): void
{
    if (!$force && time() - (int) ($_SESSION['notif_sync_at'] ?? 0) < 60) {
        return;
    }
    $_SESSION['notif_sync_at'] = time();

    $now = now_datetime();
    $newSince = date('Y-m-d H:i:s', time() - NOTIF_WINDOW_NEW_DAYS * 86400);
    $eventSince = date('Y-m-d H:i:s', time() - NOTIF_WINDOW_EVENT_DAYS * 86400);
    // Si la fila ya existe y el hecho es más reciente, se refresca y vuelve a "no leída".
    // (columnas con el nombre de la tabla: el SELECT también tiene created_at/title y serían ambiguas)
    $refresh = 'notifications.is_read = IF(VALUES(created_at) > notifications.created_at, 0, notifications.is_read),
                notifications.title = IF(VALUES(created_at) > notifications.created_at, VALUES(title), notifications.title),
                notifications.body = IF(VALUES(created_at) > notifications.created_at, VALUES(body), notifications.body),
                notifications.created_at = GREATEST(notifications.created_at, VALUES(created_at))';

    try {
        // 1) Nuevas asignaciones (desde que se hacen visibles: fecha de inicio o de creación)
        $pdo->prepare(
            "INSERT INTO notifications (user_id, type, ref_id, title, body, url, created_at)
             SELECT sub.student_id, 'assignment', a.id, CONCAT('Nueva asignación: ', LEFT(a.title, 150)),
                    CONCAT(c.name, ' — ', s.name, IF(COALESCE(ax.due_date, a.due_date) IS NULL, '', CONCAT(' · Entrega: ', DATE_FORMAT(COALESCE(ax.due_date, a.due_date), '%d/%m/%Y %h:%i %p')))),
                    CONCAT('student/assignment.php?id=', a.id), GREATEST(a.created_at, COALESCE(a.start_date, a.created_at))
             FROM submissions sub
             INNER JOIN assignments a ON a.id = sub.assignment_id
             INNER JOIN subjects s ON s.id = a.subject_id
             INNER JOIN courses c ON c.id = s.course_id AND c.is_active = 1
             LEFT JOIN assignment_extensions ax ON ax.assignment_id = a.id AND ax.student_id = sub.student_id
             WHERE sub.student_id = :sid AND (a.start_date IS NULL OR a.start_date <= :now)
               AND GREATEST(a.created_at, COALESCE(a.start_date, a.created_at)) >= :since
             ON DUPLICATE KEY UPDATE user_id = user_id"
        )->execute(['sid' => $studentId, 'now' => $now, 'since' => $newSince]);

        // 2) Prórrogas
        $pdo->prepare(
            "INSERT INTO notifications (user_id, type, ref_id, title, body, url, created_at)
             SELECT ax.student_id, 'extension', a.id, CONCAT('⏰ Prórroga: ', LEFT(a.title, 150)),
                    CONCAT('Tu nueva fecha de entrega es el ', DATE_FORMAT(ax.due_date, '%d/%m/%Y %h:%i %p')),
                    CONCAT('student/assignment.php?id=', a.id), ax.created_at
             FROM assignment_extensions ax
             INNER JOIN assignments a ON a.id = ax.assignment_id
             INNER JOIN subjects s ON s.id = a.subject_id
             INNER JOIN courses c ON c.id = s.course_id AND c.is_active = 1
             WHERE ax.student_id = :sid AND ax.created_at >= :since
             ON DUPLICATE KEY UPDATE $refresh"
        )->execute(['sid' => $studentId, 'since' => $eventSince]);

        // 3) Calificaciones puestas o corregidas por el profesor
        $pdo->prepare(
            "INSERT INTO notifications (user_id, type, ref_id, title, body, url, created_at)
             SELECT sub.student_id, 'grade', sub.id, CONCAT('Calificación: ', LEFT(a.title, 150)),
                    CONCAT('Obtuviste ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(sub.score AS CHAR))), ' de ', a.points, ' puntos', IF(sub.feedback IS NULL OR sub.feedback = '', '', ' · Hay un comentario de tu profesor')),
                    CONCAT('student/assignment.php?id=', a.id), sub.reviewed_at
             FROM submissions sub
             INNER JOIN assignments a ON a.id = sub.assignment_id
             INNER JOIN subjects s ON s.id = a.subject_id
             INNER JOIN courses c ON c.id = s.course_id AND c.is_active = 1
             WHERE sub.student_id = :sid AND sub.reviewed_at IS NOT NULL AND sub.score IS NOT NULL AND sub.reviewed_at >= :since
             ON DUPLICATE KEY UPDATE $refresh"
        )->execute(['sid' => $studentId, 'since' => $eventSince]);

        // 4) Trabajos devueltos para corregir
        $pdo->prepare(
            "INSERT INTO notifications (user_id, type, ref_id, title, body, url, created_at)
             SELECT sub.student_id, 'returned', sub.id, CONCAT('Devuelto para corregir: ', LEFT(a.title, 150)),
                    LEFT(COALESCE(NULLIF(sub.return_note, ''), 'Tu profesor te devolvió el trabajo para que lo corrijas.'), 280),
                    CONCAT('student/assignment.php?id=', a.id), sub.returned_at
             FROM submissions sub
             INNER JOIN assignments a ON a.id = sub.assignment_id
             INNER JOIN subjects s ON s.id = a.subject_id
             INNER JOIN courses c ON c.id = s.course_id AND c.is_active = 1
             WHERE sub.student_id = :sid AND sub.returned_at IS NOT NULL AND sub.status = 'pending' AND sub.returned_at >= :since
             ON DUPLICATE KEY UPDATE $refresh"
        )->execute(['sid' => $studentId, 'since' => $eventSince]);

        // 5) Avisos del profesor
        $pdo->prepare(
            "INSERT INTO notifications (user_id, type, ref_id, title, body, url, created_at)
             SELECT cs.student_id, 'announcement', an.id, CONCAT('📢 ', LEFT(an.title, 180)),
                    CONCAT(c.name, ' · ', u.name), 'student/announcements.php', an.publish_at
             FROM course_students cs
             INNER JOIN announcements an ON an.course_id = cs.course_id
             INNER JOIN courses c ON c.id = cs.course_id AND c.is_active = 1
             INNER JOIN users u ON u.id = an.teacher_id
             WHERE cs.student_id = :sid AND an.publish_at <= :now AND an.publish_at >= :since
               AND (an.expires_at IS NULL OR an.expires_at > :now2)
             ON DUPLICATE KEY UPDATE user_id = user_id"
        )->execute(['sid' => $studentId, 'now' => $now, 'now2' => $now, 'since' => $eventSince]);

        // Limpieza: lo leído de hace más de 60 días
        $pdo->prepare('DELETE FROM notifications WHERE user_id = :sid AND is_read = 1 AND created_at < :old')
            ->execute(['sid' => $studentId, 'old' => date('Y-m-d H:i:s', time() - 60 * 86400)]);
    } catch (Throwable $e) {
        error_log('notifications_sync_student: ' . $e->getMessage());
    }
}

function notifications_unread_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0');
    $stmt->execute(['u' => $userId]);
    return (int) $stmt->fetchColumn();
}

/** Las más recientes primero (no leídas antes que leídas del mismo momento no: orden por fecha). */
function notifications_recent(PDO $pdo, int $userId, int $limit = 10, bool $onlyUnread = false, int $offset = 0): array
{
    $sql = 'SELECT id, type, title, body, url, is_read, created_at FROM notifications WHERE user_id = :u'
         . ($onlyUnread ? ' AND is_read = 0' : '') . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['u' => $userId]);
    return $stmt->fetchAll();
}

/** Marca una (o todas, con $id = null) como leídas. */
function notifications_mark_read(PDO $pdo, int $userId, ?int $id = null): int
{
    if ($id === null) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :u AND is_read = 0');
        $stmt->execute(['u' => $userId]);
    } else {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :u AND id = :id');
        $stmt->execute(['u' => $userId, 'id' => $id]);
    }
    return $stmt->rowCount();
}

/** "hace 5 min", "hace 2 h", "ayer", "15/10". */
function notifications_time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return 'ahora';
    }
    if ($diff < 3600) {
        return 'hace ' . (int) ($diff / 60) . ' min';
    }
    if ($diff < 86400) {
        return 'hace ' . (int) ($diff / 3600) . ' h';
    }
    if ($diff < 172800) {
        return 'ayer';
    }
    return date('d/m', strtotime($datetime));
}

function notifications_icon(string $type): string
{
    return match ($type) {
        'assignment'   => '📌',
        'extension'    => '⏰',
        'grade'        => '🎓',
        'returned'     => '↩️',
        'announcement' => '📢',
        default        => '🔔',
    };
}
