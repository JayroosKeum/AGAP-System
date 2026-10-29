<?php

require_once __DIR__ . '/../config/gemini.php';

class AiServiceController
{
    private string $apiKey;
    private string $model;

    public function __construct(string $model = 'gemini-1.5-flash')
    {
        $this->apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : (string) (getenv('GEMINI_API_KEY') ?: '');
        if ($this->apiKey === '' && file_exists(__DIR__ . '/../models/Setting.php')) { require_once __DIR__ . '/../models/Setting.php'; try { $this->apiKey = (string)Setting::getSetting('gemini_api_key', ''); } catch (Throwable $t) {} }
        $this->model = $model;
    }

    /**
     * Transcribes spoken audio (.webm, .wav, .mp3, .m4a, .ogg) using Gemini 1.5 Flash.
     *
     * @param array|string $audioInput $_FILES array or file path / base64 string
     * @param string $mimeType Optional MIME type if passing binary/base64
     * @return array Response payload with success status and transcribed text
     */
    public function transcribeAudio($audioInput, string $mimeType = ''): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Gemini API key is not configured. Please set the GEMINI_API_KEY environment variable.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'PHP cURL extension is required for Gemini AI operations.'
            ];
        }

        $media = $this->prepareMediaInput(
            $audioInput,
            $mimeType,
            25 * 1024 * 1024,
            ['audio/webm', 'audio/wav', 'audio/x-wav', 'audio/mp3', 'audio/mpeg', 'audio/ogg', 'audio/m4a', 'audio/mp4', 'audio/aac'],
            'Audio'
        );
        if (!$media['success']) {
            return $media;
        }
        $audioData = $media['data'];
        $detectedMime = $media['mime_type'];

        $prompt = "You are an accurate transcription assistant for a barangay dispute resolution session (Katarungang Pambarangay). Transcribe all spoken dialogue from this audio verbatim into clear text. Support Tagalog, English, Taglish, or local Philippine dialects. Preserve names, amounts, dates, commitments, and statements accurately. Output ONLY the clean transcribed text without preamble, commentary, markdown headings, or timestamps.";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inlineData' => [
                                'mimeType' => $detectedMime,
                                'data' => $audioData
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 8192
            ]
        ];

        return $this->sendGeminiRequest($payload);
    }

    /**
     * Extracts text from handwritten or printed photo notes (.jpg, .png, .webp) using Gemini 1.5 Flash.
     *
     * @param array|string $imageInput $_FILES array or file path / base64 string
     * @param string $mimeType Optional MIME type if passing binary/base64
     * @return array Response payload with success status and extracted text
     */
    public function ocrImage($imageInput, string $mimeType = ''): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Gemini API key is not configured. Please set the GEMINI_API_KEY environment variable.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'PHP cURL extension is required for Gemini AI operations.'
            ];
        }

        $media = $this->prepareMediaInput(
            $imageInput,
            $mimeType,
            15 * 1024 * 1024,
            ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'],
            'Image'
        );
        if (!$media['success']) {
            return $media;
        }
        $imageData = $media['data'];
        $detectedMime = $media['mime_type'];

        $prompt = "You are an expert OCR and transcription assistant for barangay dispute resolution and session minutes. Transcribe all handwritten or printed notes from this image accurately. Preserve bullet points, names, dates, amounts, agreed terms, and key statements. If handwriting is partially obscured, transcribe what is clearly visible and indicate illegible words with [illegible]. Output ONLY the transcribed text without conversational commentary.";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inlineData' => [
                                'mimeType' => $detectedMime,
                                'data' => $imageData
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 8192
            ]
        ];

        return $this->sendGeminiRequest($payload);
    }

    /**
     * Dispatches request to Gemini REST API via PHP cURL.
     */
    private function sendGeminiRequest(array $payload): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($this->model) . ':generateContent';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log('Gemini API cURL error: ' . $curlError);
            return [
                'success' => false,
                'message' => 'Failed to connect to Gemini AI service: ' . ($curlError ?: 'Connection timed out.')
            ];
        }

        $decoded = json_decode($response, true);
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            $apiErrorMsg = $decoded['error']['message'] ?? ('HTTP status ' . $statusCode);
            error_log('Gemini API error (' . $statusCode . '): ' . $apiErrorMsg);
            return [
                'success' => false,
                'message' => 'Gemini AI service returned an error: ' . $apiErrorMsg
            ];
        }

        $text = trim((string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($text === '') {
            return [
                'success' => false,
                'message' => 'Gemini AI returned an empty response. Please verify the input clarity.'
            ];
        }

        return [
            'success' => true,
            'text' => $text,
            'model' => $this->model
        ];
    }

    private function detectMimeType(string $filePath, string $fallback): string
    {
        if (function_exists('mime_content_type') && file_exists($filePath)) {
            $mime = mime_content_type($filePath);
            if ($mime) return $mime;
        }
        return $fallback;
    }

    private function prepareMediaInput($input, string $mimeType, int $maxBytes, array $allowedMimes, string $label): array
    {
        $rawContent = null;
        $detectedMime = strtolower(trim($mimeType));
        $maxEncodedBytes = (int) (ceil($maxBytes / 3) * 4);

        if (is_array($input)) {
            if ((int) ($input['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => $label . ' upload failed.'];
            }

            $path = (string) ($input['tmp_name'] ?? '');
            $size = is_file($path) ? filesize($path) : false;
            if ($path === '' || !is_uploaded_file($path) || $size === false) {
                return ['success' => false, 'message' => 'Invalid uploaded ' . strtolower($label) . ' file.'];
            }
            if ($size > $maxBytes || (int) ($input['size'] ?? 0) > $maxBytes) {
                return ['success' => false, 'message' => $label . ' file exceeds the maximum allowed size of ' . (int) ($maxBytes / 1024 / 1024) . 'MB.'];
            }

            $detectedMime = $this->detectMimeType($path, (string) ($input['type'] ?? ''));
            $detectedMime = $this->normalizeMediaMime($detectedMime, $allowedMimes);
            if ($detectedMime === null) {
                return ['success' => false, 'message' => 'Unsupported ' . strtolower($label) . ' MIME type.'];
            }
            $rawContent = file_get_contents($path);
        } elseif (is_string($input) && strlen($input) <= 4096 && is_file($input)) {
            $size = filesize($input);
            if ($size === false || $size > $maxBytes) {
                return ['success' => false, 'message' => $label . ' file exceeds the maximum allowed size of ' . (int) ($maxBytes / 1024 / 1024) . 'MB.'];
            }

            $detectedMime = $this->detectMimeType($input, $detectedMime);
            $detectedMime = $this->normalizeMediaMime($detectedMime, $allowedMimes);
            if ($detectedMime === null) {
                return ['success' => false, 'message' => 'Unsupported ' . strtolower($label) . ' MIME type.'];
            }
            $rawContent = file_get_contents($input);
        } elseif (is_string($input)) {
            $encoded = trim($input);
            if (strlen($encoded) > ($maxEncodedBytes + 4096)) {
                return ['success' => false, 'message' => $label . ' data exceeds the maximum allowed size of ' . (int) ($maxBytes / 1024 / 1024) . 'MB.'];
            }
            if (preg_match('/\Adata:([^;,]+);base64,(.*)\z/is', $encoded, $matches)) {
                if ($detectedMime !== '' && strcasecmp($detectedMime, $matches[1]) !== 0) {
                    return ['success' => false, 'message' => $label . ' MIME type does not match its data URI.'];
                }
                $detectedMime = strtolower($matches[1]);
                $encoded = $matches[2];
            }
            $detectedMime = $this->normalizeMediaMime($detectedMime, $allowedMimes);
            if ($detectedMime === null) {
                return ['success' => false, 'message' => 'Unsupported ' . strtolower($label) . ' MIME type.'];
            }
            $encoded = preg_replace('/\s+/', '', $encoded);
            if ($encoded === '' || strlen($encoded) > $maxEncodedBytes) {
                return ['success' => false, 'message' => $label . ' data exceeds the maximum allowed size of ' . (int) ($maxBytes / 1024 / 1024) . 'MB.'];
            }

            $rawContent = base64_decode($encoded, true);
            if ($rawContent === false) {
                return ['success' => false, 'message' => $label . ' data must be valid base64.'];
            }
            if (strlen($rawContent) > $maxBytes) {
                return ['success' => false, 'message' => $label . ' data exceeds the maximum allowed size of ' . (int) ($maxBytes / 1024 / 1024) . 'MB.'];
            }
        } else {
            return ['success' => false, 'message' => 'No valid ' . strtolower($label) . ' file or data was provided.'];
        }

        if ($rawContent === false || $rawContent === null || $rawContent === '') {
            return ['success' => false, 'message' => 'Unable to read ' . strtolower($label) . ' content.'];
        }

        return [
            'success' => true,
            'data' => base64_encode($rawContent),
            'mime_type' => $detectedMime,
        ];
    }

    private function normalizeMediaMime(string $mimeType, array $allowedMimes): ?string
    {
        $mimeType = strtolower(trim($mimeType));
        $mimeType = match ($mimeType) {
            'image/jpg' => 'image/jpeg',
            'video/webm' => 'audio/webm',
            'application/ogg' => 'audio/ogg',
            default => $mimeType,
        };
        $allowedMimes = array_map(
            static fn(string $mime): string => strtolower($mime) === 'image/jpg' ? 'image/jpeg' : strtolower($mime),
            $allowedMimes
        );

        return in_array($mimeType, $allowedMimes, true) ? $mimeType : null;
    }
}
