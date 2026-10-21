<?php
/**
 * orphan_helpers.php
 * Imágenes huérfanas: archivos de uploads/images que ya no usa ningún trabajo, pregunta, descripción o aviso.
 * Se cuentan solo las de más de 24 horas (así no se borra una imagen que alguien está subiendo ahora mismo).
 * Exclusivo del súper administrador.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const ORPHAN_MIN_AGE_SECONDS = 86400;
const ORPHAN_NAME_RE = '/uploads\/images\/([A-Za-z0-9._-]+\.(?:jpe?g|png|gif|webp))/i';

/**
 * Nombres de archivo de imagen que siguen referenciados en la base de datos.
 * Se leen por bloques las columnas que pueden contener rutas de /uploads/images/.
 */
function orphan_referenced_names(PDO $pdo): array
{
    $columns = [
        ['student_projects', 'id', ['data_json']],
        ['activity_questions', 'id', ['image_path', 'statement']],
        ['assignments', 'id', ['description']],
        ['quizzes', 'id', ['description', 'instructions']],
        ['activities', 'id', ['description', 'instructions']],
        ['announcements', 'id', ['body']],
        ['quiz_questions', 'id', ['statement']],
    ];
    $names = [];
    foreach ($columns as [$table, $pk, $cols]) {
        try {
            $last = 0;
            do {
                $stmt = $pdo->query("SELECT {$pk}, " . implode(', ', $cols) . " FROM {$table} WHERE {$pk} > {$last} ORDER BY {$pk} LIMIT 500");
                $rows = $stmt->fetchAll();
                foreach ($rows as $r) {
                    $last = (int) $r[$pk];
                    foreach ($cols as $c) {
                        $text = str_replace('\\/', '/', (string) $r[$c]);
                        if ($text !== '' && stripos($text, 'uploads/images/') !== false && preg_match_all(ORPHAN_NAME_RE, $text, $m)) {
                            foreach ($m[1] as $n) {
                                $names[strtolower($n)] = true;
                            }
                        }
                    }
                }
            } while (count($rows) === 500);
        } catch (Throwable $e) {
            // Si una tabla o columna no existe en esta instalación, se ignora (nunca se borra por error de lectura).
            error_log('orphan_referenced_names ' . $table . ': ' . $e->getMessage());
            throw new RuntimeException('No se pudo revisar la tabla ' . $table . '; por seguridad no se borrará nada.');
        }
    }
    return $names;
}

/** Imágenes huérfanas: [['name','size','mtime']] y el total de imágenes revisadas. */
function orphan_scan(PDO $pdo): array
{
    $dir = rtrim(UPLOAD_DIR, '/') . '/images';
    $referenced = orphan_referenced_names($pdo);
    $orphans = [];
    $total = 0;
    $minAge = time() - ORPHAN_MIN_AGE_SECONDS;

    foreach ((array) glob($dir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) as $f) {
        if (!is_file($f)) {
            continue;
        }
        $total++;
        $name = basename($f);
        if (isset($referenced[strtolower($name)]) || filemtime($f) > $minAge) {
            continue;
        }
        $orphans[] = ['name' => $name, 'size' => (int) filesize($f), 'mtime' => filemtime($f)];
    }
    usort($orphans, fn($a, $b) => $b['size'] <=> $a['size']);
    return ['orphans' => $orphans, 'total' => $total, 'referenced' => count($referenced)];
}

/**
 * Borra las huérfanas indicadas (volviendo a comprobar que siguen siendo huérfanas) y sus filas en uploads.
 * Devuelve [archivos borrados, bytes liberados].
 */
function orphan_delete(PDO $pdo, array $names): array
{
    $current = array_column(orphan_scan($pdo)['orphans'], null, 'name');   // vuelve a calcular: nunca se confía en la lista del navegador
    $files = 0;
    $bytes = 0;
    $del = $pdo->prepare("DELETE FROM uploads WHERE kind = 'image' AND stored_name = :n");
    foreach ($names as $name) {
        if (!isset($current[$name])) {
            continue;
        }
        $path = rtrim(UPLOAD_DIR, '/') . '/images/' . $name;
        if (is_file($path) && @unlink($path)) {
            $files++;
            $bytes += $current[$name]['size'];
            $del->execute(['n' => $name]);
        }
    }
    return [$files, $bytes];
}
