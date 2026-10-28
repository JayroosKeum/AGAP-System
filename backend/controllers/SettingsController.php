<?php

require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../services/AuditService.php';

class SettingsController
{
    private Setting $model;
    private AuditService $audit;

    public function __construct(?PDO $conn = null)
    {
        $this->model = new Setting($conn);
        $this->audit = new AuditService();
    }

    /**
     * Gets all settings and category metadata for the settings workspace.
     */
    public function getSettings(int $roleId): array
    {
        $allSettings = $this->model->getAllSettings();
        $categories = $this->model->getCategories();
        $storage = (in_array($roleId, [1, 2], true)) ? $this->model->getStorageBreakdown() : [];
        $diagnostics = (in_array($roleId, [1, 2], true)) ? $this->model->getDiagnostics() : [];

        // Mask sensitive API keys for display
        $maskedSettings = $allSettings;
        if (!empty($maskedSettings['gemini_api_key'])) {
            $len = strlen($maskedSettings['gemini_api_key']);
            $maskedSettings['gemini_api_key_masked'] = substr($maskedSettings['gemini_api_key'], 0, 4) . str_repeat('•', max(0, $len - 8)) . substr($maskedSettings['gemini_api_key'], -4);
        } else {
            $maskedSettings['gemini_api_key_masked'] = '';
        }

        if (!empty($maskedSettings['sms_api_key'])) {
            $len = strlen($maskedSettings['sms_api_key']);
            $maskedSettings['sms_api_key_masked'] = substr($maskedSettings['sms_api_key'], 0, 4) . str_repeat('•', max(0, $len - 8)) . substr($maskedSettings['sms_api_key'], -4);
        } else {
            $maskedSettings['sms_api_key_masked'] = '';
        }

        return [
            'success' => true,
            'is_admin' => in_array($roleId, [1, 2], true),
            'settings' => $maskedSettings,
            'categories' => $categories,
            'storage' => $storage,
            'diagnostics' => $diagnostics
        ];
    }

    /**
     * Updates multiple system settings.
     */
    public function updateSettings(array $data, int $actorUserId, int $roleId): array
    {
        if (!in_array($roleId, [1, 2], true)) {
            return ['success' => false, 'message' => 'Unauthorized. Only Administrators and Lupon Clerks can modify system settings.'];
        }

        // Filter and prepare settings array
        $toUpdate = [];

        // 1. Barangay Info & Signatories
        $infoKeys = [
            'barangay_name', 'city_name', 'province_name', 'barangay_hall_address',
            'barangay_contact', 'barangay_email', 'captain_name', 'barangay_secretary_name',
            'lupon_secretary_name', 'legal_officer_name', 'signature_style'
        ];
        foreach ($infoKeys as $k) {
            if (isset($data[$k])) {
                $toUpdate[$k] = trim((string)$data[$k]);
            }
        }

        // 2. Hearing Logistics & KP Rules
        $numericLimits = [
            'daily_hearing_capacity' => [1, 20],
            'officer_daily_hearing_capacity' => [1, 10],
            'mediation_default_duration_min' => [15, 120],
            'mediation_max_duration_min' => [30, 180],
            'conciliation_default_duration_min' => [15, 120],
            'conciliation_max_duration_min' => [30, 180],
            'mediation_max_sessions' => [1, 3], // 2 Tumana rule
            'conciliation_max_sessions' => [1, 5], // 3 RA 7160
            'paanyaya_max_attempts' => [1, 10], // 6 Tumana cap
            'mediation_session_interval_days' => [1, 14],
            'party_reschedule_limit' => [1, 5],
            'summons_max_attempts' => [1, 6],
            'summons_attempt_interval_days' => [1, 7],
            'summons_sla_working_days' => [1, 5],
            'summons_delivery_warning_days' => [1, 10],
            'jurisdiction_max_imprisonment_years' => [1, 10],
            'jurisdiction_max_fine' => [500, 50000],
            'session_inactivity_timeout_min' => [5, 240],
            'password_expiration_days' => [0, 365],
            'max_failed_login_attempts' => [3, 10],
            'lockout_duration_min' => [5, 120],
            'ai_rate_limit_per_minute' => [5, 60],
            'document_margin_top' => [5, 40],
            'document_margin_bottom' => [5, 40],
            'document_margin_left' => [5, 40],
            'document_margin_right' => [5, 40]
        ];

        foreach ($numericLimits as $k => $range) {
            if (isset($data[$k])) {
                $val = (int)$data[$k];
                if ($val < $range[0]) $val = $range[0];
                if ($val > $range[1]) $val = $range[1];
                $toUpdate[$k] = (string)$val;
            }
        }

        // Synchronize summons_max_attempts and paanyaya_max_attempts for backward compatibility
        if (isset($toUpdate['summons_max_attempts'])) {
            $toUpdate['paanyaya_max_attempts'] = $toUpdate['summons_max_attempts'];
        } elseif (isset($toUpdate['paanyaya_max_attempts'])) {
            $toUpdate['summons_max_attempts'] = $toUpdate['paanyaya_max_attempts'];
        }

        // String & toggle settings
        $miscKeys = [
            'working_hours_start', 'working_hours_end', 'mediation_day_of_week',
            'jurisdiction_auto_referral_notice', 'default_map_latitude', 'default_map_longitude',
            'default_map_zoom', 'document_paper_size', 'auto_serve_complainant_notice',
            'kp_header_title', 'kp_lgu_header', 'gemini_model', 'ai_narrative_refinement_enabled',
            'ai_ocr_processing_enabled', 'ai_case_summaries_enabled', 'sms_provider',
            'sms_sender_name', 'sms_paanyaya_template', 'sms_hearing_template',
            'map_tile_provider'
        ];
        foreach ($miscKeys as $k) {
            if (isset($data[$k])) {
                $toUpdate[$k] = trim((string)$data[$k]);
            }
        }

        // API keys (only update if not empty and not masked placeholder)
        if (!empty($data['gemini_api_key']) && !str_contains($data['gemini_api_key'], '••••')) {
            $toUpdate['gemini_api_key'] = trim((string)$data['gemini_api_key']);
        }
        if (!empty($data['sms_api_key']) && !str_contains($data['sms_api_key'], '••••')) {
            $toUpdate['sms_api_key'] = trim((string)$data['sms_api_key']);
        }

        // JSON fields (Holidays, Working days, Mediation slots, Puroks)
        if (isset($data['configured_holidays'])) {
            $holidays = is_array($data['configured_holidays']) ? $data['configured_holidays'] : json_decode((string)$data['configured_holidays'], true);
            if (is_array($holidays)) {
                $toUpdate['configured_holidays'] = json_encode(array_values($holidays), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if (isset($data['working_days'])) {
            $days = is_array($data['working_days']) ? $data['working_days'] : json_decode((string)$data['working_days'], true);
            if (is_array($days)) {
                $toUpdate['working_days'] = json_encode(array_values($days));
            }
        }

        if (isset($data['mediation_time_slots'])) {
            $slots = is_array($data['mediation_time_slots']) ? $data['mediation_time_slots'] : json_decode((string)$data['mediation_time_slots'], true);
            if (is_array($slots)) {
                $toUpdate['mediation_time_slots'] = json_encode(array_values($slots));
            }
        }

        if (isset($data['barangay_puroks'])) {
            $puroks = is_array($data['barangay_puroks']) ? $data['barangay_puroks'] : json_decode((string)$data['barangay_puroks'], true);
            if (is_array($puroks)) {
                $toUpdate['barangay_puroks'] = json_encode(array_values($puroks));
            }
        }

        if (empty($toUpdate)) {
            return ['success' => false, 'message' => 'No valid settings data provided for update.'];
        }

        $ok = $this->model->setMultiple($toUpdate);
        if ($ok) {
            $this->audit->log($actorUserId, 'Updated system settings (' . count($toUpdate) . ' parameters modified)', 'Settings', null);
            return ['success' => true, 'message' => 'System settings updated successfully.'];
        }

        return ['success' => false, 'message' => 'Failed to save settings to the database.'];
    }

    /**
     * Uploads official seal image (Barangay Seal or City Seal).
     */
    public function uploadSeal(string $type, array $file, int $actorUserId, int $roleId): array
    {
        if (!in_array($roleId, [1, 2], true)) {
            return ['success' => false, 'message' => 'Unauthorized to upload official seal images.'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'File upload error code: ' . $file['error']];
        }

        $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes, true)) {
            return ['success' => false, 'message' => 'Invalid image format. Allowed formats: PNG, JPG, WEBP.'];
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'File size exceeds maximum limit of 5 MB.'];
        }

        $ext = match($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png'
        };

        $targetDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'seals';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $prefix = ($type === 'city') ? 'city_seal' : 'barangay_seal';
        $filename = "{$prefix}_" . date('Ymd_His') . ".{$ext}";
        $destPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['success' => false, 'message' => 'Failed to save uploaded image to storage directory.'];
        }

        // Relative path from frontend web root
        $relativePath = "../../storage/uploads/seals/{$filename}";
        $settingKey = ($type === 'city') ? 'city_seal_path' : 'barangay_seal_path';

        $this->model->set($settingKey, $relativePath);
        $this->audit->log($actorUserId, "Uploaded new official seal image for {$type}", 'Settings', null);

        return [
            'success' => true,
            'message' => ucfirst($type) . ' seal uploaded and saved successfully.',
            'path' => $relativePath,
            'filename' => $filename
        ];
    }

    /**
     * Tests live connection to Google Gemini API.
     */
    public function testGeminiApi(?string $apiKey): array
    {
        $key = trim((string)$apiKey);
        if ($key === '' || str_contains($key, '••••')) {
            $key = (string)$this->model->get('gemini_api_key', '');
        }

        if ($key === '') {
            return ['success' => false, 'message' => 'Gemini API Key is empty. Please enter an API key first.'];
        }

        $model = (string)$this->model->get('gemini_model', 'gemini-1.5-flash');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key);

        $payload = json_encode([
            'contents' => [
                ['parts' => [['text' => 'Hello. Answer in one word: Ready']]]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 5,
                'temperature' => 0.1
            ]
        ]);

        $startTime = microtime(true);
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $latency = round((microtime(true) - $startTime) * 1000);

        if ($error) {
            return [
                'success' => false,
                'message' => "Connection failed: {$error}",
                'latency_ms' => $latency
            ];
        }

        if ($httpCode === 200) {
            $resData = json_decode($response, true);
            $reply = $resData['candidates'][0]['content']['parts'][0]['text'] ?? 'Ready';
            return [
                'success' => true,
                'message' => "Successfully connected to Google Gemini ({$model}). Ping latency: {$latency} ms.",
                'model' => $model,
                'latency_ms' => $latency,
                'response_sample' => trim($reply)
            ];
        }

        $resData = json_decode($response, true);
        $errDetail = $resData['error']['message'] ?? "HTTP Error {$httpCode}";
        return [
            'success' => false,
            'message' => "API rejected request (HTTP {$httpCode}): {$errDetail}",
            'latency_ms' => $latency
        ];
    }

    /**
     * Tests SMS Gateway connection.
     */
    public function testSmsGateway(string $provider, ?string $apiKey, string $recipient, string $message): array
    {
        $key = trim((string)$apiKey);
        if ($key === '' || str_contains($key, '••••')) {
            $key = (string)$this->model->get('sms_api_key', '');
        }

        $recipient = trim($recipient);
        if ($recipient === '') {
            return ['success' => false, 'message' => 'Recipient mobile number is required.'];
        }

        if ($provider === 'Disabled') {
            return ['success' => false, 'message' => 'SMS Gateway provider is currently set to Disabled.'];
        }

        if ($key === '') {
            return ['success' => false, 'message' => 'SMS Gateway API key/token is missing.'];
        }

        $senderName = (string)$this->model->get('sms_sender_name', 'BRGY_TUMANA');

        if ($provider === 'Semaphore') {
            $ch = curl_init('https://api.semaphore.co/api/v4/messages');
            $data = [
                'apikey' => $key,
                'number' => $recipient,
                'message' => $message ?: 'AGAP System test dispatch from Barangay Tumana.',
                'sendername' => $senderName
            ];
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);

            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                return ['success' => false, 'message' => "SMS dispatch failed: {$err}"];
            }

            if ($code === 200) {
                return ['success' => true, 'message' => "Test SMS sent successfully via Semaphore to {$recipient}."];
            }

            return ['success' => false, 'message' => "Semaphore returned HTTP {$code}: " . substr($res, 0, 200)];
        }

        return ['success' => false, 'message' => "Provider '{$provider}' test protocol not configured."];
    }

    /**
     * Exports database dump and provides download.
     */
    public function exportBackup(int $actorUserId, int $roleId): void
    {
        if (!in_array($roleId, [1, 2], true)) {
            http_response_code(403);
            die("Unauthorized to export database backups.");
        }

        $sql = $this->model->exportDatabaseDump();
        $filename = "agap_backup_" . date('Ymd_His') . ".sql";

        // Save copy to storage/backups/
        $backupDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        file_put_contents($backupDir . DIRECTORY_SEPARATOR . $filename, $sql);

        $this->audit->log($actorUserId, "Exported full database backup ({$filename})", 'Settings', null);

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $sql;
        exit;
    }

    /**
     * Category CRUD
     */
    public function saveCategory(array $data, int $actorUserId, int $roleId): array
    {
        if (!in_array($roleId, [1, 2], true)) {
            return ['success' => false, 'message' => 'Unauthorized to modify dispute categories.'];
        }
        $result = $this->model->saveCategory($data);
        if ($result['success']) {
            $catId = $result['category_id'] ?? 0;
            $this->audit->log($actorUserId, "Saved dispute category #{$catId} ({$data['category_name']})", 'Settings', $catId);
        }
        return $result;
    }

    public function deleteCategory(int $categoryId, int $actorUserId, int $roleId): array
    {
        if (!in_array($roleId, [1, 2], true)) {
            return ['success' => false, 'message' => 'Unauthorized to delete dispute categories.'];
        }
        $result = $this->model->deleteCategory($categoryId);
        if ($result['success']) {
            $this->audit->log($actorUserId, "Deleted or deactivated dispute category #{$categoryId}", 'Settings', $categoryId);
        }
        return $result;
    }

    /**
     * Personal Profile & Password
     */
    public function getUserProfile(int $userId): array
    {
        $profile = $this->model->getUserProfile($userId);
        if (!$profile) {
            return ['success' => false, 'message' => 'User profile not found.'];
        }
        return ['success' => true, 'profile' => $profile];
    }

    public function updateUserProfile(int $userId, array $data): array
    {
        $result = $this->model->updateUserProfile($userId, $data);
        if ($result['success']) {
            $this->audit->log($userId, 'Updated personal user profile', 'Settings', $userId);
        }
        return $result;
    }

    public function changeUserPassword(int $userId, string $curPass, string $newPass, string $confirmPass): array
    {
        if ($newPass !== $confirmPass) {
            return ['success' => false, 'message' => 'New password and password confirmation do not match.'];
        }
        $result = $this->model->updateUserPassword($userId, $curPass, $newPass);
        if ($result['success']) {
            $this->audit->log($userId, 'Changed account password', 'Settings', $userId);
        }
        return $result;
    }
}
