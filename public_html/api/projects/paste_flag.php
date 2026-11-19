<?php
/**
 * POST /api/projects/paste_flag.php
 * Body: project_id, hits (pegados detectados), attempts (intentos bloqueados), chars (máx. de caracteres de una vez), csrf_token
 * Lo llama assets/js/paste_guard.js. Solo cuenta si el trabajo es del estudiante, sigue en borrador y la asignación bloquea el pegado.
 */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project_helpers.php';
require_once __DIR__ . '/../../includes/group_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');
csrf_verify($_POST['csrf_token'] ?? null);

$projectId = (int) ($_POST['project_id'] ?? 0);
$hits = max(0, min(20, (int) ($_POST['hits'] ?? 0)));
$attempts = max(0, min(50, (int) ($_POST['attempts'] ?? 0)));
$chars = max(0, min(1000000, (int) ($_POST['chars'] ?? 0)));
if ($projectId <= 0 || ($hits === 0 && $attempts === 0)) {
    json_response(['success' => false], 400);
}

$pdo = Database::getConnection();
if (!paste_flag_ready($pdo)) {
    json_response(['success' => false, 'message' => 'Falta la migración.'], 200);
}

$ownerId = project_effective_student($pdo, $projectId, (int) current_user_id());
$stmt = $pdo->prepare(
    'SELECT sp.id FROM student_projects sp
     INNER JOIN assignments a ON a.id = sp.assignment_id
     WHERE sp.id = :id AND sp.student_id = :sid AND sp.status = \'draft\' AND a.block_paste = 1'
);
$stmt->execute(['id' => $projectId, 'sid' => $ownerId]);
if (!$stmt->fetch()) {
    json_response(['success' => false], 200);
}

$pdo->prepare(
    'UPDATE student_projects
     SET paste_count = paste_count + :h, paste_attempts = paste_attempts + :a,
         paste_max_chars = GREATEST(paste_max_chars, :c),
         paste_flagged_at = IF(:h2 > 0, COALESCE(paste_flagged_at, :now), paste_flagged_at)
     WHERE id = :id'
)->execute(['h' => $hits, 'h2' => $hits, 'a' => $attempts, 'c' => $chars, 'now' => now_datetime(), 'id' => $projectId]);

json_response(['success' => true]);
