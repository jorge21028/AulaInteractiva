<?php
/**
 * delete_helpers.php
 * Eliminar asignaciones, actividades y cuestionarios del profesor, y borrar las imágenes que los estudiantes
 * subieron en sus trabajos. Todo se hace en una transacción; los archivos físicos se borran DESPUÉS de confirmar
 * los cambios en la base de datos y solo si ningún otro registro los usa.
 *
 * Qué arrastra cada eliminación:
 *   - Asignación: sus entregas/notas, los trabajos de los estudiantes (y sus archivos subidos), los intentos de
 *     cuestionario y de juegos de palabras. NO borra la actividad ni el cuestionario en que se basa.
 *   - Actividad: sus preguntas/palabras (e imágenes), las partidas en vivo y TODAS las asignaciones que la usan.
 *   - Cuestionario: sus preguntas, los intentos de los estudiantes y TODAS las asignaciones que lo usan.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const DELETE_IMAGE_NAME_RE = '/uploads\/images\/([A-Za-z0-9._-]+\.(?:jpe?g|png|gif|webp))/i';

// ---------------------------------------------------------------------------
// Archivos
// ---------------------------------------------------------------------------

/** Ruta física de un archivo de uploads/<carpeta> a partir de su nombre (validado), o null. */
function delete_upload_path(string $folder, string $name): ?string
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) || str_contains($name, '..')) {
        return null;
    }
    return rtrim(UPLOAD_DIR, '/') . '/' . $folder . '/' . $name;
}

/** ¿Algún registro (trabajos de estudiantes o imágenes de preguntas) sigue usando este archivo de imagen? */
function delete_image_still_used(PDO $pdo, string $name): bool
{
    $like = '%' . addcslashes($name, '%_\\') . '%';
    $stmt = $pdo->prepare('SELECT 1 FROM student_projects WHERE data_json LIKE :n LIMIT 1');
    $stmt->execute(['n' => $like]);
    if ($stmt->fetch()) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM activity_questions WHERE image_path LIKE :n LIMIT 1');
    $stmt->execute(['n' => $like]);
    return (bool) $stmt->fetch();
}

/** Borra del disco los archivos de imagen que ya no usa nadie. Devuelve [archivos borrados, bytes liberados]. */
function delete_unused_image_files(PDO $pdo, array $names): array
{
    $files = 0;
    $bytes = 0;
    foreach (array_unique($names) as $name) {
        $path = delete_upload_path('images', (string) $name);
        if ($path === null || !is_file($path) || delete_image_still_used($pdo, (string) $name)) {
            continue;
        }
        $size = (int) @filesize($path);
        if (@unlink($path)) {
            $files++;
            $bytes += $size;
        }
    }
    return [$files, $bytes];
}

/** Archivos de audio/video de la tabla uploads: se borran siempre (solo pertenecen a un trabajo). */
function delete_media_files(array $rows): void
{
    foreach ($rows as $r) {
        $folder = ($r['kind'] ?? 'image') === 'image' ? 'images' : (($r['kind'] ?? '') === 'audio' ? 'audio' : 'video');
        $path = delete_upload_path($folder, (string) $r['stored_name']);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}

// ---------------------------------------------------------------------------
// Quitar las imágenes del contenido de un trabajo (data_json)
// ---------------------------------------------------------------------------

/** ¿Es un elemento "imagen" subida (objeto de lienzo o ficha de galería) que apunta a /uploads/images? */
function delete_is_image_node($node): bool
{
    if (!is_array($node)) {
        return false;
    }
    $url = (string) ($node['src'] ?? $node['url'] ?? '');
    if (!preg_match(DELETE_IMAGE_NAME_RE, $url)) {
        return false;
    }
    return isset($node['url']) || (($node['type'] ?? '') === 'image') || (($node['type'] ?? '') === 'Image');
}

/**
 * Recorre el JSON del trabajo y elimina todo lo que sea una imagen subida: fichas de galería, objetos de imagen
 * de los lienzos, fondos de imagen y etiquetas <img> dentro del texto enriquecido.
 * Devuelve el nuevo valor y rellena $names con los nombres de archivo encontrados.
 */
function delete_strip_images($value, array &$names)
{
    if (is_string($value)) {
        if (stripos($value, 'uploads/images/') === false) {
            return $value;
        }
        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ($m) use (&$names) {
                if (preg_match(DELETE_IMAGE_NAME_RE, $m[0], $f)) {
                    $names[] = $f[1];
                    return '';
                }
                return $m[0];
            },
            $value
        );
    }
    if (!is_array($value)) {
        return $value;
    }

    $isList = array_is_list($value);
    $out = [];
    foreach ($value as $key => $item) {
        if (delete_is_image_node($item)) {
            preg_match(DELETE_IMAGE_NAME_RE, (string) ($item['src'] ?? $item['url']), $f);
            $names[] = $f[1];
            continue;                      // se quita el elemento completo
        }
        $out[$key] = delete_strip_images($item, $names);
    }
    return $isList ? array_values($out) : $out;
}

// ---------------------------------------------------------------------------
// Imágenes de las asignaciones (trabajos de los estudiantes)
// ---------------------------------------------------------------------------

/** Asignaciones del profesor con trabajos de creación e información de sus imágenes. */
function delete_images_overview(PDO $pdo, ?int $teacherId = null): array
{
    $sql = 'SELECT a.id, a.title, a.project_type, s.name AS subject_name, c.name AS course_name, c.is_active, u.name AS teacher_name
            FROM assignments a
            INNER JOIN subjects s ON s.id = a.subject_id
            INNER JOIN courses c ON c.id = s.course_id
            INNER JOIN users u ON u.id = a.teacher_id
            WHERE a.project_type IS NOT NULL' . ($teacherId !== null ? ' AND a.teacher_id = :tid' : '') . '
            ORDER BY u.name, c.name, s.name, a.title';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($teacherId !== null ? ['tid' => $teacherId] : []);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $info = delete_assignment_image_info($pdo, (int) $r['id']);
        $r['image_count'] = $info['count'];
        $r['image_bytes'] = $info['bytes'];
    }
    unset($r);
    return $rows;
}

/** Cuántas imágenes (archivos distintos) y cuánto espacio ocupan en una asignación. */
function delete_assignment_image_info(PDO $pdo, int $assignmentId): array
{
    $stmt = $pdo->prepare('SELECT id, data_json FROM student_projects WHERE assignment_id = :aid');
    $stmt->execute(['aid' => $assignmentId]);
    $names = [];
    $projectIds = [];
    foreach ($stmt->fetchAll() as $p) {
        $projectIds[] = (int) $p['id'];
        if (preg_match_all(DELETE_IMAGE_NAME_RE, str_replace('\\/', '/', (string) $p['data_json']), $m)) {
            foreach ($m[1] as $n) {
                $names[] = $n;
            }
        }
    }
    if ($projectIds) {
        $in = implode(',', $projectIds);
        foreach ($pdo->query("SELECT stored_name FROM uploads WHERE kind = 'image' AND project_id IN ($in)")->fetchAll() as $u) {
            $names[] = $u['stored_name'];
        }
    }
    $names = array_unique($names);
    $count = 0;
    $bytes = 0;
    foreach ($names as $n) {
        $path = delete_upload_path('images', $n);
        if ($path !== null && is_file($path)) {
            $count++;
            $bytes += (int) filesize($path);
        }
    }
    return ['count' => $count, 'bytes' => $bytes];
}

/**
 * Borra TODAS las imágenes subidas por los estudiantes en las asignaciones indicadas (solo las del profesor):
 * quita las imágenes del contenido de cada trabajo y elimina los archivos. El resto del trabajo (textos, tablas,
 * audio, video) se conserva.
 * Devuelve ['files' => n, 'bytes' => b, 'projects' => m].
 */
function delete_assignment_images(PDO $pdo, ?int $teacherId, array $assignmentIds): array
{
    $assignmentIds = array_values(array_unique(array_filter(array_map('intval', $assignmentIds))));
    $result = ['files' => 0, 'bytes' => 0, 'projects' => 0];
    if (empty($assignmentIds)) {
        return $result;
    }

    $in = implode(',', array_fill(0, count($assignmentIds), '?'));
    // $teacherId = null: administrador (cualquier asignación); si no, solo las de ese profesor.
    $own = $pdo->prepare('SELECT id FROM assignments WHERE ' . ($teacherId !== null ? 'teacher_id = ? AND ' : '') . "id IN ($in)");
    $own->execute($teacherId !== null ? array_merge([$teacherId], $assignmentIds) : $assignmentIds);
    $ownIds = array_map('intval', array_column($own->fetchAll(), 'id'));
    if (empty($ownIds)) {
        return $result;
    }
    $in = implode(',', $ownIds);

    $allNames = [];
    $pdo->beginTransaction();
    try {
        $projects = $pdo->query("SELECT id, data_json FROM student_projects WHERE assignment_id IN ($in)")->fetchAll();
        $update = $pdo->prepare('UPDATE student_projects SET data_json = :data WHERE id = :id');
        foreach ($projects as $p) {
            $names = [];
            $decoded = json_decode((string) $p['data_json'], true);
            if (is_array($decoded)) {
                $clean = delete_strip_images($decoded, $names);
                if (!empty($names)) {
                    $update->execute(['data' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $p['id']]);
                    $result['projects']++;
                }
            }
            foreach ($names as $n) {
                $allNames[] = $n;
            }
        }

        // Archivos registrados en uploads para esos trabajos (aunque ya no estén referenciados en el contenido)
        $projectIds = array_map('intval', array_column($projects, 'id'));
        if ($projectIds) {
            $pin = implode(',', $projectIds);
            foreach ($pdo->query("SELECT stored_name FROM uploads WHERE kind = 'image' AND project_id IN ($pin)")->fetchAll() as $u) {
                $allNames[] = $u['stored_name'];
            }
            $pdo->exec("DELETE FROM uploads WHERE kind = 'image' AND project_id IN ($pin)");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    [$result['files'], $result['bytes']] = delete_unused_image_files($pdo, $allNames);
    return $result;
}

// ---------------------------------------------------------------------------
// Resumen previo (para la pantalla de confirmación)
// ---------------------------------------------------------------------------

function delete_count(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) ($stmt->fetch(PDO::FETCH_NUM)[0] ?? 0);
}

/** Carga y valida que el elemento sea del profesor. Devuelve [fila, resumen_html_items] o null. */
function delete_describe(PDO $pdo, string $type, int $id, int $teacherId): ?array
{
    if ($type === 'assignment') {
        $stmt = $pdo->prepare(
            'SELECT a.*, s.name AS subject_name, c.name AS course_name FROM assignments a
             INNER JOIN subjects s ON s.id = a.subject_id INNER JOIN courses c ON c.id = s.course_id
             WHERE a.id = :id AND a.teacher_id = :tid'
        );
        $stmt->execute(['id' => $id, 'tid' => $teacherId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $img = delete_assignment_image_info($pdo, $id);
        return [$row, [
            'Asignación' => $row['title'] . ' (' . $row['course_name'] . ' — ' . $row['subject_name'] . ')',
            'Estudiantes asignados' => delete_count($pdo, 'SELECT COUNT(*) FROM submissions WHERE assignment_id = ?', [$id]),
            'Entregas realizadas' => delete_count($pdo, "SELECT COUNT(*) FROM submissions WHERE assignment_id = ? AND status = 'completed'", [$id]),
            'Entregas con nota' => delete_count($pdo, 'SELECT COUNT(*) FROM submissions WHERE assignment_id = ? AND score IS NOT NULL', [$id]),
            'Trabajos de creación' => delete_count($pdo, 'SELECT COUNT(*) FROM student_projects WHERE assignment_id = ?', [$id]),
            'Imágenes subidas por estudiantes' => $img['count'],
        ]];
    }

    if ($type === 'activity') {
        $stmt = $pdo->prepare(
            'SELECT act.*, s.name AS subject_name, c.name AS course_name FROM activities act
             INNER JOIN subjects s ON s.id = act.subject_id INNER JOIN courses c ON c.id = s.course_id
             WHERE act.id = :id AND act.teacher_id = :tid'
        );
        $stmt->execute(['id' => $id, 'tid' => $teacherId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [$row, [
            'Actividad' => $row['title'] . ' (' . $row['course_name'] . ' — ' . $row['subject_name'] . ')',
            'Preguntas / palabras' => delete_count($pdo, 'SELECT COUNT(*) FROM activity_questions WHERE activity_id = ?', [$id]),
            'Asignaciones que la usan' => delete_count($pdo, 'SELECT COUNT(*) FROM assignments WHERE activity_id = ?', [$id]),
            'Entregas realizadas en esas asignaciones' => delete_count($pdo, "SELECT COUNT(*) FROM submissions sb INNER JOIN assignments a ON a.id = sb.assignment_id WHERE a.activity_id = ? AND sb.status = 'completed'", [$id]),
            'Partidas en vivo jugadas' => delete_count($pdo, 'SELECT COUNT(*) FROM games WHERE activity_id = ?', [$id]),
        ]];
    }

    if ($type === 'quiz') {
        $stmt = $pdo->prepare(
            'SELECT qz.*, s.name AS subject_name, c.name AS course_name FROM quizzes qz
             INNER JOIN subjects s ON s.id = qz.subject_id INNER JOIN courses c ON c.id = s.course_id
             WHERE qz.id = :id AND qz.teacher_id = :tid'
        );
        $stmt->execute(['id' => $id, 'tid' => $teacherId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [$row, [
            'Cuestionario' => $row['title'] . ' (' . $row['course_name'] . ' — ' . $row['subject_name'] . ')',
            'Preguntas' => delete_count($pdo, 'SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = ?', [$id]),
            'Asignaciones que lo usan' => delete_count($pdo, 'SELECT COUNT(*) FROM assignments WHERE quiz_id = ?', [$id]),
            'Intentos de estudiantes' => delete_count($pdo, 'SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ?', [$id]),
            'Entregas realizadas en esas asignaciones' => delete_count($pdo, "SELECT COUNT(*) FROM submissions sb INNER JOIN assignments a ON a.id = sb.assignment_id WHERE a.quiz_id = ? AND sb.status = 'completed'", [$id]),
        ]];
    }

    return null;
}

// ---------------------------------------------------------------------------
// Eliminaciones
// ---------------------------------------------------------------------------

/** Borra intentos de cuestionario (y sus respuestas/preguntas sorteadas) de un conjunto de asignaciones o de un cuestionario. */
function delete_quiz_attempts(PDO $pdo, string $column, array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids) || !in_array($column, ['assignment_id', 'quiz_id'], true)) {
        return;
    }
    $in = implode(',', $ids);
    $attemptIds = array_map('intval', array_column($pdo->query("SELECT id FROM quiz_attempts WHERE {$column} IN ($in)")->fetchAll(), 'id'));
    if ($attemptIds) {
        $ain = implode(',', $attemptIds);
        $pdo->exec("DELETE FROM quiz_attempt_answers WHERE attempt_id IN ($ain)");
        $pdo->exec("DELETE FROM quiz_attempt_questions WHERE attempt_id IN ($ain)");
        $pdo->exec("DELETE FROM quiz_attempts WHERE id IN ($ain)");
    }
}

/** Núcleo: borra asignaciones (ids ya validados como del profesor). Devuelve nombres de imágenes y filas de uploads a limpiar. */
function delete_assignments_core(PDO $pdo, array $assignmentIds): array
{
    $assignmentIds = array_values(array_filter(array_map('intval', $assignmentIds)));
    $leftovers = ['image_names' => [], 'media' => [], 'submission_files' => []];
    if (empty($assignmentIds)) {
        return $leftovers;
    }
    $in = implode(',', $assignmentIds);

    // Trabajos de los estudiantes: sus imágenes y archivos subidos
    $projects = $pdo->query("SELECT id, data_json FROM student_projects WHERE assignment_id IN ($in)")->fetchAll();
    $projectIds = array_map('intval', array_column($projects, 'id'));
    foreach ($projects as $p) {
        if (preg_match_all(DELETE_IMAGE_NAME_RE, str_replace('\\/', '/', (string) $p['data_json']), $m)) {
            foreach ($m[1] as $n) {
                $leftovers['image_names'][] = $n;
            }
        }
    }
    if ($projectIds) {
        $pin = implode(',', $projectIds);
        foreach ($pdo->query("SELECT stored_name, kind FROM uploads WHERE project_id IN ($pin)")->fetchAll() as $u) {
            if ($u['kind'] === 'image') {
                $leftovers['image_names'][] = $u['stored_name'];
            } else {
                $leftovers['media'][] = $u;
            }
        }
        $pdo->exec("DELETE FROM uploads WHERE project_id IN ($pin)");

        // Entrega de archivos (PDF, Word, Excel...): se quitan las filas y luego se borran del disco
        require_once __DIR__ . '/project_files_helpers.php';
        $leftovers['submission_files'] = project_files_detach_for_projects($pdo, $projectIds);
    }

    delete_quiz_attempts($pdo, 'assignment_id', $assignmentIds);
    $pdo->exec("DELETE FROM word_game_attempts WHERE assignment_id IN ($in)");
    $pdo->exec("DELETE FROM student_projects WHERE assignment_id IN ($in)");
    $pdo->exec("DELETE FROM submissions WHERE assignment_id IN ($in)");
    $pdo->exec("DELETE FROM assignment_students WHERE assignment_id IN ($in)");
    $pdo->exec("DELETE FROM assignments WHERE id IN ($in)");

    return $leftovers;
}

/** Limpia del disco lo que quedó huérfano tras una eliminación (ya confirmada en la BD). */
function delete_finish_files(PDO $pdo, array $leftovers): array
{
    delete_media_files($leftovers['media'] ?? []);
    if (!empty($leftovers['submission_files'])) {
        require_once __DIR__ . '/project_files_helpers.php';
        project_files_remove_disk($leftovers['submission_files']);
    }
    return delete_unused_image_files($pdo, $leftovers['image_names'] ?? []);
}

function assignment_delete(PDO $pdo, int $assignmentId, int $teacherId): bool
{
    $own = $pdo->prepare('SELECT id FROM assignments WHERE id = :id AND teacher_id = :tid');
    $own->execute(['id' => $assignmentId, 'tid' => $teacherId]);
    if (!$own->fetch()) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $left = delete_assignments_core($pdo, [$assignmentId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('assignment_delete: ' . $e->getMessage());
        return false;
    }
    delete_finish_files($pdo, $left);
    return true;
}

function activity_delete(PDO $pdo, int $activityId, int $teacherId): bool
{
    $own = $pdo->prepare('SELECT id FROM activities WHERE id = :id AND teacher_id = :tid');
    $own->execute(['id' => $activityId, 'tid' => $teacherId]);
    if (!$own->fetch()) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $aIds = array_column($pdo->query("SELECT id FROM assignments WHERE activity_id = " . (int) $activityId)->fetchAll(), 'id');
        $left = delete_assignments_core($pdo, $aIds);

        $qImages = array_column($pdo->query("SELECT image_path FROM activity_questions WHERE activity_id = " . (int) $activityId . " AND image_path IS NOT NULL")->fetchAll(), 'image_path');
        $pdo->prepare('DELETE FROM activities WHERE id = :id')->execute(['id' => $activityId]);   // preguntas, opciones y partidas en cascada
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('activity_delete: ' . $e->getMessage());
        return false;
    }

    foreach ($qImages as $path) {
        if (preg_match(DELETE_IMAGE_NAME_RE, (string) $path, $m)) {
            $left['image_names'][] = $m[1];
        }
    }
    delete_finish_files($pdo, $left);
    return true;
}

function quiz_delete(PDO $pdo, int $quizId, int $teacherId): bool
{
    $own = $pdo->prepare('SELECT id FROM quizzes WHERE id = :id AND teacher_id = :tid');
    $own->execute(['id' => $quizId, 'tid' => $teacherId]);
    if (!$own->fetch()) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $aIds = array_column($pdo->query("SELECT id FROM assignments WHERE quiz_id = " . (int) $quizId)->fetchAll(), 'id');
        $left = delete_assignments_core($pdo, $aIds);

        delete_quiz_attempts($pdo, 'quiz_id', [$quizId]);
        $qIds = array_map('intval', array_column($pdo->query("SELECT id FROM quiz_questions WHERE quiz_id = " . (int) $quizId)->fetchAll(), 'id'));
        if ($qIds) {
            $in = implode(',', $qIds);
            $pdo->exec("DELETE FROM quiz_options WHERE question_id IN ($in)");
            $pdo->exec("DELETE FROM quiz_questions WHERE id IN ($in)");
        }
        $pdo->prepare('DELETE FROM quizzes WHERE id = :id')->execute(['id' => $quizId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('quiz_delete: ' . $e->getMessage());
        return false;
    }

    delete_finish_files($pdo, $left);
    return true;
}

function delete_format_bytes(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format(max(0, $bytes) / 1024, 0, ',', '.') . ' KB';
}
