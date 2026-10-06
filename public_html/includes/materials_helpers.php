<?php
/**
 * materials_helpers.php
 * Archivos que el profesor sube a una asignatura para que los estudiantes los descarguen.
 * Cada subida crea una "carpeta" (con título y descripción) que puede contener varios archivos.
 *
 * Los archivos se guardan en uploads/materials/ con un nombre aleatorio y NO son accesibles por URL directa
 * (uploads/materials/.htaccess lo impide): se descargan con api/materials/download.php, que verifica el permiso.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/** Tamaño máximo por archivo. El servidor gratuito puede imponer un límite menor (se detecta y se avisa). */
const MATERIAL_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB
const MATERIAL_MAX_FILES_PER_UPLOAD = 10;

/** Tipos permitidos (lista blanca). Nada ejecutable ni HTML/JS/SVG. */
const MATERIAL_ALLOWED_EXT = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'rtf',
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp3', 'wav', 'ogg', 'mp4', 'webm', 'zip',
];

function materials_dir(): string
{
    $dir = UPLOAD_DIR . '/materials';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Si la carpeta se creó aquí (y no con el paquete), se protege igualmente contra el acceso directo.
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

function materials_format_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
    return $bytes . ' B';
}

function materials_file_icon(string $name): string
{
    return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'pdf' => '📕',
        'doc', 'docx', 'odt', 'rtf', 'txt' => '📄',
        'xls', 'xlsx', 'ods', 'csv' => '📊',
        'ppt', 'pptx', 'odp' => '📽️',
        'jpg', 'jpeg', 'png', 'gif', 'webp' => '🖼️',
        'mp3', 'wav', 'ogg' => '🎵',
        'mp4', 'webm' => '🎬',
        'zip' => '🗜️',
        default => '📎',
    };
}

/** Normaliza el nombre original para mostrarlo y para la descarga (sin rutas ni caracteres de control). */
function materials_clean_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"<>|:*?]/u', '', $name);
    $name = trim((string) $name);
    return mb_substr($name !== '' ? $name : 'archivo', 0, 200);
}

/** Convierte $_FILES['files'] (múltiple) en una lista de archivos. */
function materials_collect_uploads(array $files): array
{
    $out = [];
    if (!isset($files['name'])) {
        return $out;
    }
    $names = (array) $files['name'];
    foreach ($names as $i => $name) {
        if ($name === '' && (int) ($files['error'][$i] ?? 4) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name' => (string) $name, 'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
            'size' => (int) ($files['size'][$i] ?? 0), 'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
        ];
    }
    return $out;
}

/**
 * Valida y guarda archivos subidos dentro de una carpeta. Devuelve ['saved' => int, 'errors' => string[]].
 * Cada archivo se valida por separado: si uno falla, los demás igual se guardan.
 */
function materials_store_files(PDO $pdo, int $folderId, array $uploads): array
{
    $saved = 0;
    $errors = [];
    $dir = materials_dir();
    $insert = $pdo->prepare(
        'INSERT INTO subject_files (folder_id, original_name, stored_name, mime_type, size_bytes, created_at)
         VALUES (:fid, :name, :stored, :mime, :size, :now)'
    );

    if (count($uploads) > MATERIAL_MAX_FILES_PER_UPLOAD) {
        $errors[] = 'Máximo ' . MATERIAL_MAX_FILES_PER_UPLOAD . ' archivos por subida; se omitieron los últimos.';
        $uploads = array_slice($uploads, 0, MATERIAL_MAX_FILES_PER_UPLOAD);
    }

    foreach ($uploads as $f) {
        $name = materials_clean_name($f['name']);

        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = match ($f['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "«{$name}» es demasiado pesado para este servidor.",
                UPLOAD_ERR_PARTIAL => "«{$name}» no terminó de subirse. Intenta de nuevo.",
                default => "«{$name}» no se pudo recibir.",
            };
            continue;
        }
        if ($f['size'] > MATERIAL_MAX_BYTES) {
            $errors[] = "«{$name}» supera el máximo de " . materials_format_size(MATERIAL_MAX_BYTES) . '.';
            continue;
        }
        if ($f['size'] <= 0) {
            $errors[] = "«{$name}» está vacío.";
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, MATERIAL_ALLOWED_EXT, true)) {
            $errors[] = "«{$name}»: tipo de archivo no permitido (.{$ext}).";
            continue;
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
            // Respaldo para entornos de prueba / hosts donde is_uploaded_file falla
            if (!is_file($f['tmp_name']) || !@copy($f['tmp_name'], $dir . '/' . $stored)) {
                $errors[] = "«{$name}» no se pudo guardar en el servidor.";
                continue;
            }
        }

        $mime = function_exists('mime_content_type') ? (@mime_content_type($dir . '/' . $stored) ?: null) : null;
        $insert->execute([
            'fid' => $folderId, 'name' => $name, 'stored' => $stored, 'mime' => $mime,
            'size' => $f['size'], 'now' => now_datetime(),
        ]);
        $saved++;
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/** Carpetas de una asignatura con sus archivos (más recientes primero). */
function materials_folders_for_subject(PDO $pdo, int $subjectId): array
{
    $stmt = $pdo->prepare('SELECT * FROM subject_folders WHERE subject_id = :sid ORDER BY created_at DESC, id DESC');
    $stmt->execute(['sid' => $subjectId]);
    $folders = $stmt->fetchAll();
    if (empty($folders)) {
        return [];
    }

    $ids = array_map(fn($f) => (int) $f['id'], $folders);
    $in = implode(',', $ids);
    $files = $pdo->query("SELECT * FROM subject_files WHERE folder_id IN ($in) ORDER BY original_name ASC, id ASC")->fetchAll();

    $byFolder = [];
    foreach ($files as $f) {
        $byFolder[(int) $f['folder_id']][] = $f;
    }
    foreach ($folders as &$folder) {
        $folder['files'] = $byFolder[(int) $folder['id']] ?? [];
        $folder['total_size'] = array_sum(array_map(fn($f) => (int) $f['size_bytes'], $folder['files']));
    }
    unset($folder);

    return $folders;
}

/** Borra del disco los archivos indicados (por stored_name). */
function materials_unlink_stored(array $storedNames): void
{
    $dir = materials_dir();
    foreach ($storedNames as $stored) {
        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,5}$/', (string) $stored)) {
            @unlink($dir . '/' . $stored);
        }
    }
}

function materials_delete_file(PDO $pdo, int $fileId, int $folderId): bool
{
    $stmt = $pdo->prepare('SELECT stored_name FROM subject_files WHERE id = :id AND folder_id = :fid');
    $stmt->execute(['id' => $fileId, 'fid' => $folderId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    $pdo->prepare('DELETE FROM subject_files WHERE id = :id')->execute(['id' => $fileId]);
    materials_unlink_stored([$row['stored_name']]);
    return true;
}

function materials_delete_folder(PDO $pdo, int $folderId): void
{
    $stmt = $pdo->prepare('SELECT stored_name FROM subject_files WHERE folder_id = :fid');
    $stmt->execute(['fid' => $folderId]);
    $names = array_column($stmt->fetchAll(), 'stored_name');
    $pdo->prepare('DELETE FROM subject_folders WHERE id = :id')->execute(['id' => $folderId]); // los archivos se borran en cascada
    materials_unlink_stored($names);
}

/** Datos del archivo si el usuario puede descargarlo (profesor de la asignatura o estudiante inscrito en el curso). */
function materials_file_for_user(PDO $pdo, int $fileId, int $userId, string $role): ?array
{
    $sql = 'SELECT f.*, fo.subject_id, s.course_id
            FROM subject_files f
            INNER JOIN subject_folders fo ON fo.id = f.folder_id
            INNER JOIN subjects s ON s.id = fo.subject_id
            WHERE f.id = :id';
    if ($role === 'teacher') {
        $sql .= ' AND EXISTS (SELECT 1 FROM teacher_courses tc WHERE tc.course_id = s.course_id AND tc.teacher_id = :uid)';
    } elseif ($role === 'student') {
        $sql .= ' AND EXISTS (SELECT 1 FROM course_students cs WHERE cs.course_id = s.course_id AND cs.student_id = :uid)';
    } else {
        return null;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute(['id' => $fileId, 'uid' => $userId]);
    return $stmt->fetch() ?: null;
}

/** ¿Puede el estudiante ver los archivos de esta asignatura? Devuelve la asignatura y su curso, o null. */
function materials_subject_for_student(PDO $pdo, int $subjectId, int $studentId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name, s.course_id, c.name AS course_name
         FROM subjects s
         INNER JOIN courses c ON c.id = s.course_id
         INNER JOIN course_students cs ON cs.course_id = c.id
         WHERE s.id = :sid AND cs.student_id = :uid LIMIT 1'
    );
    $stmt->execute(['sid' => $subjectId, 'uid' => $studentId]);
    return $stmt->fetch() ?: null;
}
