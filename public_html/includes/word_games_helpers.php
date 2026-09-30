<?php
/**
 * word_games_helpers.php
 * Lógica compartida de los juegos de palabras: AHORCADO y CRUCIGRAMA.
 *
 * Cómo se guardan los datos (sin tablas nuevas):
 *   - Cada palabra es una fila de activity_questions con type = 'palabra':
 *       statement = la PISTA (enunciado)
 *       question_options (1 fila, is_correct = 1) = la PALABRA respuesta
 *   - La actividad usa activities.game_mode = 'ahorcado' o 'crucigrama'.
 *
 * Archivos Aiken: se aceptan dos formas (se puede mezclar en el mismo archivo):
 *   1) Aiken clásico (con opciones): la respuesta es el TEXTO de la opción correcta.
 *        Capital de Francia
 *        A) Madrid
 *        B) París
 *        ANSWER: B          -> palabra = "París"
 *   2) Aiken corto (sin opciones): la respuesta es la propia palabra.
 *        Capital de Francia
 *        ANSWER: París
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const HANGMAN_MAX_LIVES = 6;
const WORD_GAME_MODES = ['ahorcado', 'crucigrama'];

// ---------------------------------------------------------------------------
// Modos de juego (etiquetas / iconos)
// ---------------------------------------------------------------------------

function game_mode_is_word_game(?string $mode): bool
{
    return in_array($mode, WORD_GAME_MODES, true);
}

function game_mode_icon(?string $mode): string
{
    return match ($mode) {
        'sapito'     => '🐸',
        'ahorcado'   => '🪢',
        'crucigrama' => '🧩',
        default      => '🎯',
    };
}

function game_mode_label(?string $mode): string
{
    return match ($mode) {
        'sapito'     => 'El Sapito',
        'ahorcado'   => 'Ahorcado',
        'crucigrama' => 'Crucigrama',
        default      => 'Trivia',
    };
}

// ---------------------------------------------------------------------------
// Normalización de letras / palabras
// ---------------------------------------------------------------------------

/**
 * Devuelve el carácter en mayúscula y sin tilde (la Ñ se conserva como Ñ).
 * Si no es una letra reconocida devuelve cadena vacía.
 */
function word_normalize_char(string $ch): string
{
    $ch = mb_strtolower($ch, 'UTF-8');
    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    $ch = strtr($ch, $map);
    $up = mb_strtoupper($ch, 'UTF-8');
    return preg_match('/^[A-ZÑ]$/u', $up) ? $up : '';
}

/** Descompone un texto en caracteres (seguro para UTF-8). */
function word_chars(string $text): array
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return $chars === false ? [] : $chars;
}

/** Solo las letras (A-Z y Ñ) de una palabra, normalizadas. Ej: "París" -> "PARIS". */
function word_letters(string $word): string
{
    $out = '';
    foreach (word_chars($word) as $ch) {
        $out .= word_normalize_char($ch);
    }
    return $out;
}

/**
 * Valida una palabra para un modo de juego. Devuelve null si es válida o el mensaje de error.
 */
function word_validate_for_mode(string $word, string $mode): ?string
{
    $word = trim($word);
    if ($word === '') {
        return 'La palabra no puede estar vacía.';
    }
    $letters = word_letters($word);
    $len = mb_strlen($letters, 'UTF-8');

    if ($mode === 'crucigrama') {
        // En el crucigrama solo se admiten letras (se ignoran espacios y guiones).
        $stripped = preg_replace('/[\s\-]+/u', '', $word);
        if ($stripped === '' || mb_strlen($stripped, 'UTF-8') !== $len) {
            return 'En el crucigrama la palabra solo puede tener letras (sin números ni símbolos).';
        }
        if ($len < 2 || $len > 15) {
            return 'En el crucigrama la palabra debe tener entre 2 y 15 letras.';
        }
        return null;
    }

    // ahorcado
    if ($len < 2) {
        return 'La palabra debe tener al menos 2 letras.';
    }
    if (mb_strlen($word, 'UTF-8') > 40) {
        return 'La palabra o frase no puede pasar de 40 caracteres.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// Importación desde archivo Aiken
// ---------------------------------------------------------------------------

/**
 * Parsea un archivo Aiken para juegos de palabras.
 * Devuelve ['items' => [['clue' => string, 'word' => string], ...], 'errors' => string[]].
 */
function aiken_parse_words(string $content, string $mode): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    $blocks = preg_split('/\n[ \t]*\n+/', trim($content));
    $items = [];
    $errors = [];
    $seen = [];

    foreach ($blocks as $blockIndex => $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }
        $numero = $blockIndex + 1;

        $lines = array_values(array_filter(
            array_map('rtrim', explode("\n", $block)),
            fn($l) => trim($l) !== ''
        ));

        $statementLines = [];
        $options = [];
        $answerRaw = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:ANSWER|RESPUESTA)\s*:\s*(.+?)\s*$/iu', $line, $m)) {
                $answerRaw = $m[1];
                continue;
            }
            if (preg_match('/^\s*ANSWER\s+([A-Za-z])\s*$/i', $line, $m)) {
                $answerRaw = $m[1];
                continue;
            }
            if ($answerRaw === null && empty($options) && !preg_match('/^\s*[A-Za-z][\.\)]\s+.+$/u', $line)) {
                $statementLines[] = trim($line);
                continue;
            }
            if (preg_match('/^\s*([A-Za-z])[\.\)]\s+(.+)$/u', $line, $m)) {
                $options[] = ['letter' => strtoupper($m[1]), 'text' => trim($m[2])];
            }
        }

        $clue = trim(implode(' ', $statementLines));
        if ($clue === '') {
            $errors[] = "Bloque {$numero}: no se encontró la pista (enunciado).";
            continue;
        }
        if ($answerRaw === null) {
            $errors[] = "Bloque {$numero}: falta la línea \"ANSWER: ...\" (\"{$clue}\").";
            continue;
        }

        $answerRaw = trim($answerRaw, " \t\"'“”");
        $word = null;

        if (preg_match('/^[A-Za-z]$/', $answerRaw) && count($options) >= 2) {
            // Aiken clásico: la respuesta es una letra que apunta a una opción.
            foreach ($options as $o) {
                if ($o['letter'] === strtoupper($answerRaw)) {
                    $word = $o['text'];
                    break;
                }
            }
            if ($word === null) {
                $errors[] = "Bloque {$numero}: la respuesta \"{$answerRaw}\" no coincide con ninguna opción (\"{$clue}\").";
                continue;
            }
        } else {
            $word = $answerRaw;
        }

        $word = trim($word);
        $err = word_validate_for_mode($word, $mode);
        if ($err !== null) {
            $errors[] = "Bloque {$numero} (\"{$clue}\" → \"{$word}\"): {$err}";
            continue;
        }

        if ($mode === 'crucigrama') {
            $key = word_letters($word);
            if (isset($seen[$key])) {
                $errors[] = "Bloque {$numero}: la palabra \"{$word}\" está repetida en el archivo; se omitió.";
                continue;
            }
            $seen[$key] = true;
        }

        $items[] = ['clue' => $clue, 'word' => $word];
    }

    return ['items' => $items, 'errors' => $errors];
}

/**
 * Inserta palabras (pista + palabra) en una actividad. En el crucigrama omite las que
 * ya existen en la actividad. Devuelve ['inserted' => int, 'skipped' => string[]].
 */
function aiken_import_words_into_activity(PDO $pdo, int $activityId, array $items, int $timeSeconds, int $points, string $mode): array
{
    $existing = [];
    if ($mode === 'crucigrama') {
        foreach (word_activity_words($pdo, $activityId) as $w) {
            $existing[word_letters($w['word'])] = true;
        }
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM activity_questions WHERE activity_id = :activity_id');
    $countStmt->execute(['activity_id' => $activityId]);
    $orderIndex = (int) ($countStmt->fetch()['total'] ?? 0);

    $insertQ = $pdo->prepare(
        'INSERT INTO activity_questions (activity_id, type, statement, time_seconds, points, order_index, created_at)
         VALUES (:activity_id, \'palabra\', :statement, :time_seconds, :points, :order_index, :created_at)'
    );
    $insertOpt = $pdo->prepare(
        'INSERT INTO question_options (question_id, text, is_correct, order_index) VALUES (:qid, :text, 1, 0)'
    );

    $inserted = 0;
    $skipped = [];

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            if ($mode === 'crucigrama') {
                $key = word_letters($item['word']);
                if (isset($existing[$key])) {
                    $skipped[] = $item['word'];
                    continue;
                }
                $existing[$key] = true;
            }
            $insertQ->execute([
                'activity_id' => $activityId, 'statement' => $item['clue'],
                'time_seconds' => $timeSeconds, 'points' => $points,
                'order_index' => $orderIndex, 'created_at' => now_datetime(),
            ]);
            $qid = (int) $pdo->lastInsertId();
            $insertOpt->execute(['qid' => $qid, 'text' => $item['word']]);
            $orderIndex++;
            $inserted++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

/** Lista simple de palabras de una actividad: [['id','clue','word'], ...]. */
function word_activity_words(PDO $pdo, int $activityId): array
{
    $stmt = $pdo->prepare(
        "SELECT q.id, q.statement AS clue,
                (SELECT o.text FROM question_options o WHERE o.question_id = q.id ORDER BY o.order_index ASC, o.id ASC LIMIT 1) AS word
         FROM activity_questions q
         WHERE q.activity_id = :activity_id AND q.type = 'palabra'
         ORDER BY q.order_index ASC, q.id ASC"
    );
    $stmt->execute(['activity_id' => $activityId]);
    return array_map(fn($r) => ['id' => (int) $r['id'], 'clue' => $r['clue'], 'word' => (string) $r['word']], $stmt->fetchAll());
}

// ---------------------------------------------------------------------------
// AHORCADO
// ---------------------------------------------------------------------------

/**
 * Estado del ahorcado para una palabra y las letras ya intentadas.
 * $guesses: letras normalizadas (A-Z, Ñ). Nunca revela letras no adivinadas.
 * Devuelve ['pattern' => string[], 'guessed' => string[], 'wrong' => string[],
 *           'lives_left' => int, 'max_lives' => int, 'solved' => bool, 'lost' => bool]
 */
function hangman_state(string $word, array $guesses): array
{
    $guesses = array_values(array_unique($guesses));
    $wordLetters = array_unique(word_chars(word_letters($word)));

    $wrong = [];
    foreach ($guesses as $g) {
        if (!in_array($g, $wordLetters, true)) {
            $wrong[] = $g;
        }
    }

    $pattern = [];
    $allFound = true;
    foreach (word_chars($word) as $ch) {
        $n = word_normalize_char($ch);
        if ($n === '') {
            $pattern[] = $ch; // espacio, guion, signo: se muestra tal cual
        } elseif (in_array($n, $guesses, true)) {
            $pattern[] = mb_strtoupper($ch, 'UTF-8'); // se muestra con su tilde original
        } else {
            $pattern[] = '_';
            $allFound = false;
        }
    }

    $lives = max(0, HANGMAN_MAX_LIVES - count($wrong));

    return [
        'pattern'    => $pattern,
        'guessed'    => $guesses,
        'wrong'      => $wrong,
        'lives_left' => $lives,
        'max_lives'  => HANGMAN_MAX_LIVES,
        'solved'     => $allFound,
        'lost'       => !$allFound && $lives <= 0,
    ];
}

/** Decodifica un JSON guardado (estado del intento) a arreglo; nunca falla. */
function word_game_decode_answer(?string $raw): array
{
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

// ---------------------------------------------------------------------------
// CRUCIGRAMA
// ---------------------------------------------------------------------------

/**
 * Comprueba si una palabra cabe en (r,c) con la dirección dada ($dir 0 = horizontal, 1 = vertical).
 * Devuelve el número de cruces con letras ya colocadas, o -1 si no cabe.
 * $grid: "r,c" => letra ; $dirs: "r,c" => máscara (1 horizontal, 2 vertical)
 */
function crossword_can_place(array $grid, array $dirs, array $chars, int $r, int $c, int $dir): int
{
    $dr = $dir === 1 ? 1 : 0;
    $dc = $dir === 0 ? 1 : 0;
    $len = count($chars);

    if (isset($grid[($r - $dr) . ',' . ($c - $dc)])) {
        return -1; // casilla justo antes ocupada
    }
    if (isset($grid[($r + $len * $dr) . ',' . ($c + $len * $dc)])) {
        return -1; // casilla justo después ocupada
    }

    $cross = 0;
    $mask = $dir === 0 ? 1 : 2;

    for ($k = 0; $k < $len; $k++) {
        $rr = $r + $k * $dr;
        $cc = $c + $k * $dc;
        $key = $rr . ',' . $cc;

        if (isset($grid[$key])) {
            if ($grid[$key] !== $chars[$k]) {
                return -1;
            }
            if (($dirs[$key] & $mask) !== 0) {
                return -1; // se superpondría con otra palabra en la misma dirección
            }
            $cross++;
        } else {
            // Casilla nueva: sus vecinas laterales deben estar vacías (evita palabras pegadas).
            if (isset($grid[($rr + $dc) . ',' . ($cc + $dr)]) || isset($grid[($rr - $dc) . ',' . ($cc - $dr)])) {
                return -1;
            }
        }
    }

    return $cross;
}

/** Un intento de armar el crucigrama empezando por la palabra $items[$seedIdx]. */
function crossword_try_layout(array $items, int $seedIdx): array
{
    $order = $items;
    $seed = array_splice($order, $seedIdx, 1);
    $order = array_merge($seed, $order);

    $grid = [];
    $dirs = [];
    $placed = [];
    $bbox = null; // [minR, maxR, minC, maxC]

    $place = function (array $item, int $r, int $c, int $dir) use (&$grid, &$dirs, &$placed, &$bbox) {
        $dr = $dir === 1 ? 1 : 0;
        $dc = $dir === 0 ? 1 : 0;
        $mask = $dir === 0 ? 1 : 2;
        foreach ($item['chars'] as $k => $ch) {
            $key = ($r + $k * $dr) . ',' . ($c + $k * $dc);
            $grid[$key] = $ch;
            $dirs[$key] = ($dirs[$key] ?? 0) | $mask;
        }
        $endR = $r + (count($item['chars']) - 1) * $dr;
        $endC = $c + (count($item['chars']) - 1) * $dc;
        $bbox = $bbox === null
            ? [$r, $endR, $c, $endC]
            : [min($bbox[0], $r), max($bbox[1], $endR), min($bbox[2], $c), max($bbox[3], $endC)];
        $placed[] = ['item' => $item, 'r' => $r, 'c' => $c, 'dir' => $dir];
    };

    $place($order[0], 0, 0, 0);
    $pending = array_slice($order, 1);

    do {
        $progress = false;
        foreach ($pending as $pi => $item) {
            $best = null;
            $bestScore = null;

            foreach ($placed as $pw) {
                foreach ($pw['item']['chars'] as $i => $pch) {
                    foreach ($item['chars'] as $j => $ch) {
                        if ($ch !== $pch) {
                            continue;
                        }
                        if ($pw['dir'] === 0) {
                            $r = $pw['r'] - $j;
                            $c = $pw['c'] + $i;
                            $dir = 1;
                        } else {
                            $r = $pw['r'] + $i;
                            $c = $pw['c'] - $j;
                            $dir = 0;
                        }
                        $cross = crossword_can_place($grid, $dirs, $item['chars'], $r, $c, $dir);
                        if ($cross < 1) {
                            continue;
                        }
                        $len = count($item['chars']);
                        $endR = $r + ($dir === 1 ? $len - 1 : 0);
                        $endC = $c + ($dir === 0 ? $len - 1 : 0);
                        $h = max($bbox[1], $endR) - min($bbox[0], $r) + 1;
                        $w = max($bbox[3], $endC) - min($bbox[2], $c) + 1;
                        // Más cruces es mejor; luego el tablero más compacto y cuadrado.
                        $score = $cross * 10000 - ($h * $w) * 10 - abs($h - $w);
                        if ($bestScore === null || $score > $bestScore) {
                            $bestScore = $score;
                            $best = [$r, $c, $dir];
                        }
                    }
                }
            }

            if ($best !== null) {
                $place($item, $best[0], $best[1], $best[2]);
                unset($pending[$pi]);
                $progress = true;
            }
        }
    } while ($progress && !empty($pending));

    return ['placed' => $placed, 'unplaced' => array_values($pending), 'bbox' => $bbox];
}

/**
 * Arma el crucigrama de forma DETERMINISTA a partir de las palabras de la actividad
 * (mismas palabras => mismo tablero para todos los estudiantes).
 *
 * $words: filas de activity_questions con 'id', 'statement' (pista) y 'options'[0]['text'] (palabra).
 * Devuelve:
 *   ['rows' => int, 'cols' => int,
 *    'words' => [['id','number','dir' => 'across'|'down','row','col','length','clue','answer'], ...],
 *    'unplaced' => [['id','clue','word'], ...]]
 */
function crossword_build_layout(array $words): array
{
    $items = [];
    $seen = [];
    foreach ($words as $w) {
        $raw = (string) ($w['options'][0]['text'] ?? ($w['word'] ?? ''));
        $answer = word_letters($raw);
        $chars = word_chars($answer);
        if (count($chars) < 2 || isset($seen[$answer])) {
            continue;
        }
        $seen[$answer] = true;
        $items[] = [
            'id' => (int) $w['id'], 'clue' => (string) ($w['statement'] ?? ($w['clue'] ?? '')),
            'raw' => $raw, 'answer' => $answer, 'chars' => $chars,
        ];
    }

    if (empty($items)) {
        return ['rows' => 0, 'cols' => 0, 'words' => [], 'unplaced' => []];
    }

    // Orden estable: más largas primero; a igualdad, por id.
    usort($items, fn($a, $b) => [count($b['chars']), $a['id']] <=> [count($a['chars']), $b['id']]);

    $best = null;
    $tries = min(6, count($items));
    for ($s = 0; $s < $tries; $s++) {
        $t = crossword_try_layout($items, $s);
        $bb = $t['bbox'];
        $area = ($bb[1] - $bb[0] + 1) * ($bb[3] - $bb[2] + 1);
        $t['area'] = $area;
        if ($best === null
            || count($t['placed']) > count($best['placed'])
            || (count($t['placed']) === count($best['placed']) && $area < $best['area'])) {
            $best = $t;
        }
    }

    $minR = $best['bbox'][0];
    $minC = $best['bbox'][2];
    $rows = $best['bbox'][1] - $minR + 1;
    $cols = $best['bbox'][3] - $minC + 1;

    $entries = [];
    foreach ($best['placed'] as $p) {
        $entries[] = [
            'id' => $p['item']['id'], 'dir' => $p['dir'] === 0 ? 'across' : 'down',
            'row' => $p['r'] - $minR, 'col' => $p['c'] - $minC, 'length' => count($p['item']['chars']),
            'clue' => $p['item']['clue'], 'answer' => $p['item']['answer'],
        ];
    }

    // Numeración: por fila y columna de inicio; si dos palabras empiezan en la misma casilla comparten número.
    usort($entries, fn($a, $b) => [$a['row'], $a['col'], $a['dir']] <=> [$b['row'], $b['col'], $b['dir']]);
    $numbers = [];
    $next = 1;
    foreach ($entries as &$e) {
        $k = $e['row'] . ',' . $e['col'];
        if (!isset($numbers[$k])) {
            $numbers[$k] = $next++;
        }
        $e['number'] = $numbers[$k];
    }
    unset($e);

    $unplaced = array_map(fn($i) => ['id' => $i['id'], 'clue' => $i['clue'], 'word' => $i['raw']], $best['unplaced']);

    return ['rows' => $rows, 'cols' => $cols, 'words' => $entries, 'unplaced' => $unplaced];
}

/** Versión del crucigrama para enviar a estudiantes: SIN las respuestas. */
function crossword_public_layout(array $layout): array
{
    return [
        'rows' => $layout['rows'], 'cols' => $layout['cols'],
        'words' => array_map(function ($w) {
            unset($w['answer']);
            return $w;
        }, $layout['words']),
    ];
}

/** Casillas del crucigrama: "r,c" => letra correcta. */
function crossword_solution_cells(array $layout): array
{
    $cells = [];
    foreach ($layout['words'] as $w) {
        foreach (word_chars($w['answer']) as $k => $ch) {
            $r = $w['row'] + ($w['dir'] === 'down' ? $k : 0);
            $c = $w['col'] + ($w['dir'] === 'across' ? $k : 0);
            $cells[$r . ',' . $c] = $ch;
        }
    }
    return $cells;
}

/** Limpia lo que envía el estudiante: solo casillas "r,c" válidas del tablero con una letra A-Z/Ñ. */
function crossword_sanitize_cells(array $layout, $raw): array
{
    $valid = crossword_solution_cells($layout);
    $out = [];
    if (!is_array($raw)) {
        return $out;
    }
    foreach ($raw as $key => $val) {
        $key = (string) $key;
        if (!isset($valid[$key]) || !is_string($val)) {
            continue;
        }
        $n = word_normalize_char(mb_substr($val, 0, 1, 'UTF-8'));
        if ($n !== '') {
            $out[$key] = $n;
        }
    }
    return $out;
}

/** Califica: ['correct' => int, 'total' => int, 'words' => [id => bool]] */
function crossword_grade(array $layout, array $cells): array
{
    $correct = 0;
    $result = [];
    foreach ($layout['words'] as $w) {
        $ok = true;
        foreach (word_chars($w['answer']) as $k => $ch) {
            $r = $w['row'] + ($w['dir'] === 'down' ? $k : 0);
            $c = $w['col'] + ($w['dir'] === 'across' ? $k : 0);
            if (($cells[$r . ',' . $c] ?? '') !== $ch) {
                $ok = false;
                break;
            }
        }
        $result[$w['id'] . ':' . $w['dir']] = $ok;
        if ($ok) {
            $correct++;
        }
    }
    return ['correct' => $correct, 'total' => count($layout['words']), 'words' => $result];
}

/** Tablero con las respuestas (HTML seguro) para que el profesor revise cómo quedó el crucigrama. */
function crossword_render_solution_html(array $layout): string
{
    if (empty($layout['words'])) {
        return '';
    }
    $cells = crossword_solution_cells($layout);
    $starts = [];
    foreach ($layout['words'] as $w) {
        $starts[$w['row'] . ',' . $w['col']] = $w['number'];
    }

    $html = '<div style="overflow-x:auto;"><table style="border-collapse:collapse; margin:8px 0;">';
    for ($r = 0; $r < $layout['rows']; $r++) {
        $html .= '<tr>';
        for ($c = 0; $c < $layout['cols']; $c++) {
            $k = $r . ',' . $c;
            if (isset($cells[$k])) {
                $num = isset($starts[$k]) ? '<span style="position:absolute; top:1px; left:2px; font-size:0.55rem; color:#64748B;">' . (int) $starts[$k] . '</span>' : '';
                $html .= '<td style="position:relative; width:28px; height:28px; border:1px solid #334155; background:#fff; text-align:center; font-weight:700; font-size:0.9rem;">'
                    . $num . htmlspecialchars($cells[$k], ENT_QUOTES, 'UTF-8') . '</td>';
            } else {
                $html .= '<td style="width:28px; height:28px; border:none;"></td>';
            }
        }
        $html .= '</tr>';
    }
    $html .= '</table></div>';
    return $html;
}
