<?php

require_once __DIR__ . '/../config/gemini.php';

class AiServiceController
{
    private string $apiKey;
    private string $model;

    public function __construct(string $model = 'gemini-1.5-flash')
    {
        $this->apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : (string) (getenv('GEMINI_API_KEY') ?: '');
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

        $audioData = null;
        $detectedMime = $mimeType;

        if (is_array($audioInput) && isset($audioInput['tmp_name'])) {
            if ($audioInput['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'Failed to upload audio file (Upload Error Code: ' . $audioInput['error'] . ').'];
            }
            if (!is_uploaded_file($audioInput['tmp_name'])) {
                return ['success' => false, 'message' => 'Invalid uploaded audio file.'];
            }
            if ($audioInput['size'] > 25 * 1024 * 1024) {
                return ['success' => false, 'message' => 'Audio file exceeds the maximum allowed size of 25MB.'];
            }

            $detectedMime = $this->detectMimeType($audioInput['tmp_name'], $audioInput['type'] ?? 'audio/webm');
            $rawContent = file_get_contents($audioInput['tmp_name']);
            if ($rawContent === false || strlen($rawContent) === 0) {
                return ['success' => false, 'message' => 'Unable to read audio content.'];
            }
            $audioData = base64_encode($rawContent);
        } elseif (is_string($audioInput) && file_exists($audioInput)) {
            $detectedMime = $this->detectMimeType($audioInput, $mimeType ?: 'audio/webm');
            $rawContent = file_get_contents($audioInput);
            if ($rawContent === false || strlen($rawContent) === 0) {
                return ['success' => false, 'message' => 'Unable to read audio content from file path.'];
            }
            $audioData = base64_encode($rawContent);
        } elseif (is_string($audioInput) && !empty($audioInput)) {
            $audioData = $audioInput;
            $detectedMime = $mimeType ?: 'audio/webm';
        } else {
            return ['success' => false, 'message' => 'No valid audio file or data was provided.'];
        }

        // Normalize audio MIME types for Gemini
        $allowedMimes = ['audio/webm', 'audio/wav', 'audio/x-wav', 'audio/mp3', 'audio/mpeg', 'audio/ogg', 'audio/m4a', 'audio/mp4', 'audio/aac'];
        if (!in_array($detectedMime, $allowedMimes, true)) {
            $detectedMime = 'audio/webm';
        }

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

        $imageData = null;
        $detectedMime = $mimeType;

        if (is_array($imageInput) && isset($imageInput['tmp_name'])) {
            if ($imageInput['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'Failed to upload image file (Upload Error Code: ' . $imageInput['error'] . ').'];
            }
            if (!is_uploaded_file($imageInput['tmp_name'])) {
                return ['success' => false, 'message' => 'Invalid uploaded image file.'];
            }
            if ($imageInput['size'] > 15 * 1024 * 1024) {
                return ['success' => false, 'message' => 'Image file exceeds the maximum allowed size of 15MB.'];
            }

            $detectedMime = $this->detectMimeType($imageInput['tmp_name'], $imageInput['type'] ?? 'image/jpeg');
            $rawContent = file_get_contents($imageInput['tmp_name']);
            if ($rawContent === false || strlen($rawContent) === 0) {
                return ['success' => false, 'message' => 'Unable to read image content.'];
            }
            $imageData = base64_encode($rawContent);
        } elseif (is_string($imageInput) && file_exists($imageInput)) {
            $detectedMime = $this->detectMimeType($imageInput, $mimeType ?: 'image/jpeg');
            $rawContent = file_get_contents($imageInput);
            if ($rawContent === false || strlen($rawContent) === 0) {
                return ['success' => false, 'message' => 'Unable to read image content from file path.'];
            }
            $imageData = base64_encode($rawContent);
        } elseif (is_string($imageInput) && !empty($imageInput)) {
            $imageData = $imageInput;
            $detectedMime = $mimeType ?: 'image/jpeg';
        } else {
            return ['success' => false, 'message' => 'No valid image file or data was provided.'];
        }

        // Normalize image MIME types
        $allowedImageMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($detectedMime, $allowedImageMimes, true)) {
            $detectedMime = 'image/jpeg';
        }

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
}
