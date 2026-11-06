<?php

require_once __DIR__ . '/../config/gemini.php';

class GeminiService
{
    private string $apiKey = '';
    private string $model = 'gemini-3.5-flash';
    private array $fallbackModels = ['gemini-3.5-flash', 'gemini-3.8-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-3-flash-preview'];

    public function __construct(?string $model = null, ?string $apiKey = null)
    {
        $this->apiKey = $apiKey !== null ? trim($apiKey) : $this->resolveApiKey();

        if ($model !== null && trim($model) !== '') {
            $this->model = trim($model);
        } else {
            $this->model = $this->resolveModel();
        }
    }

    /**
     * Resolves the Gemini API key from deployment configuration first, followed by
     * legacy database and local-development settings. A server secret must always
     * win over a value that an administrator may have stored in the database.
     */
    public function resolveApiKey(): string
    {
        // 1. Production environment (Apache/FPM/Docker/secret manager).
        $envKey = (string) (getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? ($_SERVER['GEMINI_API_KEY'] ?? '')));
        if (trim($envKey) !== '') {
            return trim($envKey);
        }

        // 2. Check defined constant for hosts that load backend/config/gemini.php.
        if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
            return trim((string) GEMINI_API_KEY);
        }

        // 3. Legacy database setting. New production deployments should use a
        // server-side secret instead, so the key is not persisted in application data.
        if (file_exists(__DIR__ . '/../models/Setting.php')) {
            require_once __DIR__ . '/../models/Setting.php';
            try {
                $dbKey = trim((string)Setting::getSetting('gemini_api_key', ''));
                if ($dbKey !== '') {
                    return $dbKey;
                }
            } catch (Throwable $t) {
                // Ignore DB error if connection is not yet initialized
            }
        }

        // 4. Local-development fallback. The .env file is ignored by Git.
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (str_starts_with($line, 'GEMINI_API_KEY=')) {
                        $val = trim(substr($line, strlen('GEMINI_API_KEY=')), " \t\n\r\0\x0B\"'");
                        if ($val !== '') {
                            return $val;
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Resolves default model from system settings or falls back to Gemini 3.8 Flash.
     */
    public function resolveModel(): string
    {
        if (file_exists(__DIR__ . '/../models/Setting.php')) {
            require_once __DIR__ . '/../models/Setting.php';
            try {
                $dbModel = trim((string)Setting::getSetting('gemini_model', ''));
                if ($dbModel !== '') {
                    return $dbModel;
                }
            } catch (Throwable $t) {}
        }

        return 'gemini-3.5-flash';
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Executes generateContent request against Google Gemini API with automatic model fallback.
     *
     * @param string|array $contents Either a prompt string or structured array of contents/parts
     * @param array $generationConfig Optional generation config (temperature, maxOutputTokens, response_mime_type, etc.)
     * @param string|null $modelOverride Optional model override for this request
     * @return array Standard result ['success' => bool, 'data' => array, 'message' => string, 'model_used' => string]
     */
    public function generate($contents, array $generationConfig = [], ?string $modelOverride = null): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Gemini API key is not configured. Please set the API key in System Settings or .env file.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'PHP cURL extension is required for Gemini AI operations.'
            ];
        }

        // Standardize contents payload
        if (is_string($contents)) {
            $contentsPayload = [[
                'parts' => [[
                    'text' => $contents
                ]]
            ]];
        } elseif (isset($contents['parts'])) {
            $contentsPayload = [$contents];
        } elseif (isset($contents[0]['parts'])) {
            $contentsPayload = $contents;
        } else {
            $contentsPayload = [[
                'parts' => $contents
            ]];
        }

        // Build candidate models list to try
        $modelsToTry = [];
        $primaryModel = $modelOverride ?: $this->model;
        $modelsToTry[] = $primaryModel;

        foreach ($this->fallbackModels as $fb) {
            if (!in_array($fb, $modelsToTry, true)) {
                $modelsToTry[] = $fb;
            }
        }

        $lastError = 'Unknown error';
        $lastStatus = 0;

        foreach ($modelsToTry as $candidateModel) {
            $payload = [
                'contents' => $contentsPayload
            ];

            if (!empty($generationConfig)) {
                // Accept the application's snake_case option, but send Gemini's REST field name only.
                $cfg = $generationConfig;
                if (isset($cfg['response_mime_type']) && !isset($cfg['responseMimeType'])) {
                    $cfg['responseMimeType'] = $cfg['response_mime_type'];
                }
                unset($cfg['response_mime_type']);
                $payload['generationConfig'] = $cfg;
            }

            $result = $this->callGeminiEndpoint($candidateModel, $payload);

            if ($result['success']) {
                $result['model_used'] = $candidateModel;
                return $result;
            }

            $lastError = $result['message'] ?? 'API error';
            $lastStatus = $result['http_status'] ?? 0;

            // Retry only when the selected model is unavailable. Retrying a 429
            // consumes no useful capacity and can delay every AI screen.
            if (!in_array($lastStatus, [0, 400, 404, 500, 502, 503, 504], true)) {
                break;
            }
        }

        return [
            'success' => false,
            'message' => $lastError,
            'http_status' => $lastStatus
        ];
    }

    /**
     * Dispatches HTTP request via cURL to Gemini REST API.
     */
    private function callGeminiEndpoint(string $modelName, array $payload): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($modelName) . ':generateContent';

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            return [
                'success' => false,
                'message' => 'Unable to encode the Gemini request payload.',
                'http_status' => 0,
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'success' => false,
                'message' => 'Unable to initialize the Gemini connection.',
                'http_status' => 0,
            ];
        }
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true
        ];
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            $curlOptions[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($ch, $curlOptions);

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);

        $latencyMs = round((microtime(true) - $startTime) * 1000);

        if ($response === false) {
            error_log("Gemini request to {$modelName} failed: {$curlError}");
            return [
                'success' => false,
                'message' => 'Connection to Gemini service failed: ' . ($curlError ?: 'Request timed out.'),
                'http_status' => 0,
                'latency_ms' => $latencyMs
            ];
        }

        $decoded = json_decode($response, true);

        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            $apiErrorMsg = $decoded['error']['message'] ?? ('HTTP status ' . $statusCode);
            error_log("Gemini request to {$modelName} returned HTTP {$statusCode}: {$apiErrorMsg}");
            return [
                'success' => false,
                'message' => "Gemini API error ({$statusCode}): {$apiErrorMsg}",
                'http_status' => $statusCode,
                'latency_ms' => $latencyMs,
                'error_detail' => $decoded['error'] ?? null
            ];
        }

        return [
            'success' => true,
            'data' => $decoded,
            'latency_ms' => $latencyMs
        ];
    }

    /**
     * Robustly extracts and decodes a JSON object or array from raw LLM text output.
     * Handles markdown code fences (```json ... ```), preceding conversational intros,
     * trailing comments, single/double quotation mismatches, and trailing commas.
     *
     * @param string $rawText
     * @return array|null Parsed associative array, or null on failure
     */
    public static function extractJson(string $rawText): ?array
    {
        $text = trim($rawText);
        if ($text === '') {
            return null;
        }

        // Direct decode attempt
        $direct = json_decode($text, true);
        if (is_array($direct)) {
            return $direct;
        }

        // 1. Strip markdown fences if present anywhere in text
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $text, $fenceMatch)) {
            $candidate = trim($fenceMatch[1]);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            // If fence was found but invalid JSON, use the inside of fence as search subject
            $text = $candidate;
        }

        // 2. Locate outermost matching curly brackets { ... } or square brackets [ ... ]
        $firstBrace = strpos($text, '{');
        $firstBracket = strpos($text, '[');

        $startChar = null;
        $endChar = null;
        $startPos = -1;

        if ($firstBrace !== false && ($firstBracket === false || $firstBrace < $firstBracket)) {
            $startChar = '{';
            $endChar = '}';
            $startPos = $firstBrace;
        } elseif ($firstBracket !== false) {
            $startChar = '[';
            $endChar = ']';
            $startPos = $firstBracket;
        }

        if ($startPos !== -1 && $startChar !== null && $endChar !== null) {
            $lastPos = strrpos($text, $endChar);
            if ($lastPos !== false && $lastPos > $startPos) {
                $jsonSnippet = substr($text, $startPos, $lastPos - $startPos + 1);
                $decoded = json_decode($jsonSnippet, true);
                if (is_array($decoded)) {
                    return $decoded;
                }

                // 3. Clean common JSON LLM quirks (trailing commas before } or ])
                $sanitized = preg_replace('/,\s*([\}\]])/', '$1', $jsonSnippet);
                // Clean unescaped newlines inside strings if any
                $decodedClean = json_decode($sanitized, true);
                if (is_array($decodedClean)) {
                    return $decodedClean;
                }
            }
        }

        return null;
    }
}
