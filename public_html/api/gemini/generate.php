<?php
/**
 * POST /api/gemini/generate.php
 *
 * Recibe los parámetros del formulario del profesor, pide a GeminiService
 * que genere las preguntas, y devuelve el resultado en JSON para que el
 * profesor lo revise en pantalla ANTES de guardarlo. Este endpoint nunca
 * escribe en la base de datos ni publica nada.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/GeminiService.php';

header('Content-Type: application/json; charset=utf-8');
require_role('teacher');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;

csrf_verify($input['csrf_token'] ?? null);

$pdo = Database::getConnection();
$teacherId = current_user_id();

// Verificar que la asignatura pertenece a un curso de este profesor
$subjectId = (int) ($input['subject_id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT s.name AS subject_name FROM subjects s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN teacher_courses tc ON tc.course_id = c.id
     WHERE s.id = :subject_id AND tc.teacher_id = :teacher_id LIMIT 1'
);
$stmt->execute(['subject_id' => $subjectId, 'teacher_id' => $teacherId]);
$subject = $stmt->fetch();

if (!$subject) {
    json_response(['success' => false, 'error' => 'Selecciona una asignatura válida.'], 400);
}

$tema = clean_string($input['tema'] ?? '');
if ($tema === '') {
    json_response(['success' => false, 'error' => 'Indica el tema de la actividad.'], 400);
}

$params = [
    'subject_name' => $subject['subject_name'],
    'tema'         => $tema,
    'descripcion'  => clean_string($input['descripcion'] ?? ''),
    'objetivo'     => clean_string($input['objetivo'] ?? ''),
    'cantidad'     => (int) ($input['cantidad'] ?? 10),
    'dificultad'   => clean_string($input['dificultad'] ?? 'media'),
    'tipo'         => clean_string($input['tipo'] ?? 'mixto'),
    'tiempo'       => (int) ($input['tiempo'] ?? 20),
    'instrucciones_adicionales' => clean_string($input['instrucciones_adicionales'] ?? ''),
];

$result = GeminiService::generateActivity($params);

if (!$result['success']) {
    json_response(['success' => false, 'error' => $result['error']], 502);
}

audit_log($pdo, $teacherId, 'gemini_generate', "Generó actividad con IA sobre '{$tema}'");

json_response(['success' => true, 'activity' => $result['data']]);
