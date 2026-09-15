<?php
/**
 * project_helpers.php
 * Lógica compartida de "creación académica" (Fase 5): resúmenes, tablas
 * comparativas y, en fases futuras, otros tipos de trabajo del estudiante.
 *
 * IMPORTANTE: aquí NUNCA se usa inteligencia artificial para generar o
 * completar el contenido del estudiante (ver requisito 35/20 de la
 * especificación). El estudiante construye el trabajo directamente.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const PROJECT_TYPES = [
    'resumen' => 'Resumen',
    'tabla_comparativa' => 'Tabla comparativa',
    'infografia' => 'Infografía',
    'mapa_mental' => 'Mapa mental',
];

/**
 * Tipos que usan el editor gráfico (Canvas/Fabric.js) en vez del editor
 * de texto o de tablas.
 */
const PROJECT_CANVAS_TYPES = ['infografia', 'mapa_mental'];

/**
 * Estructura inicial vacía para un tipo de trabajo nuevo.
 */
function project_default_data(string $type): array
{
    return match ($type) {
        'resumen' => ['html' => ''],
        'tabla_comparativa' => [
            'columns' => ['Criterio', 'Opción A', 'Opción B'],
            'rows' => [
                ['', '', ''],
            ],
        ],
        'infografia' => [
            'width' => 800,
            'height' => 1200,
            'backgroundColor' => '#ffffff',
            'objects' => [],
        ],
        'mapa_mental' => [
            'width' => 1400,
            'height' => 900,
            'backgroundColor' => '#ffffff',
            'objects' => [],
        ],
        default => [],
    };
}

/**
 * Sanea recursivamente cualquier campo "html" dentro de la estructura de
 * un trabajo, antes de guardarlo. Ver sanitize_rich_html() en security.php.
 * Los campos de texto plano (celdas de tabla, encabezados) se guardan tal
 * cual, ya que se escapan con e() al momento de mostrarlos.
 *
 * Para los tipos de editor gráfico (infografía, mapa mental), además:
 * - Acota el ancho/alto del lienzo a un rango razonable.
 * - Limita la cantidad de objetos (evita payloads abusivos).
 * - Solo permite imágenes que efectivamente subió este mismo estudiante
 *   (por prefijo de URL), para no aceptar referencias a recursos externos.
 */
function project_sanitize_data(array $data, string $type = '', int $studentId = 0): array
{
    if (isset($data['html']) && is_string($data['html'])) {
        $data['html'] = sanitize_rich_html($data['html']);
    }

    if (in_array($type, PROJECT_CANVAS_TYPES, true)) {
        $data['width'] = max(200, min(3000, (int) ($data['width'] ?? 800)));
        $data['height'] = max(200, min(3000, (int) ($data['height'] ?? 1200)));

        if (!isset($data['backgroundColor']) || !is_string($data['backgroundColor'])) {
            $data['backgroundColor'] = '#ffffff';
        }

        $objects = is_array($data['objects'] ?? null) ? $data['objects'] : [];
        $objects = array_slice($objects, 0, 300);

        $uploadsPrefix = rtrim(APP_URL, '/') . '/uploads/images/';

        foreach ($objects as $i => &$obj) {
            if (!is_array($obj)) {
                unset($objects[$i]);
                continue;
            }
            if (($obj['type'] ?? '') === 'image') {
                $src = (string) ($obj['src'] ?? '');
                if (!str_starts_with($src, $uploadsPrefix)) {
                    // Imagen que no viene de nuestro propio endpoint de subida: descartar el objeto.
                    unset($objects[$i]);
                }
            }
        }
        unset($obj);

        $data['objects'] = array_values($objects);
    }

    return $data;
}

/**
 * Busca (o crea) el trabajo del estudiante para una asignación de tipo
 * "creación académica". Idempotente: si ya existe, lo devuelve tal cual.
 */
function project_find_or_create_for_assignment(PDO $pdo, int $assignmentId, int $studentId, string $type, string $defaultTitle): array
{
    $stmt = $pdo->prepare('SELECT * FROM student_projects WHERE assignment_id = :aid AND student_id = :sid LIMIT 1');
    $stmt->execute(['aid' => $assignmentId, 'sid' => $studentId]);
    $project = $stmt->fetch();

    if ($project) {
        return $project;
    }

    $insert = $pdo->prepare(
        "INSERT INTO student_projects (student_id, assignment_id, type, title, data_json, status, created_at, updated_at)
         VALUES (:sid, :aid, :type, :title, :data_json, 'draft', :created_at, :updated_at)"
    );
    $now = now_datetime();
    $insert->execute([
        'sid' => $studentId,
        'aid' => $assignmentId,
        'type' => $type,
        'title' => $defaultTitle,
        'data_json' => json_encode(project_default_data($type)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $stmt->execute(['aid' => $assignmentId, 'sid' => $studentId]);
    return $stmt->fetch();
}

/**
 * Guarda el progreso de un trabajo (autosave / guardar borrador).
 * No se puede editar un trabajo ya entregado.
 */
function project_save(PDO $pdo, int $projectId, int $studentId, array $data, ?string $title = null): bool
{
    $stmt = $pdo->prepare('SELECT status, type FROM student_projects WHERE id = :id AND student_id = :sid');
    $stmt->execute(['id' => $projectId, 'sid' => $studentId]);
    $project = $stmt->fetch();

    if (!$project || $project['status'] === 'submitted') {
        return false;
    }

    $fields = 'data_json = :data_json, updated_at = :updated_at';
    $params = [
        'data_json' => json_encode(project_sanitize_data($data, $project['type'], $studentId)),
        'updated_at' => now_datetime(), 'id' => $projectId, 'sid' => $studentId,
    ];

    if ($title !== null && $title !== '') {
        $fields .= ', title = :title';
        $params['title'] = $title;
    }

    $pdo->prepare("UPDATE student_projects SET {$fields} WHERE id = :id AND student_id = :sid")->execute($params);
    return true;
}

/**
 * Marca un trabajo como entregado y actualiza (o crea) la entrega
 * correspondiente en "submissions", en estado 'completed' sin calificar
 * todavía (la calificación de trabajos abiertos la pone el profesor).
 */
function project_submit(PDO $pdo, int $projectId, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM student_projects WHERE id = :id AND student_id = :sid');
    $stmt->execute(['id' => $projectId, 'sid' => $studentId]);
    $project = $stmt->fetch();

    if (!$project || $project['status'] === 'submitted') {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE student_projects SET status = 'submitted', submitted_at = :submitted_at, updated_at = :updated_at WHERE id = :id"
        )->execute(['submitted_at' => now_datetime(), 'updated_at' => now_datetime(), 'id' => $projectId]);

        if ($project['assignment_id']) {
            $subStmt = $pdo->prepare(
                'SELECT id, reviewed_at FROM submissions WHERE assignment_id = :aid AND student_id = :sid'
            );
            $subStmt->execute(['aid' => $project['assignment_id'], 'sid' => $studentId]);
            $submission = $subStmt->fetch();

            if ($submission) {
                // No pisar una calificación que el profesor ya ajustó manualmente.
                if ($submission['reviewed_at'] === null) {
                    $pdo->prepare(
                        "UPDATE submissions SET status = 'completed', project_id = :pid, completed_at = :now WHERE id = :id"
                    )->execute(['pid' => $projectId, 'now' => now_datetime(), 'id' => $submission['id']]);
                } else {
                    $pdo->prepare('UPDATE submissions SET project_id = :pid WHERE id = :id')
                        ->execute(['pid' => $projectId, 'id' => $submission['id']]);
                }
            } else {
                $pdo->prepare(
                    "INSERT INTO submissions (assignment_id, student_id, project_id, status, completed_at, created_at)
                     VALUES (:aid, :sid, :pid, 'completed', :completed_at, :created_at)"
                )->execute([
                    'aid' => $project['assignment_id'], 'sid' => $studentId, 'pid' => $projectId,
                    'completed_at' => now_datetime(), 'created_at' => now_datetime(),
                ]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('project_submit error: ' . $e->getMessage());
        return false;
    }
}
