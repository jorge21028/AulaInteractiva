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
    return game_calculate_points_ratio($isCorrect ? 1.0 : 0.0, $questionPoints, $speedBonusMax, $timeSeconds, $responseTimeMs);
}

/**
 * Igual que game_calculate_points(), pero para respuestas con crédito
 * parcial (ordenar, relacionar): $ratio va de 0.0 (nada correcto) a
 * 1.0 (todo correcto). Tanto los puntos base como la bonificación por
 * rapidez se escalan proporcionalmente al acierto.
 */
function game_calculate_points_ratio(float $ratio, int $questionPoints, int $speedBonusMax, int $timeSeconds, int $responseTimeMs): int
{
    $ratio = max(0.0, min(1.0, $ratio));
    if ($ratio <= 0.0) {
        return 0;
    }

    $totalMs = max($timeSeconds * 1000, 1);
    $remainingMs = max($totalMs - $responseTimeMs, 0);
    $bonus = (int) round($speedBonusMax * ($remainingMs / $totalMs) * $ratio);

    return (int) round($questionPoints * $ratio) + $bonus;
}

/**
 * Mezcla estable: da siempre el mismo resultado para la misma semilla,
 * sin alterar el generador aleatorio global de PHP. Se usa para que el
 * orden "desordenado" que ve el estudiante (en preguntas de ordenar o
 * relacionar) sea el mismo en cada consulta de polling, en vez de
 * cambiar cada 1-2 segundos.
 */
function game_stable_shuffle(array $items, int $seed): array
{
    $items = array_values($items);
    mt_srand($seed);
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    mt_srand(); // volver a una semilla aleatoria, para no afectar el resto del sistema
    return $items;
}

/**
 * Reconstruye, de forma determinista, el orden "desordenado" de los
 * elementos de la derecha en una pregunta de tipo "relacionar", tal
 * como se le mostró al estudiante. Se necesita tanto para mostrárselo
 * (state.php) como para calificar su respuesta (answer.php), ya que el
 * estudiante responde con el índice dentro de esa lista mezclada.
 */
function game_shuffled_right_items(array $options, int $gameId, int $questionId): array
{
    $texts = array_map(fn($o) => (string) $o['match_text'], $options);
    return game_stable_shuffle($texts, $gameId * 1000003 + $questionId);
}

/**
 * Califica una respuesta de tipo "ordenar": compara, posición por
 * posición, el orden que envió el estudiante contra el orden correcto
 * (definido por order_index). Devuelve una proporción de 0.0 a 1.0.
 *
 * @param array $options Las opciones de la pregunta (con 'id' y 'order_index'), en cualquier orden.
 * @param array $submittedOrder Array de option_id en el orden que eligió el estudiante.
 */
function game_score_ordenar(array $options, array $submittedOrder): float
{
    if (empty($options)) {
        return 0.0;
    }

    $correctOrder = $options;
    usort($correctOrder, fn($a, $b) => $a['order_index'] <=> $b['order_index']);
    $correctIds = array_map(fn($o) => (int) $o['id'], $correctOrder);

    $total = count($correctIds);
    $correctCount = 0;
    for ($i = 0; $i < $total; $i++) {
        $submittedId = isset($submittedOrder[$i]) ? (int) $submittedOrder[$i] : null;
        if ($submittedId === $correctIds[$i]) {
            $correctCount++;
        }
    }

    return $total > 0 ? $correctCount / $total : 0.0;
}

/**
 * Califica una respuesta de tipo "relacionar". El estudiante envía,
 * para cada opción (elemento izquierdo), el índice dentro de la lista
 * mezclada de elementos derechos que eligió como pareja.
 *
 * @param array $options Las opciones de la pregunta (con 'id' y 'match_text').
 * @param array $submittedPairs Array de ['option_id' => int, 'right_index' => int].
 * @param array $shuffledRightTexts La lista mezclada de textos derechos (ver game_shuffled_right_items()).
 */
function game_score_relacionar(array $options, array $submittedPairs, array $shuffledRightTexts): float
{
    if (empty($options)) {
        return 0.0;
    }

    $byId = [];
    foreach ($options as $o) {
        $byId[(int) $o['id']] = $o;
    }

    $submittedById = [];
    foreach ($submittedPairs as $pair) {
        if (!is_array($pair) || !isset($pair['option_id'])) {
            continue;
        }
        $submittedById[(int) $pair['option_id']] = $pair['right_index'] ?? null;
    }

    $total = count($byId);
    $correctCount = 0;

    foreach ($byId as $optionId => $option) {
        $rightIndex = $submittedById[$optionId] ?? null;
        if ($rightIndex === null || !isset($shuffledRightTexts[$rightIndex])) {
            continue;
        }
        $chosenText = mb_strtolower(trim((string) $shuffledRightTexts[$rightIndex]));
        $correctText = mb_strtolower(trim((string) $option['match_text']));
        if ($chosenText !== '' && $chosenText === $correctText) {
            $correctCount++;
        }
    }

    return $total > 0 ? $correctCount / $total : 0.0;
}

/**
 * Califica una respuesta de tipo "completar" (una sola respuesta
 * correcta en texto libre): comparación exacta, sin distinguir
 * mayúsculas/minúsculas ni espacios sobrantes al inicio/final.
 */
function game_score_completar(string $correctAnswer, string $submittedAnswer): float
{
    $a = mb_strtolower(trim($correctAnswer));
    $b = mb_strtolower(trim($submittedAnswer));
    if ($a === '') {
        return 0.0;
    }
    return $a === $b ? 1.0 : 0.0;
}

/**
 * Nombre legible para cada tipo de pregunta soportado, usado en varias
 * pantallas (lista de preguntas del profesor, resultados, etc.).
 */
function question_type_label(string $type): string
{
    return match ($type) {
        'multiple' => 'Selección múltiple',
        'truefalse' => 'Verdadero/Falso',
        'ordenar' => 'Ordenar elementos',
        'relacionar' => 'Relacionar parejas',
        'completar' => 'Completar espacios',
        default => ucfirst($type),
    };
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
