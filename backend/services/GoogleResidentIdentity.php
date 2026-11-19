<?php

declare(strict_types=1);

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

final class GoogleResidentIdentity
{
    public static function clientId(): string
    {
        new Database();
        $raw = getenv('GOOGLE_RESIDENT_CLIENT_ID');
        if (!is_string($raw) || $raw === '') {
            $raw = $_ENV['GOOGLE_RESIDENT_CLIENT_ID'] ?? ($_SERVER['GOOGLE_RESIDENT_CLIENT_ID'] ?? '');
        }
        $value = trim((string) $raw, " \t\n\r\0\x0B\"'");
        return preg_match('/^[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D', $value) ? $value : '';
    }

    public function confirm(string $credential, string $nonce): array
    {
        $clientId = self::clientId();
        if ($clientId === '' || $nonce === '' || strlen($credential) > 8192 || substr_count($credential, '.') !== 2) {
            throw new DomainException('Invalid Google sign-in request.');
        }

        require_once __DIR__ . '/../../vendor/autoload.php';
        $request = curl_init('https://www.googleapis.com/oauth2/v3/certs');
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FAILONERROR => true,
        ]);
        $caBundle = getenv('GOOGLE_RESIDENT_CA_FILE') ?: $_SERVER['GOOGLE_RESIDENT_CA_FILE'] ?? '';
        if ($caBundle !== '') {
            if (!is_string($caBundle) || !is_readable($caBundle)) {
                throw new RuntimeException('The configured Google CA bundle cannot be read.');
            }
            curl_setopt($request, CURLOPT_CAINFO, $caBundle);
        }
        $response = curl_exec($request);
        if (!is_string($response)) {
            throw new RuntimeException('Google signing keys are temporarily unavailable.');
        }
        $keys = JWK::parseKeySet(json_decode($response, true, 8, JSON_THROW_ON_ERROR), 'RS256');
        return $this->verifyCredential($credential, $nonce, $clientId, $keys);
    }

    private function verifyCredential(string $credential, string $nonce, string $clientId, array $keys): array
    {
        $claims = JWT::decode($credential, $keys);

        $email = $claims->email ?? null;
        $subject = $claims->sub ?? null;
        if (!in_array($claims->iss ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ($claims->aud ?? null) !== $clientId
            || !is_int($claims->exp ?? null) || !is_int($claims->iat ?? null)
            || !is_string($claims->nonce ?? null) || !hash_equals($nonce, $claims->nonce)
            || !is_string($subject) || !preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $subject)
            || ($claims->email_verified ?? false) !== true
            || !is_string($email) || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !str_ends_with(strtolower($email), '@gmail.com')) {
            throw new DomainException('Sign in with a verified Gmail account.');
        }
        return ['google_subject' => $subject, 'email' => strtolower($email)];
    }
}
