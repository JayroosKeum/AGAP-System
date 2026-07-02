<?php

require_once __DIR__ . '/../config/gemini.php';

class GeminiService
{
    private $apiKey;

    public function __construct()
    {
        $this->apiKey = GEMINI_API_KEY;
    }

    public function generate($prompt)
    {
        $url =
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=".$this->apiKey;

        $payload = [
            "contents" => [[
                "parts" => [[
                    "text" => $prompt
                ]]
            ]]
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);

        $response = curl_exec($ch);

        curl_close($ch);

        return json_decode(
            $response,
            true
        );
    }
}