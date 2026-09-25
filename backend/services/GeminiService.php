<?php

require_once __DIR__ . '/../config/gemini.php';

class GeminiService
{
    private $apiKey;

    public function __construct()
    {
        $this->apiKey = GEMINI_API_KEY;
    }

    public function generate(string $prompt, array $generationConfig = []): array
    {
        if ($this->apiKey === '') {
            return ['success' => false, 'message' => 'AI narrative assistance is not configured.'];
        }
        if (!function_exists('curl_init')) {
            return ['success' => false, 'message' => 'AI narrative assistance is unavailable because PHP cURL is not enabled.'];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent';

        $payload = [
            "contents" => [[
                "parts" => [[
                    "text" => $prompt
                ]]
            ]],
            'generationConfig' => $generationConfig
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            error_log('Gemini request failed: ' . $curlError);
            return ['success' => false, 'message' => 'AI narrative assistance is temporarily unavailable.'];
        }

        $decoded = json_decode($response, true);
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            error_log('Gemini request returned HTTP ' . $statusCode);
            return ['success' => false, 'message' => 'AI narrative assistance could not process the request.'];
        }

        return ['success' => true, 'data' => $decoded];
    }
}
