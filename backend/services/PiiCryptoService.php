<?php

declare(strict_types=1);

/**
 * Authenticated AES-256-GCM PII encryption helper for sensitive resident and party fields.
 * Reads key material from APP_PII_KEY (env) and uses a versioned "enc:v1:" prefix so
 * plaintext legacy values and encrypted values can coexist safely.
 */
final class PiiCryptoService
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc:v1:';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private ?string $key;

    public function __construct(?string $keyOverride = null)
    {
        $raw = $keyOverride ?? (getenv('APP_PII_KEY') ?: ($_ENV['APP_PII_KEY'] ?? ''));
        $raw = trim((string) $raw);
        if ($raw === '') {
            $this->key = null;
            return;
        }
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            $this->key = $decoded !== false && strlen($decoded) === 32
                ? $decoded
                : hash('sha256', $raw, true);
            return;
        }
        $this->key = hash('sha256', $raw, true);
    }

    public function isConfigured(): bool
    {
        return $this->key !== null && function_exists('openssl_encrypt');
    }

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '' || !$this->isConfigured()) {
            return $plaintext;
        }
        if (self::isEncrypted($plaintext)) {
            return $plaintext;
        }
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Unable to encrypt PII payload.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !self::isEncrypted($value)) {
            return $value;
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException('APP_PII_KEY is required to decrypt PII data.');
        }
        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) <= (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new RuntimeException('Malformed encrypted PII payload.');
        }
        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new RuntimeException('PII decryption authentication check failed.');
        }
        return $plaintext;
    }

    /**
     * Deterministic HMAC-SHA256 blind index for exact-match lookups on encrypted fields.
     */
    public function blindIndex(string $value): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('APP_PII_KEY is required to compute a PII blind index.');
        }
        return hash_hmac('sha256', mb_strtolower(trim($value), 'UTF-8'), $this->key);
    }
}
