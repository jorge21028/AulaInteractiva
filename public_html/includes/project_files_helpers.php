<?php
/**
 * project_files_helpers.php
 * "Entrega de archivos" (Fase 21): el estudiante sube PDF, Word, Excel y otros archivos como entrega de una tarea,
 * y el profesor (o el súper administrador) los revisa y, cuando quiere, los borra para liberar espacio en el hosting.
 *
 * Los archivos se guardan en uploads/submissions/ con un nombre aleatorio y NO tienen URL pública
 * (uploads/submissions/.htaccess lo impide): se descargan con api/submissions/download.php, que verifica el permiso.
 *
 * Al borrar un archivo se elimina del disco y la fila queda marcada con deleted_at (así el estudiante y el profesor
 * ven "archivo eliminado" en vez de un enlace roto).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/materials_helpers.php';
require_once __DIR__ . '/group_helpers.php'; // materials_format_size(), materials_file_icon(), materials_clean_name()

/** Tamaño máximo por archivo. El hosting gratuito puede imponer un límite menor (se detecta y se avisa). */
const PROJECT_FILE_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB
/** Máximo de archivos por entrega (cuida el espacio del hosting). */
const PROJECT_FILE_MAX_COUNT = 5;

/** Tipos permitidos (lista blanca). Nada ejecutable ni HTML/JS/SVG. */
const PROJECT_FILE_ALLOWED_EXT = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    'txt', 'csv', 'rtf', 'zip', 'jpg', 'jpeg', 'png', 'gif', 'webp',
];

/** Extensiones que el profesor puede abrir en el navegador sin descargar (con tipo fijo, nunca el que diga el archivo). */
const PROJECT_FILE_INLINE_TYPES = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
];

function project_files_dir(): string
{
    $dir = rtrim(UPLOAD_DIR, '/') . '/submissions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/** ¿Existe ya la tabla project_files? (permite desplegar el código antes de ejecutar el SQL sin romper nada). */
function project_files_table_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists === null) {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'project_files'")->fetchColumn();
        } catch (Throwable $e) {
            $exists = false;
        }
    }
    return $exists;
}

/** Comprobación ligera de que el contenido coincide con la extensión (no solo el nombre). */
function project_files_signature_ok(string $path, string $ext): bool
{
    $head = (string) @file_get_contents($path, false, null, 0, 8);
    if ($head === '') {
        return false;
    }
    return match ($ext) {
        'pdf' => str_starts_with($head, '%PDF'),
        'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'zip' => str_starts_with($head, "PK"),
        'doc', 'xls', 'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0") || str_starts_with($head, "PK") || str_starts_with($head, '{\\rtf'),
        'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
        'png' => str_starts_with($head, "\x89PNG"),
        'gif' => str_starts_with($head, 'GIF8'),
        'webp' => str_starts_with($head, 'RIFF'),
        'rtf' => str_starts_with($head, '{\\rtf'),
        default => true, // txt, csv
    };
}

/** Archivos de un trabajo (más recientes al final). */
function project_files_list(PDO $pdo, int $projectId, bool $includeDeleted = true): array
{
    $sql = 'SELECT * FROM project_files WHERE project_id = :pid' . ($includeDeleted ? '' : ' AND deleted_at IS NULL') . ' ORDER BY id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetchAll();
}

/** [cantidad activa, bytes activos, cantidad eliminada]. */
function project_files_summary(PDO $pdo, int $projectId): array
{
    $stmt = $pdo->prepare(
        'SELECT SUM(deleted_at IS NULL) AS n, COALESCE(SUM(CASE WHEN deleted_at IS NULL THEN size_bytes ELSE 0 END), 0) AS b,
                SUM(deleted_at IS NOT NULL) AS d
         FROM project_files WHERE project_id = :pid'
    );
    $stmt->execute(['pid' => $projectId]);
    $r = $stmt->fetch();
    return ['count' => (int) ($r['n'] ?? 0), 'bytes' => (int) ($r['b'] ?? 0), 'deleted' => (int) ($r['d'] ?? 0)];
}

/** Marca el trabajo como editado ahora y devuelve la nueva "versión" (para que el autoguardado no se desincronice). */
function project_files_touch(PDO $pdo, int $projectId): string
{
    $now = now_datetime();
    $pdo->prepare('UPDATE student_projects SET updated_at = :now WHERE id = :id')->execute(['now' => $now, 'id' => $projectId]);
    return $now;
}

/**
 * Valida y guarda UN archivo subido por el estudiante para su trabajo.
 * $project: fila de student_projects (ya comprobado que es del estudiante, tipo "archivos", sin entregar y sin bloqueo).
 * Devuelve [ok, mensaje, fila|null].
 */
function project_files_store(PDO $pdo, array $project, array $upload): array
{
    $name = materials_clean_name((string) ($upload['name'] ?? ''));
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return [false, match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "«{$name}» es demasiado pesado para este servidor. Prueba con uno más pequeño.",
            UPLOAD_ERR_PARTIAL => "«{$name}» no terminó de subirse. Revisa tu conexión e intenta de nuevo.",
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
            default => "«{$name}» no se pudo recibir. Intenta de nuevo.",
        }, null];
    }

    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0) {
        return [false, "«{$name}» está vacío.", null];
    }
    if ($size > PROJECT_FILE_MAX_BYTES) {
        return [false, "«{$name}» supera el máximo de " . materials_format_size(PROJECT_FILE_MAX_BYTES) . '.', null];
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, PROJECT_FILE_ALLOWED_EXT, true)) {
        return [false, "«{$name}»: tipo de archivo no permitido (.{$ext}). Usa PDF, Word, Excel, PowerPoint, texto, ZIP o imágenes.", null];
    }

    $tmp = (string) ($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp) || !project_files_signature_ok($tmp, $ext)) {
        return [false, "«{$name}» no parece ser un archivo .{$ext} válido.", null];
    }

    $summary = project_files_summary($pdo, (int) $project['id']);
    if ($summary['count'] >= PROJECT_FILE_MAX_COUNT) {
        return [false, 'Ya llegaste al máximo de ' . PROJECT_FILE_MAX_COUNT . ' archivos. Quita alguno para subir otro.', null];
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $path = project_files_dir() . '/' . $stored;
    if (!move_uploaded_file($tmp, $path)) {
        error_log('project_files_store: move_uploaded_file failed for ' . $stored);
        return [false, "No fue posible guardar «{$name}». Intenta de nuevo.", null];
    }
    @chmod($path, 0644);

    $pdo->prepare(
        'INSERT INTO project_files (project_id, student_id, original_name, stored_name, mime_type, size_bytes, created_at)
         VALUES (:pid, :sid, :name, :stored, :mime, :size, :now)'
    )->execute([
        'pid' => $project['id'], 'sid' => $project['student_id'], 'name' => $name, 'stored' => $stored,
        'mime' => mb_substr((string) ($upload['type'] ?? ''), 0, 120), 'size' => $size, 'now' => now_datetime(),
    ]);
    $id = (int) $pdo->lastInsertId();
    $row = $pdo->query('SELECT * FROM project_files WHERE id = ' . $id)->fetch();

    return [true, 'ok', $row ?: null];
}

/** Borra del disco un archivo guardado (valida el nombre para no salir de la carpeta). */
function project_files_unlink(string $storedName): bool
{
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,5}$/', $storedName)) {
        return false;
    }
    $path = project_files_dir() . '/' . $storedName;
    if (!is_file($path)) {
        return true; // ya no existe: el objetivo (que no ocupe espacio) está cumplido
    }
    return @unlink($path);
}

/** El estudiante quita uno de SUS archivos antes de entregar (se borra del todo). */
function project_files_student_remove(PDO $pdo, int $fileId, int $projectId, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM project_files WHERE id = :id AND project_id = :pid AND student_id = :sid AND deleted_at IS NULL');
    $stmt->execute(['id' => $fileId, 'pid' => $projectId, 'sid' => $studentId]);
    $row = $stmt->fetch();
    if (!$row || !project_files_unlink($row['stored_name'])) {
        return false;
    }
    $pdo->prepare('DELETE FROM project_files WHERE id = :id')->execute(['id' => $fileId]);
    return true;
}

/**
 * Archivo + datos de permiso para descargar. $role: 'student' (dueño), 'teacher' (dueño de la asignación) o 'admin'.
 */
function project_files_for_user(PDO $pdo, int $fileId, int $userId, string $role): ?array
{
    $stmt = $pdo->prepare(
        'SELECT pf.*, sp.assignment_id, a.teacher_id
         FROM project_files pf
         INNER JOIN student_projects sp ON sp.id = pf.project_id
         LEFT JOIN assignments a ON a.id = sp.assignment_id
         WHERE pf.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $fileId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $allowed = match ($role) {
        'admin' => true,
        'teacher' => (int) $row['teacher_id'] === $userId,
        'student' => (int) $row['student_id'] === $userId || project_effective_student($pdo, (int) $row['project_id'], $userId) === (int) $row['student_id'],
        default => false,
    };
    return $allowed ? $row : null;
}

/**
 * Selecciona archivos ACTIVOS que el usuario (profesor/admin) puede borrar.
 * $scope: 'file' | 'project' | 'assignment' | 'ids' (en 'ids', $id es una lista de ids).
 */
function project_files_staff_select(PDO $pdo, string $scope, $id, string $role, int $userId): array
{
    $sql = 'SELECT pf.id, pf.project_id, pf.original_name, pf.stored_name, pf.size_bytes, pf.created_at, sp.assignment_id
            FROM project_files pf
            INNER JOIN student_projects sp ON sp.id = pf.project_id
            LEFT JOIN assignments a ON a.id = sp.assignment_id
            WHERE pf.deleted_at IS NULL';
    $params = [];

    switch ($scope) {
        case 'file':
            $sql .= ' AND pf.id = :id';
            $params['id'] = (int) $id;
            break;
        case 'project':
            $sql .= ' AND pf.project_id = :id';
            $params['id'] = (int) $id;
            break;
        case 'assignment':
            $sql .= ' AND sp.assignment_id = :id';
            $params['id'] = (int) $id;
            break;
        case 'ids':
            $ids = array_values(array_filter(array_map('intval', (array) $id)));
            if (empty($ids)) {
                return [];
            }
            $sql .= ' AND pf.id IN (' . implode(',', $ids) . ')';
            break;
        default:
            return [];
    }

    if ($role === 'teacher') {
        $sql .= ' AND a.teacher_id = :tid';
        $params['tid'] = $userId;
    } elseif ($role !== 'admin') {
        return [];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Borra del disco los archivos indicados y los marca como eliminados.
 * Devuelve ['files' => borrados, 'bytes' => liberados, 'failed' => no se pudieron borrar].
 */
function project_files_staff_delete(PDO $pdo, array $rows, int $userId, string $role): array
{
    $result = ['files' => 0, 'bytes' => 0, 'failed' => 0];
    $mark = $pdo->prepare(
        'UPDATE project_files SET deleted_at = :now, deleted_by = :uid, deleted_by_role = :role WHERE id = :id AND deleted_at IS NULL'
    );
    foreach ($rows as $row) {
        if (!project_files_unlink((string) $row['stored_name'])) {
            $result['failed']++;
            continue;
        }
        $mark->execute(['now' => now_datetime(), 'uid' => $userId, 'role' => $role, 'id' => $row['id']]);
        $result['files']++;
        $result['bytes'] += (int) $row['size_bytes'];
    }
    return $result;
}

/**
 * Al borrar trabajos (asignación, entrega, estudiante quitado): quita las filas de archivos y devuelve los nombres
 * guardados para borrarlos del disco DESPUÉS de confirmar la transacción (project_files_remove_disk).
 */
function project_files_detach_for_projects(PDO $pdo, array $projectIds): array
{
    $projectIds = array_values(array_filter(array_map('intval', $projectIds)));
    if (empty($projectIds) || !project_files_table_exists($pdo)) {
        return [];
    }
    $in = implode(',', $projectIds);
    $names = array_column($pdo->query("SELECT stored_name FROM project_files WHERE project_id IN ($in) AND deleted_at IS NULL")->fetchAll(), 'stored_name');
    $pdo->exec("DELETE FROM project_files WHERE project_id IN ($in)");
    return $names;
}

function project_files_remove_disk(array $storedNames): int
{
    $n = 0;
    foreach ($storedNames as $name) {
        if (project_files_unlink((string) $name)) {
            $n++;
        }
    }
    return $n;
}

/** Resumen por asignación para el súper administrador: archivos activos y espacio que ocupan. */
function project_files_admin_overview(PDO $pdo, int $olderThanDays = 0): array
{
    $sql = 'SELECT a.id AS assignment_id, a.title, s.name AS subject_name, c.name AS course_name, u.name AS teacher_name,
                   COUNT(pf.id) AS file_count, COALESCE(SUM(pf.size_bytes), 0) AS total_bytes, MIN(pf.created_at) AS oldest
            FROM project_files pf
            INNER JOIN student_projects sp ON sp.id = pf.project_id
            INNER JOIN assignments a ON a.id = sp.assignment_id
            INNER JOIN subjects s ON s.id = a.subject_id
            INNER JOIN courses c ON c.id = s.course_id
            INNER JOIN users u ON u.id = a.teacher_id
            WHERE pf.deleted_at IS NULL';
    $params = [];
    if ($olderThanDays > 0) {
        $sql .= ' AND pf.created_at < :cutoff';
        $params['cutoff'] = date('Y-m-d H:i:s', time() - $olderThanDays * 86400);
    }
    $sql .= ' GROUP BY a.id, a.title, s.name, c.name, u.name ORDER BY total_bytes DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Archivos activos de varias asignaciones (para listar en la página del administrador). */
function project_files_admin_files(PDO $pdo, array $assignmentIds, int $olderThanDays = 0): array
{
    $assignmentIds = array_values(array_filter(array_map('intval', $assignmentIds)));
    if (empty($assignmentIds)) {
        return [];
    }
    $sql = 'SELECT pf.*, sp.assignment_id, u.name AS student_name
            FROM project_files pf
            INNER JOIN student_projects sp ON sp.id = pf.project_id
            INNER JOIN users u ON u.id = pf.student_id
            WHERE pf.deleted_at IS NULL AND sp.assignment_id IN (' . implode(',', $assignmentIds) . ')';
    $params = [];
    if ($olderThanDays > 0) {
        $sql .= ' AND pf.created_at < :cutoff';
        $params['cutoff'] = date('Y-m-d H:i:s', time() - $olderThanDays * 86400);
    }
    $sql .= ' ORDER BY u.name ASC, pf.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Texto "Eliminado por el profesor/administrador el dd/mm/aaaa" para un archivo borrado. */
function project_files_deleted_label(array $file): string
{
    $who = ($file['deleted_by_role'] ?? '') === 'admin' ? 'el administrador' : 'el profesor';
    $when = !empty($file['deleted_at']) ? ' el ' . date('d/m/Y', strtotime($file['deleted_at'])) : '';
    return 'Eliminado por ' . $who . $when . ' para liberar espacio';
}
