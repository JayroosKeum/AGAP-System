<?php

require_once __DIR__ . '/../services/GeminiService.php';

class AIController
{
    private $gemini;

    public function __construct()
    {
        $this->gemini =
            new GeminiService();
    }

    public function chatbot($message)
    {
        return $this->gemini
            ->generate($message);
    }

    public function narrative($data)
    {
        $prompt = "

        Convert the following details
        into a formal barangay complaint.

        WHO:
        {$data['who']}

        WHAT:
        {$data['what']}

        WHEN:
        {$data['when']}

        WHERE:
        {$data['where']}

        WHY:
        {$data['why']}

        HOW:
        {$data['how']}

        ";

        return $this->gemini
            ->generate($prompt);
    }
}
