<?php

// Configure GEMINI_API_KEY in the web-server/PHP environment, .env file, or system_settings table.
// Never place an API key in this tracked file or expose it to browser JavaScript.

$resolvedGeminiKey = (string) (getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? ($_SERVER['GEMINI_API_KEY'] ?? '')));

if (trim($resolvedGeminiKey) === '') {
    $envFile = dirname(__DIR__, 2) . '/.env';
    if (file_exists($envFile)) {
        $envVars = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if (!empty($envVars['GEMINI_API_KEY'])) {
            $resolvedGeminiKey = trim((string) $envVars['GEMINI_API_KEY']);
        }
    }
}

if (trim($resolvedGeminiKey) === '') {
    // Attempt fallback from database system_settings table
    try {
        require_once __DIR__ . '/database.php';
        $db = (new Database())->connect();
        if ($db) {
            $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
            $val = $stmt ? $stmt->fetchColumn() : null;
            if ($val && trim((string)$val) !== '') {
                $resolvedGeminiKey = trim((string)$val);
            }
        }
    } catch (Throwable) {
        // Silently ignore if DB is unreachable during early bootstrap
    }
}

define('GEMINI_API_KEY', $resolvedGeminiKey);
