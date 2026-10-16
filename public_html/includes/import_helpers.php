<?php
/**
 * import_helpers.php
 * Importar contenido (actividades interactivas y cuestionarios) de un curso del profesor a una asignatura
 * de otro curso (o del mismo), incluso si el curso de origen está inactivo.
 *
 * Se copia el contenido COMPLETO (preguntas, opciones, palabras, imágenes) como elementos nuevos e
 * independientes: editar o borrar la copia no afecta al original. NO se copian partidas, asignaciones,
 * entregas ni notas de estudiantes.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/** Curso del profesor (activo o inactivo) o null si no es suyo. */
function import_load_course(PDO $pdo, int $courseId, int $teacherId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.is_active FROM courses c
         INNER JOIN teacher_courses tc ON tc.course_id = c.id
         WHERE c.id = :cid AND tc.teacher_id = :tid LIMIT 1'
    );
    $stmt->execute(['cid' => $courseId, 'tid' => $teacherId]);
    return $stmt->fetch() ?: null;
}

/** Contenido importable de un curso: ['activities' => [...], 'quizzes' => [...]]. */
function import_list_source(PDO $pdo, int $courseId, int $teacherId): array
{
    $a = $pdo->prepare(
        'SELECT act.id, act.title, act.game_mode, act.status, s.name AS subject_name,
                (SELECT COUNT(*) FROM activity_questions q WHERE q.activity_id = act.id) AS items_count
         FROM activities act INNER JOIN subjects s ON s.id = act.subject_id
         WHERE s.course_id = :cid AND act.teacher_id = :tid
         ORDER BY s.name, act.title'
    );
    $a->execute(['cid' => $courseId, 'tid' => $teacherId]);

    $q = $pdo->prepare(
        'SELECT qz.id, qz.title, qz.status, s.name AS subject_name,
                (SELECT COUNT(*) FROM quiz_questions x WHERE x.quiz_id = qz.id) AS items_count
         FROM quizzes qz INNER JOIN subjects s ON s.id = qz.subject_id
         WHERE s.course_id = :cid AND qz.teacher_id = :tid
         ORDER BY s.name, qz.title'
    );
    $q->execute(['cid' => $courseId, 'tid' => $teacherId]);

    return ['activities' => $a->fetchAll(), 'quizzes' => $q->fetchAll()];
}

/** Título sin repetir dentro de la asignatura destino. */
function import_unique_title(PDO $pdo, string $table, int $subjectId, string $title): string
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM {$table} WHERE subject_id = :sid AND title = :title");
    $candidate = $title;
    $n = 1;
    while (true) {
        $stmt->execute(['sid' => $subjectId, 'title' => $candidate]);
        if ((int) $stmt->fetch()['total'] === 0) {
            return mb_substr($candidate, 0, 200);
        }
        $n++;
        $candidate = mb_substr($title, 0, 185) . ($n === 2 ? ' (importada)' : " (importada $n)");
    }
}

/** INSERT genérico a partir de un arreglo columna => valor. Devuelve el id nuevo. */
function import_insert_row(PDO $pdo, string $table, array $row): int
{
    $cols = array_keys($row);
    $sql = "INSERT INTO {$table} (`" . implode('`, `', $cols) . '`) VALUES (:' . implode(', :', $cols) . ')';
    $pdo->prepare($sql)->execute($row);
    return (int) $pdo->lastInsertId();
}

/** Copia físicamente la imagen de una pregunta (así borrar una no deja sin imagen a la otra). */
function import_copy_image(?string $relativePath, array &$copiedFiles): ?string
{
    if (!$relativePath) {
        return null;
    }
    $src = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (!is_file($src)) {
        return null;
    }
    $newName = safe_random_filename(basename($src));
    $dir = UPLOAD_DIR . '/images';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $dest = $dir . '/' . $newName;
    if (!@copy($src, $dest)) {
        return null;
    }
    $copiedFiles[] = $dest;
    return 'uploads/images/' . $newName;
}

/** Copia una actividad con todas sus preguntas/palabras y opciones. Devuelve el id de la copia. */
function import_activity(PDO $pdo, int $activityId, int $courseId, int $destSubjectId, int $teacherId, array &$copiedFiles): ?int
{
    $stmt = $pdo->prepare(
        'SELECT act.* FROM activities act INNER JOIN subjects s ON s.id = act.subject_id
         WHERE act.id = :id AND act.teacher_id = :tid AND s.course_id = :cid'
    );
    $stmt->execute(['id' => $activityId, 'tid' => $teacherId, 'cid' => $courseId]);
    $act = $stmt->fetch();
    if (!$act) {
        return null;
    }

    $oldId = (int) $act['id'];
    unset($act['id']);
    $act['subject_id'] = $destSubjectId;
    $act['teacher_id'] = $teacherId;
    $act['title'] = import_unique_title($pdo, 'activities', $destSubjectId, (string) $act['title']);
    $act['created_at'] = now_datetime();
    $act['updated_at'] = null;
    $newId = import_insert_row($pdo, 'activities', $act);

    $qStmt = $pdo->prepare('SELECT * FROM activity_questions WHERE activity_id = :id ORDER BY order_index, id');
    $qStmt->execute(['id' => $oldId]);
    $oStmt = $pdo->prepare('SELECT * FROM question_options WHERE question_id = :id ORDER BY order_index, id');

    foreach ($qStmt->fetchAll() as $q) {
        $oldQ = (int) $q['id'];
        unset($q['id']);
        $q['activity_id'] = $newId;
        $q['image_path'] = import_copy_image($q['image_path'] ?? null, $copiedFiles);
        $q['created_at'] = now_datetime();
        $newQ = import_insert_row($pdo, 'activity_questions', $q);

        $oStmt->execute(['id' => $oldQ]);
        foreach ($oStmt->fetchAll() as $o) {
            unset($o['id']);
            $o['question_id'] = $newQ;
            import_insert_row($pdo, 'question_options', $o);
        }
    }
    return $newId;
}

/** Copia un cuestionario con su banco de preguntas y opciones. Devuelve el id de la copia. */
function import_quiz(PDO $pdo, int $quizId, int $courseId, int $destSubjectId, int $teacherId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT qz.* FROM quizzes qz INNER JOIN subjects s ON s.id = qz.subject_id
         WHERE qz.id = :id AND qz.teacher_id = :tid AND s.course_id = :cid'
    );
    $stmt->execute(['id' => $quizId, 'tid' => $teacherId, 'cid' => $courseId]);
    $quiz = $stmt->fetch();
    if (!$quiz) {
        return null;
    }

    $oldId = (int) $quiz['id'];
    unset($quiz['id']);
    $quiz['subject_id'] = $destSubjectId;
    $quiz['teacher_id'] = $teacherId;
    $quiz['title'] = import_unique_title($pdo, 'quizzes', $destSubjectId, (string) $quiz['title']);
    $quiz['created_at'] = now_datetime();
    $quiz['updated_at'] = null;
    $newId = import_insert_row($pdo, 'quizzes', $quiz);

    $qStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = :id ORDER BY order_index, id');
    $qStmt->execute(['id' => $oldId]);
    $oStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = :id ORDER BY order_index, id');

    foreach ($qStmt->fetchAll() as $q) {
        $oldQ = (int) $q['id'];
        unset($q['id']);
        $q['quiz_id'] = $newId;
        $q['created_at'] = now_datetime();
        $newQ = import_insert_row($pdo, 'quiz_questions', $q);

        $oStmt->execute(['id' => $oldQ]);
        foreach ($oStmt->fetchAll() as $o) {
            unset($o['id']);
            $o['question_id'] = $newQ;
            import_insert_row($pdo, 'quiz_options', $o);
        }
    }
    return $newId;
}

/**
 * Ejecuta la importación en una transacción (todo o nada).
 * $scope = 'all' importa todo lo del curso de origen; 'selected' solo los ids indicados.
 * Devuelve ['activities' => [ids nuevos], 'quizzes' => [ids nuevos]].
 */
function import_run(PDO $pdo, int $teacherId, int $sourceCourseId, int $destSubjectId, string $scope, array $activityIds, array $quizIds): array
{
    if ($scope === 'all') {
        $all = import_list_source($pdo, $sourceCourseId, $teacherId);
        $activityIds = array_column($all['activities'], 'id');
        $quizIds = array_column($all['quizzes'], 'id');
    }

    $copiedFiles = [];
    $result = ['activities' => [], 'quizzes' => []];

    $pdo->beginTransaction();
    try {
        foreach (array_unique(array_map('intval', $activityIds)) as $id) {
            $new = import_activity($pdo, $id, $sourceCourseId, $destSubjectId, $teacherId, $copiedFiles);
            if ($new) {
                $result['activities'][] = $new;
            }
        }
        foreach (array_unique(array_map('intval', $quizIds)) as $id) {
            $new = import_quiz($pdo, $id, $sourceCourseId, $destSubjectId, $teacherId);
            if ($new) {
                $result['quizzes'][] = $new;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($copiedFiles as $f) {
            @unlink($f);
        }
        throw $e;
    }
    return $result;
}
