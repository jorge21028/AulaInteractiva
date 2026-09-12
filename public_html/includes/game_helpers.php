<?php
/**
 * game_helpers.php
 * Lógica compartida del sistema de partidas en tiempo real (Fase 2).
 * Sincronización basada en polling (fetch + consultas periódicas),
 * sin WebSockets, para ser compatible con InfinityFree.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/**
 * Busca una partida por código. Prioriza partidas no finalizadas
 * (un código solo se reutiliza una vez la partida anterior termina).
 */
function game_find_by_code(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM games WHERE code = :code AND status != 'finished' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['code' => $code]);
    $game = $stmt->fetch();

    if (!$game) {
        // Si no hay ninguna activa, devolver la más reciente (ej: para ver resultados finales)
        $stmt = $pdo->prepare('SELECT * FROM games WHERE code = :code ORDER BY id DESC LIMIT 1');
        $stmt->execute(['code' => $code]);
        $game = $stmt->fetch() ?: null;
    }

    return $game ?: null;
}

function game_find_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM games WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Devuelve todas las preguntas de una actividad, con sus opciones, en orden.
 */
function activity_fetch_questions(PDO $pdo, int $activityId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM activity_questions WHERE activity_id = :activity_id ORDER BY order_index ASC, id ASC'
    );
    $stmt->execute(['activity_id' => $activityId]);
    $questions = $stmt->fetchAll();

    foreach ($questions as &$q) {
        $optStmt = $pdo->prepare(
            'SELECT * FROM question_options WHERE question_id = :question_id ORDER BY order_index ASC, id ASC'
        );
        $optStmt->execute(['question_id' => $q['id']]);
        $q['options'] = $optStmt->fetchAll();
    }
    unset($q);

    return $questions;
}

/**
 * Si la partida está en estado 'question' y ya se acabó el tiempo,
 * la avanza automáticamente a 'question_results'. Debe llamarse en
 * cada polling antes de construir la respuesta, para que el sistema
 * avance aunque el profesor no esté mirando la pantalla exactamente
 * en el segundo final.
 */
function game_auto_advance_if_expired(PDO $pdo, array $game, array $questions): array
{
    if ($game['status'] !== 'question') {
        return $game;
    }

    $idx = (int) $game['current_question_index'];
    if (!isset($questions[$idx])) {
        return $game;
    }

    $timeSeconds = (int) $questions[$idx]['time_seconds'];
    $startedAt = new DateTime($game['current_question_started_at']);
    $now = new DateTime();
    $elapsed = $now->getTimestamp() - $startedAt->getTimestamp();

    if ($elapsed >= $timeSeconds) {
        $stmt = $pdo->prepare("UPDATE games SET status = 'question_results' WHERE id = :id");
        $stmt->execute(['id' => $game['id']]);
        $game['status'] = 'question_results';
    }

    return $game;
}

/**
 * Avanza la partida a la siguiente pregunta, o la finaliza si ya no hay más.
 */
function game_advance(PDO $pdo, array $game, array $questions): array
{
    $nextIndex = (int) $game['current_question_index'] + 1;

    if ($nextIndex >= count($questions)) {
        $stmt = $pdo->prepare(
            "UPDATE games SET status = 'finished', finished_at = :finished_at WHERE id = :id"
        );
        $stmt->execute(['finished_at' => now_datetime(), 'id' => $game['id']]);
        $game['status'] = 'finished';
        $game['finished_at'] = now_datetime();
        return $game;
    }

    $stmt = $pdo->prepare(
        "UPDATE games SET status = 'question', current_question_index = :idx, current_question_started_at = :started_at WHERE id = :id"
    );
    $stmt->execute([
        'idx'        => $nextIndex,
        'started_at' => now_datetime(),
        'id'         => $game['id'],
    ]);

    $game['status'] = 'question';
    $game['current_question_index'] = $nextIndex;
    $game['current_question_started_at'] = now_datetime();

    return $game;
}

/**
 * Calcula puntos por una respuesta: puntos base si es correcta, más
 * una bonificación por rapidez proporcional al tiempo restante.
 */
function game_calculate_points(bool $isCorrect, int $questionPoints, int $speedBonusMax, int $timeSeconds, int $responseTimeMs): int
{
    if (!$isCorrect) {
        return 0;
    }

    $totalMs = max($timeSeconds * 1000, 1);
    $remainingMs = max($totalMs - $responseTimeMs, 0);
    $bonus = (int) round($speedBonusMax * ($remainingMs / $totalMs));

    return $questionPoints + $bonus;
}

/**
 * Devuelve el ranking de jugadores de una partida, ordenado por puntaje.
 */
function game_ranking(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, nickname, score FROM game_players WHERE game_id = :game_id ORDER BY score DESC, joined_at ASC'
    );
    $stmt->execute(['game_id' => $gameId]);
    return $stmt->fetchAll();
}

/**
 * Cuenta los jugadores que ya respondieron la pregunta actual.
 */
function game_answered_count(PDO $pdo, int $gameId, int $questionId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total FROM game_answers WHERE game_id = :game_id AND question_id = :question_id'
    );
    $stmt->execute(['game_id' => $gameId, 'question_id' => $questionId]);
    return (int) ($stmt->fetch()['total'] ?? 0);
}

/**
 * Une (o reutiliza la unión existente de) un estudiante a una partida por código.
 * Devuelve ['success' => bool, 'message' => string, 'game' => array|null].
 * Se usa tanto desde /api/games/join.php (AJAX) como desde /game/join.php y
 * /game/play.php (unión automática al abrir un enlace directo con código).
 */
function game_join_student(PDO $pdo, string $code, int $studentId, string $nickname): array
{
    $game = game_find_by_code($pdo, $code);

    if (!$game) {
        return ['success' => false, 'message' => 'No existe ninguna partida con ese código.', 'game' => null];
    }

    if ($game['status'] === 'finished') {
        return ['success' => false, 'message' => 'Esta partida ya finalizó.', 'game' => $game];
    }

    $check = $pdo->prepare('SELECT id FROM game_players WHERE game_id = :game_id AND student_id = :student_id');
    $check->execute(['game_id' => $game['id'], 'student_id' => $studentId]);

    if (!$check->fetch()) {
        $stmt = $pdo->prepare(
            'INSERT INTO game_players (game_id, student_id, nickname, score, joined_at) VALUES (:game_id, :student_id, :nickname, 0, :joined_at)'
        );
        $stmt->execute([
            'game_id'    => $game['id'],
            'student_id' => $studentId,
            'nickname'   => $nickname !== '' ? $nickname : 'Estudiante',
            'joined_at'  => now_datetime(),
        ]);
    }

    return ['success' => true, 'message' => '', 'game' => $game];
}

/**
 * Distribución de respuestas (cuántos eligieron cada opción) para la pantalla de resultados.
 */
function game_answer_distribution(PDO $pdo, int $gameId, int $questionId): array
{
    $stmt = $pdo->prepare(
        'SELECT option_id, COUNT(*) AS total FROM game_answers
         WHERE game_id = :game_id AND question_id = :question_id
         GROUP BY option_id'
    );
    $stmt->execute(['game_id' => $gameId, 'question_id' => $questionId]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $result[(int) $row['option_id']] = (int) $row['total'];
    }
    return $result;
}
