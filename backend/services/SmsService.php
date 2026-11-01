<?php

require_once __DIR__ . '/../models/Setting.php';

/** Sends outbound SMS using the gateway configured in System Settings. */
class SmsService
{
    public function sendActivation(string $phoneNumber, string $message): void
    {
        $settings = new Setting();
        $provider = trim((string) $settings->get('sms_provider', 'Semaphore'));
        $apiKey = trim((string) $settings->get('sms_api_key', ''));
        $sender = trim((string) $settings->get('sms_sender_name', 'BRGY_TUMANA'));

        if ($provider === '' || $provider === 'Disabled') {
            throw new RuntimeException('SMS gateway is disabled.');
        }
        if ($apiKey === '') {
            throw new RuntimeException('SMS gateway credentials are not configured.');
        }

        $number = $this->normalizePhilippineMobile($phoneNumber);
        if ($provider === 'Semaphore') {
            $this->sendSemaphore($apiKey, $sender, $number, $message);
            return;
        }
        if ($provider === 'Twilio') {
            $this->sendTwilio($apiKey, $sender, $number, $message);
            return;
        }
        throw new RuntimeException("Unsupported SMS provider '{$provider}'.");
    }

    private function sendSemaphore(string $apiKey, string $sender, string $number, string $message): void
    {
        $response = $this->post('https://api.semaphore.co/api/v4/messages', [
            'apikey' => $apiKey, 'number' => $number, 'message' => $message, 'sendername' => $sender,
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Semaphore returned HTTP ' . $response['status'] . '.');
        }
    }

    private function sendTwilio(string $credentials, string $from, string $number, string $message): void
    {
        [$accountSid, $authToken] = array_pad(explode(':', $credentials, 2), 2, '');
        if ($accountSid === '' || $authToken === '' || $from === '') {
            throw new RuntimeException('Twilio requires sms_api_key as ACCOUNT_SID:AUTH_TOKEN and a sender number.');
        }
        $response = $this->post('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json', [
            'To' => $number, 'From' => $from, 'Body' => $message,
        ], [$accountSid, $authToken]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Twilio returned HTTP ' . $response['status'] . '.');
        }
    }

    private function post(string $url, array $fields, ?array $basicAuth = null): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('The cURL extension is required for SMS delivery.');
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_TIMEOUT => 15]);
        if ($basicAuth) curl_setopt($curl, CURLOPT_USERPWD, $basicAuth[0] . ':' . $basicAuth[1]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false || $error !== '') throw new RuntimeException('SMS gateway request failed: ' . $error);
        return ['status' => $status, 'body' => $body];
    }

    private function normalizePhilippineMobile(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (str_starts_with($digits, '63')) $digits = substr($digits, 2);
        elseif (str_starts_with($digits, '0')) $digits = substr($digits, 1);
        if (!preg_match('/^9\d{9}$/', $digits)) throw new InvalidArgumentException('A Philippine mobile number is required for SMS delivery.');
        return '+63' . $digits;
    }
}
