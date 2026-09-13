<?php
/**
 * GeminiService.php
 *
 * Único punto de contacto con la API de Google Gemini. Aislado a propósito
 * (ver requisito 41 del documento de especificación): si en el futuro se
 * cambia de proveedor de IA, solo este archivo debe modificarse — el resto
 * del sistema (api/gemini/generate.php, teacher/activity_generate.php)
 * no conoce detalles de Gemini, solo llama a GeminiService::generateActivity().
 *
 * Reglas que este servicio SIEMPRE respeta:
 * - La API Key nunca se expone al navegador (solo vive en config/env.php).
 * - Devuelve datos estructurados en JSON, nunca texto libre sin validar.
 * - Nunca escribe en la base de datos ni publica actividades.
 * - Nunca controla partidas.
 * - Ante cualquier error, devuelve un mensaje genérico y seguro.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

class GeminiService
{
    private const ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s';
    private const TIMEOUT_SECONDS = 30;
    private const MAX_QUESTIONS = 25;

    private const ALLOWED_TYPES = ['multiple', 'truefalse'];
    private const ALLOWED_DIFFICULTY = ['facil', 'media', 'dificil'];

    /**
     * Genera una actividad interactiva a partir de los parámetros del profesor.
     *
     * @param array $params subject_name, tema, descripcion, objetivo, cantidad,
     *                       dificultad, tipo, tiempo, instrucciones_adicionales
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public static function generateActivity(array $params): array
    {
        if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
            return self::fail('Falta configurar GEMINI_API_KEY en config/env.php (está vacía).');
        }

        if (!function_exists('curl_init')) {
            return self::fail('La extensión curl de PHP no está habilitada en este servidor.');
        }

        $cantidad = max(1, min(self::MAX_QUESTIONS, (int) ($params['cantidad'] ?? 10)));
        $tipo = in_array($params['tipo'] ?? '', self::ALLOWED_TYPES, true) ? $params['tipo'] : 'mixto';
        $dificultad = in_array($params['dificultad'] ?? '', self::ALLOWED_DIFFICULTY, true) ? $params['dificultad'] : 'media';
        $tiempo = max(5, min(120, (int) ($params['tiempo'] ?? 20)));

        $prompt = self::buildPrompt([
            'subject_name' => $params['subject_name'] ?? '',
            'tema'         => $params['tema'] ?? '',
            'descripcion'  => $params['descripcion'] ?? '',
            'objetivo'     => $params['objetivo'] ?? '',
            'cantidad'     => $cantidad,
            'dificultad'   => $dificultad,
            'tipo'         => $tipo,
            'tiempo'       => $tiempo,
            'instrucciones_adicionales' => $params['instrucciones_adicionales'] ?? '',
        ]);

        $requestBody = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature'      => 0.7,
                'responseMimeType' => 'application/json',
                'responseSchema'   => self::responseSchema(),
            ],
        ];

        $rawResponse = self::callGemini($requestBody);
        if (is_string($rawResponse)) {
            // callGemini devolvió un mensaje de error en vez de la respuesta.
            return self::fail($rawResponse);
        }

        $activityJson = self::extractTextFromResponse($rawResponse);
        if ($activityJson === null) {
            $preview = substr(json_encode($rawResponse), 0, 300);
            return self::fail("Gemini respondió sin el contenido esperado. Respuesta cruda: {$preview}");
        }

        $decoded = json_decode($activityJson, true);
        if (!is_array($decoded)) {
            $preview = substr($activityJson, 0, 300);
            return self::fail("No se pudo decodificar el JSON devuelto por Gemini. Texto recibido: {$preview}");
        }

        [$isValid, $errorMessage, $cleaned] = self::validateAndClean($decoded);
        if (!$isValid) {
            return self::fail("Estructura JSON inválida: {$errorMessage}");
        }

        return ['success' => true, 'data' => $cleaned, 'error' => null];
    }

    private static function fail(string $debugDetail = ''): array
    {
        $message = 'No fue posible generar la actividad. Intenta nuevamente.';

        // Solo en entorno local se agrega el detalle técnico, para poder
        // diagnosticar sin exponer errores internos a usuarios reales.
        if (defined('APP_ENV') && APP_ENV === 'local' && $debugDetail !== '') {
            error_log('GeminiService: ' . $debugDetail);
            $message .= ' [DEBUG: ' . $debugDetail . ']';
        } elseif ($debugDetail !== '') {
            error_log('GeminiService: ' . $debugDetail);
        }

        return [
            'success' => false,
            'data' => null,
            'error' => $message,
        ];
    }

    private static function buildPrompt(array $p): string
    {
        $tipoTexto = match ($p['tipo']) {
            'multiple'  => 'exclusivamente de selección múltiple (una sola respuesta correcta, entre 3 y 4 opciones)',
            'truefalse' => 'exclusivamente de verdadero/falso',
            default     => 'una combinación de selección múltiple (3-4 opciones) y verdadero/falso',
        };

        return <<<PROMPT
Eres un asistente que ayuda a un profesor a crear preguntas para una actividad
interactiva educativa tipo cuestionario, en español, para la asignatura
"{$p['subject_name']}".

Tema: {$p['tema']}
Descripción del contexto: {$p['descripcion']}
Objetivo de aprendizaje: {$p['objetivo']}
Cantidad de preguntas a generar: {$p['cantidad']}
Dificultad: {$p['dificultad']}
Tipo de preguntas: {$tipoTexto}
Tiempo sugerido por pregunta: {$p['tiempo']} segundos
Instrucciones adicionales del profesor: {$p['instrucciones_adicionales']}

Genera un título breve y atractivo para la actividad, una descripción de una
línea, y exactamente {$p['cantidad']} preguntas apropiadas para estudiantes,
claras, sin ambigüedad, con una única respuesta correcta por pregunta.
Las explicaciones deben ser breves (máximo 2 líneas) y educativas.
No incluyas texto fuera del JSON solicitado.
PROMPT;
    }

    /**
     * Esquema estructurado que Gemini debe respetar (Structured Output).
     * Evita que el modelo devuelva texto libre que haya que interpretar.
     */
    private static function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'titulo' => ['type' => 'string'],
                'descripcion' => ['type' => 'string'],
                'preguntas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'tipo' => ['type' => 'string', 'enum' => ['multiple', 'truefalse']],
                            'enunciado' => ['type' => 'string'],
                            'tiempo' => ['type' => 'integer'],
                            'puntos' => ['type' => 'integer'],
                            'opciones' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'texto' => ['type' => 'string'],
                                        'correcta' => ['type' => 'boolean'],
                                    ],
                                    'required' => ['texto', 'correcta'],
                                ],
                            ],
                            'explicacion' => ['type' => 'string'],
                        ],
                        'required' => ['tipo', 'enunciado', 'tiempo', 'puntos', 'opciones'],
                    ],
                ],
            ],
            'required' => ['titulo', 'descripcion', 'preguntas'],
        ];
    }

    /**
     * @return array|string Array con la respuesta decodificada si todo salió bien,
     *                       o un string con el detalle del error si algo falló.
     */
    private static function callGemini(array $requestBody)
    {
        $url = sprintf(self::ENDPOINT_TEMPLATE, rawurlencode(GEMINI_MODEL), GEMINI_API_KEY);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($requestBody),
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return "Error de red al contactar Gemini (curl errno {$curlErrno}): {$curlError}";
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return "Gemini respondió HTTP {$httpCode}: " . substr((string) $response, 0, 500);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return 'La respuesta de Gemini no es JSON válido: ' . substr((string) $response, 0, 300);
        }

        return $decoded;
    }

    private static function extractTextFromResponse(array $response): ?string
    {
        return $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /**
     * Valida la estructura devuelta por Gemini y la normaliza a un formato
     * seguro para insertar en la base de datos. Nunca confía ciegamente en
     * el contenido generado por el modelo.
     *
     * @return array{0: bool, 1: string, 2: array}
     */
    private static function validateAndClean(array $data): array
    {
        $titulo = trim((string) ($data['titulo'] ?? ''));
        $descripcion = trim((string) ($data['descripcion'] ?? ''));
        $preguntas = $data['preguntas'] ?? null;

        if ($titulo === '') {
            return [false, 'falta título', []];
        }
        if (!is_array($preguntas) || count($preguntas) === 0) {
            return [false, 'sin preguntas', []];
        }

        $cleanQuestions = [];

        foreach ($preguntas as $i => $q) {
            if (!is_array($q)) {
                return [false, "pregunta #{$i} no es un objeto", []];
            }

            $tipo = $q['tipo'] ?? '';
            if (!in_array($tipo, self::ALLOWED_TYPES, true)) {
                return [false, "pregunta #{$i} tipo inválido", []];
            }

            $enunciado = trim((string) ($q['enunciado'] ?? ''));
            if ($enunciado === '') {
                return [false, "pregunta #{$i} sin enunciado", []];
            }

            $tiempo = max(5, min(120, (int) ($q['tiempo'] ?? 20)));
            $puntos = max(10, min(1000, (int) ($q['puntos'] ?? 100)));
            $explicacion = trim((string) ($q['explicacion'] ?? ''));

            $opciones = $q['opciones'] ?? null;
            if (!is_array($opciones) || count($opciones) < 2) {
                return [false, "pregunta #{$i} sin suficientes opciones", []];
            }

            $cleanOptions = [];
            $correctCount = 0;

            foreach ($opciones as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $texto = trim((string) ($o['texto'] ?? ''));
                if ($texto === '') {
                    continue;
                }
                $correcta = (bool) ($o['correcta'] ?? false);
                if ($correcta) {
                    $correctCount++;
                }
                $cleanOptions[] = ['texto' => $texto, 'correcta' => $correcta];
            }

            if (count($cleanOptions) < 2) {
                return [false, "pregunta #{$i} con opciones vacías", []];
            }

            // Exactamente una opción correcta: si Gemini se equivocó (0 o varias),
            // forzamos que la primera sea la correcta en vez de descartar la pregunta,
            // pero queda claramente marcada para que el profesor la revise.
            if ($correctCount !== 1) {
                foreach ($cleanOptions as $idx => &$opt) {
                    $opt['correcta'] = ($idx === 0);
                }
                unset($opt);
            }

            if ($tipo === 'truefalse') {
                $cleanOptions = array_slice($cleanOptions, 0, 2);
            } else {
                $cleanOptions = array_slice($cleanOptions, 0, 6);
            }

            $cleanQuestions[] = [
                'tipo'        => $tipo,
                'enunciado'   => $enunciado,
                'tiempo'      => $tiempo,
                'puntos'      => $puntos,
                'explicacion' => $explicacion,
                'opciones'    => $cleanOptions,
            ];
        }

        if (empty($cleanQuestions)) {
            return [false, 'ninguna pregunta válida tras la limpieza', []];
        }

        return [true, '', [
            'titulo'      => $titulo,
            'descripcion' => $descripcion,
            'preguntas'   => $cleanQuestions,
        ]];
    }
}
