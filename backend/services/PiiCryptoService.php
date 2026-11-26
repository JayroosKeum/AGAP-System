<?php

declare(strict_types=1);

/**
 * Authenticated AES-256-GCM PII encryption helper for sensitive resident, party, and account fields.
 * Reads key material from APP_PII_KEY (env) and optional rotation fallback keys from
 * APP_PII_KEYS_LEGACY (comma-separated). Uses a versioned "enc:v1:" prefix so
 * legacy plaintext values and encrypted values can be migrated safely.
 */
final class PiiCryptoService
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc:v1:';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /**
     * Free-text narrative, audit JSON, and hash columns that must never be transformed
     * by dynamic read/write PII unwrapping.
     *
     * @var array<int, string>
     */
    public const NON_PII_SKIP_KEYS = [
        'narrative', 'additional_details', 'requested_resolution',
        'description', 'agreement_details', 'salaysay',
        'before_state', 'after_state', 'old_value', 'new_value', 'old_values', 'new_values',
        'metadata', 'audit_data', 'remarks', 'notes',
        'review_notes', 'resolution_notes', 'minutes_text',
        'agreed_terms', 'objection_reason', 'deletion_reason',
        'pause_notes', 'justification_reason', 'attendance_remarks',
        'entry_hash', 'prev_hash', 'password_hash', 'otp_hash', 'token_hash',
    ];

    private ?string $key;
    /** @var array<int, string> */
    private array $legacyKeys = [];
    private static bool $envChecked = false;

    public function __construct(?string $keyOverride = null, array $legacyKeyOverrides = [])
    {
        if ($keyOverride === null) {
            self::loadEnvIfMissing();
            $raw = getenv('APP_PII_KEY') ?: ($_ENV['APP_PII_KEY'] ?? ($_SERVER['APP_PII_KEY'] ?? ''));
        } else {
            $raw = $keyOverride;
        }

        $this->key = self::deriveKey((string) $raw);

        if ($legacyKeyOverrides !== []) {
            foreach ($legacyKeyOverrides as $legacyRaw) {
                $derived = self::deriveKey((string) $legacyRaw);
                if ($derived !== null) {
                    $this->legacyKeys[] = $derived;
                }
            }
        } elseif ($keyOverride === null) {
            $rawLegacy = getenv('APP_PII_KEYS_LEGACY') ?: ($_ENV['APP_PII_KEYS_LEGACY'] ?? ($_SERVER['APP_PII_KEYS_LEGACY'] ?? ''));
            if (is_string($rawLegacy) && trim($rawLegacy) !== '') {
                foreach (explode(',', $rawLegacy) as $part) {
                    $derived = self::deriveKey($part);
                    if ($derived !== null) {
                        $this->legacyKeys[] = $derived;
                    }
                }
            }
        }
    }

    private static function deriveKey(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            return $decoded !== false && strlen($decoded) === 32
                ? $decoded
                : hash('sha256', $raw, true);
        }
        return hash('sha256', $raw, true);
    }

    private static function loadEnvIfMissing(): void
    {
        if (self::$envChecked) {
            return;
        }
        self::$envChecked = true;
        $existing = getenv('APP_PII_KEY') ?: ($_ENV['APP_PII_KEY'] ?? ($_SERVER['APP_PII_KEY'] ?? ''));
        if (is_string($existing) && trim($existing) !== '') {
            return;
        }
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!is_file($envFile) || !is_readable($envFile)) {
            return;
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (in_array($k, ['APP_PII_KEY', 'APP_PII_KEYS_LEGACY', 'APP_AUDIT_HMAC_KEY'], true) && $v !== '') {
                $cur = $_SERVER[$k] ?? ($_ENV[$k] ?? getenv($k));
                if (!is_string($cur) || $cur === '') {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                    $_SERVER[$k] = $v;
                }
            }
        }
    }

    public function isConfigured(): bool
    {
        return $this->key !== null && function_exists('openssl_encrypt');
    }

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && (str_starts_with($value, self::PREFIX) || str_starts_with($value, 'name:v1:'));
    }

    public function encryptName(?string $value): ?string
    {
        if ($value === null || $value === '') return $value;
        if (self::isEncrypted($value)) {
            // Never trust an input prefix without authenticating its payload.
            $this->decrypt($value);
            if (str_starts_with($value, 'name:v1:')) return $value;
        }
        return 'name:v1:' . substr((string) $this->encrypt($value), strlen(self::PREFIX));
    }

    /**
     * Encrypts a non-empty PII string with AES-256-GCM.
     * Fails closed when APP_PII_KEY is missing or unusable.
     */
    public function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }
        if (self::isEncrypted($plaintext)) {
            return $plaintext;
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException('APP_PII_KEY is required to encrypt protected PII data (fail-closed).');
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
        if (is_string($value) && str_starts_with($value, 'name:v1:')) $value = self::PREFIX . substr($value, strlen('name:v1:'));
        if ($value === null || $value === '' || !self::isEncrypted($value)) {
            return $value;
        }
        if (!$this->isConfigured() && $this->legacyKeys === []) {
            throw new RuntimeException('APP_PII_KEY is required to decrypt PII data.');
        }
        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) <= (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new RuntimeException('Malformed encrypted PII payload.');
        }
        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);

        $keysToTry = [];
        if ($this->key !== null) {
            $keysToTry[] = $this->key;
        }
        foreach ($this->legacyKeys as $legacyKey) {
            $keysToTry[] = $legacyKey;
        }

        foreach ($keysToTry as $candidateKey) {
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $candidateKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        throw new RuntimeException('PII decryption authentication check failed.');
    }

    /**
     * Re-encrypts an existing ciphertext (or legacy plaintext) under the active primary key.
     */
    public function rotate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $plaintext = $this->decrypt($value);
        return $this->encrypt($plaintext);
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

    /**
     * Computes a blind index for normalized contact numbers (digits + optional leading +).
     */
    public function contactBlindIndex(?string $contact): ?string
    {
        if ($contact === null || trim($contact) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $contact) ?? '';
        $normalized = $digits !== '' ? $digits : mb_strtolower(trim($contact), 'UTF-8');
        return $this->blindIndex('contact:' . $normalized);
    }

    /**
     * Computes a blind index for the last 4 digits of a contact number (for public tracker verification).
     */
    public function contactLast4BlindIndex(?string $contact): ?string
    {
        if ($contact === null || trim($contact) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $contact) ?? '';
        if (strlen($digits) < 4) {
            return null;
        }
        return $this->blindIndex('last4:' . substr($digits, -4));
    }

    public function emailBlindIndex(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }
        return $this->blindIndex('email:' . mb_strtolower(trim($email), 'UTF-8'));
    }

    /**
     * Decrypts selected keys in an associative database row in place.
     *
     * @param array<string, mixed> $row
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    public function decryptRow(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && is_string($row[$field]) && self::isEncrypted($row[$field])) {
                $row[$field] = $this->decrypt($row[$field]);
            }
        }
        return $row;
    }

    /**
     * Convenience static helper for authorized read paths.
     */
    public static function decryptField(?string $value): ?string
    {
        if ($value === null || $value === '' || !self::isEncrypted($value)) {
            return $value;
        }
        static $shared = null;
        if ($shared === null) {
            $shared = new self();
        }
        return $shared->decrypt($value);
    }

    /**
     * Decrypts selected encrypted fields across a list of database rows, skipping any unreadable field safely.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $fields
     * @return array<int, array<string, mixed>>
     */
    public static function decryptRows(array $rows, array $fields): array
    {
        if ($rows === [] || $fields === []) {
            return $rows;
        }
        $crypto = new self();
        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($fields as $field) {
                if (array_key_exists($field, $row) && is_string($row[$field]) && self::isEncrypted($row[$field])) {
                    try {
                        $rows[$idx][$field] = $crypto->decrypt($row[$field]);
                    } catch (Throwable $e) {
                        error_log('PII decrypt skipped for field ' . $field . ': ' . $e->getMessage());
                    }
                }
            }
        }
        return $rows;
    }

    /**
     * Replaces any valid authenticated (enc|name):v1:... ciphertext tokens inside a string.
     * Leaves unconfigured environments and unauthenticated literal strings untouched.
     */
    public static function decryptStringTokens(?string $value): ?string
    {
        if ($value === null || $value === '' || (!str_contains($value, 'enc:v1:') && !str_contains($value, 'name:v1:'))) {
            return $value;
        }
        $crypto = new self();
        if (!$crypto->isConfigured()) {
            return $value;
        }
        return preg_replace_callback(
            '/(?:enc|name):v1:[A-Za-z0-9+\/=]+/',
            static function (array $match) use ($crypto): string {
                try {
                    return (string) $crypto->decrypt($match[0]);
                } catch (Throwable) {
                    return $match[0];
                }
            },
            $value
        ) ?? $value;
    }

    /**
     * Dynamically decrypts PII ciphertext tokens across an entire row or nested payload,
     * excluding narrative, audit, and hash keys.
     *
     * @param array<int, string> $skipKeys
     */
    public static function decryptPayload(mixed $data, array $skipKeys = self::NON_PII_SKIP_KEYS, string $currentKey = ''): mixed
    {
        if (is_string($data)) {
            if ($currentKey !== '' && in_array(strtolower($currentKey), $skipKeys, true)) {
                return $data;
            }
            return self::decryptStringTokens($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $nextKey = is_string($key) ? $key : '';
                if ($nextKey !== '' && in_array(strtolower($nextKey), $skipKeys, true)) {
                    continue;
                }
                $data[$key] = self::decryptPayload($val, $skipKeys, $nextKey);
            }
            return $data;
        }
        return $data;
    }

    /**
     * Unwraps validly encrypted tokens accidentally posted back by stale/cached client forms
     * before validation or persistence.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $skipKeys
     * @return array<string, mixed>
     */
    public static function unwrapSubmittedInputs(array $data, array $skipKeys = self::NON_PII_SKIP_KEYS): array
    {
        $unwrapped = self::decryptPayload($data, $skipKeys);
        return is_array($unwrapped) ? $unwrapped : $data;
    }
}
