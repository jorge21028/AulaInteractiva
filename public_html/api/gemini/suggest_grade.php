<?php
/**
 * POST /api/gemini/suggest_grade.php   (JSON: submission_id, criteria?, csrf_token)
 * El profesor pide a Gemini una SUGERENCIA de nota y retroalimentación para una entrega de creación (resumen, tabla,
 * infografía, archivos, foro...). No guarda nada: la pantalla de calificaciones rellena los campos y el profesor decide.
 * Se envía a Google solo el texto del trabajo (sin nombre ni correo del estudiante).
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project_helpers.php';
require_once __DIR__ . '/../../includes/work_text_helpers.php';
require_once __DIR__ . '/../../services/GeminiService.php';

header('Content-Type: application/json; charset=utf-8');
require_role('teacher');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
csrf_verify($input['csrf_token'] ?? null);

// Freno simple: máximo 120 sugerencias por hora (alcanza para calificar un grupo completo) y una cada 3 segundos (cuida la cuota gratuita de Gemini)
$now = time();
$calls = array_values(array_filter((array) ($_SESSION['ai_grade_calls'] ?? []), fn($t) => $t > $now - 3600));
if (count($calls) >= 120) {
    json_response(['success' => false, 'message' => 'Llegaste al límite de 120 sugerencias por hora. Intenta más tarde.'], 429);
}
if ($calls && $now - end($calls) < 3) {
    json_response(['success' => false, 'message' => 'Espera unos segundos antes de pedir otra sugerencia.'], 429);
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    'SELECT sub.id, sub.student_id, sub.project_id, sub.assignment_id, a.title, a.description, a.points, a.project_type, a.teacher_id
     FROM submissions sub INNER JOIN assignments a ON a.id = sub.assignment_id
     WHERE sub.id = :id AND a.teacher_id = :tid LIMIT 1'
);
$stmt->execute(['id' => (int) ($input['submission_id'] ?? 0), 'tid' => current_user_id()]);
$sub = $stmt->fetch();
if (!$sub || empty($sub['project_type'])) {
    json_response(['success' => false, 'message' => 'Entrega no encontrada.'], 404);
}

if ($sub['project_type'] === 'foro') {
    $work = work_text_for_forum($pdo, (int) $sub['assignment_id'], (int) $sub['student_id']);
} else {
    $p = null;
    if ($sub['project_id']) {
        $q = $pdo->prepare('SELECT * FROM student_projects WHERE id = :id');
        $q->execute(['id' => $sub['project_id']]);
        $p = $q->fetch();
    }
    $work = $p ? work_text_for_project($pdo, $p) : '';
}

if (mb_strlen(trim($work)) < 20) {
    json_response(['success' => false, 'message' => 'No hay suficiente texto para analizar. (Gemini no lee PDF, Excel ni imágenes; sí lee .docx, .txt y .csv.)'], 422);
}

$_SESSION['ai_grade_calls'] = array_merge($calls, [$now]);

$result = GeminiService::suggestGrade([
    'title' => $sub['title'], 'description' => strip_tags((string) $sub['description']), 'points' => (int) $sub['points'],
    'kind' => PROJECT_TYPES[$sub['project_type']] ?? $sub['project_type'], 'work_text' => $work,
    'criteria' => mb_substr(trim((string) ($input['criteria'] ?? '')), 0, 1500),
]);

if (!$result['success']) {
    json_response(['success' => false, 'message' => $result['error']], 502);
}
json_response(['success' => true] + $result['data'] + ['points' => (int) $sub['points']]);
